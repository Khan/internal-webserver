<?php

final class PhabricatorRemarkupRuleEmbedFile
  extends PhabricatorRemarkupRuleObject {

  const KEY_EMBED_FILE_PHIDS = 'phabricator.embedded-file-phids';

  protected function getObjectNamePrefix() {
    return 'F';
  }

  protected function loadObjects(array $ids) {
    $engine = $this->getEngine();

    $viewer = $engine->getConfig('viewer');
    $objects = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withIDs($ids)
      ->execute();

    $phids_key = self::KEY_EMBED_FILE_PHIDS;
    $phids = $engine->getTextMetadata($phids_key, array());
    foreach (mpull($objects, 'getPHID') as $phid) {
      $phids[] = $phid;
    }
    $engine->setTextMetadata($phids_key, $phids);

    return $objects;
  }

  protected function renderObjectEmbed($object, $handle, $options) {
    $options = $this->getFileOptions($options) + array(
      'name' => $object->getName(),
    );

    $is_viewable_image = $object->isViewableImage();
    $is_audio = $object->isAudio();
    $force_link = ($options['layout'] == 'link');

    $options['viewable'] = ($is_viewable_image || $is_audio);

    if ($is_viewable_image && !$force_link) {
      return $this->renderImageFile($object, $handle, $options);
    } else if ($is_audio && !$force_link) {
      return $this->renderAudioFile($object, $handle, $options);
    } else {
      return $this->renderFileLink($object, $handle, $options);
    }
  }

  private function getFileOptions($option_string) {
    $options = array(
      'size'    => 'thumb',
      'layout'  => 'left',
      'float'   => false,
    );

    if ($option_string) {
      $option_string = trim($option_string, ', ');
      $parser = new PhutilSimpleOptions();
      $options = $parser->parse($option_string) + $options;
    }

    return $options;
  }

  private function renderImageFile(
    PhabricatorFile $file,
    PhabricatorObjectHandle $handle,
    array $options) {

    require_celerity_resource('lightbox-attachment-css');

    $attrs = array();
    $image_class = null;
    switch ((string)$options['size']) {
      case 'full':
        $attrs += array(
          'src' => $file->getBestURI(),
          'width' => $file->getImageWidth(),
          'height' => $file->getImageHeight(),
        );
        break;
      case 'thumb':
      default:
        $attrs['src'] = $file->getPreview220URI();
        $dimensions =
          PhabricatorImageTransformer::getPreviewDimensions($file, 220);
        $attrs['width'] = $dimensions['sdx'];
        $attrs['height'] = $dimensions['sdy'];
        $image_class = 'phabricator-remarkup-embed-image';
        break;
    }

    $img = phutil_tag('img', $attrs);

    $embed = javelin_tag(
      'a',
      array(
        'href'        => $file->getBestURI(),
        'class'       => $image_class,
        'sigil'       => 'lightboxable',
        'meta'        => array(
          'phid' => $file->getPHID(),
          'uri' => $file->getBestURI(),
          'dUri' => $file->getDownloadURI(),
          'viewable' => true,
        ),
      ),
      $img);

    switch ($options['layout']) {
      case 'right':
      case 'center':
      case 'inline':
      case 'left':
        $layout_class = 'phabricator-remarkup-embed-layout-'.$options['layout'];
        break;
      default:
        $layout_class = 'phabricator-remarkup-embed-layout-left';
        break;
    }

    if ($options['float']) {
      switch ($options['layout']) {
        case 'center':
        case 'inline':
          break;
        case 'right':
          $layout_class .= ' phabricator-remarkup-embed-float-right';
          break;
        case 'left':
        default:
          $layout_class .= ' phabricator-remarkup-embed-float-left';
          break;
      }
    }

    return phutil_tag(
      'div',
      array(
        'class' => $layout_class,
      ),
      $embed);
  }

  private function renderAudioFile(
    PhabricatorFile $file,
    PhabricatorObjectHandle $handle,
    array $options) {

    if (idx($options, 'autoplay')) {
      $preload = 'auto';
      $autoplay = 'autoplay';
    } else {
      $preload = 'none';
      $autoplay = null;
    }

    return phutil_tag(
      'audio',
      array(
        'controls' => 'controls',
        'preload' => $preload,
        'autoplay' => $autoplay,
        'loop' => idx($options, 'loop') ? 'loop' : null,
      ),
      phutil_tag(
        'source',
        array(
          'src' => $file->getBestURI(),
          'type' => $file->getMimeType(),
        )));
  }

  private function renderFileLink(
    PhabricatorFile $file,
    PhabricatorObjectHandle $handle,
    array $options) {

    return id(new PhabricatorFileLinkView())
      ->setFilePHID($file->getPHID())
      ->setFileName($options['name'])
      ->setFileDownloadURI($file->getDownloadURI())
      ->setFileViewURI($file->getBestURI())
      ->setFileViewable($options['viewable']);
  }

}
