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

final class DifferentialChangesetDetailView extends AphrontView {

  private $changeset;
  private $buttons = array();
  private $editable;
  private $symbolIndex;
  private $id;
  private $vsChangesetID;

  public function setChangeset($changeset) {
    $this->changeset = $changeset;
    return $this;
  }

  public function addButton($button) {
    $this->buttons[] = $button;
    return $this;
  }

  public function setEditable($editable) {
    $this->editable = $editable;
    return $this;
  }

  public function setSymbolIndex($symbol_index) {
    $this->symbolIndex = $symbol_index;
    return $this;
  }

  public function getID() {
    if (!$this->id) {
      $this->id = celerity_generate_unique_node_id();
    }
    return $this->id;
  }

  public function setVsChangesetID($vs_changeset_id) {
    $this->vsChangesetID = $vs_changeset_id;
    return $this;
  }

  public function getVsChangesetID() {
    return $this->vsChangesetID;
  }

  public function render() {
    require_celerity_resource('differential-changeset-view-css');
    require_celerity_resource('syntax-highlighting-css');

    Javelin::initBehavior('phabricator-oncopy', array());

    $changeset = $this->changeset;
    $class = 'differential-changeset';
    if (!$this->editable) {
      $class .= ' differential-changeset-immutable';
    }

    $buttons = null;
    if ($this->buttons) {
      $buttons =
        '<div class="differential-changeset-buttons">'.
          implode('', $this->buttons).
        '</div>';
    }

    $id = $this->getID();

    if ($this->symbolIndex) {
      Javelin::initBehavior(
        'repository-crossreference',
        array(
          'container' => $id,
        ) + $this->symbolIndex);
    }

    $display_filename = $changeset->getDisplayFilename();

    $buoyant_begin = $this->renderBuoyant($display_filename);
    $buoyant_end   = $this->renderBuoyant(null);

    $output = javelin_render_tag(
      'div',
      array(
        'sigil' => 'differential-changeset',
        'meta'  => array(
          'left'  => nonempty(
            $this->getVsChangesetID(),
            $this->changeset->getID()),
          'right' => $this->changeset->getID(),
        ),
        'class' => $class,
        'id'    => $id,
      ),
      $buoyant_begin.
      phutil_render_tag(
        'a',
        array(
          'name' => $changeset->getAnchorName(),
        ),
        '').
      $buttons.
      '<h1>'.phutil_escape_html($display_filename).'</h1>'.
      '<div style="clear: both;"></div>'.
      $this->renderChildren().
      $buoyant_end);


    return $output;
  }

  private function renderBuoyant($text) {
    return javelin_render_tag(
      'div',
      array(
        'sigil' => 'buoyant',
        'meta'  => array(
          'text' => $text,
        ),
        'style' => ($text === null)
          // Current CSS spacing rules cause the "end" anchor to appear too
          // late in the display document. Shift it up a bit so we drop the
          // buoyant header sooner. This reduces confusion when using keystroke
          // navigation.
          ? 'bottom: 60px; position: absolute;'
          : null,
      ),
      '');
  }
}
