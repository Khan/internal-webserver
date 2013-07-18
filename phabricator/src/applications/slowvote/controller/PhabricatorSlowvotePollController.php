<?php

/**
 * @group slowvote
 */
final class PhabricatorSlowvotePollController
  extends PhabricatorSlowvoteController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $poll = id(new PhabricatorSlowvoteQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->needOptions(true)
      ->needChoices(true)
      ->needViewerChoices(true)
      ->executeOne();
    if (!$poll) {
      return new Aphront404Response();
    }

    $poll_view = id(new SlowvoteEmbedView())
      ->setHeadless(true)
      ->setUser($user)
      ->setPoll($poll);

    if ($request->isAjax()) {
      return id(new AphrontAjaxResponse())
        ->setContent(
          array(
            'pollID' => $poll->getID(),
            'contentHTML' => $poll_view->render(),
          ));
    }

    $header = id(new PhabricatorHeaderView())
      ->setHeader($poll->getQuestion());

    $xaction_header = id(new PhabricatorHeaderView())
      ->setHeader(pht('Ongoing Deliberations'));

    $actions = $this->buildActionView($poll);
    $properties = $this->buildPropertyView($poll);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName('V'.$poll->getID()));

    $xactions = $this->buildTransactions($poll);
    $add_comment = $this->buildCommentForm($poll);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $header,
        $actions,
        $properties,
        phutil_tag(
          'div',
          array(
            'class' => 'ml',
          ),
          $poll_view),
        $xaction_header,
        $xactions,
        $add_comment,
      ),
      array(
        'title' => 'V'.$poll->getID().' '.$poll->getQuestion(),
        'device' => true,
        'dust' => true,
        'pageObjects' => array($poll->getPHID()),
      ));
  }

  private function buildActionView(PhabricatorSlowvotePoll $poll) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer)
      ->setObject($poll);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $poll,
      PhabricatorPolicyCapability::CAN_EDIT);

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Edit Poll'))
        ->setIcon('edit')
        ->setHref($this->getApplicationURI('edit/'.$poll->getID().'/'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    return $view;
  }

  private function buildPropertyView(PhabricatorSlowvotePoll $poll) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorPropertyListView())
      ->setUser($viewer)
      ->setObject($poll);

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $viewer,
      $poll);

    $view->addProperty(
      pht('Visible To'),
      $descriptions[PhabricatorPolicyCapability::CAN_VIEW]);

    $view->invokeWillRenderEvent();

    if (strlen($poll->getDescription())) {
      $view->addTextContent(
        $output = PhabricatorMarkupEngine::renderOneObject(
          id(new PhabricatorMarkupOneOff())->setContent(
            $poll->getDescription()),
          'default',
          $viewer));
    }

    return $view;
  }

  private function buildTransactions(PhabricatorSlowvotePoll $poll) {
    $viewer = $this->getRequest()->getUser();

    $xactions = id(new PhabricatorSlowvoteTransactionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($poll->getPHID()))
      ->execute();

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($viewer);
    foreach ($xactions as $xaction) {
      if ($xaction->getComment()) {
        $engine->addObject(
          $xaction->getComment(),
          PhabricatorApplicationTransactionComment::MARKUP_FIELD_COMMENT);
      }
    }
    $engine->process();

    $timeline = id(new PhabricatorApplicationTransactionView())
      ->setUser($viewer)
      ->setTransactions($xactions)
      ->setMarkupEngine($engine);

    return $timeline;
  }

  private function buildCommentForm(PhabricatorSlowvotePoll $poll) {
    $viewer = $this->getRequest()->getUser();

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $add_comment_header = id(new PhabricatorHeaderView())
      ->setHeader(
        $is_serious
          ? pht('Add Comment')
          : pht('Enter Deliberations'));

    $submit_button_name = $is_serious
      ? pht('Add Comment')
      : pht('Perhaps');

    $draft = PhabricatorDraft::newFromUserAndKey($viewer, $poll->getPHID());

    $add_comment_form = id(new PhabricatorApplicationTransactionCommentView())
      ->setUser($viewer)
      ->setDraft($draft)
      ->setAction($this->getApplicationURI('/comment/'.$poll->getID().'/'))
      ->setSubmitButtonName($submit_button_name);

    return array(
      $add_comment_header,
      $add_comment_form,
    );
  }


}
