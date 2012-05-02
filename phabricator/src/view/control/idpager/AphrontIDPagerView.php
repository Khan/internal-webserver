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

final class AphrontIDPagerView extends AphrontView {

  private $afterID;
  private $beforeID;

  private $pageSize = 100;

  private $nextPageID;
  private $prevPageID;

  private $uri;

  final public function setPageSize($page_size) {
    $this->pageSize = max(1, $page_size);
    return $this;
  }

  final public function getPageSize() {
    return $this->pageSize;
  }

  final public function setURI(PhutilURI $uri) {
    $this->uri = $uri;
    return $this;
  }

  final public function readFromRequest(AphrontRequest $request) {
    $this->uri = $request->getRequestURI();
    $this->afterID = $request->getStr('after');
    $this->beforeID = $request->getStr('before');
    return $this;
  }

  final public function setAfterID($after_id) {
    $this->afterID = $after_id;
    return $this;
  }

  final public function getAfterID() {
    return $this->afterID;
  }

  final public function setBeforeID($before_id) {
    $this->beforeID = $before_id;
    return $this;
  }

  final public function getBeforeID() {
    return $this->beforeID;
  }

  final public function setNextPageID($next_page_id) {
    $this->nextPageID = $next_page_id;
    return $this;
  }

  final public function getNextPageID() {
    return $this->nextPageID;
  }

  final public function setPrevPageID($prev_page_id) {
    $this->prevPageID = $prev_page_id;
    return $this;
  }

  final public function getPrevPageID() {
    return $this->prevPageID;
  }

  final public function sliceResults(array $results) {
    if (count($results) > $this->getPageSize()) {
      $offset = ($this->beforeID ? count($results) - $this->getPageSize() : 0);
      $results = array_slice($results, $offset, $this->getPageSize(), true);
    }
    return $results;
  }

  public function render() {
    if (!$this->uri) {
      throw new Exception(
        "You must call setURI() before you can call render().");
    }

    $links = array();

    if ($this->beforeID || $this->afterID) {
      $links[] = phutil_render_tag(
        'a',
        array(
          'href' => $this->uri
            ->alter('before', null)
            ->alter('after', null),
        ),
        "\xC2\xAB First");
    }

    if ($this->prevPageID) {
      $links[] = phutil_render_tag(
        'a',
        array(
          'href' => $this->uri
            ->alter('after', null)
            ->alter('before', $this->prevPageID),
        ),
        "\xE2\x80\xB9 Prev");
    }

    if ($this->nextPageID) {
      $links[] = phutil_render_tag(
        'a',
        array(
          'href' => $this->uri
            ->alter('after', $this->nextPageID)
            ->alter('before', null),
        ),
        "Next \xE2\x80\xBA");
    }

    return
      '<div class="aphront-pager-view">'.
        implode('', $links).
      '</div>';
  }

}
