<?php

final class PhabricatorAuthProviderOAuth1Twitter
  extends PhabricatorAuthProviderOAuth1 {

  public function getProviderName() {
    return pht('Twitter');
  }

  public function getConfigurationHelp() {
    $login_uri = PhabricatorEnv::getURI($this->getLoginURI());

    return pht(
      "To configure Twitter OAuth, create a new application here:".
      "\n\n".
      "https://dev.twitter.com/apps".
      "\n\n".
      "When creating your application, use these settings:".
      "\n\n".
      "  - **Callback URL:** Set this to: `%s`".
      "\n\n".
      "After completing configuration, copy the **Consumer Key** and ".
      "**Consumer Secret** to the fields above.",
      $login_uri);
  }

  protected function newOAuthAdapter() {
    return new PhutilAuthAdapterOAuthTwitter();
  }

  protected function getLoginIcon() {
    return 'Twitter';
  }

}
