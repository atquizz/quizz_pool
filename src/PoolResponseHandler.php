<?php

namespace Drupal\quizz_pool;

use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

/**
 * Extension of QuizQuestionResponse
 */
class PoolResponseHandler extends ResponseHandler {

  /**
   * {@inheritdoc}
   * @var string
   */
  protected $base_table;
  protected $user_answer_ids;
  protected $choice_order;
  protected $need_evaluated;

  public function __construct($result_id, Question $question, $input = NULL) {
    parent::__construct($result_id, $question, $input);

    if (NULL === $input) {
      if ($response = $this->getCorrectAnswer()) {
        $this->answer = $response->answer;
        $this->score = $response->score;
      }
    }
    else {
      $this->setAnswerInput($input);
    }

    $quiz_id = $this->result->getQuiz()->qid;
    if (!isset($_SESSION['quiz'][$quiz_id]["pool_{$this->question->qid}"])) {
      $_SESSION['quiz'][$quiz_id]["pool_{$this->question->qid}"] = array(
          'passed' => FALSE,
          'delta'  => 0,
      );
    }
  }

  public function setAnswerInput($input) {
    if (NULL !== $input && is_array($input)) {
      $input = reset($input);
    }
    parent::setAnswerInput($input);
  }

  /**
   * Implementation of getCorrectAnswer
   */
  public function getCorrectAnswer() {
    return db_query('SELECT answer, score'
        . ' FROM {quizz_pool_answer}'
        . ' WHERE question_vid = :qvid AND result_id = :rid', array(
          ':qvid' => $this->question->vid,
          ':rid'  => $this->result_id
      ))->fetch();
  }

  public function isValid() {
    if (2 == $this->answer) { // @TODO Number 2 here is too magic.
      drupal_set_message(t("You haven't completed the quiz pool"), 'warning');
      return FALSE;
    }
    return parent::isValid();
  }

  /**
   * @return Question
   */
  private function getQuestion() {
    $quiz_id = $this->result->getQuiz()->qid;
    $key = "pool_{$this->question->qid}";

    if (!empty($_SESSION['quiz'][$quiz_id][$key])) {
      $sess = $_SESSION['quiz'][$quiz_id][$key];
      $passed = isset($sess['passed']) ? $sess['passed'] : FALSE;
      $delta = isset($sess['delta']) ? $sess['delta'] : 0;
      return entity_metadata_wrapper('quiz_question_entity', $this->question)
          ->field_question_reference[$passed ? $delta - 1 : $delta]
          ->value();
    }

    $question_vid = db_select('quizz_pool_answer_questions', 'p')
      ->fields('p', array('question_vid'))
      ->condition('pool_qid', $this->question->qid)
      ->condition('pool_vid', $this->question->vid)
      ->condition('result_id', $this->result_id)
      ->execute()
      ->fetchColumn();

    if (!empty($question_vid)) {
      return $question = quizz_question_load(NULL, $question_vid);
    }
  }

  /**
   * Implementation of save
   * @see QuizQuestionResponse#save()
   */
  public function save() {
    $sess = &$_SESSION['quiz'][$this->result->getQuiz()->qid]["pool_{$this->question->qid}"];
    $passed = &$sess['passed'];
    $delta = &$sess['delta'];

    $wrapper = entity_metadata_wrapper('quiz_question_entity', $this->question);
    if ($question = $wrapper->field_question_reference[$delta]->value()) {
      $result = $this->evaluateQuestion($question);
      if ($result->is_valid) {
        $passed = $result->is_correct ? TRUE : $passed;
        if ($delta < $wrapper->field_question_reference->count()) {
          $delta++;
        }
      }
    }

    if (!$passed) {
      $question = $wrapper->field_question_reference[$delta - 1]->value();
    }

    db_insert('quizz_pool_answer')
      ->fields(array(
          'question_qid' => $this->question->qid,
          'question_vid' => $this->question->vid,
          'result_id'    => $this->result_id,
          'score'        => (int) $this->getScore(),
          'answer'       => (int) $this->answer,
      ))
      ->execute();
  }

  private function evaluateQuestion(Question $question) {
    $handler = $question->getResponseHandler($this->result_id, $this->answer);
    $answer = $handler->toBareObject();

    // If a result_id is set, we are taking a quiz.
    if (isset($this->answer)) {
      $keys = array(
          'pool_qid'     => $this->question->qid,
          'pool_vid'     => $this->question->vid,
          'question_qid' => $question->qid,
          'question_vid' => $question->vid,
          'result_id'    => $this->result->result_id,
      );

      db_merge('quizz_pool_answer_questions')
        ->key($keys)
        ->fields($keys + array(
            'answer'       => serialize($this->answer),
            'is_correct'   => $answer->is_correct,
            'is_evaluated' => (int) $handler->isEvaluated(),
            'score'        => (int) $handler->score(),
        ))
        ->execute()
      ;
    }

    // fix error with score
    if ($this->result->score < 0) {
      $this->result->score = 0;
    }

    return $answer;
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    parent::delete();

    // The quiz question delete and resave instead update
    // We have a difference between $respone update and delete.
    // Question update $this->question is entity
    // Question delete $this->question is custom object.
    if (!isset($this->question->created)) {
      db_delete('quizz_pool_answer_questions')
        ->condition('pool_qid', $this->question->qid)
        ->condition('pool_vid', $this->question->vid)
        ->condition('result_id', $this->result_id)
        ->execute();
    }
  }

  /**
   * Implementation of score
   * @return int
   * @see QuizQuestionResponse#score()
   */
  public function score() {
    return $this->isCorrect() ? $this->getQuestionMaxScore() : 0;
  }

  public function isCorrect() {
    $handler = $this->getQuestion()->getResponseHandler($this->result_id, $this->answer);
    $handler->setAnswerInput($this->answer);
    return $handler->isCorrect();
  }

  /**
   * Implementation of getFeedbackValues.
   */
  public function getFeedbackValues() {
    if (!$question = $this->getQuestion()) {
      return array('#markup' => t('No question passed.'));
    }
    return $question->getResponseHandler($this->result_id)->getFeedbackValues();
  }

}
