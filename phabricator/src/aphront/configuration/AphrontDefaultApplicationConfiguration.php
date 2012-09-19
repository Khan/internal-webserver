<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @group aphront
 */
class AphrontDefaultApplicationConfiguration
  extends AphrontApplicationConfiguration {

  public function __construct() {

  }

  public function getApplicationName() {
    return 'aphront-default';
  }

  public function getURIMap() {
    return $this->getResourceURIMapRules() + array(
      '/(?:(?P<filter>(?:jump|apps))/)?' =>
        'PhabricatorDirectoryMainController',
      '/(?:(?P<filter>feed)/)' => array(
        'public/' => 'PhabricatorFeedPublicStreamController',
        '(?:(?P<subfilter>[^/]+)/)?' =>
          'PhabricatorDirectoryMainController',
      ),
      '/F(?P<id>\d+)' => 'PhabricatorFileShortcutController',
      '/file/' => array(
        '' => 'PhabricatorFileListController',
        'filter/(?P<filter>\w+)/' => 'PhabricatorFileListController',
        'upload/' => 'PhabricatorFileUploadController',
        'dropupload/' => 'PhabricatorFileDropUploadController',
        'delete/(?P<id>\d+)/' => 'PhabricatorFileDeleteController',
        'info/(?P<phid>[^/]+)/' => 'PhabricatorFileInfoController',

        'data/(?P<key>[^/]+)/(?P<phid>[^/]+)/.*'
          => 'PhabricatorFileDataController',
        // TODO: This is a deprecated version of /data/. Remove it after
        // old links have had a chance to rot.
        'alt/(?P<key>[^/]+)/(?P<phid>[^/]+)/'
          => 'PhabricatorFileDataController',

        'macro/' => array(
          '' => 'PhabricatorFileMacroListController',
          'edit/(?:(?P<id>\d+)/)?' => 'PhabricatorFileMacroEditController',
          'delete/(?P<id>\d+)/' => 'PhabricatorFileMacroDeleteController',
        ),
        'proxy/' => 'PhabricatorFileProxyController',
        'xform/(?P<transform>[^/]+)/(?P<phid>[^/]+)/'
          => 'PhabricatorFileTransformController',
      ),
      '/phid/' => array(
        '' => 'PhabricatorPHIDLookupController',
      ),
      '/conduit/' => array(
        '' => 'PhabricatorConduitListController',
        'method/(?P<method>[^/]+)/' => 'PhabricatorConduitConsoleController',
        'log/' => 'PhabricatorConduitLogController',
        'log/view/(?P<view>[^/]+)/' => 'PhabricatorConduitLogController',
        'token/' => 'PhabricatorConduitTokenController',
      ),
      '/api/(?P<method>[^/]+)' => 'PhabricatorConduitAPIController',

      '/typeahead/' => array(
        'common/(?P<type>\w+)/'
          => 'PhabricatorTypeaheadCommonDatasourceController',
      ),

      '/login/' => array(
        '' => 'PhabricatorLoginController',
        'email/' => 'PhabricatorEmailLoginController',
        'etoken/(?P<token>\w+)/' => 'PhabricatorEmailTokenController',
        'refresh/' => 'PhabricatorRefreshCSRFController',
        'validate/' => 'PhabricatorLoginValidateController',
        'mustverify/' => 'PhabricatorMustVerifyEmailController',
      ),

      '/logout/' => 'PhabricatorLogoutController',

      '/oauth/' => array(
        '(?P<provider>\w+)/' => array(
          'login/'     => 'PhabricatorOAuthLoginController',
          'diagnose/'  => 'PhabricatorOAuthDiagnosticsController',
          'unlink/'    => 'PhabricatorOAuthUnlinkController',
        ),
      ),

      '/ldap/' => array(
        'login/' => 'PhabricatorLDAPLoginController',
        'unlink/'    => 'PhabricatorLDAPUnlinkController',
      ),

      '/oauthserver/' => array(
        'auth/'          => 'PhabricatorOAuthServerAuthController',
        'test/'          => 'PhabricatorOAuthServerTestController',
        'token/'         => 'PhabricatorOAuthServerTokenController',
        'clientauthorization/' => array(
          '' => 'PhabricatorOAuthClientAuthorizationListController',
          'delete/(?P<phid>[^/]+)/' =>
            'PhabricatorOAuthClientAuthorizationDeleteController',
          'edit/(?P<phid>[^/]+)/' =>
            'PhabricatorOAuthClientAuthorizationEditController',
        ),
        'client/' => array(
          ''                        => 'PhabricatorOAuthClientListController',
          'create/'                 => 'PhabricatorOAuthClientEditController',
          'delete/(?P<phid>[^/]+)/' => 'PhabricatorOAuthClientDeleteController',
          'edit/(?P<phid>[^/]+)/'   => 'PhabricatorOAuthClientEditController',
          'view/(?P<phid>[^/]+)/'   => 'PhabricatorOAuthClientViewController',
        ),
      ),

      '/xhprof/' => array(
        'list/(?P<view>[^/]+)/' => 'PhabricatorXHProfSampleListController',
        'profile/(?P<phid>[^/]+)/' => 'PhabricatorXHProfProfileController',
      ),

      '/~/' => 'DarkConsoleController',

      '/repository/' => array(
        ''                     => 'PhabricatorRepositoryListController',
        'create/'              => 'PhabricatorRepositoryCreateController',
        'edit/(?P<id>\d+)/(?:(?P<view>\w+)?/)?' =>
          'PhabricatorRepositoryEditController',
        'delete/(?P<id>\d+)/'  => 'PhabricatorRepositoryDeleteController',
        'project/(?P<id>\d+)/' =>
          'PhabricatorRepositoryArcanistProjectEditController',
      ),

      '/search/' => array(
        '' => 'PhabricatorSearchController',
        '(?P<key>[^/]+)/' => 'PhabricatorSearchController',
        'attach/(?P<phid>[^/]+)/(?P<type>\w+)/(?:(?P<action>\w+)/)?'
          => 'PhabricatorSearchAttachController',
        'select/(?P<type>\w+)/'
          => 'PhabricatorSearchSelectController',
        'index/(?P<phid>[^/]+)/' => 'PhabricatorSearchIndexController',
      ),

      '/herald/' => array(
        '' => 'HeraldHomeController',
        'view/(?P<content_type>[^/]+)/(?:(?P<rule_type>[^/]+)/)?'
          => 'HeraldHomeController',
        'new/(?:(?P<type>[^/]+)/(?:(?P<rule_type>[^/]+)/)?)?'
          => 'HeraldNewController',
        'rule/(?:(?P<id>\d+)/)?' => 'HeraldRuleController',
        'history/(?:(?P<id>\d+)/)?' => 'HeraldRuleEditHistoryController',
        'delete/(?P<id>\d+)/' => 'HeraldDeleteController',
        'test/' => 'HeraldTestConsoleController',
        'transcript/' => 'HeraldTranscriptListController',
        'transcript/(?P<id>\d+)/(?:(?P<filter>\w+)/)?'
          => 'HeraldTranscriptController',
      ),

      '/owners/' => array(
        '' => 'PhabricatorOwnersListController',
        'view/(?P<view>[^/]+)/' => 'PhabricatorOwnersListController',
        'edit/(?P<id>\d+)/' => 'PhabricatorOwnersEditController',
        'new/' => 'PhabricatorOwnersEditController',
        'package/(?P<id>\d+)/' => 'PhabricatorOwnersDetailController',
        'delete/(?P<id>\d+)/' => 'PhabricatorOwnersDeleteController',
      ),

      '/xhpast/' => array(
        '' => 'PhabricatorXHPASTViewRunController',
        'view/(?P<id>\d+)/'
          => 'PhabricatorXHPASTViewFrameController',
        'frameset/(?P<id>\d+)/'
          => 'PhabricatorXHPASTViewFramesetController',
        'input/(?P<id>\d+)/'
          => 'PhabricatorXHPASTViewInputController',
        'tree/(?P<id>\d+)/'
          => 'PhabricatorXHPASTViewTreeController',
        'stream/(?P<id>\d+)/'
          => 'PhabricatorXHPASTViewStreamController',
      ),

      '/status/' => 'PhabricatorStatusController',


      '/help/' => array(
        'keyboardshortcut/' => 'PhabricatorHelpKeyboardShortcutController',
      ),

      '/countdown/' => array(
        ''
          => 'PhabricatorCountdownListController',
        '(?P<id>\d+)/'
          => 'PhabricatorCountdownViewController',
        'edit/(?:(?P<id>\d+)/)?'
          => 'PhabricatorCountdownEditController',
        'delete/(?P<id>\d+)/'
          => 'PhabricatorCountdownDeleteController'
      ),

      '/V(?P<id>\d+)' => 'PhabricatorSlowvotePollController',
      '/vote/' => array(
        '(?:view/(?P<view>\w+)/)?' => 'PhabricatorSlowvoteListController',
        'create/' => 'PhabricatorSlowvoteCreateController',
      ),

      '/phame/' => array(
        ''                          => 'PhameAllPostListController',
        'post/' => array(
          ''                        => 'PhameUserPostListController',
          'delete/(?P<phid>[^/]+)/' => 'PhamePostDeleteController',
          'edit/(?P<phid>[^/]+)/'   => 'PhamePostEditController',
          'new/'                    => 'PhamePostEditController',
          'preview/'                => 'PhamePostPreviewController',
          'view/(?P<phid>[^/]+)/'   => 'PhamePostViewController',
        ),
        'draft/' => array(
          ''                        => 'PhameDraftListController',
          'new/'                    => 'PhamePostEditController',
        ),
        'blog/' => array(
          ''                         => 'PhameUserBlogListController',
          'all/'                     => 'PhameAllBlogListController',
          'new/'                     => 'PhameBlogEditController',
          'delete/(?P<phid>[^/]+)/'  => 'PhameBlogDeleteController',
          'edit/(?P<phid>[^/]+)/'    => 'PhameBlogEditController',
          'view/(?P<phid>[^/]+)/'    => 'PhameBlogViewController',
        ),
        'posts/' => array(
          ''                        => 'PhameUserPostListController',
          '(?P<bloggername>\w+)/'   => 'PhameBloggerPostListController',
          '(?P<bloggername>\w+)/(?P<phametitle>.+/)'
                                    => 'PhamePostViewController',
        ),
      ),

      '/calendar/' => array(
        '' => 'PhabricatorCalendarBrowseController',
      ),

      '/drydock/' => array(
        '' => 'DrydockResourceListController',
        'resource/' => 'DrydockResourceListController',
        'resource/allocate/' => 'DrydockResourceAllocateController',
        'host/' => array(
          '' => 'DrydockHostListController',
          'edit/' => 'DrydockHostEditController',
          'edit/(?P<id>\d+)/' => 'DrydockhostEditController',
        ),
        'lease/' => 'DrydockLeaseListController',
        'log/' => 'DrydockLogController',
      ),

      '/chatlog/' => array(
        '' =>
          'PhabricatorChatLogChannelListController',
        'channel/(?P<channel>[^/]+)/' =>
          'PhabricatorChatLogChannelLogController',
      ),

      '/notification/' => array(
        '(?:(?P<filter>all|unread)/)?'
          => 'PhabricatorNotificationListController',
        'panel/' => 'PhabricatorNotificationPanelController',
        'individual/' => 'PhabricatorNotificationIndividualController',
        'status/' => 'PhabricatorNotificationStatusController',
        'clear/' => 'PhabricatorNotificationClearController',
      ),

      '/phortune/' => array(
        'stripe/' => array(
          'testpaymentform/' => 'PhortuneStripeTestPaymentFormController',
        ),
      ),
    );
  }

  protected function getResourceURIMapRules() {
    return array(
      '/res/' => array(
        '(?P<package>pkg/)?'.
        '(?P<hash>[a-f0-9]{8})/'.
        '(?P<path>.+\.(?:css|js|jpg|png|swf|gif))'
          => 'CelerityResourceController',
      ),
    );
  }

  public function buildRequest() {
    $request = new AphrontRequest($this->getHost(), $this->getPath());
    $request->setRequestData($_GET + $_POST);
    $request->setApplicationConfiguration($this);
    return $request;
  }

  public function handleException(Exception $ex) {
    $request = $this->getRequest();

    // For Conduit requests, return a Conduit response.
    if ($request->isConduit()) {
      $response = new ConduitAPIResponse();
      $response->setErrorCode(get_class($ex));
      $response->setErrorInfo($ex->getMessage());

      return id(new AphrontJSONResponse())
        ->setContent($response->toDictionary());
    }

    // For non-workflow requests, return a Ajax response.
    if ($request->isAjax() && !$request->isJavelinWorkflow()) {
      $response = new AphrontAjaxResponse();
      $response->setError(
        array(
          'code' => get_class($ex),
          'info' => $ex->getMessage(),
        ));
      return $response;
    }

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $user = $request->getUser();
    if (!$user) {
      // If we hit an exception very early, we won't have a user.
      $user = new PhabricatorUser();
    }

    if ($ex instanceof PhabricatorPolicyException) {
      $content =
        '<div class="aphront-policy-exception">'.
          phutil_escape_html($ex->getMessage()).
        '</div>';

      $dialog = new AphrontDialogView();
      $dialog
        ->setTitle(
            $is_serious
              ? 'Access Denied'
              : "You Shall Not Pass")
        ->setClass('aphront-access-dialog')
        ->setUser($user)
        ->appendChild($content);

      if ($this->getRequest()->isAjax()) {
        $dialog->addCancelButton('/', 'Close');
      } else {
        $dialog->addCancelButton('/', $is_serious ? 'OK' : 'Away With Thee');
      }

      $response = new AphrontDialogResponse();
      $response->setDialog($dialog);
      return $response;
    }

    if ($ex instanceof AphrontUsageException) {
      $error = new AphrontErrorView();
      $error->setTitle(phutil_escape_html($ex->getTitle()));
      $error->appendChild(phutil_escape_html($ex->getMessage()));

      $view = new PhabricatorStandardPageView();
      $view->setRequest($this->getRequest());
      $view->appendChild($error);

      $response = new AphrontWebpageResponse();
      $response->setContent($view->render());

      return $response;
    }


    // Always log the unhandled exception.
    phlog($ex);

    $class    = phutil_escape_html(get_class($ex));
    $message  = phutil_escape_html($ex->getMessage());

    if ($ex instanceof AphrontQuerySchemaException) {
      $message .=
        "\n\n".
        "NOTE: This usually indicates that the MySQL schema has not been ".
        "properly upgraded. Run 'bin/storage upgrade' to ensure your ".
        "schema is up to date.";
    }

    if (PhabricatorEnv::getEnvConfig('phabricator.show-stack-traces')) {
      $trace = $this->renderStackTrace($ex->getTrace(), $user);
    } else {
      $trace = null;
    }

    $content =
      '<div class="aphront-unhandled-exception">'.
        '<div class="exception-message">'.$message.'</div>'.
        $trace.
      '</div>';

    $dialog = new AphrontDialogView();
    $dialog
      ->setTitle('Unhandled Exception ("'.$class.'")')
      ->setClass('aphront-exception-dialog')
      ->setUser($user)
      ->appendChild($content);

    if ($this->getRequest()->isAjax()) {
      $dialog->addCancelButton('/', 'Close');
    }

    $response = new AphrontDialogResponse();
    $response->setDialog($dialog);

    return $response;
  }

  public function willSendResponse(AphrontResponse $response) {
    return $response;
  }

  public function build404Controller() {
    return array(new Phabricator404Controller($this->getRequest()), array());
  }

  public function buildRedirectController($uri) {
    return array(
      new PhabricatorRedirectController($this->getRequest()),
      array(
        'uri' => $uri,
      ));
  }

  private function renderStackTrace($trace, PhabricatorUser $user) {

    $libraries = PhutilBootloader::getInstance()->getAllLibraries();

    $version = PhabricatorEnv::getEnvConfig('phabricator.version');
    if (preg_match('/[^a-f0-9]/i', $version)) {
      $version = '';
    }

    // TODO: Make this configurable?
    $path = 'https://secure.phabricator.com/diffusion/%s/browse/master/src/';

    $callsigns = array(
      'arcanist' => 'ARC',
      'phutil' => 'PHU',
      'phabricator' => 'P',
    );

    $rows = array();
    $depth = count($trace);
    foreach ($trace as $part) {
      $lib = null;
      $file = idx($part, 'file');
      $relative = $file;
      foreach ($libraries as $library) {
        $root = phutil_get_library_root($library);
        if (Filesystem::isDescendant($file, $root)) {
          $lib = $library;
          $relative = Filesystem::readablePath($file, $root);
          break;
        }
      }

      $where = '';
      if (isset($part['class'])) {
        $where .= $part['class'].'::';
      }
      if (isset($part['function'])) {
        $where .= $part['function'].'()';
      }

      if ($file) {
        if (isset($callsigns[$lib])) {
          $attrs = array('title' => $file);
          try {
            $attrs['href'] = $user->loadEditorLink(
              '/src/'.$relative,
              $part['line'],
              $callsigns[$lib]);
          } catch (Exception $ex) {
            // The database can be inaccessible.
          }
          if (empty($attrs['href'])) {
            $attrs['href'] = sprintf($path, $callsigns[$lib]).
              str_replace(DIRECTORY_SEPARATOR, '/', $relative).
              ($version && $lib == 'phabricator' ? ';'.$version : '').
              '$'.$part['line'];
            $attrs['target'] = '_blank';
          }
          $file_name = phutil_render_tag(
            'a',
            $attrs,
            phutil_escape_html($relative));
        } else {
          $file_name = phutil_render_tag(
            'span',
            array(
              'title' => $file,
            ),
            phutil_escape_html($relative));
        }
        $file_name = $file_name.' : '.(int)$part['line'];
      } else {
        $file_name = '<em>(Internal)</em>';
      }


      $rows[] = array(
        $depth--,
        phutil_escape_html($lib),
        $file_name,
        phutil_escape_html($where),
      );
    }
    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Depth',
        'Library',
        'File',
        'Where',
      ));
    $table->setColumnClasses(
      array(
        'n',
        '',
        '',
        'wide',
      ));

    return
      '<div class="exception-trace">'.
        '<div class="exception-trace-header">Stack Trace</div>'.
        $table->render().
      '</div>';
  }

}
