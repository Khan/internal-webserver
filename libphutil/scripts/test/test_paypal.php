#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

// NOTE: These credentials are global test credentials provided by PayPal.

$f = id(new PhutilPayPalAPIFuture())
  ->setHost('https://api-3t.sandbox.paypal.com/nvp')
  ->setAPIUsername('sdk-three_api1.sdk.com')
  ->setAPIPassword('QFZCWN5HZM8VBG7Q')
  ->setAPISignature('A-IzJhZZjhg29XQ2qnhapuwxIDzyAZQ92FRP5dqBzVesOkzbdUONzmOU');

$f->setRawPayPalQuery(
  'SetExpressCheckout',
  array(
    'PAYMENTREQUEST_0_AMT'            => '1.23',
    'PAYMENTREQUEST_0_CURRENCYCODE'   => 'USD',
    'RETURNURL'                       => 'http://www.example.com/?return=1',
    'CANCELURL'                       => 'http://www.example.com/?cancel=1',
    'PAYMENTREQUEST_0_PAYMENTACTION'  => 'Sale',
  ));

print_r($f->resolve());
