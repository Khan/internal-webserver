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

final class AphrontRequestTestCase extends PhabricatorTestCase {

  public function testRequestDataAccess() {
    $r = new AphrontRequest('http://example.com/', '/');
    $r->setRequestData(
      array(
        'str_empty' => '',
        'str'       => 'derp',
        'str_true'  => 'true',
        'str_false' => 'false',

        'zero'      => '0',
        'one'       => '1',

        'arr_empty' => array(),
        'arr_num'   => array(1, 2, 3),

        'comma'     => ',',
        'comma_1'   => 'a, b',
        'comma_2'   => ' ,a ,, b ,,,, ,, ',
        'comma_3'   => '0',
        'comma_4'   => 'a, a, b, a',
        'comma_5'   => "a\nb, c\n\nd\n\n\n,\n",
    ));

    $this->assertEqual(1, $r->getInt('one'));
    $this->assertEqual(0, $r->getInt('zero'));
    $this->assertEqual(null, $r->getInt('does-not-exist'));
    $this->assertEqual(0, $r->getInt('str_empty'));

    $this->assertEqual(true, $r->getBool('one'));
    $this->assertEqual(false, $r->getBool('zero'));
    $this->assertEqual(true, $r->getBool('str_true'));
    $this->assertEqual(false, $r->getBool('str_false'));
    $this->assertEqual(true, $r->getBool('str'));
    $this->assertEqual(null, $r->getBool('does-not-exist'));
    $this->assertEqual(false, $r->getBool('str_empty'));

    $this->assertEqual('derp', $r->getStr('str'));
    $this->assertEqual('', $r->getStr('str_empty'));
    $this->assertEqual(null, $r->getStr('does-not-exist'));

    $this->assertEqual(array(), $r->getArr('arr_empty'));
    $this->assertEqual(array(1, 2, 3), $r->getArr('arr_num'));
    $this->assertEqual(null, $r->getArr('str_empty', null));
    $this->assertEqual(null, $r->getArr('str_true', null));
    $this->assertEqual(null, $r->getArr('does-not-exist', null));
    $this->assertEqual(array(), $r->getArr('does-not-exist'));

    $this->assertEqual(array(), $r->getStrList('comma'));
    $this->assertEqual(array('a', 'b'), $r->getStrList('comma_1'));
    $this->assertEqual(array('a', 'b'), $r->getStrList('comma_2'));
    $this->assertEqual(array('0'), $r->getStrList('comma_3'));
    $this->assertEqual(array('a', 'a', 'b', 'a'), $r->getStrList('comma_4'));
    $this->assertEqual(array('a', 'b', 'c', 'd'), $r->getStrList('comma_5'));
    $this->assertEqual(array(), $r->getStrList('does-not-exist'));
    $this->assertEqual(null, $r->getStrList('does-not-exist', null));

    $this->assertEqual(true, $r->getExists('str'));
    $this->assertEqual(false, $r->getExists('does-not-exist'));
  }

}
