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

/**
 * Query symbol information (class and function names and location), returning
 * a list of matching @{class:PhabricatorRepositorySymbol} objects and possibly
 * attached data.
 *
 * @task config   Configuring the Query
 * @task exec     Executing the Query
 * @task internal Internals
 *
 * @group diffusion
 */
final class DiffusionSymbolQuery {

  private $namePrefix;
  private $name;

  private $projectIDs;
  private $language;
  private $type;

  private $limit = 20;

  private $needPaths;
  private $needArcanistProject;
  private $needRepositories;


/* -(  Configuring the Query  )---------------------------------------------- */


  /**
   * @task config
   */
  public function setName($name) {
    $this->name = $name;
    return $this;
  }


  /**
   * @task config
   */
  public function setNamePrefix($name_prefix) {
    $this->namePrefix = $name_prefix;
    return $this;
  }


  /**
   * @task config
   */
  public function setProjectIDs(array $project_ids) {
    $this->projectIDs = $project_ids;
    return $this;
  }


  /**
   * @task config
   */
  public function setLanguage($language) {
    $this->language = $language;
    return $this;
  }


  /**
   * @task config
   */
  public function setType($type) {
    $this->type = $type;
    return $this;
  }


  /**
   * @task config
   */
  public function setLimit($limit) {
    $this->limit = $limit;
    return $this;
  }


  /**
   * @task config
   */
  public function needPaths($need_paths) {
    $this->needPaths = $need_paths;
    return $this;
  }


  /**
   * @task config
   */
  public function needArcanistProjects($need_arcanist_projects) {
    $this->needArcanistProjects = $need_arcanist_projects;
    return $this;
  }


  /**
   * @task config
   */
  public function needRepositories($need_repositories) {
    $this->needRepositories = $need_repositories;
    return $this;
  }


/* -(  Executing the Query  )------------------------------------------------ */


  /**
   * @task exec
   */
  public function execute() {
    if ($this->name && $this->namePrefix) {
      throw new Exception(
        "You can not set both a name and a name prefix!");
    } else if (!$this->name && !$this->namePrefix) {
      throw new Exception(
        "You must set a name or a name prefix!");
    }

    $symbol = new PhabricatorRepositorySymbol();
    $conn_r = $symbol->establishConnection('r');

    $where = array();
    if ($this->name) {
      $where[] = qsprintf(
        $conn_r,
        'symbolName = %s',
        $this->name);
    }

    if ($this->namePrefix) {
      $where[] = qsprintf(
        $conn_r,
        'symbolName LIKE %>',
        $this->namePrefix);
    }

    if ($this->projectIDs) {
      $where[] = qsprintf(
        $conn_r,
        'arcanistProjectID IN (%Ld)',
        $this->projectIDs);
    }

    $where = 'WHERE ('.implode(') AND (', $where).')';

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T %Q',
      $symbol->getTableName(),
      $where);

    // Our ability to match up symbol types and languages probably isn't all
    // that great, so use them as hints for ranking rather than hard
    // requirements. TODO: Is this really the right choice?
    foreach ($data as $key => $row) {
      $score = 0;
      if ($this->language && $row['symbolLanguage'] == $this->language) {
        $score += 2;
      }
      if ($this->type && $row['symbolType'] == $this->type) {
        $score += 1;
      }
      $data[$key]['score'] = $score;
      $data[$key]['id'] = $key;
    }

    $data = isort($data, 'score');
    $data = array_reverse($data);

    $data = array_slice($data, 0, $this->limit);

    $symbols = $symbol->loadAllFromArray($data);

    if ($symbols) {
      if ($this->needPaths) {
        $this->loadPaths($symbols);
      }
      if ($this->needArcanistProjects || $this->needRepositories) {
        $this->loadArcanistProjects($symbols);
      }
      if ($this->needRepositories) {
        $this->loadRepositories($symbols);
      }

    }

    return $symbols;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * @task internal
   */
  private function loadPaths(array $symbols) {
    assert_instances_of($symbols, 'PhabricatorRepositorySymbol');
    $path_map = queryfx_all(
      id(new PhabricatorRepository())->establishConnection('r'),
      'SELECT * FROM %T WHERE id IN (%Ld)',
      PhabricatorRepository::TABLE_PATH,
      mpull($symbols, 'getPathID'));
    $path_map = ipull($path_map, 'path', 'id');
    foreach ($symbols as $symbol) {
      $symbol->attachPath(idx($path_map, $symbol->getPathID()));
    }
  }


  /**
   * @task internal
   */
  private function loadArcanistProjects(array $symbols) {
    assert_instances_of($symbols, 'PhabricatorRepositorySymbol');
    $projects = id(new PhabricatorRepositoryArcanistProject())->loadAllWhere(
      'id IN (%Ld)',
      mpull($symbols, 'getArcanistProjectID'));
    foreach ($symbols as $symbol) {
      $project = idx($projects, $symbol->getArcanistProjectID());
      $symbol->attachArcanistProject($project);
    }
  }


  /**
   * @task internal
   */
  private function loadRepositories(array $symbols) {
    assert_instances_of($symbols, 'PhabricatorRepositorySymbol');

    $projects = mpull($symbols, 'getArcanistProject');
    $projects = array_filter($projects);

    $repo_ids = mpull($projects, 'getRepositoryID');
    $repo_ids = array_filter($repo_ids);

    if ($repo_ids) {
      $repos = id(new PhabricatorRepository())->loadAllWhere(
        'id IN (%Ld)',
        $repo_ids);
    } else {
      $repos = array();
    }

    foreach ($symbols as $symbol) {
      $proj = $symbol->getArcanistProject();
      if ($proj) {
        $symbol->attachRepository(idx($repos, $proj->getRepositoryID()));
      } else {
        $symbol->attachRepository(null);
      }
    }
  }


}
