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
 * Socket-based HTTP future, for making HTTP requests using future semantics.
 * This is an alternative to :CURLFuture which has better resolution behavior
 * (select()-based wait instead of busy wait) but fewer features. You should
 * prefer this class to :CURLFuture unless you need its advanced features (like
 * HTTP/1.1, chunked transfer encoding, gzip, etc.).
 *
 * Example Usage
 *
 *    $future = new HTTPFuture('http://www.example.com/');
 *    list($http_response_code, $response_body, $headers) = $future->resolve();
 *
 * @group futures
 */
final class HTTPFuture extends BaseHTTPFuture {

  private $host;
  private $port = 80;
  private $fullRequestPath;

  private $socket;
  private $writeBuffer;
  private $response;

  private $stateConnected     = false;
  private $stateWriteComplete = false;
  private $stateReady         = false;
  private $stateStartTime;

  private $profilerCallID;

  public function setURI($uri) {
    $parts = parse_url($uri);
    if (!$parts) {
      throw new Exception("Could not parse URI '{$uri}'.");
    }

    if (empty($parts['scheme']) || $parts['scheme'] !== 'http') {
      throw new Exception(
        "URI '{$uri}' must be fully qualified with 'http://' scheme.");
    }

    if (!isset($parts['host'])) {
      throw new Exception(
        "URI '{$uri}' must be fully qualified and include host name.");
    }

    $this->host = $parts['host'];

    if (!empty($parts['port'])) {
      $this->port = $parts['port'];
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new Exception(
        "HTTP Basic Auth is not supported by HTTPFuture.");
    }

    if (isset($parts['path'])) {
      $this->fullRequestPath = $parts['path'];
    } else {
      $this->fullRequestPath = '/';
    }

    if (isset($parts['query'])) {
      $this->fullRequestPath .= '?'.$parts['query'];
    }

    return parent::setURI($uri);
  }

  public function __destruct() {
    if ($this->socket) {
      @fclose($this->socket);
      $this->socket = null;
    }
  }

  public function getReadSockets() {
    if ($this->socket) {
      return array($this->socket);
    }
    return array();
  }

  public function getWriteSockets() {
    if (strlen($this->writeBuffer)) {
      return array($this->socket);
    }
    return array();
  }

  public function isWriteComplete() {
    return $this->stateWriteComplete;
  }

  private function getDefaultUserAgent() {
    return 'HTTPFuture/1.0';
  }

  public function isReady() {
    if ($this->stateReady) {
      return true;
    }

    if (!$this->socket) {
      $this->stateStartTime = microtime(true);
      $this->socket = $this->buildSocket();
      if (!$this->socket) {
        return $this->stateReady;
      }

      $profiler = PhutilServiceProfiler::getInstance();
      $this->profilerCallID = $profiler->beginServiceCall(
        array(
          'type' => 'http',
          'uri' => $this->getURI(),
        ));
    }

    if (!$this->stateConnected) {
      $read   = array();
      $write  = array($this->socket);
      $except = array();
      $select = stream_select($read, $write, $except, $tv_sec = 0);
      if ($write) {
        $this->stateConnected = true;
      }
    }

    if ($this->stateConnected) {
      if (strlen($this->writeBuffer)) {
        $bytes = @fwrite($this->socket, $this->writeBuffer);
        if ($bytes === false) {
          throw new Exception("Failed to write to buffer.");
        } else if ($bytes) {
          $this->writeBuffer = substr($this->writeBuffer, $bytes);
        }
      }

      if (!strlen($this->writeBuffer)) {
        $this->stateWriteComplete = true;
      }

      while (($data = fread($this->socket, 32768)) || strlen($data)) {
        $this->response .= $data;
      }

      if ($data === false) {
        throw new Exception("Failed to read socket.");
      }
    }

    return $this->checkSocket();
  }

  private function buildSocket() {

    $errno = null;
    $errstr = null;
    $socket = @stream_socket_client(
      'tcp://'.$this->host.':'.$this->port,
      $errno,
      $errstr,
      $ignored_connection_timeout = 1.0,
      STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

    if (!$socket) {
      $this->stateReady = true;
      $this->result = $this->buildErrorResult(
        HTTPFutureResponseStatusTransport::ERROR_CONNECTION_FAILED);
      return null;
    }

    $ok = stream_set_blocking($socket, 0);
    if (!$ok) {
      throw new Exception("Failed to set stream nonblocking.");
    }

    $this->writeBuffer = $this->buildHTTPRequest();

    return $socket;
  }

  private function checkSocket() {

    $timeout = false;
    $now = microtime(true);
    if (($now - $this->stateStartTime) > $this->getTimeout()) {
      $timeout = true;
    }

    if (!feof($this->socket) && !$timeout) {
      return false;
    }

    $this->stateReady = true;

    if ($timeout) {
      $this->result = $this->buildErrorResult(
        HTTPFutureResponseStatusTransport::ERROR_TIMEOUT);
    } else if (!$this->stateConnected) {
      $this->result = $this->buildErrorResult(
        HTTPFutureResponseStatusTransport::ERROR_CONNECTION_REFUSED);
    } else if (!$this->stateWriteComplete) {
      $this->result = $this->buildErrorResult(
        HTTPFutureResponseStatusTransport::ERROR_CONNECTION_FAILED);
    } else {
      $this->result = $this->parseRawHTTPResponse($this->response);
    }

    $profiler = PhutilServiceProfiler::getInstance();
    $profiler->endServiceCall($this->profilerCallID, array());

    return true;
  }

  private function buildErrorResult($error) {
    return array(
      $status = new HTTPFutureResponseStatusTransport($error, $this->getURI()),
      $body = null,
      $headers = array());
  }

  private function buildHTTPRequest() {
    $data = $this->getData();
    $method = $this->getMethod();
    $uri = $this->fullRequestPath;

    $add_headers = array();

    if ($this->getMethod() == 'GET') {
      if (is_array($data)) {
        $data = http_build_query($data, '', '&');
        if (strpos($uri, '?') !== false) {
          $uri .= '&'.$data;
        } else {
          $uri .= '?'.$data;
        }
        $data = '';
      }
    } else {
      if (is_array($data)) {
        $data = http_build_query($data, '', '&')."\r\n";
        $add_headers[] = array(
          'Content-Type',
          'application/x-www-form-urlencoded');
      }
    }

    $length = strlen($data);

    $add_headers[] = array(
      'Content-Length',
      $length);

    if (!$this->getHeaders('User-Agent')) {
      $add_headers[] = array(
        'User-Agent',
        $this->getDefaultUserAgent());
    }

    if (!$this->getHeaders('Host')) {
      $add_headers[] = array(
        'Host',
        $this->host);
    }

    $headers = array_merge($this->getHeaders(), $add_headers);
    foreach ($headers as $key => $header) {
      list($name, $value) = $header;
      if (strlen($value)) {
        $value = ': '.$value;
      }
      $headers[$key] = $name.$value."\r\n";
    }

    return
      "{$method} {$uri} HTTP/1.0\r\n".
      implode('', $headers).
      "\r\n".
      $data;
  }

}
