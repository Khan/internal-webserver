<?php

/**
 * Implements the base IRC protocol so servers don't kick you off.
 *
 * @group irc
 */
final class PhabricatorIRCProtocolHandler extends PhabricatorBotHandler {

  public function receiveMessage(PhabricatorBotMessage $message) {
    switch ($message->getCommand()) {
      case '422': // Error - no MOTD
      case '376': // End of MOTD
        $nickpass = $this->getConfig('nickpass');
        if ($nickpass) {
          $this->writeMessage(
            id(new PhabricatorBotMessage())
            ->setCommand('MESSAGE')
            ->setTarget('nickserv')
            ->setBody("IDENTIFY {$nickpass}"));
        }
        $join = $this->getConfig('join');
        if (!$join) {
          throw new Exception("Not configured to join any channels!");
        }
        foreach ($join as $channel) {
          $this->writeMessage(
            id(new PhabricatorBotMessage())
            ->setCommand('JOIN')
            ->setBody($channel));
        }
        break;
      case 'PING':
        $this->writeMessage(
          id(new PhabricatorBotMessage())
          ->setCommand('PONG')
          ->setBody($message->getBody()));
        break;
    }
  }

}
