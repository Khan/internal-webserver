<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class PhabricatorFile extends PhabricatorFileDAO {

  const STORAGE_FORMAT_RAW  = 'raw';

  protected $phid;
  protected $name;
  protected $mimeType;
  protected $byteSize;
  protected $authorPHID;
  protected $secretKey;
  protected $contentHash;

  protected $storageEngine;
  protected $storageFormat;
  protected $storageHandle;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorPHIDConstants::PHID_TYPE_FILE);
  }

  public static function readUploadedFileData($spec) {
    if (!$spec) {
      throw new Exception("No file was uploaded!");
    }

    $err = idx($spec, 'error');
    if ($err) {
      throw new PhabricatorFileUploadException($err);
    }

    $tmp_name = idx($spec, 'tmp_name');
    $is_valid = @is_uploaded_file($tmp_name);
    if (!$is_valid) {
      throw new Exception("File is not an uploaded file.");
    }

    $file_data = Filesystem::readFile($tmp_name);
    $file_size = idx($spec, 'size');

    if (strlen($file_data) != $file_size) {
      throw new Exception("File size disagrees with uploaded size.");
    }

    self::validateFileSize(strlen($file_data));

    return $file_data;
  }

  public static function newFromPHPUpload($spec, array $params = array()) {
    $file_data = self::readUploadedFileData($spec);

    $file_name = nonempty(
      idx($params, 'name'),
      idx($spec,   'name'));
    $params = array(
      'name' => $file_name,
    ) + $params;

    return self::newFromFileData($file_data, $params);
  }

  public static function newFromXHRUpload($data, array $params = array()) {
    self::validateFileSize(strlen($data));
    return self::newFromFileData($data, $params);
  }

  private static function validateFileSize($size) {
    $limit = PhabricatorEnv::getEnvConfig('storage.upload-size-limit');
    if (!$limit) {
      return;
    }

    $limit = phabricator_parse_bytes($limit);
    if ($size > $limit) {
      throw new PhabricatorFileUploadException(-1000);
    }
  }

  public static function newFromFileData($data, array $params = array()) {
    $selector = PhabricatorEnv::newObjectFromConfig('storage.engine-selector');

    $engines = $selector->selectStorageEngines($data, $params);
    if (!$engines) {
      throw new Exception("No valid storage engines are available!");
    }

    $data_handle = null;
    $engine_identifier = null;
    $exceptions = array();
    foreach ($engines as $engine) {
      $engine_class = get_class($engine);
      try {
        // Perform the actual write.
        $data_handle = $engine->writeFile($data, $params);
        if (!$data_handle || strlen($data_handle) > 255) {
          // This indicates an improperly implemented storage engine.
          throw new PhabricatorFileStorageConfigurationException(
            "Storage engine '{$engine_class}' executed writeFile() but did ".
            "not return a valid handle ('{$data_handle}') to the data: it ".
            "must be nonempty and no longer than 255 characters.");
        }

        $engine_identifier = $engine->getEngineIdentifier();
        if (!$engine_identifier || strlen($engine_identifier) > 32) {
          throw new PhabricatorFileStorageConfigurationException(
            "Storage engine '{$engine_class}' returned an improper engine ".
            "identifier '{$engine_identifier}': it must be nonempty ".
            "and no longer than 32 characters.");
        }

        // We stored the file somewhere so stop trying to write it to other
        // places.
        break;
      } catch (Exception $ex) {
        if ($ex instanceof PhabricatorFileStorageConfigurationException) {
          // If an engine is outright misconfigured (or misimplemented), raise
          // that immediately since it probably needs attention.
          throw $ex;
        }

        // If an engine doesn't work, keep trying all the other valid engines
        // in case something else works.
        phlog($ex);

        $exceptions[] = $ex;
      }
    }

    if (!$data_handle) {
      throw new PhutilAggregateException(
        "All storage engines failed to write file:",
        $exceptions);
    }

    $file_name = idx($params, 'name');
    $file_name = self::normalizeFileName($file_name);

    // If for whatever reason, authorPHID isn't passed as a param
    // (always the case with newFromFileDownload()), store a ''
    $authorPHID = idx($params, 'authorPHID');

    $file = new PhabricatorFile();
    $file->setName($file_name);
    $file->setByteSize(strlen($data));
    $file->setAuthorPHID($authorPHID);
    $file->setContentHash(PhabricatorHash::digest($data));

    $file->setStorageEngine($engine_identifier);
    $file->setStorageHandle($data_handle);

    // TODO: This is probably YAGNI, but allows for us to do encryption or
    // compression later if we want.
    $file->setStorageFormat(self::STORAGE_FORMAT_RAW);

    if (isset($params['mime-type'])) {
      $file->setMimeType($params['mime-type']);
    } else {
      $tmp = new TempFile();
      Filesystem::writeFile($tmp, $data);
      $file->setMimeType(Filesystem::getMimeType($tmp));
    }

    $file->save();

    return $file;
  }

  public static function newFromFileDownload($uri, $name) {
    $uri = new PhutilURI($uri);

    $protocol = $uri->getProtocol();
    switch ($protocol) {
      case 'http':
      case 'https':
        break;
      default:
        // Make sure we are not accessing any file:// URIs or similar.
        return null;
    }

    $timeout = stream_context_create(
      array(
        'http' => array(
          'timeout' => 5,
        ),
      ));

    $file_data = @file_get_contents($uri, false, $timeout);
    if ($file_data === false) {
      return null;
    }

    return self::newFromFileData($file_data, array('name' => $name));
  }

  public static function normalizeFileName($file_name) {
    return preg_replace('/[^a-zA-Z0-9.~_-]/', '_', $file_name);
  }

  public function delete() {
    $engine = $this->instantiateStorageEngine();

    $ret = parent::delete();

    $engine->deleteFile($this->getStorageHandle());

    return $ret;
  }

  public function loadFileData() {

    $engine = $this->instantiateStorageEngine();
    $data = $engine->readFile($this->getStorageHandle());

    switch ($this->getStorageFormat()) {
      case self::STORAGE_FORMAT_RAW:
        $data = $data;
        break;
      default:
        throw new Exception("Unknown storage format.");
    }

    return $data;
  }

  public function getViewURI() {
    if (!$this->getPHID()) {
      throw new Exception(
        "You must save a file before you can generate a view URI.");
    }

    $name = phutil_escape_uri($this->getName());

    $path = '/file/data/'.$this->getSecretKey().'/'.$this->getPHID().'/'.$name;
    return PhabricatorEnv::getCDNURI($path);
  }

  public function getInfoURI() {
    return '/file/info/'.$this->getPHID().'/';
  }

  public function getBestURI() {
    if ($this->isViewableInBrowser()) {
      return $this->getViewURI();
    } else {
      return $this->getInfoURI();
    }
  }

  public function getThumb60x45URI() {
    return '/file/xform/thumb-60x45/'.$this->getPHID().'/';
  }

  public function getThumb160x120URI() {
    return '/file/xform/thumb-160x120/'.$this->getPHID().'/';
  }


  public function isViewableInBrowser() {
    return ($this->getViewableMimeType() !== null);
  }

  public function isViewableImage() {
    if (!$this->isViewableInBrowser()) {
      return false;
    }

    $mime_map = PhabricatorEnv::getEnvConfig('files.image-mime-types');
    $mime_type = $this->getMimeType();
    return idx($mime_map, $mime_type);
  }

  public function isTransformableImage() {

    // NOTE: The way the 'gd' extension works in PHP is that you can install it
    // with support for only some file types, so it might be able to handle
    // PNG but not JPEG. Try to generate thumbnails for whatever we can. Setup
    // warns you if you don't have complete support.

    $matches = null;
    $ok = preg_match(
      '@^image/(gif|png|jpe?g)@',
      $this->getViewableMimeType(),
      $matches);
    if (!$ok) {
      return false;
    }

    switch ($matches[1]) {
      case 'jpg';
      case 'jpeg':
        return function_exists('imagejpeg');
        break;
      case 'png':
        return function_exists('imagepng');
        break;
      case 'gif':
        return function_exists('imagegif');
        break;
      default:
        throw new Exception('Unknown type matched as image MIME type.');
    }
  }

  public static function getTransformableImageFormats() {
    $supported = array();

    if (function_exists('imagejpeg')) {
      $supported[] = 'jpg';
    }

    if (function_exists('imagepng')) {
      $supported[] = 'png';
    }

    if (function_exists('imagegif')) {
      $supported[] = 'gif';
    }

    return $supported;
  }

  protected function instantiateStorageEngine() {
    $engines = id(new PhutilSymbolLoader())
      ->setType('class')
      ->setAncestorClass('PhabricatorFileStorageEngine')
      ->selectAndLoadSymbols();

    foreach ($engines as $engine_class) {
      $engine = newv($engine_class['name'], array());
      if ($engine->getEngineIdentifier() == $this->getStorageEngine()) {
        return $engine;
      }
    }

    throw new Exception("File's storage engine could be located!");
  }

  public function getViewableMimeType() {
    $mime_map = PhabricatorEnv::getEnvConfig('files.viewable-mime-types');

    $mime_type = $this->getMimeType();
    $mime_parts = explode(';', $mime_type);
    $mime_type = trim(reset($mime_parts));

    return idx($mime_map, $mime_type);
  }

  public function validateSecretKey($key) {
    return ($key == $this->getSecretKey());
  }

  public function save() {
    if (!$this->getSecretKey()) {
      $this->setSecretKey($this->generateSecretKey());
    }
    return parent::save();
  }

  public function generateSecretKey() {
    return Filesystem::readRandomCharacters(20);
  }
}
