<?php

final class PhabricatorConfigIssueViewController
  extends PhabricatorConfigController {

  private $issueKey;

  public function willProcessRequest(array $data) {
    $this->issueKey = $data['key'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $issues = PhabricatorSetupCheck::runAllChecks();
    PhabricatorSetupCheck::setOpenSetupIssueCount(count($issues));

    if (empty($issues[$this->issueKey])) {
      $content = id(new AphrontErrorView())
        ->setSeverity(AphrontErrorView::SEVERITY_NOTICE)
        ->setTitle(pht('Issue Resolved'))
        ->appendChild(pht('This setup issue has been resolved. '))
        ->appendChild(
          phutil_render_tag(
            'a',
            array(
              'href' => $this->getApplicationURI('issue/'),
            ),
            pht('Return to Open Issue List')));
      $title = pht('Resolved Issue');
    } else {
      $issue = $issues[$this->issueKey];
      $content = $this->renderIssue($issue);
      $title = $issue->getShortName();
    }

    $crumbs = $this
      ->buildApplicationCrumbs()
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Setup Issues'))
          ->setHref($this->getApplicationURI('issue/')))
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName($title)
          ->setHref($request->getRequestURI()));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $content,
      ),
      array(
        'title' => $title,
        'device' => true,
      )
    );
  }

  private function renderIssue(PhabricatorSetupIssue $issue) {
    require_celerity_resource('setup-issue-css');

    $view = new PhabricatorSetupIssueView();
    $view->setIssue($issue);
    $view;

    $container = phutil_render_tag(
      'div',
      array(
        'class' => 'setup-issue-background',
      ),
      $view->render());

    return $container;
  }

}
