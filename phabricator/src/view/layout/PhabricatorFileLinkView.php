<?php

final class PhabricatorFileLinkView extends AphrontView {

  private $fileName;
  private $fileDownloadURI;
  private $fileViewURI;
  private $fileViewable;
  private $filePHID;

  public function setFilePHID($file_phid) {
    $this->filePHID = $file_phid;
    return $this;
  }
  private function getFilePHID() {
    return $this->filePHID;
  }

  public function setFileViewable($file_viewable) {
    $this->fileViewable = $file_viewable;
    return $this;
  }
  private function getFileViewable() {
    return $this->fileViewable;
  }

  public function setFileViewURI($file_view_uri) {
    $this->fileViewURI = $file_view_uri;
    return $this;
  }
  private function getFileViewURI() {
    return $this->fileViewURI;
  }

  public function setFileDownloadURI($file_download_uri) {
    $this->fileDownloadURI = $file_download_uri;
    return $this;
  }
  private function getFileDownloadURI() {
    return $this->fileDownloadURI;
  }

  public function setFileName($file_name) {
    $this->fileName = $file_name;
    return $this;
  }
  private function getFileName() {
    return $this->fileName;
  }

  public function render() {
    require_celerity_resource('phabricator-remarkup-css');
    require_celerity_resource('lightbox-attachment-css');

    $sigil       = null;
    $meta        = null;
    $mustcapture = false;
    if ($this->getFileViewable()) {
      $mustcapture = true;
      $sigil       = 'lightboxable';
      $meta        = array(
        'phid'     => $this->getFilePHID(),
        'viewable' => $this->getFileViewable(),
        'uri'      => $this->getFileViewURI(),
        'dUri'     => $this->getFileDownloadURI(),
        'name'     => $this->getFileName(),
      );
    }

    return javelin_tag(
      'a',
      array(
        'href'        => $this->getFileViewURI(),
        'class'       => 'phabricator-remarkup-embed-layout-link',
        'sigil'       => $sigil,
        'meta'        => $meta,
        'mustcapture' => $mustcapture,
      ),
      $this->getFileName());
  }
}
