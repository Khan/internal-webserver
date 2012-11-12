<?php

/**
 * Simple wrapper to create a temporary file and guarantee it will be deleted on
 * object destruction. Used like a string to path:
 *
 *    $temp = new TempFile();
 *    Filesystem::writeFile($temp, 'Hello World');
 *    echo "Wrote data to path: ".$temp;
 *
 * Throws Filesystem exceptions for errors.
 *
 * @task  create    Creating a Temporary File
 * @task  config    Configuration
 * @task  internal  Internals
 *
 * @group filesystem
 */
final class TempFile {

  private $dir;
  private $file;
  private $preserve;

/* -(  Creating a Temporary File  )------------------------------------------ */


  /**
   * Create a new temporary file.
   *
   * @param string? Filename hint. This is useful if you intend to edit the
   *                file with an interactive editor, so the user's editor shows
   *                "commit-message" instead of "p3810hf-1z9b89bas".
   * @task create
   */
  public function __construct($filename = null) {
    $this->dir = Filesystem::createTemporaryDirectory();
    if ($filename === null) {
      $this->file = tempnam($this->dir, getmypid().'-');
    } else {
      $this->file = $this->dir.'/'.$filename;
    }

    Filesystem::writeFile($this, '');
  }


/* -(  Configuration  )------------------------------------------------------ */


  /**
   * Normally, the file is deleted when this object passes out of scope. You
   * can set it to be preserved instead.
   *
   * @param bool True to preserve the file after object destruction.
   * @return this
   * @task config
   */
  public function setPreserveFile($preserve) {
    $this->preserve = $preserve;
    return $this;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Get the path to the temporary file. Normally you can just use the object
   * in a string context.
   *
   * @return string Absolute path to the temporary file.
   * @task internal
   */
  public function __toString() {
    return $this->file;
  }


  /**
   * When the object is destroyed, it destroys the temporary file. You can
   * change this behavior with @{method:setPreserveFile}.
   *
   * @task internal
   */
  public function __destruct() {
    if ($this->preserve) {
      return;
    }
    Filesystem::remove($this->dir);

    // NOTE: tempnam() doesn't guarantee it will return a file inside the
    // directory you passed to the function, so we make sure to nuke the file
    // explicitly.

    Filesystem::remove($this->file);
  }

}
