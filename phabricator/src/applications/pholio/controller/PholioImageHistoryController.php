<?php

/**
 * @group pholio
 */
final class PholioImageHistoryController extends PholioController {

  private $imageID;

  public function willProcessRequest(array $data) {
    $this->imageID = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $image = id(new PholioImageQuery())
      ->setViewer($user)
      ->withIDs(array($this->imageID))
      ->executeOne();

    if (!$image) {
      return new Aphront404Response();
    }

    // note while we have a mock object, its missing images we need to show
    // the history of what's happened here.
    // fetch the real deal
    //
    $mock = id(new PholioMockQuery())
      ->setViewer($user)
      ->needImages(true)
      ->withIDs(array($image->getMockID()))
      ->executeOne();

    $phids = array($mock->getAuthorPHID());
    $this->loadHandles($phids);

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($user);
    $engine->addObject($mock, PholioMock::MARKUP_FIELD_DESCRIPTION);
    $engine->process();


    $images = $mock->getImageHistorySet($this->imageID);
    // TODO - this is a hack until we specialize the view object
    $mock->attachImages($images);
    $latest_image = last($images);

    $title = pht(
      'Image history for "%s" from the mock "%s."',
      $latest_image->getName(),
      $mock->getName());

    $header = id(new PhabricatorHeaderView())
      ->setHeader($title);

    require_celerity_resource('pholio-css');
    require_celerity_resource('pholio-inline-comments-css');

    $comment_form_id = celerity_generate_unique_node_id();
    $output = id(new PholioMockImagesView())
      ->setRequestURI($request->getRequestURI())
      ->setCommentFormID($comment_form_id)
      ->setUser($user)
      ->setMock($mock)
      ->setImageID($this->imageID);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs
      ->addCrumb(
        id(new PhabricatorCrumbView())
        ->setName('M'.$mock->getID())
        ->setHref('/M'.$mock->getID()))
      ->addCrumb(
        id(new PhabricatorCrumbView())
        ->setName('Image History')
        ->setHref($request->getRequestURI()));

    $content = array(
      $crumbs,
      $header,
      $output->render(),
    );

    return $this->buildApplicationPage(
      $content,
      array(
        'title' => 'M'.$mock->getID().' '.$title,
        'device' => true,
        'pageObjects' => array($mock->getPHID()),
      ));
  }

}
