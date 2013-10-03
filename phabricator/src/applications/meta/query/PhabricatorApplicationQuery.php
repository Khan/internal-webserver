<?php

final class PhabricatorApplicationQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $installed;
  private $beta;
  private $firstParty;
  private $nameContains;
  private $classes;
  private $phids;

  const ORDER_APPLICATION = 'order:application';
  const ORDER_NAME = 'order:name';

  private $order = self::ORDER_APPLICATION;

  public function withNameContains($name_contains) {
    $this->nameContains = $name_contains;
    return $this;
  }

  public function withInstalled($installed) {
    $this->installed = $installed;
    return $this;
  }

  public function withBeta($beta) {
    $this->beta = $beta;
    return $this;
  }

  public function withFirstParty($first_party) {
    $this->firstParty = $first_party;
    return $this;
  }

  public function withClasses(array $classes) {
    $this->classes = $classes;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function setOrder($order) {
    $this->order = $order;
    return $this;
  }

  public function loadPage() {
    $apps = PhabricatorApplication::getAllApplications();

    if ($this->classes) {
      $classes = array_fuse($this->classes);
      foreach ($apps as $key => $app) {
        if (empty($classes[get_class($app)])) {
          unset($apps[$key]);
        }
      }
    }

    if ($this->phids) {
      $phids = array_fuse($this->phids);
      foreach ($apps as $key => $app) {
        if (empty($phids[$app->getPHID()])) {
          unset($apps[$key]);
        }
      }
    }

    if (strlen($this->nameContains)) {
      foreach ($apps as $key => $app) {
        if (stripos($app->getName(), $this->nameContains) === false) {
          unset($apps[$key]);
        }
      }
    }

    if ($this->installed !== null) {
      foreach ($apps as $key => $app) {
        if ($app->isInstalled() != $this->installed) {
          unset($apps[$key]);
        }
      }
    }

    if ($this->beta !== null) {
      foreach ($apps as $key => $app) {
        if ($app->isBeta() != $this->beta) {
          unset($apps[$key]);
        }
      }
    }

    if ($this->firstParty !== null) {
      foreach ($apps as $key => $app) {
        if ($app->isFirstParty() != $this->firstParty) {
          unset($apps[$key]);
        }
      }
    }

    switch ($this->order) {
      case self::ORDER_NAME:
        $apps = msort($apps, 'getName');
        break;
      case self::ORDER_APPLICATION:
        $apps = $apps;
        break;
      default:
        throw new Exception(
          pht('Unknown order "%s"!', $this->order));
    }

    return $apps;
  }

}
