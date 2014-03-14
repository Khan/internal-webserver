<?php

final class SubscriptionListDialogBuilder {

  private $viewer;
  private $handles;
  private $objectPHID;
  private $title;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function getHandles() {
    return $this->handles;
  }

  public function setObjectPHID($object_phid) {
    $this->objectPHID = $object_phid;
    return $this;
  }

  public function getObjectPHID() {
    return $this->objectPHID;
  }

  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }

  public function getTitle() {
    return $this->title;
  }

  public function buildDialog() {
    $phid = $this->getObjectPHID();
    $handles = $this->getHandles();
    $object_handle = $handles[$phid];
    unset($handles[$phid]);

    require_celerity_resource('subscribers-list-css');
    return id(new AphrontDialogView())
      ->setUser($this->getViewer())
      ->setClass('subscriber-list-dialog')
      ->setTitle($this->getTitle())
      ->appendChild($this->buildBody($this->getViewer(), $handles))
      ->addCancelButton($object_handle->getURI(), pht('Dismiss'));
  }

  private function buildBody(PhabricatorUser $viewer, array $handles) {

    $list = id(new PHUIObjectItemListView())
      ->setUser($viewer);
    foreach ($handles as $handle) {
      // TODO - include $handle image - T4400
      $item = id(new PHUIObjectItemView())
        ->setHeader($handle->getFullName())
        ->setHref($handle->getURI())
        ->setDisabled($handle->isDisabled());
      $list->addItem($item);
    }

    return $list;
  }

}
