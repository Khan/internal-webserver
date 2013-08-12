<?php

final class PhabricatorAuthProviderOAuthTwitch
  extends PhabricatorAuthProviderOAuth {

  public function getProviderName() {
    return pht('Twitch.tv');
  }

  public function getConfigurationHelp() {
    $login_uri = $this->getLoginURI();

    return pht(
      "To configure Twitch.tv OAuth, create a new application here:".
      "\n\n".
      "http://www.twitch.tv/settings/applications".
      "\n\n".
      "When creating your application, use these settings:".
      "\n\n".
      "  - **Redirect URI:** Set this to: `%s`".
      "\n\n".
      "After completing configuration, copy the **Client ID** and ".
      "**Client Secret** to the fields above. (You may need to generate the ".
      "client secret by clicking 'New Secret' first.)",
      $login_uri);
  }

  protected function newOAuthAdapter() {
    return new PhutilAuthAdapterOAuthTwitch();
  }

  protected function getLoginIcon() {
    return 'TwitchTV';
  }

}
