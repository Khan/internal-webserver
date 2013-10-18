<?php

final class PhabricatorApplicationMacro extends PhabricatorApplication {

  public function getBaseURI() {
    return '/macro/';
  }

  public function getShortDescription() {
    return pht('Image Macros and Memes');
  }

  public function getIconName() {
    return 'macro';
  }

  public function getTitleGlyph() {
    return "\xE2\x9A\x98";
  }

  public function getApplicationGroup() {
    return self::GROUP_UTILITIES;
  }

  public function getQuickCreateURI() {
    return $this->getBaseURI().'create/';
  }

  public function getRoutes() {
    return array(
      '/macro/' => array(
        '(query/(?P<key>[^/]+)/)?' => 'PhabricatorMacroListController',
        'create/' => 'PhabricatorMacroEditController',
        'view/(?P<id>[1-9]\d*)/' => 'PhabricatorMacroViewController',
        'comment/(?P<id>[1-9]\d*)/' => 'PhabricatorMacroCommentController',
        'edit/(?P<id>[1-9]\d*)/' => 'PhabricatorMacroEditController',
        'audio/(?P<id>[1-9]\d*)/' => 'PhabricatorMacroAudioController',
        'disable/(?P<id>[1-9]\d*)/' => 'PhabricatorMacroDisableController',
        'meme/' => 'PhabricatorMacroMemeController',
        'meme/create/' => 'PhabricatorMacroMemeDialogController',
      ),
    );
  }

  protected function getCustomCapabilities() {
    return array(
      PhabricatorMacroCapabilityManage::CAPABILITY => array(
        'caption' => pht('Allows creating and editing macros.')
      ),
    );
  }

}
