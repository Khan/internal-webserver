<?php

/**
 * @group phriction
 */
final class PhrictionNewController extends PhrictionController {

  public function processRequest() {

    $request = $this->getRequest();
    $user    = $request->getUser();
    $slug    = PhabricatorSlug::normalize($request->getStr('slug'));

    if ($request->isFormPost()) {
      $document = id(new PhrictionDocument())->loadOneWhere(
        'slug = %s',
        $slug);
      $prompt = $request->getStr('prompt', 'no');
      $document_exists = $document && $document->getStatus() ==
        PhrictionDocumentStatus::STATUS_EXISTS;

      if ($document_exists && $prompt == 'no') {
        $dialog = new AphrontDialogView();
        $dialog->setSubmitURI('/phriction/new/')
          ->setTitle(pht('Edit Existing Document?'))
          ->setUser($user)
          ->appendChild(pht(
            'The document %s already exists. Do you want to edit it instead?',
            phutil_tag('tt', array(), $slug)))
          ->addHiddenInput('slug', $slug)
          ->addHiddenInput('prompt', 'yes')
          ->addCancelButton('/w/')
          ->addSubmitButton(pht('Edit Document'));

        return id(new AphrontDialogResponse())->setDialog($dialog);
      } else if (PhrictionDocument::isProjectSlug($slug)) {
        $project = id(new PhabricatorProjectQuery())
          ->setViewer($user)
          ->withPhrictionSlugs(array(
            PhrictionDocument::getProjectSlugIdentifier($slug)))
          ->executeOne();
        if (!$project) {
          $dialog = new AphrontDialogView();
          $dialog->setSubmitURI('/w/')
              ->setTitle(pht('Oops!'))
              ->setUser($user)
              ->appendChild(pht(
                  'You cannot create wiki pages under "projects/",
                  because they are reserved as project pages.
                  Create a new project with this name first.'))
              ->addCancelButton('/w/', 'Okay');
          return id(new AphrontDialogResponse())->setDialog($dialog);
        }
      }

      $uri  = '/phriction/edit/?slug='.$slug;
      return id(new AphrontRedirectResponse())
        ->setURI($uri);
    }

    if ($slug == '/') {
      $slug = '';
    }

    $view = id(new PHUIFormLayoutView())
      ->appendChild(id(new AphrontFormTextControl())
                       ->setLabel('/w/')
                       ->setValue($slug)
                       ->setName('slug'));

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->setTitle(pht('New Document'))
      ->setSubmitURI('/phriction/new/')
      ->appendChild(phutil_tag('p',
        array(),
        pht('Create a new document at')))
      ->appendChild($view)
      ->addSubmitButton(pht('Create'))
      ->addCancelButton('/w/');

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
