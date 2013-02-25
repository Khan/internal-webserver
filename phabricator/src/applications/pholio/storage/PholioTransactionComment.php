<?php

/**
 * @group pholio
 */
final class PholioTransactionComment
  extends PhabricatorApplicationTransactionComment {

  protected $imageID;
  protected $x;
  protected $y;
  protected $width;
  protected $height;

  public function getApplicationTransactionObject() {
    return new PholioTransaction();
  }

  public function toDictionary() {
    return array(
      'id' => $this->getID(),
      'phid' => $this->getPHID(),
      'transactionphid' => $this->getTransactionPHID(),
      'x' => $this->getX(),
      'y' => $this->getY(),
      'width' => $this->getWidth(),
      'height' => $this->getHeight(),
    );
  }

}
