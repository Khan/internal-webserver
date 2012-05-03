<?php

return array(

  // Uncomment this for initial setup, then recomment.
#setup  'phabricator.setup' => true,

  // This will be the base domain for your install, and must be configured.
  // Use "https://" if you have SSL. See below for some notes.
  'phabricator.base-uri' => 'http://phabricator.khanacademy.org/',

  // Connection information for MySQL.
  'mysql.host' => 'localhost',
  'mysql.user' => 'phabricator',
  'mysql.pass' => 'codereview',

  // Basic email domain configuration.
  'metamta.default-address' => 'noreply@phabricator.khanacademy.org',
  'metamta.domain'          => 'phabricator.khanacademy.org',

  // This apparently avoids some cookie-based attacks.
  'security.alternate-file-domain'  => 'phabricator-files.khanacademy.org',

  // Timezone for khan academy.
  'phabricator.timezone'    => 'America/Los_Angeles',

  // NOTE: Check default.conf.php for detailed explanations of all the
  // configuration options, including these.

) + phabricator_read_config_file('production');
