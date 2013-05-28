<?php

final class PhabricatorPagedFormExample extends PhabricatorUIExample {

  public function getName() {
    return pht('Form (Paged)');
  }

  public function getDescription() {
    return pht(
      'Use %s to render forms with multiple pages.',
      hsprintf('<tt>PHUIPagedFormView</tt>'));
  }

  public function renderExample() {
    $request = $this->getRequest();
    $user = $request->getUser();


    $page1 = id(new PHUIFormPageView())
      ->addControl(
        id(new AphrontFormTextControl())
          ->setName('page1')
          ->setLabel('Page 1'));

    $page2 = id(new PHUIFormPageView())
      ->addControl(
        id(new AphrontFormTextControl())
          ->setName('page2')
          ->setLabel('Page 2'));

    $page3 = id(new PHUIFormPageView())
      ->addControl(
        id(new AphrontFormTextControl())
          ->setName('page3')
          ->setLabel('Page 3'));

    $page4 = id(new PHUIFormPageView())
      ->addControl(
        id(new AphrontFormTextControl())
          ->setName('page4')
          ->setLabel('Page 4'));

    $form = new PHUIPagedFormView();
    $form->setUser($user);

    $form->addPage('page1', $page1);
    $form->addPage('page2', $page2);
    $form->addPage('page3', $page3);
    $form->addPage('page4', $page4);

    if ($request->isFormPost()) {
      $form->readFromRequest($request);
      if ($form->isComplete()) {
        return id(new AphrontDialogView())
          ->setUser($user)
          ->setTitle(pht('Form Complete'))
          ->appendChild(pht('You submitted the form. Well done!'))
          ->addCancelButton($request->getRequestURI(), pht('Again!'));
      }
    } else {
      $form->readFromObject(null);
    }

    return $form;
  }
}
