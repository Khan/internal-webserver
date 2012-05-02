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

final class PhabricatorRepositoryDeleteController
  extends PhabricatorRepositoryController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {

    $repository = id(new PhabricatorRepository())->load($this->id);
    if (!$repository) {
      return new Aphront404Response();
    }

    $request = $this->getRequest();

    if ($request->isDialogFormPost()) {
      $repository->delete();
      return id(new AphrontRedirectResponse())->setURI('/repository/');
    }

    $dialog = new AphrontDialogView();
    $dialog
      ->setUser($request->getUser())
      ->setTitle('Really delete repository?')
      ->appendChild(
        '<p>Really delete the "'.phutil_escape_html($repository->getName()).
        '" ('.phutil_escape_html($repository->getCallsign()).') repository? '.
        'This operation can not be undone.</p>')
      ->setSubmitURI('/repository/delete/'.$this->id.'/')
      ->addSubmitButton('Delete Repository')
      ->addCancelButton('/repository/');

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }
}
