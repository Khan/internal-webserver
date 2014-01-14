<?php

/**
 * @group conduit
 */
final class ConduitAPI_conduit_connect_Method extends ConduitAPIMethod {

  public function shouldRequireAuthentication() {
    return false;
  }

  public function shouldAllowUnguardedWrites() {
    return true;
  }

  public function getMethodDescription() {
    return "Connect a session-based client.";
  }

  public function defineParamTypes() {
    return array(
      'client'              => 'required string',
      'clientVersion'       => 'required int',
      'clientDescription'   => 'optional string',
      'user'                => 'optional string',
      'authToken'           => 'optional int',
      'authSignature'       => 'optional string',
      'host'                => 'required string',
    );
  }

  public function defineReturnType() {
    return 'dict<string, any>';
  }

  public function defineErrorTypes() {
    return array(
      "ERR-BAD-VERSION" =>
        "Client/server version mismatch. Upgrade your server or downgrade ".
        "your client.",
      "NEW-ARC-VERSION" =>
        "Client/server version mismatch. Upgrade your client.",
      "ERR-UNKNOWN-CLIENT" =>
        "Client is unknown.",
      "ERR-INVALID-USER" =>
        "The username you are attempting to authenticate with is not valid.",
      "ERR-INVALID-CERTIFICATE" =>
        "Your authentication certificate for this server is invalid.",
      "ERR-INVALID-TOKEN" =>
        "The challenge token you are authenticating with is outside of the ".
        "allowed time range. Either your system clock is out of whack or ".
        "you're executing a replay attack.",
      "ERR-NO-CERTIFICATE" => "This server requires authentication.",
    );
  }

  protected function execute(ConduitAPIRequest $request) {

    $this->validateHost($request->getValue('host'));

    $client = $request->getValue('client');
    $client_version = (int)$request->getValue('clientVersion');
    $client_description = (string)$request->getValue('clientDescription');
    $username = (string)$request->getValue('user');

    // Log the connection, regardless of the outcome of checks below.
    $connection = new PhabricatorConduitConnectionLog();
    $connection->setClient($client);
    $connection->setClientVersion($client_version);
    $connection->setClientDescription($client_description);
    $connection->setUsername($username);
    $connection->save();

    switch ($client) {
      case 'arc':
        $server_version = 6;
        $supported_versions = array(
          $server_version => true,
          // Client version 5 introduced "user.query" call
          4               => true,
          // Client version 6 introduced "diffusion.getlintmessages" call
          5               => true,
        );

        if (empty($supported_versions[$client_version])) {
          if ($server_version < $client_version) {
            $ex = new ConduitException('ERR-BAD-VERSION');
            $ex->setErrorDescription(
              "Your 'arc' client version is '{$client_version}', which ".
              "is newer than the server version, '{$server_version}'. ".
              "Upgrade your Phabricator install.");
          } else {
            $ex = new ConduitException('NEW-ARC-VERSION');
            $ex->setErrorDescription(
              "A new version of arc is available! You need to upgrade ".
              "to connect to this server (you are running version ".
              "{$client_version}, the server is running version ".
              "{$server_version}).");
          }
          throw $ex;
        }
        break;
      default:
        // Allow new clients by default.
        break;
    }

    $token = $request->getValue('authToken');
    $signature = $request->getValue('authSignature');

    $user = id(new PhabricatorUser())->loadOneWhere(
      'username = %s',
      $username);
    if (!$user) {
      throw new ConduitException('ERR-INVALID-USER');
    }

    $session_key = null;
    if ($token && $signature) {
      $threshold = 60 * 15;
      $now = time();
      if (abs($token - $now) > $threshold) {
        throw id(new ConduitException('ERR-INVALID-TOKEN'))
          ->setErrorDescription(
            pht(
              "The request you submitted is signed with a timestamp, but that ".
              "timestamp is not within %s of the current time. The ".
              "signed timestamp is %s (%s), and the current server time is ".
              "%s (%s). This is a difference of %s seconds, but the ".
              "timestamp must differ from the server time by no more than ".
              "%s seconds. Your client or server clock may not be set ".
              "correctly.",
              phabricator_format_relative_time($threshold),
              $token,
              date('r', $token),
              $now,
              date('r', $now),
              ($token - $now),
              $threshold));
      }
      $valid = sha1($token.$user->getConduitCertificate());
      if ($valid != $signature) {
        throw new ConduitException('ERR-INVALID-CERTIFICATE');
      }
      $session_key = id(new PhabricatorAuthSessionEngine())
        ->establishSession(
          PhabricatorAuthSession::TYPE_CONDUIT,
          $user->getPHID());
    } else {
      throw new ConduitException('ERR-NO-CERTIFICATE');
    }

    return array(
      'connectionID'  => $connection->getID(),
      'sessionKey'    => $session_key,
      'userPHID'      => $user->getPHID(),
    );
  }

}
