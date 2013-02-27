  private $authorName;
  private $authorEmail;
  public function setAuthorEmail($author_email) {
    $this->authorEmail = $author_email;

  public function getAuthorEmail() {
    return $this->authorEmail;
  }

  public function setAuthorName($author_name) {
    $this->authorName = $author_name;
    return $this;
  }

  public function getAuthorName() {
    return $this->authorName;
  }

  public function getFullAuthor() {
    $author_name = $this->getAuthorName();
    if ($author_name === null) {
      return null;
    }

    $author_email = $this->getAuthorEmail();
    if ($author_email === null) {
      return null;
    }

    $full_author = sprintf('%s <%s>', $author_name, $author_email);

    // Because git is very picky about the author being in a valid format,
    // verify that we can parse it.
    $address = new PhutilEmailAddress($full_author);
    if (!$address->getDisplayName() || !$address->getAddress()) {
      return null;
    }

    return $full_author;
  private function getEOL($patch_type) {

    // NOTE: Git always generates "\n" line endings, even under Windows, and
    // can not parse certain patches with "\r\n" line endings. SVN generates
    // patches with "\n" line endings on Mac or Linux and "\r\n" line endings
    // on Windows. (This EOL style is used only for patch metadata lines, not
    // for the actual patch content.)

    // (On Windows, Mercurial generates \n newlines for `--git` diffs, as it
    // must, but also \n newlines for unified diffs. We never need to deal with
    // these as we use Git format for Mercurial, so this case is currently
    // ignored.)

    switch ($patch_type) {
      case 'git':
        return "\n";
      case 'unified':
        return phutil_is_windows() ? "\r\n" : "\n";
      default:
        throw new Exception(
          "Unknown patch type '{$patch_type}'!");
    }
  }

      'tar tfO %s',
      $path);
        'tar xfO %s meta.json',
        $path);
      $author_name   = idx($meta_info, 'authorName');
      $author_email  = idx($meta_info, 'authorEmail');
      // this arc bundle was probably made before we started storing meta info
      'tar xfO %s changes.json',
      $path);
      'version'      => 5,
      'authorName'   => $this->getAuthorName(),
      'authorEmail'  => $this->getAuthorEmail(),
    $eol = $this->getEOL('unified');

      $hunk_changes = $this->buildHunkChanges($change->getHunks(), $eol);
      $result[] = $eol;
      $result[] = $eol;
      $result[] = '--- '.$old_path.$eol;
      $result[] = '+++ '.$cur_path.$eol;
    $eol = $this->getEOL('git');

        $change_body = $this->buildHunkChanges($change->getHunks(), $eol);
      $result[] = "diff --git {$old_index} {$cur_index}".$eol;
        $result[] = "new file mode {$new_mode}".$eol;
          $result[] = "old mode {$old_mode}".$eol;
          $result[] = "new mode {$new_mode}".$eol;
        $result[] = "copy from {$old_path}".$eol;
        $result[] = "copy to {$cur_path}".$eol;
        $result[] = "rename from {$old_path}".$eol;
        $result[] = "rename to {$cur_path}".$eol;
          $result[] = "deleted file mode {$old_mode}".$eol;
          $result[] = "--- {$old_target}".$eol;
          $result[] = "+++ {$cur_target}".$eol;
    $diff = implode('', $result).$eol;
  private function buildHunkChanges(array $hunks, $eol) {
        $result[] = "@@ -{$o_head} +{$n_head} @@".$eol;
          $result[] = $eol;
    $eol = $this->getEOL('git');

    $content[] = "index {$old_sha1}..{$new_sha1}".$eol;
    $content[] = "GIT binary patch".$eol;
    $content[] = "literal {$new_length}".$eol;
    $content[] = $this->emitBinaryDiffBody($new_data).$eol;
    $content[] = "literal {$old_length}".$eol;
    $content[] = $this->emitBinaryDiffBody($old_data).$eol;
    $eol = $this->getEOL('git');

      $buf .= $eol;