<?php

final class PhabricatorApplicationEditController
  extends PhabricatorApplicationsController{

  private $application;

  public function willProcessRequest(array $data) {
    $this->application = $data['application'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $application = id(new PhabricatorApplicationQuery())
      ->setViewer($user)
      ->withClasses(array($this->application))
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->executeOne();
    if (!$application) {
      return new Aphront404Response();
    }

    $title = $application->getName();

    $view_uri = $this->getApplicationURI('view/'.get_class($application).'/');

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($user)
      ->setObject($application)
      ->execute();

    if ($request->isFormPost()) {
      $result = array();
      foreach ($application->getCapabilities() as $capability) {
        $old = $application->getPolicy($capability);
        $new = $request->getStr('policy:'.$capability);

        if ($old == $new) {
          // No change to the setting.
          continue;
        }

        if (empty($policies[$new])) {
          // Can't set the policy to something invalid.
          continue;
        }

        if ($new == PhabricatorPolicies::POLICY_PUBLIC) {
          $capobj = PhabricatorPolicyCapability::getCapabilityByKey(
            $capability);
          if (!$capobj || !$capobj->shouldAllowPublicPolicySetting()) {
            // Can't set non-public policies to public.
            continue;
          }
        }

        $result[$capability] = $new;
      }

      if ($result) {
        $key = 'phabricator.application-settings';
        $config_entry = PhabricatorConfigEntry::loadConfigEntry($key);
        $value = $config_entry->getValue();

        $phid = $application->getPHID();
        if (empty($value[$phid])) {
          $value[$application->getPHID()] = array();
        }
        if (empty($value[$phid]['policy'])) {
          $value[$phid]['policy'] = array();
        }

        $value[$phid]['policy'] = $result + $value[$phid]['policy'];

        // Don't allow users to make policy edits which would lock them out of
        // applications, since they would be unable to undo those actions.
        PhabricatorEnv::overrideConfig($key, $value);
        PhabricatorPolicyFilter::mustRetainCapability(
          $user,
          $application,
          PhabricatorPolicyCapability::CAN_VIEW);

        PhabricatorPolicyFilter::mustRetainCapability(
          $user,
          $application,
          PhabricatorPolicyCapability::CAN_EDIT);

        PhabricatorConfigEditor::storeNewValue(
          $config_entry,
          $value,
          $this->getRequest());
      }

      return id(new AphrontRedirectResponse())->setURI($view_uri);
    }

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $user,
      $application);

    $form = id(new AphrontFormView())
      ->setUser($user);

    foreach ($application->getCapabilities() as $capability) {
      $label = $application->getCapabilityLabel($capability);
      $can_edit = $application->isCapabilityEditable($capability);
      $caption = $application->getCapabilityCaption($capability);

      if (!$can_edit) {
        $form->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel($label)
            ->setValue(idx($descriptions, $capability))
            ->setCaption($caption));
      } else {
        $form->appendChild(
          id(new AphrontFormPolicyControl())
          ->setUser($user)
          ->setCapability($capability)
          ->setPolicyObject($application)
          ->setPolicies($policies)
          ->setLabel($label)
          ->setName('policy:'.$capability)
          ->setCaption($caption));
      }

    }

    $form->appendChild(
      id(new AphrontFormSubmitControl())
        ->setValue(pht('Save Policies'))
        ->addCancelButton($view_uri));

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($application->getName())
        ->setHref($view_uri));
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Edit Policies')));

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Edit Policies: %s', $application->getName()));

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setForm($form);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
