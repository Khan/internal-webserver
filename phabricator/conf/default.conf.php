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

return array(

  // The root URI which Phabricator is installed on.
  // Example: "http://phabricator.example.com/"
  'phabricator.base-uri'        => null,

  // If you have multiple environments, provide the production environment URI
  // here so that emails, etc., generated in development/sandbox environments
  // contain the right links.
  'phabricator.production-uri'  => null,

  // Setting this to 'true' will invoke a special setup mode which helps guide
  // you through setting up Phabricator.
  'phabricator.setup'           => false,

// -- IMPORTANT! Security! -------------------------------------------------- //

  // IMPORTANT: By default, Phabricator serves files from the same domain the
  // application lives on. This is convenient but not secure: it creates a large
  // class of vulnerabilities which can not be generally mitigated.
  //
  // To avoid this, you should configure a second domain in the same way you
  // have the primary domain configured (e.g., point it at the same machine and
  // set up the same vhost rules) and provide it here. For instance, if your
  // primary install is on "http://www.phabricator-example.com/", you could
  // configure "http://www.phabricator-files.com/" and specify the entire
  // domain (with protocol) here. This will enforce that files are
  // served only from the alternate domain. Ideally, you should use a
  // completely separate domain name rather than just a different subdomain.
  //
  // It is STRONGLY RECOMMENDED that you configure this. Your install is NOT
  // SECURE unless you do so.
  'security.alternate-file-domain'  => null,

  // Default key for HMAC digests where the key is not important (i.e., the
  // hash itself is secret). You can change this if you want (to any other
  // string), but doing so will break existing sessions and CSRF tokens.
  'security.hmac-key' => '[D\t~Y7eNmnQGJ;rnH6aF;m2!vJ8@v8C=Cs:aQS\.Qw',


// -- Customization --------------------------------------------------------- //

  // If you want to use a custom logo (e.g., for your company or organization),
  // copy 'webroot/rsrc/image/custom/example_template.png' to
  // 'webroot/rsrc/image/custom/custom.png' and set this to the URI you want it
  // to link to (like http://www.yourcompany.com/).
  'phabricator.custom.logo'   => null,


// -- Access Policies ------------------------------------------------------- //

  // Phabricator allows you to set the visibility of objects (like repositories
  // and source code) to "Public", which means anyone on the internet can see
  // them, even without being logged in. This is great for open source, but
  // some installs may never want to make anything public, so this policy is
  // disabled by default. You can enable it here, which will let you set the
  // policy for objects to "Public". With this option disabled, the most open
  // policy is "All Users", which means users must be logged in to view things.
  'policy.allow-public'         => false,


// -- Logging --------------------------------------------------------------- //

  // To enable the Phabricator access log, specify a path here. The Phabricator
  // access log can provide more detailed information about Phabricator access
  // than normal HTTP access logs (for instance, it can show logged-in users,
  // controllers, and other application data). If not set, no log will be
  // written.
  //
  // Make sure the PHP process can write to the log!
  'log.access.path'             => null,

  // Format for the access log. If not set, the default format will be used:
  //
  //  "[%D]\t%h\t%u\t%M\t%C\t%m\t%U\t%c\t%T"
  //
  // Available variables are:
  //
  //  - %c The HTTP response code.
  //  - %C The controller which handled the request.
  //  - %D The request date.
  //  - %e Epoch timestamp.
  //  - %h The webserver's host name.
  //  - %p The PID of the server process.
  //  - %R The HTTP referrer.
  //  - %r The remote IP.
  //  - %T The request duration, in microseconds.
  //  - %U The request path.
  //  - %u The logged-in user, if one is logged in.
  //  - %M The HTTP method.
  //  - %m For conduit, the Conduit method which was invoked.
  //
  // If a variable isn't available (for example, %m appears in the file format
  // but the request is not a Conduit request), it will be rendered as "-".
  //
  // Note that the default format is subject to change in the future, so if you
  // rely on the log's format, specify it explicitly.
  'log.access.format'           => null,


// -- DarkConsole ----------------------------------------------------------- //

  // DarkConsole is a administrative debugging/profiling tool built into
  // Phabricator. You can leave it disabled unless you're developing against
  // Phabricator.

  // Determines whether or not DarkConsole is available. DarkConsole exposes
  // some data like queries and stack traces, so you should be careful about
  // turning it on in production (although users can not normally see it, even
  // if the deployment configuration enables it).
  'darkconsole.enabled'         => false,

  // Always enable DarkConsole, even for logged out users. This potentially
  // exposes sensitive information to users, so make sure untrusted users can
  // not access an install running in this mode. You should definitely leave
  // this off in production. It is only really useful for using DarkConsole
  // utilities to debug or profile logged-out pages. You must set
  // 'darkconsole.enabled' to use this option.
  'darkconsole.always-on'       => false,


  // Allows you to mask certain configuration values from appearing in the
  // "Config" tab of DarkConsole.
  'darkconsole.config-mask'     => array(
    'mysql.pass',
    'amazon-ses.secret-key',
    'recaptcha.private-key',
    'phabricator.csrf-key',
    'facebook.application-secret',
    'github.application-secret',
    'google.application-secret',
    'phabricator.application-secret',
    'disqus.application-secret',
    'phabricator.mail-key',
    'security.hmac-key',
  ),


// --  MySQL  --------------------------------------------------------------- //

  // Class providing database configuration. It must implement
  // DatabaseConfigurationProvider.
  'mysql.configuration-provider' => 'DefaultDatabaseConfigurationProvider',

  // The username to use when connecting to MySQL.
  'mysql.user' => 'root',

  // The password to use when connecting to MySQL.
  'mysql.pass' => '',

  // The MySQL server to connect to. If you want to connect to a different
  // port than the default (which is 3306), specify it in the hostname
  // (e.g., db.example.com:1234).
  'mysql.host' => 'localhost',

  // The number of times to try reconnecting to the MySQL database
  'mysql.connection-retries' => 3,

  // Phabricator supports PHP extensions MySQL and MySQLi. It is possible to
  // implement also other access mechanism (e.g. PDO_MySQL). The class must
  // extend AphrontMySQLDatabaseConnectionBase.
  'mysql.implementation' => 'AphrontMySQLDatabaseConnection',

// -- Notifications ----//
  'notification.enabled' => false,

// -- Email ----------------------------------------------------------------- //

  // Some Phabricator tools send email notifications, e.g. when Differential
  // revisions are updated or Maniphest tasks are changed. These options allow
  // you to configure how email is delivered.

  // You can test your mail setup by going to "MetaMTA" in the web interface,
  // clicking "Send New Message", and then composing a message.

  // Default address to send mail "From".
  'metamta.default-address'     => 'noreply@example.com',

  // Domain used to generate Message-IDs.
  'metamta.domain'              => 'example.com',

  // When a message is sent to multiple recipients (for example, several
  // reviewers on a code review), Phabricator can either deliver one email to
  // everyone (e.g., "To: alincoln, usgrant, htaft") or separate emails to each
  // user (e.g., "To: alincoln", "To: usgrant", "To: htaft"). The major
  // advantages and disadvantages of each approach are:
  //
  //   - One mail to everyone:
  //     - Recipients can see To/Cc at a glance.
  //     - If you use mailing lists, you won't get duplicate mail if you're
  //       a normal recipient and also Cc'd on a mailing list.
  //     - Getting threading to work properly is harder, and probably requires
  //       making mail less useful by turning off options.
  //     - Sometimes people will "Reply All" and everyone will get two mails,
  //       one from the user and one from Phabricator turning their mail into
  //       a comment.
  //     - Not supported with a private reply-to address.
  //   - One mail to each user:
  //     - Recipients need to look in the mail body to see To/Cc.
  //     - If you use mailing lists, recipients may sometimes get duplicate
  //       mail.
  //     - Getting threading to work properly is easier, and threading settings
  //       can be customzied by each user.
  //     - "Reply All" no longer spams all other users.
  //     - Required if private reply-to addresses are configured.
  //
  // In the code, splitting one outbound email into one-per-recipient is
  // sometimes referred to as "multiplexing".
  'metamta.one-mail-per-recipient'  => true,

  // When a user takes an action which generates an email notification (like
  // commenting on a Differential revision), Phabricator can either send that
  // mail "From" the user's email address (like "alincoln@logcabin.com") or
  // "From" the 'metamta.default-address' address. The user experience is
  // generally better if Phabricator uses the user's real address as the "From"
  // since the messages are easier to organize when they appear in mail clients,
  // but this will only work if the server is authorized to send email on behalf
  // of the "From" domain. Practically, this means:
  //    - If you are doing an install for Example Corp and all the users will
  //      have corporate @corp.example.com addresses and any hosts Phabricator
  //      is running on are authorized to send email from corp.example.com,
  //      you can enable this to make the user experience a little better.
  //    - If you are doing an install for an open source project and your
  //      users will be registering via Facebook and using personal email
  //      addresses, you MUST NOT enable this or virtually all of your outgoing
  //      email will vanish into SFP blackholes.
  //    - If your install is anything else, you're much safer leaving this
  //      off since the risk in turning it on is that your outgoing mail will
  //      mostly never arrive.
  'metamta.can-send-as-user'    => false,


  // Adapter class to use to transmit mail to the MTA. The default uses
  // PHPMailerLite, which will invoke "sendmail". This is appropriate
  // if sendmail actually works on your host, but if you haven't configured mail
  // it may not be so great. You can also use Amazon SES, by changing this to
  // 'PhabricatorMailImplementationAmazonSESAdapter', signing up for SES, and
  // filling in your 'amazon-ses.access-key' and 'amazon-ses.secret-key' below.
  'metamta.mail-adapter'        =>
    'PhabricatorMailImplementationPHPMailerLiteAdapter',

  // When email is sent, try to hand it off to the MTA immediately. This may
  // be worth disabling if your MTA infrastructure is slow or unreliable. If you
  // disable this option, you must run the 'metamta_mta.php' daemon or mail
  // won't be handed off to the MTA. If you're using Amazon SES it can be a
  // little slugish sometimes so it may be worth disabling this and moving to
  // the daemon after you've got your install up and running. If you have a
  // properly configured local MTA it should not be necessary to disable this.
  'metamta.send-immediately'    => true,

  // If you're using Amazon SES to send email, provide your AWS access key
  // and AWS secret key here. To set up Amazon SES with Phabricator, you need
  // to:
  //  - Make sure 'metamta.mail-adapter' is set to:
  //    "PhabricatorMailImplementationAmazonSESAdapter"
  //  - Make sure 'metamta.can-send-as-user' is false.
  //  - Make sure 'metamta.default-address' is configured to something sensible.
  //  - Make sure 'metamta.default-address' is a validated SES "From" address.
  'amazon-ses.access-key'       =>  null,
  'amazon-ses.secret-key'       =>  null,

  // If you're using Sendgrid to send email, provide your access credentials
  // here. This will use the REST API. You can also use Sendgrid as a normal
  // SMTP service.
  'sendgrid.api-user'           => null,
  'sendgrid.api-key'            => null,

  // You can configure a reply handler domain so that email sent from Maniphest
  // will have a special "Reply To" address like "T123+82+af19f@example.com"
  // that allows recipients to reply by email and interact with tasks. For
  // instructions on configurating reply handlers, see the article
  // "Configuring Inbound Email" in the Phabricator documentation. By default,
  // this is set to 'null' and Phabricator will use a generic 'noreply@' address
  // or the address of the acting user instead of a special reply handler
  // address (see 'metamta.default-address'). If you set a domain here,
  // Phabricator will begin generating private reply handler addresses. See
  // also 'metamta.maniphest.reply-handler' to further configure behavior.
  // This key should be set to the domain part after the @, like "example.com".
  'metamta.maniphest.reply-handler-domain' => null,

  // You can follow the instructions in "Configuring Inbound Email" in the
  // Phabricator documentation and set 'metamta.maniphest.reply-handler-domain'
  // to support updating Maniphest tasks by email. If you want more advanced
  // customization than this provides, you can override the reply handler
  // class with an implementation of your own. This will allow you to do things
  // like have a single public reply handler or change how private reply
  // handlers are generated and validated.
  // This key should be set to a loadable subclass of
  // PhabricatorMailReplyHandler (and possibly of ManiphestReplyHandler).
  'metamta.maniphest.reply-handler' => 'ManiphestReplyHandler',

  // If you don't want phabricator to take up an entire domain
  // (or subdomain for that matter), you can use this and set a common
  // prefix for mail sent by phabricator. It will make use of the fact that
  // a mail-address such as phabricator+D123+1hjk213h@example.com will be
  // delivered to the phabricator users mailbox.
  // Set this to the left part of the email address and it well get
  // prepended to all outgoing mail. If you want to use e.g.
  // 'phabricator@example.com' this should be set to 'phabricator'.
  'metamta.single-reply-handler-prefix' => null,

  // Prefix prepended to mail sent by Maniphest. You can change this to
  // distinguish between testing and development installs, for example.
  'metamta.maniphest.subject-prefix' => '[Maniphest]',

  // See 'metamta.maniphest.reply-handler-domain'. This does the same thing,
  // but allows email replies via Differential.
  'metamta.differential.reply-handler-domain' => null,

  // See 'metamta.maniphest.reply-handler'. This does the same thing, but
  // affects Differential.
  'metamta.differential.reply-handler' => 'DifferentialReplyHandler',

  // Prefix prepended to mail sent by Differential.
  'metamta.differential.subject-prefix' => '[Differential]',

  // Set this to true if you want patches to be attached to mail from
  // Differential. This won't work if you are using SendGrid as your mail
  // adapter.
  'metamta.differential.attach-patches' => false,

  // To include patches in email bodies, set this to a positive integer. Patches
  // will be inlined if they are at most that many lines. For instance, a value
  // of 100 means "inline patches if they are no longer than 100 lines". By
  // default, patches are not inlined.
  'metamta.differential.inline-patches' => 0,

  // If you enable either of the options above, you can choose what format
  // patches are sent in. Valid options are 'unified' (like diff -u) or 'git'.
  'metamta.differential.patch-format'   => 'unified',

  // Prefix prepended to mail sent by Diffusion.
  'metamta.diffusion.subject-prefix' => '[Diffusion]',

  // See 'metamta.maniphest.reply-handler-domain'. This does the same thing,
  // but allows email replies via Diffusion.
  'metamta.diffusion.reply-handler-domain' => null,

  // See 'metamta.maniphest.reply-handler'. This does the same thing, but
  // affects Diffusion.
  'metamta.diffusion.reply-handler' => 'PhabricatorAuditReplyHandler',

  // Prefix prepended to mail sent by Package.
  'metamta.package.subject-prefix' => '[Package]',

  // See 'metamta.maniphest.reply-handler'. This does similar thing for package
  // except that it only supports sending out mail and doesn't handle incoming
  // email.
  'metamta.package.reply-handler' => 'OwnersPackageReplyHandler',

  // By default, Phabricator generates unique reply-to addresses and sends a
  // separate email to each recipient when you enable reply handling. This is
  // more secure than using "From" to establish user identity, but can mean
  // users may receive multiple emails when they are on mailing lists. Instead,
  // you can use a single, non-unique reply to address and authenticate users
  // based on the "From" address by setting this to 'true'. This trades away
  // a little bit of security for convenience, but it's reasonable in many
  // installs. Object interactions are still protected using hashes in the
  // single public email address, so objects can not be replied to blindly.
  'metamta.public-replies' => false,

  // You can configure an email address like "bugs@phabricator.example.com"
  // which will automatically create Maniphest tasks when users send email
  // to it. This relies on the "From" address to authenticate users, so it is
  // is not completely secure. To set this up, enter a complete email
  // address like "bugs@phabricator.example.com" and then configure mail to
  // that address so it routed to Phabricator (if you've already configured
  // reply handlers, you're probably already done). See "Configuring Inbound
  // Email" in the documentation for more information.
  'metamta.maniphest.public-create-email' => null,

  // If you enable 'metamta.public-replies', Phabricator uses "From" to
  // authenticate users. You can additionally enable this setting to try to
  // authenticate with 'Reply-To'. Note that this is completely spoofable and
  // insecure (any user can set any 'Reply-To' address) but depending on the
  // nature of your install or other deliverability conditions this might be
  // okay. Generally, you can't do much more by spoofing Reply-To than be
  // annoying (you can write but not read content). But, you know, this is
  // still **COMPLETELY INSECURE**.
  'metamta.insecure-auth-with-reply-to' => false,

  // If you enable 'metamta.maniphest.public-create-email' and create an
  // email address like "bugs@phabricator.example.com", it will default to
  // rejecting mail which doesn't come from a known user. However, you might
  // want to let anyone send email to this address; to do so, set a default
  // author here (a Phabricator username). A typical use of this might be to
  // create a "System Agent" user called "bugs" and use that name here. If you
  // specify a valid username, mail will always be accepted and used to create
  // a task, even if the sender is not a system user. The original email
  // address will be stored in an 'From Email' field on the task.
  'metamta.maniphest.default-public-author' => null,

  // If this option is enabled, Phabricator will add a "Precedence: bulk"
  // header to transactional mail (e.g., Differential, Maniphest and Herald
  // notifications). This may improve the behavior of some auto-responder
  // software and prevent it from replying. However, it may also cause
  // deliverability issues -- notably, you currently can not send this header
  // via Amazon SES, and enabling this option with SES will prevent delivery
  // of any affected mail.
  'metamta.precedence-bulk' => false,

  // Mail.app on OS X Lion won't respect threading headers unless the subject
  // is prefixed with "Re:". If you enable this option, Phabricator will add
  // "Re:" to the subject line of all mail which is expected to thread. If
  // you've set 'metamta.one-mail-per-recipient', users can override this
  // setting in their preferences.
  'metamta.re-prefix' => false,

  // If true, allow MetaMTA to change mail subjects to put text like
  // '[Accepted]' and '[Commented]' in them. This makes subjects more useful,
  // but might break threading on some clients. If you've set
  // 'metamta.one-mail-per-recipient', users can override this setting in their
  // preferences.
  'metamta.vary-subjects' => true,


// -- Auth ------------------------------------------------------------------ //

  // Can users login with a username/password, or by following the link from
  // a password reset email? You can disable this and configure one or more
  // OAuth providers instead.
  'auth.password-auth-enabled'  => true,

  // Maximum number of simultaneous web sessions each user is permitted to have.
  // Setting this to "1" will prevent a user from logging in on more than one
  // browser at the same time.
  'auth.sessions.web'           => 5,

  // Maximum number of simultaneous Conduit sessions each user is permitted
  // to have.
  'auth.sessions.conduit'       => 5,

  // Set this true to enable the Settings -> SSH Public Keys panel, which will
  // allow users to associated SSH public keys with their accounts. This is only
  // really useful if you're setting up services over SSH and want to use
  // Phabricator for authentication; in most situations you can leave this
  // disabled.
  'auth.sshkeys.enabled'        => false,

  // If true, email addresses must be verified (by clicking a link in an
  // email) before a user can login. By default, verification is optional
  // unless 'auth.email-domains' is nonempty (see below).
  'auth.require-email-verification' => false,

  // You can restrict allowed email addresses to certain domains (like
  // "yourcompany.com") by setting a list of allowed domains here. Users will
  // only be allowed to register using email addresses at one of the domains,
  // and will only be able to add new email addresses for these domains. If
  // you configure this, it implies 'auth.require-email-verification'.
  //
  // To configure email domains, set a list of domains like this:
  //
  //   array(
  //     'yourcompany.com',
  //     'yourcompany.co.uk',
  //   )
  //
  // You should omit the "@" from domains. Note that the domain must match
  // exactly. If you allow "yourcompany.com", that permits "joe@yourcompany.com"
  // but rejects "joe@mail.yourcompany.com".
  'auth.email-domains' => array(),


// -- Accounts -------------------------------------------------------------- //

  // Is basic account information (email, real name, profile picture) editable?
  // If you set up Phabricator to automatically synchronize account information
  // from some other authoritative system, you can disable this to ensure
  // information remains consistent across both systems.
  'account.editable'            => true,

  // When users set or reset a password, it must have at least this many
  // characters.
  'account.minimum-password-length'  => 8,


// -- Facebook OAuth -------------------------------------------------------- //

  // Can users use Facebook credentials to login to Phabricator?
  'facebook.auth-enabled'       => false,

  // Can users use Facebook credentials to create new Phabricator accounts?
  'facebook.registration-enabled' => true,

  // Are Facebook accounts permanently linked to Phabricator accounts, or can
  // the user unlink them?
  'facebook.auth-permanent'     => false,

  // The Facebook "Application ID" to use for Facebook API access.
  'facebook.application-id'     => null,

  // The Facebook "Application Secret" to use for Facebook API access.
  'facebook.application-secret' => null,


// -- GitHub OAuth ---------------------------------------------------------- //

  // Can users use GitHub credentials to login to Phabricator?
  'github.auth-enabled'         => false,

  // Can users use GitHub credentials to create new Phabricator accounts?
  'github.registration-enabled' => true,

  // Are GitHub accounts permanently linked to Phabricator accounts, or can
  // the user unlink them?
  'github.auth-permanent'       => false,

  // The GitHub "Client ID" to use for GitHub API access.
  'github.application-id'       => null,

  // The GitHub "Secret" to use for GitHub API access.
  'github.application-secret'   => null,


// -- Google OAuth ---------------------------------------------------------- //

  // Can users use Google credentials to login to Phabricator?
  'google.auth-enabled'         => false,

  // Can users use Google credentials to create new Phabricator accounts?
  'google.registration-enabled' => true,

  // Are Google accounts permanently linked to Phabricator accounts, or can
  // the user unlink them?
  'google.auth-permanent'       => false,

  // The Google "Client ID" to use for Google API access.
  'google.application-id'       => null,

  // The Google "Client Secret" to use for Google API access.
  'google.application-secret'   => null,

// -- LDAP Auth ----------------------------------------------------- //
  // Enable ldap auth
  'ldap.auth-enabled'         => false,

  // The LDAP server hostname
  'ldap.hostname' => '',

  // The LDAP base domain name
  'ldap.base_dn' => '',

  // The attribute to be regarded as 'username'. Has to be unique
  'ldap.search_attribute' => '',

  // The attribute(s) to be regarded as 'real name'.
  // If more then one attribute is supplied the values of the attributes in
  // the array will be joined
  'ldap.real_name_attributes' => array(),

  // The LDAP version
  'ldap.version' => 3,

// -- Disqus OAuth ---------------------------------------------------------- //

  // Can users use Disqus credentials to login to Phabricator?
  'disqus.auth-enabled'         => false,

  // Can users use Disqus credentials to create new Phabricator accounts?
  'disqus.registration-enabled' => true,

  // Are Disqus accounts permanently linked to Phabricator accounts, or can
  // the user unlink them?
  'disqus.auth-permanent'       => false,

  // The Disqus "Client ID" to use for Disqus API access.
  'disqus.application-id'       => null,

  // The Disqus "Client Secret" to use for Disqus API access.
  'disqus.application-secret'   => null,


// -- Phabricator OAuth ----------------------------------------------------- //

  // Meta-town -- Phabricator is itself an OAuth Provider
  // TODO -- T887 -- make this support multiple Phabricator instances!

  // The URI of the Phabricator instance to use as an OAuth server.
  'phabricator.oauth-uri'            => null,

  // Can users use Phabricator credentials to login to Phabricator?
  'phabricator.auth-enabled'         => false,

  // Can users use Phabricator credentials to create new Phabricator accounts?
  'phabricator.registration-enabled' => true,

  // Are Phabricator accounts permanently linked to Phabricator accounts, or can
  // the user unlink them?
  'phabricator.auth-permanent'       => false,

  // The Phabricator "Client ID" to use for Phabricator API access.
  'phabricator.application-id'       => null,

  // The Phabricator "Client Secret" to use for Phabricator API access.
  'phabricator.application-secret'   => null,

// -- Disqus Comments ------------------------------------------------------- //

  // Should Phame users have Disqus comment widget, and if so what's the
  // website shortname to use? For example, secure.phabricator.org uses
  // "phabricator", which we registered with Disqus. If you aren't familiar
  // with Disqus, see:
  // Disqus quick start guide - http://docs.disqus.com/help/4/
  // Information on shortnames - http://docs.disqus.com/help/68/
  'disqus.shortname'            => null,

// -- Recaptcha ------------------------------------------------------------- //

  // Is Recaptcha enabled? If disabled, captchas will not appear. You should
  // enable Recaptcha if your install is public-facing, as it hinders
  // brute-force attacks.
  'recaptcha.enabled'           => false,

  // Your Recaptcha public key, obtained from Recaptcha.
  'recaptcha.public-key'        => null,

  // Your Recaptcha private key, obtained from Recaptcha.
  'recaptcha.private-key'       => null,


// -- Misc ------------------------------------------------------------------ //

  // This is hashed with other inputs to generate CSRF tokens. If you want, you
  // can change it to some other string which is unique to your install. This
  // will make your install more secure in a vague, mostly theoretical way. But
  // it will take you like 3 seconds of mashing on your keyboard to set it up so
  // you might as well.
  'phabricator.csrf-key'        => '0b7ec0592e0a2829d8b71df2fa269b2c6172eca3',

  // This is hashed with other inputs to generate mail tokens. If you want, you
  // can change it to some other string which is unique to your install. In
  // particular, you will want to do this if you accidentally send a bunch of
  // mail somewhere you shouldn't have, to invalidate all old reply-to
  // addresses.
  'phabricator.mail-key'        => '5ce3e7e8787f6e40dfae861da315a5cdf1018f12',

  // Version string displayed in the footer. You probably should leave this
  // alone.
  'phabricator.version'         => 'UNSTABLE',

  // PHP requires that you set a timezone in your php.ini before using date
  // functions, or it will emit a warning. If this isn't possible (for instance,
  // because you are using HPHP) you can set some valid constant for
  // date_default_timezone_set() here and Phabricator will set it on your
  // behalf, silencing the warning.
  'phabricator.timezone'        => null,

  // When unhandled exceptions occur, stack traces are hidden by default.
  // You can enable traces for development to make it easier to debug problems.
  'phabricator.show-stack-traces' => false,

  // Shows an error callout if a page generated PHP errors, warnings or notices.
  // This makes it harder to miss problems while developing Phabricator.
  'phabricator.show-error-callout' => false,

  // When users write comments which have URIs, they'll be automatically linked
  // if the protocol appears in this set. This whitelist is primarily to prevent
  // security issues like javascript:// URIs.
  'uri.allowed-protocols' => array(
    'http'  => true,
    'https' => true,
  ),

  // Tokenizers are UI controls which let the user select other users, email
  // addresses, project names, etc., by typing the first few letters and having
  // the control autocomplete from a list. They can load their data in two ways:
  // either in a big chunk up front, or as the user types. By default, the data
  // is loaded in a big chunk. This is simpler and performs better for small
  // datasets. However, if you have a very large number of users or projects,
  // (in the ballpark of more than a thousand), loading all that data may become
  // slow enough that it's worthwhile to query on demand instead. This makes
  // the typeahead slightly less responsive but overall performance will be much
  // better if you have a ton of stuff. You can figure out which setting is
  // best for your install by changing this setting and then playing with a
  // user tokenizer (like the user selectors in Maniphest or Differential) and
  // seeing which setting loads faster and feels better.
  'tokenizer.ondemand'          => false,

  // By default, Phabricator includes some silly nonsense in the UI, such as
  // a submit button called "Clowncopterize" in Differential and a call to
  // "Leap Into Action". If you'd prefer more traditional UI strings like
  // "Submit", you can set this flag to disable most of the jokes and easter
  // eggs.
  'phabricator.serious-business' => false,


// -- Files ----------------------------------------------------------------- //

  // Lists which uploaded file types may be viewed in the browser. If a file
  // has a mime type which does not appear in this list, it will always be
  // downloaded instead of displayed. This is mainly a usability
  // consideration, since browsers tend to freak out when viewing enormous
  // binary files.
  //
  // The keys in this array are viewable mime types; the values are the mime
  // types they will be delivered as when they are viewed in the browser.
  //
  // IMPORTANT: Configure 'security.alternate-file-domain' above! Your install
  // is NOT safe if it is left unconfigured.
  'files.viewable-mime-types' => array(
    'image/jpeg'  => 'image/jpeg',
    'image/jpg'   => 'image/jpg',
    'image/png'   => 'image/png',
    'image/gif'   => 'image/gif',
    'text/plain'  => 'text/plain; charset=utf-8',
    'text/x-diff' => 'text/plain; charset=utf-8',

    // ".ico" favicon files, which have mime type diversity. See:
    // http://en.wikipedia.org/wiki/ICO_(file_format)#MIME_type
    'image/x-ico'               => 'image/x-icon',
    'image/x-icon'              => 'image/x-icon',
    'image/vnd.microsoft.icon'  => 'image/x-icon',
  ),

  // List of mime types which can be used as the source for an <img /> tag.
  // This should be a subset of 'files.viewable-mime-types' and exclude files
  // like text.
  'files.image-mime-types' => array(
    'image/jpeg'                => true,
    'image/jpg'                 => true,
    'image/png'                 => true,
    'image/gif'                 => true,
    'image/x-ico'               => true,
    'image/x-icon'              => true,
    'image/vnd.microsoft.icon'  => true,
  ),

  // Phabricator can proxy images from other servers so you can paste the URI
  // to a funny picture of a cat into the comment box and have it show up as an
  // image. However, this means the webserver Phabricator is running on will
  // make HTTP requests to arbitrary URIs. If the server has access to internal
  // resources, this could be a security risk. You should only enable it if you
  // are installed entirely a VPN and VPN access is required to access
  // Phabricator, or if the webserver has no special access to anything. If
  // unsure, it is safer to leave this disabled.
  'files.enable-proxy' => false,


// -- Storage --------------------------------------------------------------- //

  // Phabricator allows users to upload files, and can keep them in various
  // storage engines. This section allows you to configure which engines
  // Phabricator will use, and how it will use them.

  // The largest filesize Phabricator will store in the MySQL BLOB storage
  // engine, which just uses a database table to store files. While this isn't a
  // best practice, it's really easy to set up. This is hard-limited by the
  // value of 'max_allowed_packet' in MySQL (since this often defaults to 1MB,
  // the default here is slightly smaller than 1MB). Set this to 0 to disable
  // use of the MySQL blob engine.
  'storage.mysql-engine.max-size' => 1000000,

  // Phabricator provides a local disk storage engine, which just writes files
  // to some directory on local disk. The webserver must have read/write
  // permissions on this directory. This is straightforward and suitable for
  // most installs, but will not scale past one web frontend unless the path
  // is actually an NFS mount, since you'll end up with some of the files
  // written to each web frontend and no way for them to share. To use the
  // local disk storage engine, specify the path to a directory here. To
  // disable it, specify null.
  'storage.local-disk.path'       => null,

  // If you want to store files in Amazon S3, specify an AWS access and secret
  // key here and a bucket name below.
  'amazon-s3.access-key'          =>  null,
  'amazon-s3.secret-key'          =>  null,

  // Set this to a valid Amazon S3 bucket to store files there. You must also
  // configure S3 access keys above.
  'storage.s3.bucket'             => null,

  // Phabricator uses a storage engine selector to choose which storage engine
  // to use when writing file data. If you add new storage engines or want to
  // provide very custom rules (e.g., write images to one storage engine and
  // other files to a different one), you can provide an alternate
  // implementation here. The default engine will use choose MySQL, Local Disk,
  // and S3, in that order, if they have valid configurations above and a file
  // fits within configured limits.
  'storage.engine-selector' => 'PhabricatorDefaultFileStorageEngineSelector',

  // Set the size of the largest file a user may upload. This is used to render
  // text like "Maximum file size: 10MB" on interfaces where users can upload
  // files, and files larger than this size will be rejected.
  //
  // Specify this limit in bytes, or using a "K", "M", or "G" suffix.
  //
  // NOTE: Setting this to a large size is NOT sufficient to allow users to
  // upload large files. You must also configure a number of other settings. To
  // configure file upload limits, consult the article "Configuring File Upload
  // Limits" in the documentation. Once you've configured some limit across all
  // levels of the server, you can set this limit to an appropriate value and
  // the UI will then reflect the actual configured limit.
  'storage.upload-size-limit'   => null,

  // Phabricator puts databases in a namespace, which defualts to "phabricator"
  // -- for instance, the Differential database is named
  // "phabricator_differential" by default. You can change this namespace if you
  // want. Normally, you should not do this unless you are developing
  // Phabricator and using namespaces to separate multiple sandbox datasets.
  'storage.default-namespace'    => 'phabricator',


// -- Search ---------------------------------------------------------------- //

  // Phabricator supports Elastic Search; to use it, specify a host like
  // 'http://elastic.example.com:9200/' here.
  'search.elastic.host'     => null,

  // Phabricator uses a search engine selector to choose which search engine
  // to use when indexing and reconstructing documents, and when executing
  // queries. You can override the engine selector to provide a new selector
  // class which can select some custom engine you implement, if you want to
  // store your documents in some search engine which does not have default
  // support.
  'search.engine-selector'  => 'PhabricatorDefaultSearchEngineSelector',


// -- Differential ---------------------------------------------------------- //

  'differential.revision-custom-detail-renderer'  => null,

  // Array for custom remarkup rules. The array should have a list of
  // class names of classes that extend PhutilRemarkupRule
  'differential.custom-remarkup-rules' => null,

  // Array for custom remarkup block rules. The array should have a list of
  // class names of classes that extend PhutilRemarkupEngineBlockRule
  'differential.custom-remarkup-block-rules' => null,

  // Set display word-wrap widths for Differential. Specify a dictionary of
  // regular expressions mapping to column widths. The filename will be matched
  // against each regexp in order until one matches. The default configuration
  // uses a width of 100 for Java and 80 for other languages. Note that 80 is
  // the greatest column width of all time. Changes here will not be immediately
  // reflected in old revisions unless you purge the changeset render cache
  // (with `./scripts/util/purge_cache.php --changesets`).
  'differential.wordwrap' => array(
    '/\.java$/' => 100,
    '/.*/'      => 80,
  ),

  // List of file regexps where whitespace is meaningful and should not
  // use 'ignore-all' by default
  'differential.whitespace-matters' => array(
    '/\.py$/',
    '/\.l?hs$/',
  ),

  'differential.field-selector' => 'DifferentialDefaultFieldSelector',

  // Differential can show "Host" and "Path" fields on revisions, with
  // information about the machine and working directory where the
  // change came from. These fields are disabled by default because they may
  // occasionally have sensitive information; you can set this to true to
  // enable them.
  'differential.show-host-field'  => false,

  // Differential has a required "Test Plan" field by default, which requires
  // authors to fill out information about how they verified the correctness of
  // their changes when sending code for review. If you'd prefer not to use
  // this field, you can disable it here. You can also make it optional
  // (instead of required) below.
  'differential.show-test-plan-field' => true,

  // Differential has a required "Test Plan" field by default. You can make it
  // optional by setting this to false. You can also completely remove it above,
  // if you prefer.
  'differential.require-test-plan-field' => true,

  // If you set this to true, users can "!accept" revisions via email (normally,
  // they can take other actions but can not "!accept"). This action is disabled
  // by default because email authentication can be configured to be very weak,
  // and, socially, email "!accept" is kind of sketchy and implies revisions may
  // not actually be receiving thorough review.
  'differential.enable-email-accept' => false,

  // If you set this to true, users won't need to login to view differential
  // revisions.  Anonymous users will have read-only access and won't be able to
  // interact with the revisions.
  'differential.anonymous-access' => false,

  // List of file regexps that should be treated as if they are generated by
  // an automatic process, and thus get hidden by default in differential
  'differential.generated-paths' => array(
    // '/config\.h$/',
    // '#/autobuilt/#',
  ),


// -- Maniphest ------------------------------------------------------------- //

  'maniphest.enabled' => true,

  // Array of custom fields for Maniphest tasks. For details on adding custom
  // fields to Maniphest, see "Maniphest User Guide: Adding Custom Fields".
  'maniphest.custom-fields' => array(),

  // Class which drives custom field construction. See "Maniphest User Guide:
  // Adding Custom Fields" in the documentation for more information.
  'maniphest.custom-task-extensions-class' => 'ManiphestDefaultTaskExtensions',

// -- Phriction ------------------------------------------------------------- //

  'phriction.enabled' => true,

// -- Remarkup -------------------------------------------------------------- //

  // If you enable this, linked YouTube videos will be embeded inline. This has
  // mild security implications (you'll leak referrers to YouTube) and is pretty
  // silly (but sort of awesome).
  'remarkup.enable-embedded-youtube' => false,


// -- Garbage Collection ---------------------------------------------------- //

  // Phabricator generates various logs and caches in the database which can
  // be garbage collected after a while to make the total data size more
  // manageable. To run garbage collection, launch a
  // PhabricatorGarbageCollector daemon.

  // Since the GC daemon can issue large writes and table scans, you may want to
  // run it only during off hours or make sure it is scheduled so it doesn't
  // overlap with backups. This determines when the daemon can start running
  // each day.
  'gcdaemon.run-at'    => '12 AM',

  // How many seconds after 'gcdaemon.run-at' the daemon may collect garbage
  // for. By default it runs continuously, but you can set it to run for a
  // limited period of time. For instance, if you do backups at 3 AM, you might
  // run garbage collection for an hour beforehand. This is not a high-precision
  // limit so you may want to leave some room for the GC to actually stop, and
  // if you set it to something like 3 seconds you're on your own.
  'gcdaemon.run-for'   => 24 * 60 * 60,

  // These 'ttl' keys configure how much old data the GC daemon keeps around.
  // Objects older than the ttl will be collected. Set any value to 0 to store
  // data indefinitely.

  'gcdaemon.ttl.herald-transcripts'         => 30 * (24 * 60 * 60),
  'gcdaemon.ttl.daemon-logs'                =>  7 * (24 * 60 * 60),
  'gcdaemon.ttl.differential-parse-cache'   => 14 * (24 * 60 * 60),


// -- Feed ------------------------------------------------------------------ //

  // If you set this to true, you can embed Phabricator activity feeds in other
  // pages using iframes. These feeds are completely public, and a login is not
  // required to view them! This is intended for things like open source
  // projects that want to expose an activity feed on the project homepage.
  'feed.public' => false,


// -- Drydock --------------------------------------------------------------- //

  // If you want to use Drydock's builtin EC2 Blueprints, configure your AWS
  // EC2 credentials here.
  'amazon-ec2.access-key'   => null,
  'amazon-ec2.secret-key'   => null,

// -- Customization --------------------------------------------------------- //

  // Paths to additional phutil libraries to load.
  'load-libraries' => array(),

  'aphront.default-application-configuration-class' =>
    'AphrontDefaultApplicationConfiguration',

  'controller.oauth-registration' =>
    'PhabricatorOAuthDefaultRegistrationController',


  // Directory that phd (the Phabricator daemon control script) should use to
  // track running daemons.
  'phd.pid-directory' => '/var/tmp/phd',

  // Number of "TaskMaster" daemons that "phd start" should start. You can
  // raise this if you have a task backlog, or explicitly launch more with
  // "phd launch <N> taskmaster".
  'phd.start-taskmasters' => 4,

  // Path to custom celerity resource map relative to 'phabricator/src'.
  // See also `scripts/celerity_mapper.php`.
  'celerity.resource-path' => '__celerity_resource_map__.php',

  // This value is an input to the hash function when building resource hashes.
  // It has no security value, but if you accidentally poison user caches (by
  // pushing a bad patch or having something go wrong with a CDN, e.g.) you can
  // change this to something else and rebuild the Celerity map to break user
  // caches. Unless you are doing Celerity development, it is exceptionally
  // unlikely that you need to modify this.
  'celerity.resource-hash' => 'd9455ea150622ee044f7931dabfa52aa',

  // In a development environment, it is desirable to force static resources
  // (CSS and JS) to be read from disk on every request, so that edits to them
  // appear when you reload the page even if you haven't updated the resource
  // maps. This setting ensures requests will be verified against the state on
  // disk. Generally, you should leave this off in production (caching behavior
  // and performance improve with it off) but turn it on in development. (These
  // settings are the defaults.)
  'celerity.force-disk-reads' => false,

  // Minify static resources by removing whitespace and comments. You should
  // enable this in production, but disable it in development.
  'celerity.minify' => false,

  // You can respond to various application events by installing listeners,
  // which will receive callbacks when interesting things occur. Specify a list
  // of classes which extend PhabricatorEventListener here.
  'events.listeners'  => array(),

// -- Pygments -------------------------------------------------------------- //

  // Phabricator can highlight PHP by default, but if you want syntax
  // highlighting for other languages you should install the python package
  // 'Pygments', make sure the 'pygmentize' script is available in the
  // $PATH of the webserver, and then enable this.
  'pygments.enabled'            => false,

  // In places that we display a dropdown to syntax-highlight code,
  // this is where that list is defined.
  // Syntax is 'lexer-name' => 'Display Name',
  'pygments.dropdown-choices' => array(
    'apacheconf' => 'Apache Configuration',
    'bash' => 'Bash Scripting',
    'brainfuck' => 'Brainf*ck',
    'c' => 'C',
    'cpp' => 'C++',
    'css' => 'CSS',
    'diff' => 'Diff',
    'django' => 'Django Templating',
    'erb' => 'Embedded Ruby/ERB',
    'erlang' => 'Erlang',
    'html' => 'HTML',
    'infer' => 'Infer from title (extension)',
    'java' => 'Java',
    'js' => 'Javascript',
    'mysql' => 'MySQL',
    'perl' => 'Perl',
    'php' => 'PHP',
    'text' => 'Plain Text',
    'python' => 'Python',
    'rainbow' => 'Rainbow',
    'remarkup' => 'Remarkup',
    'ruby' => 'Ruby',
    'xml' => 'XML',
  ),

  'pygments.dropdown-default' => 'infer',

  // This is an override list of regular expressions which allows you to choose
  // what language files are highlighted as. If your projects have certain rules
  // about filenames or use unusual or ambiguous language extensions, you can
  // create a mapping here. This is an ordered dictionary of regular expressions
  // which will be tested against the filename. They should map to either an
  // explicit language as a string value, or a numeric index into the captured
  // groups as an integer.
  'syntax.filemap' => array(
    // Example: Treat all '*.xyz' files as PHP.
    // '@\\.xyz$@' => 'php',

    // Example: Treat 'httpd.conf' as 'apacheconf'.
    // '@/httpd\\.conf$@' => 'apacheconf',

    // Example: Treat all '*.x.bak' file as '.x'. NOTE: we map to capturing
    // group 1 by specifying the mapping as "1".
    // '@\\.([^.]+)\\.bak$@' => 1,

    '@\.arcconfig$@' => 'js',
  ),

);
