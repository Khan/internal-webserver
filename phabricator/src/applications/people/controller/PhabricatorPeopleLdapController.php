<?php

final class PhabricatorPeopleLdapController
  extends PhabricatorPeopleController {

  public function processRequest() {

    $request = $this->getRequest();
    $admin = $request->getUser();

    $content = array();

    $form = id(new AphrontFormView())
      ->setAction($request->getRequestURI()
        ->alter('search', 'true')->alter('import', null))
      ->setUser($admin)
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('LDAP username'))
        ->setName('username'))
      ->appendChild(
        id(new AphrontFormPasswordControl())
        ->setLabel(pht('Password'))
        ->setName('password'))
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('LDAP query'))
        ->setCaption(pht('A filter such as (objectClass=*)'))
        ->setName('query'))
      ->appendChild(
        id(new AphrontFormSubmitControl())
        ->setValue(pht('Search')));

    $panel = id(new AphrontPanelView())
      ->setHeader(pht('Import LDAP Users'))
      ->setNoBackground()
      ->setWidth(AphrontPanelView::WIDTH_FORM)
      ->appendChild($form);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Import Ldap Users'))
        ->setHref($this->getApplicationURI('/ldap/')));

    $nav = $this->buildSideNavView();
    $nav->setCrumbs($crumbs);
    $nav->selectFilter('ldap');
    $nav->appendChild($content);

    if ($request->getStr('import')) {
      $nav->appendChild($this->processImportRequest($request));
    }

    $nav->appendChild($panel);

    if ($request->getStr('search')) {
      $nav->appendChild($this->processSearchRequest($request));
    }

    return $this->buildApplicationPage(
      $nav,
      array(
        'title'  => pht('Import Ldap Users'),
        'device' => true,
      ));
  }

  private function processImportRequest($request) {
    $admin = $request->getUser();
    $usernames = $request->getArr('usernames');
    $emails = $request->getArr('email');
    $names = $request->getArr('name');

    $notice_view = new AphrontErrorView();
    $notice_view->setSeverity(AphrontErrorView::SEVERITY_NOTICE);
    $notice_view->setTitle(pht("Import Successful"));
    $notice_view->setErrors(array(
      pht("Successfully imported users from LDAP"),
    ));

    $list = new PhabricatorObjectItemListView();
    $list->setNoDataString(pht("No users imported?"));

    foreach ($usernames as $username) {
      $user = new PhabricatorUser();
      $user->setUsername($username);
      $user->setRealname($names[$username]);

      $email_obj = id(new PhabricatorUserEmail())
        ->setAddress($emails[$username])
        ->setIsVerified(1);
      try {
        id(new PhabricatorUserEditor())
          ->setActor($admin)
          ->createNewUser($user, $email_obj);

        id(new PhabricatorExternalAccount())
          ->setUserPHID($user->getPHID())
          ->setAccountType('ldap')
          ->setAccountDomain('self')
          ->setAccountID($username)
          ->save();

        $header = pht('Successfully added %s', $username);
        $attribute = null;
        $color = 'green';
      } catch (Exception $ex) {
        $header = pht('Failed to add %s', $username);
        $attribute = $ex->getMessage();
        $color = 'red';
      }

      $item = id(new PhabricatorObjectItemView())
        ->setHeader($header)
        ->addAttribute($attribute)
        ->setBarColor($color);

      $list->addItem($item);
    }

    return array(
      $notice_view,
      $list,
    );

  }

  private function processSearchRequest($request) {
    $panel = new AphrontPanelView();
    $admin = $request->getUser();

    $search = $request->getStr('query');

    $ldap_provider = PhabricatorAuthProviderLDAP::getLDAPProvider();
    if (!$ldap_provider) {
      throw new Exception("No LDAP provider enabled!");
    }

    $ldap_adapter = $ldap_provider->getAdapter();
    $ldap_adapter->setLoginUsername($request->getStr('username'));
    $ldap_adapter->setLoginPassword(
      new PhutilOpaqueEnvelope($request->getStr('password')));

    // This causes us to connect and bind.
    // TODO: Clean up this discard mode stuff.
    DarkConsoleErrorLogPluginAPI::enableDiscardMode();
      $ldap_adapter->getAccountID();
    DarkConsoleErrorLogPluginAPI::disableDiscardMode();

    $results = $ldap_adapter->searchLDAP('%Q', $search);

    foreach ($results as $key => $record) {
      $account_id = $ldap_adapter->readLDAPRecordAccountID($record);
      if (!$account_id) {
        unset($results[$key]);
        continue;
      }

      $info = array(
        $account_id,
        $ldap_adapter->readLDAPRecordEmail($record),
        $ldap_adapter->readLDAPRecordRealName($record),
      );
      $results[$key] = $info;
      $results[$key][] = $this->renderUserInputs($info);
    }

    $form = id(new AphrontFormView())
      ->setUser($admin);

    $table = new AphrontTableView($results);
    $table->setHeaders(
      array(
        pht('Username'),
        pht('Email'),
        pht('Real Name'),
        pht('Import?'),
      ));
    $form->appendChild($table);
    $form->setAction($request->getRequestURI()
      ->alter('import', 'true')->alter('search', null))
      ->appendChild(
        id(new AphrontFormSubmitControl())
        ->setValue(pht('Import')));

    $panel->appendChild($form);

    return $panel;
  }

  private function renderUserInputs($user) {
    $username = $user[0];
    return hsprintf(
      '%s%s%s',
      phutil_tag(
        'input',
        array(
          'type' => 'checkbox',
          'name' => 'usernames[]',
          'value' => $username,
        )),
      phutil_tag(
        'input',
        array(
          'type' => 'hidden',
          'name' => "email[$username]",
          'value' => $user[1],
        )),
      phutil_tag(
        'input',
        array(
          'type' => 'hidden',
          'name' => "name[$username]",
          'value' => $user[2],
        )));
  }

}
