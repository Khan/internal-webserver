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

final class PhabricatorRepositoryGitCommitMessageParserWorker
  extends PhabricatorRepositoryCommitMessageParserWorker {

  public function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    // NOTE: %B was introduced somewhat recently in git's history, so pull
    // commit message information with %s and %b instead.
    // Even though we pass --encoding here, git doesn't always succeed, so
    // we try a little harder, since git *does* tell us what the actual encoding
    // is correctly (unless it doesn't; encoding is sometimes empty).
    list($info) = $repository->execxLocalCommand(
<<<<<<< HEAD
      'log -n 1 --encoding=%s --format=%s %s --',
      'UTF-8',
      implode('%x00', array('%e', '%cn', '%ce', '%an', '%ae', '%s%n%n%b')),
||||||| merged common ancestors
      "log -n 1 --encoding='UTF-8' " .
      "--pretty=format:%%cn%%x00%%an%%x00%%s%%n%%n%%b %s",
=======
      "log -n 1 --encoding='UTF-8' " .
      "--pretty=format:%%e%%x00%%cn%%x00%%an%%x00%%s%%n%%n%%b %s",
>>>>>>> 89123d17e0ed054c3b5fd9c83b908405ee43861e
      $commit->getCommitIdentifier());

<<<<<<< HEAD
    $parts = explode("\0", $info);
    $encoding = array_shift($parts);
||||||| merged common ancestors
    list($committer, $author, $message) = explode("\0", $info);
=======
    list($encoding, $committer, $author, $message) = explode("\0", $info);
>>>>>>> 89123d17e0ed054c3b5fd9c83b908405ee43861e

<<<<<<< HEAD
    // See note above - git doesn't always convert the encoding correctly.
    $do_convert = false;
    if (strlen($encoding) && strtoupper($encoding) != 'UTF-8') {
      if (function_exists('mb_convert_encoding')) {
        $do_convert = true;
      }
    }

    foreach ($parts as $key => $part) {
      if ($do_convert) {
        $parts[$key] = mb_convert_encoding($part, 'UTF-8', $encoding);
      }
      $parts[$key] = phutil_utf8ize($part);
    }

    $committer_name   = $parts[0];
    $committer_email  = $parts[1];
    $author_name      = $parts[2];
    $author_email     = $parts[3];
    $message          = $parts[4];

    if (strlen($author_email)) {
      $author = "{$author_name} <{$author_email}>";
    } else {
      $author = "{$author_name}";
    }

    if (strlen($committer_email)) {
      $committer = "{$committer_name} <{$committer_email}>";
    } else {
      $committer = "{$committer_name}";
    }
||||||| merged common ancestors
    // Make sure these are valid UTF-8.
    $committer = phutil_utf8ize($committer);
    $author = phutil_utf8ize($author);
    $message = phutil_utf8ize($message);
    $message = trim($message);
=======
    // See note above - git doesn't always convert the encoding correctly.
    if (strlen($encoding) && strtoupper($encoding) != "UTF-8") {
      if (function_exists('mb_convert_encoding')) {
        $message = mb_convert_encoding($message, "UTF-8", $encoding);
        $author = mb_convert_encoding($author, "UTF-8", $encoding);
        $committer = mb_convert_encoding($committer, "UTF-8", $encoding);
      }
    }

    // Make completely sure these are valid UTF-8.
    $committer = phutil_utf8ize($committer);
    $author = phutil_utf8ize($author);
    $message = phutil_utf8ize($message);
    $message = trim($message);
>>>>>>> 89123d17e0ed054c3b5fd9c83b908405ee43861e

    if ($committer == $author) {
      $committer = null;
    }

    $this->updateCommitData($author, $message, $committer);

    if ($this->shouldQueueFollowupTasks()) {
      $task = new PhabricatorWorkerTask();
      $task->setTaskClass('PhabricatorRepositoryGitCommitChangeParserWorker');
      $task->setData(
        array(
          'commitID' => $commit->getID(),
        ));
      $task->save();
    }
  }

  protected function getCommitHashes(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    list($stdout) = $repository->execxLocalCommand(
      'log -n 1 --format=%s %s --',
      '%T',
      $commit->getCommitIdentifier());

    $commit_hash = $commit->getCommitIdentifier();
    $tree_hash = trim($stdout);

    return array(
      array(ArcanistDifferentialRevisionHash::HASH_GIT_COMMIT,
            $commit_hash),
      array(ArcanistDifferentialRevisionHash::HASH_GIT_TREE,
            $tree_hash),
    );
  }

}
