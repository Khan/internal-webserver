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

final class PhabricatorUserEmailSettingsPanelController
  extends PhabricatorUserSettingsPanelController {

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();
    $editable = $this->getAccountEditable();

    $uri = $request->getRequestURI();
    $uri->setQueryParams(array());

    if ($editable) {
      $new = $request->getStr('new');
      if ($new) {
        return $this->returnNewAddressResponse($uri, $new);
      }

      $delete = $request->getInt('delete');
      if ($delete) {
        return $this->returnDeleteAddressResponse($uri, $delete);
      }
    }

    $verify = $request->getInt('verify');
    if ($verify) {
      return $this->returnVerifyAddressResponse($uri, $verify);
    }

    $primary = $request->getInt('primary');
    if ($primary) {
      return $this->returnPrimaryAddressResponse($uri, $primary);
    }

    $emails = id(new PhabricatorUserEmail())->loadAllWhere(
      'userPHID = %s',
      $user->getPHID());

    $rowc = array();
    $rows = array();
    foreach ($emails as $email) {

      if ($email->getIsPrimary()) {
        $action = phutil_render_tag(
          'a',
          array(
            'class' => 'button small disabled',
          ),
          'Primary');
        $remove = $action;
        $rowc[] = 'highlighted';
      } else {
        if ($email->getIsVerified()) {
          $action = javelin_render_tag(
            'a',
            array(
              'class' => 'button small grey',
              'href'  => $uri->alter('primary', $email->getID()),
              'sigil' => 'workflow',
            ),
            'Make Primary');
        } else {
          $action = javelin_render_tag(
            'a',
            array(
              'class' => 'button small grey',
              'href'  => $uri->alter('verify', $email->getID()),
              'sigil' => 'workflow',
            ),
            'Verify');
        }
        $remove = javelin_render_tag(
          'a',
          array(
            'class'   => 'button small grey',
            'href'    => $uri->alter('delete', $email->getID()),
            'sigil'   => 'workflow'
          ),
          'Remove');
        $rowc[] = null;
      }

      $rows[] = array(
        phutil_escape_html($email->getAddress()),
        $action,
        $remove,
      );
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Email',
        'Status',
        'Remove',
      ));
    $table->setColumnClasses(
      array(
        'wide',
        'action',
        'action',
      ));
    $table->setRowClasses($rowc);
    $table->setColumnVisibility(
      array(
        true,
        true,
        $editable,
      ));

    $view = new AphrontPanelView();
    if ($editable) {
      $view->addButton(
        javelin_render_tag(
          'a',
          array(
            'href'      => $uri->alter('new', 'true'),
            'class'     => 'green button',
            'sigil'     => 'workflow',
          ),
          'Add New Address'));
    }
    $view->setHeader('Email Addresses');
    $view->appendChild($table);

    return $view;
  }

  private function returnNewAddressResponse(PhutilURI $uri, $new) {
    $request = $this->getRequest();
    $user = $request->getUser();

    $e_email = true;
    $email   = trim($request->getStr('email'));
    $errors  = array();
    if ($request->isDialogFormPost()) {

      if ($new == 'verify') {
        // The user clicked "Done" from the "an email has been sent" dialog.
        return id(new AphrontReloadResponse())->setURI($uri);
      }

      if (!strlen($email)) {
        $e_email = 'Required';
        $errors[] = 'Email is required.';
      }

      if (!$errors) {
        $object = id(new PhabricatorUserEmail())
          ->setUserPHID($user->getPHID())
          ->setAddress($email)
          ->setIsVerified(0)
          ->setIsPrimary(0);

        try {
          $object->save();

          $object->sendVerificationEmail($user);

          $dialog = id(new AphrontDialogView())
            ->setUser($user)
            ->addHiddenInput('new',  'verify')
            ->setTitle('Verification Email Sent')
            ->appendChild(
              '<p>A verification email has been sent. Click the link in the '.
              'email to verify your address.</p>')
            ->setSubmitURI($uri)
            ->addSubmitButton('Done');

          return id(new AphrontDialogResponse())->setDialog($dialog);
        } catch (AphrontQueryDuplicateKeyException $ex) {
          $email = 'Duplicate';
          $errors[] = 'Another user already has this email.';
        }
      }
    }

    if ($errors) {
      $errors = id(new AphrontErrorView())
        ->setWidth(AphrontErrorView::WIDTH_DIALOG)
        ->setErrors($errors);
    }

    $form = id(new AphrontFormLayoutView())
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Email')
          ->setName('email')
          ->setValue($request->getStr('email'))
          ->setError($e_email));

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->addHiddenInput('new', 'true')
      ->setTitle('New Address')
      ->appendChild($errors)
      ->appendChild($form)
      ->addSubmitButton('Save')
      ->addCancelButton($uri);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private function returnDeleteAddressResponse(PhutilURI $uri, $email_id) {
    $request = $this->getRequest();
    $user = $request->getUser();

    // NOTE: You can only delete your own email addresses, and you can not
    // delete your primary address.
    $email = id(new PhabricatorUserEmail())->loadOneWhere(
      'id = %d AND userPHID = %s AND isPrimary = 0',
      $email_id,
      $user->getPHID());

    if (!$email) {
      return new Aphront404Response();
    }

    if ($request->isFormPost()) {
      $email->delete();
      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    $address = $email->getAddress();

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->addHiddenInput('delete', $email_id)
      ->setTitle("Really delete address '{$address}'?")
      ->appendChild(
        '<p>Are you sure you want to delete this address? You will no '.
        'longer be able to use it to login.</p>')
      ->addSubmitButton('Delete')
      ->addCancelButton($uri);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private function returnVerifyAddressResponse(PhutilURI $uri, $email_id) {
    $request = $this->getRequest();
    $user = $request->getUser();

    // NOTE: You can only send more email for your unverified addresses.
    $email = id(new PhabricatorUserEmail())->loadOneWhere(
      'id = %d AND userPHID = %s AND isVerified = 0',
      $email_id,
      $user->getPHID());

    if (!$email) {
      return new Aphront404Response();
    }

    if ($request->isFormPost()) {
      $email->sendVerificationEmail($user);
      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    $address = $email->getAddress();

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->addHiddenInput('verify', $email_id)
      ->setTitle("Send Another Verification Email?")
      ->appendChild(
        '<p>Send another copy of the verification email to '.
        phutil_escape_html($address).'?</p>')
      ->addSubmitButton('Send Email')
      ->addCancelButton($uri);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  private function returnPrimaryAddressResponse(PhutilURI $uri, $email_id) {
    $request = $this->getRequest();
    $user = $request->getUser();

    // NOTE: You can only make your own verified addresses primary.
    $email = id(new PhabricatorUserEmail())->loadOneWhere(
      'id = %d AND userPHID = %s AND isVerified = 1 AND isPrimary = 0',
      $email_id,
      $user->getPHID());

    if (!$email) {
      return new Aphront404Response();
    }

    if ($request->isFormPost()) {

      // TODO: Transactions!

      $email->setIsPrimary(1);

      $old_primary = $user->loadPrimaryEmail();
      if ($old_primary) {
        $old_primary->setIsPrimary(0);
        $old_primary->save();
      }
      $email->save();

      if ($old_primary) {
        $old_primary->sendOldPrimaryEmail($user, $email);
      }
      $email->sendNewPrimaryEmail($user);

      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    $address = $email->getAddress();

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->addHiddenInput('primary', $email_id)
      ->setTitle("Change primary email address?")
      ->appendChild(
        '<p>If you change your primary address, Phabricator will send all '.
        'email to '.phutil_escape_html($address).'.</p>')
      ->addSubmitButton('Change Primary Address')
      ->addCancelButton($uri);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
