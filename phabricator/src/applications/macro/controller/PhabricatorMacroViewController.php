<?php

final class PhabricatorMacroViewController
  extends PhabricatorMacroController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $macro = id(new PhabricatorMacroQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$macro) {
      return new Aphront404Response();
    }

    $file = $macro->getFile();

    $title_short = pht('Macro "%s"', $macro->getName());
    $title_long  = pht('Image Macro "%s"', $macro->getName());

    $actions = $this->buildActionView($macro);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->setActionList($actions);
    $crumbs->addTextCrumb(
      $title_short,
      $this->getApplicationURI('/view/'.$macro->getID().'/'));

    $properties = $this->buildPropertyView($macro, $actions);
    if ($file) {
      $file_view = new PHUIPropertyListView();
      $file_view->addImageContent(
        phutil_tag(
          'img',
          array(
            'src'     => $file->getViewURI(),
            'class'   => 'phabricator-image-macro-hero',
          )));
    }

    $xactions = id(new PhabricatorMacroTransactionQuery())
      ->setViewer($request->getUser())
      ->withObjectPHIDs(array($macro->getPHID()))
      ->execute();

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($user);
    foreach ($xactions as $xaction) {
      if ($xaction->getComment()) {
        $engine->addObject(
          $xaction->getComment(),
          PhabricatorApplicationTransactionComment::MARKUP_FIELD_COMMENT);
      }
    }
    $engine->process();

    $timeline = id(new PhabricatorApplicationTransactionView())
      ->setUser($user)
      ->setObjectPHID($macro->getPHID())
      ->setTransactions($xactions)
      ->setMarkupEngine($engine);

    $header = id(new PHUIHeaderView())
      ->setUser($user)
      ->setPolicyObject($macro)
      ->setHeader($title_long);

    if ($macro->getIsDisabled()) {
      $header->addTag(
        id(new PHUITagView())
          ->setType(PHUITagView::TYPE_STATE)
          ->setName(pht('Macro Disabled'))
          ->setBackgroundColor(PHUITagView::COLOR_BLACK));
    }

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $comment_header = $is_serious
      ? pht('Add Comment')
      : pht('Grovel in Awe');

    $submit_button_name = $is_serious
      ? pht('Add Comment')
      : pht('Lavish Praise');

    $draft = PhabricatorDraft::newFromUserAndKey($user, $macro->getPHID());

    $add_comment_form = id(new PhabricatorApplicationTransactionCommentView())
      ->setUser($user)
      ->setObjectPHID($macro->getPHID())
      ->setDraft($draft)
      ->setHeaderText($comment_header)
      ->setAction($this->getApplicationURI('/comment/'.$macro->getID().'/'))
      ->setSubmitButtonName($submit_button_name);

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    if ($file_view) {
      $object_box->addPropertyList($file_view);
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
        $timeline,
        $add_comment_form,
      ),
      array(
        'title' => $title_short,
        'device' => true,
      ));
  }

  private function buildActionView(PhabricatorFileImageMacro $macro) {

    $can_manage = $this->hasApplicationCapability(
      PhabricatorMacroCapabilityManage::CAPABILITY);

    $request = $this->getRequest();
    $view = id(new PhabricatorActionListView())
      ->setUser($request->getUser())
      ->setObject($macro)
      ->setObjectURI($request->getRequestURI())
      ->addAction(
        id(new PhabricatorActionView())
        ->setName(pht('Edit Macro'))
        ->setHref($this->getApplicationURI('/edit/'.$macro->getID().'/'))
        ->setDisabled(!$can_manage)
        ->setWorkflow(!$can_manage)
        ->setIcon('edit'));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Edit Audio'))
        ->setHref($this->getApplicationURI('/audio/'.$macro->getID().'/'))
        ->setDisabled(!$can_manage)
        ->setWorkflow(!$can_manage)
        ->setIcon('herald'));

    if ($macro->getIsDisabled()) {
      $view->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Restore Macro'))
          ->setHref($this->getApplicationURI('/disable/'.$macro->getID().'/'))
          ->setWorkflow(true)
          ->setDisabled(!$can_manage)
          ->setIcon('undo'));
    } else {
      $view->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Disable Macro'))
          ->setHref($this->getApplicationURI('/disable/'.$macro->getID().'/'))
          ->setWorkflow(true)
          ->setDisabled(!$can_manage)
          ->setIcon('delete'));
    }

    return $view;
  }

  private function buildPropertyView(
    PhabricatorFileImageMacro $macro,
    PhabricatorActionListView $actions) {

    $view = id(new PHUIPropertyListView())
      ->setUser($this->getRequest()->getUser())
      ->setObject($macro)
      ->setActionList($actions);

    switch ($macro->getAudioBehavior()) {
      case PhabricatorFileImageMacro::AUDIO_BEHAVIOR_ONCE:
        $view->addProperty(pht('Audio Behavior'), pht('Play Once'));
        break;
      case PhabricatorFileImageMacro::AUDIO_BEHAVIOR_LOOP:
        $view->addProperty(pht('Audio Behavior'), pht('Loop'));
        break;
    }

    $audio_phid = $macro->getAudioPHID();
    if ($audio_phid) {
      $this->loadHandles(array($audio_phid));
      $view->addProperty(
        pht('Audio'),
        $this->getHandle($audio_phid)->renderLink());
    }

    $view->invokeWillRenderEvent();

    return $view;
  }

}
