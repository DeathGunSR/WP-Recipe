=== WC Bank Receipt Ultimate Control ===
Contributors: deathgunsr
Tags: woocommerce, bank transfer, receipt, bacs, iran, persian, payment
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 7.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.1
WC tested up to: 11.2

Bank-transfer (card-to-card) receipts for WooCommerce: upload with a deadline, single and bulk review, rotating bank cards with limits, gallery, reports, CSV export, action log and a sound + popup alert for new orders and status changes.

== Description ==

**WC Bank Receipt Ultimate Control** manages the card-to-card (bank transfer) payment
flow in WooCommerce. The customer sees the bank card, the amount and a payment
deadline on the order-received page, uploads a receipt photo or PDF, and the shop
owner approves or rejects it — one order at a time or in bulk.

= Receipt lifecycle =

* Order placed with the "Card to card" (BACS) gateway → the customer sees the card
  details, the amount and a countdown for the upload deadline.
* Valid upload → the receipt becomes `pending_review`, the order moves to `on-hold`
  and the file is stored in a protected folder.
* Approve → the receipt becomes `approved`, the standard WooCommerce payment cycle
  completes and is verified (the order becomes paid), and the bank card is charged
  exactly once — even for repeated clicks or concurrent requests.
* Reject → the reason is stored and shown to the customer, and the order can be
  uploaded again (history of the previous decision is kept).
* Deadline reached → an hourly WP-Cron job closes the order; no customer visit is
  required.

Every path (single approve, single reject, bulk review) runs through one transition
engine, so preconditions, logging, SMS and card accounting behave identically.

= Review and reporting =

* Bulk review page for all receipts waiting in the queue (paged, 50 per page).
* Reports with status/amount summaries and a paged CSV export.
* Receipt gallery with thumbnails, protected streaming of the file and safe delete.

= Bank-card rotation =

Cards have a priority, an amount limit, a transaction-count limit and an expiry date.
`get_active_card()` never returns an expired, disabled or exhausted card, and a card is
charged once per approved receipt — never twice, not even for concurrent requests.

= Admin order alerts (sound + popup) =

Managers who keep wp-admin open get a popup and an audible beep when an order is
placed (any payment method) or changes status through the standard WooCommerce flow:

* Two independent events — `new_order` and `status_change` (with the previous and the
  new status) — are queued in the `…_events` table; the first status WooCommerce applies
to a brand-new order is folded into its new-order event instead of becoming a second one.
* The popup shows the order number, customer, order total, payment method, current
  status, order item count, the event time and a **View order** button that opens the
  order edit screen. Deleted orders stay readable from the stored snapshot.
* One queue per manager: each user has their own cursor in user meta, so an event is
delivered once to each manager and two browser tabs do not swallow each other's alerts.
* The beep is generated locally in the browser (Web Audio API) — no audio file, no
  external service, no request. It can be switched off in the plugin settings, it is
  rate-limited so a burst of status changes beeps once instead of N times, and when the
  browser blocks autoplay the popup still appears with a button to enable the sound.
* Polling backs off exponentially on transport errors and only fetches events newer than
  the manager's cursor; what was shown is acknowledged, so a reload never re-beeps old
  events. The queue is pruned (max 1000 rows / 7 days).

= SMS (UI temporarily disabled) =

The SMS layer is fully implemented (Kavenegar, Melli Payamak or a custom HTTP endpoint,
per-event templates, delivery log with honest `sent` / `failed` / `unknown` statuses),
but its tab is **disabled in this release** (`SMS_UI_ENABLED = false`): the menu entry is
hidden, the settings pages are closed even for administrators, and no SMS is ever sent.
Existing settings, credentials and logs are preserved — only the interface is off.
The order alerts above are completely independent of SMS.

= Data policy =

* Activation creates the tables, the protected upload directory and the cron events.
* Deactivation only stops scheduled work. Tables, options, order meta and receipt files
  are preserved.
* Data is removed only through "Delete plugin" **and** only when the shop owner has
  explicitly opted in (`wc_receipt_delete_data_on_uninstall = yes`).

= Privacy =

This version sends **no data** to the plugin author. There is no telemetry, no hidden
remote call and no third-party request other than the SMS provider you configure.

== Installation ==

1. Upload the ZIP through Plugins → Add New → Upload Plugin, then activate it.
2. Open the new "فیش بانکی" (Bank Receipt) menu.
3. Add at least one bank card under "کارت‌های بانکی" (Bank cards).
4. Optional: configure the deadline, retention period and SMS provider.
5. Make sure the "Card to card" (BACS) gateway is enabled in WooCommerce.

Activation creates:

* three tables (cards, receipt log, SMS log),
* `wp-content/uploads/bank-receipts/` protected by a generated `.htaccess`,
* hourly cron events for cleanup, deadline reminders and deadline enforcement.

== Frequently Asked Questions ==

= Does the plugin delete my data when I deactivate it? =

No. Deactivation only stops the scheduled jobs. Tables, options, order meta and receipt
files stay untouched.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. Every order read/write uses the WooCommerce order APIs (`wc_get_order()`,
`wc_get_orders()`), and the plugin declares HPOS compatibility.

= Is the receipt file publicly reachable? =

No. Receipts live in a protected folder and are streamed through a viewer that checks
the order ownership (customer) or the capability (administrator) before reading the file.

= Which checkout flows are supported? =

Classic checkout *and* the block-based order confirmation page — the upload form is
rendered through WooCommerce's thankyou hooks and there is also a
`[wc_bank_receipt]` shortcode for custom pages or account pages.

= How do I put the receipt panel on another page? =

Place `[wc_bank_receipt]` there with the Shortcode block, a widget, or the builder's
shortcode element; add `order_id="123"` to describe one specific order. The visitor still
has to be allowed to see that order (the customer, a manager, or a link carrying the
order key), so the shortcode never exposes another customer's data.

= Will it conflict with my theme or page builder? =

The front-end CSS is scoped to the panel root `.wcrf-panel` (no `body`, `:root` or bare
element selectors) and the front-end JS exposes no global and looks up no element id, so
the panel neither restyles your site nor depends on your theme's markup. With Elementor
there is also a native "پنل فیش بانکی" widget. The panel was verified on Storefront 4.6.2,
Hello Elementor 3.5.1, Twenty Twenty-Five 1.5 and Twenty Twenty-Four 1.6 (one check per
theme, plus a markup-structure comparison and both order templates) — the full list of
what was actually tested is in `docs/compatibility.md`; nothing claims to work with every
theme.

= Can I match the form to my theme without writing CSS? =

Yes. **فیش بانکی → ظاهر و استایل** exposes the whole palette (primary/hover, text, surface,
border, button, success/warning/error), typography, radii, spacing and the form width as
plain fields with live preview. The out-of-the-box state (“قالب سایت”) inherits the theme's
font and colours and stays readable on any light or dark theme; the presets **Minimal**,
**Modern** and **Flat** set the same tokens in one click; “رنگ تأکید قالب” picks up the
theme/Elementor accent colour when the theme provides one. Nothing is written to your
theme and no external font is loaded.

= Can the same page have two differently styled forms? =

Yes. `[wc_bank_receipt style="minimal"]` (and `order_id="123" style="modern"`) applies one of
the documented presets to that one instance only; anything else falls back to the global
appearance settings. Only the three whitelisted names are accepted, and an unknown value is
ignored rather than turned into CSS.

= What happens to a receipt after it is rejected? =

The reason is shown to the customer, the order returns to `pending`, and the customer can
upload a new receipt. The previous decision stays in the order history; only the
"current" reason is cleared.

= Are my existing receipts kept when I upgrade from 7.1.0? =

Yes. Orders that only carry the legacy `_wc_receipt_url` meta are still listed in the
gallery and their files are still resolved (and cleaned up) by the retention job.

== Where the receipt panel appears ==

The panel is one component with one implementation and three ways to place it:

* **Automatically** on the order-received page (classic template and block-based order
  confirmation) and on the customer's view-order page, for Card to card (BACS) orders.
* **With a shortcode** on any page, post, widget area or builder column:
  `[wc_bank_receipt]` shows the order of the current thank-you/view-order page, and
  `[wc_bank_receipt order_id="123"]` shows a specific order — still subject to the
  server-side access check. On a page with no order context the shortcode renders nothing
  (never a random order, never the literal shortcode text).
* **With an Elementor widget** ("پنل فیش بانکی", WooCommerce category) when Elementor is
  active. Without Elementor the widget file is never loaded.

A page prints the panel once even if both the WooCommerce hook and the shortcode apply.
Two shortcodes placed side by side are two independent panels, with no duplicate element id.

== Theme, builder and plugin safety ==

* Every front-end CSS selector is scoped under the panel root `.wcrf-panel` — there is no
  rule for `body`, `:root` or a bare `button`, `input`, `form`, `a` or `img`, so the panel
  cannot restyle your theme and your theme cannot restyle the panel by accident.
* The front-end script exposes no global function and looks up no element id; it
  initialises each panel separately and works with page builders that insert content later.
* The upload form works with JavaScript disabled (the file input covers the drop zone).
* The panel stylesheet and script load only on the WooCommerce order endpoints and on the
  pages that actually render the panel (Elementor layouts in post meta included), and they
  are versioned with the release so caches update by themselves.
* WooCommerce inactive: the plugin does not boot, shows an admin notice instead of a fatal
  error, and the shortcode prints nothing rather than its own text.

== Appearance: presets, tokens and priorities ==

The appearance layer is a set of CSS custom properties (`--wcrf-*`) written as one inline
`<style>` block on the panel root, never on `:root` or `body`: the tokens live inside
`.wcrf-panel{…}`, so a second panel with a different preset is unaffected and the theme is
never changed. The stylesheet reads only those tokens, so a colour or radius reaches every
related part of the panel (card, button, fields, badges, notes) as one coherent unit.

Priority, highest first:

1. an override on that one widget instance (Elementor),
2. the `style` preset on that one shortcode instance,
3. the global appearance settings,
4. values inherited from the theme, then the plugin defaults.

Every value is validated server-side against its own type and range (hex colour, length
with a bounded numeric part, line height, font weight, enumerated preset), and the whole
block is assembled from that whitelist — a saved value can never inject a CSS rule or
markup. An empty field means “inherit” and emits no custom property at all. “بازنشانی به
حالت پیش‌فرض” restores inheritance, and the saved/unsaved state is shown next to the form.
The Elementor widget exposes the same tokens as native controls (including Elementor's
Global Colors for its public types) and its controls override the global settings for that
instance only. Elementor is optional at every step.

Full reference, defaults and the token list: `docs/appearance.md`.

== Compatibility documented, not assumed ==

[docs/compatibility.md](docs/compatibility.md) lists the three display layers, the shortcode
and widget documentation, the CSS/JS isolation contract, the filters for integrators, the
compatibility matrix of what was actually tested (WordPress 7.1.3, WooCommerce 11.2.1,
PHP 8.2, HPOS, Elementor) and the known limitations. No claim of “works with every theme”.
[docs/appearance.md](docs/appearance.md) documents the appearance tokens, their defaults and
the precedence rules described above.

== Screenshots ==

1. Settings — deadline, retention and upload rules
2. Alert sound setting with the test-sound button
3. Bank cards with independent amount / transaction limits
4. Receipt gallery with protected thumbnails
5. Bulk review of the pending queue
6. CSV report export
7. Admin order-alert popup, captured from a real order placed while the dashboard was open
8. Customer upload form on the order-received page
9. Appearance & style — token groups with the live panel preview

== Changelog ==

= 7.5.1 =
Reliability fix for the receipt decision path. Nothing changes for a shop where one manager
reviews one receipt at a time; the fix removes a way for two simultaneous decisions on the
same receipt to both take effect.

* Fix: two decisions on the **same** receipt instance (an approve and a reject) could now
  and then both apply when they arrived at the same moment — from two manager tabs, two
  managers, a double click or a retried request. The receipt ended up rejected while the
  order was already marked paid and the card had been charged. `approve` and `reject` now
  share one per-receipt decision claim in the `…_transitions` ledger, and the winning
  request re-reads the authoritative receipt state after claiming it, so exactly one
  decision ever takes effect. Verified with a barrier-synchronised two-process race:
  6/6 rounds had both decisions take effect before the fix, 0/8 rounds after it.
* Fix: the winning request now abandons the operation (instead of applying it to a stale
  order snapshot) when the receipt was decided or replaced between the request's first
  read and its claim.
* No data, setting or order meta is changed by this upgrade; existing receipts, approved
  orders and the audit ledger are untouched. A receipt that was rejected and uploaded
  again is a new receipt instance and is decided normally.

= 7.5.0 =
Appearance and design system: the panel now follows the theme by default and can be styled
from the admin without writing CSS.

* New: **فیش بانکی → ظاهر و استایل** — colours (primary + hover, text, muted text,
  surface, card, border, button, success/warning/error with their text colours),
  typography, radii, border width, shadow, spacing, form width and a compact/comfortable
  density, each with a label, a hint and an enforced range.
* New: three modes — **inherit** (`قالب سایت`, the default: the panel uses the theme's font
  and colours and only falls back to its own readable defaults), **presets** (Minimal,
  Modern, Flat) and **custom**.
* New: live preview on the settings screen that re-renders the real panel component (no
  order and no customer data involved), an unsaved/saved indicator and a reset to defaults.
* New: the appearance is a set of documented CSS custom properties (`--wcrf-color-primary`,
  `--wcrf-radius-card`, `--wcrf-content-width`, …) emitted on the panel root, so one change
  updates the card, buttons, fields, badges and notes together and no rule ever touches
  `:root`, `body` or a bare element selector.
* New: `[wc_bank_receipt style="minimal"]` applies one documented preset to that instance
  only; unknown values are ignored and no CSS is ever accepted from the shortcode.
* New: the Elementor widget exposes the same tokens as native controls, with the widget
  overriding the global settings for its own instance only; Elementor stays optional.
* Security: every value is validated server-side against its type and range (hex colour,
  bounded length, line height, font weight, whitelisted preset) and the inline block is
  assembled from that whitelist, so a saved value cannot inject CSS or markup.
* Fixed: the panel used the `Field` system colour for the drop zone, which does not follow a
  dark theme in Chromium (white-on-white text); it now uses a theme-aware surface colour.
* Unchanged: no appearance change touches orders, receipt state, ownership, uploads or the
  file viewer, and the payment path is not involved at all.

= 7.4.0 =
Theme, page-builder and plugin compatibility.

* New: `[wc_bank_receipt]` shortcode (with an optional `order_id`) and a thin Elementor
  widget ("پنل فیش بانکی") — all three display methods share one context and one renderer,
  so no path has its own markup, checks or upload code.
* New: the panel is one component — the automatic hooks, the shortcode and the widget all
  print the same markup, and a page prints it once even when several of them apply.
* Security: a single authorization rule for display, upload and the receipt viewer;
  an order id in a shortcode or widget is a selector, never a credential; a page with no
  order context renders nothing.
* Fixed: the panel's inline `<script>` (with a global `wcrCopy`), the duplicate element ids
  and the inline styles are gone — the panel is now two versioned asset files, and two
  panels on one page are two independent instances.
* Fixed: front-end CSS is scoped to `.wcrf-panel` (no `body`/`:root`/bare-element rules),
  so it cannot leak into the theme or into wp-admin.
* Fixed: the assets loaded only on two WooCommerce endpoints, so the shortcode on a custom
  page also loaded them late and the thank-you block page could print the panel with
  inline CSS; the files now load exactly where the panel is (builder layouts included).
* Fixed: an upload form whose file input was hidden until JavaScript ran is now usable
  with JavaScript disabled, and the copy button has a working fallback when the Clipboard
  API is refused.
* Compatibility: no fatal error and no raw shortcode text with WooCommerce inactive.
* Verified: the same order page on Storefront (classic), Hello Elementor (classic),
  Twenty Twenty-Five and Twenty Twenty-Four (block) prints one panel with both assets and
  no duplicate id, and the classic `thankyou.php` template as well as the My Account
  "view order" screen (owner only) render it too.
* Changed: the countdown's first value is now the real remaining time rendered on the
  server instead of a `--:--:--` placeholder, so the panel shows a value immediately and
  no content filter can rewrite that part of the markup.

= 7.3.0 =
Sound and popup alerts for orders; the SMS tab is temporarily switched off.

* New: an order-alert queue with two independent events — **new order** (any payment
  method) and **order status change** (previous → new status). The initial status
  WooCommerce applies to a fresh order is folded into its new-order event, and a UNIQUE
  dedupe key makes the queue itself the duplicate check.
* New: a manager popup showing order number, customer, formatted order total, payment
  method, current status, item count, event time and a **View order** button built with
  `get_edit_order_url()`. Values reach the DOM as text, never as HTML.
* New: an audible alert generated locally with the Web Audio API (no file, no network),
  switchable in the settings together with a test-sound button, with burst control and a
  blocked-autoplay notice that does not prevent the popup.
* New: per-manager polling with a cursor stored in user meta — multiple tabs and multiple
  managers each get every event exactly once; transport errors back off instead of
  stopping the alerts; a page reload never re-beeps delivered events.
* Fixed: the previous notification path only alerted card-to-card payments and its
  popup wrote order data into `innerHTML`. Both are replaced by the event queue above.
* Changed: the SMS tab is hidden and its pages are closed (`SMS_UI_ENABLED = false`).
  No SMS is sent, and nothing was deleted — settings, credentials and the log table stay.

= 7.2.0 =
Security, lifecycle and order-flow rebuild on top of 7.1.0.

* Security: fixed cross-order guest upload (order key + order-scoped nonce), the viewer
  now authorizes by order ownership/capability instead of relying on a nonce, receipts
  are never served from a public URL, and every AJAX endpoint checks capability + nonce
  and escapes its output.
* Order flow: a single transition engine for single/bulk approve and reject, with
  server-side preconditions, an idempotency claim per (order, receipt, transition) and a
  verified WooCommerce payment cycle on approval — no duplicate card charge, log entry or
  SMS. Four simultaneous approve requests used to charge a card four times
  (`charged=400,000`); exactly one request now wins (`charged=100,000`).
* Lifecycle: activation now creates tables, the protected directory and the cron events
  (previously the bootstrap never ran on the activation request); deactivation preserves
  all data; data removal happens only on uninstall with explicit opt-in; the schema
  migration is repeatable and additive.
* Uploads: real MIME validation, size limits, random file names, no server path in order
  meta, and no half-stored state when a precondition fails.
* Files: retention is counted from the upload time, approved receipts are kept by
  default, legacy URL-only receipts stay visible, and file/meta deletion stays consistent.
* Bank cards: unusable cards are never offered, amount and transaction limits are
  independent, and card usage is recorded once per approval.
* SMS: HTTP and provider-level failures are logged as failures; one message per
  transition; credentials are never rendered back into the admin page.
* Performance: paged gallery, reports and bulk review, batched cleanup, bounded reminder
  and expiry processing.
* Compatibility: declared and tested PHP/WP/WC floor and ceiling, no fatal error without
  WooCommerce, HPOS support through the order APIs, classic and block order confirmation.

= 7.1.0 =
Previous release. Card management, gallery, reports, SMS and bulk review.

== Upgrade Notice ==

= 7.5.1 =
Prevents a rare double decision on one receipt (approve and reject applied at the same
moment by two manager tabs or two managers). No data, setting or order meta is touched by
the upgrade; receipt files, approved orders and the audit ledger are preserved.

= 7.5.0 =
Adds the appearance and design system (theme-aware defaults, presets and a live preview)
and an optional per-instance `style` preset for the shortcode. Existing settings, orders
and receipt files are untouched; no appearance value is set until you change one.

= 7.4.0 =
Adds the `[wc_bank_receipt]` shortcode and an Elementor widget, scopes all front-end CSS and
JS to the panel, drops the panel's inline script and duplicate ids, and loads the panel
assets only where the panel is. No data or setting is changed by the upgrade.

= 7.3.0 =
Adds reliable sound + popup alerts for new orders and order status changes (any payment
method), with a per-manager queue that survives reloads and multiple tabs. The SMS tab is
temporarily hidden — no SMS is sent and no SMS data is removed.

= 7.2.0 =
Security and order-flow release. Upgrading is strongly recommended: the previous version
allowed a guest to attach a receipt to another customer's order, exposed receipt files
through a public URL, and could charge a card more than once for the same receipt.
