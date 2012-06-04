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

/*
* @group oauthserver
*/
final class PhabricatorOAuthResponse extends AphrontResponse {

  private $state;
  private $content;
  private $clientURI;
  private $error;
  private $errorDescription;

  private function getState() {
    return $this->state;
  }
  public function setState($state) {
    $this->state = $state;
    return $this;
  }

  private function getContent() {
    return $this->content;
  }
  public function setContent($content) {
    $this->content = $content;
    return $this;
  }

  private function getClientURI() {
    return $this->clientURI;
  }
  public function setClientURI(PhutilURI $uri) {
    $this->setHTTPResponseCode(302);
    $this->clientURI = $uri;
    return $this;
  }
  private function getFullURI() {
    $base_uri     = $this->getClientURI();
    $query_params = $this->buildResponseDict();
    foreach ($query_params as $key => $value) {
      $base_uri->setQueryParam($key, $value);
    }
    return $base_uri;
  }

  private function getError() {
    return $this->error;
  }
  public function setError($error) {
    // errors sometimes redirect to the client (302) but otherwise
    // the spec says all code 400
    if (!$this->getClientURI()) {
      $this->setHTTPResponseCode(400);
    }
    $this->error = $error;
    return $this;
  }

  private function getErrorDescription() {
    return $this->errorDescription;
  }
  public function setErrorDescription($error_description) {
    $this->errorDescription = $error_description;
    return $this;
  }

  public function __construct() {
    $this->setHTTPResponseCode(200);      // assume the best
    return $this;
  }

  public function getHeaders() {
    $headers = array(
      array('Content-Type', 'application/json'),
    );
    if ($this->getClientURI()) {
      $headers[] = array('Location', $this->getFullURI());
    }
    // TODO -- T844 set headers with X-Auth-Scopes, etc
    $headers = array_merge(parent::getHeaders(), $headers);
    return $headers;
  }

  private function buildResponseDict() {
    if ($this->getError()) {
      $content = array(
        'error'             => $this->getError(),
        'error_description' => $this->getErrorDescription(),
      );
      $this->setContent($content);
    }

    $content = $this->getContent();
    if (!$content) {
      return '';
    }
    if ($this->getState()) {
      $content['state'] = $this->getState();
    }
    return $content;
  }

  public function buildResponseString() {
    return $this->encodeJSONForHTTPResponse($this->buildResponseDict());
  }
}
