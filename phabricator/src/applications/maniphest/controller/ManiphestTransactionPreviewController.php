<?php

/**
 * @group maniphest
 */
final class ManiphestTransactionPreviewController extends ManiphestController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $comments = $request->getStr('comments');

    $task = id(new ManiphestTaskQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$task) {
      return new Aphront404Response();
    }

    id(new PhabricatorDraft())
      ->setAuthorPHID($user->getPHID())
      ->setDraftKey($task->getPHID())
      ->setDraft($comments)
      ->replaceOrDelete();

    $action = $request->getStr('action');

    $transaction = new ManiphestTransaction();
    $transaction->setAuthorPHID($user->getPHID());
    $transaction->setTransactionType($action);

    // This should really be split into a separate transaction, but it should
    // all come out in the wash once we fully move to modern stuff.
    $transaction->attachComment(
      id(new ManiphestTransactionComment())
        ->setContent($comments));

    $value = $request->getStr('value');
    // grab phids for handles and set transaction values based on action and
    // value (empty or control-specific format) coming in from the wire
    switch ($action) {
      case ManiphestTransaction::TYPE_PRIORITY:
        $transaction->setOldValue($task->getPriority());
        $transaction->setNewValue($value);
        break;
      case ManiphestTransaction::TYPE_OWNER:
        if ($value) {
          $value = current(json_decode($value));
          $phids = array($value);
        } else {
          $phids = array();
        }
        $transaction->setNewValue($value);
        break;
      case ManiphestTransaction::TYPE_CCS:
        if ($value) {
          $value = json_decode($value);
        }
        if (!$value) {
          $value = array();
        }
        $phids = $value;

        foreach ($task->getCCPHIDs() as $cc_phid) {
          $phids[] = $cc_phid;
          $value[] = $cc_phid;
        }

        $transaction->setOldValue($task->getCCPHIDs());
        $transaction->setNewValue($value);
        break;
      case ManiphestTransaction::TYPE_PROJECTS:
        if ($value) {
          $value = json_decode($value);
        }
        if (!$value) {
          $value = array();
        }

        $phids = $value;
        foreach ($task->getProjectPHIDs() as $project_phid) {
          $phids[] = $project_phid;
          $value[] = $project_phid;
        }

        $transaction->setOldValue($task->getProjectPHIDs());
        $transaction->setNewValue($value);
        break;
      case ManiphestTransaction::TYPE_STATUS:
        $phids = array();
        $transaction->setOldValue($task->getStatus());
        $transaction->setNewValue($value);
        break;
      default:
        $phids = array();
        $transaction->setNewValue($value);
        break;
    }
    $phids[] = $user->getPHID();

    $handles = $this->loadViewerHandles($phids);

    $transactions = array();
    $transactions[] = $transaction;

    $engine = new PhabricatorMarkupEngine();
    $engine->setViewer($user);
    if ($transaction->hasComment()) {
      $engine->addObject(
        $transaction->getComment(),
        PhabricatorApplicationTransactionComment::MARKUP_FIELD_COMMENT);
    }
    $engine->process();

    $transaction->setHandles($handles);

    $view = id(new PhabricatorApplicationTransactionView())
      ->setUser($user)
      ->setTransactions($transactions)
      ->setIsPreview(true);

    return id(new AphrontAjaxResponse())
      ->setContent((string)phutil_implode_html('', $view->buildEvents()));
  }

}
