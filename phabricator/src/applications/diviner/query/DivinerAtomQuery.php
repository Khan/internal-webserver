<?php

final class DivinerAtomQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $bookPHIDs;
  private $names;
  private $types;
  private $contexts;
  private $indexes;
  private $includeUndocumentable;
  private $includeGhosts;
  private $nodeHashes;
  private $titles;
  private $nameContains;

  private $needAtoms;
  private $needExtends;
  private $needChildren;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withBookPHIDs(array $phids) {
    $this->bookPHIDs = $phids;
    return $this;
  }

  public function withTypes(array $types) {
    $this->types = $types;
    return $this;
  }

  public function withNames(array $names) {
    $this->names = $names;
    return $this;
  }

  public function withContexts(array $contexts) {
    $this->contexts = $contexts;
    return $this;
  }

  public function withIndexes(array $indexes) {
    $this->indexes = $indexes;
    return $this;
  }

  public function withNodeHashes(array $hashes) {
    $this->nodeHashes = $hashes;
    return $this;
  }

  public function withTitles($titles) {
    $this->titles = $titles;
    return $this;
  }

  public function withNameContains($text) {
    $this->nameContains = $text;
    return $this;
  }

  public function needAtoms($need) {
    $this->needAtoms = $need;
    return $this;
  }

  public function needChildren($need) {
    $this->needChildren = $need;
    return $this;
  }


  /**
   * Include "ghosts", which are symbols which used to exist but do not exist
   * currently (for example, a function which existed in an older version of
   * the codebase but was deleted).
   *
   * These symbols had PHIDs assigned to them, and may have other sorts of
   * metadata that we don't want to lose (like comments or flags), so we don't
   * delete them outright. They might also come back in the future: the change
   * which deleted the symbol might be reverted, or the documentation might
   * have been generated incorrectly by accident. In these cases, we can
   * restore the original data.
   *
   * However, most callers are not interested in these symbols, so they are
   * excluded by default. You can use this method to include them in results.
   *
   * @param bool  True to include ghosts.
   * @return this
   */
  public function withIncludeGhosts($include) {
    $this->includeGhosts = $include;
    return $this;
  }


  public function needExtends($need) {
    $this->needExtends = $need;
    return $this;
  }

  public function withIncludeUndocumentable($include) {
    $this->includeUndocumentable = $include;
    return $this;
  }

  protected function loadPage() {
    $table = new DivinerLiveSymbol();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $table->loadAllFromArray($data);
  }

  protected function willFilterPage(array $atoms) {
    $books = array_unique(mpull($atoms, 'getBookPHID'));

    $books = id(new DivinerBookQuery())
      ->setViewer($this->getViewer())
      ->withPHIDs($books)
      ->execute();
    $books = mpull($books, null, 'getPHID');

    foreach ($atoms as $key => $atom) {
      $book = idx($books, $atom->getBookPHID());
      if (!$book) {
        unset($atoms[$key]);
        continue;
      }
      $atom->attachBook($book);
    }

    if ($this->needAtoms) {
      $atom_data = id(new DivinerLiveAtom())->loadAllWhere(
        'symbolPHID IN (%Ls)',
        mpull($atoms, 'getPHID'));
      $atom_data = mpull($atom_data, null, 'getSymbolPHID');

      foreach ($atoms as $key => $atom) {
        $data = idx($atom_data, $atom->getPHID());
        if (!$data) {
          unset($atoms[$key]);
          continue;
        }
        $atom->attachAtom($data);
      }
    }

    // Load all of the symbols this symbol extends, recursively. Commonly,
    // this means all the ancestor classes and interfaces it extends and
    // implements.

    if ($this->needExtends) {

      // First, load all the matching symbols by name. This does 99% of the
      // work in most cases, assuming things are named at all reasonably.

      $names = array();
      foreach ($atoms as $atom) {
        foreach ($atom->getAtom()->getExtends() as $xref) {
          $names[] = $xref->getName();
        }
      }

      if ($names) {
        $xatoms = id(new DivinerAtomQuery())
          ->setViewer($this->getViewer())
          ->withNames($names)
          ->needExtends(true)
          ->needAtoms(true)
          ->needChildren($this->needChildren)
          ->execute();
        $xatoms = mgroup($xatoms, 'getName', 'getType', 'getBookPHID');
      } else {
        $xatoms = array();
      }

      foreach ($atoms as $atom) {
        $alang = $atom->getAtom()->getLanguage();
        $extends = array();
        foreach ($atom->getAtom()->getExtends() as $xref) {

          // If there are no symbols of the matching name and type, we can't
          // resolve this.
          if (empty($xatoms[$xref->getName()][$xref->getType()])) {
            continue;
          }

          // If we found matches in the same documentation book, prefer them
          // over other matches. Otherwise, look at all the the matches.
          $matches = $xatoms[$xref->getName()][$xref->getType()];
          if (isset($matches[$atom->getBookPHID()])) {
            $maybe = $matches[$atom->getBookPHID()];
          } else {
            $maybe = array_mergev($matches);
          }

          if (!$maybe) {
            continue;
          }

          // Filter out matches in a different language, since, e.g., PHP
          // classes can not implement JS classes.
          $same_lang = array();
          foreach ($maybe as $xatom) {
            if ($xatom->getAtom()->getLanguage() == $alang) {
              $same_lang[] = $xatom;
            }
          }

          if (!$same_lang) {
            continue;
          }

          // If we have duplicates remaining, just pick the first one. There's
          // nothing more we can do to figure out which is the real one.
          $extends[] = head($same_lang);
        }

        $atom->attachExtends($extends);
      }
    }

    if ($this->needChildren) {
      $child_hashes = $this->getAllChildHashes($atoms, $this->needExtends);

      if ($child_hashes) {
        $children = id(new DivinerAtomQuery())
          ->setViewer($this->getViewer())
          ->withIncludeUndocumentable(true)
          ->withNodeHashes($child_hashes)
          ->needAtoms($this->needAtoms)
          ->execute();

        $children = mpull($children, null, 'getNodeHash');
      } else {
        $children = array();
      }

      $this->attachAllChildren($atoms, $children, $this->needExtends);
    }

    return $atoms;
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->bookPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'bookPHID IN (%Ls)',
        $this->bookPHIDs);
    }

    if ($this->types) {
      $where[] = qsprintf(
        $conn_r,
        'type IN (%Ls)',
        $this->types);
    }

    if ($this->names) {
      $where[] = qsprintf(
        $conn_r,
        'name IN (%Ls)',
        $this->names);
    }

    if ($this->titles) {
      $hashes = array();
      foreach ($this->titles as $title) {
        $slug = DivinerAtomRef::normalizeTitleString($title);
        $hash = PhabricatorHash::digestForIndex($slug);
        $hashes[] = $hash;
      }

      $where[] = qsprintf(
        $conn_r,
        'titleSlugHash in (%Ls)',
        $hashes);
    }

    if ($this->contexts) {
      $with_null = false;
      $contexts = $this->contexts;
      foreach ($contexts as $key => $value) {
        if ($value === null) {
          unset($contexts[$key]);
          $with_null = true;
          continue;
        }
      }

      if ($contexts && $with_null) {
        $where[] = qsprintf(
          $conn_r,
          'context IN (%Ls) OR context IS NULL',
          $contexts);
      } else if ($contexts) {
        $where[] = qsprintf(
          $conn_r,
          'context IN (%Ls)',
          $contexts);
      } else if ($with_null) {
        $where[] = qsprintf(
          $conn_r,
          'context IS NULL');
      }
    }

    if ($this->indexes) {
      $where[] = qsprintf(
        $conn_r,
        'atomIndex IN (%Ld)',
        $this->indexes);
    }

    if (!$this->includeUndocumentable) {
      $where[] = qsprintf(
        $conn_r,
        'isDocumentable = 1');
    }

    if (!$this->includeGhosts) {
      $where[] = qsprintf(
        $conn_r,
        'graphHash IS NOT NULL');
    }

    if ($this->nodeHashes) {
      $where[] = qsprintf(
        $conn_r,
        'nodeHash IN (%Ls)',
        $this->nodeHashes);
    }

    if ($this->nameContains) {
      // NOTE: This CONVERT() call makes queries case-insensitive, since the
      // column has binary collation. Eventually, this should move into
      // fulltext.

      $where[] = qsprintf(
        $conn_r,
        'CONVERT(name USING utf8) LIKE %~',
        $this->nameContains);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }


  /**
   * Walk a list of atoms and collect all the node hashes of the atoms'
   * children. When recursing, also walk up the tree and collect children of
   * atoms they extend.
   *
   * @param list<DivinerLiveSymbol> List of symbols to collect child hashes of.
   * @param bool                    True to collect children of extended atoms,
   *                                as well.
   * @return map<string, string>    Hashes of atoms' children.
   */
  private function getAllChildHashes(array $symbols, $recurse_up) {
    assert_instances_of($symbols, 'DivinerLiveSymbol');

    $hashes = array();
    foreach ($symbols as $symbol) {
      foreach ($symbol->getAtom()->getChildHashes() as $hash) {
        $hashes[$hash] = $hash;
      }
      if ($recurse_up) {
        $hashes += $this->getAllChildHashes($symbol->getExtends(), true);
      }
    }

    return $hashes;
  }


  /**
   * Attach child atoms to existing atoms. In recursive mode, also attach child
   * atoms to atoms that these atoms extend.
   *
   * @param list<DivinerLiveSymbol> List of symbols to attach childeren to.
   * @param map<string, DivinerLiveSymbol> Map of symbols, keyed by node hash.
   * @param bool True to attach children to extended atoms, as well.
   * @return void
   */
  private function attachAllChildren(
    array $symbols,
    array $children,
    $recurse_up) {

    assert_instances_of($symbols, 'DivinerLiveSymbol');
    assert_instances_of($children, 'DivinerLiveSymbol');

    foreach ($symbols as $symbol) {
      $symbol_children = array();
      foreach ($symbol->getAtom()->getChildHashes() as $hash) {
        if (isset($children[$hash])) {
          $symbol_children[] = $children[$hash];
        }
      }
      $symbol->attachChildren($symbol_children);
      if ($recurse_up) {
        $this->attachAllChildren($symbol->getExtends(), $children, true);
      }
    }
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationDiviner';
  }

}
