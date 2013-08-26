<?php

final class HeraldTestConsoleController extends HeraldController {

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $request = $this->getRequest();

    $object_name = trim($request->getStr('object_name'));

    $e_name = true;
    $errors = array();
    if ($request->isFormPost()) {
      if (!$object_name) {
        $e_name = pht('Required');
        $errors[] = pht('An object name is required.');
      }

      if (!$errors) {
        $matches = null;
        $object = null;
        if (preg_match('/^D(\d+)$/', $object_name, $matches)) {
          $object = id(new DifferentialRevision())->load($matches[1]);
          if (!$object) {
            $e_name = pht('Invalid');
            $errors[] = pht('No Differential Revision with that ID exists.');
          }
        } else if (preg_match('/^r([A-Z]+)(\w+)$/', $object_name, $matches)) {
          $repo = id(new PhabricatorRepository())->loadOneWhere(
            'callsign = %s',
            $matches[1]);
          if (!$repo) {
            $e_name = pht('Invalid');
            $errors[] = pht('There is no repository with the callsign: %s.',
              $matches[1]);
          }
          $commit = id(new PhabricatorRepositoryCommit())->loadOneWhere(
            'repositoryID = %d AND commitIdentifier = %s',
            $repo->getID(),
            $matches[2]);
          if (!$commit) {
            $e_name = pht('Invalid');
            $errors[] = pht('There is no commit with that identifier.');
          }
          $object = $commit;
        } else {
          $e_name = pht('Invalid');
          $errors[] = pht('This object name is not recognized.');
        }

        if (!$errors) {
          if ($object instanceof DifferentialRevision) {
            $adapter = HeraldDifferentialRevisionAdapter::newLegacyAdapter(
              $object,
              $object->loadActiveDiff());
          } else if ($object instanceof PhabricatorRepositoryCommit) {
            $data = id(new PhabricatorRepositoryCommitData())->loadOneWhere(
              'commitID = %d',
              $object->getID());
            $adapter = HeraldCommitAdapter::newLegacyAdapter(
              $repo,
              $object,
              $data);
          } else {
            throw new Exception("Can not build adapter for object!");
          }

          $rules = id(new HeraldRuleQuery())
            ->setViewer($user)
            ->withContentTypes(array($adapter->getAdapterContentType()))
            ->needConditionsAndActions(true)
            ->needAppliedToPHIDs(array($object->getPHID()))
            ->needValidateAuthors(true)
            ->execute();

          $engine = id(new HeraldEngine())
            ->setDryRun(true);

          $effects = $engine->applyRules($rules, $adapter);
          $engine->applyEffects($effects, $adapter, $rules);

          $xscript = $engine->getTranscript();

          return id(new AphrontRedirectResponse())
            ->setURI('/herald/transcript/'.$xscript->getID().'/');
        }
      }
    }

    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle(pht('Form Errors'));
      $error_view->setErrors($errors);
    } else {
      $error_view = null;
    }

    $text = pht('Enter an object to test rules '.
        'for, like a Diffusion commit (e.g., rX123) or a '.
        'Differential revision (e.g., D123). You will be shown the '.
        'results of a dry run on the object.');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendChild(hsprintf(
        '<p class="aphront-form-instructions">%s</p>', $text))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Object Name'))
          ->setName('object_name')
          ->setError($e_name)
          ->setValue($object_name))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Test Rules')));

    $nav = $this->buildSideNavView();
    $nav->selectFilter('test');
    $nav->appendChild(
      array(
        $error_view,
        $form,
      ));

    $crumbs = id($this->buildApplicationCrumbs())
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Transcripts'))
          ->setHref($this->getApplicationURI('/transcript/')))
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Test Console')));
    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Test Console'),
        'device' => true,
      ));
  }

}
