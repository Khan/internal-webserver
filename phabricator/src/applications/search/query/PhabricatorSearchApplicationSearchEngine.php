<?php

final class PhabricatorSearchApplicationSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter('query', $request->getStr('query'));
    $saved->setParameter(
      'statuses',
      $this->readListFromRequest($request, 'statuses'));
    $saved->setParameter(
      'types',
      $this->readListFromRequest($request, 'types'));

    $saved->setParameter(
      'authorPHIDs',
      $this->readUsersFromRequest($request, 'authorPHIDs'));

    $saved->setParameter(
      'ownerPHIDs',
      $this->readUsersFromRequest($request, 'ownerPHIDs'));

    $saved->setParameter(
      'withUnowned',
      $this->readBoolFromRequest($request, 'withUnowned'));

    $saved->setParameter(
      'subscriberPHIDs',
      $this->readPHIDsFromRequest($request, 'subscriberPHIDs'));

    $saved->setParameter(
      'projectPHIDs',
      $this->readPHIDsFromRequest($request, 'projectPHIDs'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new PhabricatorSearchDocumentQuery())
      ->withSavedQuery($saved);
    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved) {

    $options = array();
    $author_value = null;
    $owner_value = null;
    $subscribers_value = null;
    $project_value = null;

    $author_phids = $saved->getParameter('authorPHIDs', array());
    $owner_phids = $saved->getParameter('ownerPHIDs', array());
    $subscriber_phids = $saved->getParameter('subscriberPHIDs', array());
    $project_phids = $saved->getParameter('projectPHIDs', array());

    $all_phids = array_merge(
      $author_phids,
      $owner_phids,
      $subscriber_phids,
      $project_phids);

    $all_handles = id(new PhabricatorHandleQuery())
      ->setViewer($this->requireViewer())
      ->withPHIDs($all_phids)
      ->execute();

    $author_handles = array_select_keys($all_handles, $author_phids);
    $owner_handles = array_select_keys($all_handles, $owner_phids);
    $subscriber_handles = array_select_keys($all_handles, $subscriber_phids);
    $project_handles = array_select_keys($all_handles, $project_phids);

    $with_unowned = $saved->getParameter('withUnowned', array());

    $status_values = $saved->getParameter('statuses', array());
    $status_values = array_fuse($status_values);

    $statuses = array(
      PhabricatorSearchRelationship::RELATIONSHIP_OPEN => pht('Open'),
      PhabricatorSearchRelationship::RELATIONSHIP_CLOSED => pht('Closed'),
    );
    $status_control = id(new AphrontFormCheckboxControl())
      ->setLabel(pht('Document Status'));
    foreach ($statuses as $status => $name) {
      $status_control->addCheckbox(
        'statuses[]',
        $status,
        $name,
        isset($status_values[$status]));
    }

    $type_values = $saved->getParameter('types', array());
    $type_values = array_fuse($type_values);

    $types = self::getIndexableDocumentTypes();

    $types_control = id(new AphrontFormCheckboxControl())
      ->setLabel(pht('Document Types'));
    foreach ($types as $type => $name) {
      $types_control->addCheckbox(
        'types[]',
        $type,
        $name,
        isset($type_values[$type]));
    }

    $form
      ->appendChild(
        phutil_tag(
          'input',
          array(
            'type' => 'hidden',
            'name' => 'jump',
            'value' => 'no',
          )))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Query')
          ->setName('query')
          ->setValue($saved->getParameter('query')))
      ->appendChild($status_control)
      ->appendChild($types_control)
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('authorPHIDs')
          ->setLabel('Authors')
          ->setDatasource('/typeahead/common/users/')
          ->setValue($author_handles))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('ownerPHIDs')
          ->setLabel('Owners')
          ->setDatasource('/typeahead/common/searchowner/')
          ->setValue($owner_handles))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'withUnowned',
            1,
            pht('Show only unowned documents.'),
            $with_unowned))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('subscriberPHIDs')
          ->setLabel('Subscribers')
          ->setDatasource('/typeahead/common/users/')
          ->setValue($subscriber_handles))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('projectPHIDs')
          ->setLabel('In Any Project')
          ->setDatasource('/typeahead/common/projects/')
          ->setValue($project_handles));
  }

  protected function getURI($path) {
    return '/search/'.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array(
      'all' => pht('All Documents'),
      'open' => pht('Open Documents'),
      'open-tasks' => pht('Open Tasks'),
    );

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {

    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'all':
        return $query;
      case 'open':
        return $query->setParameter('statuses', array('open'));
      case 'open-tasks':
        return $query
          ->setParameter('statuses', array('open'))
          ->setParameter('types', array(ManiphestPHIDTypeTask::TYPECONST));
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  public static function getIndexableDocumentTypes() {
    // TODO: This is inelegant and not very efficient, but gets us reasonable
    // results. It would be nice to do this more elegantly.

    // TODO: We should hide types associated with applications the user can
    // not access. There's no reasonable way to do this right now.

    $indexers = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhabricatorSearchDocumentIndexer')
      ->loadObjects();

    $types = PhabricatorPHIDType::getAllTypes();

    $results = array();
    foreach ($types as $type) {
      $typeconst = $type->getTypeConstant();
      foreach ($indexers as $indexer) {
        $fake_phid = 'PHID-'.$typeconst.'-fake';
        if ($indexer->shouldIndexDocumentByPHID($fake_phid)) {
          $results[$typeconst] = $type->getTypeName();
        }
      }
    }

    asort($results);

    return $results;
  }


}
