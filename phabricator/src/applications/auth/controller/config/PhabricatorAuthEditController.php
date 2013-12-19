<?php

final class PhabricatorAuthEditController
  extends PhabricatorAuthProviderConfigController {

  private $providerClass;
  private $configID;

  public function willProcessRequest(array $data) {
    $this->providerClass = idx($data, 'className');
    $this->configID = idx($data, 'id');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    if ($this->configID) {
      $config = id(new PhabricatorAuthProviderConfigQuery())
        ->setViewer($viewer)
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->withIDs(array($this->configID))
        ->executeOne();
      if (!$config) {
        return new Aphront404Response();
      }

      $provider = $config->getProvider();
      if (!$provider) {
        return new Aphront404Response();
      }

      $is_new = false;
    } else {
      $providers = PhabricatorAuthProvider::getAllBaseProviders();
      foreach ($providers as $candidate_provider) {
        if (get_class($candidate_provider) === $this->providerClass) {
          $provider = $candidate_provider;
          break;
        }
      }

      if (!$provider) {
        return new Aphront404Response();
      }

      // TODO: When we have multi-auth providers, support them here.

      $configs = id(new PhabricatorAuthProviderConfigQuery())
        ->setViewer($viewer)
        ->withProviderClasses(array(get_class($provider)))
        ->execute();

      if ($configs) {
        $id = head($configs)->getID();
        $dialog = id(new AphrontDialogView())
          ->setUser($viewer)
          ->setMethod('GET')
          ->setSubmitURI($this->getApplicationURI('config/edit/'.$id.'/'))
          ->setTitle(pht('Provider Already Configured'))
          ->appendChild(
            pht(
              'This provider ("%s") already exists, and you can not add more '.
              'than one instance of it. You can edit the existing provider, '.
              'or you can choose a different provider.',
              $provider->getProviderName()))
          ->addCancelButton($this->getApplicationURI('config/new/'))
          ->addSubmitButton(pht('Edit Existing Provider'));

        return id(new AphrontDialogResponse())->setDialog($dialog);
      }

      $config = $provider->getDefaultProviderConfig();
      $provider->attachProviderConfig($config);

      $is_new = true;
    }

    $errors = array();

    $v_registration = $config->getShouldAllowRegistration();
    $v_link = $config->getShouldAllowLink();
    $v_unlink = $config->getShouldAllowUnlink();

    if ($request->isFormPost()) {

      $properties = $provider->readFormValuesFromRequest($request);
      list($errors, $issues, $properties) = $provider->processEditForm(
        $request,
        $properties);

      $xactions = array();

      if (!$errors) {
        if ($is_new) {
          if (!strlen($config->getProviderType())) {
            $config->setProviderType($provider->getProviderType());
          }
          if (!strlen($config->getProviderDomain())) {
            $config->setProviderDomain($provider->getProviderDomain());
          }
        }

        $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
          ->setTransactionType(
            PhabricatorAuthProviderConfigTransaction::TYPE_REGISTRATION)
          ->setNewValue($request->getInt('allowRegistration', 0));

        $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
          ->setTransactionType(
            PhabricatorAuthProviderConfigTransaction::TYPE_LINK)
          ->setNewValue($request->getInt('allowLink', 0));

        $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
          ->setTransactionType(
            PhabricatorAuthProviderConfigTransaction::TYPE_UNLINK)
          ->setNewValue($request->getInt('allowUnlink', 0));

        foreach ($properties as $key => $value) {
          $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
            ->setTransactionType(
              PhabricatorAuthProviderConfigTransaction::TYPE_PROPERTY)
            ->setMetadataValue('auth:property', $key)
            ->setNewValue($value);
        }

        if ($is_new) {
          $config->save();
        }

        $editor = id(new PhabricatorAuthProviderConfigEditor())
          ->setActor($viewer)
          ->setContentSourceFromRequest($request)
          ->setContinueOnNoEffect(true)
          ->applyTransactions($config, $xactions);


        if ($provider->hasSetupStep() && $is_new) {
          $id = $config->getID();
          $next_uri = $this->getApplicationURI('config/edit/'.$id.'/');
        } else {
          $next_uri = $this->getApplicationURI();
        }

        return id(new AphrontRedirectResponse())->setURI($next_uri);
      }
    } else {
      $properties = $provider->readFormValuesFromProvider();
      $issues = array();
    }

    if ($errors) {
      $errors = id(new AphrontErrorView())->setErrors($errors);
    }

    if ($is_new) {
      $button = pht('Add Provider');
      $crumb = pht('Add Provider');
      $title = pht('Add Authentication Provider');
      $cancel_uri = $this->getApplicationURI('/config/new/');
    } else {
      $button = pht('Save');
      $crumb = pht('Edit Provider');
      $title = pht('Edit Authentication Provider');
      $cancel_uri = $this->getApplicationURI();
    }

    $config_name = 'auth.email-domains';
    $config_href = '/config/edit/'.$config_name.'/';

    $email_domains = PhabricatorEnv::getEnvConfig($config_name);
    if ($email_domains) {
      $registration_warning = pht(
        "Users will only be able to register with a verified email address ".
        "at one of the configured [[ %s | %s ]] domains: **%s**",
        $config_href,
        $config_name,
        implode(', ', $email_domains));
    } else {
      $registration_warning = pht(
        "NOTE: Any user who can browse to this install's login page will be ".
        "able to register a Phabricator account. To restrict who can register ".
        "an account, configure [[ %s | %s ]].",
        $config_href,
        $config_name);
    }

    $str_registration = array(
      phutil_tag('strong', array(), pht('Allow Registration:')),
      ' ',
      pht(
        'Allow users to register new Phabricator accounts using this '.
        'provider. If you disable registration, users can still use this '.
        'provider to log in to existing accounts, but will not be able to '.
        'create new accounts.'),
    );

    $str_link = hsprintf(
      '<strong>%s:</strong> %s',
      pht('Allow Linking Accounts'),
      pht(
        'Allow users to link account credentials for this provider to '.
        'existing Phabricator accounts. There is normally no reason to '.
        'disable this unless you are trying to move away from a provider '.
        'and want to stop users from creating new account links.'));

    $str_unlink = hsprintf(
      '<strong>%s:</strong> %s',
      pht('Allow Unlinking Accounts'),
      pht(
        'Allow users to unlink account credentials for this provider from '.
        'existing Phabricator accounts. If you disable this, Phabricator '.
        'accounts will be permanently bound to provider accounts.'));

    $status_tag = id(new PhabricatorTagView())
      ->setType(PhabricatorTagView::TYPE_STATE);
    if ($is_new) {
      $status_tag
        ->setName(pht('New Provider'))
        ->setBackgroundColor('blue');
    } else if ($config->getIsEnabled()) {
      $status_tag
        ->setName(pht('Enabled'))
        ->setBackgroundColor('green');
    } else {
      $status_tag
        ->setName(pht('Disabled'))
        ->setBackgroundColor('red');
    }

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel(pht('Provider'))
          ->setValue($provider->getProviderName()))
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel(pht('Status'))
          ->setValue($status_tag))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->setLabel(pht('Allow'))
          ->addCheckbox(
            'allowRegistration',
            1,
            $str_registration,
            $v_registration))
      ->appendRemarkupInstructions($registration_warning)
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'allowLink',
            1,
            $str_link,
            $v_link))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'allowUnlink',
            1,
            $str_unlink,
            $v_unlink));

    $provider->extendEditForm($request, $form, $properties, $issues);

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($cancel_uri)
          ->setValue($button));

    $help = $provider->getConfigurationHelp();
    if ($help) {
      $form->appendChild(id(new PHUIFormDividerControl()));
      $form->appendRemarkupInstructions($help);
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($crumb);

    $xaction_view = null;
    if (!$is_new) {
      $xactions = id(new PhabricatorAuthProviderConfigTransactionQuery())
        ->withObjectPHIDs(array($config->getPHID()))
        ->setViewer($viewer)
        ->execute();

      foreach ($xactions as $xaction) {
        $xaction->setProvider($provider);
      }

      $xaction_view = id(new PhabricatorApplicationTransactionView())
        ->setUser($viewer)
        ->setObjectPHID($config->getPHID())
        ->setTransactions($xactions);
    }

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setFormError($errors)
      ->setForm($form);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
        $xaction_view,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
