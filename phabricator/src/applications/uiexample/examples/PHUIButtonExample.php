<?php

final class PHUIButtonExample extends PhabricatorUIExample {

  public function getName() {
    return 'Buttons';
  }

  public function getDescription() {
    return hsprintf('Use <tt>&lt;button&gt;</tt> to render buttons.');
  }

  public function renderExample() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $colors = array('', 'green', 'grey', 'black', 'disabled');
    $sizes = array('', 'small');
    $tags = array('a', 'button');

    // phutil_tag

    $column = array();
    foreach ($tags as $tag) {
      foreach ($colors as $color) {
        foreach ($sizes as $key => $size) {
          $class = implode(' ', array($color, $size));

          if ($tag == 'a') {
            $class .= ' button';
          }

          $column[$key][] = phutil_tag(
            $tag,
            array(
              'class' => $class,
            ),
            phutil_utf8_ucwords($size.' '.$color.' '.$tag));

          $column[$key][] = hsprintf('<br /><br />');
        }
      }
    }

    $column3 = array();
    foreach ($colors as $color) {
      $caret = phutil_tag('span', array('class' => 'caret'), '');
      $column3[] = phutil_tag(
          'a',
            array(
              'class' => $color.' button dropdown'
            ),
          array(
            phutil_utf8_ucwords($color.' Dropdown'),
            $caret,
          ));
        $column3[] = hsprintf('<br /><br />');
    }

    $layout1 = id(new AphrontMultiColumnView())
      ->addColumn($column[0])
      ->addColumn($column[1])
      ->addColumn($column3)
      ->setFluidLayout(true)
      ->setGutter(AphrontMultiColumnView::GUTTER_MEDIUM);

   // PHUIButtonView

   $colors = array(null,
          PHUIButtonView::GREEN,
          PHUIButtonView::GREY,
          PHUIButtonView::BLACK,
          PHUIButtonView::DISABLED);
   $sizes = array(null, PHUIButtonView::SMALL);
   $column = array();
   foreach ($colors as $color) {
     foreach ($sizes as $key => $size) {
       $column[$key][] = id(new PHUIButtonView())
        ->setColor($color)
        ->setSize($size)
        ->setTag('a')
        ->setText('Clicky');
      $column[$key][] = hsprintf('<br /><br />');
     }
   }
   foreach ($colors as $color) {
     $column[2][] = id(new PHUIButtonView())
        ->setColor($color)
        ->setTag('button')
        ->setText('Button')
        ->setDropdown(true);
      $column[2][] = hsprintf('<br /><br />');
   }

   $layout2 = id(new AphrontMultiColumnView())
      ->addColumn($column[0])
      ->addColumn($column[1])
      ->addColumn($column[2])
      ->setFluidLayout(true)
      ->setGutter(AphrontMultiColumnView::GUTTER_MEDIUM);

    // Icon Buttons

    $column = array();
    $icons = array(
      'Comment' => 'comment',
      'Give Token' => 'like',
      'Reverse Time' => 'history',
      'Implode Earth' => 'warning');
    foreach ($icons as $text => $icon) {
      $image = id(new PHUIIconView())
          ->setSpriteSheet(PHUIIconView::SPRITE_ICONS)
          ->setSpriteIcon($icon);
      $column[] = id(new PHUIButtonView())
        ->setTag('a')
        ->setColor(PHUIButtonView::GREY)
        ->setIcon($image)
        ->setText($text)
        ->addClass(PHUI::MARGIN_SMALL_RIGHT);
    }

    $layout3 = id(new AphrontMultiColumnView())
      ->addColumn($column)
      ->setFluidLayout(true)
      ->setGutter(AphrontMultiColumnView::GUTTER_MEDIUM);


    // Baby Got Back Buttons

        $column = array();
    $icons = array('Asana', 'Github', 'Facebook', 'Google', 'LDAP');
    foreach ($icons as $icon) {
      $image = id(new PHUIIconView())
          ->setSpriteSheet(PHUIIconView::SPRITE_LOGIN)
          ->setSpriteIcon($icon);
      $column[] = id(new PHUIButtonView())
        ->setTag('a')
        ->setSize(PHUIButtonView::BIG)
        ->setColor(PHUIButtonView::GREY)
        ->setIcon($image)
        ->setText('Login or Register')
        ->setSubtext($icon)
        ->addClass(PHUI::MARGIN_MEDIUM_RIGHT);
    }

    $layout4 = id(new AphrontMultiColumnView())
      ->addColumn($column)
      ->setFluidLayout(true)
      ->setGutter(AphrontMultiColumnView::GUTTER_MEDIUM);


    // Set it and forget it

    $head1 = id(new PhabricatorHeaderView())
      ->setHeader('phutil_tag');

    $head2 = id(new PhabricatorHeaderView())
      ->setHeader('PHUIButtonView');

    $head3 = id(new PhabricatorHeaderView())
      ->setHeader('Icon Buttons');

    $head4 = id(new PhabricatorHeaderView())
      ->setHeader('Big Icon Buttons');

    $wrap1 = id(new PHUIBoxView())
      ->appendChild($layout1)
      ->addMargin(PHUI::MARGIN_LARGE);

    $wrap2 = id(new PHUIBoxView())
      ->appendChild($layout2)
      ->addMargin(PHUI::MARGIN_LARGE);

    $wrap3 = id(new PHUIBoxView())
      ->appendChild($layout3)
      ->addMargin(PHUI::MARGIN_LARGE);

    $wrap4 = id(new PHUIBoxView())
      ->appendChild($layout4)
      ->addMargin(PHUI::MARGIN_LARGE);

    return array($head1, $wrap1, $head2, $wrap2, $head3, $wrap3,
      $head4, $wrap4);
  }
}
