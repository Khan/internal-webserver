<?php

/**
 * @group maniphest
 */
final class ManiphestExportController extends ManiphestController {

  private $key;

  public function willProcessRequest(array $data) {
    $this->key = $data['key'];
    return $this;
  }

  /**
   * @phutil-external-symbol class PHPExcel
   * @phutil-external-symbol class PHPExcel_IOFactory
   * @phutil-external-symbol class PHPExcel_Style_NumberFormat
   * @phutil-external-symbol class PHPExcel_Cell_DataType
   */
  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $ok = @include_once 'PHPExcel.php';
    if (!$ok) {
      $dialog = new AphrontDialogView();
      $dialog->setUser($user);

      $inst1 = pht(
        'This system does not have PHPExcel installed. This software '.
        'component is required to export tasks to Excel. Have your system '.
        'administrator install it from:');

      $inst2 = pht(
        'Your PHP "include_path" needs to be updated to include the '.
        'PHPExcel Classes directory.');

      $dialog->setTitle(pht('Excel Export Not Configured'));
      $dialog->appendChild(hsprintf(
        '<p>%s</p>'.
        '<br />'.
        '<p>'.
          '<a href="http://www.phpexcel.net/">http://www.phpexcel.net/</a>'.
        '</p>'.
        '<br />'.
        '<p>%s</p>',
        $inst1,
        $inst2));

      $dialog->addCancelButton('/maniphest/');
      return id(new AphrontDialogResponse())->setDialog($dialog);
    }

    // TODO: PHPExcel has a dependency on the PHP zip extension. We should test
    // for that here, since it fatals if we don't have the ZipArchive class.

    $query = id(new PhabricatorSearchQuery())->loadOneWhere(
      'queryKey = %s',
      $this->key);
    if (!$query) {
      return new Aphront404Response();
    }

    if (!$request->isDialogFormPost()) {
      $dialog = new AphrontDialogView();
      $dialog->setUser($user);

      $dialog->setTitle(pht('Export Tasks to Excel'));
      $dialog->appendChild(phutil_tag('p', array(), pht(
        'Do you want to export the query results to Excel?')));

      $dialog->addCancelButton('/maniphest/');
      $dialog->addSubmitButton(pht('Export to Excel'));
      return id(new AphrontDialogResponse())->setDialog($dialog);

    }

    $query->setParameter('limit',   null);
    $query->setParameter('offset',  null);
    $query->setParameter('order',   'p');
    $query->setParameter('group',   'n');

    list($tasks, $handles) = ManiphestTaskListController::loadTasks(
      $query,
      $user);
    // Ungroup tasks.
    $tasks = array_mergev($tasks);

    $all_projects = array_mergev(mpull($tasks, 'getProjectPHIDs'));
    $project_handles = $this->loadViewerHandles($all_projects);
    $handles += $project_handles;

    $workbook = new PHPExcel();
    $sheet = $workbook->setActiveSheetIndex(0);
    $sheet->setTitle(pht('Tasks'));

    $widths = array(
      null,
      15,
      null,
      10,
      15,
      15,
      60,
      30,
      20,
      100,
    );

    foreach ($widths as $col => $width) {
      if ($width !== null) {
        $sheet->getColumnDimension($this->col($col))->setWidth($width);
      }
    }

    $status_map = ManiphestTaskStatus::getTaskStatusMap();
    $pri_map = ManiphestTaskPriority::getTaskPriorityMap();

    $date_format = null;

    $rows = array();
    $rows[] = array(
      pht('ID'),
      pht('Owner'),
      pht('Status'),
      pht('Priority'),
      pht('Date Created'),
      pht('Date Updated'),
      pht('Title'),
      pht('Projects'),
      pht('URI'),
      pht('Description'),
    );

    $is_date = array(
      false,
      false,
      false,
      false,
      true,
      true,
      false,
      false,
      false,
      false,
    );

    $header_format = array(
      'font'  => array(
        'bold' => true,
      ),
    );

    foreach ($tasks as $task) {
      $task_owner = null;
      if ($task->getOwnerPHID()) {
        $task_owner = $handles[$task->getOwnerPHID()]->getName();
      }

      $projects = array();
      foreach ($task->getProjectPHIDs() as $phid) {
        $projects[] = $handles[$phid]->getName();
      }
      $projects = implode(', ', $projects);

      $rows[] = array(
        'T'.$task->getID(),
        $task_owner,
        idx($status_map, $task->getStatus(), '?'),
        idx($pri_map, $task->getPriority(), '?'),
        $this->computeExcelDate($task->getDateCreated()),
        $this->computeExcelDate($task->getDateModified()),
        $task->getTitle(),
        $projects,
        PhabricatorEnv::getProductionURI('/T'.$task->getID()),
        phutil_utf8_shorten($task->getDescription(), 512),
      );
    }

    foreach ($rows as $row => $cols) {
      foreach ($cols as $col => $spec) {
        $cell_name = $this->col($col).($row + 1);
        $sheet
          ->setCellValue($cell_name, $spec, $return_cell = true)
          ->setDataType(PHPExcel_Cell_DataType::TYPE_STRING);

        if ($row == 0) {
          $sheet->getStyle($cell_name)->applyFromArray($header_format);
        }

        if ($is_date[$col]) {
          $code = PHPExcel_Style_NumberFormat::FORMAT_DATE_YYYYMMDD2;
          $sheet
            ->getStyle($cell_name)
            ->getNumberFormat()
            ->setFormatCode($code);
        }
      }
    }

    $writer = PHPExcel_IOFactory::createWriter($workbook, 'Excel2007');

    ob_start();
    $writer->save('php://output');
    $data = ob_get_clean();

    $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    return id(new AphrontFileResponse())
      ->setMimeType($mime)
      ->setDownload('maniphest_tasks_'.date('Ymd').'.xlsx')
      ->setContent($data);
  }

  private function computeExcelDate($epoch) {
    $seconds_per_day = (60 * 60 * 24);
    $offset = ($seconds_per_day * 25569);

    return ($epoch + $offset) / $seconds_per_day;
  }

  private function col($n) {
    return chr(ord('A') + $n);
  }

}
