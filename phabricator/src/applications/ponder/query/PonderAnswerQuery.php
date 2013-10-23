<?php

final class PonderAnswerQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $authorPHIDs;
  private $questionIDs;

  private $needViewerVotes;


  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withAuthorPHIDs(array $phids) {
    $this->authorPHIDs = $phids;
    return $this;
  }

  public function withQuestionIDs(array $ids) {
    $this->questionIDs = $ids;
    return $this;
  }

  public function needViewerVotes($need_viewer_votes) {
    $this->needViewerVotes = $need_viewer_votes;
    return $this;
  }

  private function buildWhereClause($conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->authorPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'authorPHID IN (%Ls)',
        $this->authorPHIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  public function loadPage() {
    $answer = new PonderAnswer();
    $conn_r = $answer->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT a.* FROM %T a %Q %Q %Q',
      $answer->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $answer->loadAllFromArray($data);
  }

  public function willFilterPage(array $answers) {
    $questions = id(new PonderQuestionQuery())
      ->setViewer($this->getViewer())
      ->withIDs(mpull($answers, 'getQuestionID'))
      ->execute();

    foreach ($answers as $key => $answer) {
      $question = idx($questions, $answer->getQuestionID());
      if (!$question) {
        unset($answers[$key]);
        continue;
      }
      $answer->attachQuestion($question);
    }

    if ($this->needViewerVotes) {
      $viewer_phid = $this->getViewer()->getPHID();

      $etype = PhabricatorEdgeConfig::TYPE_ANSWER_HAS_VOTING_USER;
      $edges = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs(mpull($answers, 'getPHID'))
        ->withDestinationPHIDs(array($viewer_phid))
        ->withEdgeTypes(array($etype))
        ->needEdgeData(true)
        ->execute();
      foreach ($answers as $answer) {
        $user_edge = idx(
          $edges[$answer->getPHID()][$etype],
          $viewer_phid,
          array());

        $answer->attachUserVote($viewer_phid, idx($user_edge, 'data', 0));
      }
    }


    return $answers;
  }

  protected function getReversePaging() {
    return true;
  }


  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationPonder';
  }

}
