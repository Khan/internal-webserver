<?php

/**
 * @group phame
 */
final class PhamePostEditController
  extends PhameController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {
    $request       = $this->getRequest();
    $user          = $request->getUser();

    if ($this->id) {
      $post = id(new PhamePostQuery())
        ->setViewer($user)
        ->withIDs(array($this->id))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->executeOne();
      if (!$post) {
        return new Aphront404Response();
      }

      $cancel_uri = $this->getApplicationURI('/post/view/'.$this->id.'/');
      $submit_button = pht('Save Changes');
      $page_title = pht('Edit Post');
    } else {
      $blog = id(new PhameBlogQuery())
        ->setViewer($user)
        ->withIDs(array($request->getInt('blog')))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_JOIN,
          ))
        ->executeOne();
      if (!$blog) {
        return new Aphront404Response();
      }

      $post = PhamePost::initializePost($user, $blog);
      $cancel_uri = $this->getApplicationURI('/blog/view/'.$blog->getID().'/');

      $submit_button = pht('Save Draft');
      $page_title    = pht('Create Post');
    }

    $e_phame_title = null;
    $e_title       = true;
    $errors        = array();

    if ($request->isFormPost()) {
      $comments    = $request->getStr('comments_widget');
      $data        = array('comments_widget' => $comments);
      $phame_title = $request->getStr('phame_title');
      $phame_title = PhabricatorSlug::normalize($phame_title);
      $title       = $request->getStr('title');
      $post->setTitle($title);
      $post->setPhameTitle($phame_title);
      $post->setBody($request->getStr('body'));
      $post->setConfigData($data);

      if ($phame_title == '/') {
        $errors[]      = pht('Phame title must be nonempty.');
        $e_phame_title = pht('Required');
      }

      if (!strlen($title)) {
        $errors[] = pht('Title must be nonempty.');
        $e_title  = pht('Required');
      } else {
        $e_title = null;
      }

      if (!$errors) {
        try {
          $post->save();

          $uri = $this->getApplicationURI('/post/view/'.$post->getID().'/');
          return id(new AphrontRedirectResponse())->setURI($uri);
        } catch (AphrontQueryDuplicateKeyException $e) {
          $e_phame_title = pht('Not Unique');
          $errors[]      = pht('Another post already uses this slug. '.
                           'Each post must have a unique slug.');
        }
      }
    }

    $handle = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs(array($post->getBlogPHID()))
      ->executeOne();

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->addHiddenInput('blog', $request->getInt('blog'))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('Blog'))
          ->setValue($handle->renderLink()))
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('Title'))
        ->setName('title')
        ->setValue($post->getTitle())
        ->setID('post-title')
        ->setError($e_title))
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('Phame Title'))
        ->setName('phame_title')
        ->setValue(rtrim($post->getPhameTitle(), '/'))
        ->setID('post-phame-title')
        ->setCaption(pht('Up to 64 alphanumeric characters '.
                     'with underscores for spaces. '.
                     'Formatting is enforced.'))
        ->setError($e_phame_title))
      ->appendChild(
        id(new PhabricatorRemarkupControl())
        ->setLabel(pht('Body'))
        ->setName('body')
        ->setValue($post->getBody())
        ->setHeight(AphrontFormTextAreaControl::HEIGHT_VERY_TALL)
        ->setID('post-body')
        ->setUser($user)
        ->setDisableMacros(true))
      ->appendChild(
        id(new AphrontFormSelectControl())
        ->setLabel(pht('Comments Widget'))
        ->setName('comments_widget')
        ->setvalue($post->getCommentsWidget())
        ->setOptions($post->getCommentsWidgetOptionsForSelect()))
      ->appendChild(
        id(new AphrontFormSubmitControl())
        ->addCancelButton($cancel_uri)
        ->setValue($submit_button));

    $loading = phutil_tag_div(
      'aphront-panel-preview-loading-text',
      pht('Loading preview...'));

    $preview_panel = phutil_tag_div('aphront-panel-preview', array(
      phutil_tag_div('phame-post-preview-header', pht('Post Preview')),
      phutil_tag('div', array('id' => 'post-preview'), $loading),
    ));

    require_celerity_resource('phame-css');
    Javelin::initBehavior(
      'phame-post-preview',
      array(
        'preview'     => 'post-preview',
        'body'        => 'post-body',
        'title'       => 'post-title',
        'phame_title' => 'post-phame-title',
        'uri'         => '/phame/post/preview/',
      ));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($page_title)
      ->setFormErrors($errors)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      $page_title,
      $this->getApplicationURI('/post/view/'.$this->id.'/'));

    $nav = $this->renderSideNavFilterView(null);
    $nav->appendChild(
      array(
        $crumbs,
        $form_box,
        $preview_panel,
      ));

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => $page_title,
        'device' => true,
      ));
  }

}
