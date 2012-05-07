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

function __phutil_init_script__() {
  // Adjust the runtime language configuration to be reasonable and inline with
  // expectations. We do this first, then load libraries.

  // There may be some kind of auto-prepend script configured which starts an
  // output buffer. Discard any such output buffers so messages can be sent to
  // stdout (if a user wants to capture output from a script, there are a large
  // number of ways they can accomplish it legitimately; historically, we ran
  // into this on only one install which had some bizarre configuration, but it
  // was difficult to diagnose because the symptom is "no messages of any
  // kind").
  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  error_reporting(E_ALL | E_STRICT);

  $config_map = array(
    // Always display script errors. Without this, they may not appear, which is
    // unhelpful when users encounter a problem. On the web this is a security
    // concern because you don't want to expose errors to clients, but in a
    // script context we always want to show errors.
    'display_errors'              => true,

    // Set the error log to the default, so errors go to stderr. Without this
    // errors may end up in some log, and users may not know where the log is
    // or check it.
    'error_log'                   => null,

    // XDebug raises a fatal error if the call stack gets too deep, but the
    // default setting is 100, which we may exceed legitimately with module
    // includes (and in other cases, like recursive filesystem operations
    // applied to 100+ levels of directory nesting). Stop it from triggering:
    // we explicitly limit recursive algorithms which should be limited.
    'xdebug.max_nesting_level'    => null,
  );

  foreach ($config_map as $config_key => $config_value) {
    ini_set($config_key, $config_value);
  }

  if (!ini_get('date.timezone')) {
    // If the timezone isn't set, PHP issues a warning whenever you try to parse
    // a date (like those from Git or Mercurial logs), even if the date contains
    // timezone information (like "PST" or "-0700") which makes the
    // environmental timezone setting is completely irrelevant. We never rely on
    // the system timezone setting in any capacity, so prevent PHP from flipping
    // out by setting it to a safe default (UTC) if it isn't set to some other
    // value.
    date_default_timezone_set('UTC');
  }

  // Now, load libphutil.

  $root = dirname(dirname(__FILE__));
  require_once $root.'/src/__phutil_library_init__.php';
}

__phutil_init_script__();
