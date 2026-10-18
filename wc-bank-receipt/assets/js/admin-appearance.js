/**
 * WC Bank Receipt — «ظاهر و استایل» live preview.
 *
 * SCOPE
 * -----
 * This file runs on **one** wp-admin screen and does exactly one thing: it keeps the
 * sample panel on that screen in sync with the form fields, so an administrator sees a
 * colour, a radius or a font size before saving it.
 *
 * It is not part of the customer-facing component: nothing here loads on a front-end
 * page, nothing here touches a real order, card or receipt file, and nothing here is the
 * source of truth. The *saved* appearance is always the value the server validated and
 * stored (`WCR_Design_Tokens::sanitize()`), and the value the front end prints is the one
 * the server resolved (`WCR_Design_Tokens::resolve()`). This script only mirrors that
 * resolution locally to draw the preview — hence the deliberate duplication of a short,
 * deterministic calculation and nothing else.
 *
 * SAFETY
 * ------
 *  - No globals: one IIFE, and the only page-level value is WordPress' own
 *    `wcrAppearance` config object (data, no behaviour).
 *  - The preview element is addressed with a data attribute, never an id, and only
 *    inside this form's screen.
 *  - Values are computed from the schema the server sent; an empty field means
 *    “automatic”, exactly like an empty POST field.
 *  - A failure here can only break the preview: the form still saves and the front end
 *    still renders from the stored settings.
 */
(function () {
  'use strict';

  var config = (typeof window.wcrAppearance === 'object' && window.wcrAppearance) || null;
  if (!config || !config.tokens) return;

  var form = document.querySelector('form[action="options.php"]');
  var preview = document.querySelector('[data-wcr-appearance-preview]');
  if (!form || !preview) return;

  var SCHEMA = config.tokens;
  var ORDER = [];
  for (var key in SCHEMA) {
    if (Object.prototype.hasOwnProperty.call(SCHEMA, key)) ORDER.push(key);
  }

  /* --------------------------------------------------------------- helpers */

  function field(token, attribute) {
    return form.querySelector('[' + attribute + '="' + token + '"]');
  }

  function trimNumber(value) {
    var out = String(value);
    if (out.indexOf('.') !== -1) out = out.replace(/0+$/, '').replace(/\.$/, '');
    return out === '' ? '0' : out;
  }

  /** Clamp a length exactly like the server does (per unit, then format). */
  function clampLength(number, unit, spec) {
    var range = null;
    if (spec.ranges && spec.ranges[unit]) range = spec.ranges[unit];
    else if (unit === 'px' && spec.min !== null && spec.max !== null) range = [spec.min, spec.max];
    if (!range) return '';

    var num = parseFloat(String(number).replace(/[^0-9.]/g, ''));
    if (isNaN(num)) return '';
    num = Math.min(parseFloat(range[1]), Math.max(parseFloat(range[0]), num));
    num = Math.round(num * 1000) / 1000;
    return trimNumber(num) + unit;
  }

  function clampRatio(number, spec) {
    var num = parseFloat(String(number).replace(/[^0-9.]/g, ''));
    if (isNaN(num)) return '';
    if (spec.min !== null && spec.max !== null) {
      num = Math.min(parseFloat(spec.max), Math.max(parseFloat(spec.min), num));
    }
    return trimNumber(Math.round(num * 100) / 100);
  }

  /** The value a field would submit, in its final CSS form ('' = automatic). */
  function fieldValue(token) {
    var spec = SCHEMA[token];
    if (!spec) return '';

    var el = field(token, 'data-wcr-token');

    if (spec.type === 'enum') {
      return (el && el.value && spec.options && spec.options[el.value]) ? spec.options[el.value] : '';
    }

    /* The type scale submits a number plus an explicit unit. */
    if (spec.ranges && spec.ranges.px) {
      var numEl = field(token, 'data-wcr-token-num');
      var unitEl = field(token, 'data-wcr-token-unit');
      var number = numEl ? String(numEl.value).trim() : '';
      var unit = unitEl ? unitEl.value : '';
      if (!number || !unit) return '';
      return clampLength(number, unit, spec);
    }

    if (!el) return '';
    var value = String(el.value).trim();
    if (value === '') return '';
    if (spec.type === 'length') return clampLength(value, 'px', spec);
    if (spec.type === 'ratio') return clampRatio(value, spec);
    return value; // colour: the server validates it, the preview just shows it
  }

  function merge(base, extra) {
    var out = {};
    var k;
    for (k in base) if (Object.prototype.hasOwnProperty.call(base, k)) out[k] = base[k];
    for (k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) out[k] = extra[k];
    return out;
  }

  /** Lighten (positive) or darken (negative) a hex colour — the server's shade_hex(). */
  function shadeHex(hex, percent) {
    var digits = String(hex).replace('#', '');
    if (!/^[0-9a-f]{6}$/i.test(digits)) return hex;
    var out = '#';
    for (var i = 0; i < 3; i++) {
      var part = Math.round(parseInt(digits.substr(i * 2, 2), 16) + (percent / 100) * 255);
      part = Math.max(0, Math.min(255, part));
      out += ('0' + part.toString(16)).slice(-2);
    }
    return out;
  }

  /**
   * The accent coupling, mirroring WCR_Design_Tokens::couple_accent(): one accent colour
   * fills the accent tokens that are still at their default and were not typed in.
   */
  function coupleAccent(tokens, explicit) {
    /* Nothing to couple unless the accent colour was actually chosen here. */
    if (!explicit['--wcrf-pri']) return tokens;
    var primary = tokens['--wcrf-pri'] || '';
    if (!/^#[0-9a-f]{6}$/i.test(primary)) return tokens;

    var family = {
      '--wcrf-pri-h': 12,
      '--wcrf-btn-bg': 0,
      '--wcrf-btn-bg-2': 12,
      '--wcrf-btn-bg-h': -18,
      '--wcrf-card-bg': 0,
      '--wcrf-card-bg-2': 22
    };

    for (var name in family) {
      if (!Object.prototype.hasOwnProperty.call(family, name)) continue;
      if (explicit[name]) continue;
      if (tokens[name] !== config.defaults[name]) continue;
      tokens[name] = family[name] === 0 ? primary : shadeHex(primary, family[name]);
    }
    return tokens;
  }

  /* ----------------------------------------------- the same priority order */

  function resolveTokens() {
    var tokens = merge({}, config.defaults);

    /* level 3 — the global appearance layer */
    var modeEl = form.querySelector('[data-wcr-appearance-mode]');
    var mode = modeEl ? modeEl.value : 'inherit';
    var presetEl = form.querySelector('[data-wcr-appearance-preset]');
    if (mode === 'preset' && presetEl && config.presets[presetEl.value]) {
      tokens = merge(tokens, config.presets[presetEl.value]);
    }

    var linkEls = form.querySelectorAll('[data-wcr-appearance-link]');
    for (var i = 0; i < linkEls.length; i++) {
      var slug = linkEls[i].value;
      var linkKey = linkEls[i].getAttribute('data-wcr-appearance-link');
      var map = (slug && config.links && config.links[linkKey]) ? config.links[linkKey] : null;
      if (!map) continue;
      for (var token in map) {
        if (Object.prototype.hasOwnProperty.call(map, token)) {
          tokens[token] = map[token].replace('__SLUG__', slug);
        }
      }
    }

    var explicit = {};
    for (var j = 0; j < ORDER.length; j++) {
      var value = fieldValue(ORDER[j]);
      if (value) {
        tokens[ORDER[j]] = value;
        explicit[ORDER[j]] = value;
      }
    }

    /* spacing: the density choice owns it unless it is “custom” */
    var densityEl = form.querySelector('[data-wcr-appearance-density]');
    var density = densityEl ? densityEl.value : 'comfortable';
    if (density !== 'custom' && config.densities && config.densities[density]) {
      tokens = merge(tokens, config.densities[density]);
    }

    var coupleEl = form.querySelector('[data-wcr-appearance-couple]');
    if (coupleEl && coupleEl.checked) tokens = coupleAccent(tokens, explicit);

    return tokens;
  }

  /** Only the values that differ from the stylesheet defaults — like the server. */
  function declarations() {
    var tokens = resolveTokens();
    var parts = [];
    for (var i = 0; i < ORDER.length; i++) {
      var token = ORDER[i];
      var value = tokens[token];
      if (!value || value === config.defaults[token]) continue;
      parts.push(token + ':' + value);
    }
    return parts.join(';');
  }

  /* ------------------------------------------------------------- rendering */

  var badge = null;

  function markDirty() {
    if (!badge) badge = document.querySelector('[data-wcr-appearance-state]');
    if (!badge || badge.getAttribute('data-wcr-dirty') === '1') return;
    badge.setAttribute('data-wcr-dirty', '1');
    badge.className = 'wcr-badge warn';
    badge.textContent = (config.strings && config.strings.dirty) || '';
  }

  function draw(dirty) {
    var decl = declarations();
    if (decl) preview.setAttribute('style', decl);
    else preview.removeAttribute('style');
    if (dirty) markDirty();
  }

  /* ------------------------------------------------------- element editor */

  /*
   * Interactive editing: click an element in the preview, get that element's settings.
   *
   * The panel is a VIEW, never a second store. Every control it renders proxies the real
   * field of the form (`data-wcr-token` / `-num` / `-unit`), writes into it and dispatches
   * `input`, so the existing live preview and the existing save path do all the work — and
   * an element click can never produce a value that the server would not have received
   * anyway. Clearing reuses the form's own “خودکار” button for the same reason.
   */
  var panel     = document.querySelector('[data-wcr-el-panel]');
  var panelBody = document.querySelector('[data-wcr-el-body]');
  var panelName = document.querySelector('[data-wcr-el-name]');
  var panelRows = document.querySelector('[data-wcr-el-rows]');
  var panelHint = document.querySelector('[data-wcr-el-empty]');
  var els       = config.elements || {};
  var tokenLabels = config.labels || {};
  var strings   = config.strings || {};
  var selected  = null;

  function controlTarget(token) {
    return field(token, 'data-wcr-token') || field(token, 'data-wcr-token-num');
  }

  /** Clear one token through the form's own “خودکار” button ('' = automatic). */
  function clearToken(token) {
    var button = form.querySelector('[data-wcr-clear="' + token + '"]');
    if (button) {
      button.click();
      return;
    }
    var text = field(token, 'data-wcr-token');
    if (text) text.value = '';
    var num = field(token, 'data-wcr-token-num');
    if (num) num.value = '';
    var unit = field(token, 'data-wcr-token-unit');
    if (unit) unit.value = '';
    draw(true);
  }

  function writeToken(token, spec, value) {
    var num = field(token, 'data-wcr-token-num');
    var unit = field(token, 'data-wcr-token-unit');

    if (num && unit) {
      // The type scale submits a number plus an explicit unit.
      var parsed = String(value).match(/^(\d+(?:\.\d+)?)([a-z]+)$/);
      if (value === '') { clearToken(token); return; }
      if (parsed && spec.ranges && spec.ranges[parsed[2]]) {
        num.value = parsed[1];
        unit.value = parsed[2];
      } else {
        num.value = value;
        if (!unit.value) unit.value = 'px';
      }
      draw(true);
      return;
    }

    var text = field(token, 'data-wcr-token');
    if (!text) return;
    text.value = value;
    draw(true);
  }

  function currentValue(token) {
    var spec = SCHEMA[token];
    if (!spec) return '';
    return fieldValue(token);
  }

  function buildRow(token) {
    var spec = SCHEMA[token];
    if (!spec) return null;

    var row = document.createElement('div');
    row.className = 'wcr-el-row';

    var label = document.createElement('label');
    label.className = 'wcr-el-label';
    label.textContent = tokenLabels[token] || token;

    var input;
    if (spec.type === 'enum') {
      input = document.createElement('select');
      var none = document.createElement('option');
      none.value = '';
      none.textContent = strings.automatic || '';
      input.appendChild(none);
      for (var key in spec.options) {
        if (!Object.prototype.hasOwnProperty.call(spec.options, key)) continue;
        var option = document.createElement('option');
        option.value = key;
        option.textContent = key;
        input.appendChild(option);
      }
    } else if (spec.type === 'color') {
      input = document.createElement('input');
      input.type = 'color';
      input.value = /^#[0-9a-f]{6}$/i.test(currentValue(token)) ? currentValue(token) : (config.defaults[token] || '#1565c0');
    } else if (spec.ranges && spec.ranges.px) {
      input = document.createElement('input');
      input.type = 'text';
      input.placeholder = config.defaults[token] || '';
      input.value = currentValue(token);
    } else {
      input = document.createElement('input');
      input.type = 'number';
      input.step = spec.type === 'ratio' ? '0.1' : '1';
      if (spec.type !== 'ratio') {
        if (spec.min !== null) input.min = spec.min;
        if (spec.max !== null) input.max = spec.max;
      }
      input.value = String(currentValue(token)).replace(/[^0-9.]/g, '');
    }
    input.className = 'wcr-el-input';
    input.setAttribute('data-wcr-el-token', token);

    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'wcr-btn wcr-btn-ghost wcr-el-clear';
    clear.textContent = strings.automatic || '';

    input.addEventListener('input', function () { writeToken(token, spec, this.value); });
    input.addEventListener('change', function () { writeToken(token, spec, this.value); });
    clear.addEventListener('click', function () { clearToken(token); refreshPanel(); });

    row.appendChild(label);
    row.appendChild(input);
    row.appendChild(clear);
    return row;
  }

  function refreshPanel() {
    if (!panelRows) return;
    while (panelRows.firstChild) panelRows.removeChild(panelRows.firstChild);
    if (!selected || !els[selected]) return;
    var tokens = els[selected].tokens || [];
    for (var i = 0; i < tokens.length; i++) {
      var row = buildRow(tokens[i]);
      if (row) panelRows.appendChild(row);
    }
  }

  function selectElement(el) {
    selected = el;
    var cells = preview.querySelectorAll('[data-wcr-el]');
    for (var i = 0; i < cells.length; i++) {
      cells[i].classList.toggle('is-selected', cells[i].getAttribute('data-wcr-el') === el);
    }
    if (!els[el]) return;
    if (panelName) panelName.textContent = (strings.element || '') + ' ' + els[el].label;
    if (panelBody) panelBody.hidden = false;
    if (panelHint) panelHint.hidden = true;
    refreshPanel();
  }

  if (panel && preview) {
    preview.addEventListener('click', function (event) {
      var node = event.target.closest ? event.target.closest('[data-wcr-el]') : null;
      if (!node) return;
      event.preventDefault();
      selectElement(node.getAttribute('data-wcr-el'));
    });
    var reset = panel.querySelector('[data-wcr-el-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        if (!selected || !els[selected]) return;
        var tokens = els[selected].tokens || [];
        for (var i = 0; i < tokens.length; i++) clearToken(tokens[i]);
        refreshPanel();
      });
    }
    /* Any change made in the real form must be reflected in the open panel. */
    form.addEventListener('input', function () { if (selected) refreshPanel(); });
  }

  /* --------------------------------------------------------------- wiring */

  form.addEventListener('input', function () { draw(true); });
  form.addEventListener('change', function () { draw(true); });

  /* The colour swatch is a convenience for the text field, which stays the field that
   * is actually submitted (so “automatic” can be expressed by emptying it). */
  var swatches = form.querySelectorAll('[data-wcr-swatch-for]');
  for (var s = 0; s < swatches.length; s++) {
    swatches[s].addEventListener('input', function () {
      var text = field(this.getAttribute('data-wcr-swatch-for'), 'data-wcr-token');
      if (text) text.value = this.value;
      draw(true);
    });
  }

  /* “خودکار” empties the field (or the number+unit pair) so the mode/theme layer
   * applies again — the same state as an empty POST field. */
  var clears = form.querySelectorAll('[data-wcr-clear]');
  for (var c = 0; c < clears.length; c++) {
    clears[c].addEventListener('click', function () {
      var token = this.getAttribute('data-wcr-clear');
      var text = field(token, 'data-wcr-token');
      if (text) text.value = '';
      var num = field(token, 'data-wcr-token-num');
      if (num) num.value = '';
      var unit = field(token, 'data-wcr-token-unit');
      if (unit) unit.value = '';
      draw(true);
    });
  }

  draw(false);
})();
