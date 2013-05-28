<?php

/**
 * Edit a document interactively, by launching $EDITOR (like vi or nano).
 *
 *   $result = id(new InteractiveEditor($document))
 *     ->setName('shopping_list')
 *     ->setLineOffset(15)
 *     ->editInteractively();
 *
 * This will launch the user's $EDITOR to edit the specified '$document', and
 * return their changes into '$result'.
 *
 * @task create  Creating a New Editor
 * @task edit    Editing Interactively
 * @task config  Configuring Options
 * @group console
 */
final class PhutilInteractiveEditor {

  private $name     = '';
  private $content  = '';
  private $offset   = 0;
  private $preferred;
  private $fallback;


/* -(  Creating a New Editor  )---------------------------------------------- */


  /**
   * Constructs an interactive editor, using the text of a document.
   *
   * @param  string  Document text.
   * @return $this
   *
   * @task   create
   */
  public function __construct($content) {
    $this->setContent($content);
  }


/* -(  Editing Interactively )----------------------------------------------- */


  /**
   * Launch an editor and edit the content. The edited content will be
   * returned.
   *
   * @return string    Edited content.
   * @throws Exception The editor exited abnormally or something untoward
   *                   occurred.
   *
   * @task edit
   */
  public function editInteractively() {
    $name = $this->getName();
    $content = $this->getContent();

    $tmp = Filesystem::createTemporaryDirectory('edit.');
    $path = $tmp.DIRECTORY_SEPARATOR.$name;

    try {
      Filesystem::writeFile($path, $content);
    } catch (Exception $ex) {
      Filesystem::remove($tmp);
      throw $ex;
    }

    $editor = $this->getEditor();
    $offset = $this->getLineOffset();

    $err = $this->invokeEditor($editor, $path, $offset);

    if ($err) {
      Filesystem::remove($tmp);
      throw new Exception("Editor exited with an error code (#{$err}).");
    }

    try {
      $result = Filesystem::readFile($path);
      Filesystem::remove($tmp);
    } catch (Exception $ex) {
      Filesystem::remove($tmp);
      throw $ex;
    }

    $this->setContent($result);

    return $this->getContent();
  }

  private function invokeEditor($editor, $path, $offset) {
    // NOTE: Popular Windows editors like Notepad++ and GitPad do not support
    // line offsets, so just ignore the offset feature on Windows. We rarely
    // use it anyway.

    $offset_flag = '';
    if ($offset && !phutil_is_windows()) {
      $offset = (int)$offset;
      if (preg_match('/^mate/', $editor)) {
        $offset_flag = csprintf('-l %d', $offset);
      } else {
        $offset_flag = csprintf('+%d', $offset);
      }
    }

    $cmd = csprintf(
      '%C %C %s',
      $editor,
      $offset_flag,
      $path);

    return phutil_passthru('%C', $cmd);
  }


/* -(  Configuring Options )------------------------------------------------- */


  /**
   * Set the line offset where the cursor should be positioned when the editor
   * opens. By default, the cursor will be positioned at the start of the
   * content.
   *
   * @param  int   Line number where the cursor should be positioned.
   * @return $this
   *
   * @task config
   */
  public function setLineOffset($offset) {
    $this->offset = (int)$offset;
    return $this;
  }


  /**
   * Get the current line offset. See setLineOffset().
   *
   * @return int   Current line offset.
   *
   * @task config
   */
  public function getLineOffset() {
    return $this->offset;
  }


  /**
   * Set the document name. Depending on the editor, this may be exposed to
   * the user and can give them a sense of what they're editing.
   *
   * @param  string  Document name.
   * @return $this
   *
   * @task config
   */
  public function setName($name) {
    $name = preg_replace('/[^A-Z0-9._-]+/i', '', $name);
    $this->name = $name;
    return $this;
  }


  /**
   * Get the current document name. See setName() for details.
   *
   * @return string  Current document name.
   *
   * @task config
   */
  public function getName() {
    if (!strlen($this->name)) {
      return 'untitled';
    }
    return $this->name;
  }


  /**
   * Set the text content to be edited.
   *
   * @param  string  New content.
   * @return $this
   *
   * @task config
   */
  public function setContent($content) {
    $this->content = $content;
    return $this;
  }


  /**
   * Retrieve the current content.
   *
   * @return string
   *
   * @task config
   */
  public function getContent() {
    return $this->content;
  }


  /**
   * Set the fallback editor program to be used if the env variable $EDITOR
   * is not available and there is no `editor` binary in PATH.
   *
   * @param  string  Command-line editing program (e.g. 'emacs', 'vi')
   * @return $this
   *
   * @task config
   */
  public function setFallbackEditor($editor) {
    $this->fallback = $editor;
    return $this;
  }


  /**
   * Set the preferred editor program. If set, this will override all other
   * sources of editor configuration, like $EDITOR.
   *
   * @param  string  Command-line editing program (e.g. 'emacs', 'vi')
   * @return $this
   *
   * @task config
   */
  public function setPreferredEditor($editor) {
    $this->preferred = $editor;
    return $this;
  }


  /**
   * Get the name of the editor program to use. The value of the environmental
   * variable $EDITOR will be used if available; otherwise, the `editor` binary
   * if present; otherwise the best editor will be selected.
   *
   * @return string  Command-line editing program.
   *
   * @task config
   */
  public function getEditor() {
    if ($this->preferred) {
      return $this->preferred;
    }

    $editor = getenv('EDITOR');
    if ($editor) {
      return $editor;
    }

    // Look for `editor` in PATH, some systems provide an editor which is
    // linked to something sensible.
    if (Filesystem::binaryExists('editor')) {
      return 'editor';
    }

    if ($this->fallback) {
      return $this->fallback;
    }

    if (Filesystem::binaryExists('nano')) {
      return 'nano';
    }

    throw new Exception(
      "Unable to launch an interactive text editor. Set the EDITOR ".
      "environment variable to an appropriate editor.");
  }
}
