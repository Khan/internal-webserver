<?php

final class PhabricatorXHPASTViewStreamController
  extends PhabricatorXHPASTViewPanelController {

  public function processRequest() {
    $storage = $this->getStorageTree();
    $input = $storage->getInput();
    $stdout = $storage->getStdout();

    $tree = XHPASTTree::newFromDataAndResolvedExecFuture(
      $input,
      array(0, $stdout, ''));

    $tokens = array();
    foreach ($tree->getRawTokenStream() as $id => $token) {
      $seq = $id;
      $name = $token->getTypeName();
      $title = "Token {$seq}: {$name}";

      $tokens[] = phutil_tag(
        'span',
        array(
          'title' => $title,
          'class' => 'token',
        ),
        $token->getValue());
    }

    return $this->buildXHPASTViewPanelResponse(implode('', $tokens));
  }
}
