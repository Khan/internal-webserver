<?php

final class PhabricatorProjectPHIDTypeProject extends PhabricatorPHIDType {

  const TYPECONST = 'PROJ';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Project');
  }

  public function getTypeIcon() {
    return 'policy-project';
  }

  public function newObject() {
    return new PhabricatorProject();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorProjectQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $project = $objects[$phid];

      $name = $project->getName();
      $id = $project->getID();

      $handle->setName($name);
      $handle->setObjectName('#'.rtrim($project->getPhrictionSlug(), '/'));
      $handle->setURI("/project/view/{$id}/");

      if ($project->isArchived()) {
        $handle->setStatus(PhabricatorObjectHandleStatus::STATUS_CLOSED);
      }
    }
  }

  public static function getProjectMonogramPatternFragment() {
    // NOTE: This explicitly does not match strings which contain only
    // digits, because digit strings like "#123" are used to reference tasks at
    // Facebook and are somewhat conventional in general.
    return '[^\s.!,:;{}#]*[^\s\d.!,:;{}#]+[^\s.!,:;{}#]*';
  }

  public function canLoadNamedObject($name) {
    $fragment = self::getProjectMonogramPatternFragment();
    return preg_match('/^#'.$fragment.'$/i', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    // If the user types "#YoloSwag", we still want to match "#yoloswag", so
    // we normalize, query, and then map back to the original inputs.

    $map = array();
    foreach ($names as $key => $slug) {
      $map[$this->normalizeSlug(substr($slug, 1))][] = $slug;
    }

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($query->getViewer())
      ->withPhrictionSlugs(array_keys($map))
      ->execute();

    $result = array();
    foreach ($projects as $project) {
      $slugs = array($project->getPhrictionSlug());
      foreach ($slugs as $slug) {
        foreach ($map[$slug] as $original) {
          $result[$original] = $project;
        }
      }
    }

    return $result;
  }

  private function normalizeSlug($slug) {
    // NOTE: We're using phutil_utf8_strtolower() (and not PhabricatorSlug's
    // normalize() method) because this normalization should be only somewhat
    // liberal. We want "#YOLO" to match against "#yolo", but "#\\yo!!lo"
    // should not. normalize() strips out most punctuation and leads to
    // excessively aggressive matches.

    return phutil_utf8_strtolower($slug).'/';
  }


}
