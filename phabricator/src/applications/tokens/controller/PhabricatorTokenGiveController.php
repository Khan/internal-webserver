<?php

final class PhabricatorTokenGiveController extends PhabricatorTokenController {

  private $phid;

  public function willProcessRequest(array $data) {
    $this->phid = $data['phid'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $handle = PhabricatorObjectHandleData::loadOneHandle($this->phid, $user);
    if (!$handle->isComplete()) {
      return new Aphront404Response();
    }

    $current = id(new PhabricatorTokenGivenQuery())
      ->setViewer($user)
      ->withAuthorPHIDs(array($user->getPHID()))
      ->withObjectPHIDs(array($handle->getPHID()))
      ->execute();

    if ($current) {
      $is_give = false;
      $title = pht('Rescind Token');
    } else {
      $is_give = true;
      $title = pht('Give Token');
    }

    $done_uri = $handle->getURI();
    if ($request->isDialogFormPost()) {
      if ($is_give) {
        $token_phid = $request->getStr('tokenPHID');
        $editor = id(new PhabricatorTokenGivenEditor())
          ->setActor($user)
          ->addToken($handle->getPHID(), $token_phid);
      } else {
        $editor = id(new PhabricatorTokenGivenEditor())
          ->setActor($user)
          ->deleteToken($handle->getPHID());
      }

      return id(new AphrontReloadResponse())->setURI($done_uri);
    }

    if ($is_give) {
      $dialog = $this->buildGiveTokenDialog();
    } else {
      $dialog = $this->buildRescindTokenDialog(head($current));
    }

    $dialog->setUser($user);
    $dialog->addCancelButton($done_uri);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private function buildGiveTokenDialog() {
    $user = $this->getRequest()->getUser();

    $tokens = id(new PhabricatorTokenQuery())
      ->setViewer($user)
      ->execute();

    $buttons = array();
    $ii = 0;
    foreach ($tokens as $token) {
      $buttons[] = javelin_tag(
        'button',
        array(
          'class' => 'token-button',
          'name' => 'tokenPHID',
          'value' => $token->getPHID(),
          'type' => 'submit',
          'sigil' => 'has-tooltip',
          'meta' => array(
            'tip' => $token->getName(),
          )
        ),
        $token->renderIcon());
      if ((++$ii % 4) == 0) {
        $buttons[] = phutil_tag('br');
      }
    }

    $buttons = phutil_tag(
      'div',
      array(
        'class' => 'token-grid',
      ),
      $buttons);

    $dialog = new AphrontDialogView();
    $dialog->setTitle(pht('Give Token'));
    $dialog->appendChild($buttons);

    return $dialog;
  }

  private function buildRescindTokenDialog(PhabricatorTokenGiven $token_given) {
    $dialog = new AphrontDialogView();
    $dialog->setTitle(pht('Rescind Token'));

    $dialog->appendChild(
      pht('Really rescind this lovely token?'));

    $dialog->addSubmitButton(pht('Rescind Token'));

    return $dialog;
  }

}
