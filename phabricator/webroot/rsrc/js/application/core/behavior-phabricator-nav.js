/**
 * @provides javelin-behavior-phabricator-nav
 * @requires javelin-behavior
 *           javelin-behavior-device
 *           javelin-stratcom
 *           javelin-dom
 *           javelin-magical-init
 *           javelin-vector
 * @javelin
 */

JX.behavior('phabricator-nav', function(config) {

  var content = JX.$(config.contentID);
  var local = JX.$(config.localID);
  var main = JX.$(config.mainID);
  var drag = JX.$(config.dragID);
  var background = JX.$(config.backgroundID);


// - Flexible Navigation Column ------------------------------------------------


  var dragging;
  var track;

  JX.enableDispatch(document.body, 'mousemove');

  JX.DOM.listen(drag, 'mousedown', null, function(e) {
    dragging = JX.$V(e);

    // Show the "col-resize" cursor on the whole document while we're
    // dragging, since the mouse will slip off the actual bar fairly often and
    // we don't want it to flicker.
    JX.DOM.alterClass(document.body, 'jx-drag-col', true);

    track = [
      {
        element: local,
        parameter: 'width',
        start: JX.Vector.getDim(local).x,
        width: JX.Vector.getDim(local).x,
        minWidth: 1
      },
      {
        element: background,
        parameter: 'width',
        start: JX.Vector.getDim(background).x,
        start: JX.Vector.getDim(background).x,
        minWidth: 1
      },
      {
        element: drag,
        parameter: 'left',
        start: JX.$V(drag).x
      },
      {
        element: content,
        parameter: 'marginLeft',
        start: parseInt(getComputedStyle(content).marginLeft, 10),
        width: JX.Vector.getDim(content).x,
        minWidth: 300,
        minScale: -1
      }
    ];

    e.kill();
  });

  JX.Stratcom.listen('mousemove', null, function(e) {
    if (!dragging) {
      return;
    }

    var dx = JX.$V(e).x - dragging.x;
    var panel;

    for (var k = 0; k < track.length; k++) {
      panel = track[k];
      if (!panel.minWidth) {
        continue;
      }
      var new_width = panel.width + (dx * (panel.minScale || 1));
      if (new_width < panel.minWidth) {
        dx = (panel.minWidth - panel.width) * panel.minScale;
      }
    }

    for (var k = 0; k < track.length; k++) {
      panel = track[k];
      var v = (panel.start + (dx * (panel.scale || 1)));
      panel.element.style[panel.parameter] = v + 'px';
    }
  });

  JX.Stratcom.listen('mouseup', null, function(e) {
    if (!dragging) {
      return;
    }
    JX.DOM.alterClass(document.body, 'jx-drag-col', false);
    dragging = false;
  });


  function resetdrag() {
    local.style.width = '';
    background.style.width = '';
    drag.style.left = '';
    content.style.marginLeft = '';
  }

  var collapsed = false;
  JX.Stratcom.listen('differential-filetree-toggle', null, function(e) {
    collapsed = !collapsed;
    JX.DOM.alterClass(main, 'has-local-nav', !collapsed);
    JX.DOM.alterClass(main, 'has-drag-nav', !collapsed);
    resetdrag();
  });


// - Scroll --------------------------------------------------------------------

  // When the user scrolls or resizes the window, anchor the menu to to the top
  // of the navigation bar.

  function onresize(e) {
    if (JX.Device.getDevice() != 'desktop') {
      return;
    }

    local.style.top = Math.max(
      0,
      JX.$V(content).y - Math.max(0, JX.Vector.getScroll().y)) + 'px';
  }

  local.style.position = 'fixed';
  local.style.bottom = 0;
  local.style.left = 0;

  JX.Stratcom.listen(['scroll', 'resize'], null, onresize);
  onresize();


// - Navigation Reset ----------------------------------------------------------

  JX.Stratcom.listen('phabricator-device-change', null, function(event) {
    resetdrag();
    onresize();
  });

});
