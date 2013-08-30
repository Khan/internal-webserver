<?php

/**
 * See #394445 for an explanation of why this thing even exists.
 *
 * @task recipients   Managing Recipients
 */
final class PhabricatorMetaMTAMail extends PhabricatorMetaMTADAO {

  const STATUS_QUEUE = 'queued';
  const STATUS_SENT  = 'sent';
  const STATUS_FAIL  = 'fail';
  const STATUS_VOID  = 'void';

  const MAX_RETRIES   = 250;
  const RETRY_DELAY   = 5;

  protected $parameters;
  protected $status;
  protected $message;
  protected $retryCount;
  protected $nextRetry;
  protected $relatedPHID;

  private $excludePHIDs = array();
  private $overrideNoSelfMail = false;

  public function __construct() {

    $this->status     = self::STATUS_QUEUE;
    $this->retryCount = 0;
    $this->nextRetry  = time();
    $this->parameters = array();

    parent::__construct();
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'parameters'  => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  protected function setParam($param, $value) {
    $this->parameters[$param] = $value;
    return $this;
  }

  protected function getParam($param, $default = null) {
    return idx($this->parameters, $param, $default);
  }

  /**
   * Set tags (@{class:MetaMTANotificationType} constants) which identify the
   * content of this mail in a general way. These tags are used to allow users
   * to opt out of receiving certain types of mail, like updates when a task's
   * projects change.
   *
   * @param list<const> List of @{class:MetaMTANotificationType} constants.
   * @return this
   */
  public function setMailTags(array $tags) {
    $this->setParam('mailtags', $tags);
    return $this;
  }

  public function getMailTags() {
    return $this->getParam('mailtags', array());
  }

  /**
   * In Gmail, conversations will be broken if you reply to a thread and the
   * server sends back a response without referencing your Message-ID, even if
   * it references a Message-ID earlier in the thread. To avoid this, use the
   * parent email's message ID explicitly if it's available. This overwrites the
   * "In-Reply-To" and "References" headers we would otherwise generate. This
   * needs to be set whenever an action is triggered by an email message. See
   * T251 for more details.
   *
   * @param   string The "Message-ID" of the email which precedes this one.
   * @return  this
   */
  public function setParentMessageID($id) {
    $this->setParam('parent-message-id', $id);
    return $this;
  }

  public function getParentMessageID() {
    return $this->getParam('parent-message-id');
  }

  public function getSubject() {
    return $this->getParam('subject');
  }

  public function addTos(array $phids) {
    $phids = array_unique($phids);
    $this->setParam('to', $phids);
    return $this;
  }

  public function addRawTos(array $raw_email) {
    $this->setParam('raw-to', $raw_email);
    return $this;
  }

  public function addCCs(array $phids) {
    $phids = array_unique($phids);
    $this->setParam('cc', $phids);
    return $this;
  }

  public function setExcludeMailRecipientPHIDs($exclude) {
    $this->excludePHIDs = $exclude;
    return $this;
  }
  private function getExcludeMailRecipientPHIDs() {
    return $this->excludePHIDs;
  }

  public function getOverrideNoSelfMailPreference() {
    return $this->overrideNoSelfMail;
  }

  public function setOverrideNoSelfMailPreference($override) {
    $this->overrideNoSelfMail = $override;
    return $this;
  }

  public function getTranslation(array $objects) {
    $default_translation = PhabricatorEnv::getEnvConfig('translation.provider');
    $return = null;
    $recipients = array_merge(
      idx($this->parameters, 'to', array()),
      idx($this->parameters, 'cc', array()));
    foreach (array_select_keys($objects, $recipients) as $object) {
      $translation = null;
      if ($object instanceof PhabricatorUser) {
        $translation = $object->getTranslation();
      }
      if (!$translation) {
        $translation = $default_translation;
      }
      if ($return && $translation != $return) {
        return $default_translation;
      }
      $return = $translation;
    }

    if (!$return) {
      $return = $default_translation;
    }

    return $return;
  }

  public function addPHIDHeaders($name, array $phids) {
    foreach ($phids as $phid) {
      $this->addHeader($name, '<'.$phid.'>');
    }
    return $this;
  }

  public function addHeader($name, $value) {
    $this->parameters['headers'][] = array($name, $value);
    return $this;
  }

  public function addAttachment(PhabricatorMetaMTAAttachment $attachment) {
    $this->parameters['attachments'][] = $attachment->toDictionary();
    return $this;
  }

  public function getAttachments() {
    $dicts = $this->getParam('attachments');

    $result = array();
    foreach ($dicts as $dict) {
      $result[] = PhabricatorMetaMTAAttachment::newFromDictionary($dict);
    }
    return $result;
  }

  public function setAttachments(array $attachments) {
    assert_instances_of($attachments, 'PhabricatorMetaMTAAttachment');
    $this->setParam('attachments', mpull($attachments, 'toDictionary'));
    return $this;
  }

  public function setFrom($from) {
    $this->setParam('from', $from);
    return $this;
  }

  public function setReplyTo($reply_to) {
    $this->setParam('reply-to', $reply_to);
    return $this;
  }

  public function setSubject($subject) {
    $this->setParam('subject', $subject);
    return $this;
  }

  public function setSubjectPrefix($prefix) {
    $this->setParam('subject-prefix', $prefix);
    return $this;
  }

  public function setVarySubjectPrefix($prefix) {
    $this->setParam('vary-subject-prefix', $prefix);
    return $this;
  }

  public function setBody($body) {
    $this->setParam('body', $body);
    return $this;
  }

  public function getBody() {
    return $this->getParam('body');
  }

  public function setIsHTML($html) {
    $this->setParam('is-html', $html);
    return $this;
  }

  public function getSimulatedFailureCount() {
    return nonempty($this->getParam('simulated-failures'), 0);
  }

  public function setSimulatedFailureCount($count) {
    $this->setParam('simulated-failures', $count);
    return $this;
  }

  public function getWorkerTaskID() {
    return $this->getParam('worker-task');
  }

  public function setWorkerTaskID($id) {
    $this->setParam('worker-task', $id);
    return $this;
  }

  public function getToPHIDs() {
    return $this->getParam('to', array());
  }

  public function getRawToAddresses() {
    return $this->getParam('raw-to', array());
  }

  public function getCcPHIDs() {
    return $this->getParam('cc', array());
  }

  /**
   * Flag that this is an auto-generated bulk message and should have bulk
   * headers added to it if appropriate. Broadly, this means some flavor of
   * "Precedence: bulk" or similar, but is implementation and configuration
   * dependent.
   *
   * @param bool  True if the mail is automated bulk mail.
   * @return this
   */
  public function setIsBulk($is_bulk) {
    $this->setParam('is-bulk', $is_bulk);
    return $this;
  }

  /**
   * Use this method to set an ID used for message threading. MetaMTA will
   * set appropriate headers (Message-ID, In-Reply-To, References and
   * Thread-Index) based on the capabilities of the underlying mailer.
   *
   * @param string  Unique identifier, appropriate for use in a Message-ID,
   *                In-Reply-To or References headers.
   * @param bool    If true, indicates this is the first message in the thread.
   * @return this
   */
  public function setThreadID($thread_id, $is_first_message = false) {
    $this->setParam('thread-id', $thread_id);
    $this->setParam('is-first-message', $is_first_message);
    return $this;
  }

  /**
   * Save a newly created mail to the database and attempt to send it
   * immediately if the server is configured for immediate sends. When
   * applications generate new mail they should generally use this method to
   * deliver it. If the server doesn't use immediate sends, this has the same
   * effect as calling save(): the mail will eventually be delivered by the
   * MetaMTA daemon.
   *
   * @return this
   */
  public function saveAndSend() {
    $ret = null;

    if (PhabricatorEnv::getEnvConfig('metamta.send-immediately')) {
      $ret = $this->sendNow();
    } else {
      $ret = $this->save();
    }

    return $ret;
  }

  protected function didWriteData() {
    parent::didWriteData();

    if (!$this->getWorkerTaskID()) {
      $mailer_task = PhabricatorWorker::scheduleTask(
        'PhabricatorMetaMTAWorker',
        $this->getID());

      $this->setWorkerTaskID($mailer_task->getID());
      $this->save();
    }
  }


  public function buildDefaultMailer() {
    return PhabricatorEnv::newObjectFromConfig('metamta.mail-adapter');
  }


  /**
   * Attempt to deliver an email immediately, in this process.
   *
   * @param bool  Try to deliver this email even if it has already been
   *              delivered or is in backoff after a failed delivery attempt.
   * @param PhabricatorMailImplementationAdapter Use a specific mail adapter,
   *              instead of the default.
   *
   * @return void
   */
  public function sendNow(
    $force_send = false,
    PhabricatorMailImplementationAdapter $mailer = null) {

    if ($mailer === null) {
      $mailer = $this->buildDefaultMailer();
    }

    if (!$force_send) {
      if ($this->getStatus() != self::STATUS_QUEUE) {
        throw new Exception("Trying to send an already-sent mail!");
      }

      if (time() < $this->getNextRetry()) {
        throw new Exception("Trying to send an email before next retry!");
      }
    }

    try {
      $params = $this->parameters;

      $actors = $this->loadAllActors();
      $deliverable_actors = $this->filterDeliverableActors($actors);

      $default_from = PhabricatorEnv::getEnvConfig('metamta.default-address');
      if (empty($params['from'])) {
        $mailer->setFrom($default_from);
      }

      $is_first = idx($params, 'is-first-message');
      unset($params['is-first-message']);

      $is_threaded = (bool)idx($params, 'thread-id');

      $reply_to_name = idx($params, 'reply-to-name', '');
      unset($params['reply-to-name']);

      $add_cc = array();
      $add_to = array();

      foreach ($params as $key => $value) {
        switch ($key) {
          case 'from':
            $from = $value;
            $actor = $actors[$from];

            $actor_email = $actor->getEmailAddress();
            $actor_name = $actor->getName();
            $can_send_as_user = $actor_email &&
              PhabricatorEnv::getEnvConfig('metamta.can-send-as-user');

            if ($can_send_as_user) {
              $mailer->setFrom($actor_email);
            } else {
              $from_email = coalesce($actor_email, $default_from);
              $from_name = coalesce($actor_name, pht('Phabricator'));

              if (empty($params['reply-to'])) {
                $params['reply-to'] = $from_email;
                $params['reply-to-name'] = $from_name;
              }

              $mailer->setFrom($default_from, $from_name);
            }
            break;
          case 'reply-to':
            $mailer->addReplyTo($value, $reply_to_name);
            break;
          case 'to':
            $to_actors = array_select_keys($deliverable_actors, $value);
            $add_to = array_merge(
              $add_to,
              mpull($to_actors, 'getEmailAddress'));
            break;
          case 'raw-to':
            $add_to = array_merge($add_to, $value);
            break;
          case 'cc':
            $cc_actors = array_select_keys($deliverable_actors, $value);
            $add_cc = array_merge(
              $add_cc,
              mpull($cc_actors, 'getEmailAddress'));
            break;
          case 'headers':
            foreach ($value as $pair) {
              list($header_key, $header_value) = $pair;

              // NOTE: If we have \n in a header, SES rejects the email.
              $header_value = str_replace("\n", " ", $header_value);

              $mailer->addHeader($header_key, $header_value);
            }
            break;
          case 'attachments':
            $value = $this->getAttachments();
            foreach ($value as $attachment) {
              $mailer->addAttachment(
                $attachment->getData(),
                $attachment->getFilename(),
                $attachment->getMimeType());
            }
            break;
          case 'body':
            $max = PhabricatorEnv::getEnvConfig('metamta.email-body-limit');
            if (strlen($value) > $max) {
              $value = phutil_utf8_shorten($value, $max);
              $value .= "\n";
              $value .= pht('(This email was truncated at %d bytes.)', $max);
            }
            $mailer->setBody($value);
            break;
          case 'subject':
            // Only try to use preferences if everything is multiplexed, so we
            // get consistent behavior.
            $use_prefs = self::shouldMultiplexAllMail();

            $prefs = null;
            if ($use_prefs) {

              // If multiplexing is enabled, some recipients will be in "Cc"
              // rather than "To". We'll move them to "To" later (or supply a
              // dummy "To") but need to look for the recipient in either the
              // "To" or "Cc" fields here.
              $target_phid = head(idx($params, 'to', array()));
              if (!$target_phid) {
                $target_phid = head(idx($params, 'cc', array()));
              }

              if ($target_phid) {
                $user = id(new PhabricatorUser())->loadOneWhere(
                  'phid = %s',
                  $target_phid);
                if ($user) {
                  $prefs = $user->loadPreferences();
                }
              }
            }

            $subject = array();

            if ($is_threaded) {
              $add_re = PhabricatorEnv::getEnvConfig('metamta.re-prefix');

              if ($prefs) {
                $add_re = $prefs->getPreference(
                  PhabricatorUserPreferences::PREFERENCE_RE_PREFIX,
                  $add_re);
              }

              if ($add_re) {
                $subject[] = 'Re:';
              }
            }

            $subject[] = trim(idx($params, 'subject-prefix'));

            $vary_prefix = idx($params, 'vary-subject-prefix');
            if ($vary_prefix != '') {
              $use_subject = PhabricatorEnv::getEnvConfig(
                'metamta.vary-subjects');

              if ($prefs) {
                $use_subject = $prefs->getPreference(
                  PhabricatorUserPreferences::PREFERENCE_VARY_SUBJECT,
                  $use_subject);
              }

              if ($use_subject) {
                $subject[] = $vary_prefix;
              }
            }

            $subject[] = $value;

            $mailer->setSubject(implode(' ', array_filter($subject)));
            break;
          case 'is-html':
            if ($value) {
              $mailer->setIsHTML(true);
            }
            break;
          case 'is-bulk':
            if ($value) {
              if (PhabricatorEnv::getEnvConfig('metamta.precedence-bulk')) {
                $mailer->addHeader('Precedence', 'bulk');
              }
            }
            break;
          case 'thread-id':

            // NOTE: Gmail freaks out about In-Reply-To and References which
            // aren't in the form "<string@domain.tld>"; this is also required
            // by RFC 2822, although some clients are more liberal in what they
            // accept.
            $domain = PhabricatorEnv::getEnvConfig('metamta.domain');
            $value = '<'.$value.'@'.$domain.'>';

            if ($is_first && $mailer->supportsMessageIDHeader()) {
              $mailer->addHeader('Message-ID',  $value);
            } else {
              $in_reply_to = $value;
              $references = array($value);
              $parent_id = $this->getParentMessageID();
              if ($parent_id) {
                $in_reply_to = $parent_id;
                // By RFC 2822, the most immediate parent should appear last
                // in the "References" header, so this order is intentional.
                $references[] = $parent_id;
              }
              $references = implode(' ', $references);
              $mailer->addHeader('In-Reply-To', $in_reply_to);
              $mailer->addHeader('References',  $references);
            }
            $thread_index = $this->generateThreadIndex($value, $is_first);
            $mailer->addHeader('Thread-Index', $thread_index);
            break;
          case 'mailtags':
            // Handled below.
            break;
          case 'subject-prefix':
          case 'vary-subject-prefix':
            // Handled above.
            break;
          default:
            // Just discard.
        }
      }

      if (!$add_to && !$add_cc) {
        $this->setStatus(self::STATUS_VOID);
        $this->setMessage(
          "Message has no valid recipients: all To/Cc are disabled, invalid, ".
          "or configured not to receive this mail.");
        return $this->save();
      }

      $mailer->addHeader('X-Phabricator-Sent-This-Message', 'Yes');
      $mailer->addHeader('X-Mail-Transport-Agent', 'MetaMTA');

      // Some clients respect this to suppress OOF and other auto-responses.
      $mailer->addHeader('X-Auto-Response-Suppress', 'All');

      // If the message has mailtags, filter out any recipients who don't want
      // to receive this type of mail.
      $mailtags = $this->getParam('mailtags');
      if ($mailtags) {
        $tag_header = array();
        foreach ($mailtags as $mailtag) {
          $tag_header[] = '<'.$mailtag.'>';
        }
        $tag_header = implode(', ', $tag_header);
        $mailer->addHeader('X-Phabricator-Mail-Tags', $tag_header);
      }

      // Some mailers require a valid "To:" in order to deliver mail. If we
      // don't have any "To:", try to fill it in with a placeholder "To:".
      // If that also fails, move the "Cc:" line to "To:".
      if (!$add_to) {
        $placeholder_key = 'metamta.placeholder-to-recipient';
        $placeholder = PhabricatorEnv::getEnvConfig($placeholder_key);
        if ($placeholder !== null) {
          $add_to = array($placeholder);
        } else {
          $add_to = $add_cc;
          $add_cc = array();
        }
      }

      $add_to = array_unique($add_to);
      $add_cc = array_unique($add_cc);

      $mailer->addTos($add_to);
      if ($add_cc) {
        $mailer->addCCs($add_cc);
      }
    } catch (Exception $ex) {
      $this->setStatus(self::STATUS_FAIL);
      $this->setMessage($ex->getMessage());
      return $this->save();
    }

    if ($this->getRetryCount() < $this->getSimulatedFailureCount()) {
      $ok = false;
      $error = 'Simulated failure.';
    } else {
      try {
        $ok = $mailer->send();
        $error = null;
      } catch (PhabricatorMetaMTAPermanentFailureException $ex) {
        $this->setStatus(self::STATUS_FAIL);
        $this->setMessage($ex->getMessage());
        return $this->save();
      } catch (Exception $ex) {
        $ok = false;
        $error = $ex->getMessage()."\n".$ex->getTraceAsString();
      }
    }

    if (!$ok) {
      $this->setMessage($error);
      if ($this->getRetryCount() > self::MAX_RETRIES) {
        $this->setStatus(self::STATUS_FAIL);
      } else {
        $this->setRetryCount($this->getRetryCount() + 1);
        $next_retry = time() + ($this->getRetryCount() * self::RETRY_DELAY);
        $this->setNextRetry($next_retry);
      }
    } else {
      $this->setStatus(self::STATUS_SENT);
    }

    return $this->save();
  }

  public static function getReadableStatus($status_code) {
    static $readable = array(
      self::STATUS_QUEUE => "Queued for Delivery",
      self::STATUS_FAIL  => "Delivery Failed",
      self::STATUS_SENT  => "Sent",
      self::STATUS_VOID  => "Void",
    );
    $status_code = coalesce($status_code, '?');
    return idx($readable, $status_code, $status_code);
  }

  private function generateThreadIndex($seed, $is_first_mail) {
    // When threading, Outlook ignores the 'References' and 'In-Reply-To'
    // headers that most clients use. Instead, it uses a custom 'Thread-Index'
    // header. The format of this header is something like this (from
    // camel-exchange-folder.c in Evolution Exchange):

    /* A new post to a folder gets a 27-byte-long thread index. (The value
     * is apparently unique but meaningless.) Each reply to a post gets a
     * 32-byte-long thread index whose first 27 bytes are the same as the
     * parent's thread index. Each reply to any of those gets a
     * 37-byte-long thread index, etc. The Thread-Index header contains a
     * base64 representation of this value.
     */

    // The specific implementation uses a 27-byte header for the first email
    // a recipient receives, and a random 5-byte suffix (32 bytes total)
    // thereafter. This means that all the replies are (incorrectly) siblings,
    // but it would be very difficult to keep track of the entire tree and this
    // gets us reasonable client behavior.

    $base = substr(md5($seed), 0, 27);
    if (!$is_first_mail) {
      // Not totally sure, but it seems like outlook orders replies by
      // thread-index rather than timestamp, so to get these to show up in the
      // right order we use the time as the last 4 bytes.
      $base .= ' '.pack('N', time());
    }

    return base64_encode($base);
  }

  public static function shouldMultiplexAllMail() {
    return PhabricatorEnv::getEnvConfig('metamta.one-mail-per-recipient');
  }


/* -(  Managing Recipients  )------------------------------------------------ */


  /**
   * Get all of the recipients for this mail, after preference filters are
   * applied. This list has all objects to whom delivery will be attempted.
   *
   * @return  list<phid>  A list of all recipients to whom delivery will be
   *                      attempted.
   * @task recipients
   */
  public function buildRecipientList() {
    $actors = $this->loadActors(
      array_merge(
        $this->getToPHIDs(),
        $this->getCcPHIDs()));
    $actors = $this->filterDeliverableActors($actors);
    return mpull($actors, 'getPHID');
  }

  public function loadAllActors() {
    $actor_phids = array_merge(
      array($this->getParam('from')),
      $this->getToPHIDs(),
      $this->getCcPHIDs());

    return $this->loadActors($actor_phids);
  }

  private function filterDeliverableActors(array $actors) {
    assert_instances_of($actors, 'PhabricatorMetaMTAActor');
    $deliverable_actors = array();
    foreach ($actors as $phid => $actor) {
      if ($actor->isDeliverable()) {
        $deliverable_actors[$phid] = $actor;
      }
    }
    return $deliverable_actors;
  }

  private function loadActors(array $actor_phids) {
    $actor_phids = array_filter($actor_phids);
    $viewer = PhabricatorUser::getOmnipotentUser();

    $actors = id(new PhabricatorMetaMTAActorQuery())
      ->setViewer($viewer)
      ->withPHIDs($actor_phids)
      ->execute();

    if (!$actors) {
      return array();
    }

    // Exclude explicit recipients.
    foreach ($this->getExcludeMailRecipientPHIDs() as $phid) {
      $actor = idx($actors, $phid);
      if (!$actor) {
        continue;
      }
      $actor->setUndeliverable(
        pht(
          'This message is a response to another email message, and this '.
          'recipient received the original email message, so we are not '.
          'sending them this substantially similar message (for example, '.
          'the sender used "Reply All" instead of "Reply" in response to '.
          'mail from Phabricator).'));
    }

    // Exclude the actor if their preferences are set.
    $from_phid = $this->getParam('from');
    $from_actor = idx($actors, $from_phid);
    if ($from_actor) {
      $from_user = id(new PhabricatorPeopleQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($from_phid))
        ->execute();
      $from_user = head($from_user);
      if ($from_user && !$this->getOverrideNoSelfMailPreference()) {
        $pref_key = PhabricatorUserPreferences::PREFERENCE_NO_SELF_MAIL;
        $exclude_self = $from_user
          ->loadPreferences()
          ->getPreference($pref_key);
        if ($exclude_self) {
          $from_actor->setUndeliverable(
            pht(
              'This recipient is the user whose actions caused delivery of '.
              'this message, but they have set preferences so they do not '.
              'receive mail about their own actions (Settings > Email '.
              'Preferences > Self Actions).'));
        }
      }
    }


    // Exclude all recipients who have set preferences to not receive this type
    // of email (for example, a user who says they don't want emails about task
    // CC changes).
    $tags = $this->getParam('mailtags');
    if ($tags) {
      $all_prefs = id(new PhabricatorUserPreferences())->loadAllWhere(
        'userPHID in (%Ls)',
        $actor_phids);
      $all_prefs = mpull($all_prefs, null, 'getUserPHID');

      foreach ($all_prefs as $phid => $prefs) {
        $user_mailtags = $prefs->getPreference(
          PhabricatorUserPreferences::PREFERENCE_MAILTAGS,
          array());

        // The user must have elected to receive mail for at least one
        // of the mailtags.
        $send = false;
        foreach ($tags as $tag) {
          if (idx($user_mailtags, $tag, true)) {
            $send = true;
            break;
          }
        }

        if (!$send) {
          $actors[$phid]->setUndeliverable(
            pht(
              'This mail has tags which control which users receive it, and '.
              'this recipient has not elected to receive mail with any of '.
              'the tags on this message (Settings > Email Preferences).'));
        }
      }
    }

    return $actors;
  }


}
