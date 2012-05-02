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

final class DifferentialInlineCommentEditController
  extends PhabricatorInlineCommentController {

  private $revisionID;

  public function willProcessRequest(array $data) {
    $this->revisionID = $data['id'];
  }

  protected function createComment() {

    // Verify revision and changeset correspond to actual objects.
    $revision_id = $this->revisionID;
    $changeset_id = $this->getChangesetID();

    if (!id(new DifferentialRevision())->load($revision_id)) {
      throw new Exception("Invalid revision ID!");
    }

    if (!id(new DifferentialChangeset())->load($changeset_id)) {
      throw new Exception("Invalid changeset ID!");
    }

    return id(new DifferentialInlineComment())
      ->setRevisionID($revision_id)
      ->setChangesetID($changeset_id);
  }

  protected function loadComment($id) {
    return id(new DifferentialInlineComment())->load($id);
  }

  protected function loadCommentForEdit($id) {
    $request = $this->getRequest();
    $user = $request->getUser();

    $inline = $this->loadComment($id);
    if (!$this->canEditInlineComment($user, $inline)) {
      throw new Exception("That comment is not editable!");
    }
    return $inline;
  }

  private function canEditInlineComment(
    PhabricatorUser $user,
    DifferentialInlineComment $inline) {

    // Only the author may edit a comment.
    if ($inline->getAuthorPHID() != $user->getPHID()) {
      return false;
    }

    // Saved comments may not be edited.
    if ($inline->getCommentID()) {
      return false;
    }

    // Inline must be attached to the active revision.
    if ($inline->getRevisionID() != $this->revisionID) {
      return false;
    }

    return true;
  }

}
