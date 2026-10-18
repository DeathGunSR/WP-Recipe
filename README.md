<div align="center">

# WP-Recipe

### WC Bank Receipt Ultimate Control — bank-transfer receipts for WooCommerce

**English** · [🇮🇷 فارسی](README.fa.md)

![WordPress](https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-BACS_flow-96588A?logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-dbDelta_tables-4479A1?logo=mysql&logoColor=white)
![i18n](https://img.shields.io/badge/i18n-Persian_RTL-2C3E50)

</div>

> A WooCommerce plugin (v7.6.0) that turns manual bank-transfer verification into a complete workflow: customers upload their receipt on the order page with a countdown, the shop reviews it in one click (or in bulk), rotating destination bank cards never blow past their limits — and every new order or status change raises a sound + popup alert in wp-admin. The receipt panel is one template-independent component that can be placed by the automatic WooCommerce hooks, by the `[wc_bank_receipt]` shortcode or by an Elementor widget, with its CSS and JS scoped to the panel — and its whole look is a documented set of design tokens a shop owner can set from wp-admin (theme-aware by default) without writing CSS. Every order event records **who caused it** (so a manager's own action never rings their own dashboard), the dashboard alarm repeats until it is acknowledged, one shared viewer serves both the manager and the customer, and the SMS pipeline (providers, per-event templates, bounded retries, delivery history) is back as an opt-in layer that stays silent until it is configured. The repository name is a pun on *receipt*.

---

## Table of contents

- [1. The problem and the goal](#1-the-problem-and-the-goal)
- [2. What is in this repository](#2-what-is-in-this-repository)
- [3. Features](#3-features)
- [4. Architecture](#4-architecture)
- [5. Technology stack](#5-technology-stack)
- [6. Key engineering decisions](#6-key-engineering-decisions)
- [7. Install and configure](#7-install-and-configure)
- [8. Testing and verification](#8-testing-and-verification)
- [9. Known limitations and roadmap](#9-known-limitations-and-roadmap)
- [10. Documentation](#10-documentation)

---

## 1. The problem and the goal

Plenty of Iranian WooCommerce stores sell through the built-in **BACS (bank transfer)** gateway: the customer orders, then has to transfer money and send the payment proof — historically by WhatsApp or email, with the shop owner manually matching receipts to orders and watching the same deposit card hit its ceiling.

This plugin replaces that whole loop with one workflow inside WordPress:

1. The customer uploads the receipt **on the order page**, against a deadline.
2. The shop **approves or rejects** it in one click — with the reason shown to the customer.
3. Destination **cards rotate** by priority, each with its own amount/transaction/expiry limits.
4. The shop manager is alerted to **every new order and every status change** with a popup + sound, everything is **logged**, and stale files clean themselves up.

## 2. What is in this repository

| Path | What it is |
|---|---|
| [`wc-bank-receipt/`](wc-bank-receipt) | The plugin as it ships: `wc-bank-receipt.php` (v7.6.0) plus `assets/` (panel CSS + JS, the receipt viewer, the admin appearance script), `includes/` (the design-token registry and the lazily loaded Elementor widget), `uninstall.php`, `readme.txt`, README + `docs/` (compatibility, appearance, alerts, SMS, state machine, retention policy, security model, troubleshooting, screenshots), CHANGELOG, LICENSE |
| [`dist/wc-bank-receipt-7.6.0.zip`](dist/wc-bank-receipt-7.6.0.zip) | Installable release package (ZIP root = `wc-bank-receipt/`), byte-identical to the reviewed tree |
| [`tests/acceptance.php`](tests/acceptance.php) | Acceptance harness: HTTP/DB checks in 18 groups — security, lifecycle, alerts, order flow, cards, files, SMS, performance, HPOS, compatibility, templates/page builders, Elementor, themes, order screens, appearance, **event origins**, **receipt viewer**, **receipt state / alarm UI** — 124 checks in total |
| [`tests/evidence/`](tests/evidence) | Raw evidence: the 7.1.0 baseline (22 pass / 29 fail), the full 7.3.0 and 7.4.0 runs (77 checks), the 7.4.0 run against the installed ZIP (73 checks), the Elementor run, the full 7.5.0 run (91 checks), the 7.5.0 run against the installed ZIP (91 checks), the pre-release audit runs (7.5.0 before the fix, the fixed tree and the released 7.5.1 package), the decision-race probe before/after, the WP Plugin Check output, and for 7.6.0 the full 18-group run (124 checks) plus the same package installed from its ZIP (59 checks over the changed groups) |
| [`RELEASE-REPORT.md`](RELEASE-REPORT.md) · [`COMPAT-REPORT.md`](COMPAT-REPORT.md) · [`APPEARANCE-REPORT.md`](APPEARANCE-REPORT.md) | Phase reports (7.3.0 alerts, 7.4.0 templates, 7.5.0 appearance): root causes, before/after evidence, remaining risks |
| [`IMPLEMENTATION_REPORT.md`](IMPLEMENTATION_REPORT.md) · [`IMPLEMENTATION_TEST_REPORT.md`](IMPLEMENTATION_TEST_REPORT.md) | 7.6.0 report pair: what changed and why (origins, alarm, viewer, state model, SMS pipeline, upgrade path) and the test report with every check's actual evidence |
| [`RELEASE_AUDIT_REPORT.md`](RELEASE_AUDIT_REPORT.md) · [`RELEASE_TEST_MATRIX.md`](RELEASE_TEST_MATRIX.md) | Pre-release audit of 7.5.0/7.5.1: threat model, findings with severity and evidence, the order/receipt state machine, the full test matrix with PASS/FAIL/BLOCKED/NOT RUN states, and the release decision. The audit report is **not** part of the installable ZIP — it contains security detail that is deliberately kept out of the public package |
| [`tools/`](tools) | `build-release.sh` (builds the ZIP, blocks secrets/developer paths), `run-acceptance.sh` (runs the harness against the local test site) and `screenshots.mjs` (real UI screenshots, including the live alert popup) |
| [`README.md`](README.md) | This document · [فارسی](README.fa.md) |
| [`.gitattributes`](.gitattributes) | LF normalization |

> **Install note:** upload `dist/wc-bank-receipt-7.6.0.zip` through *Plugins → Add New → Upload Plugin* (or `wp plugin install dist/wc-bank-receipt-7.6.0.zip`). The ZIP is self-contained — main file, assets, the Elementor widget file, docs and screenshots; nothing outside it is required. The pristine 7.1.0 file is still in Git history (`git show HEAD:test-receipt.php`).

## 3. Features

### Receipt lifecycle (the core loop)
- The upload form appears on **`order-received` (thank-you)** and **`view-order`** pages, only for orders paid via `bacs`, and only for the owner (logged-in customer or valid `?key=` order key).
- **Countdown timer** to the deadline (`wc_receipt_expire_hours`, default 2 h, 0.5 h steps) with an expired state that blocks upload.
- Upload validation: configurable **MIME allow-list** (JPEG/PNG/PDF), max file size (default 2 MB), extension double-check, randomized 32-hex filename decided by the **detected content type** (the client type and the extension are not trusted) — stored in `uploads/bank-receipts/` protected by a generated **`.htaccess` (Deny from all)** and streamed only through an authorization-checked viewer.
- Upload → order goes to **`on-hold`**, receipt state `pending_review`, the assigned destination card is recorded, a log row is written.
- **Approve** (AJAX) → state `approved`, order → **`processing`** (payment completion is verified), and the card is charged **exactly once** — a repeated click, a retried request or four concurrent requests cannot charge it again.
- **Reject** (AJAX) → state `rejected` + reason (displayed to the customer) + timestamp, order → **`pending`** so the customer can re-upload after fixing the payment.

### Admin order alerts (sound + popup) — new in 7.3.0
- Two independent events are queued for managers who keep wp-admin open:
  **new order** (any payment method, not only card-to-card) and **status change**
  (previous status → new status, through the standard WooCommerce flow).
- The first status WooCommerce applies to a brand-new order is folded into its new-order
  event, and a UNIQUE dedupe key makes the insert itself the duplicate check — one event is
  never queued twice, not even by two concurrent requests.
- The popup shows order number, customer, formatted order total, payment method, current
  status, item count, event time and a **View order** button (`get_edit_order_url()`).
  A deleted order stays readable from the stored snapshot; every value reaches the DOM as
  text — no `innerHTML`.
- The beep is generated locally with the **Web Audio API** — no audio file, no CDN, no
  request. It can be switched off in the settings (plus a **test sound** button), it is
  rate-limited so a burst of status changes beeps once, and blocked autoplay only shows a
  notice with an enable button — the popup always appears.
- **One queue per manager:** the cursor lives in user meta, so two admins (or two tabs) each
  get every event exactly once; acknowledging never deletes rows and never eats another
  manager's alert. Polling is spaced, backs off on transport errors and only ever fetches
  events newer than the caller's cursor, so a page reload never re-beeps old events.

### Portable receipt panel (shortcode + Elementor widget) — new in 7.4.0
- One panel, **three display methods**: the automatic WooCommerce hooks (`thankyou_bacs`,
  `view_order`), the `[wc_bank_receipt]` shortcode (with an optional `order_id`), and an
  **Elementor widget** («پنل فیش بانکی»). All three call the same context and the same
  renderer — no path has its own markup, its own authorization or its own upload code.
- **An order id is a selector, never a credential.** Display, upload and the receipt viewer
  share one rule (capability, ownership, or that order's key via sanitized `hash_equals()`).
  A page with no order context renders **nothing** — never a random order, never the
  shortcode's own text — and an unauthorized visitor gets a neutral notice with no order data.
- **One panel per page:** the first method that prints an order marks it for the request and
  the others skip it, so a thank-you page carrying the shortcode still shows one panel.
  Two shortcodes placed side by side deliberately stay two independent panels.
- **Isolated presentation:** every front-end CSS selector is scoped under `.wcrf-panel`
  (no `body`, `:root`, bare-element or generic theme-class rules), the script exposes no
  global, looks up no element id and initialises each panel separately. The markup contains
  no element id at all, so two panels on one page cannot collide.
- **Assets load where the panel is** — the order endpoints and any singular page that really
  renders the panel (Elementor layouts stored in post meta included), nowhere else — as two
  versioned files a theme can dequeue or replace.
- **Works without JavaScript:** the file input covers the drop zone, so choosing a file and
  dragging one in both work; the script only adds the preview, the drag styling and the copy
  feedback (with two clipboard fallbacks).
- Declared and tested against Elementor 4.3.5: with it active the widget is registered and
  renders a real Elementor-built page; with it deactivated the widget class is never even
  loaded.
- **Verified on four themes** — Storefront 4.6.2 and Hello Elementor 3.5.1 (classic) plus
  Twenty Twenty-Five 1.5 and Twenty Twenty-Four 1.6 (block): the same order page prints one
  panel with both assets on each, on the block order confirmation, on the classic
  `thankyou.php` template and on the customer's view-order screen.

### Theme-aware appearance without CSS — new in 7.5.0
- A **«ظاهر و استایل»** screen sets the panel's colours (accent + hover, text, muted, surface,
  field, border, bank card and button trios, success/warning/error pairs), typography, radii,
  border width, shadow, padding, gap, maximum form width and a compact/comfortable density —
  each a labelled field with a hint, an enforced range and a live preview.
- **The default follows the theme**: `font-family: inherit`, `font-size: 1em`,
  `line-height: inherit` and the CSS system colours (`CanvasText`, `Canvas`, `GrayText`) for
  text, surface and border, so a dark or block theme keeps the panel dark and readable, while
  the accent and status colours stay plugin-owned so a theme that publishes nothing usable
  still yields a contrast-safe panel. Three optional pickers link the panel to the palette the
  theme/Global Styles already publishes (`var(--wp--preset--color--slug, fallback)`).
- **Three modes** (inherit / preset / custom) and three **presets** (Minimal, Modern,
  Flat) that are value sets only — never a second markup, stylesheet or form. Optional
  accent coupling fills the hover shade, the button trio and the card gradient from one
  colour, touching only tokens that are still at their default.
- **Per-instance styling:** `[wc_bank_receipt style="minimal"]` applies one whitelisted
  preset to one placement, and the Elementor widget exposes the same tokens as native
  controls (with the kit's Global Colors/Fonts) for that instance only. `style` is a visual
  option and never changes authorization.
- **Tokens, not scattered values:** every adjustable property is one `--wcrf-*` custom
  property emitted as a `style` attribute on the panel's own root, so one change updates the
  card, buttons, fields, badges and notes coherently; nothing is written to `:root`, `body`
  or any element outside the panel, and a default install prints no inline declaration.
- **Validated by type and range** on the server (hex colour, strict `rgba()`, palette slug,
  clamped sizes, enumerated choices) with a final character guard, and stored in its own
  option group, so an appearance save can never write a payment/retention setting and a
  stored value can never become raw CSS. Reset restores inheritance.

### Event origins, a repeating alarm, one viewer and the SMS pipeline — new in 7.6.0
- **Every event records who caused it.** A queued event carries an `origin`
  (`customer_checkout`, `customer_receipt_upload`, `admin_manual_action`,
  `admin_receipt_review`, `payment_gateway`, `system_automation`, `plugin_internal`,
  `unknown`) and a `suppressed` flag. A manager's own status change, a receipt decision in
  wp-admin and the plugin's internal transitions do **not** ring the dashboard; customer,
  gateway, automation and unknown origins do. `is_admin()` is never used as proof of
  origin: classification is a scoped per-request context that is always released, even on
  an exception, and the SMS policy is decided separately, so a suppressed dashboard alert
  never silences the customer's message.
- **The alarm is not a one-shot beep.** While an event is unacknowledged the sound repeats
  (12 s by default, filterable, bounded 3–300 s). Acknowledge, dismiss and *View order*
  each record the acknowledgement in the tab and on the server first, stop only that event,
  and hand the alarm to the next queued one. A short `localStorage` lease makes one tab the
  sound leader, so a second tab draws the popup without beeping twice.
- **One receipt viewer for both roles** (`assets/js/lightbox.js` + `assets/css/lightbox.css`):
  fixed overlay, contain-fit image bounded to the viewport, close button, backdrop and
  `Escape`, focus restore and scroll lock; a PDF opens as a document. The file is still
  streamed through the authorization-checked endpoint with `Cache-Control: private,
  no-store`, so the uploads folder stays protected and the guest keeps only the local
  preview of the file they just uploaded.
- **One receipt state model** (`receipt_phase()`): `awaiting_payment` (card, countdown,
  form), `under_review` (no card, no countdown, the receipt is viewable), `rejected`
  (reason, previous file, a countdown restarted from the rejection) and `approved`
  (confirmation only). The card block, the countdown, the drop zone and the notices all
  read it, and a failed status change no longer leaves the panel claiming a review the
  order is not in.
- **The SMS screens are back** as an opt-in pipeline (next-but-one section), and the
  appearance preview is click-to-edit: clicking a part of the preview opens exactly the
  tokens that style it.

### Review and reporting tools
- Review box inside the WooCommerce order screen (state badges, lightbox preview, approve/reject with reason) — plus a receipt-state **column in the order list**, wired for **both classic and HPOS** list tables.
- **Bulk review page**: approve/reject many orders at once with one shared reason (paged, 50 per page).
- **Gallery** of uploaded receipts (thumbnails, PDF badges, lightbox, time-left; 24 per page).
- **Reports** page with stats and a **paged CSV export**; every action (uploaded/approved/rejected) lands in the `receipt_log` audit table.

### Bank-card rotation
- Card directory with bank name, card number, sheba, holder — **drag-to-reorder priority** (jQuery UI sortable → AJAX with nonce + capability check).
- Per-card, independently toggleable limits: **total amount**, **transaction count**, **expiry date**; live used/remaining columns and reason-tagged inactive badges.
- `get_active_card()` walks cards in priority order, skipping exhausted or expired ones; the assigned card id is stored on the order and charged **only at approval** (bulk included).

### SMS layer (opt-in, silent until configured)
- Three providers behind one interface: **Kavenegar**, **Melli Payamak**, and a **custom endpoint** (optional Bearer token); 15 s timeouts, `WP_Error` handled.
- Per-event **templates and switches** for: new order, any chosen order status, receipt upload (admin notice), approved, rejected, payment reminder and an explicit test message — each event decides for itself, so statuses are chosen rather than blanket-enabled.
- Templates are plain text plus an allow-listed variable set (`{order_id}`, `{order_number}`, `{old_status}`, `{new_status}`, `{order_status}`, `{customer_name}`, `{billing_first_name}`, `{billing_last_name}`, `{billing_phone}`, `{order_total}`, `{currency}`, `{payment_method}`, `{site_name}`, `{order_date}`, `{receipt_status}`, `{receipt_reject_reason}`) resolved by one function from the order object. No `eval`, no request data; an unknown variable is reported in the preview and left as typed, and the preview never sends.
- One pipeline: `sms_send_event()` decides *whether*, `sms_deliver()` queues the row, `sms_attempt()` talks to the provider. Success is judged from the HTTP code **and** the provider payload, never from “no `WP_Error`”. Retries are bounded (3 attempts, 2/4/8-minute backoff) and only for transient failures; an invalid number fails once. A duplicate guard keyed on the event keeps the status hook and the receipt decision from sending the same message twice.
- `sms_log` records recipient, message, status (`sent`/`failed`/`unknown`), attempts, HTTP code, provider id, the next attempt and the **redacted** provider response; credentials and authentication headers are redacted before anything is stored and masked on screen. The history page shows the delivery state, the attempt count and a safe error summary with filters and pagination.
- **Nothing is sent until the master switch, a provider and at least one event template are configured.** Order alerts are completely independent of SMS.

### Admin quality-of-life
- Hourly **cleanup cron** counting retention from the **upload time** (approved receipts are kept by default) plus hourly deadline-reminder and deadline-enforcement crons; deactivation stops the schedules without deleting anything.
- Settings page (deadline, size, mimes, cleanup, alert sound), appearance & style screen (design tokens with a live preview of the real panel), **full reset button** (nonce-guarded reinstall of tables/options), RTL Persian admin UI with its own CSS design system.

## 4. Architecture

```
Customer (order-received / view-order)          Shop (wp-admin)
┌──────────────────────────────────┐   ┌────────────────────────────────────┐
│ countdown · bank card + copy     │   │ Order screen: review box + lightbox│
│ drop-zone upload → handle_upload │   │ Bulk review · Gallery · Reports    │
└───────────────┬──────────────────┘   │ Cards (drag-order)                 │
                │ nonce + owner check  └───────────────┬────────────────────┘
                ▼                                     │ AJAX approve/reject
   uploads/bank-receipts/  (.htaccess Deny from all)  │ nonce + capability
                │                                     ▼
                └────► order meta state machine ──► WooCommerce status
                        pending_review                 on-hold → processing (approve)
                        approved / rejected            on-hold → pending   (reject)
                               │                                     │
              ┌────────────────┼──────────────────┐                  │
              ▼                ▼                  ▼                  ▼
        receipt_log      cards (usage++)  transitions (claims)   WooCommerce hooks
        (audit trail)    (charged once)   (idempotency)          new_order / status_changed
                                                                        │
                                                                        ▼
                                     events queue ──► poll (per-manager cursor) ──► popup + beep
                                                                                      (Web Audio, local)

WP-Cron (hourly): wc_receipt_cleanup_hook → delete expired files
                  wc_receipt_reminder_hook → one SMS before deadline
                  wc_receipt_expire_hook   → close overdue orders

**State machine:** `pending_review → approved | rejected`; meta is **always saved before** `update_status()` so the hooks read the correct state (see §6). Alert events are a separate concern with their own table and never change an order (`docs/alerts.md`).

**Receipt panel layers:** `receipt_panel_context()` (decides and gathers, no HTML) → `receipt_panel_html()` (the only renderer, returns an escaped string) → three display methods (automatic hooks, shortcode, Elementor widget). Authorization lives in the context layer only, so no placement can skip it. See [`docs/compatibility.md`](wc-bank-receipt/docs/compatibility.md).

**Appearance layers:** `WCR_Design_Tokens` (one registry of `--wcrf-*` tokens with their type, range, group and default) → `resolve()` (mode, presets, accent coupling, theme-palette links, layout) → one `style` attribute on the panel root. The stylesheet declares the same defaults, so the plugin default look is unchanged and a value can only ever be a validated token. See [`docs/appearance.md`](wc-bank-receipt/docs/appearance.md).

## 5. Technology stack

| Technology | Role in the project |
|---|---|
| **WordPress plugin APIs** | Menus, settings API, nonces/capabilities, rewrite endpoint, WP-Cron, text domain i18n (Persian RTL) |
| **WooCommerce** | `bacs` gateway hooks, thank-you/view-order rendering, order meta + status transitions, admin order screen, **HPOS and classic list tables**, `woocommerce_new_order` / `woocommerce_order_status_changed` for alerts |
| **MySQL (dbDelta)** | `{prefix}wc_bank_receipt_cards`, `_receipt_log`, `_sms_log`, `_transitions`, `_events` with a versioned upgrade path |
| **`wp_remote_post`** | Outbound SMS to Kavenegar / Melli Payamak / a custom HTTP endpoint, through one pipeline with bounded retries and a redacted delivery log |
| **Web Audio API** | The admin alert beep — generated in the browser, no audio asset, no network |
| **jQuery UI sortable** | Drag-to-reorder card priority (persisted via AJAX) |
| **Enqueued asset files** | `assets/css/frontend.css` (every selector scoped under `.wcrf-panel`, every value a `--wcrf-*` token) and `assets/js/frontend.js` (one IIFE, no globals, no element ids), plus the admin-only `assets/js/admin-appearance.js` — no build step, no dependency, standard `wp_enqueue_*` handles a theme can dequeue |
| **CSS custom properties (design tokens)** | The appearance layer: validated tokens emitted on the panel root so the theme, the admin settings, a shortcode preset and a widget override compose by documented priority |
| **WordPress shortcode + Elementor widget API** | The same panel on any page or builder column; the widget class is loaded only when Elementor asks for its widgets |

## 6. Key engineering decisions

1. **FIX #1 — context-gated upload filters.** `upload_dir`, `upload_mimes` and `wp_handle_upload_prefilter` are global filters; here they only apply while `$receipt_upload_context` is true, so receipt rules (destination folder, MIME allow-list, size checks) can never break other plugins' uploads.
2. **FIX #2 — meta before status.** `approved/rejected` meta is saved **before** `update_status()` in single and bulk paths, so the `woocommerce_order_status_changed` hooks always see the right state and the right card.
3. **Charging cards only on approval.** The card is *assigned* at upload (recorded on the order) but its amount/transaction counters are incremented only when a human approves — rejected receipts never consume a card's limit.
4. **A deadline with memory.** Rejection resets `reminder_sent` and stamps `rejected_time`, so the countdown (and the one-shot reminder) restarts from the rejection, not the original order.
5. **Two independent protection layers for receipts.** The upload directory is denied by `.htaccess`, *and* viewing a receipt goes through a per-order nonce endpoint (`wc_receipt_view`) that checks ownership/capability and streams the file instead of redirecting to a public URL.
6. **HPOS-forward compatibility.** Order columns and custom order data are registered for both the classic post-type list table and WooCommerce's HPOS list tables, with `woocommerce_can_order_be_changed_to` used to allow the reject transition (`on-hold → pending`).
7. **One idempotency claim per decision.** Approve/reject insert a row into `_transitions` (`INSERT IGNORE` on `(order_id, receipt_uid, transition)`) *before* any side effect, so a repeated click, a retried request or four concurrent approvals can never charge a card twice.
8. **Alerts by event queue, not by option.** New orders and status changes are rows with a UNIQUE dedupe key; delivery uses a per-user cursor; acknowledging only moves that user's cursor — so multi-admin and multi-tab setups behave, and a reload never re-alerts. (The pre-7.3.0 design used one shared option and a dismiss that emptied it for everyone.)
9. **One context, one renderer, three placements.** The panel's business decision (`receipt_panel_context()`), its markup (`receipt_panel_html()`) and its placements (hooks, shortcode, widget) are separate layers. The 7.3.0 code could only *echo* the panel, which is why every new placement would have needed a second copy of the markup and its checks. (Details and the full audit: [CHANGELOG.md](wc-bank-receipt/CHANGELOG.md).)
10. **CSS/JS isolation as a contract, not a habit.** Front-end styles are anchored to the `.wcrf-panel` root and behaviour to per-root initialisation — the panel cannot restyle the theme, the theme cannot restyle the panel by accident, and two panels on one page stay independent. Enforced by checks (`TPL-01`, `TPL-07`, `TPL-08`).
11. **Clean uninstall story.** Deactivation stops the cron schedules and deletes nothing — tables, options, order meta and receipt files are preserved. Data is removed only through *Delete plugin* and only when the shop owner explicitly opted in (`wc_receipt_delete_data_on_uninstall = yes`).

## 7. Install and configure

**Requirements:** WordPress 6.5+, **WooCommerce 7.1+** (tested up to 11.2) with the built-in **BACS** transfer gateway, PHP 7.4+, MySQL 5.7+/MariaDB 10.3+. The header declares the floors; HPOS is supported through the order APIs and the classic order list keeps working.

```bash
# install the built package (or upload the same ZIP in wp-admin)
wp plugin install dist/wc-bank-receipt-7.6.0.zip --activate
```

1. Activate **WC Bank Receipt Ultimate Control** — the five tables (cards, receipt log, SMS log, transitions, alert events), the protected upload directory and the three hourly crons (cleanup, reminders, expiry) are created automatically. Deactivating the plugin deletes nothing.
2. **تنظیمات فیش بانکی** → deadline, max upload size, allowed formats, cleanup cadence, alert sound (with a test button).
3. **کارت‌های بانکی** → add destination cards, set per-card limits, drag them into priority order.
4. **ظاهر و استایل** → optional: match the panel to the shop's design (or leave it on the theme-aware default) and watch the live preview.
5. Take an order through the BACS flow: the customer uploads on the thank-you page → **بررسی گروهی** or the order screen to approve/reject. Keep the dashboard open in another tab to watch the alert popup and hear the beep.

## 8. Testing and verification

The audit ships with an executable acceptance harness ([tests/acceptance.php](tests/acceptance.php)) that runs against a
**real** site (WordPress 7.1.3 + WooCommerce 11.2.1 + PHP 8.2.12, HPOS enabled, Apache with its `.htaccess`
rules in place). It drives the plugin through HTTP — guest upload, admin AJAX, bulk POST, cron hooks,
protected file viewing, alert polling — and asserts on the shop's own storage.

| Run | Result | Evidence |
|---|---|---|
| 7.1.0 (pristine) | **22 pass / 29 fail** | [baseline-run.txt](tests/evidence/baseline-run.txt) |
| 7.3.0 (alert release) | **51 pass / 0 fail / 0 skip** | [fixed-run.txt](tests/evidence/fixed-run.txt) |
| 7.3.0 installed from the ZIP | **47 pass / 0 fail** (every group except the slow perf setup) | [installed-package.txt](tests/evidence/installed-package.txt) |
| 7.4.0 — all 14 groups | **77 pass / 0 fail / 0 skip** | [compat-run.txt](tests/evidence/compat-run.txt) |
| **7.4.0 installed from the ZIP** | **73 pass / 0 fail** (every group except the slow perf setup) | [installed-package-7.4.0.txt](tests/evidence/installed-package-7.4.0.txt) |
| 7.5.0 / 7.5.1 (appearance, then the decision fix) | **91 pass / 0 fail / 0 skip** each | [appearance-7.5.0.txt](tests/evidence/appearance-7.5.0.txt) · [release-7.5.1.txt](tests/evidence/release-7.5.1.txt) |
| **7.6.0 (this release) — all 18 groups** | **124 pass / 0 fail / 0 skip** | [full-18-groups.txt](tests/evidence/full-18-groups.txt) |
| **7.6.0 installed from the ZIP** (sec, flow, file, sms, origin, lb, ui) | **59 pass / 0 fail**, schema at DB 7.6.0 with every stored row preserved | [installed-package-7.6.0.txt](tests/evidence/installed-package-7.6.0.txt) |

The same harness file measures **both** builds (it probes for the constants/methods it uses), so the comparison is
like-for-like. Highlights:

* the legacy alert path missed orders entirely: 0 events for a card-to-card order (`ALERT-01`) and 0 for a COD order (`ALERT-02`) → 1 correctly-shaped event each in 7.3.0; no status-change events at all (`ALERT-03`);
* the legacy popup wrote order data with `innerHTML` (`ALERT-08`) → every value is now a text node;
* a subscriber could empty the shared alert queue on 7.1.0 (`SEC-04`) → the queue survives and both endpoints answer 403;
* dismissing in one manager's tab wiped every pending alert (`ALERT-10`, `ALERT-06`) → each manager keeps their own cursor and no manager can consume another's queue;
* cross-order guest upload: the victim order was mutated in 7.1.0 → untouched in 7.3.0 (`SEC-01`);
* four parallel approvals: the card was charged **400,000** in 7.1.0 → **100,000** (once) in 7.3.0 (`FLOW-10`);
* deactivation: tables + receipt files destroyed in 7.1.0 → nothing deleted in 7.3.0 (`LIFE-02`);
* gallery with 125 receipts: one unpaged page in 7.1.0 → 24 items with pagination in 7.3.0 (`PERF-01`);
* without WooCommerce: front page HTTP 500 in 7.1.0 → HTTP 200 in 7.3.0 (`COMP-01`).

```bash
php tests/acceptance.php            # all groups (needs WP_PATH and a reachable SITE_URL)
php tests/acceptance.php sec,flow   # selected groups
tools/run-acceptance.sh             # same thing with the local test-site paths filled in
```

The 7.4.0 groups are `tpl` (12 checks: one renderer, multi-instance, scoping, asset loading),
`elm` (6: the Elementor widget), `theme` (5: the same order page on Storefront, Hello
Elementor, Twenty Twenty-Five and Twenty Twenty-Four) and `page` (3: the classic
`thankyou.php` template and the customer's view-order screen).

The 7.5.0 group is `style` (14 checks: the appearance screen and its real `options.php`
save, the tokens on the live panel, accent coupling, whitelisted shortcode presets, two
independent placements on one page, hostile settings input, a page without a panel,
reset, stylesheet/default parity, capability enforcement, order-data immutability and two
Elementor instances with different controls).

The 7.6.0 groups are `origin` (7 checks: a customer checkout and a customer upload are
queued, a manager's manual status change and a receipt decision are suppressed, the
classification context is released even when the callback throws, an unknown origin is
queued rather than guessed, and the SMS policy still fires for a suppressed event), `lb`
(9: the shared viewer markup and assets, the streamed file's headers, the 403 for a
foreign viewer and for a direct file URL, the PDF branch, and the absence of a viewable URL
for a guest) and `ui` (9: the receipt state model per phase, the alarm contract — repeat
interval, acknowledgement storage, one-tab lease, bounded option — the viewer colours as
design tokens, the admin editor's assets and config, and the concrete alarm/lease/ack
markup) — **124 checks in 18 groups**, all green.

A run takes a lock file, so a
second suite refuses to start instead of corrupting the first one's evidence.

## 9. Known limitations and roadmap

- **One large main file.** `wc-bank-receipt.php` still holds the admin UI, the panel logic, alerts, SMS and cron in a single class (the front-end CSS/JS and the Elementor widget now live in `assets/` and `includes/`); splitting the class into `includes/` modules is the next refactoring step.
- **No PHPUnit/CI pipeline yet** (see §8): the acceptance harness is integration-level, not a unit-test suite.
- **No `.pot` translation catalog** despite the text-domain wiring — Persian strings are inline defaults.
- **SMS is exercised through a simulated HTTP layer**, not with live Kavenegar/Melli Payamak accounts, so the provider *contract* (payload shape, success judgement, retry classification) is asserted from the plugin's side and from recorded provider answers. The screens are enabled in 7.6.0 and stay silent until the master switch, a provider and a template are configured; the checks assert that silence, the template variable set, the bounded retries and the redaction of stored responses.
- **Autoplay-blocked sound** is covered by the popup notice, the enable button and the settings switch; the harness asserts the option contract and the popup, while the click-to-enable path was verified in a real browser (see `docs/screenshots/`).
- **Alert polling is the transport** (10 s default, configurable via filter). It is cheap and CDN-safe; an SSE/WebSocket push layer is deliberately not attempted inside wp-admin.
- **Appearance layer — verified and not verified.** The 14 `style` checks and the screenshots (`docs/screenshots/09-appearance-styles.png`, `08-customer-upload-form.png`) cover the screen, the save path, presets, the priority order and the injection surface. *Not* measured: Elementor's editor UI (driven through real builder data, not clicked through by hand), **Global Colors / Global Fonts** resolution (reasoned from the control values, not measured against a kit that defines them), script optimisers and caching plugins (not installed), and PHP 7.4 as an execution target (the floor is a syntax scan). A freely chosen palette is also a contrast choice the plugin cannot make for the owner. Details: [`docs/appearance.md`](wc-bank-receipt/docs/appearance.md).
- **Deadline reminders** (`send_payment_reminders`) are covered by code review and the shared idempotency claim, but have no end-to-end check.
- **PHP 7.4 floor is verified by syntax scan**, not by running the suite on 7.4; WooCommerce was run only on 11.2.1.
- **Decimal currencies** are not exercised (amounts are integer currency units).
- **The classic thank-you template is verified on one classic theme** (`PAGE-01`: Storefront 4.6.2 plus the shortcode checkout, which is what makes WooCommerce pick `thankyou.php`). Which of the two confirmation templates a shop gets depends on the theme and the checkout page, so a shop with a heavily customised `thankyou.php` can still differ; the panel is printed from the same shared renderer in both paths.
- **Block checkout and the guest window.** When the checkout page is the WooCommerce *Checkout* block, the panel renders inside WooCommerce's *Additional Information* block, which is what fires `woocommerce_thankyou_bacs`. WooCommerce renders that block only while the guest's order-details window is open, so a guest who opens the confirmation URL much later gets WooCommerce's own verification step and no receipt form. Measured: a fresh order renders the panel, the identical URL for a several-hours-old order does not; the same order keeps its panel on *My account → View order*. This is WooCommerce's policy, not a plugin defect, and it is why the harness always fetches a freshly created order.
- **Elementor is verified against one real install** (4.3.5, downloaded from wordpress.org during the run): widget registration, a real Elementor-built page rendered over HTTP, the editor placeholder and the “no Elementor” path. A hand-built layout in the Elementor editor UI was not clicked through, and only one Elementor version was used.
- **Script/asset optimiser plugins** (defer/combine/minify/move-to-footer) were not installed in the test site; the panel script is footer-only and jQuery-independent, and the form works without JavaScript.
- **SMS credentials are plain options** in the database (standard for WP plugins, but a secrets abstraction would be better).

### Roadmap (not implemented yet)

- PHPUnit + CI workflow; `.pot` catalog and English language pack.
- Split the class into modules (receipts / cards / alerts / SMS / cron).
- Re-verify the SMS provider contract on a live Kavenegar / Melli Payamak account (the pipeline, the retries and the delivery log are already covered against a simulated HTTP layer).
- Optional alert filters (per status, per payment method) and a persistent "alert history" view.

## 10. Documentation

| Doc | Contents |
|---|---|
| [README.fa.md](README.fa.md) | The Persian version of this document |
| [RELEASE-REPORT.md](RELEASE-REPORT.md) | Final report: root causes, before/after evidence, test matrix, remaining risks |
| [COMPAT-REPORT.md](COMPAT-REPORT.md) | Phase report: the compatibility audit, what changed, what was tested, what was not |
| [APPEARANCE-REPORT.md](APPEARANCE-REPORT.md) | Phase report: the appearance layer — fields and defaults, precedence, tokens, CSS loading, tests and what is not verified |
| [wc-bank-receipt/docs/compatibility.md](wc-bank-receipt/docs/compatibility.md) | Display methods, shortcode and Elementor documentation, CSS/JS isolation contract, compatibility matrix, limitations |
| [wc-bank-receipt/docs/appearance.md](wc-bank-receipt/docs/appearance.md) | Appearance design tokens, defaults, presets, precedence order, validation and what was tested |
| [wc-bank-receipt/docs/alerts.md](wc-bank-receipt/docs/alerts.md) | Order-alert event queue, origins, repeating popup sound and delivery semantics |
| [wc-bank-receipt/docs/sms.md](wc-bank-receipt/docs/sms.md) | SMS providers, events, templates and variables, the retry policy and the delivery log |
| [wc-bank-receipt/docs/state-machine.md](wc-bank-receipt/docs/state-machine.md) | Order/receipt state machine, guards, idempotency, deadlines |
| [wc-bank-receipt/docs/security-model.md](wc-bank-receipt/docs/security-model.md) | Capabilities, ownership rules, secrets and privacy |
| [wc-bank-receipt/docs/retention-policy.md](wc-bank-receipt/docs/retention-policy.md) | When receipt files are kept or deleted |
| [wc-bank-receipt/README-fa.md](wc-bank-receipt/README-fa.md) | راهنمای فارسی نصب، راه‌اندازی، اعلان‌ها، تنظیمات و عیب‌یابی |
| [wc-bank-receipt/docs/troubleshooting-fa.md](wc-bank-receipt/docs/troubleshooting-fa.md) | عیب‌یابی گام‌به‌گام و روش گزارش خطا |

---

**More from this portfolio:**
[hyper_barcode](https://github.com/DeathGunSR/hyper_barcode) ·
[HolooDB_Sync](https://github.com/DeathGunSR/HolooDB_Sync) ·
[Hyper_Event](https://github.com/DeathGunSR/Hyper_Event)

Maintained by [@DeathGunSR](https://github.com/DeathGunSR)

</div>
