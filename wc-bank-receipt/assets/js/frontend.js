/**
 * WC Bank Receipt — front-end receipt panel behaviour.
 *
 * DESIGN CONTRACT
 * ---------------
 * 1. No globals. Everything lives inside this IIFE: no `window.wcrCopy`, no helper
 *    functions on `window`. The only page-level value is the WordPress-provided
 *    read-only config object `wcrFrontend` (strings, from wp_localize_script).
 * 2. No element ids. Every lookup starts from a `.wcrf-panel` root and uses classes or
 *    data attributes, so two panels on one page — or the same panel rendered twice by
 *    a builder preview — can never steal each other's elements, `document.getElementById`
 *    is never called, and no duplicate id can reach the markup.
 * 3. Each root is initialised exactly once (`data-wcr-init`). Listeners are attached to
 *    the root (event delegation) so re-rendering a panel cannot stack handlers.
 * 4. Progressive enhancement. The upload form works with JavaScript disabled: the file
 *    input covers the drop zone and the form posts normally. This script only adds the
 *    drag styling, the image preview and the copy button feedback.
 * 5. A failure here can never break the page: initialisation is wrapped in try/catch and
 *    every feature degrades to the plain HTML behaviour.
 */
(function () {
  'use strict';

  var config = (typeof window.wcrFrontend === 'object' && window.wcrFrontend) || {};
  var TEXT = {
    copyLabel: config.copyLabel || 'کپی شماره',
    copiedLabel: config.copiedLabel || '✓ کپی شد',
    copyFailed: config.copyFailed || 'کپی خودکار ممکن نشد؛ شماره را دستی انتخاب کنید.',
    expiredLabel: config.expiredLabel || 'پایان مهلت!'
  };

  /* ---------------------------------------------------------------- helpers */

  function panelOf(node) {
    var el = node && node.nodeType === 1 ? node : (node ? node.parentElement : null);
    while (el && el.nodeType === 1) {
      if (el.classList && el.classList.contains('wcrf-panel')) return el;
      el = el.parentElement;
    }
    return null;
  }

  /** Copy with the async Clipboard API, then the legacy path, then a manual selection. */
  function copyText(text) {
    var legacy = function () {
      var done = false;
      try {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        area.setSelectionRange(0, area.value.length);
        done = document.execCommand('copy');
        document.body.removeChild(area);
      } catch (e) {
        done = false;
      }
      return done;
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(
        function () { return true; },
        function () { return legacy(); }
      );
    }
    return Promise.resolve(legacy());
  }

  /** Last resort: select the value on screen so the visitor can copy it themselves. */
  function selectValue(panel, button) {
    var source = panel.querySelector('[data-wcr-copy-source]');
    if (!source || !window.getSelection || !document.createRange) return false;
    try {
      var range = document.createRange();
      range.selectNodeContents(source);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    } catch (e) {
      return false;
    }
    if (button) {
      button.textContent = TEXT.copyFailed;
      window.setTimeout(function () { button.textContent = TEXT.copyLabel; }, 2600);
    }
    return true;
  }

  /* ------------------------------------------------------------------ copy */

  function onCopyClick(event) {
    var button = event.target.closest ? event.target.closest('[data-wcr-copy]') : null;
    if (!button) return;
    var panel = panelOf(button);
    if (!panel) return;

    event.preventDefault();
    var value = button.getAttribute('data-wcr-copy') || '';

    copyText(value).then(function (ok) {
      if (ok) {
        button.textContent = TEXT.copiedLabel;
        button.classList.add('copied');
        window.setTimeout(function () {
          button.textContent = TEXT.copyLabel;
          button.classList.remove('copied');
        }, 2000);
      } else {
        selectValue(panel, button);
      }
    });
  }

  /* --------------------------------------------------------- upload preview */

  function showPreview(panel, file) {
    var wrap = panel.querySelector('[data-wcr-preview]');
    var img = panel.querySelector('[data-wcr-preview-img]');
    var name = panel.querySelector('[data-wcr-file-name]');
    if (!wrap || !file) return;

    if (name) name.textContent = file.name;
    wrap.hidden = false;

    if (img && file.type && file.type.indexOf('image/') === 0 && window.FileReader) {
      var reader = new FileReader();
      reader.onload = function (e) {
        img.setAttribute('src', e.target.result);
        img.hidden = false;
      };
      reader.readAsDataURL(file);
    } else if (img) {
      img.hidden = true;
      img.removeAttribute('src');
    }
  }

  function onZoneChange(event) {
    var input = event.target;
    if (!input.classList || !input.classList.contains('wcrf-file-input')) return;
    var panel = panelOf(input);
    if (!panel) return;
    showPreview(panel, input.files && input.files[0]);
  }

  function onZoneDragOver(event) {
    var zone = event.target.closest ? event.target.closest('.wcrf-drop-zone') : null;
    if (!zone) return;
    event.preventDefault();
    zone.classList.add('is-dragging');
  }

  function onZoneDragLeave(event) {
    var zone = event.target.closest ? event.target.closest('.wcrf-drop-zone') : null;
    if (!zone) return;
    zone.classList.remove('is-dragging');
  }

  function onZoneDrop(event) {
    var zone = event.target.closest ? event.target.closest('.wcrf-drop-zone') : null;
    if (!zone) return;
    event.preventDefault();
    zone.classList.remove('is-dragging');

    // The input sits on top of the zone, so a normal browser drop already filled it.
    // This is only the safety net for drops that landed on the zone's padding.
    var panel = panelOf(zone);
    var input = panel ? panel.querySelector('.wcrf-file-input') : null;
    var files = event.dataTransfer && event.dataTransfer.files;

    if (input && files && files.length) {
      if (!input.files || !input.files.length) {
        try {
          input.files = files;
        } catch (e) {
          return;
        }
        if (typeof window.Event === 'function') {
          input.dispatchEvent(new window.Event('change', { bubbles: true }));
        }
      } else {
        showPreview(panel, input.files[0]);
      }
    }
  }

  /* ------------------------------------------------------------- countdown */

  function startTimer(panel) {
    var box = panel.querySelector('[data-wcr-timer]');
    if (!box) return;

    var value = panel.querySelector('.wcrf-timer-val');
    var bar = panel.querySelector('.wcrf-progress-fill');
    var seconds = parseInt(box.getAttribute('data-remaining'), 10);
    var total = parseInt(box.getAttribute('data-total'), 10) || seconds;

    if (!value || isNaN(seconds) || seconds <= 0) return;

    var pad = function (n) { return String(n).padStart(2, '0'); };

    var tick = function () {
      if (seconds <= 0) {
        value.textContent = TEXT.expiredLabel;
        value.classList.add('urgent');
        box.classList.add('urgent');
        window.clearInterval(id);

        // Reload once so the server renders the expired state — unless the visitor is
        // in the middle of choosing a file.
        var input = panel.querySelector('.wcrf-file-input');
        var busy = input && input.files && input.files.length;
        if (bar) bar.style.width = '0%';
        if (!busy && !box.getAttribute('data-wcr-reloaded')) {
          box.setAttribute('data-wcr-reloaded', '1');
          window.setTimeout(function () { window.location.reload(); }, 1200);
        }
        return;
      }

      var h = Math.floor(seconds / 3600);
      var m = Math.floor((seconds % 3600) / 60);
      var s = seconds % 60;
      value.textContent = (h ? pad(h) + ':' : '') + pad(m) + ':' + pad(s);
      if (bar && total > 0) bar.style.width = Math.round((seconds / total) * 100) + '%';
      if (seconds < 300) {
        value.classList.add('urgent');
        box.classList.add('urgent');
      }
      seconds--;
    };

    tick();
    var id = window.setInterval(tick, 1000);
  }

  /* ----------------------------------------------------- initialise a panel */

  function initPanel(panel) {
    if (!panel || panel.getAttribute('data-wcr-init') === '1') return;
    panel.setAttribute('data-wcr-init', '1');

    // Delegated listeners on the root: re-rendering the panel's inner markup can
    // never attach a second handler.
    panel.addEventListener('click', onCopyClick);
    panel.addEventListener('change', onZoneChange);
    panel.addEventListener('dragover', onZoneDragOver);
    panel.addEventListener('dragleave', onZoneDragLeave);
    panel.addEventListener('drop', onZoneDrop);

    startTimer(panel);
  }

  function initAll(scope) {
    var panels = (scope || document).querySelectorAll('.wcrf-panel');
    for (var i = 0; i < panels.length; i++) {
      try {
        initPanel(panels[i]);
      } catch (e) {
        /* one broken panel must not stop the others */
      }
    }
  }

  function start() {
    initAll(document);

    // Page builders (Elementor, block previews, AJAX-loaded content) can insert a
    // panel after load; initialise whatever appears. Only armed when a panel exists
    // or a builder preview is active, so ordinary pages pay nothing.
    var builderActive = !!(window.elementorFrontend || document.body.classList.contains('elementor-editor-active'));
    if (!window.MutationObserver || (!document.querySelector('.wcrf-panel') && !builderActive)) return;

    var observer = new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        var added = records[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var node = added[j];
          if (node.nodeType !== 1) continue;
          if (node.classList && node.classList.contains('wcrf-panel')) {
            initAll(node.parentNode || document);
          } else if (node.querySelector && node.querySelector('.wcrf-panel')) {
            initAll(node);
          }
        }
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
