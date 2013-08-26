<?php

final class PhabricatorAuthProviderConfigTransaction
  extends PhabricatorApplicationTransaction {

  const TYPE_ENABLE         = 'config:enable';
  const TYPE_REGISTRATION   = 'config:registration';
  const TYPE_LINK           = 'config:link';
  const TYPE_UNLINK         = 'config:unlink';
  const TYPE_PROPERTY       = 'config:property';

  const PROPERTY_KEY        = 'auth:property';

  private $provider;

  public function setProvider(PhabricatorAuthProvider $provider) {
    $this->provider = $provider;
    return $this;
  }

  public function getProvider() {
    return $this->provider;
  }

  public function getApplicationName() {
    return 'auth';
  }

  public function getApplicationTransactionType() {
    return PhabricatorPHIDConstants::PHID_TYPE_AUTH;
  }

  public function getApplicationTransactionCommentObject() {
    return null;
  }

  public function getIcon() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_ENABLE:
        if ($new) {
          return 'new';
        } else {
          return 'delete';
        }
    }

    return parent::getIcon();
  }

  public function getColor() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_ENABLE:
        if ($new) {
          return 'green';
        } else {
          return 'red';
        }
    }

    return parent::getColor();
  }

  public function getTitle() {
    $author_phid = $this->getAuthorPHID();

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_ENABLE:
        if ($old === null) {
          return pht(
            '%s created this provider.',
            $this->renderHandleLink($author_phid));
        } else if ($new) {
          return pht(
            '%s enabled this provider.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s disabled this provider.',
            $this->renderHandleLink($author_phid));
        }
        break;
      case self::TYPE_REGISTRATION:
        if ($new) {
          return pht(
            '%s enabled registration.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s disabled registration.',
            $this->renderHandleLink($author_phid));
        }
        break;
      case self::TYPE_LINK:
        if ($new) {
          return pht(
            '%s enabled accont linking.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s disabled account linking.',
            $this->renderHandleLink($author_phid));
        }
        break;
      case self::TYPE_UNLINK:
        if ($new) {
          return pht(
            '%s enabled account unlinking.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s disabled account unlinking.',
            $this->renderHandleLink($author_phid));
        }
        break;
      case self::TYPE_PROPERTY:
        $provider = $this->getProvider();
        if ($provider) {
          $title = $provider->renderConfigPropertyTransactionTitle($this);
          if (strlen($title)) {
            return $title;
          }
        }

        return pht(
          '%s edited a property of this provider.',
          $this->renderHandleLink($author_phid));
        break;
    }

    return parent::getTitle();
  }

}

