<?php

final class DrydockResourceViewController extends DrydockController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $nav = $this->buildSideNav('resource');

    $resource = id(new DrydockResource())->load($this->id);
    if (!$resource) {
      return new Aphront404Response();
    }

    $title = 'Resource '.$resource->getID().' '.$resource->getName();

    $header = id(new PhabricatorHeaderView())
      ->setHeader($title);

    $actions = $this->buildActionListView($resource);
    $properties = $this->buildPropertyListView($resource);

    $resource_uri = 'resource/'.$resource->getID().'/';
    $resource_uri = $this->getApplicationURI($resource_uri);

    $pager = new AphrontPagerView();
    $pager->setURI(new PhutilURI($resource_uri), 'offset');
    $pager->setOffset($request->getInt('offset'));

    $logs = id(new DrydockLogQuery())
      ->withResourceIDs(array($resource->getID()))
      ->executeWithOffsetPager($pager);

    $log_table = $this->buildLogTableView($logs);
    $log_table->appendChild($pager);

    $nav->appendChild(
      array(
        $header,
        $actions,
        $properties,
        $log_table,
      ));

    return $this->buildApplicationPage(
      $nav,
      array(
        'device'  => true,
        'title'   => $title,
      ));

  }

  private function buildActionListView(DrydockResource $resource) {
    $view = id(new PhabricatorActionListView())
      ->setUser($this->getRequest()->getUser())
      ->setObject($resource);

    $can_close = ($resource->getStatus() == DrydockResourceStatus::STATUS_OPEN);
    $uri = '/resource/'.$resource->getID().'/close/';
    $uri = $this->getApplicationURI($uri);

    $view->addAction(
      id(new PhabricatorActionView())
        ->setHref($uri)
        ->setName(pht('Close Resource'))
        ->setIcon('delete')
        ->setWorkflow(true)
        ->setDisabled(!$can_close));

    return $view;
  }

  private function buildPropertyListView(DrydockResource $resource) {
    $view = new PhabricatorPropertyListView();

    $status = $resource->getStatus();
    $status = DrydockResourceStatus::getNameForStatus($status);
    $status = phutil_escape_html($status);

    $view->addProperty(
      pht('Status'),
      $status);

    $view->addProperty(
      pht('Resource Type'),
      phutil_escape_html($resource->getType()));

    return $view;
  }

}
