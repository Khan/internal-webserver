<?php

/**
 * @group pholio
 */
final class PholioImage extends PholioDAO
  implements
    PhabricatorMarkupInterface,
    PhabricatorPolicyInterface {

  const MARKUP_FIELD_DESCRIPTION  = 'markup:description';

  protected $mockID;
  protected $filePHID;
  protected $name = '';
  protected $description = '';
  protected $sequence;
  protected $isObsolete = 0;
  protected $replacesImagePHID = null;

  private $inlineComments = self::ATTACHABLE;
  private $file = self::ATTACHABLE;
  private $mock = self::ATTACHABLE;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(PholioPHIDTypeImage::TYPECONST);
  }

  public function attachFile(PhabricatorFile $file) {
    $this->file = $file;
    return $this;
  }

  public function getFile() {
    $this->assertAttached($this->file);
    return $this->file;
  }

  public function attachMock(PholioMock $mock) {
    $this->mock = $mock;
    return $this;
  }

  public function getMock() {
    $this->assertAttached($this->mock);
    return $this->mock;
  }


  public function attachInlineComments(array $inline_comments) {
    assert_instances_of($inline_comments, 'PholioTransactionComment');
    $this->inlineComments = $inline_comments;
    return $this;
  }

  public function getInlineComments() {
    $this->assertAttached($this->inlineComments);
    return $this->inlineComments;
  }


/* -(  PhabricatorMarkupInterface  )----------------------------------------- */


  public function getMarkupFieldKey($field) {
    $hash = PhabricatorHash::digest($this->getMarkupText($field));
    return 'M:'.$hash;
  }

  public function newMarkupEngine($field) {
    return PhabricatorMarkupEngine::newMarkupEngine(array());
  }

  public function getMarkupText($field) {
    return $this->getDescription();
  }

  public function didMarkupText($field, $output, PhutilMarkupEngine $engine) {
    return $output;
  }

  public function shouldUseMarkupCache($field) {
    return (bool)$this->getID();
  }

/* -(  PhabricatorPolicyInterface Implementation  )-------------------------- */

  public function getCapabilities() {
    return $this->getMock()->getCapabilities();
  }

  public function getPolicy($capability) {
    return $this->getMock()->getPolicy($capability);
  }

  // really the *mock* controls who can see an image
  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getMock()->hasAutomaticCapability($capability, $viewer);
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }

}
