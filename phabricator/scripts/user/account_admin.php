#!/usr/bin/env php
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

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

phutil_require_module('phutil', 'console');
phutil_require_module('phutil', 'future/exec');

echo "Enter a username to create a new account or edit an existing account.";

$username = phutil_console_prompt("Enter a username:");
if (!strlen($username)) {
  echo "Cancelled.\n";
  exit(1);
}

if (!PhabricatorUser::validateUsername($username)) {
  echo "The username '{$username}' is invalid. Usernames must consist of only ".
       "numbers and letters.\n";
  exit(1);
}


$user = id(new PhabricatorUser())->loadOneWhere(
  'username = %s',
  $username);

if (!$user) {
  $original = new PhabricatorUser();

  echo "There is no existing user account '{$username}'.\n";
  $ok = phutil_console_confirm(
    "Do you want to create a new '{$username}' account?",
    $default_no = false);
  if (!$ok) {
    echo "Cancelled.\n";
    exit(1);
  }
  $user = new PhabricatorUser();
  $user->setUsername($username);

  $is_new = true;
} else {
  $original = clone $user;

  echo "There is an existing user account '{$username}'.\n";
  $ok = phutil_console_confirm(
    "Do you want to edit the existing '{$username}' account?",
    $default_no = false);
  if (!$ok) {
    echo "Cancelled.\n";
    exit(1);
  }

  $is_new = false;
}

$user_realname = $user->getRealName();
if (strlen($user_realname)) {
  $realname_prompt = ' ['.$user_realname.']';
} else {
  $realname_prompt = '';
}
$realname = nonempty(
  phutil_console_prompt("Enter user real name{$realname_prompt}:"),
  $user_realname);
$user->setRealName($realname);

// When creating a new user we prompt for an email address; when editing an
// existing user we just skip this because it would be quite involved to provide
// a reasonable CLI interface for editing multiple addresses and managing email
// verification and primary addresses.

$new_email = null;
if ($is_new) {
  do {
    $email = phutil_console_prompt("Enter user email address:");
    $duplicate = id(new PhabricatorUserEmail())->loadOneWhere(
      'address = %s',
      $email);
    if ($duplicate) {
      echo "ERROR: There is already a user with that email address. ".
           "Each user must have a unique email address.\n";
    } else {
      break;
    }
  } while (true);

  $new_email = $email;
}

$changed_pass = false;
// This disables local echo, so the user's password is not shown as they type
// it.
phutil_passthru('stty -echo');
$password = phutil_console_prompt(
  "Enter a password for this user [blank to leave unchanged]:");
phutil_passthru('stty echo');
if (strlen($password)) {
  $changed_pass = $password;
}

$is_admin = $user->getIsAdmin();
$set_admin = phutil_console_confirm(
  'Should this user be an administrator?',
  $default_no = !$is_admin);
$user->setIsAdmin($set_admin);

echo "\n\nACCOUNT SUMMARY\n\n";
$tpl = "%12s   %-30s   %-30s\n";
printf($tpl, null, 'OLD VALUE', 'NEW VALUE');
printf($tpl, 'Username', $original->getUsername(), $user->getUsername());
printf($tpl, 'Real Name', $original->getRealName(), $user->getRealName());
if ($new_email) {
  printf($tpl, 'Email', '', $new_email);
}
printf($tpl, 'Password', null,
  ($changed_pass !== false)
    ? 'Updated'
    : 'Unchanged');

printf(
  $tpl,
  'Admin',
  $original->getIsAdmin() ? 'Y' : 'N',
  $user->getIsAdmin() ? 'Y' : 'N');

echo "\n";

if (!phutil_console_confirm("Save these changes?", $default_no = false)) {
  echo "Cancelled.\n";
  exit(1);
}

$user->save();
if ($changed_pass !== false) {
  // This must happen after saving the user because we use their PHID as a
  // component of the password hash.
  $user->setPassword($changed_pass);
  $user->save();
}

if ($new_email) {
  id(new PhabricatorUserEmail())
    ->setUserPHID($user->getPHID())
    ->setAddress($new_email)
    ->setIsVerified(1)
    ->setIsPrimary(1)
    ->save();
}

echo "Saved changes.\n";
