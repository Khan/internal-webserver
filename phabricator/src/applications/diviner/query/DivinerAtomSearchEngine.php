<?php

final class DivinerAtomSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter(
      'types',
      $this->readListFromRequest($request, 'types'));

    $saved->setParameter('name', $request->getStr('name'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new DivinerAtomQuery());

    $types = $saved->getParameter('types');
    if ($types) {
      $query->withTypes($types);
    }

    $name = $saved->getParameter('name');
    if ($name) {
      $query->withNameContains($name);
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved) {

    $all_types = array();
    foreach (DivinerAtom::getAllTypes() as $type) {
      $all_types[$type] = DivinerAtom::getAtomTypeNameString($type);
    }
    asort($all_types);

    $types = $saved->getParameter('types', array());
    $types = array_fuse($types);
    $type_control = id(new AphrontFormCheckboxControl())
      ->setLabel(pht('Types'));
    foreach ($all_types as $type => $name) {
      $type_control->addCheckbox(
        'types[]',
        $type,
        $name,
        isset($types[$type]));
    }

    $form
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Name Contains'))
          ->setName('name')
          ->setValue($saved->getParameter('name')))
      ->appendChild($type_control);

  }

  protected function getURI($path) {
    return '/diviner/'.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array(
      'all' => pht('All'),
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
