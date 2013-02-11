<?php

final class PhabricatorHelpKeyboardShortcutController
  extends PhabricatorHelpController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $keys = $request->getStr('keys');
    $keys = json_decode($keys, true);
    if (!is_array($keys)) {
      return new Aphront400Response();
    }

    // There have been at least two users asking for a keyboard shortcut to
    // close the dialog, so be explicit that escape works since it isn't
    // terribly discoverable.
    $keys[] = array(
      'keys'        => array('esc'),
      'description' => 'Close any dialog, including this one.',
    );

    $rows = array();
    foreach ($keys as $shortcut) {
      $keystrokes = array();
      foreach ($shortcut['keys'] as $stroke) {
        $keystrokes[] = phutil_tag('kbd', array(), $stroke);
      }
      $keystrokes = array_interleave(' or ', $keystrokes);
      $rows[] = phutil_tag(
        'tr',
        array(),
        array(
          phutil_tag('th', array(), $keystrokes),
          phutil_tag('td', array(), $shortcut['description']),
        ));
    }

    $table =
      '<table class="keyboard-shortcut-help">'.
        implode('', $rows).
      '</table>';

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->setTitle('Keyboard Shortcuts')
      ->appendChild($table)
      ->addCancelButton('#', 'Close');

    return id(new AphrontDialogResponse())
      ->setDialog($dialog);
  }

}
