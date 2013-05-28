<?php

/**
 * @group phame
 */
final class PhameBlogViewController extends PhameController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $blog = id(new PhameBlogQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$blog) {
      return new Aphront404Response();
    }

    $pager = id(new AphrontCursorPagerView())
      ->readFromRequest($request);

    $posts = id(new PhamePostQuery())
      ->setViewer($user)
      ->withBlogPHIDs(array($blog->getPHID()))
      ->executeWithCursorPager($pager);

    $nav = $this->renderSideNavFilterView(null);

    $header = id(new PhabricatorHeaderView())
      ->setHeader($blog->getName());

    $handle_phids = array_merge(
      mpull($posts, 'getBloggerPHID'),
      mpull($posts, 'getBlogPHID'));
    $this->loadHandles($handle_phids);

    $actions = $this->renderActions($blog, $user);
    $properties = $this->renderProperties($blog, $user);
    $post_list = $this->renderPostList(
      $posts,
      $user,
      pht('This blog has no visible posts.'));

    require_celerity_resource('phame-css');
    $post_list = id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_LARGE)
      ->addClass('phame-post-list')
      ->appendChild($post_list);


    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($blog->getName())
        ->setHref($this->getApplicationURI()));

    $nav->appendChild(
      array(
        $crumbs,
        $header,
        $actions,
        $properties,
        $post_list,
      ));

    return $this->buildApplicationPage(
      $nav,
      array(
        'device' => true,
        'title' => $blog->getName(),
        'dust' => true,
      ));
  }

  private function renderProperties(PhameBlog $blog, PhabricatorUser $user) {
    require_celerity_resource('aphront-tooltip-css');
    Javelin::initBehavior('phabricator-tooltips');

    $properties = new PhabricatorPropertyListView();

    $properties->addProperty(
      pht('Skin'),
      $blog->getSkin());

    $properties->addProperty(
      pht('Domain'),
      $blog->getDomain());

    $feed_uri = PhabricatorEnv::getProductionURI(
      $this->getApplicationURI('blog/feed/'.$blog->getID().'/'));
    $properties->addProperty(
      pht('Atom URI'),
      javelin_tag('a',
        array(
          'href' => $feed_uri,
          'sigil' => 'has-tooltip',
          'meta' => array(
            'tip' => pht('Atom URI does not support custom domains.'),
            'size' => 320,
          )
        ),
        $feed_uri));

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $user,
      $blog);

    $properties->addProperty(
      pht('Visible To'),
      $descriptions[PhabricatorPolicyCapability::CAN_VIEW]);

    $properties->addProperty(
      pht('Editable By'),
      $descriptions[PhabricatorPolicyCapability::CAN_EDIT]);

    $properties->addProperty(
      pht('Joinable By'),
      $descriptions[PhabricatorPolicyCapability::CAN_JOIN]);

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($user)
      ->addObject($blog, PhameBlog::MARKUP_FIELD_DESCRIPTION)
      ->process();

    $properties->addTextContent(
      phutil_tag(
        'div',
        array(
          'class' => 'phabricator-remarkup',
        ),
        $engine->getOutput($blog, PhameBlog::MARKUP_FIELD_DESCRIPTION)));

    return $properties;
  }

  private function renderActions(PhameBlog $blog, PhabricatorUser $user) {

    $actions = id(new PhabricatorActionListView())
      ->setObject($blog)
      ->setUser($user);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $user,
      $blog,
      PhabricatorPolicyCapability::CAN_EDIT);

    $can_join = PhabricatorPolicyFilter::hasCapability(
      $user,
      $blog,
      PhabricatorPolicyCapability::CAN_JOIN);

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('new')
        ->setHref($this->getApplicationURI('post/edit/?blog='.$blog->getID()))
        ->setName(pht('Write Post'))
        ->setDisabled(!$can_join)
        ->setWorkflow(!$can_join));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setUser($user)
        ->setIcon('world')
        ->setHref($this->getApplicationURI('live/'.$blog->getID().'/'))
        ->setRenderAsForm(true)
        ->setName(pht('View Live')));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('edit')
        ->setHref($this->getApplicationURI('blog/edit/'.$blog->getID().'/'))
        ->setName('Edit Blog')
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('delete')
        ->setHref($this->getApplicationURI('blog/delete/'.$blog->getID().'/'))
        ->setName('Delete Blog')
        ->setDisabled(!$can_edit)
        ->setWorkflow(true));

    return $actions;
  }

}
