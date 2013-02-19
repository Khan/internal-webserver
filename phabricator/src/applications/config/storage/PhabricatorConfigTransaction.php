<?php

final class PhabricatorConfigTransaction
  extends PhabricatorApplicationTransaction {

  const TYPE_EDIT = 'config:edit';

  public function getApplicationName() {
    return 'config';
  }

  public function getApplicationTransactionType() {
    return PhabricatorPHIDConstants::PHID_TYPE_CONF;
  }

  public function getApplicationTransactionCommentObject() {
    return null;
  }

  public function getApplicationObjectTypeName() {
    return pht('config');
  }


  public function getTitle() {
    $author_phid = $this->getAuthorPHID();

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_EDIT:

        // TODO: After T2213 show the actual values too; for now, we don't
        // have the tools to do it without making a bit of a mess of it.

        $old_del = idx($old, 'deleted');
        $new_del = idx($new, 'deleted');
        if ($old_del && !$new_del) {
          return pht(
            '%s created this configuration entry.',
            $this->renderHandleLink($author_phid));
        } else if (!$old_del && $new_del) {
          return pht(
            '%s deleted this configuration entry.',
            $this->renderHandleLink($author_phid));
        } else if ($old_del && $new_del) {
          // This is a bug.
          return pht(
            '%s deleted this configuration entry (again?).',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s edited this configuration entry.',
            $this->renderHandleLink($author_phid));
        }
        break;
    }

    return parent::getTitle();
  }


  public function getIcon() {
    switch ($this->getTransactionType()) {
      case self::TYPE_EDIT:
        return 'edit';
    }

    return parent::getIcon();
  }

  public function hasChangeDetails() {
    switch ($this->getTransactionType()) {
      case self::TYPE_EDIT:
        return true;
    }
    return parent::hasChangeDetails();
  }

  public function renderChangeDetails() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    if ($old['deleted']) {
      $old_text = '';
    } else {
      // NOTE: Here and below, we're adding a synthetic "\n" to prevent the
      // differ from complaining about missing trailing newlines.
      $old_text = PhabricatorConfigJSON::prettyPrintJSON($old['value'])."\n";
    }

    if ($new['deleted']) {
      $new_text = '';
    } else {
      $new_text = PhabricatorConfigJSON::prettyPrintJSON($new['value'])."\n";
    }

    $view = id(new PhabricatorApplicationTransactionTextDiffDetailView())
      ->setOldText($old_text)
      ->setNewText($new_text);

    return $view->render();
  }

  public function getColor() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_EDIT:
        $old_del = idx($old, 'deleted');
        $new_del = idx($new, 'deleted');

        if ($old_del && !$new_del) {
          return PhabricatorTransactions::COLOR_GREEN;
        } else if (!$old_del && $new_del) {
          return PhabricatorTransactions::COLOR_RED;
        } else {
          return PhabricatorTransactions::COLOR_BLUE;
        }
        break;
    }
  }

}

