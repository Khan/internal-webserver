<?php

final class PHUIDocumentView extends AphrontTagView {

  /* For mobile displays, where do you want the sidebar */
  const NAV_BOTTOM = 'nav_bottom';
  const NAV_TOP = 'nav_top';

  private $offset;
  private $header;
  private $sidenav;
  private $topnav;
  private $crumbs;
  private $bookname;
  private $bookdescription;
  private $mobileview;

  public function setOffset($offset) {
    $this->offset = $offset;
    return $this;
  }

  public function setHeader(PHUIHeaderView $header) {
    $header->setGradient(PhabricatorActionHeaderView::HEADER_LIGHTBLUE);
    $this->header = $header;
    return $this;
  }

  public function setSideNav(PHUIListView $list, $display = self::NAV_BOTTOM) {
    $list->setType(PHUIListView::SIDENAV_LIST);
    $this->sidenav = $list;
    $this->mobileview = $display;
    return $this;
  }

  public function setTopNav(PHUIListView $list) {
    $list->setType(PHUIListView::NAVBAR_LIST);
    $this->topnav = $list;
    return $this;
  }

  public function setCrumbs(PHUIListView $list) {
    $this->crumbs  = $list;
    return $this;
  }

  public function setBook($name, $description) {
    $this->bookname = $name;
    $this->bookdescription = $description;
    return $this;
  }

  public function getTagAttributes() {
    $classes = array();

    if ($this->offset) {
      $classes[] = 'phui-document-offset';
    };

    return array(
      'class' => $classes,
    );
  }

  public function getTagContent() {
    require_celerity_resource('phui-document-view-css');

    $classes = array();
    $classes[] = 'phui-document-view';
    if ($this->offset) {
      $classes[] = 'phui-offset-view';
    }
    if ($this->sidenav) {
      $classes[] = 'phui-sidenav-view';
    }

    $sidenav = null;
    if ($this->sidenav) {
      $sidenav = phutil_tag(
        'div',
        array(
          'class' => 'phui-document-sidenav'
        ),
        $this->sidenav);
    }

    $book = null;
    if ($this->bookname) {
      $book = phutil_tag(
        'div',
        array(
          'class' => 'phui-document-bookname grouped'
        ),
        array(
          phutil_tag(
            'span',
            array('class' => 'bookname'),
            $this->bookname),
          phutil_tag(
            'span',
            array('class' => 'bookdescription'),
          $this->bookdescription)));
    }

    $topnav = null;
    if ($this->topnav) {
      $topnav = phutil_tag(
        'div',
        array(
          'class' => 'phui-document-topnav'
        ),
        $this->topnav);
    }

    $crumbs = null;
    if ($this->crumbs) {
      $crumbs = phutil_tag(
        'div',
        array(
          'class' => 'phui-document-crumbs'
        ),
        $this->bookName);
    }

    $content_inner = phutil_tag(
        'div',
        array(
          'class' => 'phui-document-inner',
        ),
        array(
          $book,
          $this->header,
          $topnav,
          $this->renderChildren(),
          $crumbs
        ));

    if ($this->mobileview == self::NAV_BOTTOM) {
      $order = array($content_inner, $sidenav);
    } else {
      $order = array($sidenav, $content_inner);
    }

    $content = phutil_tag(
        'div',
        array(
          'class' => 'phui-document-content',
        ),
        $order);

    $view = phutil_tag(
      'div',
      array(
        'class' => implode(' ', $classes),
      ),
      $content);

    return $view;
  }

}
