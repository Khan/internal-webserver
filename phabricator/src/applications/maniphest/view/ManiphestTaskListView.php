<?php

/**
 * @group maniphest
 */
final class ManiphestTaskListView extends ManiphestView {

  private $tasks;
  private $handles;
  private $showBatchControls;
  private $showSubpriorityControls;

  public function setTasks(array $tasks) {
    assert_instances_of($tasks, 'ManiphestTask');
    $this->tasks = $tasks;
    return $this;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function setShowBatchControls($show_batch_controls) {
    $this->showBatchControls = $show_batch_controls;
    return $this;
  }

  public function setShowSubpriorityControls($show_subpriority_controls) {
    $this->showSubpriorityControls = $show_subpriority_controls;
    return $this;
  }

  public function render() {
    $handles = $this->handles;

    $list = new PHUIObjectItemListView();
    $list->setCards(true);
    $list->setFlush(true);

    $status_map = ManiphestTaskStatus::getTaskStatusMap();
    $color_map = ManiphestTaskPriority::getColorMap();

    if ($this->showBatchControls) {
      Javelin::initBehavior('maniphest-list-editor');
    }

    foreach ($this->tasks as $task) {
      $item = new PHUIObjectItemView();
      $item->setObjectName('T'.$task->getID());
      $item->setHeader($task->getTitle());
      $item->setHref('/T'.$task->getID());

      if ($task->getOwnerPHID()) {
        $owner = idx($handles, $task->getOwnerPHID());
        // TODO: This should be guaranteed, see T3817.
        if ($owner) {
          $item->addByline(pht('Assigned: %s', $owner->renderLink()));
        }
      }

      $status = $task->getStatus();
      if ($status != ManiphestTaskStatus::STATUS_OPEN) {
        $item->addFootIcon(
          ($status == ManiphestTaskStatus::STATUS_CLOSED_RESOLVED)
            ? 'enable-white'
            : 'delete-white',
          idx($status_map, $status, 'Unknown'));
      }

      $item->setBarColor(idx($color_map, $task->getPriority(), 'grey'));

      $item->addIcon(
        'none',
        phabricator_datetime($task->getDateModified(), $this->getUser()));

      if ($this->showSubpriorityControls) {
        $item->setGrippable(true);
      }
      if ($this->showSubpriorityControls || $this->showBatchControls) {
        $item->addSigil('maniphest-task');
      }

      $projects_view = new ManiphestTaskProjectsView();
      $projects_view->setHandles(
        array_select_keys(
          $handles,
          $task->getProjectPHIDs()));

      $item->addAttribute($projects_view);

      $item->setMetadata(
        array(
          'taskID' => $task->getID(),
        ));

      if ($this->showBatchControls) {
        $item->addAction(
          id(new PHUIListItemView())
            ->setIcon('edit')
            ->addSigil('maniphest-edit-task')
            ->setHref('/maniphest/task/edit/'.$task->getID().'/'));
      }

      $list->addItem($item);
    }

    return $list;
  }

}
