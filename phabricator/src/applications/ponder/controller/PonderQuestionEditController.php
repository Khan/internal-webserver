<?php

final class PonderQuestionEditController extends PonderController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    if ($this->id) {
      $question = id(new PonderQuestionQuery())
        ->setViewer($user)
        ->withIDs(array($this->id))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->executeOne();
      if (!$question) {
        return new Aphront404Response();
      }
    } else {
      $question = id(new PonderQuestion())
        ->setStatus(PonderQuestionStatus::STATUS_OPEN)
        ->setAuthorPHID($user->getPHID())
        ->setVoteCount(0)
        ->setAnswerCount(0)
        ->setHeat(0.0);
    }

    $v_title = $question->getTitle();
    $v_content = $question->getContent();

    $errors = array();
    $e_title = true;
    if ($request->isFormPost()) {
      $v_title = $request->getStr('title');
      $v_content = $request->getStr('content');

      $len = phutil_utf8_strlen($v_title);
      if ($len < 1) {
        $errors[] = pht('Title must not be empty.');
        $e_title = pht('Required');
      } else if ($len > 255) {
        $errors[] = pht('Title is too long.');
        $e_title = pht('Too Long');
      }

      if (!$errors) {
        $template = id(new PonderQuestionTransaction());
        $xactions = array();

        $xactions[] = id(clone $template)
          ->setTransactionType(PonderQuestionTransaction::TYPE_TITLE)
          ->setNewValue($v_title);

        $xactions[] = id(clone $template)
          ->setTransactionType(PonderQuestionTransaction::TYPE_CONTENT)
          ->setNewValue($v_content);

        $editor = id(new PonderQuestionEditor())
          ->setActor($user)
          ->setContentSourceFromRequest($request)
          ->setContinueOnNoEffect(true);

        $editor->applyTransactions($question, $xactions);

        return id(new AphrontRedirectResponse())
          ->setURI('/Q'.$question->getID());
      }
    }

    $error_view = null;
    if ($errors) {
      $error_view = id(new AphrontErrorView())
        ->setTitle(pht('Form Errors'))
        ->setErrors($errors);
    }

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Question'))
          ->setName('title')
          ->setValue($v_title)
          ->setError($e_title))
      ->appendChild(
        id(new PhabricatorRemarkupControl())
          ->setName('content')
          ->setID('content')
          ->setValue($v_content)
          ->setLabel(pht('Description'))
          ->setUser($user))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($this->getApplicationURI())
          ->setValue(pht('Ask Away!')));

    $preview = id(new PHUIRemarkupPreviewPanel())
      ->setHeader(pht('Question Preview'))
      ->setControlID('content')
      ->setPreviewURI($this->getApplicationURI('preview/'));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Ask New Question'))
      ->setFormError($error_view)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs();

    $id = $question->getID();
    if ($id) {
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName("Q{$id}")
          ->setHref("/Q{$id}"));
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Edit')));
    } else {
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Ask Question')));
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
        $preview,
      ),
      array(
        'title'  => pht('Ask New Question'),
        'device' => true,
      ));
  }

}
