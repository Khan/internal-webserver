<?php

final class HeraldNewController extends HeraldController {

  private $contentType;
  private $ruleType;

  public function willProcessRequest(array $data) {
    $this->contentType = idx($data, 'type');
    $this->ruleType = idx($data, 'rule_type');
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $content_type_map = HeraldContentTypeConfig::getContentTypeMap();
    if (empty($content_type_map[$this->contentType])) {
      $this->contentType = head_key($content_type_map);
    }

    $rule_type_map = HeraldRuleTypeConfig::getRuleTypeMap();
    if (empty($rule_type_map[$this->ruleType])) {
      $this->ruleType = HeraldRuleTypeConfig::RULE_TYPE_PERSONAL;
    }

    // Reorder array to put "personal" first.
    $rule_type_map = array_select_keys(
      $rule_type_map,
      array(
        HeraldRuleTypeConfig::RULE_TYPE_PERSONAL,
      )) + $rule_type_map;

    $captions = array(
      HeraldRuleTypeConfig::RULE_TYPE_PERSONAL =>
        pht('Personal rules notify you about events. You own them, but '.
        'they can only affect you.'),
      HeraldRuleTypeConfig::RULE_TYPE_GLOBAL =>
        pht('Global rules notify anyone about events. No one owns them, and '.
        'anyone can edit them. Usually, Global rules are used to notify '.
        'mailing lists.'),
    );

    $radio = id(new AphrontFormRadioButtonControl())
      ->setLabel(pht('Type'))
      ->setName('rule_type')
      ->setValue($this->ruleType);

    foreach ($rule_type_map as $value => $name) {
      $radio->addButton(
        $value,
        $name,
        idx($captions, $value));
    }

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setAction('/herald/rule/')
      ->setFlexible(true)
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('New rule for'))
          ->setName('content_type')
          ->setValue($this->contentType)
          ->setOptions($content_type_map))
      ->appendChild($radio)
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Create Rule'))
          ->addCancelButton('/herald/view/'.$this->contentType.'/'));

    $crumbs = $this
      ->buildApplicationCrumbs()
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Create Herald Rule'))
          ->setHref($this->getApplicationURI(
            'view/'.$this->contentType.'/'.$this->ruleType)));

    $nav = $this->renderNav();
    $nav->selectFilter('new');
    $nav->appendChild($form);
    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Create Herald Rule'),
        'device' => true,
        'dust' => true,
      ));
  }

}
