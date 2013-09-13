<?php

/**
 * @group slowvote
 */
final class SlowvoteEmbedView extends AphrontView {

  private $poll;
  private $handles;
  private $headless;

  public function setHeadless($headless) {
    $this->headless = $headless;
    return $this;
  }

  public function setPoll(PhabricatorSlowvotePoll $poll) {
    $this->poll = $poll;
    return $this;
  }

  public function getPoll() {
    return $this->poll;
  }

  public function render() {
    if (!$this->poll) {
      throw new Exception("Call setPoll() before render()!");
    }

    $poll = $this->poll;

    $phids = array();
    foreach ($poll->getChoices() as $choice) {
      $phids[] = $choice->getAuthorPHID();
    }
    $phids[] = $poll->getAuthorPHID();

    $this->handles = id(new PhabricatorHandleQuery())
      ->setViewer($this->getUser())
      ->withPHIDs($phids)
      ->execute();

    $options = $poll->getOptions();

    if ($poll->getShuffle()) {
      shuffle($options);
    }

    require_celerity_resource('phabricator-slowvote-css');
    require_celerity_resource('javelin-behavior-slowvote-embed');

    $config = array(
      'pollID' => $poll->getID());
    Javelin::initBehavior('slowvote-embed', $config);

    $user_choices = $poll->getViewerChoices($this->getUser());
    $user_choices = mpull($user_choices, 'getOptionID', 'getOptionID');

    $out = array();
    foreach ($options as $option) {
      $is_selected = isset($user_choices[$option->getID()]);
      $out[] = $this->renderLabel($option, $is_selected);
    }

    $link_to_slowvote = phutil_tag(
      'a',
      array(
        'href' => '/V'.$poll->getID()
      ),
      $poll->getQuestion());

    if ($this->headless) {
      $header = null;
    } else {
      $header = phutil_tag(
        'div',
        array(
          'class' => 'slowvote-header',
        ),
        phutil_tag(
          'div',
          array(
            'class' => 'slowvote-header-content',
          ),
          array(
            'V'.$poll->getID(),
            ' ',
            $link_to_slowvote)));

      $description = null;
      if ($poll->getDescription()) {
        $description = PhabricatorMarkupEngine::renderOneObject(
          id(new PhabricatorMarkupOneOff())->setContent(
            $poll->getDescription()),
          'default',
          $this->getUser());
        $description = phutil_tag(
          'div',
          array(
            'class' => 'slowvote-description',
          ),
          $description);
      }

      $header = array(
        $header,
        $description);
    }

    $vis = $poll->getResponseVisibility();
    if ($this->areResultsVisible()) {
      if ($vis == PhabricatorSlowvotePoll::RESPONSES_OWNER) {
        $quip = pht('Only you can see the results.');
      } else {
        $quip = pht('Voting improves cardiovascular endurance.');
      }
    } else if ($vis == PhabricatorSlowvotePoll::RESPONSES_VOTERS) {
      $quip = pht('You must vote to see the results.');
    } else if ($vis == PhabricatorSlowvotePoll::RESPONSES_OWNER) {
      $quip = pht('Only the author can see the results.');
    }

    $hint = phutil_tag(
      'span',
      array(
        'class' => 'slowvote-hint',
      ),
      $quip);

    $submit = phutil_tag(
      'div',
      array(
        'class' => 'slowvote-footer',
      ),
      phutil_tag(
        'div',
        array(
          'class' => 'slowvote-footer-content',
        ),
        array(
          $hint,
          phutil_tag(
            'button',
            array(
            ),
            pht('Engage in Deliberations')),
        )));

    $body = phabricator_form(
      $this->getUser(),
      array(
        'action'  => '/vote/'.$poll->getID().'/',
        'method'  => 'POST',
        'class'   => 'slowvote-body',
      ),
      array(
        phutil_tag(
          'div',
          array(
            'class' => 'slowvote-body-content',
          ),
          $out),
        $submit,
      ));

    return javelin_tag(
      'div',
      array(
        'class' => 'slowvote-embed',
        'sigil' => 'slowvote-embed',
        'meta' => array(
          'pollID' => $poll->getID()
        )
      ),
      array($header, $body));
  }

  private function renderLabel(PhabricatorSlowvoteOption $option, $selected) {
    $classes = array();
    $classes[] = 'slowvote-option-label';

    $status = $this->renderStatus($option);
    $voters = $this->renderVoters($option);

    return phutil_tag(
      'div',
      array(
        'class' => 'slowvote-option-label-group',
      ),
      array(
        phutil_tag(
          'label',
          array(
            'class' => implode(' ', $classes),
          ),
          array(
            phutil_tag(
              'div',
              array(
                'class' => 'slowvote-control-offset',
              ),
              $option->getName()),
            $this->renderBar($option),
            phutil_tag(
              'div',
              array(
                'class' => 'slowvote-above-the-bar',
              ),
              array(
                $this->renderControl($option, $selected),
              )),
          )),
        $status,
        $voters,
      ));
  }

  private function renderBar(PhabricatorSlowvoteOption $option) {
    if (!$this->areResultsVisible()) {
      return null;
    }

    $poll = $this->getPoll();

    $choices = mgroup($poll->getChoices(), 'getOptionID');
    $choices = count(idx($choices, $option->getID(), array()));
    $count = count(mgroup($poll->getChoices(), 'getAuthorPHID'));

    return phutil_tag(
      'div',
      array(
        'class' => 'slowvote-bar',
        'style' => sprintf(
          'width: %.1f%%;',
          $count ? 100 * ($choices / $count) : 0),
      ),
      array(
        phutil_tag(
          'div',
          array(
            'class' => 'slowvote-control-offset',
          ),
          $option->getName()),
      ));
  }

  private function renderControl(PhabricatorSlowvoteOption $option, $selected) {
    $types = array(
      PhabricatorSlowvotePoll::METHOD_PLURALITY => 'radio',
      PhabricatorSlowvotePoll::METHOD_APPROVAL => 'checkbox',
    );

    return phutil_tag(
      'input',
      array(
        'type' => idx($types, $this->getPoll()->getMethod()),
        'name' => 'vote[]',
        'value' => $option->getID(),
        'checked' => ($selected ? 'checked' : null),
      ));
  }

  private function renderVoters(PhabricatorSlowvoteOption $option) {
    if (!$this->areResultsVisible()) {
      return null;
    }

    $poll = $this->getPoll();

    $choices = mgroup($poll->getChoices(), 'getOptionID');
    $choices = idx($choices, $option->getID(), array());

    if (!$choices) {
      return null;
    }

    $handles = $this->handles;
    $authors = mpull($choices, 'getAuthorPHID', 'getAuthorPHID');

    $viewer_phid = $this->getUser()->getPHID();

    // Put the viewer first if they've voted for this option.
    $authors = array_select_keys($authors, array($viewer_phid))
             + $authors;

    $voters = array();
    foreach ($authors as $author_phid) {
      $handle = $handles[$author_phid];

      $voters[] = javelin_tag(
        'div',
        array(
          'class' => 'slowvote-voter',
          'style' => 'background-image: url('.$handle->getImageURI().')',
          'sigil' => 'has-tooltip',
          'meta' => array(
            'tip' => $handle->getName(),
          ),
        ));
    }

    return phutil_tag(
      'div',
      array(
        'class' => 'slowvote-voters',
      ),
      $voters);
  }

  private function renderStatus(PhabricatorSlowvoteOption $option) {
    if (!$this->areResultsVisible()) {
      return null;
    }

    $poll = $this->getPoll();

    $choices = mgroup($poll->getChoices(), 'getOptionID');
    $choices = count(idx($choices, $option->getID(), array()));
    $count = count(mgroup($poll->getChoices(), 'getAuthorPHID'));

    $percent = sprintf('%d%%', $count ? 100 * $choices / $count : 0);

    switch ($poll->getMethod()) {
      case PhabricatorSlowvotePoll::METHOD_PLURALITY:
        $status = pht('%s (%d / %d)', $percent, $choices, $count);
        break;
      case PhabricatorSlowvotePoll::METHOD_APPROVAL:
        $status = pht('%s Approval (%d / %d)', $percent, $choices, $count);
        break;
    }

    return phutil_tag(
      'div',
      array(
        'class' => 'slowvote-status',
      ),
      $status);
  }

  private function areResultsVisible() {
    $poll = $this->getPoll();

    $vis = $poll->getResponseVisibility();
    if ($vis == PhabricatorSlowvotePoll::RESPONSES_VISIBLE) {
      return true;
    } else if ($vis == PhabricatorSlowvotePoll::RESPONSES_OWNER) {
      return ($poll->getAuthorPHID() == $this->getUser()->getPHID());
    } else {
      $choices = mgroup($poll->getChoices(), 'getAuthorPHID');
      return (bool)idx($choices, $this->getUser()->getPHID());
    }
  }

}
