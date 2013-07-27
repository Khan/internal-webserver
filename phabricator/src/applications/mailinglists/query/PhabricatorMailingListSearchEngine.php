<?php

final class PhabricatorMailingListSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new PhabricatorMailingListQuery());

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved_query) {

    // This just makes it clear to the user that the lack of filters is
    // intentional, not a bug.
    $form->appendChild(
      id(new AphrontFormMarkupControl())
        ->setValue(pht('No query filters are available for mailing lists.')));
  }

  protected function getURI($path) {
    return '/mailinglists/'.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array(
      'all' => pht('All Lists'),
    );

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {

    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'all':
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

}
