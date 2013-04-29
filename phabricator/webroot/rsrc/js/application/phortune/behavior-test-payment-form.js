/**
 * @provides javelin-behavior-test-payment-form
 * @requires javelin-behavior
 *           javelin-dom
 *           phortune-credit-card-form
 */

JX.behavior('test-payment-form', function(config) {
  var ccform = new JX.PhortuneCreditCardForm(JX.$(config.formID), onsubmit);

  function onsubmit(card_data) {
    onresponse();
  }

  function onresponse() {
    ccform.submitForm([], {test: true});
  }

});
