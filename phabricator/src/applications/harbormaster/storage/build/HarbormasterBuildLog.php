<?php

final class HarbormasterBuildLog extends HarbormasterDAO
  implements PhabricatorPolicyInterface {

  protected $buildPHID;
  protected $buildStepPHID;
  protected $logSource;
  protected $logType;
  protected $duration;
  protected $live;

  private $build = self::ATTACHABLE;
  private $buildStep = self::ATTACHABLE;

  const CHUNK_BYTE_LIMIT = 102400;

  /**
   * The log is encoded as plain text.
   */
  const ENCODING_TEXT = 'text';

  public static function initializeNewBuildLog(
    HarbormasterBuild $build,
    HarbormasterBuildStep $build_step) {

    return id(new HarbormasterBuildLog())
      ->setBuildPHID($build->getPHID())
      ->setBuildStepPHID($build_step->getPHID())
      ->setDuration(null)
      ->setLive(0);
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      HarbormasterPHIDTypeBuildLog::TYPECONST);
  }

  public function attachBuild(HarbormasterBuild $build) {
    $this->build = $build;
    return $this;
  }

  public function getBuild() {
    return $this->assertAttached($this->build);
  }

  public function getName() {
    return pht('Build Log');
  }

  public function attachBuildStep(
    HarbormasterBuildStep $build_step = null) {
    $this->buildStep = $build_step;
    return $this;
  }

  public function getBuildStep() {
    return $this->assertAttached($this->buildStep);
  }

  public function start() {
    if ($this->getLive()) {
      throw new Exception("Live logging has already started for this log.");
    }

    $this->setLive(1);
    $this->save();

    return time();
  }

  public function append($content) {
    if (!$this->getLive()) {
      throw new Exception("Start logging before appending data to the log.");
    }
    if (strlen($content) === 0) {
      return;
    }

    // If the length of the content is greater than the chunk size limit,
    // then we can never fit the content in a single record.  We need to
    // split our content out and call append on it for as many parts as there
    // are to the content.
    if (strlen($content) > self::CHUNK_BYTE_LIMIT) {
      $current = $content;
      while (strlen($current) > self::CHUNK_BYTE_LIMIT) {
        $part = substr($current, 0, self::CHUNK_BYTE_LIMIT);
        $current = substr($current, self::CHUNK_BYTE_LIMIT);
        $this->append($part);
      }
      $this->append($current);
      return;
    }

    // Retrieve the size of last chunk from the DB for this log.  If the
    // chunk is over 500K, then we need to create a new log entry.
    $conn = $this->establishConnection('w');
    $result = queryfx_all(
      $conn,
      'SELECT id, size, encoding '.
      'FROM harbormaster_buildlogchunk '.
      'WHERE logID = %d '.
      'ORDER BY id DESC '.
      'LIMIT 1',
      $this->getID());
    if (count($result) === 0 ||
      $result[0]["size"] + strlen($content) > self::CHUNK_BYTE_LIMIT ||
      $result[0]["encoding"] !== self::ENCODING_TEXT) {

      // We must insert a new chunk because the data we are appending
      // won't fit into the existing one, or we don't have any existing
      // chunk data.
      queryfx(
        $conn,
        'INSERT INTO harbormaster_buildlogchunk '.
        '(logID, encoding, size, chunk) '.
        'VALUES '.
        '(%d, %s, %d, %s)',
        $this->getID(),
        self::ENCODING_TEXT,
        strlen($content),
        $content);
    } else {
      // We have a resulting record that we can append our content onto.
      queryfx(
        $conn,
        'UPDATE harbormaster_buildlogchunk '.
        'SET chunk = CONCAT(chunk, %s), size = LENGTH(CONCAT(chunk, %s))'.
        'WHERE id = %d',
        $content,
        $content,
        $result[0]["id"]);
    }
  }

  public function finalize($start = 0) {
    if (!$this->getLive()) {
      throw new Exception("Start logging before finalizing it.");
    }

    // TODO: Encode the log contents in a gzipped format.
    $this->reload();
    if ($start > 0) {
      $this->setDuration(time() - $start);
    }
    $this->setLive(0);
    $this->save();
  }

  public function getLogText() {
    // TODO: This won't cope very well if we're pulling like a 700MB
    // log file out of the DB.  We should probably implement some sort
    // of optional limit parameter so that when we're rendering out only
    // 25 lines in the UI, we don't wastefully read in the whole log.

    // We have to read our content out of the database and stitch all of
    // the log data back together.
    $conn = $this->establishConnection('r');
    $result = queryfx_all(
      $conn,
      'SELECT chunk '.
      'FROM harbormaster_buildlogchunk '.
      'WHERE logID = %d '.
      'ORDER BY id ASC',
      $this->getID());

    $content = "";
    foreach ($result as $row) {
      $content .= $row["chunk"];
    }
    return $content;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    return $this->getBuild()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getBuild()->hasAutomaticCapability(
      $capability,
      $viewer);
  }

  public function describeAutomaticCapability($capability) {
    return pht(
      'Users must be able to see a build to view it\'s build log.');
  }


}
