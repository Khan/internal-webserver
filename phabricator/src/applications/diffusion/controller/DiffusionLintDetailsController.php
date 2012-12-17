<?php

final class DiffusionLintDetailsController extends DiffusionController {

  public function processRequest() {
    $limit = 500;
    $offset = $this->getRequest()->getInt('offset', 0);

    $drequest = $this->getDiffusionRequest();
    $branch = $drequest->loadBranch();
    $messages = $this->loadLintMessages($branch, $limit, $offset);
    $is_dir = (substr('/'.$drequest->getPath(), -1) == '/');

    $rows = array();
    foreach ($messages as $message) {
      $path = hsprintf(
        '<a href="%s">%s</a>',
        $drequest->generateURI(array(
          'action' => 'lint',
          'path' => $message['path'],
        )),
        substr($message['path'], strlen($drequest->getPath()) + 1));

      $line = hsprintf(
        '<a href="%s">%s</a>',
        $drequest->generateURI(array(
          'action' => 'browse',
          'path' => $message['path'],
          'line' => $message['line'],
          'commit' => $branch->getLintCommit(),
        )),
        $message['line']);

      $rows[] = array(
        $path,
        $line,
        phutil_escape_html(ArcanistLintSeverity::getStringForSeverity(
          $message['severity'])),
        phutil_escape_html($message['name']),
        phutil_escape_html($message['description']),
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(array(
        'Path',
        'Line',
        'Severity',
        'Name',
        'Description',
      ))
      ->setColumnClasses(array('', 'n', '', '', ''))
      ->setColumnVisibility(array($is_dir));

    $content = array();

    $pager = id(new AphrontPagerView())
      ->setPageSize($limit)
      ->setOffset($offset)
      ->setHasMorePages(count($messages) >= $limit)
      ->setURI($this->getRequest()->getRequestURI(), 'offset');

    $lint = $drequest->getLint();
    $link = hsprintf(
      '<a href="%s">%s</a>',
      $drequest->generateURI(array(
        'action' => 'lint',
        'lint' => null,
      )),
      pht('Switch to Grouped View'));

    $content[] = id(new AphrontPanelView())
      ->setHeader(
        ($lint != '' ? phutil_escape_html($lint)." \xC2\xB7 " : '').
        pht('%d Lint Message(s)', count($messages)))
      ->setCaption($link)
      ->appendChild($table)
      ->appendChild($pager);

    $nav = $this->buildSideNav('lint', false);
    $nav->appendChild($content);
    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'lint',
      ));
    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array('title' => array(
        'Lint',
        $drequest->getRepository()->getCallsign(),
      )));
  }

  private function loadLintMessages(
    PhabricatorRepositoryBranch $branch,
    $limit,
    $offset) {

    $drequest = $this->getDiffusionRequest();
    if (!$branch) {
      return array();
    }

    $conn = $branch->establishConnection('r');

    $where = array(
      qsprintf(
        $conn,
        'branchID = %d',
        $branch->getID())
    );
    if ($drequest->getPath() != '') {
      $is_dir = (substr($drequest->getPath(), -1) == '/');
      $where[] = qsprintf(
        $conn,
        'path '.($is_dir ? 'LIKE %>' : '= %s'),
        '/'.$drequest->getPath());
    }

    if ($drequest->getLint() != '') {
      $where[] = qsprintf(
        $conn,
        'code = %s',
        $drequest->getLint());
    }

    return queryfx_all(
      $conn,
      'SELECT *
        FROM %T
        WHERE %Q
        ORDER BY path, code, line LIMIT %d OFFSET %d',
      PhabricatorRepository::TABLE_LINTMESSAGE,
      implode(' AND ', $where),
      $limit,
      $offset);
  }

}
