<?php

/**
 * @group pholio
 */
final class PholioMockEditor extends PhabricatorApplicationTransactionEditor {

  private $newImages = array();
  private function setNewImages(array $new_images) {
    assert_instances_of($new_images, 'PholioImage');
    $this->newImages = $new_images;
    return $this;
  }
  private function getNewImages() {
    return $this->newImages;
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_EDGE;
    $types[] = PhabricatorTransactions::TYPE_COMMENT;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;

    $types[] = PholioTransactionType::TYPE_NAME;
    $types[] = PholioTransactionType::TYPE_DESCRIPTION;
    $types[] = PholioTransactionType::TYPE_INLINE;

    $types[] = PholioTransactionType::TYPE_IMAGE_FILE;
    $types[] = PholioTransactionType::TYPE_IMAGE_NAME;
    $types[] = PholioTransactionType::TYPE_IMAGE_DESCRIPTION;
    $types[] = PholioTransactionType::TYPE_IMAGE_REPLACE;
    $types[] = PholioTransactionType::TYPE_IMAGE_SEQUENCE;

    return $types;
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PholioTransactionType::TYPE_NAME:
        return $object->getName();
      case PholioTransactionType::TYPE_DESCRIPTION:
        return $object->getDescription();
      case PholioTransactionType::TYPE_IMAGE_FILE:
        $images = $object->getImages();
        return mpull($images, 'getPHID');
      case PholioTransactionType::TYPE_IMAGE_NAME:
        $name = null;
        $phid = null;
        $image = $this->getImageForXaction($object, $xaction);
        if ($image) {
          $name = $image->getName();
          $phid = $image->getPHID();
        }
        return array($phid => $name);
      case PholioTransactionType::TYPE_IMAGE_DESCRIPTION:
        $description = null;
        $phid = null;
        $image = $this->getImageForXaction($object, $xaction);
        if ($image) {
          $description = $image->getDescription();
          $phid = $image->getPHID();
        }
        return array($phid => $description);
      case PholioTransactionType::TYPE_IMAGE_REPLACE:
        $raw = $xaction->getNewValue();
        return $raw->getReplacesImagePHID();
      case PholioTransactionType::TYPE_IMAGE_SEQUENCE:
        $sequence = null;
        $phid = null;
        $image = $this->getImageForXaction($object, $xaction);
        if ($image) {
          $sequence = $image->getSequence();
          $phid = $image->getPHID();
        }
        return array($phid => $sequence);
    }
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PholioTransactionType::TYPE_NAME:
      case PholioTransactionType::TYPE_DESCRIPTION:
      case PholioTransactionType::TYPE_IMAGE_NAME:
      case PholioTransactionType::TYPE_IMAGE_DESCRIPTION:
      case PholioTransactionType::TYPE_IMAGE_SEQUENCE:
        return $xaction->getNewValue();
      case PholioTransactionType::TYPE_IMAGE_REPLACE:
        $raw = $xaction->getNewValue();
        return $raw->getPHID();
      case PholioTransactionType::TYPE_IMAGE_FILE:
        $raw_new_value = $xaction->getNewValue();
        $new_value = array();
        foreach ($raw_new_value as $key => $images) {
          $new_value[$key] = mpull($images, 'getPHID');
        }
        $xaction->setNewValue($new_value);
        return $this->getPHIDTransactionNewValue($xaction);
    }
  }

  protected function transactionHasEffect(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PholioTransactionType::TYPE_INLINE:
        return true;
    }

    return parent::transactionHasEffect($object, $xaction);
  }

  protected function shouldApplyInitialEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PholioTransactionType::TYPE_IMAGE_FILE:
        case PholioTransactionType::TYPE_IMAGE_REPLACE:
          return true;
          break;
      }
    }
    return false;
  }

  protected function applyInitialEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $new_images = array();
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PholioTransactionType::TYPE_IMAGE_FILE:
          $new_value = $xaction->getNewValue();
          foreach ($new_value as $key => $txn_images) {
            if ($key != '+') {
              continue;
            }
            foreach ($txn_images as $image) {
              $image->save();
              $new_images[] = $image;
            }
          }
          break;
        case PholioTransactionType::TYPE_IMAGE_REPLACE:
          $image = $xaction->getNewValue();
          $image->save();
          $new_images[] = $image;
          break;
      }
    }
    $this->setNewImages($new_images);
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PholioTransactionType::TYPE_NAME:
        $object->setName($xaction->getNewValue());
        if ($object->getOriginalName() === null) {
          $object->setOriginalName($xaction->getNewValue());
        }
        break;
      case PholioTransactionType::TYPE_DESCRIPTION:
        $object->setDescription($xaction->getNewValue());
        break;
    }
  }

  private function getImageForXaction(
    PholioMock $mock,
    PhabricatorApplicationTransaction $xaction) {
    $raw_new_value = $xaction->getNewValue();
    $image_phid = key($raw_new_value);
    $images = $mock->getImages();
    foreach ($images as $image) {
      if ($image->getPHID() == $image_phid) {
        return $image;
      }
    }
    return null;
  }

  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PholioTransactionType::TYPE_IMAGE_FILE:
        $old_map = array_fuse($xaction->getOldValue());
        $new_map = array_fuse($xaction->getNewValue());

        $obsolete_map = array_diff_key($old_map, $new_map);
        $images = $object->getImages();
        foreach ($images as $seq => $image) {
          if (isset($obsolete_map[$image->getPHID()])) {
            $image->setIsObsolete(1);
            $image->save();
            unset($images[$seq]);
          }
        }
        $object->attachImages($images);
        break;
      case PholioTransactionType::TYPE_IMAGE_REPLACE:
        $old = $xaction->getOldValue();
        $images = $object->getImages();
        foreach ($images as $seq => $image) {
          if ($image->getPHID() == $old) {
            $image->setIsObsolete(1);
            $image->save();
            unset($images[$seq]);
          }
        }
        $object->attachImages($images);
        break;
      case PholioTransactionType::TYPE_IMAGE_NAME:
        $image = $this->getImageForXaction($object, $xaction);
        $value = (string) head($xaction->getNewValue());
        $image->setName($value);
        $image->save();
        break;
      case PholioTransactionType::TYPE_IMAGE_DESCRIPTION:
        $image = $this->getImageForXaction($object, $xaction);
        $value = (string) head($xaction->getNewValue());
        $image->setDescription($value);
        $image->save();
        break;
      case PholioTransactionType::TYPE_IMAGE_SEQUENCE:
        $image = $this->getImageForXaction($object, $xaction);
        $value = (int) head($xaction->getNewValue());
        $image->setSequence($value);
        $image->save();
        break;
    }
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $images = $this->getNewImages();
    foreach ($images as $image) {
      $image->setMockID($object->getID());
      $image->save();
    }
  }

  protected function mergeTransactions(
    PhabricatorApplicationTransaction $u,
    PhabricatorApplicationTransaction $v) {

    $type = $u->getTransactionType();
    switch ($type) {
      case PholioTransactionType::TYPE_NAME:
      case PholioTransactionType::TYPE_DESCRIPTION:
        return $v;
      case PholioTransactionType::TYPE_IMAGE_REPLACE:
        if ($u->getNewValue() == $v->getOldValue()) {
          return $v;
        }
      case PholioTransactionType::TYPE_IMAGE_FILE:
        return $this->mergePHIDOrEdgeTransactions($u, $v);
      case PholioTransactionType::TYPE_IMAGE_NAME:
      case PholioTransactionType::TYPE_IMAGE_DESCRIPTION:
      case PholioTransactionType::TYPE_IMAGE_SEQUENCE:
        $raw_new_value_u = $u->getNewValue();
        $raw_new_value_v = $v->getNewValue();
        $phid_u = key($raw_new_value_u);
        $phid_v = key($raw_new_value_v);
        if ($phid_u == $phid_v) {
          return $v;
        }
        break;
    }

    return parent::mergeTransactions($u, $v);
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new PholioReplyHandler())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $name = $object->getName();
    $original_name = $object->getOriginalName();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("M{$id}: {$name}")
      ->addHeader('Thread-Topic', "M{$id}: {$original_name}");
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    return array(
      $object->getAuthorPHID(),
      $this->requireActor()->getPHID(),
    );
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = new PhabricatorMetaMTAMailBody();
    $headers = array();
    $comments = array();
    $inline_comments = array();

    foreach ($xactions as $xaction) {
      if ($xaction->shouldHide()) {
        continue;
      }
      $comment = $xaction->getComment();
      switch ($xaction->getTransactionType()) {
        case PholioTransactionType::TYPE_INLINE:
          if ($comment && strlen($comment->getContent())) {
            $inline_comments[] = $comment;
          }
          break;
        case PhabricatorTransactions::TYPE_COMMENT:
          if ($comment && strlen($comment->getContent())) {
            $comments[] = $comment->getContent();
          }
        // fallthrough
        default:
          $headers[] = id(clone $xaction)
            ->setRenderingTarget('text')
            ->getTitle();
          break;
      }
    }

    $body->addRawSection(implode("\n", $headers));

    foreach ($comments as $comment) {
      $body->addRawSection($comment);
    }

    if ($inline_comments) {
      $body->addRawSection(pht('INLINE COMMENTS'));
      foreach ($inline_comments as $comment) {
        $text = pht(
          'Image %d: %s',
          $comment->getImageID(),
          $comment->getContent());
        $body->addRawSection($text);
      }
    }

    $body->addTextSection(
      pht('MOCK DETAIL'),
      PhabricatorEnv::getProductionURI('/M'.$object->getID()));

    return $body;
  }

  protected function getMailSubjectPrefix() {
    return PhabricatorEnv::getEnvConfig('metamta.pholio.subject-prefix');
  }

  protected function supportsFeed() {
    return true;
  }

  protected function supportsSearch() {
    return true;
  }

  protected function supportsHerald() {
    return true;
  }

  protected function buildHeraldAdapter(
    PhabricatorLiskDAO $object,
    array $xactions) {

    return id(new HeraldPholioMockAdapter())
      ->setMock($object);
  }

  protected function didApplyHeraldRules(
    PhabricatorLiskDAO $object,
    HeraldAdapter $adapter,
    HeraldTranscript $transcript) {

    $cc_phids = $adapter->getCcPHIDs();
    if ($cc_phids) {
      id(new PhabricatorSubscriptionsEditor())
        ->setObject($object)
        ->setActor($this->requireActor())
        ->subscribeImplicit($cc_phids)
        ->save();
    }
  }

  protected function sortTransactions(array $xactions) {
    $head = array();
    $tail = array();

    // Move inline comments to the end, so the comments precede them.
    foreach ($xactions as $xaction) {
      $type = $xaction->getTransactionType();
      if ($type == PholioTransactionType::TYPE_INLINE) {
        $tail[] = $xaction;
      } else {
        $head[] = $xaction;
      }
    }

    return array_values(array_merge($head, $tail));
  }

  protected function shouldImplyCC(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PholioTransactionType::TYPE_INLINE:
        return true;
    }

    return parent::shouldImplyCC($object, $xaction);
  }

}
