<?php

/**
 * @group file
 */
final class PhabricatorTransformedFile extends PhabricatorFileDAO {

  protected $originalPHID;
  protected $transform;
  protected $transformedPHID;

}
