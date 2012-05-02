/**
 * @requires javelin-stratcom
 *           javelin-request
 *           javelin-dom
 *           javelin-vector
 *           javelin-install
 *           javelin-util
 *           javelin-mask
 *           javelin-uri
 * @provides javelin-workflow
 * @javelin
 */

/**
 * @group workflow
 */
JX.install('Workflow', {
  construct : function(uri, data) {
    if (__DEV__) {
      if (!uri || uri == '#') {
        JX.$E(
          'new JX.Workflow(<?>, ...): '+
          'bogus URI provided when creating workflow.');
      }
    }
    this.setURI(uri);
    this.setData(data || {});
  },

  events : ['error', 'finally', 'submit'],

  statics : {
    _stack   : [],
    newFromForm : function(form, data) {
      var pairs = JX.DOM.convertFormToListOfPairs(form);
      for (var k in data) {
        pairs.push([k, data[k]]);
      }

      // Disable form elements during the request
      var inputs = [].concat(
        JX.DOM.scry(form, 'input'),
        JX.DOM.scry(form, 'button'),
        JX.DOM.scry(form, 'textarea'));
      for (var ii = 0; ii < inputs.length; ii++) {
        if (inputs[ii].disabled) {
          delete inputs[ii];
        } else {
          inputs[ii].disabled = true;
        }
      }

      var workflow = new JX.Workflow(form.getAttribute('action'), {});
      workflow.setDataWithListOfPairs(pairs);
      workflow.setMethod(form.getAttribute('method'));
      workflow.listen('finally', function() {
        // Re-enable form elements
        for (var ii = 0; ii < inputs.length; ii++) {
          inputs[ii] && (inputs[ii].disabled = false);
        }
      });
      return workflow;
    },
    newFromLink : function(link) {
      var workflow = new JX.Workflow(link.href);
      return workflow;
    },
    _push : function(workflow) {
      JX.Mask.show();
      JX.Workflow._stack.push(workflow);
    },
    _pop : function() {
      var dialog = JX.Workflow._stack.pop();
      (dialog.getCloseHandler() || JX.bag)();
      dialog._destroy();
      JX.Mask.hide();
    },
    disable : function() {
      JX.Workflow._disabled = true;
    },
    _onbutton : function(event) {

      if (JX.Stratcom.pass()) {
        return;
      }

      if (JX.Workflow._disabled) {
        return;
      }

      var t = event.getTarget();
      if (t.name == '__cancel__' || t.name == '__close__') {
        JX.Workflow._pop();
      } else {

        var form = event.getNode('jx-dialog');
        var data = JX.DOM.convertFormToListOfPairs(form);
        data.push([t.name, true]);

        var active = JX.Workflow._getActiveWorkflow();
        var e = active.invoke('submit', {form: form, data: data});
        if (!e.getStopped()) {
          active._destroy();
          active
            .setURI(form.getAttribute('action') || active.getURI())
            .setDataWithListOfPairs(data)
            .start();
        }
      }
      event.prevent();
    },
    _getActiveWorkflow : function() {
      var stack = JX.Workflow._stack;
      return stack[stack.length - 1];
    }
  },

  members : {
    _root : null,
    _pushed : false,
    _data : null,
    _onload : function(r) {
      // It is permissible to send back a falsey redirect to force a page
      // reload, so we need to take this branch if the key is present.
      if (r && (typeof r.redirect != 'undefined')) {
        JX.$U(r.redirect).go();
      } else if (r && r.dialog) {
        this._push();
        this._root = JX.$N(
          'div',
          {className: 'jx-client-dialog'},
          JX.$H(r.dialog));
        JX.DOM.listen(
          this._root,
          'click',
          [['jx-workflow-button'], ['tag:button']],
          JX.Workflow._onbutton);
        document.body.appendChild(this._root);
        var d = JX.Vector.getDim(this._root);
        var v = JX.Vector.getViewport();
        var s = JX.Vector.getScroll();
        JX.$V((v.x - d.x) / 2, s.y + 100).setPos(this._root);
        try {
          JX.DOM.focus(JX.DOM.find(this._root, 'button', '__default__'));
          var inputs = JX.DOM.scry(this._root, 'input')
                         .concat(JX.DOM.scry(this._root, 'textarea'));
          var miny = Number.POSITIVE_INFINITY;
          var target = null;
          for (var ii = 0; ii < inputs.length; ++ii) {
            if (inputs[ii].type != 'hidden') {
              // Find the topleft-most displayed element.
              var p = JX.$V(inputs[ii]);
              if (p.y < miny) {
                 miny = p.y;
                 target = inputs[ii];
              }
            }
          }
          target && JX.DOM.focus(target);
        } catch (_ignored) {}
      } else if (this.getHandler()) {
        this.getHandler()(r);
        this._pop();
      } else if (r) {
        if (__DEV__) {
          JX.$E('Response to workflow request went unhandled.');
        }
      }
    },
    _push : function() {
      if (!this._pushed) {
        this._pushed = true;
        JX.Workflow._push(this);
      }
    },
    _pop : function() {
      if (this._pushed) {
        this._pushed = false;
        JX.Workflow._pop();
      }
    },
    _destroy : function() {
      if (this._root) {
        JX.DOM.remove(this._root);
        this._root = null;
      }
    },
    start : function() {
      var uri = this.getURI();
      var method = this.getMethod();
      var r = new JX.Request(uri, JX.bind(this, this._onload));
      var list_of_pairs = this._data;
      list_of_pairs.push(['__wflow__', true]);
      r.setDataWithListOfPairs(list_of_pairs);
      r.setDataSerializer(this.getDataSerializer());
      if (method) {
        r.setMethod(method);
      }
      r.listen('finally', JX.bind(this, this.invoke, 'finally'));
      r.listen('error', JX.bind(this, function(error) {
        var e = this.invoke('error', error);
        if (e.getStopped()) {
          return;
        }
        // TODO: Default error behavior? On Facebook Lite, we just shipped the
        // user to "/error/". We could emit a blanket 'workflow-failed' type
        // event instead.
      }));
      r.send();
    },

    setData : function(dictionary) {
      this._data = [];
      for (var k in dictionary) {
        this._data.push([k, dictionary[k]]);
      }
      return this;
    },

    setDataWithListOfPairs : function(list_of_pairs) {
      this._data = list_of_pairs;
      return this;
    }
  },

  properties : {
    handler : null,
    closeHandler : null,
    dataSerializer : null,
    method : null,
    URI : null
  },

  initialize : function() {

    function close_dialog_when_user_presses_escape(e) {
      if (e.getSpecialKey() != 'esc') {
        // Some key other than escape.
        return;
      }

      if (JX.Workflow._disabled) {
        // Workflows are disabled on this page.
        return;
      }

      if (JX.Stratcom.pass()) {
        // Something else swallowed the event.
        return;
      }

      var active = JX.Workflow._getActiveWorkflow();
      if (!active) {
        // No active workflow.
        return;
      }

      // Note: the cancel button is actually an <a /> tag.
      var buttons = JX.DOM.scry(active._root, 'a', 'jx-workflow-button');
      if (!buttons.length) {
        // No buttons in the dialog.
        return;
      }

      var cancel = null;
      for (var ii = 0; ii < buttons.length; ii++) {
        if (buttons[ii].name == '__cancel__') {
          cancel = buttons[ii];
          break;
        }
      }

      if (!cancel) {
        // No 'Cancel' button.
        return;
      }

      JX.Workflow._pop();
      e.prevent();
    };

    JX.Stratcom.listen('keydown', null, close_dialog_when_user_presses_escape);
  }

});
