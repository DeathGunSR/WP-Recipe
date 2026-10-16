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
