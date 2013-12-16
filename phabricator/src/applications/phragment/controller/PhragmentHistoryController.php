<?php

final class PhragmentHistoryController extends PhragmentController {

  private $dblob;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->dblob = idx($data, "dblob", "");
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $parents = $this->loadParentFragments($this->dblob);
    if ($parents === null) {
      return new Aphront404Response();
    }
    $current = idx($parents, count($parents) - 1, null);

    $path = $current->getPath();

    $crumbs = $this->buildApplicationCrumbsWithPath($parents);
    if ($this->hasApplicationCapability(
      PhragmentCapabilityCanCreate::CAPABILITY)) {
      $crumbs->addAction(
        id(new PHUIListItemView())
          ->setName(pht('Create Fragment'))
          ->setHref($this->getApplicationURI('/create/'.$path))
          ->setIcon('create'));
    }

    $current_box = $this->createCurrentFragmentView($current, true);

    $versions = id(new PhragmentFragmentVersionQuery())
      ->setViewer($viewer)
      ->withFragmentPHIDs(array($current->getPHID()))
      ->execute();

    $list = id(new PHUIObjectItemListView())
      ->setUser($viewer);

    $file_phids = mpull($versions, 'getFilePHID');
    $files = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs($file_phids)
      ->execute();
    $files = mpull($files, null, 'getPHID');

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $current,
      PhabricatorPolicyCapability::CAN_EDIT);

    $first = true;
    foreach ($versions as $version) {
      $item = id(new PHUIObjectItemView());
      $item->setHeader('Version '.$version->getSequence());
      $item->setHref($version->getURI());
      $item->addAttribute(phabricator_datetime(
        $version->getDateCreated(),
        $viewer));

      if ($version->getFilePHID() === null) {
        $item->setDisabled(true);
        $item->addAttribute('Deletion');
      }

      if (!$first && $can_edit) {
        $item->addAction(id(new PHUIListItemView())
          ->setIcon('undo')
          ->setRenderNameAsTooltip(true)
          ->setWorkflow(true)
          ->setName(pht("Revert to Here"))
          ->setHref($this->getApplicationURI(
            "revert/".$version->getID()."/".$current->getPath())));
      }

      $disabled = !isset($files[$version->getFilePHID()]);
      $action = id(new PHUIListItemView())
        ->setIcon('download')
        ->setDisabled($disabled || !$this->isCorrectlyConfigured())
        ->setRenderNameAsTooltip(true)
        ->setName(pht("Download"));
      if (!$disabled && $this->isCorrectlyConfigured()) {
        $action->setHref($files[$version->getFilePHID()]
          ->getDownloadURI($version->getURI()));
      }
      $item->addAction($action);

      $list->addItem($item);

      $first = false;
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $this->renderConfigurationWarningIfRequired(),
        $current_box,
        $list),
      array(
        'title' => pht('Fragment History'),
        'device' => true));
  }

}
