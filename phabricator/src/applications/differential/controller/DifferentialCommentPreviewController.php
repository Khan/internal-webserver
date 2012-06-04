<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class DifferentialCommentPreviewController
  extends DifferentialController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {

    $request = $this->getRequest();

    $author_phid = $request->getUser()->getPHID();

    $action = $request->getStr('action');

    $engine = PhabricatorMarkupEngine::newDifferentialMarkupEngine();

    $comment = new DifferentialComment();
    $comment->setContent($request->getStr('content'));
    $comment->setAction($action);
    $comment->setAuthorPHID($author_phid);

    $handles = array($author_phid);

    $reviewers = $request->getStr('reviewers');
    if (($action == DifferentialAction::ACTION_ADDREVIEWERS
        || $action == DifferentialAction::ACTION_REQUEST) && $reviewers) {
      $reviewers = explode(',', $reviewers);
      $comment->setMetadata(array(
        DifferentialComment::METADATA_ADDED_REVIEWERS => $reviewers));
      $handles = array_merge($handles, $reviewers);
    }

    $ccs = $request->getStr('ccs');
    if ($action == DifferentialAction::ACTION_ADDCCS && $ccs) {
      $ccs = explode(',', $ccs);
      $comment->setMetadata(array(
        DifferentialComment::METADATA_ADDED_CCS => $ccs));
      $handles = array_merge($handles, $ccs);
    }

    $handles = id(new PhabricatorObjectHandleData($handles))
      ->loadHandles();

    $view = new DifferentialRevisionCommentView();
    $view->setUser($request->getUser());
    $view->setComment($comment);
    $view->setHandles($handles);
    $view->setMarkupEngine($engine);
    $view->setPreview(true);
    $view->setTargetDiff(null);

    $draft = new PhabricatorDraft();
    $draft
      ->setAuthorPHID($author_phid)
      ->setDraftKey('differential-comment-'.$this->id)
      ->setDraft($comment->getContent())
      ->replace();

    return id(new AphrontAjaxResponse())
      ->setContent($view->render());
  }

}
