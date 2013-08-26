<?php

final class PhabricatorAuthNewController
  extends PhabricatorAuthProviderConfigController {

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $providers = PhabricatorAuthProvider::getAllBaseProviders();

    $e_provider = null;
    $errors = array();

    if ($request->isFormPost()) {
      $provider_string = $request->getStr('provider');
      if (!strlen($provider_string)) {
        $e_provider = pht('Required');
        $errors[] = pht('You must select an authentication provider.');
      } else {
        $found = false;
        foreach ($providers as $provider) {
          if (get_class($provider) === $provider_string) {
            $found = true;
            break;
          }
        }
        if (!$found) {
          $e_provider = pht('Invalid');
          $errors[] = pht('You must select a valid provider.');
        }
      }

      if (!$errors) {
        return id(new AphrontRedirectResponse())->setURI(
          $this->getApplicationURI('/config/new/'.$provider_string.'/'));
      }
    }

    if ($errors) {
      $errors = id(new AphrontErrorView())->setErrors($errors);
    }

    $options = id(new AphrontFormRadioButtonControl())
      ->setLabel(pht('Provider'))
      ->setName('provider')
      ->setError($e_provider);

    $configured = PhabricatorAuthProvider::getAllProviders();
    $configured_classes = array();
    foreach ($configured as $configured_provider) {
      $configured_classes[get_class($configured_provider)] = true;
    }

    // Sort providers by login order, and move disabled providers to the
    // bottom.
    $providers = msort($providers, 'getLoginOrder');
    $providers = array_diff_key($providers, $configured_classes) + $providers;

    foreach ($providers as $provider) {
      if (isset($configured_classes[get_class($provider)])) {
        $disabled = true;
        $description = pht('This provider is already configured.');
      } else {
        $disabled = false;
        $description = $provider->getDescriptionForCreate();
      }
      $options->addButton(
        get_class($provider),
        $provider->getNameForCreate(),
        $description,
        $disabled ? 'disabled' : null,
        $disabled);
    }

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendChild($options)
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($this->getApplicationURI())
          ->setValue(pht('Continue')));

    $form_box = id(new PHUIFormBoxView())
      ->setHeaderText(pht('Add Authentication Provider'))
      ->setFormError($errors)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Add Provider')));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
      ),
      array(
        'title' => pht('Add Authentication Provider'),
        'device' => true,
      ));
  }

}
