<?php

final class PhabricatorApplicationLaunchView extends AphrontView {

  private $application;
  private $status;
  private $fullWidth;

  public function setFullWidth($full_width) {
    $this->fullWidth = $full_width;
    return $this;
  }

  public function setApplication(PhabricatorApplication $application) {
    $this->application = $application;
    return $this;
  }

  public function setApplicationStatus(array $status) {
    $this->status = $status;
    return $this;
  }

  public function render() {
    $application = $this->application;

    require_celerity_resource('phabricator-application-launch-view-css');
    require_celerity_resource('sprite-apps-large-css');

    $content = array();
    $icon = null;
    if ($application) {
      $content[] = phutil_render_tag(
        'span',
        array(
          'class' => 'phabricator-application-launch-name',
        ),
        phutil_escape_html($application->getName()));

      if ($this->fullWidth) {
        $content[] = phutil_render_tag(
          'span',
          array(
            'class' => 'phabricator-application-launch-description',
          ),
          phutil_escape_html($application->getShortDescription()));
      }

      $count = 0;
      if ($this->status) {
        foreach ($this->status as $status) {
          $count += $status->getCount();
        }
      }

      if ($count) {
        $content[] = phutil_render_tag(
          'span',
          array(
            'class' => 'phabricator-application-launch-attention',
          ),
          phutil_escape_html($count));
      }

      $classes = array();
      $classes[] = 'phabricator-application-launch-icon';
      $styles = array();

      if ($application->getIconURI()) {
        $styles[] = 'background-image: url('.$application->getIconURI().')';
      } else {
        $icon = $application->getIconName();
        $classes[] = 'sprite-apps-large';
        $classes[] = 'app-'.$icon.'-light-large';
      }

      $icon = phutil_render_tag(
        'span',
        array(
          'class' => implode(' ', $classes),
          'style' => nonempty(implode('; ', $styles), null),
        ),
        '');
    }

    $classes = array();
    $classes[] = 'phabricator-application-launch-container';
    if ($this->fullWidth) {
      $classes[] = 'application-tile-full';
    }

    return phutil_render_tag(
      $application ? 'a' : 'div',
      array(
        'class' => implode(' ', $classes),
        'href'  => $application ? $application->getBaseURI() : null,
      ),
      $icon.
      $this->renderSingleView($content));
  }
}
