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

final class DrydockSSHCommandInterface extends DrydockCommandInterface {

  public function getExecFuture($command) {
    $argv = func_get_args();
    $full_command = call_user_func_array('csprintf', $argv);

    // NOTE: The "-t -t" is for psuedo-tty allocation so we can "sudo" on some
    // systems, but maybe more trouble than it's worth?

    return new ExecFuture(
      'ssh -t -t -o StrictHostKeyChecking=no -i %s %s@%s -- %s',
      $this->getConfig('ssh-keyfile'),
      $this->getConfig('user'),
      $this->getConfig('host'),
      $full_command);
  }

}
