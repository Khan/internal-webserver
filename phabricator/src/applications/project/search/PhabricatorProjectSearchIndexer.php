<?php

final class PhabricatorProjectSearchIndexer
  extends PhabricatorSearchDocumentIndexer {

  public function getIndexableObject() {
    return new PhabricatorProject();
  }

  protected function buildAbstractDocumentByPHID($phid) {
    $project = $this->loadDocumentByPHID($phid);

    $doc = new PhabricatorSearchAbstractDocument();
    $doc->setPHID($project->getPHID());
    $doc->setDocumentType(PhabricatorProjectPHIDTypeProject::TYPECONST);
    $doc->setDocumentTitle($project->getName());
    $doc->setDocumentCreated($project->getDateCreated());
    $doc->setDocumentModified($project->getDateModified());

    $doc->addRelationship(
      $project->isArchived()
        ? PhabricatorSearchRelationship::RELATIONSHIP_CLOSED
        : PhabricatorSearchRelationship::RELATIONSHIP_OPEN,
      $project->getPHID(),
      PhabricatorProjectPHIDTypeProject::TYPECONST,
      time());

    // NOTE: This could be more full-featured, but for now we're mostly
    // interested in the side effects of indexing.

    return $doc;
  }
}
