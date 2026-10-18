/**
 * WC Bank Receipt — shared receipt lightbox.
 *
 * DESIGN CONTRACT
 * ---------------
 * 1. ONE overlay for the whole page. The element is created lazily on the first open and
 *    appended to <body>, so a page that shows a receipt in three places (panel, gallery,
 *    admin order screen) still has exactly one overlay, no duplicated ids and no per
 *    instance state.
 * 2. Progressive enhancement. Every trigger is a real <a href="…"> pointing at the
 *    authorized URL of the file. With JavaScript disabled the receipt still opens — in a
 *    new tab — and nothing about the markup is JavaScript-only.
 * 3. The URL is never invented here. The server mints the URL (nonce-scoped, capability
 *    checked) and the script only displays what the link already carries; a guest preview
 *    arrives as a data URI and therefore stays unshareable.
 * 4. Focus and scroll are handled like a dialog: focus moves to the close button, Escape
 *    and the backdrop close it, the page's scroll position is restored on close, and the
 *    previously focused element gets the focus back.
 * 5. PDFs are rendered as documents, never as images.
 * 6. A failure can only ever leave the ordinary link behaviour: all of it is inside one
 *    IIFE, and the click handler falls back to the default navigation if anything throws.
 */
(function () {
  'use strict';

  var config = (typeof window.wcrLightbox === 'object' && window.wcrLightbox) || {};
  var TEXT = {
    close: config.close || 'بستن',
    openNew: config.openNew || 'بازکردن در زبانهٔ جدید',
    missing: config.missing || 'فایل رسید در دسترس نیست.',
    hint: config.hint || 'برای بستن، بیرون تصویر را کلیک کنید یا کلید Esc را بزنید.'
  };

  var overlay = null;
  var contentBox = null;
  var captionBox = null;
  var lastFocus = null;
  var savedScroll = 0;
  var savedOverflow = '';

  /* ------------------------------------------------------------------ build */

  function build() {
    if (overlay) return;

    overlay = document.createElement('div');
    overlay.className = 'wcr-lb-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.hidden = true;

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'wcr-lb-close';
    close.setAttribute('aria-label', TEXT.close);
    // A multiplication sign (U+00D7) written as text, never as markup: the component builds
    // every node, so nothing here can ever become an HTML injection point.
    close.textContent = '\u00d7';

    contentBox = document.createElement('div');
    contentBox.className = 'wcr-lb-content';

    captionBox = document.createElement('p');
    captionBox.className = 'wcr-lb-caption';

    var hint = document.createElement('p');
    hint.className = 'wcr-lb-hint';
    hint.textContent = TEXT.hint;

    overlay.appendChild(close);
    overlay.appendChild(contentBox);
    overlay.appendChild(captionBox);
    overlay.appendChild(hint);
    document.body.appendChild(overlay);

    close.addEventListener('click', function (event) {
      event.stopPropagation();
      closeOverlay();
    });
    // The backdrop closes; the content does not (clicking the receipt must never
    // dismiss the very thing the manager is trying to read).
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) closeOverlay();
    });
    overlay.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' || event.key === 'Esc') closeOverlay();
    });
  }

  /* ------------------------------------------------------------------- open */

  function clearContent() {
    while (contentBox.firstChild) contentBox.removeChild(contentBox.firstChild);
  }

  function showMessage(message) {
    clearContent();
    var box = document.createElement('div');
    box.className = 'wcr-lb-missing';
    box.textContent = message;
    contentBox.appendChild(box);
  }

  function addFallbackLink(href, label) {
    var link = document.createElement('a');
    link.className = 'wcr-lb-fallback';
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = label + ' — ' + TEXT.openNew;
    contentBox.appendChild(link);
  }

  function openOverlay(href, type, label) {
    build();

    lastFocus = document.activeElement;
    savedScroll = window.pageYOffset || document.documentElement.scrollTop || 0;
    savedOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    captionBox.textContent = label || '';
    captionBox.hidden = !label;

    if (type === 'pdf') {
      // A document, not a picture: an <img> would show nothing at all for a PDF.
      clearContent();
      var frame = document.createElement('iframe');
      frame.className = 'wcr-lb-doc';
      frame.src = href;
      frame.title = label || '';
      contentBox.appendChild(frame);
      addFallbackLink(href, label || '');
    } else {
      clearContent();
      var img = document.createElement('img');
      img.className = 'wcr-lb-img';
      img.alt = label || '';
      img.decoding = 'async';
      img.addEventListener('load', function () {
        // The fit is computed by CSS (object-fit: contain), so nothing has to be measured
        // here — only a failure is worth reacting to.
        img.classList.add('is-loaded');
      });
      img.addEventListener('error', function () {
        showMessage(TEXT.missing);
        addFallbackLink(href, label || '');
      });
      img.src = href;
      contentBox.appendChild(img);
    }

    // The appearance of the overlay follows the panel it was opened from: the trigger's
    // declarations are copied over, so a themed front-end panel does not open a
    // default-coloured lightbox. In wp-admin there is nothing to copy and the CSS
    // defaults apply.
    var panel = triggerPanel(href);
    if (panel) {
      var decl = panel.getAttribute('style');
      if (decl) overlay.setAttribute('style', decl);
    } else if (overlay.hasAttribute('style')) {
      overlay.removeAttribute('style');
    }

    overlay.hidden = false;
    overlay.classList.add('is-open');

    var close = overlay.querySelector('.wcr-lb-close');
    if (close) {
      try { close.focus(); } catch (e) { /* focus is a nicety, never a failure */ }
    }
  }

  var currentPanel = null;
  function setPanel(panel) { currentPanel = panel || null; }
  function triggerPanel() { return currentPanel; }

  function closeOverlay() {
    if (!overlay || overlay.hidden) return;
    overlay.hidden = true;
    overlay.classList.remove('is-open');
    clearContent();
    document.body.style.overflow = savedOverflow;
    try {
      // Return the reader to where they were: the panel/gallery must not jump.
      window.scrollTo(0, savedScroll);
    } catch (e) { /* ignore */ }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (e) { /* ignore */ }
    }
    lastFocus = null;
  }

  /* ------------------------------------------------------------------ wiring */

  document.addEventListener('click', function (event) {
    var trigger = null;
    try {
      trigger = event.target && event.target.closest ? event.target.closest('a[data-wcr-lb]') : null;
    } catch (e) {
      trigger = null;
    }
    if (!trigger) return;

    // Respect the user's own navigation choices (new tab, download, middle click).
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    var href = trigger.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;

    try {
      setPanel(trigger.closest ? trigger.closest('.wcrf-panel') : null);
      event.preventDefault();
      openOverlay(href, (trigger.getAttribute('data-wcr-lb') || 'image').toLowerCase(), trigger.getAttribute('data-wcr-lb-label') || '');
    } catch (e) {
      // Anything unexpected: let the browser follow the link, exactly as without JS.
      setPanel(null);
      window.location.href = href;
    }
  }, false);

  document.addEventListener('keydown', function (event) {
    if ((event.key === 'Escape' || event.key === 'Esc') && overlay && !overlay.hidden) {
      closeOverlay();
    }
  }, false);
})();
