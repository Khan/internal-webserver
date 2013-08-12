<?php

final class PonderSearchIndexer
  extends PhabricatorSearchDocumentIndexer {

  public function getIndexableObject() {
    return new PonderQuestion();
  }

  protected function buildAbstractDocumentByPHID($phid) {
    $question = $this->loadDocumentByPHID($phid);

    $doc = $this->newDocument($phid)
      ->setDocumentTitle($question->getTitle())
      ->setDocumentCreated($question->getDateCreated())
      ->setDocumentModified($question->getDateModified());

    $doc->addField(
      PhabricatorSearchField::FIELD_BODY,
      $question->getContent());

    $doc->addRelationship(
      PhabricatorSearchRelationship::RELATIONSHIP_AUTHOR,
      $question->getAuthorPHID(),
      PhabricatorPeoplePHIDTypeUser::TYPECONST,
      $question->getDateCreated());

    $answers = id(new PonderAnswerQuery())
      ->setViewer($this->getViewer())
      ->withQuestionIDs(array($question->getID()))
      ->execute();
    foreach ($answers as $answer) {
      if (strlen($answer->getContent())) {
        $doc->addField(
          PhabricatorSearchField::FIELD_COMMENT,
          $answer->getContent());
      }
    }

    $this->indexTransactions(
      $doc,
      new PonderQuestionTransactionQuery(),
      array($phid));
    $this->indexTransactions(
      $doc,
      new PonderAnswerTransactionQuery(),
      mpull($answers, 'getPHID'));

    $this->indexSubscribers($doc);

    return $doc;
  }
}
