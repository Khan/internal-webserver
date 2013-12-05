<?php

final class HarbormasterBuildViewController
  extends HarbormasterController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $id = $this->id;

    $build = id(new HarbormasterBuildQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
    if (!$build) {
      return new Aphront404Response();
    }

    $title = pht("Build %d", $id);

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setUser($viewer)
      ->setPolicyObject($build);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header);

    $actions = $this->buildActionList($build);
    $this->buildPropertyLists($box, $build, $actions);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($title));

    $build_targets = id(new HarbormasterBuildTargetQuery())
      ->setViewer($viewer)
      ->withBuildPHIDs(array($build->getPHID()))
      ->execute();

    $targets = array();
    foreach ($build_targets as $build_target) {
      $header = id(new PHUIHeaderView())
        ->setHeader(pht('Build Target %d', $build_target->getID()))
        ->setUser($viewer);
      $properties = new PHUIPropertyListView();

      $details = $build_target->getDetails();
      if ($details) {
        $properties->addSectionHeader(pht('Configuration Details'));
        foreach ($details as $key => $value) {
          $properties->addProperty($key, $value);
        }
      }

      $variables = $build_target->getVariables();
      if ($variables) {
        $properties->addSectionHeader(pht('Variables'));
        foreach ($variables as $key => $value) {
          $properties->addProperty($key, $value);
        }
      }

      $targets[] = id(new PHUIObjectBoxView())
        ->setHeader($header)
        ->addPropertyList($properties);

      $targets[] = $this->buildArtifacts($build_target);
      $targets[] = $this->buildLog($build, $build_target);
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
        $targets
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function buildArtifacts(HarbormasterBuildTarget $build_target) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $artifacts = id(new HarbormasterBuildArtifactQuery())
      ->setViewer($viewer)
      ->withBuildTargetPHIDs(array($build_target->getPHID()))
      ->execute();

    if (count($artifacts) === 0) {
      return null;
    }

    $list = new PHUIObjectItemListView();

    foreach ($artifacts as $artifact) {
      $list->addItem($artifact->getObjectItemView($viewer));
    }

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Build Artifacts'))
      ->setUser($viewer);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header);

    return array($box, $list);
  }

  private function buildLog(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $request = $this->getRequest();
    $viewer = $request->getUser();
    $limit = $request->getInt('l', 25);

    $logs = id(new HarbormasterBuildLogQuery())
      ->setViewer($viewer)
      ->withBuildTargetPHIDs(array($build_target->getPHID()))
      ->execute();

    $log_boxes = array();
    foreach ($logs as $log) {
      $start = 1;
      $lines = preg_split("/\r\n|\r|\n/", $log->getLogText());
      if ($limit !== 0) {
        $start = count($lines) - $limit;
        if ($start >= 1) {
          $lines = array_slice($lines, -$limit, $limit);
        } else {
          $start = 1;
        }
      }
      $log_view = new ShellLogView();
      $log_view->setLines($lines);
      $log_view->setStart($start);

      $header = id(new PHUIHeaderView())
        ->setHeader(pht(
          'Build Log %d (%s - %s)',
          $log->getID(),
          $log->getLogSource(),
          $log->getLogType()))
        ->setSubheader($this->createLogHeader($build, $log))
        ->setUser($viewer);

      $log_boxes[] = id(new PHUIObjectBoxView())
        ->setHeader($header)
        ->setForm($log_view);
    }

    return $log_boxes;
  }

  private function createLogHeader($build, $log) {
    $request = $this->getRequest();
    $limit = $request->getInt('l', 25);

    $lines_25 = $this->getApplicationURI('/build/'.$build->getID().'/?l=25');
    $lines_50 = $this->getApplicationURI('/build/'.$build->getID().'/?l=50');
    $lines_100 =
      $this->getApplicationURI('/build/'.$build->getID().'/?l=100');
    $lines_0 = $this->getApplicationURI('/build/'.$build->getID().'/?l=0');

    $link_25 = phutil_tag('a', array('href' => $lines_25), pht('25'));
    $link_50 = phutil_tag('a', array('href' => $lines_50), pht('50'));
    $link_100 = phutil_tag('a', array('href' => $lines_100), pht('100'));
    $link_0 = phutil_tag('a', array('href' => $lines_0), pht('Unlimited'));

    if ($limit === 25) {
      $link_25 = phutil_tag('strong', array(), $link_25);
    } else if ($limit === 50) {
      $link_50 = phutil_tag('strong', array(), $link_50);
    } else if ($limit === 100) {
      $link_100 = phutil_tag('strong', array(), $link_100);
    } else if ($limit === 0) {
      $link_0 = phutil_tag('strong', array(), $link_0);
    }

    return phutil_tag(
      'span',
      array(),
      array(
        $link_25,
        ' - ',
        $link_50,
        ' - ',
        $link_100,
        ' - ',
        $link_0,
        ' Lines'));
  }

  private function buildActionList(HarbormasterBuild $build) {
    $request = $this->getRequest();
    $viewer = $request->getUser();
    $id = $build->getID();

    $list = id(new PhabricatorActionListView())
      ->setUser($viewer)
      ->setObject($build)
      ->setObjectURI("/build/{$id}");

    $action =
      id(new PhabricatorActionView())
        ->setName(pht('Cancel Build'))
        ->setIcon('delete');
    switch ($build->getBuildStatus()) {
      case HarbormasterBuild::STATUS_PENDING:
      case HarbormasterBuild::STATUS_WAITING:
      case HarbormasterBuild::STATUS_BUILDING:
        $cancel_uri = $this->getApplicationURI('/build/cancel/'.$id.'/');
        $action
          ->setHref($cancel_uri)
          ->setWorkflow(true);
        break;
      default:
        $action
          ->setDisabled(true);
        break;
    }
    $list->addAction($action);

    return $list;
  }

  private function buildPropertyLists(
    PHUIObjectBoxView $box,
    HarbormasterBuild $build,
    PhabricatorActionListView $actions) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($build)
      ->setActionList($actions);
    $box->addPropertyList($properties);

    $properties->addProperty(
      pht('Status'),
      $this->getStatus($build));

    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array(
        $build->getBuildablePHID(),
        $build->getBuildPlanPHID()))
      ->execute();

    $properties->addProperty(
      pht('Buildable'),
      $handles[$build->getBuildablePHID()]->renderLink());

    $properties->addProperty(
      pht('Build Plan'),
      $handles[$build->getBuildPlanPHID()]->renderLink());

  }

  private function getStatus(HarbormasterBuild $build) {
    if ($build->getCancelRequested()) {
      return pht('Cancelling');
    }
    switch ($build->getBuildStatus()) {
      case HarbormasterBuild::STATUS_INACTIVE:
        return pht('Inactive');
      case HarbormasterBuild::STATUS_PENDING:
        return pht('Pending');
      case HarbormasterBuild::STATUS_WAITING:
        return pht('Waiting on Resource');
      case HarbormasterBuild::STATUS_BUILDING:
        return pht('Building');
      case HarbormasterBuild::STATUS_PASSED:
        return pht('Passed');
      case HarbormasterBuild::STATUS_FAILED:
        return pht('Failed');
      case HarbormasterBuild::STATUS_ERROR:
        return pht('Unexpected Error');
      case HarbormasterBuild::STATUS_CANCELLED:
        return pht('Cancelled');
      default:
        return pht('Unknown');
    }
  }

}
