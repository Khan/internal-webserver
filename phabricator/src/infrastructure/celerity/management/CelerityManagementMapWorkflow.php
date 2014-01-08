<?php

final class CelerityManagementMapWorkflow
  extends CelerityManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('map')
      ->setExamples('**map** [options]')
      ->setSynopsis(pht('Rebuild static resource maps.'))
      ->setArguments(
        array());
  }

  public function execute(PhutilArgumentParser $args) {
    $resources_map = CelerityPhysicalResources::getAll();

    $this->log(
      pht(
        "Rebuilding %d resource source(s).",
        new PhutilNumber(count($resources_map))));

    foreach ($resources_map as $name => $resources) {
      $this->rebuildResources($resources);
    }

    $this->log(pht("Done."));

    return 0;
  }

  /**
   * Rebuild the resource map for a resource source.
   *
   * @param CelerityPhysicalResources Resource source to rebuild.
   * @return void
   */
  private function rebuildResources(CelerityPhysicalResources $resources) {
    $this->log(
      pht(
        'Rebuilding resource source "%s" (%s)...',
        $resources->getName(),
        get_class($resources)));

    $binary_map = $this->rebuildBinaryResources($resources);

    $this->log(
      pht(
        'Found %d binary resources.',
        new PhutilNumber(count($binary_map))));

    $xformer = id(new CelerityResourceTransformer())
      ->setMinify(false)
      ->setRawURIMap(ipull($binary_map, 'uri'));

    $text_map = $this->rebuildTextResources($resources, $xformer);

    $this->log(
      pht(
        'Found %d text resources.',
        new PhutilNumber(count($text_map))));

    $resource_graph = array();
    $requires_map = array();
    $symbol_map = array();
    foreach ($text_map as $name => $info) {
      if (isset($info['provides'])) {
        $symbol_map[$info['provides']] = $info['hash'];

        // We only need to check for cycles and add this to the requires map
        // if it actually requires anything.
        if (!empty($info['requires'])) {
          $resource_graph[$info['provides']] = $info['requires'];
          $requires_map[$info['hash']] = $info['requires'];
        }
      }
    }

    $this->detectGraphCycles($resource_graph);
    $name_map = ipull($binary_map, 'hash') + ipull($text_map, 'hash');
    $hash_map = array_flip($name_map);

    $package_map = $this->rebuildPackages(
      $resources,
      $symbol_map,
      $hash_map);

    $this->log(
      pht(
        'Found %d packages.',
        new PhutilNumber(count($package_map))));

    $component_map = array();
    foreach ($package_map as $package_name => $package_info) {
      foreach ($package_info['symbols'] as $symbol) {
        $component_map[$symbol] = $package_name;
      }
    }

    $name_map = $this->mergeNameMaps(
      array(
        array(pht('Binary'), ipull($binary_map, 'hash')),
        array(pht('Text'), ipull($text_map, 'hash')),
        array(pht('Package'), ipull($package_map, 'hash')),
      ));
    $package_map = ipull($package_map, 'symbols');

    ksort($name_map);
    ksort($symbol_map);
    ksort($requires_map);
    ksort($package_map);

    $map_content = $this->formatMapContent(array(
      'names' => $name_map,
      'symbols' => $symbol_map,
      'requires' => $requires_map,
      'packages' => $package_map,
    ));

    $map_path = $resources->getPathToMap();
    $this->log(pht('Writing map "%s".', Filesystem::readablePath($map_path)));
    Filesystem::writeFile($map_path, $map_content);
  }


  /**
   * Find binary resources (like PNG and SWF) and return information about
   * them.
   *
   * @param CelerityPhysicalResources Resource map to find binary resources for.
   * @return map<string, map<string, string>> Resource information map.
   */
  private function rebuildBinaryResources(
    CelerityPhysicalResources $resources) {

    $binary_map = $resources->findBinaryResources();

    $result_map = array();
    foreach ($binary_map as $name => $data_hash) {
      $hash = $resources->getCelerityHash($data_hash.$name);

      $result_map[$name] = array(
        'hash' => $hash,
        'uri' => $resources->getResourceURI($hash, $name),
      );
    }

    return $result_map;
  }


  /**
   * Find text resources (like JS and CSS) and return information about them.
   *
   * @param CelerityPhysicalResources Resource map to find text resources for.
   * @param CelerityResourceTransformer Configured resource transformer.
   * @return map<string, map<string, string>> Resource information map.
   */
  private function rebuildTextResources(
    CelerityPhysicalResources $resources,
    CelerityResourceTransformer $xformer) {

    $text_map = $resources->findTextResources();

    $result_map = array();
    foreach ($text_map as $name => $data_hash) {
      $raw_data = $resources->getResourceData($name);
      $xformed_data = $xformer->transformResource($name, $raw_data);

      $data_hash = $resources->getCelerityHash($xformed_data);
      $hash = $resources->getCelerityHash($data_hash.$name);

      list($provides, $requires) = $this->getProvidesAndRequires(
        $name,
        $raw_data);

      $result_map[$name] = array(
        'hash' => $hash,
      );

      if ($provides !== null) {
        $result_map[$name] += array(
          'provides' => $provides,
          'requires' => $requires,
        );
      }
    }

    return $result_map;
  }


  /**
   * Parse the `@provides` and `@requires` symbols out of a text resource, like
   * JS or CSS.
   *
   * @param string Resource name.
   * @param string Resource data.
   * @return pair<string|null, list<string>|nul> The `@provides` symbol and the
   *    list of `@requires` symbols. If the resource is not part of the
   *    dependency graph, both are null.
   */
  private function getProvidesAndRequires($name, $data) {
    $parser = new PhutilDocblockParser();

    $matches = array();
    $ok = preg_match('@/[*][*].*?[*]/@s', $data, $matches);
    if (!$ok) {
      throw new Exception(
        pht(
          'Resource "%s" does not have a header doc comment. Encode '.
          'dependency data in a header docblock.',
          $name));
    }

    list($description, $metadata) = $parser->parse($matches[0]);

    $provides = preg_split('/\s+/', trim(idx($metadata, 'provides')));
    $requires = preg_split('/\s+/', trim(idx($metadata, 'requires')));
    $provides = array_filter($provides);
    $requires = array_filter($requires);

    if (!$provides) {
      // Tests and documentation-only JS is permitted to @provide no targets.
      return array(null, null);
    }

    if (count($provides) > 1) {
      throw new Exception(
        pht(
          'Resource "%s" must @provide at most one Celerity target.',
          $name));
    }

    return array(head($provides), $requires);
  }


  /**
   * Check for dependency cycles in the resource graph. Raises an exception if
   * a cycle is detected.
   *
   * @param map<string, list<string>> Map of `@provides` symbols to their
   *                                  `@requires` symbols.
   * @return void
   */
  private function detectGraphCycles(array $nodes) {
    $graph = id(new CelerityResourceGraph())
      ->addNodes($nodes)
      ->setResourceGraph($nodes)
      ->loadGraph();

    foreach ($nodes as $provides => $requires) {
      $cycle = $graph->detectCycles($provides);
      if ($cycle) {
        throw new Exception(
          pht(
            'Cycle detected in resource graph: %s',
            implode(' > ', $cycle)));
      }
    }
  }

  /**
   * Build package specifications for a given resource source.
   *
   * @param CelerityPhysicalResources Resource source to rebuild.
   * @param list<string, string> Map of `@provides` to hashes.
   * @param list<string, string> Map of hashes to resource names.
   * @return map<string, map<string, string>> Package information maps.
   */
  private function rebuildPackages(
    CelerityPhysicalResources $resources,
    array $symbol_map,
    array $reverse_map) {

    $package_map = array();

    $package_spec = $resources->getResourcePackages();
    foreach ($package_spec as $package_name => $package_symbols) {
      $type = null;
      $hashes = array();
      foreach ($package_symbols as $symbol) {
        $symbol_hash = idx($symbol_map, $symbol);
        if ($symbol_hash === null) {
          throw new Exception(
            pht(
              'Package specification for "%s" includes "%s", but that symbol '.
              'is not @provided by any resource.',
              $package_name,
              $symbol));
        }

        $resource_name = $reverse_map[$symbol_hash];
        $resource_type = $resources->getResourceType($resource_name);
        if ($type === null) {
          $type = $resource_type;
        } else if ($type !== $resource_type) {
          throw new Exception(
            pht(
              'Package specification for "%s" includes resources of multiple '.
              'types (%s, %s). Each package may only contain one type of '.
              'resource.',
              $package_name,
              $type,
              $resource_type));
        }

        $hashes[] = $symbol.':'.$symbol_hash;
      }

      $hash = $resources->getCelerityHash(implode("\n", $hashes));
      $package_map[$package_name] = array(
        'hash' => $hash,
        'symbols' => $package_symbols,
      );
    }

    return $package_map;
  }

  private function mergeNameMaps(array $maps) {
    $result = array();
    $origin = array();
    foreach ($maps as $map) {
      list($map_name, $data) = $map;
      foreach ($data as $name => $hash) {
        if (empty($result[$name])) {
          $result[$name] = $hash;
          $origin[$name] = $map_name;
        } else {
          $old = $origin[$name];
          $new = $map_name;
          throw new Exception(
            pht(
              'Resource source defines two resources with the same name, '.
              '"%s". One is defined in the "%s" map; the other in the "%s" '.
              'map. Each resource must have a unique name.',
              $name,
              $old,
              $new));
        }
      }
    }
    return $result;
  }

  private function log($message) {
    $console = PhutilConsole::getConsole();
    $console->writeErr("%s\n", $message);
  }

  private function formatMapContent(array $data) {
    $content = var_export($data, true);
    $content = preg_replace('/\s+$/m', '', $content);
    $content = preg_replace('/array \(/', 'array(', $content);

    $generated = '@'.'generated';
    return <<<EOFILE
<?php

/**
 * This file is automatically generated. Use 'bin/celerity map' to rebuild it.
 * {$generated}
 */
return {$content};

EOFILE;
  }


}
