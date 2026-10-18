# WC Bank Receipt Ultimate Control

Card-to-card (bank transfer) receipts for WooCommerce: upload with a deadline, single and
bulk review, rotating bank cards with independent limits, receipt gallery, reports, CSV
export, action log and sound + popup alerts for new orders and status changes.

The receipt panel is a single, template-independent component you can place with a
shortcode, an Elementor widget or the automatic WooCommerce hooks — its CSS and JS are
scoped to the panel, so it drops into any theme, page builder or column.

* **Version:** 7.6.0
* **Requires:** WordPress 6.5+, WooCommerce 7.1+, PHP 7.4+ (tested on WP 7.1.3 / WC 11.2.1 / PHP 8.2)
* **License:** GPL-2.0-or-later
* **Text domain:** `wc-bank-receipt`

## Highlights

* The customer sees the active bank card, the amount and a payment deadline on the
  order-received page, uploads a JPEG/PNG/PDF receipt, and can re-upload after a rejection.
* The shop owner approves or rejects a receipt one at a time or in bulk; the decision runs
  through a single transition engine with server-side preconditions and an idempotency
  claim, so a card is charged once, a log row is written once and an SMS is sent once.
* Approval completes the WooCommerce payment cycle (`payment_complete()`) **and verifies**
  the paid state; a failure is reported as an error instead of a silent success.
* **Order alerts:** a manager who keeps wp-admin open gets a popup and a repeating alarm
  for a new order (any payment method) and for any order status change, with the previous
  and the new status. Every event records **who caused it** -- customer, gateway, automation
  and unknown origins ring; a manager's own status change, a receipt decision in wp-admin
  and the plugin's internal transitions do not (the SMS policy is decided separately, so a
  suppressed alert never silences the customer's message). Each manager has their own queue,
  the alarm repeats until the event is acknowledged (12 s by default, bounded), one tab owns
  the sound, the popup links straight to the order edit screen, and the sound is generated
  locally (Web Audio, no file and no network). Deleted orders stay readable from the
  snapshot.
* Deadlines, reminders and retention run on WP-Cron, independent of customer page views.
* Receipt files live in a protected uploads folder and are streamed through an
  authorization-checked viewer — never through a public URL.
* Deactivation deletes nothing; data removal happens only on uninstall and only when the
  shop owner opted in.
* **Template-independent panel:** one context, one renderer, three display methods — the
automatic thank-you/view-order hooks, the `[wc_bank_receipt]` shortcode, and an Elementor
widget. An order id in a shortcode or widget is a selector, never a credential: the
server checks capability, ownership or that order's key, and a page with no order context
renders nothing instead of guessing.
* **Isolated presentation:** every front-end CSS selector is scoped under `.wcrf-panel`
(no `body`, `:root` or bare `button/input/a` rules), the JS exposes no global and looks up
no element id, and two panels on one page are two independent instances. The upload form
works with JavaScript disabled.
* **Theme-aware appearance without CSS:** **فیش بانکی → ظاهر و استایل** exposes colours,
typography, radii, borders, shadow, spacing and the form width as labelled fields with a
live preview of the real panel component. The default mode inherits the theme's font and
colours (a dark theme stays dark) and only falls back to the plugin's own readable palette
when the theme gives nothing usable; the **Minimal**, **Modern** and **Flat** presets set
the same tokens in one click. Everything is a documented `--wcrf-*` custom property
emitted on the panel root, so one value updates the whole panel coherently and no rule
touches `:root`, `body` or a bare element. Values are validated by type and range
server-side, and `[wc_bank_receipt style="minimal"]` applies a whitelisted preset to a
single instance only.
* **Assets load where the panel is:** the WooCommerce order endpoints and any singular page
(including Elementor layouts stored in post meta) that actually renders the panel —
nowhere else. Versioned with the release, removable with `wp_dequeue_style/script`.
* **SMS, opt-in:** Kavenegar, Melli Payamak or a custom HTTP endpoint; per-event templates
  (new order, chosen statuses, receipt upload, approve, reject, reminder and an explicit
  test message) over an allow-listed variable set; bounded retries with backoff; a duplicate
  guard keyed on the event; and a delivery history with the attempt count, the HTTP code and
  a redacted provider answer. Nothing is sent until the master switch, a provider and at
  least one event template are configured.
* **One receipt viewer for both roles:** a shared overlay (`lightbox.js` / `lightbox.css`)
  with `Escape`, backdrop and button close, focus restore and scroll lock -- on the manager's
  order screen and in the customer's panel. A PDF opens as a document, not as an image.
* **One receipt state model:** `awaiting_payment`, `under_review`, `rejected` and `approved`
  drive the card block, the countdown, the drop zone and the notices; a rejection restarts
  the deadline from the rejection time, never from the order date.
* No telemetry, no hidden outbound request.

## Documentation

| File | Contents |
|---|---|
| [README-fa.md](README-fa.md) | راهنمای کامل فارسی: نصب، راه‌اندازی، چرخه وضعیت، تنظیمات، عیب‌یابی |
| [docs/state-machine.md](docs/state-machine.md) | Order/receipt state machine, guards and idempotency |
| [docs/alerts.md](docs/alerts.md) | Order-alert event queue, origins, popup, repeating sound and delivery semantics |
| [docs/sms.md](docs/sms.md) | SMS providers, events, templates, variables, retries and the delivery log |
| [docs/compatibility.md](docs/compatibility.md) | Display methods, shortcode and Elementor widget docs, CSS/JS isolation contract, compatibility matrix and limitations |
| [docs/appearance.md](docs/appearance.md) | Appearance tokens, defaults, presets, precedence order and the settings screen |
| [docs/retention-policy.md](docs/retention-policy.md) | When receipt files are kept or deleted |
| [docs/security-model.md](docs/security-model.md) | Capabilities, ownership checks, secrets and privacy |
| [docs/troubleshooting-fa.md](docs/troubleshooting-fa.md) | عیب‌یابی گام‌به‌گام و روش گزارش خطا |
| [CHANGELOG.md](CHANGELOG.md) | All changes with their root causes |

## Install

1. Upload the ZIP via *Plugins → Add New → Upload Plugin* and activate it.
2. Open the new **فیش بانکی** menu, add a bank card, review the deadline, retention and
   alert-sound settings.
3. Make sure the **Card to card (BACS)** gateway is enabled in WooCommerce.

Activation creates three plugin tables, the protected receipts folder and three hourly
cron events. Nothing else is touched.

### Placing the panel yourself

```text
[wc_bank_receipt]                 ← the order of the current thank-you / view-order page
[wc_bank_receipt order_id="123"]  ← a specific order (the visitor must still be allowed to see it)
```

With Elementor, either use the **Shortcode** widget with the shortcode above or the plugin's
own **پنل فیش بانکی** widget (WooCommerce category). See
[docs/compatibility.md](docs/compatibility.md).

A whitelisted appearance preset can be applied to one instance without affecting the rest of
the page (and without Elementor):

```text
[wc_bank_receipt style="minimal"]   ← preset values: inherit (default), minimal, modern, flat
```

Any other value is ignored and the global appearance settings apply. The appearance system
is documented in [docs/appearance.md](docs/appearance.md).

### Verified environments

The panel was checked over HTTP on **Storefront 4.6.2** and **Hello Elementor 3.5.1**
(classic themes) and on **Twenty Twenty-Five 1.5** and **Twenty Twenty-Four 1.6** (block
themes): one panel, both asset files, no duplicate element id and no fatal error on each,
with the same markup structure on all four. Both order-confirmation paths are covered —
the block confirmation and the classic `thankyou.php` template — as well as the customer's
My Account → “view order” screen (owner only). Elementor was verified at 4.3.5.
The full matrix, including what was **not** tested, is in
[docs/compatibility.md](docs/compatibility.md).

## Support

Repository and issue tracker: <https://github.com/DeathGunSR/WP-Recipe>
Please include your WordPress / WooCommerce / PHP / plugin versions, the exact
reproduction steps and the relevant `debug.log` lines. Never paste API keys, customer
data or real receipt files in a public report.
