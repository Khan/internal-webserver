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
 * TODO: Is this concrete-extensible?
 */
class DrydockRemoteHostBlueprint extends DrydockBlueprint {

  public function getType() {
    return 'host';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {

    switch ($type) {
      case 'command':
        $ssh = new DrydockSSHCommandInterface();
        $ssh->setConfiguration(
          array(
            'host'        => 'secure.phabricator.com',
            'user'        => 'ec2-user',
            'ssh-keyfile' => '/Users/epriestley/.ssh/id_ec2w',
          ));
        return $ssh;
    }

    throw new Exception("No interface of type '{$type}'.");
  }

}
