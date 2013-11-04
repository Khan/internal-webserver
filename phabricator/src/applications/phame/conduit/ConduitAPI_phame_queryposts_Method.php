<?php

/**
 * @group conduit
 */
final class ConduitAPI_phame_queryposts_Method extends ConduitAPI_phame_Method {

  public function getMethodDescription() {
    return "Query phame posts.";
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function defineParamTypes() {
    return array(
      'ids'            => 'optional list<int>',
      'phids'          => 'optional list<phid>',
      'blogPHIDs'      => 'optional list<phid>',
      'bloggerPHIDs'   => 'optional list<phid>',
      'phameTitles'    => 'optional list<string>',
      'published'      => 'optional bool',
      'publishedAfter' => 'optional date',
      'before'         => 'optional int',
      'after'          => 'optional int',
      'limit'          => 'optional int',
    );
  }

  public function defineReturnType() {
    return 'list<dict>';
  }

  public function defineErrorTypes() {
    return array(
    );
  }

  protected function execute(ConduitAPIRequest $request) {

    $query = new PhamePostQuery();

    $query->setViewer($request->getUser());

    $ids = $request->getValue('ids', array());
    if ($ids) {
      $query->withIDs($ids);
    }

    $phids = $request->getValue('phids', array());
    if ($phids) {
      $query->withPHIDs($phids);
    }

    $blog_phids = $request->getValue('blogPHIDs', array());
    if ($blog_phids) {
      $query->withBlogPHIDs($blog_phids);
    }

    $blogger_phids = $request->getValue('bloggerPHIDs', array());
    if ($blogger_phids) {
      $query->withBloggerPHIDs($blogger_phids);
    }

    $phame_titles = $request->getValue('phameTitles', array());
    if ($phame_titles) {
      $query->withPhameTitles($phame_titles);
    }

    $published = $request->getValue('published', null);
    if ($published === true) {
      $query->withVisibility(PhamePost::VISIBILITY_PUBLISHED);
    } else if ($published === false) {
      $query->withVisibility(PhamePost::VISIBILITY_DRAFT);
    }

    $published_after = $request->getValue('publishedAfter', null);
    if ($published_after !== null) {
      $query->withPublishedAfter($published_after);
    }

    $after = $request->getValue('after', null);
    if ($after !== null) {
      $query->setAfterID($after);
    }

    $before = $request->getValue('before', null);
    if ($before !== null) {
      $query->setBeforeID($before);
    }

    $limit = $request->getValue('limit', null);
    if ($limit !== null) {
      $query->setLimit($limit);
    }

    $blogs = $query->execute();

    $results = array();
    foreach ($blogs as $blog) {
      $results[] = array(
        'id'            => $blog->getID(),
        'phid'          => $blog->getPHID(),
        'blogPHID'      => $blog->getBlogPHID(),
        'bloggerPHID'   => $blog->getBloggerPHID(),
        'viewURI'       => $blog->getViewURI(),
        'title'         => $blog->getTitle(),
        'phameTitle'    => $blog->getPhameTitle(),
        'body'          => $blog->getBody(),
        'summary'       => PhabricatorMarkupEngine::summarize($blog->getBody()),
        'datePublished' => $blog->getDatePublished(),
        'published'     => !$blog->isDraft(),
      );
    }

    return $results;
  }


}
