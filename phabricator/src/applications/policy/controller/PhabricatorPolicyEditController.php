<?php

final class PhabricatorPolicyEditController
  extends PhabricatorPolicyController {

  private $phid;

  public function willProcessRequest(array $data) {
    $this->phid = idx($data, 'phid');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $action_options = array(
      PhabricatorPolicy::ACTION_ALLOW => pht('Allow'),
      PhabricatorPolicy::ACTION_DENY => pht('Deny'),
    );

    $rules = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhabricatorPolicyRule')
      ->loadObjects();
    $rules = msort($rules, 'getRuleOrder');

    $default_rule = array(
      'action' => head_key($action_options),
      'rule' => head_key($rules),
      'value' => null,
    );

    if ($this->phid) {
      $policies = id(new PhabricatorPolicyQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($this->phid))
        ->execute();
      if (!$policies) {
        return new Aphront404Response();
      }
      $policy = head($policies);
    } else {
      $policy = id(new PhabricatorPolicy())
        ->setRules(array($default_rule))
        ->setDefaultAction(PhabricatorPolicy::ACTION_DENY);
    }

    $root_id = celerity_generate_unique_node_id();

    $default_action = $policy->getDefaultAction();
    $rule_data = $policy->getRules();

    $errors = array();
    if ($request->isFormPost()) {
      $data = $request->getStr('rules');
      $data = @json_decode($data, true);
      if (!is_array($data)) {
        throw new Exception("Failed to JSON decode rule data!");
      }

      $rule_data = array();
      foreach ($data as $rule) {
        $action = idx($rule, 'action');
        switch ($action) {
          case 'allow':
          case 'deny':
            break;
          default:
            throw new Exception("Invalid action '{$action}'!");
        }

        $rule_class = idx($rule, 'rule');
        if (empty($rules[$rule_class])) {
          throw new Exception("Invalid rule class '{$rule_class}'!");
        }

        $rule_obj = $rules[$rule_class];

        $value = $rule_obj->getValueForStorage(idx($rule, 'value'));

        $rule_data[] = array(
          'action' => $action,
          'rule' => $rule_class,
          'value' => $value,
        );
      }

      // Filter out nonsense rules, like a "users" rule without any users
      // actually specified.
      $valid_rules = array();
      foreach ($rule_data as $rule) {
        $rule_class = $rule['rule'];
        if ($rules[$rule_class]->ruleHasEffect($rule['value'])) {
          $valid_rules[] = $rule;
        }
      }

      if (!$valid_rules) {
        $errors[] = pht('None of these policy rules have any effect.');
      }

      // NOTE: Policies are immutable once created, and we always create a new
      // policy here. If we didn't, we would need to lock this endpoint down,
      // as users could otherwise just go edit the policies of objects with
      // custom policies.

      if (!$errors) {
        $new_policy = new PhabricatorPolicy();
        $new_policy->setRules($valid_rules);
        $new_policy->setDefaultAction($request->getStr('default'));
        $new_policy->save();

        $data = array(
          'phid' => $new_policy->getPHID(),
          'info' => array(
            'name' => $new_policy->getName(),
            'full' => $new_policy->getName(),
            'icon' => $new_policy->getIcon(),
          ),
        );

        return id(new AphrontAjaxResponse())->setContent($data);
      }
    }

    // Convert rule values to display format (for example, expanding PHIDs
    // into tokens).
    foreach ($rule_data as $key => $rule) {
      $rule_data[$key]['value'] = $rules[$rule['rule']]->getValueForDisplay(
        $viewer,
        $rule['value']);
    }

    $default_select = AphrontFormSelectControl::renderSelectTag(
      $default_action,
      $action_options,
      array(
        'name' => 'default',
      ));

    if ($errors) {
      $errors = id(new AphrontErrorView())
        ->setErrors($errors);
    }

    $form = id(new PHUIFormLayoutView())
      ->appendChild($errors)
      ->appendChild(
        javelin_tag(
          'input',
          array(
            'type' => 'hidden',
            'name' => 'rules',
            'sigil' => 'rules',
          )))
      ->appendChild(
        id(new AphrontFormInsetView())
          ->setTitle(pht('Rules'))
          ->setRightButton(
            javelin_tag(
              'a',
              array(
                'href' => '#',
                'class' => 'button green',
                'sigil' => 'create-rule',
                'mustcapture' => true
              ),
              pht('New Rule')))
          ->setDescription(
            pht('These rules are processed in order.'))
          ->setContent(javelin_tag(
            'table',
            array(
              'sigil' => 'rules',
              'class' => 'policy-rules-table'
            ),
            '')))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('If No Rules Match'))
          ->setValue(pht(
            "%s all other users.",
            $default_select)));

    $form = phutil_tag(
      'div',
      array(
        'id' => $root_id,
      ),
      $form);

    $rule_options = mpull($rules, 'getRuleDescription');
    $type_map = mpull($rules, 'getValueControlType');
    $templates = mpull($rules, 'getValueControlTemplate');

    require_celerity_resource('policy-edit-css');
    Javelin::initBehavior(
      'policy-rule-editor',
      array(
        'rootID' => $root_id,
        'actions' => $action_options,
        'rules' => $rule_options,
        'types' => $type_map,
        'templates' => $templates,
        'data' => $rule_data,
        'defaultRule' => $default_rule,
      ));

    $dialog = id(new AphrontDialogView())
      ->setWidth(AphrontDialogView::WIDTH_FULL)
      ->setUser($viewer)
      ->setTitle(pht('Edit Policy'))
      ->appendChild($form)
      ->addSubmitButton(pht('Save Policy'))
      ->addCancelButton('#');

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
