<?php

final class PhabricatorPolicyQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $object;
  private $phids;

  public function setObject(PhabricatorPolicyInterface $object) {
    $this->object = $object;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public static function loadPolicies(
    PhabricatorUser $viewer,
    PhabricatorPolicyInterface $object) {

    $results = array();

    $map = array();
    foreach ($object->getCapabilities() as $capability) {
      $map[$capability] = $object->getPolicy($capability);
    }

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($viewer)
      ->withPHIDs($map)
      ->execute();

    foreach ($map as $capability => $phid) {
      $results[$capability] = $policies[$phid];
    }

    return $results;
  }

  public static function renderPolicyDescriptions(
    PhabricatorUser $viewer,
    PhabricatorPolicyInterface $object,
    $icon = false) {

    $policies = self::loadPolicies($viewer, $object);

    foreach ($policies as $capability => $policy) {
      $policies[$capability] = $policy->renderDescription($icon);
    }

    return $policies;
  }

  public function loadPage() {
    if ($this->object && $this->phids) {
      throw new Exception(
        "You can not issue a policy query with both setObject() and ".
        "setPHIDs().");
    } else if ($this->object) {
      $phids = $this->loadObjectPolicyPHIDs();
    } else {
      $phids = $this->phids;
    }

    $phids = array_fuse($phids);

    $results = array();

    // First, load global policies.
    foreach ($this->getGlobalPolicies() as $phid => $policy) {
      if (isset($phids[$phid])) {
        $results[$phid] = $policy;
        unset($phids[$phid]);
      }
    }

    // If we still need policies, we're going to have to fetch data. Bucket
    // the remaining policies into rule-based policies and handle-based
    // policies.
    if ($phids) {
      $rule_policies = array();
      $handle_policies = array();
      foreach ($phids as $phid) {
        $phid_type = phid_get_type($phid);
        if ($phid_type == PhabricatorPolicyPHIDTypePolicy::TYPECONST) {
          $rule_policies[$phid] = $phid;
        } else {
          $handle_policies[$phid] = $phid;
        }
      }

      if ($handle_policies) {
        $handles = id(new PhabricatorHandleQuery())
          ->setViewer($this->getViewer())
          ->withPHIDs($handle_policies)
          ->execute();
        foreach ($handle_policies as $phid) {
          $results[$phid] = PhabricatorPolicy::newFromPolicyAndHandle(
            $phid,
            $handles[$phid]);
        }
      }

      if ($rule_policies) {
        $rules = id(new PhabricatorPolicy())->loadAllWhere(
          'phid IN (%Ls)',
          $rule_policies);
        $results += mpull($rules, null, 'getPHID');
      }
    }

    $results = msort($results, 'getSortKey');

    return $results;
  }

  public static function isGlobalPolicy($policy) {
    $globalPolicies = self::getGlobalPolicies();

    if (isset($globalPolicies[$policy])) {
      return true;
    }

    return false;
  }

  public static function getGlobalPolicy($policy) {
    if (!self::isGlobalPolicy($policy)) {
      throw new Exception("Policy '{$policy}' is not a global policy!");
    }
    return idx(self::getGlobalPolicies(), $policy);
  }

  private static function getGlobalPolicies() {
    static $constants = array(
      PhabricatorPolicies::POLICY_PUBLIC,
      PhabricatorPolicies::POLICY_USER,
      PhabricatorPolicies::POLICY_ADMIN,
      PhabricatorPolicies::POLICY_NOONE,
    );

    $results = array();
    foreach ($constants as $constant) {
      $results[$constant] = id(new PhabricatorPolicy())
        ->setType(PhabricatorPolicyType::TYPE_GLOBAL)
        ->setPHID($constant)
        ->setName(self::getGlobalPolicyName($constant))
        ->setShortName(self::getGlobalPolicyShortName($constant))
        ->makeEphemeral();
    }

    return $results;
  }

  private static function getGlobalPolicyName($policy) {
    switch ($policy) {
      case PhabricatorPolicies::POLICY_PUBLIC:
        return pht('Public (No Login Required)');
      case PhabricatorPolicies::POLICY_USER:
        return pht('All Users');
      case PhabricatorPolicies::POLICY_ADMIN:
        return pht('Administrators');
      case PhabricatorPolicies::POLICY_NOONE:
        return pht('No One');
      default:
        return pht('Unknown Policy');
    }
  }

  private static function getGlobalPolicyShortName($policy) {
    switch ($policy) {
      case PhabricatorPolicies::POLICY_PUBLIC:
        return pht('Public');
      default:
        return null;
    }
  }

  private function loadObjectPolicyPHIDs() {
    $phids = array();
    $viewer = $this->getViewer();

    if ($viewer->getPHID()) {
      $projects = id(new PhabricatorProjectQuery())
        ->setViewer($viewer)
        ->withMemberPHIDs(array($viewer->getPHID()))
        ->execute();
      foreach ($projects as $project) {
        $phids[] = $project->getPHID();
      }
    }

    $capabilities = $this->object->getCapabilities();
    foreach ($capabilities as $capability) {
      $policy = $this->object->getPolicy($capability);
      if (!$policy) {
        continue;
      }
      $phids[] = $policy;
    }

    // If this install doesn't have "Public" enabled, don't include it as an
    // option unless the object already has a "Public" policy. In this case we
    // retain the policy but enforce it as though it was "All Users".
    $show_public = PhabricatorEnv::getEnvConfig('policy.allow-public');
    foreach ($this->getGlobalPolicies() as $phid => $policy) {
      if ($phid == PhabricatorPolicies::POLICY_PUBLIC) {
        if (!$show_public) {
          continue;
        }
      }
      $phids[] = $phid;
    }

    return $phids;
  }

  protected function shouldDisablePolicyFiltering() {
    // Policy filtering of policies is currently perilous and not required by
    // the application.
    return true;
  }


  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationPolicy';
  }

}

