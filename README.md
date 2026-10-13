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

> A single-file WooCommerce plugin (2,516 lines, v7.1.0) that turns manual bank-transfer verification into a complete workflow: customers upload their receipt on the order page with a countdown, the shop reviews it in one click (or in bulk), rotating destination bank cards never blow past their limits — and every state change is announced by SMS, logged, and audited. The repository name is a pun on *receipt*.

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
4. Customers and the admin are **notified by SMS**, everything is **logged**, and stale files clean themselves up.

## 2. What is in this repository

| Path | What it is |
|---|---|
| [`test-receipt.php`](test-receipt.php) | The entire plugin — one class `WC_Bank_Receipt_Pro`, 2,516 lines: admin UI (RTL Persian), frontend flow, card rotation, SMS engine, cron jobs, security layer |
| [`README.md`](README.md) | This document · [فارسی](README.fa.md) |
| [`.gitattributes`](.gitattributes) | LF normalization |

> **Install note:** the main file ships as `test-receipt.php`; on installation put it in `wp-content/plugins/wc-bank-receipt/` and rename it to `wc-bank-receipt.php` (any name works as long as the folder + file define the plugin).

## 3. Features

### Receipt lifecycle (the core loop)
- The upload form appears on **`order-received` (thank-you)** and **`view-order`** pages, only for orders paid via `bacs`, and only for the owner (logged-in customer or valid `?key=` order key).
- **Countdown timer** to the deadline (`wc_receipt_expire_hours`, default 2 h, 0.5 h steps) with an expired state that blocks upload.
- Upload validation: configurable **MIME allow-list** (JPEG/PNG/PDF), max file size (default 2 MB), extension double-check, randomized `md5(uniqid)` filename — stored in `uploads/bank-receipts/` protected by a generated **`.htaccess` (Deny from all)**.
- Upload → order goes to **`on-hold`**, receipt state `pending_review`, the assigned destination card is recorded, a log row is written and the admin gets an SMS.
- **Approve** (AJAX) → state `approved`, order → **`processing`**, the card's usage counters are charged once.
- **Reject** (AJAX) → state `rejected` + reason (displayed to the customer) + timestamp, order → **`pending`** so the customer can re-upload after fixing the payment.

### Review and reporting tools
- Review box inside the WooCommerce order screen (state badges, lightbox preview, approve/reject with reason) — plus a receipt-state **column in the order list**, wired for **both classic and HPOS** list tables.
- **Bulk review page**: approve/reject many orders at once with one shared reason.
- **Gallery** of uploaded receipts (thumbnails, PDF badges, lightbox, time-left).
- **Reports** page with stats and **CSV export**; every action (uploaded/approved/rejected) lands in the `receipt_log` audit table.

### Bank-card rotation
- Card directory with bank name, card number, sheba, holder — **drag-to-reorder priority** (jQuery UI sortable → AJAX with nonce + capability check).
- Per-card, independently toggleable limits: **total amount**, **transaction count**, **expiry date**; live used/remaining columns and reason-tagged inactive badges.
- `get_active_card()` walks cards in priority order, skipping exhausted or expired ones; the assigned card id is stored on the order and charged **only at approval** (bulk included).

### SMS engine
- Three providers behind one interface: **Kavenegar**, **Melli Payamak**, and a **custom endpoint** (optional Bearer token); 15 s timeouts, `WP_Error` handled.
- Per-event **templates and senders** for: approved, rejected, payment reminder, cancelled, admin-on-upload; plus an admin alert number.
- **Hourly reminder cron**: when a pending order's remaining time drops below `reminder_before_hours` (default 1 h), the customer gets one reminder — guarded by a `reminder_sent` meta flag so it never duplicates.
- Every send is written to `sms_log` with recipient, message, status and the provider response (truncated to 500 chars).

### Admin quality-of-life
- **New-order popup with sound** in wp-admin (poll AJAX + dismiss, persisted in an option).
- Hourly **cleanup cron** (daily/weekly/monthly/custom days) deleting expired receipt files.
- Settings page (deadline, size, mimes, cleanup), **full reset button** (nonce-guarded reinstall of tables/options), RTL Persian admin UI with its own CSS design system.

## 4. Architecture

```
Customer (order-received / view-order)          Shop (wp-admin)
┌──────────────────────────────────┐   ┌────────────────────────────────────┐
│ countdown · bank card + copy     │   │ Order screen: review box + lightbox│
│ drop-zone upload → handle_upload │   │ Bulk review · Gallery · Reports    │
└───────────────┬──────────────────┘   │ Cards (drag-order) · SMS panel     │
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
        receipt_log      cards (usage++)      sms_log ◄──── Kavenegar / Melli / custom
        (audit trail)    (charged once)       (all sends)     (templates per event)

WP-Cron (hourly): wc_receipt_cleanup_hook → delete expired files
                  wc_receipt_reminder_hook → one SMS before deadline
```

**State machine:** `pending_review → approved | rejected`; meta is **always saved before** `update_status()` so the SMS hook reads the correct state (see §6).

## 5. Technology stack

| Technology | Role in the project |
|---|---|
| **WordPress plugin APIs** | Menus, settings API, nonces/capabilities, rewrite endpoint, WP-Cron, text domain i18n (Persian RTL) |
| **WooCommerce** | `bacs` gateway hooks, thank-you/view-order rendering, order meta + status transitions, admin order screen, **HPOS and classic list tables** |
| **MySQL (dbDelta)** | `{prefix}wc_bank_receipt_cards`, `_receipt_log`, `_sms_log` with a versioned upgrade path (`wc_bank_receipt_db_version` → 7.1.0) |
| **`wp_remote_post`** | Outbound SMS to Kavenegar / Melli Payamak / custom HTTP endpoint |
| **jQuery UI sortable** | Drag-to-reorder card priority (persisted via AJAX) |
| **Inline CSS (RTL)** | Self-contained admin/frontend design system — no build step, no external assets |

## 6. Key engineering decisions

1. **FIX #1 — context-gated upload filters.** `upload_dir`, `upload_mimes` and `wp_handle_upload_prefilter` are global filters; here they only apply while `$receipt_upload_context` is true, so receipt rules (destination folder, MIME allow-list, size checks) can never break other plugins' uploads.
2. **FIX #2 — meta before status.** `approved/rejected` meta is saved **before** `update_status()` in single and bulk paths, so the `woocommerce_order_status_changed` SMS hook always sees the right state and the right card.
3. **Charging cards only on approval.** The card is *assigned* at upload (recorded on the order) but its amount/transaction counters are incremented only when a human approves — rejected receipts never consume a card's limit.
4. **A deadline with memory.** Rejection resets `reminder_sent` and stamps `rejected_time`, so the countdown (and the one-shot reminder) restarts from the rejection, not the original order.
5. **Two independent protection layers for receipts.** The upload directory is denied by `.htaccess`, *and* viewing a receipt goes through a per-order nonce endpoint (`wc_receipt_view`) — a leaked URL is not enough.
6. **HPOS-forward compatibility.** Order columns and custom order data are registered for both the classic post-type list table and WooCommerce's HPOS list tables, with `woocommerce_can_order_be_changed_to` used to allow the reject transition (`on-hold → pending`).
7. **Clean uninstall story.** Deactivation drops the plugin tables, deletes its options, removes the uploads directory and clears both cron hooks; activation re-creates everything, and a settings-page reset does the same on demand.

## 7. Install and configure

**Requirements:** WordPress with **WooCommerce** and the built-in **BACS** transfer gateway · PHP 7+ (null-coalescing is the only modern syntax used) · MySQL 5.7+/MariaDB 10.3+. The code declares no version floor itself; the HPOS hooks are additive and fall back to the classic order list automatically.

```bash
# put the plugin in place (rename the main file)
mkdir -p wp-content/plugins/wc-bank-receipt
cp test-receipt.php wp-content/plugins/wc-bank-receipt/wc-bank-receipt.php
```

1. Activate **WC Bank Receipt Ultimate Control** — the three tables, the protected upload directory and both hourly crons are created automatically.
2. **تنظیمات فیش بانکی** → deadline, max upload size, allowed formats, cleanup cadence.
3. **پیام‌رسانی** → pick a provider (Kavenegar / Melli / custom), add credentials, adjust the five event templates + admin phone.
4. **کارت‌های بانکی** → add destination cards, set per-card limits, drag them into priority order.
5. Take an order through the BACS flow: the customer uploads on the thank-you page → **بررسی گروهی** or the order screen to approve/reject.

## 8. Testing and verification

> **Honest verification status:**
> - This repository ships **no automated tests and no CI** — the plugin's correctness rests on its defensive structure (nonces on every mutation, capability checks, ownership checks on upload/view, `WP_Error` paths) and on manual verification against a live store.
> - During this documentation pass the full source (2,516 lines) was read end-to-end and every claim above was checked against it; no build step exists to run (plain PHP + inline CSS/JS).
> - Recommended next step (roadmap): a PHPUnit suite with the WordPress test harness covering the state machine, card rotation edge cases (all cards exhausted) and the SMS log paths.

## 9. Known limitations and roadmap

- **Single-file monolith** — one class holds admin UI, frontend, SMS and cron; splitting into `includes/` modules is the first refactoring step.
- **No automated tests / CI yet** (see §8).
- **No `.pot` translation catalog** despite the text-domain wiring — Persian strings are inline defaults.
- **All-cards-exhausted edge**: `get_active_card()` falls back to the first card when every card is over its limits, so limits can be exceeded by one final upload; a hard stop is more correct.
- **SMS credentials are plain options** in the database (standard for WP plugins, but a secrets abstraction would be better).
- **`test-receipt.php` filename** is confusing in production — see the install note in §2.

### Roadmap (not implemented yet)

- PHPUnit + CI workflow; `.pot` catalog and English language pack.
- Split the class into modules (receipts / cards / SMS / cron).
- Hard-stop card selection when all limits are hit; optional receipt image moderation queue.

## 10. Documentation

| Doc | Contents |
|---|---|
| [README.fa.md](README.fa.md) | The Persian version of this document |
| [`test-receipt.php`](test-receipt.php) | The plugin is its own reference: every hook, option key and table is documented inline |

---

<div align="center">

**More from this portfolio:**
[hyper_barcode](https://github.com/DeathGunSR/hyper_barcode) ·
[HolooDB_Sync](https://github.com/DeathGunSR/HolooDB_Sync) ·
[Hyper_Event](https://github.com/DeathGunSR/Hyper_Event)

Maintained by [@DeathGunSR](https://github.com/DeathGunSR)

</div>
