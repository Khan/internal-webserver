/**
 * @provides javelin-behavior-phabricator-transaction-comment-form
 * @requires javelin-behavior
 *           javelin-dom
 *           javelin-util
 *           phabricator-shaped-request
 */

JX.behavior('phabricator-transaction-comment-form', function(config) {

  var form = JX.$(config.formID);

  JX.DOM.listen(form, 'willClear', null, function(e) {
    e.kill();
    JX.$(config.commentID).value = '';
  });

  var getdata = function() {
    var obj = JX.DOM.convertFormToDictionary(form);
    obj.__preview__ = 1;

    if (config.draftKey) {
      obj.__draft__ = config.draftKey;
    }

    return obj;
  };

  var onresponse = function(response) {
    var panel = JX.$(config.panelID);
    if (!response.xactions.length) {
      JX.DOM.hide(panel);
    } else {
      JX.DOM.setContent(
        JX.$(config.timelineID),
        [
          JX.$H(response.spacer),
          JX.$H(response.xactions.join(response.spacer)),
          JX.$H(response.spacer)
        ]);
      JX.DOM.show(panel);
    }
  };

  var request = new JX.PhabricatorShapedRequest(
    config.actionURI,
    onresponse,
    getdata);
  var trigger = JX.bind(request, request.trigger);

  JX.DOM.listen(form, 'keydown', null, trigger);

  request.start();
});
