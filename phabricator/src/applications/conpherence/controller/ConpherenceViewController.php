<?php

/**
 * @group conpherence
 */
final class ConpherenceViewController extends
  ConpherenceController {

  private $conpherenceID;
  private $conpherence;

  public function setConpherence(ConpherenceThread $conpherence) {
    $this->conpherence = $conpherence;
    return $this;
  }
  public function getConpherence() {
    return $this->conpherence;
  }

  public function setConpherenceID($conpherence_id) {
    $this->conpherenceID = $conpherence_id;
    return $this;
  }
  public function getConpherenceID() {
    return $this->conpherenceID;
  }

  public function willProcessRequest(array $data) {
    $this->setConpherenceID(idx($data, 'id'));
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $conpherence_id = $this->getConpherenceID();
    if (!$conpherence_id) {
      return new Aphront404Response();
    }
    $conpherence = id(new ConpherenceThreadQuery())
      ->setViewer($user)
      ->withIDs(array($conpherence_id))
      ->needHeaderPics(true)
      ->needAllTransactions(true)
      ->executeOne();
    $this->setConpherence($conpherence);

    $participant = $conpherence->getParticipant($user->getPHID());
    $transactions = $conpherence->getTransactions();
    $latest_transaction = end($transactions);
    $write_guard = AphrontWriteGuard::beginScopedUnguardedWrites();
    $participant->markUpToDate($latest_transaction);
    unset($write_guard);

    $header = $this->renderHeaderPaneContent();
    $messages = $this->renderMessagePaneContent();
    $content = $header + $messages;

    if ($request->isAjax()) {
      return id(new AphrontAjaxResponse())->setContent($content);
    }

    $layout = id(new ConpherenceLayoutView())
      ->setBaseURI($this->getApplicationURI())
      ->setThread($conpherence)
      ->setHeader($header)
      ->setMessages($messages['messages'])
      ->setReplyForm($messages['form'])
      ->setRole('thread');

    return $this->buildApplicationPage(
      $layout,
      array(
        'title' => $conpherence->getTitle(),
        'device' => true,
      ));
  }

  private function renderHeaderPaneContent() {
    require_celerity_resource('conpherence-header-pane-css');
    $conpherence = $this->getConpherence();
    $header = $this->buildHeaderPaneContent($conpherence);
    return array('header' => hsprintf('%s', $header));
  }


  private function renderMessagePaneContent() {
    require_celerity_resource('conpherence-message-pane-css');
    $user = $this->getRequest()->getUser();
    $conpherence = $this->getConpherence();

    $data = $this->renderConpherenceTransactions($conpherence);
    $latest_transaction_id = $data['latest_transaction_id'];
    $transactions = $data['transactions'];

    $update_uri = $this->getApplicationURI('update/'.$conpherence->getID().'/');

    Javelin::initBehavior('conpherence-pontificate');

    $form =
      id(new AphrontFormView())
      ->setAction($update_uri)
      ->setFlexible(true)
      ->addSigil('conpherence-pontificate')
      ->setWorkflow(true)
      ->setUser($user)
      ->addHiddenInput('action', 'message')
      ->addHiddenInput('latest_transaction_id', $latest_transaction_id)
      ->appendChild(
        id(new PhabricatorRemarkupControl())
        ->setUser($user)
        ->setName('text'))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Pontificate')))
      ->render();

    $scrollbutton = javelin_tag(
      'a',
      array(
        'href' => '#',
        'mustcapture' => true,
        'sigil' => 'show-older-messages',
        'class' => 'conpherence-show-older-messages',
      ),
      pht('Show Older Messages'));

    return array(
      'messages' => hsprintf('%s%s', $scrollbutton, $transactions),
      'form' => $form
    );

  }

}
