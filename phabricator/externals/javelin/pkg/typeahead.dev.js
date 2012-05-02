/**
 * @requires javelin-install
 *           javelin-dom
 *           javelin-vector
 *           javelin-util
 * @provides javelin-typeahead
 * @javelin
 */

/**
 * A typeahead is a UI component similar to a text input, except that it
 * suggests some set of results (like friends' names, common searches, or
 * repository paths) as the user types them. Familiar examples of this UI
 * include Google Suggest, the Facebook search box, and OS X's Spotlight
 * feature.
 *
 * To build a @{JX.Typeahead}, you need to do four things:
 *
 *  1. Construct it, passing some DOM nodes for it to attach to. See the
 *     constructor for more information.
 *  2. Attach a datasource by calling setDatasource() with a valid datasource,
 *     often a @{JX.TypeaheadPreloadedSource}.
 *  3. Configure any special options that you want.
 *  4. Call start().
 *
 * If you do this correctly, a dropdown menu should appear under the input as
 * the user types, suggesting matching results.
 *
 * @task build        Building a Typeahead
 * @task datasource   Configuring a Datasource
 * @task config       Configuring Options
 * @task start        Activating a Typeahead
 * @task control      Controlling Typeaheads from Javascript
 * @task internal     Internal Methods
 * @group control
 */
JX.install('Typeahead', {
  /**
   * Construct a new Typeahead on some "hardpoint". At a minimum, the hardpoint
   * should be a ##<div>## with "position: relative;" wrapped around a text
   * ##<input>##. The typeahead's dropdown suggestions will be appended to the
   * hardpoint in the DOM. Basically, this is the bare minimum requirement:
   *
   *   LANG=HTML
   *   <div style="position: relative;">
   *     <input type="text" />
   *   </div>
   *
   * Then get a reference to the ##<div>## and pass it as 'hardpoint', and pass
   * the ##<input>## as 'control'. This will enhance your boring old
   * ##<input />## with amazing typeahead powers.
   *
   * On the Facebook/Tools stack, ##<javelin:typeahead-template />## can build
   * this for you.
   *
   * @param Node  "Hardpoint", basically an anchorpoint in the document which
   *              the typeahead can append its suggestion menu to.
   * @param Node? Actual ##<input />## to use; if not provided, the typeahead
   *              will just look for a (solitary) input inside the hardpoint.
   * @task build
   */
  construct : function(hardpoint, control) {
    this._hardpoint = hardpoint;
    this._control = control || JX.DOM.find(hardpoint, 'input');

    this._root = JX.$N(
      'div',
      {className: 'jx-typeahead-results'});
    this._display = [];

    this._listener = JX.DOM.listen(
      this._control,
      ['focus', 'blur', 'keypress', 'keydown', 'input'],
      null,
      JX.bind(this, this.handleEvent));

    JX.DOM.listen(
      this._root,
      ['mouseover', 'mouseout'],
      null,
      JX.bind(this, this._onmouse));

    JX.DOM.listen(
      this._root,
      'mousedown',
      'tag:a',
      JX.bind(this, function(e) {
        if (!e.isRightButton()) {
          this._choose(e.getNode('tag:a'));
        }
        e.prevent();
      }));

  },

  events : ['choose', 'query', 'start', 'change', 'show'],

  properties : {

    /**
     * Boolean. If true (default), the user is permitted to submit the typeahead
     * with a custom or empty selection. This is a good behavior if the
     * typeahead is attached to something like a search input, where the user
     * might type a freeform query or select from a list of suggestions.
     * However, sometimes you require a specific input (e.g., choosing which
     * user owns something), in which case you can prevent null selections.
     *
     * @task config
     */
    allowNullSelection : true
  },

  members : {
    _root : null,
    _control : null,
    _hardpoint : null,
    _listener : null,
    _value : null,
    _stop : false,
    _focus : -1,
    _focused : false,
    _placeholderVisible : false,
    _placeholder : null,
    _display : null,
    _datasource : null,

    /**
     * Activate your properly configured typeahead. It won't do anything until
     * you call this method!
     *
     * @task start
     * @return void
     */
    start : function() {
      this.invoke('start');
      if (__DEV__) {
        if (!this._datasource) {
          throw new Error(
            "JX.Typeahead.start(): " +
            "No datasource configured. Create a datasource and call " +
            "setDatasource().");
        }
      }
      this.updatePlaceholder();
    },


    /**
     * Configure a datasource, which is where the Typeahead gets suggestions
     * from. See @{JX.TypeaheadDatasource} for more information. You must
     * provide exactly one datasource.
     *
     * @task datasource
     * @param JX.TypeaheadDatasource The datasource which the typeahead will
     *                               draw from.
     */
    setDatasource : function(datasource) {
      if (__DEV__) {
        if (this._datasource) {
          throw new Error(
            "JX.Typeahead.setDatasource(): " +
            "Typeahead already has a datasource.");
        }
      }
      datasource.listen('waiting', JX.bind(this, this.waitForResults));
      datasource.listen('resultsready', JX.bind(this, this.showResults));
      datasource.bindToTypeahead(this);
      this._datasource = datasource;
    },

    /**
     * Override the <input /> selected in the constructor with some other input.
     * This is primarily useful when building a control on top of the typeahead,
     * like @{JX.Tokenizer}.
     *
     * @task config
     * @param node An <input /> node to use as the primary control.
     */
    setInputNode : function(input) {
      this._control = input;
      return this;
    },


    /**
     * Hide the typeahead's dropdown suggestion menu.
     *
     * @task control
     * @return void
     */
    hide : function() {
      this._changeFocus(Number.NEGATIVE_INFINITY);
      this._display = [];
      this._moused = false;
      JX.DOM.hide(this._root);
    },


    /**
     * Show a given result set in the typeahead's dropdown suggestion menu.
     * Normally, you don't call this method directly. Usually it gets called
     * in response to events from the datasource you have configured.
     *
     * @task   control
     * @param  list List of ##<a />## tags to show as suggestions/results.
     * @return void
     */
    showResults : function(results) {
      var obj = {show: results};
      var e = this.invoke('show', obj);
      this._display = obj.show;

      if (this._display.length && !e.getPrevented()) {
        JX.DOM.setContent(this._root, this._display);
        this._changeFocus(Number.NEGATIVE_INFINITY);
        var d = JX.Vector.getDim(this._hardpoint);
        d.x = 0;
        d.setPos(this._root);
        if (this._root.parentNode !== this._hardpoint) {
          this._hardpoint.appendChild(this._root);
        }
        JX.DOM.show(this._root);
      } else {
        this.hide();
      }
    },

    refresh : function() {
      if (this._stop) {
        return;
      }

      this._value = this._control.value;
      this.invoke('change', this._value);
    },
    /**
     * Show a "waiting for results" UI in place of the typeahead's dropdown
     * suggestion menu. NOTE: currently there's no such UI, lolol.
     *
     * @task control
     * @return void
     */
    waitForResults : function() {
      // TODO: Build some sort of fancy spinner or "..." type UI here to
      // visually indicate that we're waiting on the server.
      // Wait on the datasource 'complete' event for hiding the spinner.
      this.hide();
    },

    /**
     * @task internal
     */
    _onmouse : function(event) {
      this._moused = (event.getType() == 'mouseover');
      this._drawFocus();
    },


    /**
     * @task internal
     */
    _changeFocus : function(d) {
      var n = Math.min(Math.max(-1, this._focus + d), this._display.length - 1);
      if (!this.getAllowNullSelection()) {
        n = Math.max(0, n);
      }
      if (this._focus >= 0 && this._focus < this._display.length) {
        JX.DOM.alterClass(this._display[this._focus], 'focused', false);
      }
      this._focus = n;
      this._drawFocus();
      return true;
    },


    /**
     * @task internal
     */
    _drawFocus : function() {
      var f = this._display[this._focus];
      if (f) {
        JX.DOM.alterClass(f, 'focused', !this._moused);
      }
    },


    /**
     * @task internal
     */
    _choose : function(target) {
      var result = this.invoke('choose', target);
      if (result.getPrevented()) {
        return;
      }

      this._control.value = target.name;
      this.hide();
    },


    /**
     * @task control
     */
    clear : function() {
      this._control.value = '';
      this.hide();
    },


    /**
     * @task control
     */
    disable : function() {
      this._control.blur();
      this._control.disabled = true;
      this._stop = true;
    },


    /**
     * @task control
     */
    submit : function() {
      if (this._focus >= 0 && this._display[this._focus]) {
        this._choose(this._display[this._focus]);
        return true;
      } else {
        result = this.invoke('query', this._control.value);
        if (result.getPrevented()) {
          return true;
        }
      }
      return false;
    },

    setValue : function(value) {
      this._control.value = value;
    },

    getValue : function() {
      return this._control.value;
    },

    /**
     * @task internal
     */
    _update : function(event) {

      if (event.getType() == 'focus') {
        this._focused = true;
        this.updatePlaceholder();
      }

      var k = event.getSpecialKey();
      if (k && event.getType() == 'keydown') {
        switch (k) {
          case 'up':
            if (this._display.length && this._changeFocus(-1)) {
              event.prevent();
            }
            break;
          case 'down':
            if (this._display.length && this._changeFocus(1)) {
              event.prevent();
            }
            break;
          case 'return':
            if (this.submit()) {
              event.prevent();
              return;
            }
            break;
          case 'esc':
            if (this._display.length && this.getAllowNullSelection()) {
              this.hide();
              event.prevent();
            }
            break;
          case 'tab':
            // If the user tabs out of the field, don't refresh.
            return;
        }
      }

      // We need to defer because the keystroke won't be present in the input's
      // value field yet.
      setTimeout(JX.bind(this, function() {
        if (this._value == this._control.value) {
          // The typeahead value hasn't changed.
          return;
        }
        this.refresh();
      }), 0);
    },

    /**
     * This method is pretty much internal but @{JX.Tokenizer} needs access to
     * it for delegation. You might also need to delegate events here if you
     * build some kind of meta-control.
     *
     * Reacts to user events in accordance to configuration.
     *
     * @task internal
     * @param JX.Event User event, like a click or keypress.
     * @return void
     */
    handleEvent : function(e) {
      if (this._stop || e.getPrevented()) {
        return;
      }
      var type = e.getType();
      if (type == 'blur') {
        this._focused = false;
        this.updatePlaceholder();
        this.hide();
      } else {
        this._update(e);
      }
    },

    removeListener : function() {
      if (this._listener) {
        this._listener.remove();
      }
    },


    /**
     * Set a string to display in the control when it is not focused, like
     * "Type a user's name...". This string hints to the user how to use the
     * control.
     *
     * When the string is displayed, the input will have class
     * "jx-typeahead-placeholder".
     *
     * @param string Placeholder string, or null for no placeholder.
     * @return this
     *
     * @task config
     */
    setPlaceholder : function(string) {
      this._placeholder = string;
      this.updatePlaceholder();
      return this;
    },


    /**
     * Update the control to either show or hide the placeholder text as
     * necessary.
     *
     * @return void
     * @task internal
     */
    updatePlaceholder : function() {

      if (this._placeholderVisible) {
        // If the placeholder is visible, we want to hide if the control has
        // been focused or the placeholder has been removed.
        if (this._focused || !this._placeholder) {
          this._placeholderVisible = false;
          this._control.value = '';
        }
      } else if (!this._focused) {
        // If the placeholder is not visible, we want to show it if the control
        // has benen blurred.
        if (this._placeholder) {
          this._placeholderVisible = true;
        }
      }

      if (this._placeholderVisible) {
        // We need to resist the Tokenizer wiping the input on blur.
        this._control.value = this._placeholder;
      }

      JX.DOM.alterClass(
        this._control,
        'jx-typeahead-placeholder',
        this._placeholderVisible);
    }
  }
});


/**
 * @requires javelin-install
 * @provides javelin-typeahead-normalizer
 * @javelin
 */

/**
 * @group control
 */
JX.install('TypeaheadNormalizer', {
  statics : {
    /**
     * Normalizes a string by lowercasing it and stripping out extra spaces
     * and punctuation.
     *
     * @param string
     * @return string Normalized string.
     */
    normalize : function(str) {
      return ('' + str)
        .toLocaleLowerCase()
        .replace(/[\.,-\/#!$%\^&\*;:{}=\-_`~()]/g, '')
        .replace(/ +/g, ' ')
        .replace(/^\s*|\s*$/g, '');
    }
  }
});


/**
 * @requires javelin-install
 *           javelin-util
 *           javelin-dom
 *           javelin-typeahead-normalizer
 * @provides javelin-typeahead-source
 * @javelin
 */

/**
 * @group control
 */
JX.install('TypeaheadSource', {
  construct : function() {
    this._raw = {};
    this._lookup = {};
    this.setNormalizer(JX.TypeaheadNormalizer.normalize);
    this._excludeIDs = {};
  },

  events : ['waiting', 'resultsready', 'complete'],

  properties : {

    /**
     * Allows you to specify a function which will be used to normalize strings.
     * Strings are normalized before being tokenized, and before being sent to
     * the server. The purpose of normalization is to strip out irrelevant data,
     * like uppercase/lowercase, extra spaces, or punctuation. By default,
     * the @{JX.TypeaheadNormalizer} is used to normalize strings, but you may
     * want to provide a different normalizer, particiularly if there are
     * special characters with semantic meaning in your object names.
     *
     * @param function
     */
    normalizer : null,

    /**
     * If a typeahead query should be processed before being normalized and
     * tokenized, specify a queryExtractor.
     *
     * @param function
     */
    queryExtractor : null,

    /**
     * Transformers convert data from a wire format to a runtime format. The
     * transformation mechanism allows you to choose an efficient wire format
     * and then expand it on the client side, rather than duplicating data
     * over the wire. The transformation is applied to objects passed to
     * addResult(). It should accept whatever sort of object you ship over the
     * wire, and produce a dictionary with these keys:
     *
     *    - **id**: a unique id for each object.
     *    - **name**: the string used for matching against user input.
     *    - **uri**: the URI corresponding with the object (must be present
     *      but need not be meaningful)
     *    - **display**: the text or nodes to show in the DOM. Usually just the
     *      same as ##name##.
     *
     * The default transformer expects a three element list with elements
     * [name, uri, id]. It assigns the first element to both ##name## and
     * ##display##.
     *
     * @param function
     */
    transformer : null,

    /**
     * Configures the maximum number of suggestions shown in the typeahead
     * dropdown.
     *
     * @param int
     */
    maximumResultCount : 5,

    /**
     * Optional function which is used to sort results. Inputs are the input
     * string, the list of matches, and a default comparator. The function
     * should sort the list for display. This is the minimum useful
     * implementation:
     *
     *   function(value, list, comparator) {
     *     list.sort(comparator);
     *   }
     *
     * Alternatively, you may pursue more creative implementations.
     *
     * The `value` is a raw string; you can bind the datasource into the
     * function and use normalize() or tokenize() to parse it.
     *
     * The `list` is a list of objects returned from the transformer function,
     * see the `transformer` property. These are the objects in the list which
     * match the value.
     *
     * The `comparator` is a sort callback which implements sensible default
     * sorting rules (e.g., alphabetic order), which you can use as a fallback
     * if you just want to tweak the results (e.g., put some items at the top).
     *
     * The function is called after the user types some text, immediately before
     * the possible completion results are displayed to the user.
     *
     * @param function
     */
    sortHandler : null

  },

  members : {
    _raw : null,
    _lookup : null,
    _excludeIDs : null,

    bindToTypeahead : function(typeahead) {
      typeahead.listen('change', JX.bind(this, this.didChange));
      typeahead.listen('start', JX.bind(this, this.didStart));
    },

    didChange : function(value) {
      return;
    },

    didStart : function() {
      return;
    },

    clearCache : function() {
      this._raw = {};
      this._lookup = {};
    },

    addExcludeID : function(id) {
      if (id) {
        this._excludeIDs[id] = true;
      }
    },

    removeExcludeID : function (id) {
      if (id) {
        delete this._excludeIDs[id];
      }
    },

    addResult : function(obj) {
      obj = (this.getTransformer() || this._defaultTransformer)(obj);

      if (obj && obj.id && this._excludeIDs[obj.id]) {
        return;
      }

      if (obj.id in this._raw) {
        // We're already aware of this result. This will happen if someone
        // searches for "zeb" and then for "zebra" with a
        // TypeaheadRequestSource, for example, or the datasource just doesn't
        // dedupe things properly. Whatever the case, just ignore it.
        return;
      }

      if (__DEV__) {
        for (var k in {name : 1, id : 1, display : 1, uri : 1}) {
          if (!(k in obj)) {
            throw new Error(
              "JX.TypeaheadSource.addResult(): " +
              "result must have properties 'name', 'id', 'uri' and 'display'.");
          }
        }
      }

      this._raw[obj.id] = obj;
      var t = this.tokenize(obj.name);
      for (var jj = 0; jj < t.length; ++jj) {
        this._lookup[t[jj]] = this._lookup[t[jj]] || [];
        this._lookup[t[jj]].push(obj.id);
      }
    },

    waitForResults : function() {
      this.invoke('waiting');
      return this;
    },

    matchResults : function(value) {

      // This table keeps track of the number of tokens each potential match
      // has actually matched. When we're done, the real matches are those
      // which have matched every token (so the value is equal to the token
      // list length).
      var match_count = {};

      // This keeps track of distinct matches. If the user searches for
      // something like "Chris C" against "Chris Cox", the "C" will match
      // both fragments. We need to make sure we only count distinct matches.
      var match_fragments = {};

      var matched = {};
      var seen = {};

      var query_extractor = this.getQueryExtractor();
      if (query_extractor) {
        value = query_extractor(value);
      }
      var t = this.tokenize(value);

      // Sort tokens by longest-first. We match each name fragment with at
      // most one token.
      t.sort(function(u, v) { return v.length - u.length; });

      for (var ii = 0; ii < t.length; ++ii) {
        // Do something reasonable if the user types the same token twice; this
        // is sort of stupid so maybe kill it?
        if (t[ii] in seen) {
          t.splice(ii--, 1);
          continue;
        }
        seen[t[ii]] = true;
        var fragment = t[ii];
        for (var name_fragment in this._lookup) {
          if (name_fragment.substr(0, fragment.length) === fragment) {
            if (!(name_fragment in matched)) {
              matched[name_fragment] = true;
            } else {
              continue;
            }
            var l = this._lookup[name_fragment];
            for (var jj = 0; jj < l.length; ++jj) {
              var match_id = l[jj];
              if (!match_fragments[match_id]) {
                match_fragments[match_id] = {};
              }
              if (!(fragment in match_fragments[match_id])) {
                match_fragments[match_id][fragment] = true;
                match_count[match_id] = (match_count[match_id] || 0) + 1;
              }
            }
          }
        }
      }

      var hits = [];
      for (var k in match_count) {
        if (match_count[k] == t.length) {
          hits.push(k);
        }
      }

      this.sortHits(value, hits);

      var nodes = this.renderNodes(value, hits);
      this.invoke('resultsready', nodes);
      this.invoke('complete');
    },

    sortHits : function(value, hits) {
      var objs = [];
      for (var ii = 0; ii < hits.length; ii++) {
        objs.push(this._raw[hits[ii]]);
      }

       var default_comparator = function(u, v) {
         var key_u = u.sort || u.name;
         var key_v = v.sort || v.name;
         return key_u.localeCompare(key_v);
      };

      var handler = this.getSortHandler() || function(value, list, cmp) {
        list.sort(cmp);
      };

      handler(value, objs, default_comparator);

      hits.splice(0, hits.length);
      for (var ii = 0; ii < objs.length; ii++) {
        hits.push(objs[ii].id);
      }
    },

    renderNodes : function(value, hits) {
      var n = Math.min(this.getMaximumResultCount(), hits.length);
      var nodes = [];
      for (var kk = 0; kk < n; kk++) {
        nodes.push(this.createNode(this._raw[hits[kk]]));
      }
      return nodes;
    },

    createNode : function(data) {
      return JX.$N(
        'a',
        {
          href: data.uri,
          name: data.name,
          rel: data.id,
          className: 'jx-result'
        },
        data.display
      );
    },

    normalize : function(str) {
      return this.getNormalizer()(str);
    },
    tokenize : function(str) {
      str = this.normalize(str);
      if (!str.length) {
        return [];
      }
      return str.split(/\s/g);
    },
    _defaultTransformer : function(object) {
      return {
        name : object[0],
        display : object[0],
        uri : object[1],
        id : object[2]
      };
    }
  }
});


/**
 * @requires javelin-install
 *           javelin-util
 *           javelin-stratcom
 *           javelin-request
 *           javelin-typeahead-source
 * @provides javelin-typeahead-preloaded-source
 * @javelin
 */

/**
 * Simple datasource that loads all possible results from a single call to a
 * URI. This is appropriate if the total data size is small (up to perhaps a
 * few thousand items). If you have more items so you can't ship them down to
 * the client in one repsonse, use @{JX.TypeaheadOnDemandSource}.
 *
 * @group control
 */
JX.install('TypeaheadPreloadedSource', {

  extend : 'TypeaheadSource',

  construct : function(uri) {
    JX.TypeaheadSource.call(this);
    this.uri = uri;
  },

  members : {

    ready : false,
    uri : null,
    lastValue : null,

    didChange : function(value) {
      if (this.ready) {
        this.matchResults(value);
      } else {
        this.lastValue = value;
        this.waitForResults();
      }
    },

    didStart : function() {
      var r = new JX.Request(this.uri, JX.bind(this, this.ondata));
      r.setMethod('GET');
      r.send();
    },

    ondata : function(results) {
      for (var ii = 0; ii < results.length; ++ii) {
        this.addResult(results[ii]);
      }
      if (this.lastValue !== null) {
        this.matchResults(this.lastValue);
      }
      this.ready = true;
    }
  }
});





/**
 * @requires javelin-install
 *           javelin-util
 *           javelin-stratcom
 *           javelin-request
 *           javelin-typeahead-source
 * @provides javelin-typeahead-ondemand-source
 * @javelin
 */

/**
 * @group control
 */
JX.install('TypeaheadOnDemandSource', {

  extend : 'TypeaheadSource',

  construct : function(uri) {
    JX.TypeaheadSource.call(this);
    this.uri = uri;
    this.haveData = {
      '' : true
    };
  },

  properties : {
    /**
     * Configures how many milliseconds we wait after the user stops typing to
     * send a request to the server. Setting a value of 250 means "wait 250
     * milliseconds after the user stops typing to request typeahead data".
     * Higher values reduce server load but make the typeahead less responsive.
     */
    queryDelay : 125,
    /**
     * Auxiliary data to pass along when sending the query for server results.
     */
    auxiliaryData : {}
  },

  members : {
    uri : null,
    lastChange : null,
    haveData : null,

    didChange : function(value) {
      this.lastChange = JX.now();
      value = this.normalize(value);

      if (this.haveData[value]) {
        this.matchResults(value);
      } else {
        this.waitForResults();
        setTimeout(
          JX.bind(this, this.sendRequest, this.lastChange, value),
          this.getQueryDelay()
        );
      }
    },

    sendRequest : function(when, value) {
      if (when != this.lastChange) {
        return;
      }
      var r = new JX.Request(
        this.uri,
        JX.bind(this, this.ondata, this.lastChange, value));
      r.setMethod('GET');
      r.setData(JX.copy(this.getAuxiliaryData(), {q : value}));
      r.send();
    },

    ondata : function(when, value, results) {
      if (results) {
        for (var ii = 0; ii < results.length; ii++) {
          this.addResult(results[ii]);
        }
      }
      this.haveData[value] = true;
      if (when != this.lastChange) {
        return;
      }
      this.matchResults(value);
    }
  }
});




/**
 * @requires javelin-dom
 *           javelin-util
 *           javelin-stratcom
 *           javelin-install
 * @provides javelin-tokenizer
 * @javelin
 */

/**
 * A tokenizer is a UI component similar to a text input, except that it
 * allows the user to input a list of items ("tokens"), generally from a fixed
 * set of results. A familiar example of this UI is the "To:" field of most
 * email clients, where the control autocompletes addresses from the user's
 * address book.
 *
 * @{JX.Tokenizer} is built on top of @{JX.Typeahead}, and primarily adds the
 * ability to choose multiple items.
 *
 * To build a @{JX.Tokenizer}, you need to do four things:
 *
 *  1. Construct it, padding a DOM node for it to attach to. See the constructor
 *     for more information.
 *  2. Build a {@JX.Typeahead} and configure it with setTypeahead().
 *  3. Configure any special options you want.
 *  4. Call start().
 *
 * If you do this correctly, the input should suggest items and enter them as
 * tokens as the user types.
 *
 * @group control
 */
JX.install('Tokenizer', {
  construct : function(containerNode) {
    this._containerNode = containerNode;
  },

  events : [
    /**
     * Emitted when the value of the tokenizer changes, similar to an 'onchange'
     * from a <select />.
     */
    'change'],

  properties : {
    limit : null,
    nextInput : null
  },

  members : {
    _containerNode : null,
    _root : null,
    _focus : null,
    _orig : null,
    _typeahead : null,
    _tokenid : 0,
    _tokens : null,
    _tokenMap : null,
    _initialValue : null,
    _seq : 0,
    _lastvalue : null,
    _placeholder : null,

    start : function() {
      if (__DEV__) {
        if (!this._typeahead) {
          throw new Error(
            'JX.Tokenizer.start(): ' +
            'No typeahead configured! Use setTypeahead() to provide a ' +
            'typeahead.');
        }
      }

      this._orig = JX.DOM.find(this._containerNode, 'input', 'tokenizer-input');
      this._tokens = [];
      this._tokenMap = {};

      var focus = this.buildInput(this._orig.value);
      this._focus = focus;

      var input_container = JX.DOM.scry(
        this._containerNode,
        'div',
        'tokenizer-input-container'
      );
      input_container = input_container[0] || this._containerNode;

      JX.DOM.listen(
        focus,
        ['click', 'focus', 'blur', 'keydown', 'keypress'],
        null,
        JX.bind(this, this.handleEvent));

      JX.DOM.listen(
        input_container,
        'click',
        null,
        JX.bind(
          this,
          function(e) {
            if (e.getNode('remove')) {
              this._remove(e.getNodeData('token').key);
            } else if (e.getTarget() == this._root) {
              this.focus();
            }
          }));

      var root = JX.$N('div');
      root.id = this._orig.id;
      JX.DOM.alterClass(root, 'jx-tokenizer', true);
      root.style.cursor = 'text';
      this._root = root;

      root.appendChild(focus);

      var typeahead = this._typeahead;
      typeahead.setInputNode(this._focus);
      typeahead.start();

      setTimeout(JX.bind(this, function() {
        var container = this._orig.parentNode;
        JX.DOM.setContent(container, root);
        var map = this._initialValue || {};
        for (var k in map) {
          this.addToken(k, map[k]);
        }
        JX.DOM.appendContent(
          root,
          JX.$N('div', {style: {clear: 'both'}})
        );
        this._redraw();
      }), 0);
    },

    setInitialValue : function(map) {
      this._initialValue = map;
      return this;
    },

    setTypeahead : function(typeahead) {

      typeahead.setAllowNullSelection(false);
      typeahead.removeListener();

      typeahead.listen(
        'choose',
        JX.bind(this, function(result) {
          JX.Stratcom.context().prevent();
          if (this.addToken(result.rel, result.name)) {
            if (this.shouldHideResultsOnChoose()) {
              this._typeahead.hide();
            }
            this._focus.value = '';
            this._redraw();
            this.focus();
          }
        })
      );

      typeahead.listen(
        'query',
        JX.bind(
          this,
          function(query) {

          // TODO: We should emit a 'query' event here to allow the caller to
          // generate tokens on the fly, e.g. email addresses or other freeform
          // or algorithmic tokens.

          // Then do this if something handles the event.
          // this._focus.value = '';
          // this._redraw();
          // this.focus();

          if (query.length) {
            // Prevent this event if there's any text, so that we don't submit
            // the form (either we created a token or we failed to create a
            // token; in either case we shouldn't submit). If the query is
            // empty, allow the event so that the form submission takes place.
            JX.Stratcom.context().prevent();
          }
        }));

      this._typeahead = typeahead;

      return this;
    },

    shouldHideResultsOnChoose : function() {
      return true;
    },

    handleEvent : function(e) {

      this._typeahead.handleEvent(e);
      if (e.getPrevented()) {
        return;
      }

      if (e.getType() == 'click') {
        if (e.getTarget() == this._root) {
          this.focus();
          e.prevent();
          return;
        }
      } else if (e.getType() == 'keydown') {
        this._onkeydown(e);
      } else if (e.getType() == 'blur') {
        this._focus.value = '';
        this._redraw();

        // Explicitly update the placeholder since we just wiped the field
        // value.
        this._typeahead.updatePlaceholder();
      }
    },

    refresh : function() {
      this._redraw(true);
      return this;
    },

    _redraw : function(force) {

      // If there are tokens in the tokenizer, never show a placeholder.
      // Otherwise, show one if one is configured.
      if (JX.keys(this._tokenMap).length) {
        this._typeahead.setPlaceholder(null);
      } else {
        this._typeahead.setPlaceholder(this._placeholder);
      }

      var focus = this._focus;

      if (focus.value === this._lastvalue && !force) {
        return;
      }
      this._lastvalue = focus.value;

      var root  = this._root;
      var metrics = JX.DOM.textMetrics(
        this._focus,
        'jx-tokenizer-metrics');
      metrics.y = null;
      metrics.x += 24;
      metrics.setDim(focus);

      // This is a pretty ugly hack to force a redraw after copy/paste in
      // Firefox. If we don't do this, it doesn't redraw the input so pasting
      // in an email address doesn't give you a very good behavior.
      focus.value = focus.value;
    },

    setPlaceholder : function(string) {
      this._placeholder = string;
      return this;
    },

    addToken : function(key, value) {
      if (key in this._tokenMap) {
        return false;
      }

      var focus = this._focus;
      var root = this._root;
      var token = this.buildToken(key, value);

      this._tokenMap[key] = {
        value : value,
        key : key,
        node : token
      };
      this._tokens.push(key);

      root.insertBefore(token, focus);

      this.invoke('change', this);

      return true;
    },

    buildInput: function(value) {
      return JX.$N('input', {
        className: 'jx-tokenizer-input',
        type: 'text',
        autocomplete: 'off',
        value: value
      });
    },

    /**
     * Generate a token based on a key and value. The "token" and "remove"
     * sigils are observed by a listener in start().
     */
    buildToken: function(key, value) {
      var input = JX.$N('input', {
        type: 'hidden',
        value: key,
        name: this._orig.name + '[' + (this._seq++) + ']'
      });

      var remove = JX.$N('a', {
        className: 'jx-tokenizer-x',
        sigil: 'remove'
      }, '\u00d7'); // U+00D7 multiplication sign

      return JX.$N('a', {
        className: 'jx-tokenizer-token',
        sigil: 'token',
        meta: {key: key}
      }, [value, input, remove]);
    },

    getTokens : function() {
      var result = {};
      for (var key in this._tokenMap) {
        result[key] = this._tokenMap[key].value;
      }
      return result;
    },

    _onkeydown : function(e) {
      var focus = this._focus;
      var root = this._root;
      switch (e.getSpecialKey()) {
        case 'tab':
          var completed = this._typeahead.submit();
          if (this.getNextInput()) {
            if (!completed) {
              this._focus.value = '';
            }
            setTimeout(JX.bind(this, function() {
              this.getNextInput().focus();
            }), 0);
          }
          break;
        case 'delete':
          if (!this._focus.value.length) {
            var tok;
            while (tok = this._tokens.pop()) {
              if (this._remove(tok)) {
                break;
              }
            }
          }
          break;
        case 'return':
          // Don't subject this to token limits.
          break;
        default:
          if (this.getLimit() &&
              JX.keys(this._tokenMap).length == this.getLimit()) {
            e.prevent();
          }
          setTimeout(JX.bind(this, this._redraw), 0);
          break;
      }
    },

    _remove : function(index) {
      if (!this._tokenMap[index]) {
        return false;
      }
      JX.DOM.remove(this._tokenMap[index].node);
      delete this._tokenMap[index];
      this._redraw(true);
      this.focus();

      this.invoke('change', this);

      return true;
    },

    focus : function() {
      var focus = this._focus;
      JX.DOM.show(focus);
      setTimeout(function() { JX.DOM.focus(focus); }, 0);
    }
  }
});
