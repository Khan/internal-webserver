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

final class PhabricatorUnitsTestCase extends PhabricatorTestCase {

  // NOTE: Keep tests below PHP_INT_MAX on 32-bit systems, since if you write
  // larger numeric literals they'll evaluate to nonsense.

  public function testByteFormatting() {
    $tests = array(
      1               => '1 B',
      1000            => '1 KB',
      1000000         => '1 MB',
      10000000        => '10 MB',
      100000000       => '100 MB',
      1000000000      => '1 GB',
      999             => '999 B',
    );

    foreach ($tests as $input => $expect) {
      $this->assertEqual(
        $expect,
        phabricator_format_bytes($input),
        'phabricator_format_bytes('.$input.')');
    }
  }

  public function testByteParsing() {
    $tests = array(
      '1'             => 1,
      '1k'            => 1000,
      '1K'            => 1000,
      '1kB'           => 1000,
      '1Kb'           => 1000,
      '1KB'           => 1000,
      '1MB'           => 1000000,
      '1GB'           => 1000000000,
      '1.5M'          => 1500000,
      '1 000'         => 1000,
      '1,234.56 KB'   => 1234560,
    );

    foreach ($tests as $input => $expect) {
      $this->assertEqual(
        $expect,
        phabricator_parse_bytes($input),
        'phabricator_parse_bytes('.$input.')');
    }
  }

  public function testDetailedDurationFormatting() {
    $expected_zero = 'now';

    $tests = array (
       12095939 => '19 w, 6 d',
      -12095939 => '19 w, 6 d ago',

        3380521 => '5 w, 4 d',
       -3380521 => '5 w, 4 d ago',

              0 => $expected_zero,
    );

    foreach ($tests as $duration => $expect) {
      $this->assertEqual(
        $expect,
        phabricator_format_relative_time_detailed($duration),
        'phabricator_format_relative_time_detailed('.$duration.')');
    }


    $tests = array(
      3380521   => array(
        -1 => '5 w',
         0 => '5 w',
         1 => '5 w',
         2 => '5 w, 4 d',
         3 => '5 w, 4 d, 3 h',
         4 => '5 w, 4 d, 3 h, 2 m',
         5 => '5 w, 4 d, 3 h, 2 m, 1 s',
         6 => '5 w, 4 d, 3 h, 2 m, 1 s',
      ),

      -3380521  => array(
        -1 => '5 w ago',
         0 => '5 w ago',
         1 => '5 w ago',
         2 => '5 w, 4 d ago',
         3 => '5 w, 4 d, 3 h ago',
         4 => '5 w, 4 d, 3 h, 2 m ago',
         5 => '5 w, 4 d, 3 h, 2 m, 1 s ago',
         6 => '5 w, 4 d, 3 h, 2 m, 1 s ago',
      ),

      0        => array(
        -1 => $expected_zero,
         0 => $expected_zero,
         1 => $expected_zero,
         2 => $expected_zero,
         3 => $expected_zero,
         4 => $expected_zero,
         5 => $expected_zero,
         6 => $expected_zero,
      ),
    );

    foreach ($tests as $duration => $sub_tests) {
      if (is_array($sub_tests)) {
        foreach ($sub_tests as $levels => $expect) {
          $this->assertEqual(
            $expect,
            phabricator_format_relative_time_detailed($duration, $levels),
            'phabricator_format_relative_time_detailed('.$duration.',
              '.$levels.')');
        }
      } else {
        $expect = $sub_tests;
        $this->assertEqual(
          $expect,
          phabricator_format_relative_time_detailed($duration),
          'phabricator_format_relative_time_detailed('.$duration.')');

      }
    }
  }
}
