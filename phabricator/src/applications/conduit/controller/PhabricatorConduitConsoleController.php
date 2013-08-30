<?php

/**
 * @group conduit
 */
final class PhabricatorConduitConsoleController
  extends PhabricatorConduitController {

  private $method;

  public function willProcessRequest(array $data) {
    $this->method = $data['method'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $viewer = $request->getUser();

    $method = id(new PhabricatorConduitMethodQuery())
      ->setViewer($viewer)
      ->withMethods(array($this->method))
      ->executeOne();

    if (!$method) {
      return new Aphront404Response();
    }

    $status = $method->getMethodStatus();
    $reason = $method->getMethodStatusDescription();

    $status_view = null;
    if ($status != ConduitAPIMethod::METHOD_STATUS_STABLE) {
      $status_view = new AphrontErrorView();
      switch ($status) {
        case ConduitAPIMethod::METHOD_STATUS_DEPRECATED:
          $status_view->setTitle('Deprecated Method');
          $status_view->appendChild(
            nonempty($reason, "This method is deprecated."));
          break;
        case ConduitAPIMethod::METHOD_STATUS_UNSTABLE:
          $status_view->setSeverity(AphrontErrorView::SEVERITY_WARNING);
          $status_view->setTitle('Unstable Method');
          $status_view->appendChild(
            nonempty(
              $reason,
              "This method is new and unstable. Its interface is subject ".
              "to change."));
          break;
      }
    }

    $error_types = $method->defineErrorTypes();
    if ($error_types) {
      $error_description = array();
      foreach ($error_types as $error => $meaning) {
        $error_description[] = hsprintf(
          '<li><strong>%s:</strong> %s</li>',
          $error,
          $meaning);
      }
      $error_description = phutil_tag('ul', array(), $error_description);
    } else {
      $error_description = "This method does not raise any specific errors.";
    }

    $form = new AphrontFormView();
    $form
      ->setUser($request->getUser())
      ->setAction('/api/'.$this->method)
      ->addHiddenInput('allowEmptyParams', 1)
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel('Description')
          ->setValue($method->getMethodDescription()))
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel('Returns')
          ->setValue($method->defineReturnType()))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel('Errors')
          ->setValue($error_description))
      ->appendChild(hsprintf(
        '<p class="aphront-form-instructions">Enter parameters using '.
        '<strong>JSON</strong>. For instance, to enter a list, type: '.
        '<tt>["apple", "banana", "cherry"]</tt>'));

    $params = $method->defineParamTypes();
    foreach ($params as $param => $desc) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel($param)
          ->setName("params[{$param}]")
          ->setCaption($desc));
    }

    $form
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel('Output Format')
          ->setName('output')
          ->setOptions(
            array(
              'human' => 'Human Readable',
              'json'  => 'JSON',
            )))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($this->getApplicationURI())
          ->setValue('Call Method'));

    $form_box = id(new PHUIFormBoxView())
      ->setHeaderText($method->getAPIMethodName())
      ->setFormError($status_view)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($method->getAPIMethodName()));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
      ),
      array(
        'title' => $method->getAPIMethodName(),
        'device' => true,
      ));
  }

}
