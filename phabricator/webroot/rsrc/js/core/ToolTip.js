/**
 * @requires javelin-install
 *           javelin-util
 *           javelin-dom
 *           javelin-vector
 * @provides phabricator-tooltip
 * @javelin
 */

JX.install('Tooltip', {

  statics : {
    _node : null,

    show : function(root, scale, align, content) {
      if (__DEV__) {
        switch (align) {
          case 'N':
          case 'E':
          case 'S':
          case 'W':
            break;
          default:
            JX.$E(
              "Only alignments 'N' (north), 'E' (east), 'S' (south), " +
              "and 'W' (west) are supported."
            );
            break;
        }
      }

      var node = JX.$N(
        'div',
        { className: 'jx-tooltip-container' },
        [
          JX.$N('div', { className: 'jx-tooltip' }, content),
          JX.$N('div', { className: 'jx-tooltip-anchor' })
        ]);

      node.style.maxWidth  = scale + 'px';

      JX.Tooltip.hide();
      this._node = node;

      // Append the tip to the document, but offscreen, so we can measure it.
      node.style.left = '-10000px';
      document.body.appendChild(node);

      // Jump through some hoops trying to auto-position the tooltip
      var pos = this._getSmartPosition(align, root, node);
      pos.setPos(node);
    },

    _getSmartPosition: function (align, root, node) {
      var pos = JX.Tooltip._proposePosition(align, root, node);

      // If toolip is offscreen, try to be clever
      if (!JX.Tooltip.isOnScreen(pos, node)) {
        align = JX.Tooltip._getImprovedOrientation(pos, node);
        pos = JX.Tooltip._proposePosition(align, root, node);
      }

      JX.Tooltip._setAnchor(align);
      return pos;
    },

    _proposePosition: function (align, root, node) {
      var p = JX.$V(root);
      var d = JX.Vector.getDim(root);
      var n = JX.Vector.getDim(node);
      var l = 0;
      var t = 0;

      // Caculate the tip so it's nicely aligned.
      switch (align) {
        case 'N':
          l = parseInt(p.x - ((n.x - d.x) / 2), 10);
          t  = parseInt(p.y - n.y, 10);
          break;
        case 'E':
          l = parseInt(p.x + d.x, 10);
          t  = parseInt(p.y - ((n.y - d.y) / 2), 10);
          break;
        case 'S':
          l = parseInt(p.x - ((n.x - d.x) / 2), 10);
          t  = parseInt(p.y + d.y + 5, 10);
          break;
        case 'W':
          l = parseInt(p.x - n.x - 5, 10);
          t  = parseInt(p.y - ((n.y - d.y) / 2), 10);
          break;
      }

      return new JX.Vector(l, t);
    },

    isOnScreen: function (a, node) {
      var s = JX.Vector.getScroll();
      var v = JX.Vector.getViewport();
      var max_x = s.x + v.x;
      var max_y = s.y + v.y;

      var corners = this._getNodeCornerPositions(a, node);

      // Check if any of the corners are offscreen
      for (var i = 0; i < corners.length; i++) {
        var corner = corners[i];
        if (corner.x < s.x ||
            corner.y < s.y ||
            corner.x > max_x ||
            corner.y > max_y) {
          return false;
        }
      }
      return true;
    },

    _getImprovedOrientation: function (a, node) {
      // Try to predict the "more correct" orientation
      var s = JX.Vector.getScroll();
      var v = JX.Vector.getViewport();
      var max_x = s.x + v.x;
      var max_y = s.y + v.y;

      var corners = this._getNodeCornerPositions(a, node);

      for (var i = 0; i < corners.length; i++) {
        var corner = corners[i];
        if (corner.y < v.y) {
          return 'S';
        } else
        if (corner.x < v.x) {
          return 'E';
        } else
        if (corner.y > max_y) {
          return 'N';
        } else
        if (corner.x > max_x) {
          return 'W';
        } else {
          return 'N';
        }
      }
    },

    _getNodeCornerPositions: function(pos, node) {
      // Get positions of all four corners of a node
      var n = JX.Vector.getDim(node);
      return [new JX.Vector(pos.x, pos.y),
              new JX.Vector(pos.x + n.x, pos.y),
              new JX.Vector(pos.x, pos.y + n.y),
              new JX.Vector(pos.x + n.x, pos.y + n.y)];
    },

    _setAnchor: function (align) {
      // Orient the little tail
      JX.DOM.alterClass(this._node, 'jx-tooltip-align-' + align, true);
    },

    hide : function() {
      if (this._node) {
        JX.DOM.remove(this._node);
        this._node = null;
      }
    }
  }
});
