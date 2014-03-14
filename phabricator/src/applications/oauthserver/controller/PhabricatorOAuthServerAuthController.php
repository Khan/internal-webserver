<?php

/**
 * @group oauthserver
 */
final class PhabricatorOAuthServerAuthController
extends PhabricatorAuthController {

  public function shouldRequireLogin() {
    return true;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $server        = new PhabricatorOAuthServer();
    $client_phid   = $request->getStr('client_id');
    $scope         = $request->getStr('scope', array());
    $redirect_uri  = $request->getStr('redirect_uri');
    $response_type = $request->getStr('response_type');

    // state is an opaque value the client sent us for their own purposes
    // we just need to send it right back to them in the response!
    $state = $request->getStr('state');

    if (!$client_phid) {
      return $this->buildErrorResponse(
        'invalid_request',
        pht('Malformed Request'),
        pht(
          'Required parameter %s was not present in the request.',
          phutil_tag('strong', array(), 'client_id')));
    }

    $server->setUser($viewer);
    $is_authorized = false;
    $authorization = null;
    $uri = null;
    $name = null;

    // one giant try / catch around all the exciting database stuff so we
    // can return a 'server_error' response if something goes wrong!
    try {
      $client = id(new PhabricatorOAuthServerClient())
        ->loadOneWhere('phid = %s', $client_phid);

      if (!$client) {
        return $this->buildErrorResponse(
          'invalid_request',
          pht('Invalid Client Application'),
          pht(
            'Request parameter %s does not specify a valid client application.',
            phutil_tag('strong', array(), 'client_id')));
      }

      $name = $client->getName();
      $server->setClient($client);
      if ($redirect_uri) {
        $client_uri   = new PhutilURI($client->getRedirectURI());
        $redirect_uri = new PhutilURI($redirect_uri);
        if (!($server->validateSecondaryRedirectURI($redirect_uri,
                                                    $client_uri))) {
          return $this->buildErrorResponse(
            'invalid_request',
            pht('Invalid Redirect URI'),
            pht(
              'Request parameter %s specifies an invalid redirect URI. '.
              'The redirect URI must be a fully-qualified domain with no '.
              'fragments, and must have the same domain and at least '.
              'the same query parameters as the redirect URI the client '.
              'registered.',
              phutil_tag('strong', array(), 'redirect_uri')));
        }
        $uri = $redirect_uri;
      } else {
        $uri = new PhutilURI($client->getRedirectURI());
      }

      if (empty($response_type)) {
        return $this->buildErrorResponse(
          'invalid_request',
          pht('Invalid Response Type'),
          pht(
            'Required request parameter %s is missing.',
            phutil_tag('strong', array(), 'response_type')));
      }

      if ($response_type != 'code') {
        return $this->buildErrorResponse(
          'unsupported_response_type',
          pht('Unsupported Response Type'),
          pht(
            'Request parameter %s specifies an unsupported response type. '.
            'Valid response types are: %s.',
            phutil_tag('strong', array(), 'response_type'),
            implode(', ', array('code'))));
      }

      if ($scope) {
        if (!PhabricatorOAuthServerScope::validateScopesList($scope)) {
          return $this->buildErrorResponse(
            'invalid_scope',
            pht('Invalid Scope'),
            pht(
              'Request parameter %s specifies an unsupported scope.',
              phutil_tag('strong', array(), 'scope')));
        }
        $scope = PhabricatorOAuthServerScope::scopesListToDict($scope);
      }

      // NOTE: We're always requiring a confirmation dialog to redirect.
      // Partly this is a general defense against redirect attacks, and
      // partly this shakes off anchors in the URI (which are not shaken
      // by 302'ing).

      $auth_info = $server->userHasAuthorizedClient($scope);
      list($is_authorized, $authorization) = $auth_info;

      if ($request->isFormPost()) {
        // TODO: We should probably validate this more? It feels a little funky.
        $scope = PhabricatorOAuthServerScope::getScopesFromRequest($request);

        if ($authorization) {
          $authorization->setScope($scope)->save();
        } else {
          $authorization = $server->authorizeClient($scope);
        }

        $is_authorized = true;
      }
    } catch (Exception $e) {
      return $this->buildErrorResponse(
        'server_error',
        pht('Server Error'),
        pht(
          'The authorization server encountered an unexpected condition '.
          'which prevented it from fulfilling the request.'));
    }

    // When we reach this part of the controller, we can be in two states:
    //
    //   1. The user has not authorized the application yet. We want to
    //      give them an "Authorize this application?" dialog.
    //   2. The user has authorized the application. We want to give them
    //      a "Confirm Login" dialog.

    if ($is_authorized) {

      // The second case is simpler, so handle it first. The user either
      // authorized the application previously, or has just authorized the
      // application. Show them a confirm dialog with a normal link back to
      // the application. This shakes anchors from the URI.

      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
        $auth_code = $server->generateAuthorizationCode($uri);
      unset($unguarded);

      $full_uri = $this->addQueryParams(
        $uri,
        array(
          'code'  => $auth_code->getCode(),
          'scope' => $authorization->getScopeString(),
          'state' => $state,
        ));

      // TODO: It would be nice to give the user more options here, like
      // reviewing permissions, canceling the authorization, or aborting
      // the workflow.

      $dialog = id(new AphrontDialogView())
        ->setUser($viewer)
        ->setTitle(pht('Authenticate: %s', $name))
        ->appendParagraph(
          pht(
            'This application ("%s") is authorized to use your Phabricator '.
            'credentials. Continue to complete the authentication workflow.',
            phutil_tag('strong', array(), $name)))
        ->addCancelButton((string)$full_uri, pht('Continue to Application'));

      return id(new AphrontDialogResponse())->setDialog($dialog);
    }

    // Here, we're confirming authorization for the application.

    if ($scope) {
      if ($authorization) {
        $desired_scopes = array_merge($scope,
                                      $authorization->getScope());
      } else {
        $desired_scopes = $scope;
      }
      if (!PhabricatorOAuthServerScope::validateScopesDict($desired_scopes)) {
        return $this->buildErrorResponse(
          'invalid_scope',
          pht('Invalid Scope'),
          pht('The requested scope is invalid, unknown, or malformed.'));
      }
    } else {
      $desired_scopes = array(
        PhabricatorOAuthServerScope::SCOPE_WHOAMI         => 1,
        PhabricatorOAuthServerScope::SCOPE_OFFLINE_ACCESS => 1
      );
    }

    $form = id(new AphrontFormView())
      ->addHiddenInput('client_id', $client_phid)
      ->addHiddenInput('redirect_uri', $redirect_uri)
      ->addHiddenInput('response_type', $response_type)
      ->addHiddenInput('state', $state)
      ->addHiddenInput('scope', $request->getStr('scope'))
      ->setUser($viewer)
      ->appendChild(
        PhabricatorOAuthServerScope::getCheckboxControl($desired_scopes));

    $cancel_msg = pht('The user declined to authorize this application.');
    $cancel_uri = $this->addQueryParams(
      $uri,
      array(
        'error' => 'access_denied',
        'error_description' => $cancel_msg,
      ));

    $dialog = id(new AphrontDialogView())
      ->setUser($viewer)
      ->setTitle(pht('Authorize "%s"?', $name))
      ->setSubmitURI($request->getRequestURI()->getPath())
      ->setWidth(AphrontDialogView::WIDTH_FORM)
      ->appendParagraph(
        pht(
          'Do you want to authorize the external application "%s" to '.
          'access your Phabricator account data?',
          phutil_tag('strong', array(), $name)))
      ->appendChild($form->buildLayoutView())
      ->addSubmitButton(pht('Authorize Access'))
      ->addCancelButton((string)$cancel_uri, pht('Do Not Authorize'));

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }


  private function buildErrorResponse($code, $title, $message) {
    $viewer = $this->getRequest()->getUser();

    $dialog = id(new AphrontDialogView())
      ->setUser($viewer)
      ->setTitle(pht('OAuth: %s', $title))
      ->appendParagraph($message)
      ->appendParagraph(
        pht('OAuth Error Code: %s', phutil_tag('tt', array(), $code)))
      ->addCancelButton('/', pht('Alas!'));

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }


  private function addQueryParams(PhutilURI $uri, array $params) {
    $full_uri = clone $uri;

    foreach ($params as $key => $value) {
      if (strlen($value)) {
        $full_uri->setQueryParam($key, $value);
      }
    }

    return $full_uri;
  }

}
