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
 * @group testcase
 */
final class PhutilArgumentSpecificationTestCase extends ArcanistPhutilTestCase {

  public function testNames() {
    $names = array(
      'x'   => true,
      'xx'  => true,
      '!'   => false,
      'XX'  => false,
      '1='  => false,
      '--'  => false,

      'no-stuff'  => true,
      '-stuff'    => false,
    );

    foreach ($names as $name => $valid) {
      $caught = null;
      try {
        PhutilArgumentSpecification::newQuickSpec(
          array(
            'name' => $name,
          ));
      } catch (PhutilArgumentSpecificationException $ex) {
        $caught = $ex;
      }
      $this->assertEqual(
        !$valid,
        $caught instanceof Exception,
        "Arg name '{$name}'.");
    }
  }

  public function testAliases() {
    $aliases = array(
      'a' => true,
      '1' => true,

      'no' => false,
      '-'  => false,
      '_'  => false,
      ' '  => false,
      ''   => false,
    );

    foreach ($aliases as $alias => $valid) {
      $caught = null;
      try {
        PhutilArgumentSpecification::newQuickSpec(
          array(
            'name'  => 'example',
            'short' => $alias,
          ));
      } catch (PhutilArgumentSpecificationException $ex) {
        $caught = $ex;
      }
      $this->assertEqual(
        !$valid,
        $caught instanceof Exception,
        "Arg alias '{$alias}'.");
    }
  }

  public function testSpecs() {
    $good_specs = array(
      array(
        'name' => 'verbose',
      ),
      array(
        'name' => 'verbose',
        'short' => 'v',
        'help' => 'Derp.',
        'param' => 'level',
        'default' => 'y',
        'conflicts' => array(
          'quiet' => true,
        ),
        'wildcard' => false,
      ),
      array(
        'name' => 'files',
        'wildcard' => true,
      ),
    );

    $bad_specs = array(
      array(
      ),
      array(
        'alias' => 'v',
      ),
      array(
        'name'  => 'derp',
        'fruit' => 'apple',
      ),
      array(
        'name' => 'x',
        'default' => 'y',
      ),
      array(
        'name' => 'x',
        'param' => 'y',
        'default' => 'z',
        'repeat' => true,
      ),
      array(
        'name' => 'x',
        'wildcard' => true,
        'repeat' => true,
      ),
      array(
        'name' => 'x',
        'param' => 'y',
        'wildcard' => true,
      ),

    );

    $cases = array(
      array(true, $good_specs),
      array(false, $bad_specs),
    );

    foreach ($cases as $case) {
      list($expect, $specs) = $case;
      foreach ($specs as $spec) {
        $caught = null;
        try {
          PhutilArgumentSpecification::newQuickSpec($spec);
        } catch (PhutilArgumentSpecificationException $ex) {
          $caught = $ex;
        }
        $this->assertEqual(
          !$expect,
          $caught instanceof Exception,
          "Spec validity for: ".print_r($spec, true));
      }
    }
  }

}
