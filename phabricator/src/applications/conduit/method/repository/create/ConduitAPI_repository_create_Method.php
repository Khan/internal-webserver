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
 * @group conduit
 */
final class ConduitAPI_repository_create_Method
  extends ConduitAPI_repository_Method {

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodStatusDescription() {
    return "Repository methods are new and subject to change.";
  }

  public function getMethodDescription() {
    return "Create a new repository (Admin Only).";
  }

  public function defineParamTypes() {
    return array(
      'name'          => 'required string',
      'vcs'           => 'required enum<git, hg, svn>',
      'callsign'      => 'required string',
      'encoding'      => 'optional string',
      'tracking'      => 'optional bool',
      'uri'           => 'optional string',
      'sshUser'       => 'optional string',
      'sshKey'        => 'optional string',
      'sshKeyFile'    => 'optional string',
      'httpUser'      => 'optional string',
      'httpPassword'  => 'optional string',
      'localPath'     => 'optional string',
      'svnSubpath'    => 'optional string',
      'branchFilter'  => 'optional list<string>',
      'pullFrequency' => 'optional int',
      'defaultBranch' => 'optional string',
      'heraldEnabled' => 'optional bool',
      'svnUUID'       => 'optional string',
    );
  }

  public function defineReturnType() {
    return 'nonempty dict';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-PERMISSIONS' =>
        'You do not have the authority to call this method.',
      'ERR-DUPLICATE'   =>
        'Duplicate repository callsign.',
      'ERR-BAD-CALLSIGN' =>
        'Callsign is required and must be ALL UPPERCASE LETTERS.',
      'ERR-UNKNOWN-REPOSITORY-VCS' =>
        'Unknown repository VCS type.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    if (!$request->getUser()->getIsAdmin()) {
      throw new ConduitException('ERR-PERMISSIONS');
    }

    // TODO: This has some duplication with (and lacks some of the validation
    // of) the web workflow; refactor things so they can share more code as this
    // stabilizes.

    $repository = new PhabricatorRepository();
    $repository->setName($request->getValue('name'));

    $callsign = $request->getValue('callsign');
    if (!preg_match('/[A-Z]+$/', $callsign)) {
      throw new ConduitException('ERR-BAD-CALLSIGN');
    }
    $repository->setCallsign($callsign);

    $vcs = $request->getValue('vcs');

    $map = array(
      'git' => PhabricatorRepositoryType::REPOSITORY_TYPE_GIT,
      'hg'  => PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL,
      'svn' => PhabricatorRepositoryType::REPOSITORY_TYPE_SVN,
    );
    if (empty($map[$vcs])) {
      throw new ConduitException('ERR-UNKNOWN-REPOSITORY-VCS');
    }
    $repository->setVersionControlSystem($map[$vcs]);

    $details = array(
      'encoding'          => $request->getValue('encoding'),
      'tracking-enabled'  => (bool)$request->getValue('tracking', true),
      'remote-uri'        => $request->getValue('uri'),
      'local-path'        => $request->getValue('localPath'),
      'branch-filter'     => array_fill_keys(
        $request->getValue('branchFilter', array()),
        true),
      'pull-frequency'    => $request->getValue('pullFrequency'),
      'default-branch'    => $request->getValue('defaultBranch'),
      'ssh-login'         => $request->getValue('sshUser'),
      'ssh-key'           => $request->getValue('sshKey'),
      'ssh-keyfile'       => $request->getValue('sshKeyFile'),
      'herald-disabled'   => !$request->getValue('heraldEnabled', true),
      'svn-subpath'       => $request->getValue('svnSubpath'),
    );

    foreach ($details as $key => $value) {
      $repository->setDetail($key, $value);
    }

    try {
      $repository->save();
    } catch (AphrontQueryDuplicateKeyException $ex) {
      throw new ConduitException('ERR-DUPLICATE');
    }

    return $this->buildDictForRepository($repository);
  }


}
