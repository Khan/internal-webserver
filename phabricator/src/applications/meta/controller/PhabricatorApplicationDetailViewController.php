<?php

final class PhabricatorApplicationDetailViewController
  extends PhabricatorApplicationsController{

  private $application;

  public function willProcessRequest(array $data) {
    $this->application = $data['application'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $selected = null;
    $applications = PhabricatorApplication::getAllApplications();

    foreach ($applications as $application) {
      if (get_class($application) == $this->application) {
      $selected = $application;
      break;
      }
    }

    if (!$selected) {
      return new Aphront404Response();
    }

    $title = $selected->getName();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Applications'))
        ->setHref($this->getApplicationURI()));

   $properties = $this->buildPropertyView($selected);
   $actions = $this->buildActionView($user, $selected);

   return $this->buildApplicationPage(
    array(
      $crumbs,
      id(new PhabricatorHeaderView())->setHeader($title),
      $actions,
      $properties,
    ),
    array(
      'title' => $title,
      'device' => true,
    ));
  }

  private function buildPropertyView(PhabricatorApplication $selected) {
    $properties = new PhabricatorPropertyListView();

    if ($selected->isInstalled()) {
      $properties->addProperty(
        pht('Status'), pht('Installed'));

    } else {
      $properties->addProperty(
        pht('Status'), pht('Uninstalled'));
    }

    $properties->addProperty(
      pht('Description'), $selected->getShortDescription());

    return $properties;
  }

  private function buildActionView(
    PhabricatorUser $user, PhabricatorApplication $selected) {

    if ($selected->canUninstall()) {
      if ($selected->isInstalled()) {

        return id(new PhabricatorActionListView())
          ->setUser($user)
          ->addAction(
            id(new PhabricatorActionView())
              ->setName(pht('Uninstall'))
              ->setIcon('delete')
              ->setHref(
                $this->getApplicationURI(get_class($selected).'/uninstall/'))
              );
      } else {
          return id(new PhabricatorActionListView())
            ->setUser($user)
            ->addAction(
              id(new PhabricatorActionView())
              ->setName(pht('Install'))
              ->setIcon('new')
              ->setHref(
                $this->getApplicationURI(get_class($selected).'/install/'))
              );
      }
    }
  }

}
