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

/**
 * @group aphront
 */
final class AphrontAjaxResponse extends AphrontResponse {

  private $content;
  private $error;

  public function setContent($content) {
    $this->content = $content;
    return $this;
  }

  public function setError($error) {
    $this->error = $error;
    return $this;
  }

  public function buildResponseString() {
    $response = CelerityAPI::getStaticResourceResponse();
    $object = $response->buildAjaxResponse(
      $this->content,
      $this->error);

    $response_json = $this->encodeJSONForHTTPResponse($object);
    return $this->addJSONShield($response_json, $use_javelin_shield = true);
  }

  public function getHeaders() {
    $headers = array(
      array('Content-Type', 'text/plain; charset=UTF-8'),
    );
    $headers = array_merge(parent::getHeaders(), $headers);
    return $headers;
  }

}
