/**
 * @provides javelin-behavior-conpherence-menu
 * @requires javelin-behavior
 *           javelin-dom
 *           javelin-util
 *           javelin-request
 *           javelin-stratcom
 *           javelin-workflow
 *           javelin-behavior-device
 *           javelin-history
 */

JX.behavior('conpherence-menu', function(config) {

  var thread = {
    selected: null,
    node: null,
    visible: null
  };

  function selectthreadid(id) {
    var threads = JX.DOM.scry(document.body, 'a', 'conpherence-menu-click');
    for (var ii = 0; ii < threads.length; ii++) {
      var data = JX.Stratcom.getData(threads[ii]);
      if (data.id == id) {
        selectthread(threads[ii]);
        return;
      }
    }
  }

  function selectthread(node) {

    if (thread.node) {
      JX.DOM.alterClass(thread.node, 'conpherence-selected', false);
      // keep the unread-count hidden still. big TODO once we ajax in updates
      // to threads to make this work right and move threads between read /
      // unread
    }

    JX.DOM.alterClass(node, 'conpherence-selected', true);
    JX.DOM.alterClass(node, 'hide-unread-count', true);

    thread.node = node;

    var data = JX.Stratcom.getData(node);
    thread.selected = data.id;

    JX.History.replace(config.base_uri + data.id + '/');
    redrawthread();
  }

  JX.Stratcom.listen(
    'conpherence-selectthread',
    null,
    function (e) {
      var node = JX.$(e.getData().id);
      selectthread(node);
    }
  );

  function redrawthread() {
    if (!thread.node) {
      return;
    }

    if (thread.visible == thread.selected) {
      return;
    }

    var data = JX.Stratcom.getData(thread.node);

    if (thread.visible !== null || !config.hasThread) {
      var uri = config.base_uri + data.id + '/';
      new JX.Workflow(uri, {})
        .setHandler(onresponse)
        .start();
    } else {
      didredrawthread();
    }

    if (thread.visible !== null || !config.hasWidgets) {
      var widget_uri = config.base_uri + 'widget/' + data.id + '/';
      new JX.Workflow(widget_uri, {})
        .setHandler(onwidgetresponse)
        .start();
    }

    thread.visible = thread.selected;
  }

  function onwidgetresponse(response) {
    var widgets = JX.$H(response.widgets);
    var widgetsRoot = JX.$(config.widgets_pane);
    JX.DOM.setContent(widgetsRoot, widgets);
  }

  function onresponse(response) {
    var header = JX.$H(response.header);
    var messages = JX.$H(response.messages);
    var form = JX.$H(response.form);
    var headerRoot = JX.$(config.header);
    var messagesRoot = JX.$(config.messages);
    var formRoot = JX.$(config.form_pane);
    var widgetsRoot = JX.$(config.widgets_pane);
    var menuRoot = JX.$(config.menu_pane);
    JX.DOM.setContent(headerRoot, header);
    JX.DOM.setContent(messagesRoot, messages);
    JX.DOM.setContent(formRoot, form);

    didredrawthread();
  }

  function didredrawthread() {
    var messagesRoot = JX.$(config.messages);
    messagesRoot.scrollTop = messagesRoot.scrollHeight;
  }

  JX.Stratcom.listen(
    'click',
    'conpherence-menu-click',
    function(e) {
      if (!e.isNormalClick()) {
        return;
      }

      // On devices, just follow the link normally.
      if (JX.Device.getDevice() != 'desktop') {
        return;
      }

      e.kill();
      selectthread(e.getNode('conpherence-menu-click'));
    });

  JX.Stratcom.listen('click', 'conpherence-edit-metadata', function (e) {
    e.kill();
    var root = e.getNode('conpherence-layout');
    var form = JX.DOM.find(root, 'form', 'conpherence-pontificate');
    var data = e.getNodeData('conpherence-edit-metadata');
    var header = JX.DOM.find(root, 'div', 'conpherence-header');
    var peopleWidget = null;
    try {
      peopleWidget = JX.DOM.find(root, 'div', 'widgets-people');
    } catch (ex) {
      // Ignore; maybe no people widget
    }

    new JX.Workflow.newFromForm(form, data)
      .setHandler(JX.bind(this, function(r) {
        // update the header
        JX.DOM.setContent(
          header,
          JX.$H(r.header)
        );

        try {
          // update the menu entry
          JX.DOM.replace(
            JX.$(r.conpherence_phid + '-nav-item'),
            JX.$H(r.nav_item)
          );
          JX.Stratcom.invoke(
            'conpherence-selectthread',
            null,
            { id : r.conpherence_phid + '-nav-item' }
          );
        } catch (ex) {
          // Ignore; this view may not have a menu.
        }

        if (peopleWidget) {
          // update the people widget
          JX.DOM.setContent(
            peopleWidget,
            JX.$H(r.people_widget)
          );
        }
      }))
      .start();
  });

  JX.Stratcom.listen('click', 'show-older-messages', function(e) {
    e.kill();
    var last_offset = e.getNodeData('show-older-messages').offset;
    var conf_id = e.getNodeData('show-older-messages').ID;
    JX.DOM.remove(e.getNode('show-older-messages'));
    var messages_root = JX.$(config.messages);
    new JX.Request(config.base_uri + conf_id + '/', function(r) {
      var messages = JX.$H(r.messages);
      JX.DOM.prependContent(messages_root,
      JX.$H(messages));
    }).setData({ offset: last_offset+1 }).send();
  });


  // On mobile, we just show a thread list, so we don't want to automatically
  // select or load any threads. On Desktop, we automatically select the first
  // thread.

  var old_device = null;
  function ondevicechange() {
    var new_device = JX.Device.getDevice();
    if (new_device === old_device) {
      return;
    }
    old_device = new_device;

    if (new_device != 'desktop') {
      return;
    }

    if (!config.hasThreadList) {
      loadthreads();
    } else {
      didloadthreads();
    }
  }

  JX.Stratcom.listen('phabricator-device-change', null, ondevicechange);
  ondevicechange();

  function loadthreads() {
    var uri = config.base_uri + 'thread/' + config.selectedID + '/';
    new JX.Workflow(uri)
      .setHandler(onthreadresponse)
      .start();
  }

  function onthreadresponse(r) {
    var layout = JX.$(config.layoutID);
    var menu = JX.DOM.find(layout, 'div', 'conpherence-menu-pane');
    JX.DOM.setContent(menu, JX.$H(r));

    config.selectedID && selectthreadid(config.selectedID);
  }

  function didloadthreads() {
    // If there's no thread selected yet, select the current thread or the
    // first thread.
    if (!thread.selected) {
      if (config.selectedID) {
        selectthreadid(config.selectedID);
      } else {
        var threads = JX.DOM.scry(document.body, 'a', 'conpherence-menu-click');
        if (threads.length) {
          selectthread(threads[0]);
        }
      }
    }
    redrawthread();
  }

});
