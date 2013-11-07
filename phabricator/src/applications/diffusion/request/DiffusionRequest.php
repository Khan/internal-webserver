<?php

/**
 * Contains logic to parse Diffusion requests, which have a complicated URI
 * structure.
 *
 *
 * @task new Creating Requests
 * @task uri Managing Diffusion URIs
 *
 * @group diffusion
 */
abstract class DiffusionRequest {

  protected $callsign;
  protected $path;
  protected $line;
  protected $symbolicCommit;
  protected $commit;
  protected $commitType = 'commit';
  protected $branch;
  protected $lint;

  protected $repository;
  protected $repositoryCommit;
  protected $repositoryCommitData;
  protected $stableCommitName;
  protected $arcanistProjects;

  private $initFromConduit = true;
  private $user;

  abstract protected function getSupportsBranches();
  abstract protected function didInitialize();


/* -(  Creating Requests  )-------------------------------------------------- */


  /**
   * Create a new synthetic request from a parameter dictionary. If you need
   * a @{class:DiffusionRequest} object in order to issue a DiffusionQuery, you
   * can use this method to build one.
   *
   * Parameters are:
   *
   *   - `callsign` Repository callsign. Provide this or `repository`.
   *   - `user` Viewing user. Required if `callsign` is provided.
   *   - `repository` Repository object. Provide this or `callsign`.
   *   - `branch` Optional, branch name.
   *   - `path` Optional, file path.
   *   - `commit` Optional, commit identifier.
   *   - `line` Optional, line range.
   *
   * @param   map                 See documentation.
   * @return  DiffusionRequest    New request object.
   * @task new
   */
  final public static function newFromDictionary(array $data) {
    if (isset($data['repository']) && isset($data['callsign'])) {
      throw new Exception(
        "Specify 'repository' or 'callsign', but not both.");
    } else if (!isset($data['repository']) && !isset($data['callsign'])) {
      throw new Exception(
        "One of 'repository' and 'callsign' is required.");
    } else if (isset($data['callsign']) && empty($data['user'])) {
      throw new Exception(
        "Parameter 'user' is required if 'callsign' is provided.");
    }

    if (isset($data['repository'])) {
      $object = self::newFromRepository($data['repository']);
    } else {
      $object = self::newFromCallsign($data['callsign'], $data['user']);
    }

    $object->initializeFromDictionary($data);

    return $object;
  }


  /**
   * Create a new request from an Aphront request dictionary. This is an
   * internal method that you generally should not call directly; instead,
   * call @{method:newFromDictionary}.
   *
   * @param   map                 Map of Aphront request data.
   * @return  DiffusionRequest    New request object.
   * @task new
   */
  final public static function newFromAphrontRequestDictionary(
    array $data,
    AphrontRequest $request) {

    $callsign = phutil_unescape_uri_path_component(idx($data, 'callsign'));
    $object = self::newFromCallsign($callsign, $request->getUser());

    $use_branches = $object->getSupportsBranches();
    $parsed = self::parseRequestBlob(idx($data, 'dblob'), $use_branches);

    $object->setUser($request->getUser());
    $object->initializeFromDictionary($parsed);
    $object->lint = $request->getStr('lint');
    return $object;
  }


  /**
   * Internal.
   *
   * @task new
   */
  final private function __construct() {
    // <private>
  }


  /**
   * Internal. Use @{method:newFromDictionary}, not this method.
   *
   * @param   string              Repository callsign.
   * @param   PhabricatorUser     Viewing user.
   * @return  DiffusionRequest    New request object.
   * @task new
   */
  final private static function newFromCallsign(
    $callsign,
    PhabricatorUser $viewer) {

    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->withCallsigns(array($callsign))
      ->executeOne();
    if (!$repository) {
      throw new Exception("No such repository '{$callsign}'.");
    }

    return self::newFromRepository($repository);
  }


  /**
   * Internal. Use @{method:newFromDictionary}, not this method.
   *
   * @param   PhabricatorRepository   Repository object.
   * @return  DiffusionRequest        New request object.
   * @task new
   */
  final private static function newFromRepository(
    PhabricatorRepository $repository) {

    $map = array(
      PhabricatorRepositoryType::REPOSITORY_TYPE_GIT => 'DiffusionGitRequest',
      PhabricatorRepositoryType::REPOSITORY_TYPE_SVN => 'DiffusionSvnRequest',
      PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL =>
        'DiffusionMercurialRequest',
    );

    $class = idx($map, $repository->getVersionControlSystem());

    if (!$class) {
      throw new Exception("Unknown version control system!");
    }

    $object = new $class();

    $object->repository = $repository;
    $object->callsign   = $repository->getCallsign();

    return $object;
  }


  /**
   * Internal. Use @{method:newFromDictionary}, not this method.
   *
   * @param map Map of parsed data.
   * @return void
   * @task new
   */
  final private function initializeFromDictionary(array $data) {
    $this->path            = idx($data, 'path');
    $this->symbolicCommit  = idx($data, 'commit');
    $this->commit          = idx($data, 'commit');
    $this->line            = idx($data, 'line');
    $this->initFromConduit = idx($data, 'initFromConduit', true);

    if ($this->getSupportsBranches()) {
      $this->branch = idx($data, 'branch');
    }

    if (!$this->getUser()) {
      $user = idx($data, 'user');
      if (!$user) {
        throw new Exception(
          'You must provide a PhabricatorUser in the dictionary!');
      }
      $this->setUser($user);
    }

    $this->didInitialize();
  }

  final protected function shouldInitFromConduit() {
    return $this->initFromConduit;
  }

  final public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }
  final public function getUser() {
    return $this->user;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function getCallsign() {
    return $this->callsign;
  }

  public function setPath($path) {
    $this->path = $path;
    return $this;
  }

  public function getPath() {
    return $this->path;
  }

  public function getLine() {
    return $this->line;
  }

  public function getCommit() {
    return $this->commit;
  }

  public function getSymbolicCommit() {
    return $this->symbolicCommit;
  }

  public function getBranch() {
    return $this->branch;
  }

  public function getLint() {
    return $this->lint;
  }

  protected function getArcanistBranch() {
    return $this->getBranch();
  }

  public function loadBranch() {
    return id(new PhabricatorRepositoryBranch())->loadOneWhere(
      'repositoryID = %d AND name = %s',
      $this->getRepository()->getID(),
      $this->getArcanistBranch());
  }

  public function loadCommit() {
    if (empty($this->repositoryCommit)) {
      $repository = $this->getRepository();

      // TODO: (T603) This should be a real query, but we need to sort out
      // the viewer.
      $commit = id(new PhabricatorRepositoryCommit())->loadOneWhere(
        'repositoryID = %d AND commitIdentifier = %s',
        $repository->getID(),
        $this->getCommit());
      if ($commit) {
        $commit->attachRepository($repository);
      }
      $this->repositoryCommit = $commit;
    }
    return $this->repositoryCommit;
  }

  public function loadArcanistProjects() {
    if (empty($this->arcanistProjects)) {
      $projects = id(new PhabricatorRepositoryArcanistProject())->loadAllWhere(
        'repositoryID = %d',
        $this->getRepository()->getID());
      $this->arcanistProjects = $projects;
    }
    return $this->arcanistProjects;
  }

  public function loadCommitData() {
    if (empty($this->repositoryCommitData)) {
      $commit = $this->loadCommit();
      $data = id(new PhabricatorRepositoryCommitData())->loadOneWhere(
        'commitID = %d',
        $commit->getID());
      if (!$data) {
        $data = new PhabricatorRepositoryCommitData();
        $data->setCommitMessage(
          '(This commit has not been fully parsed yet.)');
      }
      $this->repositoryCommitData = $data;
    }
    return $this->repositoryCommitData;
  }

  /**
   * Retrieve a stable, permanent commit name. This returns a non-symbolic
   * identifier for the current commit: e.g., a specific commit hash in git
   * (NOT a symbolic name like "origin/master") or a specific revision number
   * in SVN (NOT a symbolic name like "HEAD").
   *
   * @return string Stable commit name, like a git hash or SVN revision. Not
   *                a symbolic commit reference.
   */
  public function getStableCommitName() {
    if (!$this->stableCommitName) {
      $this->queryStableCommitName();
    }
    return $this->stableCommitName;
  }

  final public function getRawCommit() {
    return $this->commit;
  }

  public function setCommit($commit) {
    $this->commit = $commit;
    return $this;
  }

/* -(  Managing Diffusion URIs  )-------------------------------------------- */


  /**
   * Generate a Diffusion URI using this request to provide defaults. See
   * @{method:generateDiffusionURI} for details. This method is the same, but
   * preserves the request parameters if they are not overridden.
   *
   * @param   map         See @{method:generateDiffusionURI}.
   * @return  PhutilURI   Generated URI.
   * @task uri
   */
  public function generateURI(array $params) {
    if (empty($params['stable'])) {
      $default_commit = $this->getRawCommit();
    } else {
      $default_commit = $this->getStableCommitName();
    }

    $defaults = array(
      'callsign'  => $this->getCallsign(),
      'path'      => $this->getPath(),
      'branch'    => $this->getBranch(),
      'commit'    => $default_commit,
      'lint'      => idx($params, 'lint', $this->getLint()),
    );
    foreach ($defaults as $key => $val) {
      if (!isset($params[$key])) { // Overwrite NULL.
        $params[$key] = $val;
      }
    }
    return self::generateDiffusionURI($params);
  }


  /**
   * Generate a Diffusion URI from a parameter map. Applies the correct encoding
   * and formatting to the URI. Parameters are:
   *
   *   - `action` One of `history`, `browse`, `change`, `lastmodified`,
   *     `branch`, `tags`, `branches`,  or `revision-ref`. The action specified
   *      by the URI.
   *   - `callsign` Repository callsign.
   *   - `branch` Optional if action is not `branch`, branch name.
   *   - `path` Optional, path to file.
   *   - `commit` Optional, commit identifier.
   *   - `line` Optional, line range.
   *   - `lint` Optional, lint code.
   *   - `params` Optional, query parameters.
   *
   * The function generates the specified URI and returns it.
   *
   * @param   map         See documentation.
   * @return  PhutilURI   Generated URI.
   * @task uri
   */
  public static function generateDiffusionURI(array $params) {
    $action = idx($params, 'action');

    $callsign = idx($params, 'callsign');
    $path     = idx($params, 'path');
    $branch   = idx($params, 'branch');
    $commit   = idx($params, 'commit');
    $line     = idx($params, 'line');

    if (strlen($callsign)) {
      $callsign = phutil_escape_uri_path_component($callsign).'/';
    }

    if (strlen($branch)) {
      $branch = phutil_escape_uri_path_component($branch).'/';
    }

    if (strlen($path)) {
      $path = ltrim($path, '/');
      $path = str_replace(array(';', '$'), array(';;', '$$'), $path);
      $path = phutil_escape_uri($path);
    }

    $path = "{$branch}{$path}";

    if (strlen($commit)) {
      $commit = str_replace('$', '$$', $commit);
      $commit = ';'.phutil_escape_uri($commit);
    }

    if (strlen($line)) {
      $line = '$'.phutil_escape_uri($line);
    }

    $req_callsign = false;
    $req_branch   = false;
    $req_commit   = false;

    switch ($action) {
      case 'history':
      case 'browse':
      case 'change':
      case 'lastmodified':
      case 'tags':
      case 'branches':
      case 'lint':
        $req_callsign = true;
        break;
      case 'branch':
        $req_callsign = true;
        $req_branch = true;
        break;
      case 'commit':
        $req_callsign = true;
        $req_commit = true;
        break;
    }

    if ($req_callsign && !strlen($callsign)) {
      throw new Exception(
        "Diffusion URI action '{$action}' requires callsign!");
    }

    if ($req_commit && !strlen($commit)) {
      throw new Exception(
        "Diffusion URI action '{$action}' requires commit!");
    }

    switch ($action) {
      case 'change':
      case 'history':
      case 'browse':
      case 'lastmodified':
      case 'tags':
      case 'branches':
      case 'lint':
        $uri = "/diffusion/{$callsign}{$action}/{$path}{$commit}{$line}";
        break;
      case 'branch':
        if (strlen($path)) {
          $uri = "/diffusion/{$callsign}repository/{$path}";
        } else {
          $uri = "/diffusion/{$callsign}";
        }
        break;
      case 'external':
        $commit = ltrim($commit, ';');
        $uri = "/diffusion/external/{$commit}/";
        break;
      case 'rendering-ref':
        // This isn't a real URI per se, it's passed as a query parameter to
        // the ajax changeset stuff but then we parse it back out as though
        // it came from a URI.
        $uri = rawurldecode("{$path}{$commit}");
        break;
      case 'commit':
        $commit = ltrim($commit, ';');
        $callsign = rtrim($callsign, '/');
        $uri = "/r{$callsign}{$commit}";
        break;
      default:
        throw new Exception("Unknown Diffusion URI action '{$action}'!");
    }

    if ($action == 'rendering-ref') {
      return $uri;
    }

    $uri = new PhutilURI($uri);

    if (isset($params['lint'])) {
      $params['params'] = idx($params, 'params', array()) + array(
        'lint' => $params['lint'],
      );
    }

    if (idx($params, 'params')) {
      $uri->setQueryParams($params['params']);
    }

    return $uri;
  }


  /**
   * Internal. Public only for unit tests.
   *
   * Parse the request URI into components.
   *
   * @param   string  URI blob.
   * @param   bool    True if this VCS supports branches.
   * @return  map     Parsed URI.
   *
   * @task uri
   */
  public static function parseRequestBlob($blob, $supports_branches) {
    $result = array(
      'branch'  => null,
      'path'    => null,
      'commit'  => null,
      'line'    => null,
    );

    $matches = null;

    if ($supports_branches) {
      // Consume the front part of the URI, up to the first "/". This is the
      // path-component encoded branch name.
      if (preg_match('@^([^/]+)/@', $blob, $matches)) {
        $result['branch'] = phutil_unescape_uri_path_component($matches[1]);
        $blob = substr($blob, strlen($matches[1]) + 1);
      }
    }

    // Consume the back part of the URI, up to the first "$". Use a negative
    // lookbehind to prevent matching '$$'. We double the '$' symbol when
    // encoding so that files with names like "money/$100" will survive.
    $pattern = '@(?:(?:^|[^$])(?:[$][$])*)[$]([\d-,]+)$@';
    if (preg_match($pattern, $blob, $matches)) {
      $result['line'] = $matches[1];
      $blob = substr($blob, 0, -(strlen($matches[1]) + 1));
    }

    // We've consumed the line number if it exists, so unescape "$" in the
    // rest of the string.
    $blob = str_replace('$$', '$', $blob);

    // Consume the commit name, stopping on ';;'. We allow any character to
    // appear in commits names, as they can sometimes be symbolic names (like
    // tag names or refs).
    if (preg_match('@(?:(?:^|[^;])(?:;;)*);([^;].*)$@', $blob, $matches)) {
      $result['commit'] = $matches[1];
      $blob = substr($blob, 0, -(strlen($matches[1]) + 1));
    }

    // We've consumed the commit if it exists, so unescape ";" in the rest
    // of the string.
    $blob = str_replace(';;', ';', $blob);

    if (strlen($blob)) {
      $result['path'] = $blob;
    }

    $parts = explode('/', $result['path']);
    foreach ($parts as $part) {
      // Prevent any hyjinx since we're ultimately shipping this to the
      // filesystem under a lot of workflows.
      if ($part == '..') {
        throw new Exception("Invalid path URI.");
      }
    }

    return $result;
  }

  /**
   * Check that the working copy of the repository is present and readable.
   *
   * @param   string  Path to the working copy.
   */
  protected function validateWorkingCopy($path) {
    if (!is_readable(dirname($path))) {
      $this->raisePermissionException();
    }

    if (!Filesystem::pathExists($path)) {
      $this->raiseCloneException();
    }
  }

  protected function raisePermissionException() {
    $host = php_uname('n');
    $callsign = $this->getRepository()->getCallsign();
    throw new DiffusionSetupException(
      "The clone of this repository ('{$callsign}') on the local machine " .
      "('{$host}') could not be read. Ensure that the repository is in a " .
      "location where the web server has read permissions.");
  }

  protected function raiseCloneException() {
    $host = php_uname('n');
    $callsign = $this->getRepository()->getCallsign();
    throw new DiffusionSetupException(
      "The working copy for this repository ('{$callsign}') hasn't been ".
      "cloned yet on this machine ('{$host}'). Make sure you've started the ".
      "Phabricator daemons. If this problem persists for longer than a clone ".
      "should take, check the daemon logs (in the Daemon Console) to see if ".
      "there were errors cloning the repository. Consult the 'Diffusion User ".
      "Guide' in the documentation for help setting up repositories.");
  }

  final protected function expandCommitName() {
    $results = $this->resolveRefs(array($this->commit));
    $matches = idx($results, $this->commit, array());
    if (count($results) !== 1) {
      throw new Exception(
        pht('Ref "%s" is ambiguous or does not exist.', $this->commit));
    }

    $match = head($matches);

    $this->commit = $match['identifier'];
    $this->commitType = $match['type'];
  }

  public function getCommitType() {
    return $this->commitType;
  }

  private function queryStableCommitName() {
    if ($this->commit) {
      $this->stableCommitName = $this->commit;
      return $this->stableCommitName;
    }

    if ($this->getSupportsBranches()) {
      $branch = $this->getResolvableBranchName($this->getBranch());
    } else {
      $branch = 'HEAD';
    }

    $results = $this->resolveRefs(array($branch));

    $matches = idx($results, $branch, array());
    if (count($matches) !== 1) {
      throw new Exception(
        pht('Ref "%s" is ambiguous or does not exist.', $branch));
    }

    $this->stableCommitName = idx(head($matches), 'identifier');
    return $this->stableCommitName;
  }

  protected function getResolvableBranchName($branch) {
    return $branch;
  }

  private function resolveRefs(array $refs) {
    if ($this->shouldInitFromConduit()) {
      return DiffusionQuery::callConduitWithDiffusionRequest(
        $this->getUser(),
        $this,
        'diffusion.resolverefs',
        array(
          'refs' => $refs,
        ));
    } else {
      return id(new DiffusionLowLevelResolveRefsQuery())
        ->setRepository($this->getRepository())
        ->withRefs($refs)
        ->execute();
    }
  }


}
