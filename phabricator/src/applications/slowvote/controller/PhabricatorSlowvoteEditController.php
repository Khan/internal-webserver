<?php

/**
 * @group slowvote
 */
final class PhabricatorSlowvoteEditController
  extends PhabricatorSlowvoteController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    if ($this->id) {
      $poll = id(new PhabricatorSlowvoteQuery())
        ->setViewer($user)
        ->withIDs(array($this->id))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->executeOne();
      if (!$poll) {
        return new Aphront404Response();
      }
      $is_new = false;
    } else {
      $poll = PhabricatorSlowvotePoll::initializeNewPoll($user);
      $is_new = true;
    }

    $e_question = true;
    $e_response = true;
    $errors = array();

    $v_question = $poll->getQuestion();
    $v_description = $poll->getDescription();
    $v_responses = $poll->getResponseVisibility();
    $v_shuffle = $poll->getShuffle();

    $responses = $request->getArr('response');
    if ($request->isFormPost()) {
      $v_question = $request->getStr('question');
      $v_description = $request->getStr('description');
      $v_responses = (int)$request->getInt('responses');
      $v_shuffle = (int)$request->getBool('shuffle');
      $v_view_policy = $request->getStr('viewPolicy');

      if ($is_new) {
        $poll->setMethod($request->getInt('method'));
      }

      if (!strlen($v_question)) {
        $e_question = pht('Required');
        $errors[] = pht('You must ask a poll question.');
      } else {
        $e_question = null;
      }

      if ($is_new) {
        $responses = array_filter($responses);
        if (empty($responses)) {
          $errors[] = pht('You must offer at least one response.');
          $e_response = pht('Required');
        } else {
          $e_response = null;
        }
      }

      $xactions = array();
      $template = id(new PhabricatorSlowvoteTransaction());

      $xactions[] = id(clone $template)
        ->setTransactionType(PhabricatorSlowvoteTransaction::TYPE_QUESTION)
        ->setNewValue($v_question);

      $xactions[] = id(clone $template)
        ->setTransactionType(PhabricatorSlowvoteTransaction::TYPE_DESCRIPTION)
        ->setNewValue($v_description);

      $xactions[] = id(clone $template)
        ->setTransactionType(PhabricatorSlowvoteTransaction::TYPE_RESPONSES)
        ->setNewValue($v_responses);

      $xactions[] = id(clone $template)
        ->setTransactionType(PhabricatorSlowvoteTransaction::TYPE_SHUFFLE)
        ->setNewValue($v_shuffle);

      $xactions[] = id(clone $template)
        ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
        ->setNewValue($v_view_policy);

      if (empty($errors)) {
        $editor = id(new PhabricatorSlowvoteEditor())
          ->setActor($user)
          ->setContinueOnNoEffect(true)
          ->setContentSourceFromRequest($request);

        $xactions = $editor->applyTransactions($poll, $xactions);

        if ($is_new) {
          $poll->save();

          foreach ($responses as $response) {
            $option = new PhabricatorSlowvoteOption();
            $option->setName($response);
            $option->setPollID($poll->getID());
            $option->save();
          }
        }

        return id(new AphrontRedirectResponse())
          ->setURI('/V'.$poll->getID());
      } else {
        $poll->setViewPolicy($v_view_policy);
      }
    }

    $error_view = null;
    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle(pht('Form Errors'));
      $error_view->setErrors($errors);
    }

    $instructions =
      phutil_tag(
        'p',
        array(
          'class' => 'aphront-form-instructions',
        ),
        pht('Resolve issues and build consensus through '.
          'protracted deliberation.'));

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendChild($instructions)
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setHeight(AphrontFormTextAreaControl::HEIGHT_VERY_SHORT)
          ->setLabel(pht('Question'))
          ->setName('question')
          ->setValue($v_question)
          ->setError($e_question))
      ->appendChild(
        id(new PhabricatorRemarkupControl())
          ->setLabel(pht('Description'))
          ->setName('description')
          ->setValue($v_description));

    if ($is_new) {
      for ($ii = 0; $ii < 10; $ii++) {
        $n = ($ii + 1);
        $response = id(new AphrontFormTextControl())
          ->setLabel(pht("Response %d", $n))
          ->setName('response[]')
          ->setValue(idx($responses, $ii, ''));

        if ($ii == 0) {
          $response->setError($e_response);
        }

        $form->appendChild($response);
      }
    }

    $poll_type_options = array(
      PhabricatorSlowvotePoll::METHOD_PLURALITY =>
        pht('Plurality (Single Choice)'),
      PhabricatorSlowvotePoll::METHOD_APPROVAL  =>
        pht('Approval (Multiple Choice)'),
    );

    $response_type_options = array(
      PhabricatorSlowvotePoll::RESPONSES_VISIBLE
        => pht('Allow anyone to see the responses'),
      PhabricatorSlowvotePoll::RESPONSES_VOTERS
        => pht('Require a vote to see the responses'),
      PhabricatorSlowvotePoll::RESPONSES_OWNER
        => pht('Only I can see the responses'),
    );

    if ($is_new) {
      $form->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('Vote Type'))
          ->setName('method')
          ->setValue($poll->getMethod())
          ->setOptions($poll_type_options));
    } else {
      $form->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel(pht('Vote Type'))
          ->setValue(idx($poll_type_options, $poll->getMethod())));
    }

    if ($is_new) {
      $title = pht('Create Slowvote');
      $button = pht('Create');
      $cancel_uri = $this->getApplicationURI();
    } else {
      $title = pht('Edit %s', 'V'.$poll->getID());
      $button = pht('Save Changes');
      $cancel_uri = '/V'.$poll->getID();
    }

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($user)
      ->setObject($poll)
      ->execute();

    $form
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('Responses'))
          ->setName('responses')
          ->setValue($v_responses)
          ->setOptions($response_type_options))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->setLabel(pht('Shuffle'))
          ->addCheckbox(
            'shuffle',
            1,
            pht('Show choices in random order.'),
            $v_shuffle))
      ->appendChild(
        id(new AphrontFormPolicyControl())
          ->setUser($user)
          ->setName('viewPolicy')
          ->setPolicyObject($poll)
          ->setPolicies($policies)
          ->setCapability(PhabricatorPolicyCapability::CAN_VIEW))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue($button)
          ->addCancelButton($cancel_uri));

    $crumbs = $this->buildApplicationCrumbs($this->buildSideNavView());
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($title));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setFormError($error_view)
      ->setForm($form);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
