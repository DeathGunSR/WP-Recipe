# Changelog

All notable changes to **WC Bank Receipt Ultimate Control** are documented here.
Version 7.2.0 was a security, lifecycle and order-flow rebuild of 7.1.0: the plugin was
audited first, the defects were reproduced on a real WordPress + WooCommerce + Apache
environment, and every fix below is backed by an acceptance check. 7.3.0 added the
order-alert system and temporarily switched the SMS interface off. 7.4.0 makes the
customer-facing receipt panel portable: one context, one renderer and three display
methods, with the CSS and JS scoped to the panel. 7.5.0 adds the appearance layer: the
panel follows the theme by default and can be restyled from the admin without CSS, through
a validated set of design tokens.

Reference environment: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.2.12, HPOS enabled,
MariaDB (XAMPP), Apache with real `.htaccess` handling.

---

## 7.5.1 — one decision per receipt instance

A reliability fix on the decision path, found by a release audit of 7.5.0. Every shop that
ever had two manager tabs (or two managers) open on the same pending receipt was exposed
to it; nothing here changes the intended behaviour of a single decision.

### The defect

`apply_transition()` gated a decision on the receipt state read from order meta at the
start of the request, and then claimed the operation in the `…_transitions` ledger with
the key `(order, receipt, transition)`. Because `approve` and `reject` are two *different*
transition values, an approve and a reject on the same pending receipt did **not** collide:
both requests read `pending_review`, both inserted their own ledger row, and both then
applied their effects. The card was charged, the order was marked paid, and the receipt
ended up `rejected` — so the customer was told to upload a new receipt for an order that
was already paid, with a second charge waiting if they complied.

### The fix

* `approve` and `reject` now share **one** claim per receipt *instance*: the ledger key is
  `('decision', sha256(file))`. Exactly one of the two requests inserts the row, and the
  loser gets the normal `duplicate` result instead of a second effect. The ledger still
  records the real decision (`approve` / `reject`) alongside the lock, so the audit trail
  is unchanged in meaning.
* The claim is scoped to the receipt instance and not to the order, so a receipt that was
  rejected and uploaded again — a new file, a new instance — is decided normally.
* After a successful claim the winner re-reads the authoritative state
  (`read_meta_data(true)`) before it touches anything: `claim_matches_current_state()`
  rejects `approve`/`reject` unless the receipt is still in `pending_review` and still the
  same instance, refuses `upload` over a receipt that is already approved or under review,
  and re-checks the deadline for `expire`. This also covers pairs that legitimately do not
  share a claim key (an upload racing an approval).
* The single-charge guarantee is untouched: `card_charge` keeps its own claim, so the order
  is charged once, by the request that actually approved the receipt.

### Evidence

`tests/audit-race-inproc.php` (probe, not shipped) runs two OS processes released from a
barrier against the same pending receipt:

| build | rounds where approve *and* reject both took effect |
|---|---|
| 7.5.0 (as published in this repository) | **6 / 6** |
| 7.5.1 (fixed) | **0 / 8** |

Raw output: `tests/evidence/audit-race-before.txt` and `tests/evidence/audit-race-after.txt`;
full re-run of the acceptance suite on 7.5.1 (`91/91`) in `tests/evidence/release-7.5.1.txt`.

---

## 7.5.0 — appearance and design-token system

A shop owner can now make the receipt panel match the shop's design without editing CSS,
touching a template or installing Elementor — and the default look follows the theme
instead of imposing one. Every fix and feature below is covered by a check in
`tests/acceptance.php` (group `style`) and described in `docs/appearance.md`.

### Why tokens, and why now

7.4.0 scoped all front-end CSS to `.wcrf-panel`. That made the panel safe to place anywhere
but left the values themselves hard-coded in the stylesheet: the panel was isolated, not
*adjustable*. The naive fix — one settings field per CSS rule, or a `<style>` block written
from the settings — has two failure modes that were ruled out by design:

| Rejected approach | Why it was rejected |
|---|---|
| Free-text "custom CSS" box | A shop owner has to know the internal class names, the box is an injection surface, and the classes become a public API that can never change |
| One stored value per rule, printed per selector | Every new rule needs its own option, validation and precedence branch; the panel drifts out of sync (a new button forgets `--primary`) |
| Overriding the theme's colours globally | Changing the site for a receipt form is unacceptable; the requirement is a panel that matches the site, not a site that matches the panel |

So the adjustable surface is a **fixed, documented set of CSS custom properties**, resolved
by one function and printed on the panel's own root element. A new component reads the
token; nothing else has to change. The token set is the contract, and it is deliberately
small enough to be documented and tested value by value (`STYLE-11`).

### New: the appearance screen

* **فیش بانکی → ظاهر و استایل** (`wc-receipt-appearance`, `manage_options`) with three
groups — colours, typography, shape & spacing — and a live preview of the real component.
* Colours: primary and its hover shade, text, muted text, surface, field background, border,
  the bank card (start/end/text) and the button (start/end/hover/text) plus success, warning
  and error with a background for each.
* Typography: font stack (a named list, `inherit` first — no external font is ever loaded),
  base/title/small sizes, line height and the two font weights.
* Shape & spacing: card/button/field/base radii, border width, card shadow, padding, gap,
  maximum form width and a compact/comfortable density (with a *custom* escape hatch).
* These are the accepted colour inputs: a hex value from the picker, a theme-palette slug
  from the current theme, or empty. There is no free-text field anywhere in the screen.

### New: three modes, and a default that follows the theme

* **Theme-aware / inherit** (the default): `font-family: inherit`, `font-size: 1em`,
  `line-height: inherit` and the CSS system colours (`CanvasText`, `Canvas`, `GrayText`) for
  text, surface and border, so a dark or block theme keeps the panel dark and readable;
  the accent and status colours stay plugin-owned, because a theme that publishes nothing
  usable must still produce a contrast-safe panel.
* **Presets** — Minimal, Modern, Flat / Professional: whole coherent value sets applied as
  tokens. A preset never produces different markup, a second stylesheet or a second form.
* **Custom** — only the fields that were filled in apply; everything else stays default.
  An empty field stores nothing and is always shown with the value that currently applies.
* Optional **automatic accent coupling**: one accent colour fills the hover shade, the button
  trio and the card gradient — but only tokens that are still at their default, so an explicit
  value or a theme-palette link always wins.

### New: theme-palette links, shortcode presets, Elementor controls

* Three optional pickers link the panel to the palette the theme/Global Styles already
  publishes (`wp_get_global_settings( ['color','palette'] )`) as
  `var(--wp--preset--color--{slug}, <plugin default>)`. The panel reads a theme colour and
  never writes one; on a classic theme without a `theme.json` palette the fallback inside the
  same `var()` keeps the panel correct and the link is simply invisible. **This is a
  best-effort inheritance, not automatic design detection** — the picker lists the slugs the
  current theme actually defines instead of guessing names.
* `[wc_bank_receipt style="minimal"]` (with or without `order_id`) applies one whitelisted
  preset to that placement only; an unknown value is ignored and no CSS can be passed in.
  `style` is a visual option and has no influence on authorization — an order id in a
  shortcode is still a selector, never a credential.
* The Elementor widget («پنل فیش بانکی», **Style** tab) exposes the same tokens as native
  controls (preset, accent, text, surface, typography group, two sliders) with Elementor's
  own pickers, so the kit's **Global Colors** and **Global Fonts** are available; a global
  colour becomes `var(--e-global-color-{slug}, <default>)`. Every control is empty by default
  and means “use the plugin's setting”; a widget override applies to that instance only.
  Elementor is optional throughout: the appearance screen and the shortcode cover the same
  ground without it.

### Fixed

* **Dark-theme contrast:** the upload drop zone used the CSS system colour `Field` for its
  background. Unlike `Canvas`, Chromium does not adapt `Field` to a dark colour scheme, so a
  dark theme produced a white zone with white text. The token default is now a theme-aware
  surface value, and the drop zone follows the dark scheme.

### Security and isolation

* The appearance state is **one option** (`wc_receipt_appearance`) in **its own options
group**, so `options.php` can never write a payment, retention or SMS setting from an
appearance save — and vice versa.
* Every value is validated server-side against its own type and range (a hex colour via
`sanitize_hex_color`, a strictly parsed `rgba()`, palette slugs against `[a-z0-9_-]`, sizes
parsed and clamped to their range, line height clamped, enumerated modes and named choices
checked against their list, unknown token names dropped). A last character guard rejects
`;`, `{`, `}`, `<`, `>`, `\`, `!`, `url(`, `@import` and `javascript:` before anything is
printed. An invalid value results in a dropped field, never in repaired CSS.
* Values are emitted as a `style` attribute on `.wcrf-panel` — never on `:root`, `body` or
any element outside the panel — so custom-property inheritance confines them to the panel
subtree. An untouched installation prints no declaration at all. No new `!important` was
added, and the only one in the stylesheet is the pre-existing `[hidden]` rule.
* Changing the appearance reads and writes **nothing else**: no order, status, receipt meta,
receipt file, bank card, log row or other option. `STYLE-13` asserts this against the real
order data.

### Changed

* `assets/css/frontend.css` now expresses every visual value through a `--wcrf-*` token whose
  default is declared on `.wcrf-panel`; sizes are relative (`em`, `calc()` over the spacing
  tokens) so a font-size or padding change scales the whole panel instead of leaving stray
  pixels behind.
* New files: `includes/design-tokens.php` (the registry, resolution and validation),
  `assets/js/admin-appearance.js` (admin-only live preview) and `docs/appearance.md`.
* The admin screen no longer assumes the settings page's option group; the settings screen
  and the appearance screen each save through their own group.

### Not verified — do not assume it works

* Elementor's **editor UI** was exercised through real builder data, not by driving the
  editor by hand, and Elementor's **Global Colors/Fonts** resolution follows the documented
  variable names rather than a kit that defines them.
* No script optimiser, combine/minify plugin or page cache was installed.
* One WooCommerce and one Elementor version were available; the suite ran on PHP 8.2 (the
  PHP 7.4 floor is asserted by a syntax scan, not by execution).
* Contrast of a freely chosen palette remains the shop owner's responsibility.

### Migration

Nothing to migrate. No default output changes: with no saved appearance option the panel
renders byte-for-byte the same tokens as the shipped stylesheet, and the option is created
only when the screen is saved. Deactivation and uninstall behaviour is unchanged.

## 7.4.0 — theme, page-builder and plugin compatibility

7.3.0 made the alerts reliable; 7.4.0 makes the receipt panel independent of the page it
is printed on. The findings below come from reading the 7.3.0 code in this repository, and
every fix is covered by a check in `tests/acceptance.php` (group `tpl`).

### Audit: what was incompatible, and why

| # | Finding in the 7.3.0 code | Real effect |
|---|---|---|
| 1 | The panel was produced *inside* `display_receipt_form()`, which echoed its markup; the shortcode only wrapped that method in an output buffer. There was no way to obtain the panel as a value, so no other display method (and no template) could reuse it | Any new placement meant a second copy of the markup — the usual source of “the form looks different / skips a check on this page” |
| 2 | The display path re-implemented authorization inline (`is_user_logged_in() && get_current_user_id() == …` else `$_GET['key'] === $order->get_order_key()`, unsanitized), while the upload path used a sanitized `hash_equals()` helper | Two rules for one question, with slightly different behaviour (unsanitized input on the display path) |
| 3 | Element ids in the panel: `#wc-receipt-container`, `#receipt-upload-form`, `#wcrf-timer-box`, `#wcrf-timer`, `#wcrf-progress`, `#wcrf-drop-zone`, `#wcrf-file-input`, `#wcrf-preview-wrap`, `#wcrf-preview-img`, `#wcrf-file-name` — plus `wp_nonce_field()`, which emits `id="receipt_nonce"` | Two panels on one page produced duplicate ids and the inline script bound only the **first** panel's elements: the second panel's drag-and-drop, preview and countdown silently did nothing |
| 4 | A global `function wcrCopy()` on `window` called from `onclick="wcrCopy(this)"`, and a file input hidden with `style="display:none"` that only that inline script could open | A global pollutes every page; the clipboard had no fallback (a refused Clipboard API left the button dead); with JavaScript disabled the upload form was unusable |
| 5 | All front-end CSS was inline (`wp_add_inline_style`) in the page head with `.wcrf-*` selectors that were not anchored to a panel root, and the markup also carried an inline `style` on the preview image | No scoping guarantee, nothing a theme could dequeue or override as a file, no browser caching, and style changes shipped with every page |
| 6 | Front-end assets loaded only on two WooCommerce endpoints; the shortcode enqueued the style **at render time** (i.e. in the footer) and registered it with `false` as the source (inline-only handle). Handles were registered with the hardcoded version `7.1.0` | A panel on a custom page flashed unstyled; a release never invalidated the browser cache; a theme could not meaningfully dequeue or replace the panel CSS |
| 7 | With WooCommerce inactive the constructor returned before `add_shortcode()`, and `add_shortcode` was the *only* registration | A page that used `[wc_bank_receipt]` printed the raw shortcode text to visitors while WooCommerce was disabled |
| 8 | The panel's behaviour was inline in the markup, so a builder that inserts content after load (Elementor editor, AJAX-loaded blocks) never initialised it | The panel appeared but the countdown and the copy button did nothing in those previews |

### Fixed: one component, three display methods

* **Layer A — context.** `receipt_panel_context()` decides whether a visitor may see the
  panel and gathers every value (order, card, receipt state, deadline, upload allowance).
  It returns data only: no HTML, no echoing, no side effects.
* **Layer B — renderer.** `receipt_panel_html()` is the only place the markup exists and
  returns a fully escaped string. A theme can replace it with the
  `wcr_receipt_panel_html` filter (and adjust values with `wcr_receipt_panel_context`).
* **Layer C — display methods.** The automatic WooCommerce hooks, the new
  `[wc_bank_receipt]` shortcode and the new Elementor widget all call layer B.
* **One authorization rule.** Display, upload POST and the receipt viewer now call the same
  `visitor_may_view_order()` (capability, ownership, or that order's key via sanitized
  `hash_equals()`). An order id in a shortcode attribute or widget setting is a *selector*,
  never a credential; a page with no order context renders nothing at all.
* **One panel per page.** The first method that prints an order's panel marks it for the
  request and the others skip it, so a thank-you page that also carries the shortcode still
  shows one panel. Two shortcodes placed side by side intentionally stay two panels.
* An early version of this release skipped the automatic hooks whenever the page content
  contained the shortcode. The `TPL-11` check — the real checkout page with the shortcode
  appended — showed that a block template can render the page without executing that
  content, which would have left the panel out entirely. The guess was removed; duplicates
  are now prevented *after* the first render instead of predicted before it.

### Fixed: presentation is isolated

* New `assets/css/frontend.css`: **every** selector is prefixed with the panel root
  `.wcrf-panel`, custom properties are declared on that root with a `--wcrf-` prefix, and
  there is no `body`, `:root`, bare-element or generic theme-class selector. The panel
  cannot restyle the theme and the theme cannot restyle the panel by accident. RTL layout
  and latin values (card number, IBAN, countdown) are handled inside the panel, and a
  narrow-screen block covers mobile and narrow builder columns.
* New `assets/js/frontend.js`: one IIFE, **no globals**, **no `getElementById`**, one
  initialisation per root, listeners on the root (delegation) so re-rendering cannot stack
  handlers, and a `MutationObserver` for panels inserted later by a builder.
* The markup now carries classes and data attributes only (`data-wcr-order`, `data-wcr-copy`,
  `data-wcr-timer`, `data-wcr-preview`, …): no element id anywhere, including the upload
  nonce field, which is printed by hand instead of through `wp_nonce_field()`.
* The file input covers the drop zone, so choosing a file and dragging one in work with
  **JavaScript disabled**; the script only adds the preview, the drag styling and the copy
  feedback. The clipboard has two fallbacks (legacy `execCommand`, then selecting the value
  on screen with a message) and never throws into the page.
* Failed features are contained: each panel is initialised in its own `try/catch`.

### Fixed: assets load where the panel is

* Both handles are real, versioned files (`?ver=` = plugin version), enqueued through the
  standard API, so a theme can `wp_dequeue_style( 'wcr-frontend' )` /
  `wp_dequeue_script( 'wcr-frontend' )` or replace them.
* They load on the WooCommerce order endpoints and on any singular page that actually
  renders the panel — including Elementor layouts, which live in `_elementor_data` meta and
  never appear in `post_content`. Everywhere else (home, shop, blog, cart, checkout) nothing
  is loaded. `wcr_load_frontend_assets` forces them for a code-injected panel.
* The Elementor editor preview loads the same files.
* With WooCommerce inactive the shortcode is still registered and renders nothing, so no
  raw shortcode text reaches the page, and the plugin still cannot fatal.

### New: Elementor widget

* `includes/elementor-widget.php` defines one widget, «پنل فیش بانکی»
  (`wc_bank_receipt_panel`, WooCommerce category) with a single optional order-id control.
  The file is loaded **only** from `elementor/widgets/register`, so a site without Elementor
  never even parses the class that extends Elementor's base class (verified: the class is
  not loaded with Elementor deactivated).
* The widget is a thin adapter: no order reading, no authorization, no upload code, no
  inline styling. In the editor (no real order context) it renders a neutral placeholder
  rather than another visitor's data.

### Compatibility and verification (group `tpl`, 12 checks)

Real HTTP checks against the test site: two panels on one page (0 duplicate ids, one
stylesheet, one script, no inline handler), shortcode with no order context (renders
nothing), guest with another order's id (neutral notice, no card number, no customer
name), the real order page (1 panel, file assets, no inline CSS), the render-once guard,
content detection for both `post_content` and Elementor layouts, the stylesheet scoping
rule (every selector under `.wcrf-panel`, no `body`/`:root`), the script hygiene rule (no
`getElementById`, no `wcrCopy`, no inline `onclick`), no assets on unrelated pages, versioned
asset URLs, the checkout page carrying the shortcode (still exactly one panel), and the
shortcode page with WooCommerce deactivated. Group `elm` adds six checks against a real
Elementor install (widget registered, panel rendered over HTTP on an Elementor-built page,
placeholder in the editor, assets enqueued, class not loaded when Elementor is off).

Group `theme` renders the same order page on **every installed theme** — Storefront 4.6.2
(classic), Hello Elementor 3.5.1 (classic), Twenty Twenty-Five 1.5 and Twenty Twenty-Four
1.6 (block) — and asserts one panel, both file assets, no duplicate ids and no fatal error
per theme, then that the markup keeps the same structure on all of them. Group `page`
covers the two order screens a customer actually uses: the **classic `thankyou.php`**
template (the checkout page is switched to the shortcode variant and a classic theme is
activated for the check, because WooCommerce only then picks the classic template) and
**My Account → “view order”** for the owner — plus the same screen for a different
logged-in customer, which must show no panel, no bank card and no order tag.

One behaviour changed while writing `theme`: the countdown's initial value is now rendered
server-side (the real remaining time) instead of an ASCII `--:--:--` placeholder, so there
is no empty flash before the script ticks and no content filter can rewrite the markup
(WordPress' texturizer turns `--` into an en dash on one template path).

### Upgrade notes

* No data, setting, table, order meta or receipt file is changed by this version.
* Anything that called the old global `wcrCopy()` from a theme must be dropped; the copy
  button now works through the panel's own script.
* Anything that styled `#wc-receipt-container` must target `.wcrf-panel` instead; the id is
  gone on purpose so two panels can share a page.

---

## 7.3.0 — order alerts (sound + popup) and SMS UI off

### New: admin order alerts

**Two independent events.** `woocommerce_new_order` queues a `new_order` event for an
order placed through **any** payment method, and `woocommerce_order_status_changed` queues
a `status_change` event with the previous and the new status. The two are separate events,
but a single event is never alerted twice:

* the status WooCommerce applies to a brand-new order (`pending → on-hold` right after
  creation) is folded into its new-order event instead of becoming a second one;
* the append itself is the duplicate check — a UNIQUE `dedupe_key`
  (`event | order | from | to | time window`) makes two concurrent requests collide on the
  insert, and the second one is discarded;
* an event that repeats later (minutes or hours after the first) has a different time
  window and is kept as its own event.

The row stores a snapshot (customer, order number, formatted total as plain text, payment
method, item count, edit-order URL, GMT timestamp), so a deleted or unreadable order still
shows a safe, honest popup. Fields that are genuinely unknown stay empty instead of being
guessed.

**Managers, not visitors.** The popup script and its AJAX endpoints exist only in
wp-admin and only for users with the order-management capability; a subscriber or a guest
receives no markup, no nonce and no data (403 otherwise). Customer values are read on the
server through the WooCommerce order APIs and written into the DOM with `textContent`;
the popup contains no `innerHTML`, no `document.write` and no `eval`.

**One queue per manager.** The cursor is stored in user meta
(`wcr_alert_cursor`), not in a shared option, so every manager receives every event once:
one admin closing the popup cannot swallow another admin's alert, and two browser tabs of
the same user do not fight over the queue. A user who polls for the first time starts from
"now" instead of replaying the whole retention window, and events are acknowledged only
after they were rendered — a lost response cannot lose an event.

**Sound without a file or a network.** The beep is generated locally with the Web Audio
API (two short tones), so there is no audio asset to ship, no CDN and no request. It is
switched by the `wc_receipt_alerts_sound` option (settings → a test button is provided),
rate-limited by a cooldown (a burst of status changes beeps once, the events are still all
queued), and a blocked autoplay or a failing audio context only shows an explanatory
notice with an enable button — it never prevents the popup. A duplicate event cannot cause
a second beep: the client deduplicates by event id and never re-alerts acknowledged ids.

**Polling that survives real shops.** Requests are spaced (default 10 s, filterable),
a response carries only events newer than the caller's cursor (max 20 per call, with a
`has_more` hint), transport errors back off exponentially instead of stopping the alerts
for good, the poller pauses on a hidden tab, and a page reload does not re-alert delivered
events. The queue is pruned to the newest 1000 rows / 7 days, and pruning runs on a small
fraction of the inserts so the write path stays cheap.

**Queue strip.** When several events arrive close together they are never dropped: the
card on screen stays, and the rest are listed behind it with their order number, event
type and time. Clicking a queue entry brings it forward; **بستن همه** clears the whole
queue (Escape closes one card at a time), so the button always does what its label says.

**No network anywhere in the popup.** The bell is an inline SVG instead of an emoji
character, because WordPress core replaces emoji with images loaded from its CDN — the
popup markup therefore has no external dependency at all, and the beep is generated
locally by the Web Audio API.

**Replaces the old path.** The previous release only alerted card-to-card orders through a
single shared option and wrote order values into `innerHTML`. The popup and its endpoints
were rebuilt on the event queue above (`ajax_alerts_poll` / `ajax_alerts_dismiss`).

### Changed: SMS interface temporarily disabled

* `SMS_UI_ENABLED = false`: the SMS tab is not registered in the plugin menu and both SMS
  pages answer `403` even for an administrator who opens the URL directly.
* The sending layer is untouched and never called while the flag is off — a status change
  sends no SMS and writes no SMS log row.
* Nothing is deleted: the SMS table, the provider settings, the templates and the stored
  credentials stay in place, so the tab can be re-enabled in a later release.
* Order alerts are independent of the SMS layer.

---

## 7.2.0 — security & order-flow release

### Security (P0)

**Guest upload could attach a receipt to somebody else's order.**
*Root cause:* the upload form used one shared guest nonce that was not bound to the
order, and `handle_upload()` trusted the posted `order_id` without checking ownership,
capability or the WooCommerce order key. Any visitor could POST a different `order_id`
and mutate that order.
*Reproduced:* baseline acceptance check `SEC-01` — a guest uploaded to a victim order and
the order moved to `on-hold` with a new receipt file.
*Fixed:* an order-scoped nonce (`upload_receipt_<order_id>`) plus an ownership gate
(logged-in owner by user id, guest by the WooCommerce order key), and the request must
target exactly the authorized order. `SEC-01` now leaves the victim order untouched.

**Receipt files could be handed out through a shareable URL.**
*Root cause:* the receipt viewer authorized with a nonce only and then redirected to the
public uploads URL, so a leaked link worked for anyone (and the file itself was
reachable once its URL was known).
*Reproduced:* baseline `SEC-06` — the viewer answered `302` with
`Location: …/wp-content/uploads/bank-receipts/…`.
*Fixed:* the viewer checks order ownership (customer) or capability (admin), never
redirects to the stored file, streams the bytes with the correct content type, and the
folder stays blocked by `.htaccess` (`SEC-06`, `SEC-07`).

**AJAX surface hardened.** Every state-changing endpoint (`approve`, `reject`,
`card reorder`, new-order alert, alert dismissal) now validates capability *and* nonce,
returns sanitized output, never injects customer data with `innerHTML`, and does not
expose customer names or totals to unauthorized roles (`SEC-03`, `SEC-04`, `SEC-08`).

**Uploads validated by content.** Real MIME detection (not the extension or the client
header), size limit, allow-list (JPEG/PNG/PDF), random 32-hex file names, and a rejected
request leaves no file, no meta, no status change and no card usage behind (`SEC-02`,
`SEC-05`).

### Data & lifecycle (P0/P1)

**Activation never provisioned anything.**
*Root cause:* the tables, the protected directory and the cron events were created from
the `plugins_loaded` bootstrap, which WordPress has already fired when it runs the
activation hook, so a normal activation created no schema, no folder and no schedules.
*Reproduced:* baseline `LIFE-01` (three tables missing, no directory, no crons) and
`LIFE-04` (zero cleanup events).
*Fixed:* activation is handled by a static `on_activate()` that runs on the real
activation request; the upgrade routine is additive (`dbDelta`) and repeatable.

**Deactivation destroyed data.**
*Root cause:* `deactivate()` dropped the plugin tables and removed the protected
directory, deleting receipt files and their metadata together with the schema.
*Reproduced:* baseline `LIFE-02` — all three tables dropped, the directory removed and
the receipt files gone.
*Fixed:* deactivation only unschedules the cron events. Nothing is deleted.
Data removal moved to `uninstall.php`, and only when the shop owner opted in
(`wc_receipt_delete_data_on_uninstall = yes`); otherwise uninstall changes nothing.

### Order & receipt flow (P1)

**Approval could charge a card more than once.**
*Root cause:* the approve handler had no server-side precondition and no record of the
receipt instance it had already decided on, so a repeated click, a retried request or two
concurrent administrators each charged the card again.
*Reproduced:* baseline `FLOW-04` — a second approval added another 100,000 to the card and
another log row. Stronger: baseline `FLOW-10` fired four simultaneous approve requests and
the card was charged **four times** (`parallel=4 success=4 charged=400,000 tx=4 log=4`).
*Fixed:* one transition engine (`apply_transition()`) with server-side preconditions and an
atomic idempotency claim per `(order, receipt instance, transition)`. The repeated click is
refused as `duplicate`, and under four parallel requests exactly one wins
(`FLOW-10`: `parallel=4 success=1 charged=100,000 tx=1 log=1`).

**An order with no receipt could be approved.**
*Root cause:* the approve path never verified that a receipt existed and was in review.
*Reproduced:* baseline `FLOW-05` — an empty order ended up `approved`/`processing`.
*Fixed:* `no_receipt` and `bad_state` guards before any side effect (`FLOW-05`).

**Bulk review did not follow the single-order rules.** *(P1)*
Bulk approve/reject now iterate the same engine one order at a time and report
done/skipped/failed counters, so an ineligible order is never mutated (`FLOW-08`).

**Approval reported success without checking the outcome.**
*Root cause:* the previous approve path mutated meta and status and always reported
success, with no verification of the result. Where the status transition failed (for
example a conflicting third-party hook), the shop owner saw a success message while the
order stayed unpaid.
*Verified behaviour:* the payment cycle itself did work in 7.1.0 (baseline `FLOW-03`
confirms `paid=yes` after approval), so this is a robustness fix, not a regression repair.
*Fixed:* approval writes receipt meta first, then calls `$order->payment_complete()`, and
uses `order_reached_paid_state()` to confirm the paid state. A failure now returns
`status_failed` with a message and a log entry instead of a silent success (`FLOW-03`).

**Expiry depended on the customer visiting the page.**
*Root cause:* the deadline was evaluated while rendering the order page, and nothing ran
on schedule, so an abandoned order stayed `pending` forever.
*Reproduced:* baseline `FLOW-09` — after running the scheduled hook the overdue order was
still `pending`.
*Fixed:* an hourly WP-Cron job enforces the deadline with one time base
(`deadline_for()`), independent of page views, and leaves fresh orders alone
(`FLOW-09`, including a negative control).

### Bank cards, files, SMS (P1/P2)

* **Unusable cards were offered to customers.** `get_active_card()` returned expired
  cards (baseline `CARD-01`) and ignored the amount limit when the transaction limit was
  disabled (baseline `CARD-03`). The selector now checks active/expiry/amount/count
  independently and returns no card when none qualifies.
* **Retention deleted the wrong files.** Cleanup counted age from the order date and had
  no notion of approved evidence, so approved receipts could be removed (baseline
  `FILE-01`). Retention is now counted from the **upload time**, approved receipts are
  kept by default, batches are paged (50 orders per pass, max 20 passes) and
  file/metadata deletion stays consistent (`FILE-01`, `FILE-03`).
* **Receipt paths were derived from URLs.** A changed home/site URL broke lookup
  (baseline `FILE-02`). Paths are now resolved inside the trusted uploads folder with a
  real-path containment check, immune to home/site URL changes, subdirectory installs and
  CDN rewrites (`FILE-02`).
* **Legacy receipts disappeared after upgrade.** Orders written by releases before 7.1.1
  only carry `_wc_receipt_url`; gallery, retention and viewer now accept both storage keys
  (`FILE-04`).
* **SMS reported successes that never happened.** A provider error body with HTTP 200 was
  stored as `sent` (baseline `SMS-02`), and a transition that repeated could send twice
  (baseline `SMS-03`). Sends now check the transport *and* the provider payload, record
  `sent`/`failed`/`unknown`, are deduplicated per transition, and never affect the order
  status. Credentials are never rendered back into the settings page.
* **File name in order meta.** The upload handler stored an absolute server path in
  `_wc_receipt_file`; it now stores the base file name only.

### Performance (P2)

Gallery, reports and bulk review are paged (24 / 50 per page) instead of loading every
order, cleanup, reminders and expiry process bounded batches, and the CSV export is
paged and capped. Measured on 120 orders with receipts (`PERF-01`…`PERF-03`).

### Compatibility (P2)

* Declared floor/ceiling in the plugin header: PHP 7.4+, WP 6.5+, WooCommerce 7.1+,
  tested up to WooCommerce 11.2 (no PHP 8-only syntax in the codebase — `COMP-02`).
* No fatal error when WooCommerce is missing (admin notice only — `COMP-01`).
* HPOS support through the order APIs; classic checkout and block-based order
  confirmation both render the upload panel (shortcode `[wc_bank_receipt]` available).
* Documentation, changelog, license and troubleshooting guide added to the package.

### Data preservation guarantees

* Existing orders, cards, logs, settings and receipt files are never rewritten or removed
  by activation, deactivation or migration.
* The migration is additive and repeatable (`dbDelta` + a single DB-version option), so a
  repeated upgrade does not duplicate tables, columns or cron events (`LIFE-03`,
  `LIFE-04`).
* The plugin sends no data to the author: no telemetry, no hidden remote calls.

---

## 7.1.0

Previous release: receipt upload with deadline, single/bulk review, card rotation,
gallery, reports, CSV export and SMS. Security, lifecycle and order-flow defects fixed in
7.2.0 (see above); the order-alert system was added in 7.3.0.
