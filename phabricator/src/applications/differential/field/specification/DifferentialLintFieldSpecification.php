<?php

final class DifferentialLintFieldSpecification
  extends DifferentialFieldSpecification {

  public function shouldAppearOnDiffView() {
      return true;
  }

  public function renderLabelForDiffView() {
    return $this->renderLabelForRevisionView();
  }

  public function renderValueForDiffView() {
    return $this->renderValueForRevisionView();
  }
  public function shouldAppearOnRevisionView() {
    return true;
  }

  public function renderLabelForRevisionView() {
    return 'Lint:';
  }

  private function getLintExcuse() {
    return $this->getDiffProperty('arc:lint-excuse');
  }

  private function getPostponedLinters() {
    return $this->getDiffProperty('arc:lint-postponed');
  }

  public function renderValueForRevisionView() {
    $diff = $this->getManualDiff();
    $path_changesets = mpull($diff->loadChangesets(), 'getID', 'getFilename');

    $lstar = DifferentialRevisionUpdateHistoryView::renderDiffLintStar($diff);
    $lmsg = DifferentialRevisionUpdateHistoryView::getDiffLintMessage($diff);
    $ldata = $this->getDiffProperty('arc:lint');
    $ltail = null;

    $rows = array();

    $rows[] = array(
      'style'     => 'star',
      'name'      => $lstar,
      'value'     => $lmsg,
      'show'      => true,
    );

    $excuse = $this->getLintExcuse();
    if ($excuse) {
      $rows[] = array(
        'style'   => 'excuse',
        'name'    => 'Excuse',
        'value'   => nl2br(phutil_escape_html($excuse)),
        'show'    => true,
      );
    }

    $show_limit = 10;
    $hidden = array();

    if ($ldata) {
      $ldata = igroup($ldata, 'path');
      foreach ($ldata as $path => $messages) {

        $rows[] = array(
          'style' => 'section',
          'name'  => phutil_escape_html($path),
          'show'  => $show_limit,
        );

        foreach ($messages as $message) {
          $path = idx($message, 'path');
          $line = idx($message, 'line');

          $code = idx($message, 'code');
          $severity = idx($message, 'severity');

          $name = idx($message, 'name');
          $description = idx($message, 'description');

          $line_link = 'line '.intval($line);
          if (isset($path_changesets[$path])) {
            $href = '#C'.$path_changesets[$path].'NL'.max(1, $line);
            if ($diff->getID() != $this->getDiff()->getID()) {
              $href = '/D'.$diff->getRevisionID().'?id='.$diff->getID().$href;
            }
            $line_link = phutil_render_tag(
              'a',
              array(
                'href' => $href,
              ),
              $line_link);
          }

          if ($show_limit) {
            --$show_limit;
            $show = true;
          } else {
            $show = false;
            if (empty($hidden[$severity])) {
              $hidden[$severity] = 0;
            }
            $hidden[$severity]++;
          }

          $rows[] = array(
            'style' => $this->getSeverityStyle($severity),
            'name'  => phutil_escape_html(ucwords($severity)),
            'value' => hsprintf(
              "(%s) %s at {$line_link}",
              $code,
              $name),
            'show'  => $show,
          );

          if (isset($message['locations'])) {
            $locations = array();
            foreach ($message['locations'] as $location) {
              $other_line = idx($location, 'line');
              $locations[] =
                idx($location, 'path', $path).
                ($other_line ? ":{$other_line}" : "");
            }
            $description .= "\nOther locations: ".implode(", ", $locations);
          }

          if (strlen($description)) {
            $rows[] = array(
              'style' => 'details',
              'value' => nl2br(phutil_escape_html($description)),
              'show'  => false,
            );
            if (empty($hidden['details'])) {
              $hidden['details'] = 0;
            }
            $hidden['details']++;
          }
        }
      }
    }

    $postponed = $this->getPostponedLinters();
    if ($postponed) {
      foreach ($postponed as $linter) {
        $rows[] = array(
          'style' => $this->getPostponedStyle(),
          'name' => 'Postponed',
          'value' => phutil_escape_html($linter),
          'show'  => false,
          );
        if (empty($hidden['postponed'])) {
          $hidden['postponed'] = 0;
        }
        $hidden['postponed']++;
      }
    }

    $show_string = $this->renderShowString($hidden);

    $view = new DifferentialResultsTableView();
    $view->setRows($rows);
    $view->setShowMoreString($show_string);

    return $view->render();
  }

  private function getSeverityStyle($severity) {
    $map = array(
      ArcanistLintSeverity::SEVERITY_ERROR      => 'red',
      ArcanistLintSeverity::SEVERITY_WARNING    => 'yellow',
      ArcanistLintSeverity::SEVERITY_AUTOFIX    => 'yellow',
      ArcanistLintSeverity::SEVERITY_ADVICE     => 'yellow',
    );
    return idx($map, $severity);
  }

  private function getPostponedStyle() {
    return 'blue';
  }

  private function renderShowString(array $hidden) {
    if (!$hidden) {
      return null;
    }

    // Reorder hidden things by severity.
    $hidden = array_select_keys(
      $hidden,
      array(
        ArcanistLintSeverity::SEVERITY_ERROR,
        ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistLintSeverity::SEVERITY_AUTOFIX,
        ArcanistLintSeverity::SEVERITY_ADVICE,
        'details',
        'postponed',
      )) + $hidden;

    $show = array();
    foreach ($hidden as $key => $value) {
      switch ($key) {
        case ArcanistLintSeverity::SEVERITY_ERROR:
          $show[] = pht('%d Error(s)', $value);
          break;
        case ArcanistLintSeverity::SEVERITY_WARNING:
          $show[] = pht('%d Warning(s)', $value);
          break;
        case ArcanistLintSeverity::SEVERITY_AUTOFIX:
          $show[] = pht('%d Auto-Fix(es)', $value);
          break;
        case ArcanistLintSeverity::SEVERITY_ADVICE:
          $show[] = pht('%d Advice(s)', $value);
          break;
        case 'details':
          $show[] = pht('%d Detail(s)', $value);
          break;
        case 'postponed':
          $show[] = pht('%d Postponed', $value);
          break;
        default:
          $show[] = $value;
          break;
      }
    }

    return "Show Full Lint Results (".implode(', ', $show).")";
  }

  public function renderWarningBoxForRevisionAccept() {
    $status = $this->getDiff()->getLintStatus();
    if ($status < DifferentialLintStatus::LINT_WARN) {
      return null;
    }

    $severity = AphrontErrorView::SEVERITY_ERROR;
    $titles = array(
      DifferentialLintStatus::LINT_WARN => 'Lint Warning',
      DifferentialLintStatus::LINT_FAIL => 'Lint Failure',
      DifferentialLintStatus::LINT_SKIP => 'Lint Skipped',
      DifferentialLintStatus::LINT_POSTPONED => 'Lint Postponed',
    );

    if ($status == DifferentialLintStatus::LINT_SKIP) {
      $content =
        "<p>This diff was created without running lint. Make sure you are ".
        "OK with that before you accept this diff.</p>";

    } else if ($status == DifferentialLintStatus::LINT_POSTPONED) {
      $severity = AphrontErrorView::SEVERITY_WARNING;
      $content =
        "<p>Postponed linters didn't finish yet. Make sure you are OK with ".
        "that before you accept this diff.</p>";

    } else {
      $content =
        "<p>This diff has Lint Problems. Make sure you are OK with them ".
        "before you accept this diff.</p>";
    }

    return id(new AphrontErrorView())
      ->setSeverity($severity)
      ->appendChild($content)
      ->setTitle(idx($titles, $status, 'Warning'));
  }

}
