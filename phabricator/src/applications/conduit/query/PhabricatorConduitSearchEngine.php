<?php

final class PhabricatorConduitSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function getPageSize(PhabricatorSavedQuery $saved) {
    return PHP_INT_MAX - 1;
  }

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter('isStable', $request->getStr('isStable'));
    $saved->setParameter('isUnstable', $request->getStr('isUnstable'));
    $saved->setParameter('isDeprecated', $request->getStr('isDeprecated'));

    $saved->setParameter(
      'applicationNames',
      $request->getStrList('applicationNames'));

    $saved->setParameter('nameContains', $request->getStr('nameContains'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new PhabricatorConduitMethodQuery());

    $query->withIsStable($saved->getParameter('isStable'));
    $query->withIsUnstable($saved->getParameter('isUnstable'));
    $query->withIsDeprecated($saved->getParameter('isDeprecated'));

    $names = $saved->getParameter('applicationNames', array());
    if ($names) {
      $query->withApplicationNames($names);
    }

    $contains = $saved->getParameter('nameContains');
    if (strlen($contains)) {
      $query->withNameContains($contains);
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved) {

    $form
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Name Contains')
          ->setName('nameContains')
          ->setValue($saved->getParameter('nameContains')));

    $names = $saved->getParameter('applicationNames', array());
    $form
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Applications')
          ->setName('applicationNames')
          ->setValue(implode(', ', $names))
          ->setCaption(pht(
            'Example: %s',
            phutil_tag('tt', array(), 'differential, paste'))));

    $is_stable = $saved->getParameter('isStable');
    $is_unstable = $saved->getParameter('isUnstable');
    $is_deprecated = $saved->getParameter('isDeprecated');
    $form
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->setLabel('Stability')
          ->addCheckbox(
            'isStable',
            1,
            hsprintf(
              '<strong>%s</strong>: %s',
              pht('Stable Methods'),
              pht('Show established API methods with stable interfaces.')),
            $is_stable)
          ->addCheckbox(
            'isUnstable',
            1,
            hsprintf(
              '<strong>%s</strong>: %s',
              pht('Unstable Methods'),
              pht('Show new methods which are subject to change.')),
            $is_unstable)
          ->addCheckbox(
            'isDeprecated',
            1,
            hsprintf(
              '<strong>%s</strong>: %s',
              pht('Deprecated Methods'),
              pht(
                'Show old methods which will be deleted in a future '.
                'version of Phabricator.')),
            $is_deprecated));


  }

  protected function getURI($path) {
    return '/conduit/'.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array(
      'modern' => pht('Modern Methods'),
      'all'    => pht('All Methods'),
    );

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {

    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'modern':
        return $query
          ->setParameter('isStable', true)
          ->setParameter('isUnstable', true);
      case 'all':
        return $query
          ->setParameter('isStable', true)
          ->setParameter('isUnstable', true)
          ->setParameter('isDeprecated', true);
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

}
