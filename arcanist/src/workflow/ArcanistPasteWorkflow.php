<?php

/**
 * Upload a chunk of text to the Paste application, or download one.
 *
 * @group workflow
 */
final class ArcanistPasteWorkflow extends ArcanistBaseWorkflow {

  private $id;
  private $language;
  private $title;
  private $json;

  public function getWorkflowName() {
    return 'paste';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **paste** [--title __title__] [--lang __language__] [--json]
      **paste** __id__ [--json]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: text
          Share and grab text using the Paste application. To create a paste,
          use stdin to provide the text:

            $ cat list_of_ducks.txt | arc paste

          To retrieve a paste, specify the paste ID:

            $ arc paste P123
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'title' => array(
        'param' => 'title',
        'help' => 'Title for the paste.',
      ),
      'lang' => array(
        'param' => 'language',
        'help' => 'Language for syntax highlighting.',
      ),
      'json' => array(
        'help' => 'Output in JSON format.',
      ),
      '*' => 'argv',
    );
  }

  public function requiresAuthentication() {
    return true;
  }

  protected function didParseArguments() {
    $this->language = $this->getArgument('lang');
    $this->title    = $this->getArgument('title');
    $this->json     = $this->getArgument('json');

    $argv = $this->getArgument('argv');
    if (count($argv) > 1) {
      throw new ArcanistUsageException("Specify only one paste to retrieve.");
    } else if (count($argv) == 1) {
      $id = $argv[0];
      if (!preg_match('/^P?\d+/', $id)) {
        throw new ArcanistUsageException("Specify a paste ID, like P123.");
      }
      $this->id = (int)ltrim($id, 'P');

      if ($this->language || $this->title) {
        throw new ArcanistUsageException(
          "Use options --lang and --title only when creating pastes.");
      }
    }
  }

  private function getTitle() {
    return $this->title;
  }

  private function getLanguage() {
    return $this->language;
  }

  private function getJSON() {
    return $this->json;
  }

  public function run() {

    if ($this->id) {
      return $this->getPaste();
    } else {
      return $this->createPaste();
    }
  }

  private function getPaste() {
    $conduit = $this->getConduit();

    $info = $conduit->callMethodSynchronous(
      'paste.query',
      array(
        'ids' => array($this->id),
      ));
    $info = head($info);

    if ($this->getJSON()) {
      echo json_encode($info)."\n";
    } else {
      echo $info['content'];
      if (!preg_match('/\\n$/', $info['content'])) {
        // If there's no newline, add one, since it looks stupid otherwise. If
        // you want byte-for-byte equivalence you can use --json.
        echo "\n";
      }
    }

    return 0;
  }

  private function createPaste() {
    $conduit = $this->getConduit();

    // Avoid confusion when people type "arc paste" with nothing else.
    $this->writeStatusMessage("Reading paste from stdin...\n");

    $info = $conduit->callMethodSynchronous(
      'paste.create',
      array(
        'content'   => file_get_contents('php://stdin'),
        'title'     => $this->getTitle(),
        'language'  => $this->getLanguage(),
      ));

    if ($this->getArgument('json')) {
      echo json_encode($info)."\n";
    } else {
      echo $info['objectName'].': '.$info['uri']."\n";
    }

    return 0;
  }

}
