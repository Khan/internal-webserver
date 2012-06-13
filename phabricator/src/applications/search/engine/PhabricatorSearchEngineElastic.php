<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class PhabricatorSearchEngineElastic extends PhabricatorSearchEngine {
  private $uri;
  private $timeout;

  public function __construct($uri) {
    $this->uri = $uri;
  }

  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  public function getTimeout() {
    return $this->timeout;
  }

  public function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {

    $type = $doc->getDocumentType();
    $phid = $doc->getPHID();
    $handle = PhabricatorObjectHandleData::loadOneHandle($phid);

    // URL is not used internally but it can be useful externally.
    $spec = array(
      'title'         => $doc->getDocumentTitle(),
      'url'           => PhabricatorEnv::getProductionURI($handle->getURI()),
      'dateCreated'   => $doc->getDocumentCreated(),
      '_timestamp'    => $doc->getDocumentModified(),
      'field'         => array(),
      'relationship'  => array(),
    );

    foreach ($doc->getFieldData() as $field) {
      $spec['field'][] = array_combine(array('type', 'corpus', 'aux'), $field);
    }

    foreach ($doc->getRelationshipData() as $relationship) {
      list($rtype, $to_phid, $to_type, $time) = $relationship;
      $spec['relationship'][$rtype][] = array(
        'phid'      => $to_phid,
        'phidType'  => $to_type,
        'when'      => $time,
      );
    }

    $this->executeRequest(
      "/phabricator/{$type}/{$phid}/",
      $spec,
      $is_write = true);
  }

  public function reconstructDocument($phid) {
    $type = phid_get_type($phid);

    $response = $this->executeRequest("/phabricator/{$type}/{$phid}", array());

    if (empty($response['exists'])) {
      return null;
    }

    $hit = $response['_source'];

    $doc = new PhabricatorSearchAbstractDocument();
    $doc->setPHID($phid);
    $doc->setDocumentType($response['_type']);
    $doc->setDocumentTitle($hit['title']);
    $doc->setDocumentCreated($hit['dateCreated']);
    $doc->setDocumentModified($hit['_timestamp']);

    foreach ($hit['field'] as $fdef) {
      $doc->addField($fdef['type'], $fdef['corpus'], $fdef['aux']);
    }

    foreach ($hit['relationship'] as $rtype => $rships) {
      foreach ($rships as $rship) {
        $doc->addRelationship(
          $rtype,
          $rship['phid'],
          $rship['phidType'],
          $rship['when']);
      }
    }

    return $doc;
  }

  public function executeSearch(PhabricatorSearchQuery $query) {

    $spec = array();
    $filter = array();

    if ($query->getQuery()) {
      $spec[] = array(
        'field' => array(
          'field.corpus' => $query->getQuery(),
        ),
      );
    }

    $exclude = $query->getParameter('exclude');
    if ($exclude) {
      $filter[] = array(
        'not' => array(
          'ids' => array(
            'values' => array($exclude),
          ),
        ),
      );
    }

    $type = $query->getParameter('type');
    if ($type) {
      $uri = "/phabricator/{$type}/_search";
    } else {
      // Don't use '/phabricator/_search' for the case that there is something
      // else in the index (for example if 'phabricator' is only an alias to
      // some bigger index).
      $types = PhabricatorSearchAbstractDocument::getSupportedTypes();
      $uri = '/phabricator/' . implode(',', array_keys($types)) . '/_search';
    }

    $rel_mapping = array(
      'author' => PhabricatorSearchRelationship::RELATIONSHIP_AUTHOR,
      'open' => PhabricatorSearchRelationship::RELATIONSHIP_OPEN,
      'owner' => PhabricatorSearchRelationship::RELATIONSHIP_OWNER,
      'project' => PhabricatorSearchRelationship::RELATIONSHIP_PROJECT,
      'repository' => PhabricatorSearchRelationship::RELATIONSHIP_REPOSITORY,
    );
    foreach ($rel_mapping as $name => $field) {
      $param = $query->getParameter($name);
      if (is_array($param)) {
        $should = array();
        foreach ($param as $val) {
          $should[] = array(
            'text' => array(
              "relationship.{$field}.phid" => array(
                'query' => $val,
                'type' => 'phrase',
              ),
            ),
          );
        }
        // We couldn't solve it by minimum_number_should_match because it can
        // match multiple owners without matching author.
        $spec[] = array('bool' => array('should' => $should));
      } else if ($param) {
        $filter[] = array(
          'exists' => array(
            'field' => "relationship.{$field}.phid",
          ),
        );
      }
    }

    if ($spec) {
      $spec = array('query' => array('bool' => array('must' => $spec)));
    }

    if ($filter) {
      $filter = array('filter' => array('and' => $filter));
      if ($spec) {
        $spec = array(
          'query' => array(
            'filtered' => $spec + $filter,
          ),
        );
      } else {
        $spec = $filter;
      }
    }

    $spec['from'] = (int)$query->getParameter('offset', 0);
    $spec['size'] = (int)$query->getParameter('limit', 25);
    $response = $this->executeRequest($uri, $spec);

    $phids = ipull($response['hits']['hits'], '_id');
    return $phids;
  }

  private function executeRequest($path, array $data, $is_write = false) {
    $uri = new PhutilURI($this->uri);
    $data = json_encode($data);

    $uri->setPath($path);

    $protocol = $uri->getProtocol();
    if ($protocol == 'https') {
      $future = new HTTPSFuture($uri, $data);
    } else {
      $future = new HTTPFuture($uri, $data);
    }

    if ($is_write) {
      $future->setMethod('PUT');
    } else {
      $future->setMethod('GET');
    }

    if ($this->getTimeout()) {
      $future->setTimeout($this->getTimeout());
    }

    list($body) = $future->resolvex();

    if ($is_write) {
      return null;
    }

    $body = json_decode($body, true);
    if (!is_array($body)) {
      throw new Exception("elasticsearch server returned invalid JSON!");
    }

    return $body;
  }

}
