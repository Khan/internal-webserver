<?php

/**
 * @group conduit
 */
final class ConduitAPI_differential_close_Method
  extends ConduitAPIMethod {

  public function getMethodDescription() {
    return "Close a Differential revision.";
  }

  public function defineParamTypes() {
    return array(
      'revisionID' => 'required int',
    );
  }

  public function defineReturnType() {
    return 'void';
  }

  public function defineErrorTypes() {
    return array(
      'ERR_NOT_FOUND' => 'Revision was not found.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $id = $request->getValue('revisionID');

    $revision = id(new DifferentialRevisionQuery())
      ->withIDs(array($id))
      ->setViewer($request->getUser())
      ->needRelationships(true)
      ->needReviewerStatus(true)
      ->executeOne();
    if (!$revision) {
      throw new ConduitException('ERR_NOT_FOUND');
    }

    if ($revision->getStatus() == ArcanistDifferentialRevisionStatus::CLOSED) {
      // This can occur if someone runs 'close-revision' and hits a race, or
      // they have a remote hook installed but don't have the
      // 'remote_hook_installed' flag set, or similar. In any case, just treat
      // it as a no-op rather than adding another "X closed this revision"
      // message to the revision comments.
      return;
    }

    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_CONDUIT,
      array());

    $editor = new DifferentialCommentEditor(
      $revision,
      DifferentialAction::ACTION_CLOSE);
    $editor->setContentSource($content_source);
    $editor->setActor($request->getUser());
    $editor->save();

    $revision->setStatus(ArcanistDifferentialRevisionStatus::CLOSED);
    $revision->setDateCommitted(time());
    $revision->save();

    return;
  }

}
