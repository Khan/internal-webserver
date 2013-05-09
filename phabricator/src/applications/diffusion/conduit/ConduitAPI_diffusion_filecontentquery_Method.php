<?php

/**
 * @group conduit
 */
final class ConduitAPI_diffusion_filecontentquery_Method
  extends ConduitAPI_diffusion_abstractquery_Method {

  public function getMethodDescription() {
    return 'Retrieve file content from a repository.';
  }

  public function defineReturnType() {
    return 'array';
  }

  protected function defineCustomParamTypes() {
    return array(
      'path' => 'required string',
      'commit' => 'required string',
      'needsBlame' => 'optional bool',
    );
  }

  protected function getResult(ConduitAPIRequest $request) {
    $drequest = $this->getDiffusionRequest();
    $needs_blame = $request->getValue('needsBlame');
    $file_query = DiffusionFileContentQuery::newFromDiffusionRequest(
      $drequest);
    $file_query
      ->setViewer($request->getUser())
      ->setNeedsBlame($needs_blame);
    $file_content = $file_query->loadFileContent();
    if ($needs_blame) {
      list($text_list, $rev_list, $blame_dict) = $file_query->getBlameData();
    } else {
      $text_list = $rev_list = $blame_dict = array();
    }
    $file_content
      ->setBlameDict($blame_dict)
      ->setRevList($rev_list)
      ->setTextList($text_list);
    return $file_content->toDictionary();
  }
}
