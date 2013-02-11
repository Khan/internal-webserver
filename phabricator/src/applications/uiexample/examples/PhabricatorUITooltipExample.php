<?php

final class PhabricatorUITooltipExample extends PhabricatorUIExample {

  public function getName() {
    return 'Tooltips';
  }

  public function getDescription() {
    return hsprintf('Use <tt>JX.Tooltip</tt> to create tooltips.');
  }

  public function renderExample() {

    Javelin::initBehavior('phabricator-tooltips');
    require_celerity_resource('aphront-tooltip-css');

    $style = 'width: 200px; '.
             'height: 200px '.
             'text-align: center; '.
             'margin: 20px; '.
             'background: #dfdfdf; '.
             'padding: 30px 10px; '.
             'border: 1px solid black; ';

    $lorem = <<<EOTEXT
Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eget urna
sed ante ultricies consequat id a odio. Mauris interdum volutpat sapien eu
accumsan. In hac habitasse platea dictumst. Lorem ipsum dolor sit amet,
consectetur adipiscing elit.
EOTEXT;

    $overflow = str_repeat('M', 1024);

    $metas = array(
      'hi' => array(
        'tip' => 'Hi',
      ),
      'lorem (north)' => array(
        'tip' => $lorem,
      ),
      'lorem (east)' => array(
        'tip' => $lorem,
        'align' => 'E',
      ),
      'lorem (south)' => array(
        'tip' => $lorem,
        'align' => 'S',
      ),
      'lorem (west)' => array(
        'tip' => $lorem,
        'align' => 'W',
      ),
      'lorem (large, north)' => array(
        'tip' => $lorem,
        'size' => 300,
      ),
      'lorem (large, east)' => array(
        'tip' => $lorem,
        'size' => 300,
        'align' => 'E',
      ),
      'lorem (large, west)' => array(
        'tip' => $lorem,
        'size' => 300,
        'align' => 'W',
      ),
      'lorem (large, south)' => array(
        'tip' => $lorem,
        'size' => 300,
        'align' => 'S',
      ),
      'overflow (north)' => array(
        'tip' => $overflow,
      ),
      'overflow (east)' => array(
        'tip' => $overflow,
        'align' => 'E',
      ),
      'overflow (south)' => array(
        'tip' => $overflow,
        'align' => 'S',
      ),
      'overflow (west)' => array(
        'tip' => $overflow,
        'align' => 'W',
      ),
    );

    $content = array();
    foreach ($metas as $key => $meta) {
      $content[] = javelin_tag(
        'div',
        array(
          'sigil' => 'has-tooltip',
          'meta'  => $meta,
          'style' => $style,
        ),
        $key);
    }

    return $content;
  }
}
