<?php

/**
 * @group conduit
 */
final class ConduitFuture extends FutureProxy {

  protected $client;
  protected $conduitMethod;
  private $profilerCallID;

  public function setClient(ConduitClient $client, $method) {
    $this->client = $client;
    $this->conduitMethod = $method;
    return $this;
  }

  public function beginProfile($data) {
    $profiler = PhutilServiceProfiler::getInstance();
    $this->profilerCallID = $profiler->beginServiceCall(
      array(
        'type'    => 'conduit',
        'method'  => $this->conduitMethod,
        'size'    => strlen(http_build_query($data, '', '&')),
      ));
    return $this;
  }

  protected function didReceiveResult($result) {
    if ($this->profilerCallID !== null) {
      $profiler = PhutilServiceProfiler::getInstance();
      $profiler->endServiceCall(
        $this->profilerCallID,
        array());
    }

    list($status, $body, $headers) = $result;
    if ($status->isError()) {
      throw $status;
    }

    $raw = $body;

    $shield = 'for(;;);';
    if (!strncmp($raw, $shield, strlen($shield))) {
      $raw = substr($raw, strlen($shield));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
      throw new Exception(
        "Host returned HTTP/200, but invalid JSON data in response to ".
        "a Conduit method call:\n{$raw}");
    }

    if ($data['error_code']) {
      throw new ConduitClientException(
        $data['error_code'],
        $data['error_info']);
    }

    $result = $data['result'];

    $result = $this->client->didReceiveResponse(
      $this->conduitMethod,
      $result);

    return $result;
  }

}
