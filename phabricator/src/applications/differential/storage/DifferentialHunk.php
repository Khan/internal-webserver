<?php

final class DifferentialHunk extends DifferentialDAO
  implements PhabricatorPolicyInterface {

  protected $changesetID;
  protected $changes;
  protected $oldOffset;
  protected $oldLen;
  protected $newOffset;
  protected $newLen;

  private $changeset;

  const FLAG_LINES_ADDED     = 1;
  const FLAG_LINES_REMOVED   = 2;
  const FLAG_LINES_STABLE    = 4;

  public function getAddedLines() {
    return $this->makeContent($include = '+');
  }

  public function getRemovedLines() {
    return $this->makeContent($include = '-');
  }

  public function makeNewFile() {
    return implode('', $this->makeContent($include = ' +'));
  }

  public function makeOldFile() {
    return implode('', $this->makeContent($include = ' -'));
  }

  public function makeChanges() {
    return implode('', $this->makeContent($include = '-+'));
  }

  public function getContentWithMask($mask) {
    $include = array();

    if (($mask & self::FLAG_LINES_ADDED)) {
      $include[] = '+';
    }

    if (($mask & self::FLAG_LINES_REMOVED)) {
      $include[] = '-';
    }

    if (($mask & self::FLAG_LINES_STABLE)) {
      $include[] = ' ';
    }

    $include = implode('', $include);

    return implode('', $this->makeContent($include));
  }

  final private function makeContent($include) {
    $results = array();
    $lines = explode("\n", $this->changes);

    // NOTE: To determine whether the recomposed file should have a trailing
    // newline, we look for a "\ No newline at end of file" line which appears
    // after a line which we don't exclude. For example, if we're constructing
    // the "new" side of a diff (excluding "-"), we want to ignore this one:
    //
    //    - x
    //    \ No newline at end of file
    //    + x
    //
    // ...since it's talking about the "old" side of the diff, but interpret
    // this as meaning we should omit the newline:
    //
    //    - x
    //    + x
    //    \ No newline at end of file

    $n = (strpos($include, '+') !== false ?
      $this->newOffset :
      $this->oldOffset);
    $use_next_newline = false;
    foreach ($lines as $line) {
      if (!isset($line[0])) {
        continue;
      }

      if ($line[0] == '\\') {
        if ($use_next_newline) {
          $results[last_key($results)] = rtrim(end($results), "\n");
        }
      } else if (strpos($include, $line[0]) === false) {
        $use_next_newline = false;
      } else {
        $use_next_newline = true;
        $results[$n] = substr($line, 1)."\n";
      }

      if ($line[0] == ' ' || strpos($include, $line[0]) !== false) {
        $n++;
      }
    }

    return $results;
  }

  public function getChangeset() {
    return $this->assertAttached($this->changeset);
  }

  public function attachChangeset(DifferentialChangeset $changeset) {
    $this->changeset = $changeset;
    return $this;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    return $this->getChangeset()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getChangeset()->hasAutomaticCapability($capability, $viewer);
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }

}
