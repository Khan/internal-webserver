<?php

/**
 * Run a conduit method in-process, without requiring HTTP requests. Usage:
 *
 *   $call = new ConduitCall('method.name', array('param' => 'value'));
 *   $call->setUser($user);
 *   $result = $call->execute();
 *
 */
final class ConduitCall {

  private $method;
  private $request;
  private $user;

  public function __construct($method, array $params) {
    $this->method = $method;
    $this->handler = $this->buildMethodHandler($method);

    $invalid_params = array_diff_key(
      $params,
      $this->handler->defineParamTypes());
    if ($invalid_params) {
      throw new ConduitException(
        "Method '{$method}' doesn't define these parameters: '" .
        implode("', '", array_keys($invalid_params)) . "'.");
    }

    $this->request = new ConduitAPIRequest($params);
  }

  public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }

  public function getUser() {
    return $this->user;
  }

  public function shouldRequireAuthentication() {
    return $this->handler->shouldRequireAuthentication();
  }

  public function shouldAllowUnguardedWrites() {
    return $this->handler->shouldAllowUnguardedWrites();
  }

  public function getRequiredScope() {
    return $this->handler->getRequiredScope();
  }

  public function getErrorDescription($code) {
    return $this->handler->getErrorDescription($code);
  }

  public function execute() {
    if (!$this->getUser()) {
      if ($this->shouldRequireAuthentication()) {
        throw new ConduitException("ERR-INVALID-AUTH");
      }
    } else {
      $this->request->setUser($this->getUser());
    }

    return $this->handler->executeMethod($this->request);
  }

  protected function buildMethodHandler($method) {
    $method_class = ConduitAPIMethod::getClassNameFromAPIMethodName($method);

    // Test if the method exists.
    $ok = false;
    try {
      $ok = class_exists($method_class);
    } catch (Exception $ex) {
      // Discard, we provide a more specific exception below.
    }
    if (!$ok) {
      throw new ConduitException(
        "Conduit method '{$method}' does not exist.");
    }

    $class_info = new ReflectionClass($method_class);
    if ($class_info->isAbstract()) {
      throw new ConduitException(
        "Method '{$method}' is not valid; the implementation is an abstract ".
        "base class.");
    }

    $method = newv($method_class, array());

    if (!($method instanceof ConduitAPIMethod)) {
      throw new ConduitException(
        "Method '{$method_class}' is not valid; the implementation must be ".
        "a subclass of ConduitAPIMethod.");
    }

    $application = $method->getApplication();
    if ($application && !$application->isInstalled()) {
      $app_name = $application->getName();
      throw new ConduitException(
        "Method '{$method_class}' belongs to application '{$app_name}', ".
        "which is not installed.");
    }

    return $method;
  }


}
