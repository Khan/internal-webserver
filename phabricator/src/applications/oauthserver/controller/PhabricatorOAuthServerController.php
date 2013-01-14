<?php

abstract class PhabricatorOAuthServerController
extends PhabricatorController {

  public function buildStandardPageResponse($view, array $data) {
    $user = $this->getRequest()->getUser();
    $page = $this->buildStandardPageView();
    $page->setApplicationName('OAuth Server');
    $page->setBaseURI('/oauthserver/');
    $page->setTitle(idx($data, 'title'));

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI('/oauthserver/'));
    $nav->addLabel('Client Authorizations');
    $nav->addFilter('clientauthorization',
                    'My Authorizations');
    $nav->addLabel('Clients');
    $nav->addFilter('client/create',
                    'Create Client');
    foreach ($this->getExtraClientFilters() as $filter) {
      $nav->addFilter($filter['url'],
                      $filter['label']);
    }
    $nav->addFilter('client',
                    'My Clients');
    $nav->selectFilter($this->getFilter(),
                       'clientauthorization');

    $nav->appendChild($view);

    $page->appendChild($nav);

    $response = new AphrontWebpageResponse();
    return $response->setContent($page->render());
  }

  protected function getFilter() {
    return 'clientauthorization';
  }

  protected function getExtraClientFilters() {
    return array();
  }

  protected function getHighlightPHIDs() {
    $phids   = array();
    $request = $this->getRequest();
    $edited  = $request->getStr('edited');
    $new     = $request->getStr('new');
    if ($edited) {
      $phids[$edited] = $edited;
    }
    if ($new) {
      $phids[$new] = $new;
    }
    return $phids;
  }

  protected function buildErrorView($error_message) {
    $error = new AphrontErrorView();
    $error->setSeverity(AphrontErrorView::SEVERITY_ERROR);
    $error->setTitle($error_message);

    return $error;
  }
}
