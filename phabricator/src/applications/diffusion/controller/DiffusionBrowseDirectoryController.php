<?php

final class DiffusionBrowseDirectoryController
  extends DiffusionBrowseController {

  private $browseQueryResults;

  public function setBrowseQueryResults(DiffusionBrowseResultSet $results) {
    $this->browseQueryResults = $results;
    return $this;
  }

  public function getBrowseQueryResults() {
    return $this->browseQueryResults;
  }

  public function processRequest() {
    $drequest = $this->diffusionRequest;

    $results = $this->getBrowseQueryResults();
    $reason = $results->getReasonForEmptyResultSet();

    $content = array();

    $content[] = $this->buildHeaderView($drequest);
    $content[] = $this->buildActionView($drequest);
    $content[] = $this->buildPropertyView($drequest);

    $content[] = $this->renderSearchForm($collapsed = true);

    if (!$results->isValidResults()) {
      $empty_result = new DiffusionEmptyResultView();
      $empty_result->setDiffusionRequest($drequest);
      $empty_result->setDiffusionBrowseResultSet($results);
      $empty_result->setView($this->getRequest()->getStr('view'));
      $content[] = $empty_result;
    } else {
      $phids = array();
      foreach ($results->getPaths() as $result) {
        $data = $result->getLastCommitData();
        if ($data) {
          if ($data->getCommitDetail('authorPHID')) {
            $phids[$data->getCommitDetail('authorPHID')] = true;
          }
        }
      }

      $phids = array_keys($phids);
      $handles = $this->loadViewerHandles($phids);

      $browse_table = new DiffusionBrowseTableView();
      $browse_table->setDiffusionRequest($drequest);
      $browse_table->setHandles($handles);
      $browse_table->setPaths($results->getPaths());
      $browse_table->setUser($this->getRequest()->getUser());

      $browse_panel = new AphrontPanelView();
      $browse_panel->appendChild($browse_table);
      $browse_panel->setNoBackground();

      $content[] = $browse_panel;
    }

    $content[] = $this->buildOpenRevisions();

    $readme = $this->callConduitWithDiffusionRequest(
      'diffusion.readmequery',
      array(
        'paths' => $results->getPathDicts(),
      ));
    if ($readme) {
      $box = new PHUIBoxView();
      $box->setShadow(true);
      $box->appendChild($readme);
      $box->addPadding(PHUI::PADDING_LARGE);
      $box->addMargin(PHUI::MARGIN_LARGE);

      $header = id(new PHUIHeaderView())
        ->setHeader(pht('README'));

      $content[] = array(
        $header,
        $box,
      );
    }

    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'browse',
      ));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $content,
      ),
      array(
        'device' => true,
        'title' => array(
          nonempty(basename($drequest->getPath()), '/'),
          $drequest->getRepository()->getCallsign().' Repository',
        ),
      ));
  }

}
