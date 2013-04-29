<?php

abstract class PhortuneController extends PhabricatorController {

  protected function loadActiveAccount(PhabricatorUser $user) {
    $accounts = id(new PhortuneAccountQuery())
      ->setViewer($user)
      ->withMemberPHIDs(array($user->getPHID()))
      ->execute();

    if (!$accounts) {
      return $this->createUserAccount($user);
    } else if (count($accounts) == 1) {
      return head($accounts);
    } else {
      throw new Exception("TODO: No account selection yet.");
    }
  }

  protected function createUserAccount(PhabricatorUser $user) {
    $request = $this->getRequest();

    $xactions = array();
    $xactions[] = id(new PhortuneAccountTransaction())
      ->setTransactionType(PhortuneAccountTransaction::TYPE_NAME)
      ->setNewValue(pht('Account (%s)', $user->getUserName()));

    $xactions[] = id(new PhortuneAccountTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue(
        'edge:type',
        PhabricatorEdgeConfig::TYPE_ACCOUNT_HAS_MEMBER)
      ->setNewValue(
        array(
          '=' => array($user->getPHID() => $user->getPHID()),
        ));

    $account = new PhortuneAccount();

    $editor = id(new PhortuneAccountEditor())
      ->setActor($user)
      ->setContentSource(
        PhabricatorContentSource::newForSource(
          PhabricatorContentSource::SOURCE_WEB,
          array(
            'ip' => $request->getRemoteAddr(),
          )));

    // We create an account for you the first time you visit Phortune.
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

      $editor->applyTransactions($account, $xactions);

    unset($unguarded);

    return $account;
  }


}
