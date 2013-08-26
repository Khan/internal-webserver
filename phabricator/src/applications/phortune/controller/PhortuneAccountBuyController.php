<?php

final class PhortuneAccountBuyController
  extends PhortuneController {

  private $accountID;
  private $id;

  public function willProcessRequest(array $data) {
    $this->accountID = $data['accountID'];
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $account = id(new PhortuneAccountQuery())
      ->setViewer($user)
      ->withIDs(array($this->accountID))
      ->executeOne();
    if (!$account) {
      return new Aphront404Response();
    }

    $account_uri = $this->getApplicationURI($account->getID().'/');

    $product = id(new PhortuneProductQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$product) {
      return new Aphront404Response();
    }

    $purchase = new PhortunePurchase();
    $purchase->setProductPHID($product->getPHID());
    $purchase->setAccountPHID($account->getPHID());
    $purchase->setPurchaseName($product->getProductName());
    $purchase->setBasePriceInCents($product->getPriceInCents());
    $purchase->setQuantity(1);
    $purchase->setTotalPriceInCents(
      $purchase->getBasePriceInCents() * $purchase->getQuantity());
    $purchase->setStatus(PhortunePurchase::STATUS_PENDING);

    $cart = new PhortuneCart();
    $cart->setAccountPHID($account->getPHID());
    $cart->setOwnerPHID($user->getPHID());
    $cart->attachPurchases(
      array(
        $purchase,
      ));

    $rows = array();
    $total = 0;
    foreach ($cart->getPurchases() as $purchase) {
      $rows[] = array(
        $purchase->getPurchaseName(),
        PhortuneCurrency::newFromUSDCents($purchase->getBasePriceInCents())
          ->formatForDisplay(),
        $purchase->getQuantity(),
        PhortuneCurrency::newFromUSDCents($purchase->getTotalPriceInCents())
          ->formatForDisplay(),
      );

      $total += $purchase->getTotalPriceInCents();
    }

    $rows[] = array(
      phutil_tag('strong', array(), pht('Total')),
      '',
      '',
      phutil_tag('strong', array(),
        PhortuneCurrency::newFromUSDCents($total)->formatForDisplay()),
    );

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        pht('Item'),
        pht('Price'),
        pht('Qty.'),
        pht('Total'),
      ));

    $panel = new AphrontPanelView();
    $panel->setNoBackground(true);
    $panel->appendChild($table);


    $title = pht('Buy Stuff');


    $methods = id(new PhortunePaymentMethodQuery())
      ->setViewer($user)
      ->withAccountPHIDs(array($account->getPHID()))
      ->withStatus(PhortunePaymentMethodQuery::STATUS_OPEN)
      ->execute();

    $method_control = id(new AphrontFormRadioButtonControl())
      ->setLabel(pht('Payment Method'));

    if (!$methods) {
      $method_control = id(new AphrontFormStaticControl())
        ->setLabel(pht('Payment Method'))
        ->setValue(
          phutil_tag('em', array(), pht('No payment methods configured.')));
    } else {
      $method_control = id(new AphrontFormRadioButtonControl())
        ->setLabel(pht('Payment Method'))
        ->setName('paymentMethodID')
        ->setValue($request->getInt('paymentMethodID'));
      foreach ($methods as $method) {
        $method_control->addButton(
          $method->getID(),
          $method->getBrand().' / '.$method->getLastFourDigits(),
          $method->getDescription());
      }
    }

    $payment_method_uri = $this->getApplicationURI(
      $account->getID().'/paymentmethod/edit/');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendChild($method_control);

    $add_providers = PhortunePaymentProvider::getProvidersForAddPaymentMethod();
    if ($add_providers) {
      $new_method = phutil_tag(
        'a',
        array(
          'class' => 'button grey',
          'href'  => $payment_method_uri,
          'sigil' => 'workflow',
        ),
        pht('Add New Payment Method'));
      $form->appendChild(
        id(new AphrontFormMarkupControl())
          ->setValue($new_method));
    }

    if ($methods || $add_providers) {
      $form
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue(pht("Submit Payment"))
            ->setDisabled(!$methods));
    }

    $provider_form = null;

    $pay_providers = PhortunePaymentProvider::getProvidersForOneTimePayment();
    if ($pay_providers) {
      $one_time_options = array();
      foreach ($pay_providers as $provider) {
        $one_time_options[] = $provider->renderOneTimePaymentButton(
          $account,
          $cart,
          $user);
      }

      $provider_form = new PHUIFormLayoutView();
      $provider_form->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel('Pay With')
          ->setValue($one_time_options));
    }

    return $this->buildApplicationPage(
      array(
        $panel,
        $form,
        phutil_tag('br', array()),
        $provider_form,
      ),
      array(
        'title'   => $title,
        'device'  => true,
      ));

  }
}
