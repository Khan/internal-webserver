<?php

/**
 * Test cases for @{class:ArcanistDiffParser}.
 *
 * @group testcase
 */
final class ArcanistDiffParserTestCase extends ArcanistTestCase {

  public function testParser() {
    $root = dirname(__FILE__).'/diff/';
    foreach (Filesystem::listDirectory($root, $hidden = false) as $file) {
      $this->parseDiff($root.$file);
    }
  }

  private function parseDiff($diff_file) {
    $contents = Filesystem::readFile($diff_file);
    $file = basename($diff_file);

    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($contents);

    switch ($file) {
      case 'colorized.hggitdiff':
        $this->assertEqual(1, count($changes));
        break;
      case 'basic-missing-both-newlines-plus.udiff':
      case 'basic-missing-both-newlines.udiff':
      case 'basic-missing-new-newline-plus.udiff':
      case 'basic-missing-new-newline.udiff':
      case 'basic-missing-old-newline-plus.udiff':
      case 'basic-missing-old-newline.udiff':
        $expect_old = strpos($file, '-old-') || strpos($file, '-both-');
        $expect_new = strpos($file, '-new-') || strpos($file, '-both-');
        $expect_two = strpos($file, '-plus');

        $this->assertEqual(count($changes), $expect_two ? 2 : 1);
        $change = reset($changes);
        $this->assertEqual(true, $change !== null);

        $hunks = $change->getHunks();
        $this->assertEqual(1, count($hunks));

        $hunk = reset($hunks);
        $this->assertEqual((bool)$expect_old, $hunk->getIsMissingOldNewline());
        $this->assertEqual((bool)$expect_new, $hunk->getIsMissingNewNewline());
        break;
      case 'basic-binary.udiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        break;
      case 'basic-multi-hunk.udiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $hunks = $change->getHunks();
        $this->assertEqual(4, count($hunks));
        $this->assertEqual('right', $change->getCurrentPath());
        $this->assertEqual('left', $change->getOldPath());
        break;
      case 'basic-multi-hunk-content.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $hunks = $change->getHunks();
        $this->assertEqual(2, count($hunks));

        $there_is_a_literal_trailing_space_here = ' ';

        $corpus_0 = <<<EOCORPUS
 asdfasdf
+% quack
 %
-%
 %%
 %%
 %%%

EOCORPUS;
        $corpus_1 = <<<EOCORPUS
 %%%%%
 %%%%%
{$there_is_a_literal_trailing_space_here}
-!
+! quack

EOCORPUS;
        $this->assertEqual(
          $corpus_0,
          $hunks[0]->getCorpus());
        $this->assertEqual(
          $corpus_1,
          $hunks[1]->getCorpus());
        break;
      case 'svn-ignore-whitespace-only.svndiff':
        $this->assertEqual(2, count($changes));
        $hunks = reset($changes)->getHunks();
        $this->assertEqual(0, count($hunks));
        break;
      case 'svn-property-add.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $hunks = reset($changes)->getHunks();
        $this->assertEqual(1, count($hunks));
        $this->assertEqual(
          array(
            'duck' => 'quack',
          ),
          $change->getNewProperties()
        );
        break;
      case 'svn-property-modify.svndiff':
        $this->assertEqual(2, count($changes));

        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          array(
            'svn:ignore' => '*.phpz',
          ),
          $change->getOldProperties()
        );
        $this->assertEqual(
          array(
            'svn:ignore' => '*.php',
          ),
          $change->getNewProperties()
        );

        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          array(
            'svn:special' => '*',
          ),
          $change->getOldProperties()
        );
        $this->assertEqual(
          array(
            'svn:special' => 'moo',
          ),
          $change->getNewProperties()
        );
        break;
      case 'svn-property-delete.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);

        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          $change->getOldProperties(),
          array(
            'svn:special' => '*',
          ));
        $this->assertEqual(
          array(
          ),
          $change->getNewProperties());
        break;
      case 'svn-property-merged.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);

        $this->assertEqual(count($change->getHunks()), 0);

        $this->assertEqual(
          $change->getOldProperties(),
          array());
        $this->assertEqual(
          $change->getNewProperties(),
          array());
        break;
      case 'svn-property-merge.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);

        $this->assertEqual(count($change->getHunks()), 0);
        $this->assertEqual(
          $change->getOldProperties(),
          array(
          ));
        $this->assertEqual(
          $change->getNewProperties(),
          array(
            'svn:mergeinfo' => <<<EOTEXT
Merged /tfb/branches/internmove/www/html/js/help/UIFaq.js:r83462-126155
Merged /tfb/branches/ads-create-v3/www/html/js/help/UIFaq.js:r140558-142418
EOTEXT
          ));
        break;
      case 'svn-binary-add.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          array(
            'svn:mime-type' => 'application/octet-stream',
          ),
          $change->getNewProperties()
        );
        break;
      case 'svn-binary-diff.svndiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        $this->assertEqual(count($change->getHunks()), 0);
        break;
      case 'git-delete-file.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_DELETE,
          $change->getType());
        $this->assertEqual(
          'scripts/intern/test/testfile2',
          $change->getCurrentPath());
        $this->assertEqual(1, count($change->getHunks()));
        break;
      case 'git-binary-change.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        $this->assertEqual(0, count($change->getHunks()));
        break;
      case 'git-filemode-change.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(1, count($change->getHunks()));
        $this->assertEqual(
          array(
            'unix:filemode' => '100644',
          ),
          $change->getOldProperties()
        );
        $this->assertEqual(
          array(
            'unix:filemode' => '100755',
          ),
          $change->getNewProperties()
        );
        break;
      case 'git-filemode-change-only.gitdiff':
        $this->assertEqual(count($changes), 2);
        $change = reset($changes);
        $this->assertEqual(count($change->getHunks()), 0);
        $this->assertEqual(
          array(
            'unix:filemode' => '100644',
          ),
          $change->getOldProperties()
        );
        $this->assertEqual(
          array(
            'unix:filemode' => '100755',
          ),
          $change->getNewProperties()
        );
        break;
      case 'svn-empty-file.svndiff':
        $this->assertEqual(2, count($changes));
        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        break;
      case 'git-ignore-whitespace-only.gitdiff':
        $this->assertEqual(count($changes), 2);

        $change = array_shift($changes);
        $this->assertEqual(count($change->getHunks()), 0);
        $this->assertEqual(
          $change->getOldPath(),
          'scripts/intern/test/testfile2');
        $this->assertEqual(
          $change->getCurrentPath(),
          'scripts/intern/test/testfile2');

        $change = array_shift($changes);
        $this->assertEqual(count($change->getHunks()), 1);
        $this->assertEqual(
          $change->getOldPath(),
          'scripts/intern/test/testfile3');
        $this->assertEqual(
          $change->getCurrentPath(),
          'scripts/intern/test/testfile3');
        break;
      case 'git-move.gitdiff':
      case 'git-move-edit.gitdiff':
      case 'git-move-plus.gitdiff':

        $extra_changeset = (bool)strpos($file, '-plus');
        $has_hunk = (bool)strpos($file, '-edit');

        $this->assertEqual($extra_changeset ? 3 : 2, count($changes));

        $change = array_shift($changes);
        $this->assertEqual($has_hunk ? 1 : 0,
                          count($change->getHunks()));
        $this->assertEqual(
          $change->getType(),
          ArcanistDiffChangeType::TYPE_MOVE_HERE);

        $target = $change;

        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_AWAY,
          $change->getType()
        );

        $this->assertEqual(
          $change->getCurrentPath(),
          $target->getOldPath());
        $this->assertEqual(
          true,
          in_array($target->getCurrentPath(), $change->getAwayPaths()));
        break;
      case 'git-merge-header.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MESSAGE,
          $change->getType());
        $this->assertEqual(
          '501f6d519703458471dbea6284ec5f49d1408598',
          $change->getCommitHash());
        break;
      case 'git-new-file.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $change->getType());
        break;
      case 'git-copy.gitdiff':
        $this->assertEqual(2, count($changes));

        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_HERE,
          $change->getType());
        $this->assertEqual(
          'flib/intern/widgets/ui/UIWidgetRSSBox.php',
          $change->getCurrentPath());

        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_AWAY,
          $change->getType());
        $this->assertEqual(
          'lib/display/intern/ui/widget/UIWidgetRSSBox.php',
          $change->getCurrentPath());

        break;
      case 'git-copy-plus.gitdiff':
        $this->assertEqual(2, count($changes));

        $change = array_shift($changes);
        $this->assertEqual(3, count($change->getHunks()));
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_HERE,
          $change->getType());
        $this->assertEqual(
          'flib/intern/widgets/ui/UIWidgetGraphConnect.php',
          $change->getCurrentPath());

        $change = array_shift($changes);
        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_AWAY,
          $change->getType());
        $this->assertEqual(
          'lib/display/intern/ui/widget/UIWidgetLunchtime.php',
          $change->getCurrentPath());
        break;
      case 'svn-property-multiline.svndiff':
        $this->assertEqual(1, count($changes));
        $change = array_shift($changes);

        $this->assertEqual(0, count($change->getHunks()));
        $this->assertEqual(
          array(
            'svn:ignore' => 'tags',
          ),
          $change->getOldProperties()
        );
        $this->assertEqual(
          array(
            'svn:ignore' => "tags\nasdf\nlol\nwhat",
          ),
          $change->getNewProperties()
        );
        break;
      case 'git-empty-files.gitdiff':
        $this->assertEqual(2, count($changes));
        while ($change = array_shift($changes)) {
          $this->assertEqual(0, count($change->getHunks()));
        }
        break;
      case 'git-mnemonicprefix.gitdiff':
        // Check parsing of diffs created with `diff.mnemonicprefix`
        // configuration option set to `true`.
        $this->assertEqual(1, count($changes));
        $this->assertEqual(1, count(reset($changes)->getHunks()));
        break;
      case 'git-commit.gitdiff':
      case 'git-commit-logdecorate.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MESSAGE,
          $change->getType());
        $this->assertEqual(
          '76e2f1339c298c748aa0b52030799ed202a6537b',
          $change->getCommitHash());
        $this->assertEqual(
          <<<EOTEXT

Deprecating UIActionButton (Part 1)

Summary: Replaces calls to UIActionButton with <ui:button>.  I tested most
         of these calls, but there were some that I didn't know how to
         reach, so if you are one of the owners of this code, please test
         your feature in my sandbox: www.ngao.devrs013.facebook.com

         @brosenthal, I removed some logic that was setting a disabled state
         on a UIActionButton, which is actually a no-op.

Reviewed By: brosenthal

Other Commenters: sparker, egiovanola

Test Plan: www.ngao.devrs013.facebook.com

           Explicitly tested:
           * ads creation flow (add keyword)
           * ads manager (conversion tracking)
           * help center (create a discussion)
           * new user wizard (next step button)

Revert: OK

DiffCamp Revision: 94064

git-svn-id: svn+ssh://tubbs/svnroot/tfb/trunk/www@223593 2c7ba8d8
EOTEXT
          , $change->getMetadata('message')
        );
        break;
      case 'git-binary.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $change->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        break;
      case 'git-odd-filename.gitdiff':
        $this->assertEqual(2, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          'old/'."\342\210\206".'.jpg',
          $change->getOldPath());
        $this->assertEqual(
          'new/'."\342\210\206".'.jpg',
          $change->getCurrentPath());
        break;
      case 'hg-binary-change.hgdiff':
      case 'hg-solo-binary-change.hgdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $change->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        break;
      case 'hg-binary-delete.hgdiff':
        $this->assertEqual(1, count($changes));
        $change = reset($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_DELETE,
          $change->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $change->getFileType());
        break;
      case 'git-replace-symlink.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = array_shift($changes);
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $change->getType());
        break;
      case 'svn-1.7-property-added.svndiff':
        $this->assertEqual(1, count($changes));
        $change = head($changes);
        $new_properties = $change->getNewProperties();
        $this->assertEqual(2, count($new_properties));
        $this->assertEqual('*', idx($new_properties, 'svn:executable'));
        $this->assertEqual('text/html', idx($new_properties, 'svn:mime-type'));
        break;
      case 'hg-diff-range.hgdiff':
        $this->assertEqual(1, count($changes));
        $change = array_shift($changes);
        $this->assertEqual(
          'Test.java',
          $change->getOldPath());
        $this->assertEqual(
          'Test.java',
          $change->getCurrentPath());
        break;
      case 'hg-patch.hgdiff':
        $this->assertEqual(1, count($changes));
        break;
      case 'hg-patch-git.hgdiff':
        $this->assertEqual(1, count($changes));
        break;
      case 'custom-prefixes.gitdiff':
        $this->assertEqual(1, count($changes));
        $change = head($changes);
        $this->assertEqual(
          'dst/file',
          $change->getCurrentPath());
        break;
      case 'more-newlines.svndiff':
        $this->assertEqual(1, count($changes));
        break;
      case 'suppress-blank-empty.gitdiff':
        $this->assertEqual(1, count($changes));
        break;
      default:
        throw new Exception("No test block for diff file {$diff_file}.");
        break;
    }
  }

  public function testGitPrefixStripping() {
    static $tests = array(
      'a/file.c'    => 'file.c',
      'b/file.c'    => 'file.c',
      'i/file.c'    => 'file.c',
      'c/file.c'    => 'file.c',
      'w/file.c'    => 'file.c',
      'o/file.c'    => 'file.c',
      '1/file.c'    => 'file.c',
      '2/file.c'    => 'file.c',
      'src/file.c'  => 'src/file.c',
      'file.c'      => 'file.c',
    );

    foreach ($tests as $input => $expect) {
      $this->assertEqual(
        $expect,
        ArcanistDiffParser::stripGitPathPrefix($input),
        "Strip git prefix from '{$input}'.");
    }
  }

  public function testGitPathSplitting() {
    static $tests = array(
      "a/old.c b/new.c"       => array('old.c', 'new.c'),
      "a/old.c b/new.c\n"     => array('old.c', 'new.c'),
      "a/old.c b/new.c\r\n"   => array('old.c', 'new.c'),
      "old.c new.c"           => array('old.c', 'new.c'),
      "1/old.c 2/new.c"       => array('old.c', 'new.c'),
      '"a/\\"quotes1\\"" "b/\\"quotes2\\""' => array(
        '"quotes1"',
        '"quotes2"',
      ),
      '"a/\\"quotes and spaces1\\"" "b/\\"quotes and spaces2\\""' => array(
        '"quotes and spaces1"',
        '"quotes and spaces2"',
      ),
      '"a/\\342\\230\\2031" "b/\\342\\230\\2032"' => array(
        "\xE2\x98\x831",
        "\xE2\x98\x832",
      ),
      "a/Core Data/old.c b/Core Data/new.c" => array(
        'Core Data/old.c',
        'Core Data/new.c',
      ),
      "some file with spaces.c some file with spaces.c" => array(
        'some file with spaces.c',
        'some file with spaces.c',
      ),
    );

    foreach ($tests as $input => $expect) {
      $result = ArcanistDiffParser::splitGitDiffPaths($input);
      $this->assertEqual(
        $expect,
        $result,
        "Split: {$input}");
    }


    static $ambiguous = array(
      "old file with spaces.c new file with spaces.c",
    );

    foreach ($ambiguous as $input) {
      $caught = null;
      try {
        ArcanistDiffParser::splitGitDiffPaths($input);
      } catch (Exception $ex) {
        $caught = $ex;
      }
      $this->assertEqual(
        true,
        ($caught instanceof Exception),
        "Ambiguous: {$input}");
    }
  }

}
