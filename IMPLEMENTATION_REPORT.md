# Implementation report — 7.6.0

Scope: the mission's five areas on the real repository — admin alerts (origin + sound), the
status arrow, receipt image display for both roles, the timer/card/receipt state model, the SMS
subsystem, and the appearance editor. Every item below is implemented in
`wc-bank-receipt/wc-bank-receipt.php` (plus two new asset files and one JS file) and is backed by
a check in `tests/acceptance.php`; the run itself is recorded in
[`IMPLEMENTATION_TEST_REPORT.md`](IMPLEMENTATION_TEST_REPORT.md).

Baseline before this work: 7.5.1, 91 acceptance checks, all passing. After: 7.6.0, **123 checks,
0 failures, 0 skips** (18 groups, including the three new ones).

---

## 1. Summary of changes

| Area | Before | After |
|---|---|---|
| Admin alert queue | every status change queued an alert, including the manager's own clicks | each event carries an `origin`; manager-caused changes are stored but withheld from the popup |
| Alert sound | one beep per delivery | one repeating alarm per tab, stops per event on acknowledgement, never replays an acknowledged event |
| Transition display | from/to labels in one line, direction implied by layout | three explicit nodes (`from`, `→`, `to`) inside an LTR-isolated value, plus an `aria-label` |
| Receipt display | per-order overlay on the order screen; customer saw a small inline image | one shared lightbox component, one authorized streaming endpoint for both roles, PDF typed as a document |
| Receipt state | conditions spread over the renderer | `receipt_phase()` returns one of four phases; card/timer/form/notices all read it |
| SMS | screens disabled (`SMS_UI_ENABLED = false`) | complete opt-in pipeline: providers, per-event templates, variables, preview, test send, bounded retries, redacted log, history page |
| Appearance | token list + presets | click-to-edit element map (10 elements) wired to the same tokens; lightbox tokens added |

---

## 2. Architecture: before and after

**Before.** One large class held everything; a status hook called `record_status_change_event()`
unconditionally, the renderer decided card/timer/form inline, the order screen printed its own
overlay, the customer saw an inline thumbnail, and the SMS layer existed but its UI was not
registered (`SMS_UI_ENABLED = false`, SMS-01 asserted "no provider call ever").

**After.** Same class (no rewrite, no second bootstrap), but with one owner per concern:

| Concern | Single source of truth |
|---|---|
| Order transitions | `apply_transition()` (unchanged) + `with_origin()` around its own status writes |
| Event origin | `classify_event_origin()` → `origin_begin()/origin_end()` |
| Alert queue | `record_alert_event()` (one insert path, dedupe key on the row) |
| Receipt rendering | `receipt_panel_html()` + `receipt_phase()` |
| Receipt file display | `receipt_view_url()` + `secure_receipt_view()` + `lightbox.js` |
| SMS decision | `sms_send_event()` |
| SMS delivery | `sms_deliver()` → `sms_attempt()` → `sms_schedule_retry()` |
| SMS variables | `sms_variable_map()` / `sms_render_map()` |
| Appearance | `WCR_Design_Tokens` + `appearance_element_map()` |

Everything that used to be a second copy of a rule now calls the one function: the bulk review
goes through the same transition engine, the alert popup and the customer panel use the same
lightbox, and preview/test/templates share the same resolver.

---

## 3. Event origins and the alert filter

**Classification** (`classify_event_origin()`), in this order:

1. explicit `$hint` from the caller (receipt upload → `customer_receipt_upload`, approve/reject →
   `admin_receipt_review`);
2. the per-request context opened by `with_origin()` (used by `apply_transition()` for every
   internal status write);
3. unattended execution: `wp_doing_cron()`, `WP_CLI`, or a system admin-ajax action with no user
   → `system_automation`;
4. a manager-driven request (`current_user_can( manage_woocommerce )` **and** wp-admin / admin-ajax /
   admin REST) → `admin_manual_action`;
5. `get_created_via()`: `checkout` / `store-api` → `customer_checkout`; `rest-api` without a user
   → `payment_gateway`;
6. otherwise `unknown`.

`with_origin()` is a scoped wrapper: it stores the previous context, sets its own, runs the
callback in `try/finally`, and restores the previous value even when the callback throws — a
nested transition cannot leak its origin to the rest of the request. Verified by ORIGIN-01..07.

**Suppression** (`origin_is_suppressed()`): true for `admin_manual_action`,
`admin_receipt_review`, `plugin_internal`; false for everything else including `unknown`. The
reasoning is that a lost event is worse than a redundant one, so the list is an explicit
allow-list and unknown origins are queued (ORIGIN-05).

Suppressed rows are still **written** and still visible in the database (the audit trail); the
poll query filters them (`… AND suppressed = 0`), so the popup and the sound never see them
(ORIGIN-04).

**SMS is not affected.** The filter exists only on the alert path. ORIGIN-07 proves a manual admin
status change is suppressed on the dashboard *and* still sends exactly one SMS when the SMS
settings say so — the SMS layer never reads `suppressed` or the origin.

**Where the old approach would have failed.** `is_admin()` is true for admin-ajax, so a customer
upload would have been classified as an admin action; a cron status change would have been
attributed to whoever happened to be logged in. Neither is used.

---

## 4. Order and receipt state model

`receipt_phase( $state, $has_receipt )` returns exactly one of:

| Phase | Condition | Card | Countdown | Form | Viewer |
|---|---|---|---|---|---|
| `awaiting_payment` | no receipt / empty state | yes | yes | yes | no |
| `under_review` | `pending_review` + file | no | no | no | yes |
| `rejected` | `rejected` + file | yes (same card) | yes, from the rejection time | yes | yes, previous file |
| `approved` | `approved` | no | no | no | yes |

* The renderer (`receipt_panel_html()`) reads the phase once and switches the card block, the
  countdown, the drop zone and the notices on it, so the combinations the mission forbids (a
  timer while under review, a card while approved) are not reachable by construction.
* The countdown is rendered from `remaining_seconds()` — the server number — into
  `data-remaining`/`data-total`; the browser script is a display, not the authority.
* Rejection restarts `_wc_receipt_deadline` from `_wc_receipt_rejected_time`, so the customer
  never sees an already-expired timer (UI-03 asserts remaining ≈ the full window).
* The assigned card is reused on rejection (`_wc_receipt_assigned_card_id`), not re-picked.
* Every upload is re-checked against the deadline on the server (`expired` guard), independent of
  the browser.
* Upload writes the file + state first and only then moves the order to `on-hold`; if the status
  move fails the receipt is kept (it is the customer's payment proof), the transition returns
  `status_failed` with a message, and `upload_status_failed` is logged with the actual status.
  Same pattern for approve/reject/expire. No silent inconsistency, no lost receipt.
* Approval remains idempotent: the decision claim in `apply_transition()` refuses a second
  approval before the card charge (7.5.1's fix, unchanged).

---

## 5. Receipt image display

**The cause of the old problem** was not CSS: the files live in a protected uploads folder
(`.htaccess` deny), so any `<img src="…/bank-receipts/file.png">` was answered with 403. The
fix is a streaming endpoint, not a public folder.

* `receipt_view_url( $order )` mints `?wc_receipt_view=<order>&nonce=view_receipt_<order>` for a
  manager or the order's owner; for a guest it returns `''`.
* `secure_receipt_view()` verifies the order, the **order-scoped** nonce, then authorization
  (manager capability or `order->get_customer_id() === current_user_id()`), then resolves the path
  with real-path containment. Response: detected MIME type, `Content-Disposition: inline` for the
  image/PDF allow-list (`attachment` + `application/octet-stream` otherwise), `Content-Length`,
  `nosniff`, a restrictive CSP, `no-referrer`, and `nocache_headers()` (private, no-store).
* A guest gets a `data:` URI preview of their own upload and no URL at all (LB-06); the protected
  path never appears in the HTML and a direct file request still answers 403 (LB-09).
* `receipt_lightbox_type()` types the file: `image` or `pdf` (LB-07). The trigger carries
  `data-wcr-lb="image|pdf"`; a PDF is opened as a document, never fed to an `<img>`.
* `assets/js/lightbox.js` + `assets/css/lightbox.css` are the one shared component: fixed overlay,
  dark backdrop, contain-fit image bounded to `94vw/92vh`, close button, backdrop click and
  Escape to close, click on the image does nothing, focus restore, scroll lock, body scroll
  preserved. Nodes are built with `createElement`/`textContent` — no `innerHTML`, so a crafted
  file name cannot become markup.
* The old per-order overlay (`wor-lb-*`, duplicate ids, no keyboard support) is gone; the admin
  order screen and the customer panel both use the shared trigger (LB-01, LB-02, LB-05).
* Missing file or denied access answers a controlled 403 page/message rather than a broken image.

---

## 6. SMS

**Structure.** Options → `sms_events()` (event table: label, audience, template option, enable
option, default text) → `sms_template_for()` / `sms_event_enabled()` → `sms_variable_map()` →
`sms_send_event()` → `sms_deliver()` → `sms_attempt()` → `sms_schedule_retry()` /
`wc_receipt_sms_retry_hook`.

| Event | Audience | Default | Trigger |
|---|---|---|---|
| `new_order` | customer | off | `woocommerce_new_order` |
| `status_change` | customer | off | `woocommerce_order_status_changed`, per-status switches |
| `receipt_uploaded` | admin | on | customer upload |
| `receipt_approved` | customer | on | approval decision |
| `receipt_rejected` | customer | on | rejection decision (reason included) |
| `reminder` | customer | on | reminder cron |
| `test` | typed number | — | explicit click only |

Providers: `kavenegar`, `melli` (MelliPayamak), `custom` (own URL). Each has its own credential
options, sender line and response parser; the credentials are masked in the UI
(`masked_secret()`).

**Variables** (one allow-listed resolver, no `eval`, no request data): `{order_id}`,
`{order_number}`, `{old_status}`, `{new_status}`, `{order_status}`, `{customer_name}`,
`{billing_first_name}`, `{billing_last_name}`, `{billing_phone}`, `{order_total}`, `{currency}`,
`{payment_method}`, `{site_name}`, `{order_date}`, `{receipt_status}`,
`{receipt_reject_reason}`, `{order_url}`. An unknown placeholder is left as typed and reported in
the preview; a known placeholder without data becomes an empty string. The preview uses sample
data and sends nothing (SMS-06); the test send is a separate explicit action (SMS-11).

**Duplicate prevention.** A dedupe key is computed from the event, the order and the transition
(for status changes: old→new) and stored with the row; the insert is guarded so a repeated hook,
a retried request or a double click cannot create a second message. The status hook and the
receipt decisions go through the same function, so one approval cannot produce "approved" twice
(SMS-03, SMS-10).

**Delivery truth.** `evaluate_sms_response()` reads the HTTP status *and* the provider payload;
Kavenegar returning HTTP 200 with `{"return":{"status":411}}` is recorded as **failed**
(SMS-12 uses exactly that shape). A timeout records `failed` with `next_attempt` set (SMS-07),
never `sent`.

**Retry.** `SMS_MAX_ATTEMPTS = 3` in total; the delay is `2^(attempts+1) * 60` seconds (2, 4, 8
minutes), scheduled through WP-Cron (`wc_receipt_sms_retry_hook`), and only for retryable
failures. `sms_retry_due()` is safe to call repeatedly and stops at the maximum (SMS-08).

**Log.** `wc_bank_receipt_sms_log` + `attempts`, `http_code`, `provider_id`, `next_attempt`, and
the redacted answer. `sms_redact()` removes the configured credentials and
`apikey|token|password|Authorization` fragments before storage; request headers are never stored.
The history page paginates, filters by state, links to the order and shows a safe failure summary
(SMS-09). Credentials are never rendered back into the settings HTML.

---

## 7. Appearance and the interactive editor

`appearance_element_map()` maps ten elements (`card`, `timer`, `status`, `button`, `view-button`,
`dropzone`, `notice`, `upload`, `form`, `lightbox`) to the design tokens that style them. The
appearance screen renders the preview with `data-wcr-el` on each region and a panel with one
control per token of the selected element; changes apply live to the preview and are saved through
the existing `WCR_Design_Tokens::sanitize()` (still an allow-list of tokens, still typed values,
still no free-form CSS). Reset works per element and per screen.

Three lightbox tokens (`--wcrf-lb-backdrop`, `--wcrf-lb-btn-bg`, `--wcrf-lb-btn-fg`) were added to
the schema, to `frontend.css` defaults and to `lightbox.css`, so the shared viewer is themed from
the same screen (UI-07).

The editor does not require Elementor and does not touch any global selector: the panel CSS is
scoped to `.wcrf-panel`, the preview is sample data only, and the widget keeps reading the same
tokens (UI-06, UI-08, STYLE-*, TPL-*).

---

## 8. Database changes (migration)

`maybe_upgrade_db()` compares `DB_VERSION` (`7.6.0`) and runs additive migrations only:

| Table | Change |
|---|---|
| `{prefix}wc_bank_receipt_events` | `origin varchar(32) NOT NULL DEFAULT 'unknown'`, `suppressed tinyint(1) NOT NULL DEFAULT 0` |
| `{prefix}wc_bank_receipt_sms_log` | `attempts int unsigned DEFAULT 0`, `http_code int DEFAULT 0`, `provider_id varchar(100)`, `next_attempt datetime NULL`, `response text` (widened) |

`dbDelta()` is used, so an existing install keeps its rows and gets the columns added; existing
events read as `origin = 'unknown'`, `suppressed = 0` (queued, never silently hidden). Verified on
a copy of the live test database: 158 existing rows preserved, columns added, no table recreated.
No option is renamed or deleted; `SMS_UI_ENABLED` was removed, the master switch
(`wc_sms_enabled`) is now the only gate.

---

## 9. Known limitations and risks

1. **Autoplay cannot be guaranteed.** Chrome/Safari/Firefox may refuse audio until the manager
   interacts with the page. The plugin shows the popup, says so, and unlocks on the first gesture;
   it does not attempt to bypass the policy. A manager who never clicks will see the alert but
   hear nothing.
2. **Cross-tab sound suppression depends on `localStorage`.** Where storage is blocked (some
   private modes), each tab rings its own alarm. That is deliberate: a beep too many beats a
   silent shop.
3. **Origin classification is best effort for third-party code.** A plugin that changes a status
   inside an admin request without going through this plugin is classified by the request context
   (`admin_manual_action`). The `wcr_event_origin` filter is provided for integrations that know
   better.
4. **`wp_doing_ajax()` without a user** is treated as system only when the action is a system one;
   an unattributable ajax change stays `unknown` (and is queued).
5. **SMS delivery is asynchronous only for retries.** The first attempt happens in the same
   request as the event (so the log row is immediate); a slow provider therefore adds its latency
   to that request. Retries are cron-driven.
6. **WP-Cron must run.** With `DISABLE_WP_CRON` and no system cron, retries wait for traffic.
7. **Provider parsers cover the documented answer shapes** of Kavenegar and MelliPayamak at the
   time of writing; a provider that changes its payload is recorded as `failed` (never silently
   as `sent`), and the raw answer is kept (redacted) in the log for diagnosis.
8. **The acceptance harness has no browser.** The lightbox behaviour is verified statically
   (`LB-08` reads the shipped JS/CSS) plus over HTTP for the endpoints; visual rendering on a real
   mobile viewport was not measured automatically.
9. **One Elementor / WooCommerce version** was available in the environment (WC 11.2.1, WP 7.1.3,
   PHP 8.2.12). HPOS is enabled there and the flows pass; legacy post storage was not re-run in
   this round.
10. **The `unknown` origin is queued by design.** A site whose integrations change orders
    unattended will see those events; that is the intended conservative behaviour, not a bug.
