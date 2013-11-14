<?php

final class DifferentialLandingToGitHub
  extends DifferentialLandingStrategy {

  private $account;
  private $provider;

  public function processLandRequest(
    AphrontRequest $request,
    DifferentialRevision $revision,
    PhabricatorRepository $repository) {

    $viewer = $request->getUser();
    $this->init($viewer, $repository);

    $workspace = $this->getGitWorkspace($repository);

    try {
      id(new DifferentialLandingToHostedGit())
        ->commitRevisionToWorkspace(
          $revision,
          $workspace,
          $viewer);
    } catch (Exception $e) {
      throw new PhutilProxyException(
        'Failed to commit patch',
        $e);
    }

    try {
      $this->pushWorkspaceRepository($repository, $workspace);
    } catch (Exception $e) {
      // If it's a permission problem, we know more than git.
      $dialog = $this->verifyRemotePermissions($viewer, $revision, $repository);
      if ($dialog) {
        return $dialog;
      }

      // Else, throw what git said.
      throw new PhutilProxyException(
        'Failed to push changes upstream',
        $e);
    }
  }

  /**
   * returns PhabricatorActionView or an array of PhabricatorActionView or null.
   */
  public function createMenuItems(
    PhabricatorUser $viewer,
    DifferentialRevision $revision,
    PhabricatorRepository $repository) {

    $vcs = $repository->getVersionControlSystem();
    if ($vcs !== PhabricatorRepositoryType::REPOSITORY_TYPE_GIT) {
      return;
    }

    if ($repository->isHosted()) {
      return;
    }

    try {
      // These throw when failing.
      $this->init($viewer, $repository);
      $this->findGitHubRepo($repository);
    } catch (Exception $e) {
      return;
    }

    return $this->createActionView(
      $revision,
      pht('Land to GitHub'));
  }

  public function pushWorkspaceRepository(
    PhabricatorRepository $repository,
    ArcanistRepositoryAPI $workspace) {

    $token = $this->getAccessToken();

    $github_repo = $this->findGitHubRepo($repository);

    $remote = urisprintf(
      'https://%s:x-oauth-basic@%s/%s.git',
      $token,
      $this->provider->getProviderDomain(),
      $github_repo);

    $workspace->execxLocal(
      "push %P HEAD:master",
      new PhutilOpaqueEnvelope($remote));
  }

  private function init($viewer, $repository) {
    $repo_uri = $repository->getRemoteURIObject();
    $repo_domain = $repo_uri->getDomain();

    $this->account = id(new PhabricatorExternalAccountQuery())
      ->setViewer($viewer)
      ->withUserPHIDs(array($viewer->getPHID()))
      ->withAccountTypes(array("github"))
      ->withAccountDomains(array($repo_domain))
      ->executeOne();

    if (!$this->account) {
      throw new Exception(
        "No matching GitHub account found for {$repo_domain}.");
    }

    $this->provider = PhabricatorAuthProvider::getEnabledProviderByKey(
      $this->account->getProviderKey());
    if (!$this->provider) {
      throw new Exception("GitHub provider for {$repo_domain} is not enabled.");
    }
  }

  private function findGitHubRepo(PhabricatorRepository $repository) {
    $repo_uri = $repository->getRemoteURIObject();

    $repo_path = $repo_uri->getPath();

    if (substr($repo_path, -4) == '.git') {
      $repo_path = substr($repo_path, 0, -4);
    }
    $repo_path = ltrim($repo_path, '/');

    return $repo_path;
  }

  private function getAccessToken() {
    return $this->provider->getOAuthAccessToken($this->account);
  }

  private function verifyRemotePermissions($viewer, $revision, $repository) {
    $github_user = $this->account->getUsername();
    $github_repo = $this->findGitHubRepo($repository);

    $uri = urisprintf(
      'https://api.github.com/repos/%s/collaborators/%s',
      $github_repo,
      $github_user);

    $uri = new PhutilURI($uri);
    $uri->setQueryParam('access_token', $this->getAccessToken());
    list($status, $body, $headers) = id(new HTTPSFuture($uri))->resolve();

    // Likely status codes:
    // 204 No Content: Has permissions. Token might be too weak.
    // 404 Not Found: Not a collaborator.
    // 401 Unauthorized: Token is bad/revoked.

    $no_permission = ($status->getStatusCode() == 404);

    if ($no_permission) {
      throw new Exception(
        "You don't have permission to push to this repository. \n".
        "Push permissions for this repository are managed on GitHub.");
    }

    $scopes = BaseHTTPFuture::getHeader($headers, 'X-OAuth-Scopes');
    if (strpos($scopes, 'public_repo') === false) {
      $provider_key = $this->provider->getProviderKey();
      $refresh_token_uri = new PhutilURI("/auth/refresh/{$provider_key}/");
      $refresh_token_uri->setQueryParam('scope', 'public_repo');

      return id(new AphrontDialogView())
        ->setUser($viewer)
        ->setTitle(pht('Stronger token needed'))
        ->appendChild(pht(
            'In order to complete this action, you need a '.
            'stronger GitHub token.'))
        ->setSubmitURI($refresh_token_uri)
        ->addCancelButton('/D'.$revision->getId())
        ->addSubmitButton(pht('Refresh Account Link'));
    }
  }
}
