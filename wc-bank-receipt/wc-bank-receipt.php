<?php
/**
 * Plugin Name:       WC Bank Receipt Ultimate Control
 * Plugin URI:        https://github.com/DeathGunSR/WP-Recipe
 * Description:       مدیریت فیش بانکی کارت‌به‌کارت ووکامرس: بارگذاری فیش با مهلت، تأیید/رد تکی و گروهی، چرخش کارت‌های بانکی، گالری، گزارش، لاگ، پیامک و شخصی‌سازی ظاهر فرم.
 * Version:           7.5.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Alireza
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-bank-receipt
 * Domain Path:       /languages
 * WC requires at least: 7.1
 * WC tested up to:   11.2
 *
 * @package WC_Bank_Receipt
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * The appearance layer (design tokens, presets, validation, resolver) is a pure PHP
 * class with no WordPress bootstrap dependency, so it is loaded here — at file scope,
 * before anything can ask for a token. It is what makes the panel's look configurable
 * without a second renderer or a second stylesheet.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/design-tokens.php';

class WC_Bank_Receipt_Pro {

    const META_URL           = '_wc_receipt_url';          // legacy public URL (kept for back-compat reads)
    const META_FILE          = '_wc_receipt_file';         // file name inside the protected receipts dir
    const META_STATE         = '_wc_receipt_state';
    const META_REJECTED_TIME = '_wc_receipt_rejected_time';
    const META_REJECT_REASON = '_wc_receipt_reject_reason';
    const META_ASSIGNED_CARD = '_wc_receipt_assigned_card_id';
    const META_REMINDER_SENT = '_wc_receipt_reminder_sent';
    const META_DEADLINE      = '_wc_receipt_deadline';     // absolute UTC timestamp of the upload deadline
    const META_UPLOADED      = '_wc_receipt_uploaded_time'; // absolute UTC timestamp of the last upload
    const META_HISTORY       = '_wc_receipt_history';      // audit trail of this order's receipt actions

    const GATEWAY_ID         = 'bacs';
    const UPLOAD_DIR         = 'bank-receipts';
    const DB_PREFIX          = 'wc_bank_receipt_';
    const DB_VERSION         = '7.5.1';

    /** Release version — also the cache-busting version of every enqueued asset. */
    const VERSION            = '7.5.1';

    /** The public shortcode that renders the receipt panel anywhere. */
    const SHORTCODE          = 'wc_bank_receipt';

    /*
     * Front-end assets are real files (`assets/`), registered through the standard
     * enqueue API so a site can deregister, dequeue, version or move them like any
     * other plugin asset:
     *   - `wcr-frontend` (css) — every selector is scoped under `.wcrf-panel`;
     *   - `wcr-frontend` (js)  — initialises each panel instance independently.
     * Nothing is printed inline from the renderer: one source of truth for markup,
     * one for behaviour, one for style (see docs/compatibility.md).
     */
    const ASSET_CSS          = 'assets/css/frontend.css';
    const ASSET_JS           = 'assets/js/frontend.js';

    /*
     * The «ظاهر و استایل» screen reuses the very same panel stylesheet for its live
     * preview, and adds one admin-side script that only ever touches that preview —
     * it is never loaded on a front-end page.
     */
    const ASSET_ADMIN_JS     = 'assets/js/admin-appearance.js';

    /*
     * SMS is disabled for this release: the settings UI is not registered and no
     * message is sent. The whole delivery layer (providers, templates, log, tables
     * and stored credentials) is kept intact so the next phase only has to flip
     * this flag and the `wc_receipt_sms_enabled` option back on.
     */
    const SMS_UI_ENABLED     = false;

    /** Alert events are pruned to this many rows / this many days. */
    const ALERT_KEEP_ROWS    = 1000;
    const ALERT_KEEP_DAYS    = 7;
    const ALERT_BATCH_MAX    = 20;
    /** Two identical events inside this many seconds are one event, not two. */
    const ALERT_DEDUPE_WINDOW = 5;
    const CAP_MANAGE         = 'manage_woocommerce';       // single capability used by every mutation
    const NONCE_ADMIN        = 'receipt_admin_nonce';
    const SMS_PROVIDERS      = array(
        'kavenegar' => 'کاوه‌نگار',
        'melli'     => 'ملی پیامک',
        'custom'    => 'سفارشی (درگاه دلخواه)',
    );

    private static $instance = null;

    /**
     * Orders whose panel the AUTOMATIC hook already printed during this request.
     *
     * The automatic path prints an order once (a template may call the hook twice),
     * and the shortcode/widget consult this list so that a page which both reaches the
     * WooCommerce hook and carries `[wc_bank_receipt]` still shows a single panel.
     *
     * Two shortcodes placed deliberately on one page are NOT folded together: an author
     * who puts the panel in two columns gets two panels (each a self-contained instance).
     */
    private static $auto_rendered_orders = array();

    /** WooCommerce notices are printed inside the panel at most once per request. */
    private static $frontend_notices_printed = false;

    /**
     * Orders created during *this* request.
     *
     * WooCommerce fires `woocommerce_new_order` and then a status transition for the
     * same freshly placed order (pending → on-hold/processing). That transition is not
     * a second event for the shop owner, so it is not queued separately — while every
     * later status change of that order still is.
     */
    private static $alert_created_orders = array();

    /**
     * Rows written for orders created during this request, keyed by order id.
     *
     * WooCommerce fires `woocommerce_new_order` *before* the checkout has added items,
     * addresses and totals, so the snapshot is refreshed once the order is complete
     * (see refresh_new_order_snapshots()).
     */
    private static $alert_new_order_rows = array();

    /**
     * FIX #1 – Context flag.
     * Upload filters (upload_dir, upload_mimes, wp_handle_upload_prefilter) only apply
     * when THIS plugin is actively processing a receipt upload.
     * This prevents interference with other plugins' file upload operations.
     */
    private $receipt_upload_context = false;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        // WordPress runs with WooCommerce absent just as happily: register the
        // admin notice and stop, so no fatal error can escape from this plugin.
        if ( ! $this->woocommerce_is_available() ) {
            add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
            return;
        }

        add_action( 'init',                   array( $this, 'load_textdomain'        ) );
        add_action( 'admin_menu',             array( $this, 'admin_menu'             ) );
        add_action( 'admin_init',             array( $this, 'register_settings'      ) );
        add_action( 'init',                   array( $this, 'maybe_upgrade_db'       ), 20 );
        add_action( 'admin_enqueue_scripts',  array( $this, 'admin_enqueue_assets'   ) );
        add_action( 'wp_enqueue_scripts',     array( $this, 'frontend_enqueue_assets') );

        add_action( 'woocommerce_admin_order_data_after_billing_address',
                    array( $this, 'admin_order_section' ) );

        add_action( 'wp_ajax_wc_receipt_approve',        array( $this, 'ajax_approve'       ) );
        add_action( 'wp_ajax_wc_receipt_reject',         array( $this, 'ajax_reject'        ) );
        add_action( 'wp_ajax_wc_receipt_reorder_cards',  array( $this, 'ajax_reorder_cards' ) );

        // Columns: support both classic CPT list and HPOS list
        add_filter( 'manage_edit-shop_order_columns',                  array( $this, 'add_order_column'    ), 20    );
        add_action( 'manage_shop_order_posts_custom_column',           array( $this, 'render_order_column' ), 20, 2 );
        add_filter( 'woocommerce_shop_order_list_table_columns',       array( $this, 'add_order_column'    )        );
        add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_order_column' ), 10, 2 );

        add_action( 'wc_receipt_cleanup_hook',  array( $this, 'cleanup_old_files'      ) );
        add_action( 'wc_receipt_reminder_hook', array( $this, 'send_payment_reminders' ) );
        add_action( 'wc_receipt_expire_hook',   array( $this, 'expire_overdue_orders'  ) );

        add_action( 'wp', array( $this, 'maybe_handle_frontend' ) );

        // All three upload filters are context-gated (see $receipt_upload_context).
        add_filter( 'upload_dir',                 array( $this, 'custom_upload_dir'    ) );
        add_filter( 'upload_mimes',               array( $this, 'restrict_upload_mimes') );
        add_filter( 'wp_handle_upload_prefilter', array( $this, 'validate_upload'      ) );

        add_action( 'woocommerce_order_status_changed',
                    array( $this, 'send_sms_on_status_change' ), 10, 4 );

        add_action( 'init',              array( $this, 'add_rewrite_endpoint' ) );
        add_action( 'template_redirect', array( $this, 'secure_receipt_view'  ) );

        // Display method 2 of 3 is the `[wc_bank_receipt]` shortcode, which keeps the
        // panel available on any page, builder or theme — including sites whose
        // thank-you and account pages are block-based and whose templates skip the
        // WooCommerce hooks. It is registered in the bootstrap section at the bottom
        // of this file so that it exists with and without WooCommerce.

        // Display method 3 of 3: a thin Elementor widget. The hook only ever fires
        // when Elementor is active, so a site without Elementor is unaffected.
        add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
        // …and its editor preview loads the same front-end assets as the live page.
        add_action( 'elementor/preview/enqueue_styles', array( __CLASS__, 'enqueue_frontend_assets' ) );

        // =========================================================================
        // ADMIN ORDER ALERTS (EVENT QUEUE → POPUP & SOUND)
        //
        // Two independent events are recorded as rows in `…_events`:
        //   new_order     — an order was placed, whatever the payment method;
        //   status_change — an existing order changed status through the standard
        //                   WooCommerce flow (`woocommerce_order_status_changed`).
        // The admin UI polls the queue with a per-user cursor, so an event is
        // delivered once — reloading the page does not re-play it.
        // =========================================================================
        add_action( 'woocommerce_new_order',           array( $this, 'record_new_order_event' ), 20, 2 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'record_status_change_event' ), 20, 4 );
        // …and once the order is complete, so the popup carries the real customer, total,
        // item count and payment method. `shutdown` is the catch-all for flows that never
        // reach a checkout hook (admin-created orders, REST, WP-CLI).
        add_action( 'woocommerce_checkout_order_processed',            array( $this, 'refresh_new_order_snapshots' ), 20 );
        add_action( 'woocommerce_store_api_checkout_order_processed',  array( $this, 'refresh_new_order_snapshots' ), 20 );
        add_action( 'shutdown',                                        array( $this, 'refresh_new_order_snapshots' ), 1 );
        add_action( 'wp_ajax_wcr_alerts_poll',         array( $this, 'ajax_alerts_poll' ) );
        add_action( 'wp_ajax_wcr_alerts_dismiss',      array( $this, 'ajax_alerts_dismiss' ) );
        add_action( 'admin_footer',                    array( $this, 'render_admin_alert_popup' ) );
    }

    public function notice_missing_woocommerce() {
        if ( ! current_user_can( 'activate_plugins' ) ) return;
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'افزونهٔ «فیش بانکی ووکامرس» برای کار کردن به ووکامرس فعال نیاز دارد. هیچ داده‌ای حذف نشده است؛ پس از فعال‌سازی ووکامرس افزونه به‌طور خودکار از سر گرفته می‌شود.', 'wc-bank-receipt' )
            . '</p></div>';
    }

    // =========================================================================
    // ASSET ENQUEUE
    // =========================================================================

    public function admin_enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wc-receipt' ) === false &&
             strpos( $hook, 'wc-sms'     ) === false ) return;

        wp_enqueue_script( 'jquery-ui-sortable' );

        // Registration keeps the `false` source (inline-only) on purpose: the admin
        // screens are rendered from their own namespaced CSS, never from a file a
        // theme could override.
        wp_register_style( 'wcr-admin', false, array(), self::VERSION );
        wp_enqueue_style(  'wcr-admin' );
        wp_add_inline_style( 'wcr-admin', $this->get_admin_css() );

        /*
         * The «ظاهر و استایل» screen previews the real component, so it loads the very
         * same stylesheet the customer gets (the panel classes are namespaced, so it
         * cannot restyle wp-admin) plus one admin-only script that only ever updates
         * that preview. Neither handle is loaded on a front-end page.
         */
        if ( strpos( $hook, 'wc-receipt-appearance' ) !== false ) {
            self::enqueue_frontend_assets();
            wp_enqueue_script(
                'wcr-admin-appearance',
                plugins_url( self::ASSET_ADMIN_JS, __FILE__ ),
                array(),
                self::VERSION,
                true
            );
            wp_localize_script( 'wcr-admin-appearance', 'wcrAppearance', $this->appearance_script_config() );
        }
    }

    /**
     * Register (once) the front-end style and script.
     *
     * Both handles are real, versioned files, so a theme or another plugin can
     * dequeue / replace them with the standard API:
     *   wp_dequeue_style( 'wcr-frontend' );
     */
    public static function register_frontend_assets() {
        if ( wp_style_is( 'wcr-frontend', 'registered' ) ) return;

        wp_register_style(
            'wcr-frontend',
            plugins_url( self::ASSET_CSS, __FILE__ ),
            array(),
            self::VERSION
        );
        wp_register_script(
            'wcr-frontend',
            plugins_url( self::ASSET_JS, __FILE__ ),
            array(),
            self::VERSION,
            true // footer: the panel markup is already in the page by then
        );

        // Every string the script may print lives here, so the JS file itself
        // carries no locale-dependent text.
        wp_localize_script(
            'wcr-frontend',
            'wcrFrontend',
            array(
                'copyLabel'    => __( 'کپی شماره', 'wc-bank-receipt' ),
                'copiedLabel'  => __( '✓ کپی شد', 'wc-bank-receipt' ),
                'copyFailed'   => __( 'مرورگر شما اجازهٔ کپی خودکار نداد؛ شماره را دستی انتخاب کنید.', 'wc-bank-receipt' ),
                'expiredLabel' => __( 'پایان مهلت!', 'wc-bank-receipt' ),
                'selectLabel'  => __( 'انتخاب فایل', 'wc-bank-receipt' ),
            )
        );
    }

    /** Enqueue both handles — used by the automatic hooks and by on-demand rendering. */
    public static function enqueue_frontend_assets() {
        self::register_frontend_assets();
        wp_enqueue_style( 'wcr-frontend' );
        wp_enqueue_script( 'wcr-frontend' );
    }

    /**
     * Should the front-end assets load on this request?
     *
     * True on the WooCommerce order endpoints the panel is designed for, and on any
     * singular page that actually renders the panel — including pages built with
     * Elementor, whose layout lives in post meta and never appears in post_content.
     * A site whose panel is injected by code can force it with the filter.
     *
     * @return bool
     */
    public static function frontend_assets_needed() {
        if ( function_exists( 'is_wc_endpoint_url' ) &&
             ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
            return true;
        }

        if ( is_singular() && self::page_renders_shortcode() ) {
            return true;
        }

        return (bool) apply_filters( 'wcr_load_frontend_assets', false );
    }

    public function frontend_enqueue_assets() {
        if ( ! self::frontend_assets_needed() ) return;
        self::enqueue_frontend_assets();
    }

    // =========================================================================
    // ADMIN CSS
    // =========================================================================

    private function get_admin_css() {
        return '
:root{
  --wcr-primary:#1565c0;--wcr-primary-h:#1e88e5;--wcr-primary-bg:#e3f2fd;
  --wcr-ok:#2e7d32;--wcr-ok-h:#43a047;--wcr-ok-bg:#e8f5e9;
  --wcr-err:#c62828;--wcr-err-h:#e53935;--wcr-err-bg:#ffebee;
  --wcr-warn:#e65100;--wcr-warn-bg:#fff3e0;
  --wcr-border:#e0e0e0;--wcr-bg:#f5f6fa;--wcr-white:#fff;
  --wcr-text:#212121;--wcr-muted:#757575;
  --wcr-r:10px;--wcr-sh:0 2px 12px rgba(0,0,0,.08);--wcr-tr:all .2s ease;
}
.wcr-wrap{max-width:1400px;direction:rtl;font-family:Tahoma,"Segoe UI",sans-serif;}

/* ---- Header ---- */
.wcr-header{
  background:linear-gradient(135deg,var(--wcr-primary) 0%,var(--wcr-primary-h) 100%);
  color:#fff;padding:22px 28px;border-radius:var(--wcr-r);
  margin-bottom:24px;display:flex;align-items:center;gap:14px;
}
.wcr-header h1{color:#fff;font-size:21px;margin:0;padding:0;font-weight:600;}
.wcr-header .dashicons{font-size:30px;width:30px;height:30px;}

/* ---- Stat Cards ---- */
.wcr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:24px;}
.wcr-stat{
  background:var(--wcr-white);border-radius:var(--wcr-r);padding:18px 20px;
  box-shadow:var(--wcr-sh);border-right:4px solid var(--wcr-primary-h);
  display:flex;align-items:center;gap:14px;transition:var(--wcr-tr);
}
.wcr-stat:hover{transform:translateY(-2px);box-shadow:0 4px 20px rgba(0,0,0,.12);}
.wcr-stat.ok{border-right-color:var(--wcr-ok-h);}
.wcr-stat.err{border-right-color:var(--wcr-err-h);}
.wcr-stat.warn{border-right-color:#fb8c00;}
.wcr-stat-ico{
  width:46px;height:46px;border-radius:50%;background:var(--wcr-primary-bg);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.wcr-stat-ico .dashicons{color:var(--wcr-primary-h);font-size:20px;width:20px;height:20px;}
.wcr-stat.ok  .wcr-stat-ico{background:var(--wcr-ok-bg);}
.wcr-stat.ok  .wcr-stat-ico .dashicons{color:var(--wcr-ok-h);}
.wcr-stat.err .wcr-stat-ico{background:var(--wcr-err-bg);}
.wcr-stat.err .wcr-stat-ico .dashicons{color:var(--wcr-err-h);}
.wcr-stat.warn .wcr-stat-ico{background:var(--wcr-warn-bg);}
.wcr-stat.warn .wcr-stat-ico .dashicons{color:#fb8c00;}
.wcr-stat-val{font-size:26px;font-weight:700;color:var(--wcr-text);line-height:1;}
.wcr-stat-lbl{font-size:12px;color:var(--wcr-muted);margin-top:3px;}

/* ---- Card panel ---- */
.wcr-card{
  background:var(--wcr-white);border-radius:var(--wcr-r);
  box-shadow:var(--wcr-sh);padding:22px 26px;margin-bottom:22px;
}
.wcr-card h2,.wcr-card h3{
  margin:0 0 18px;padding-bottom:12px;
  border-bottom:2px solid var(--wcr-border);
  color:var(--wcr-text);font-size:15px;
  display:flex;align-items:center;gap:8px;
}
.wcr-card h2 .dashicons,.wcr-card h3 .dashicons{color:var(--wcr-primary-h);}

/* ---- Form table ---- */
.wcr-ftbl{width:100%;border-collapse:collapse;}
.wcr-ftbl th{
  text-align:right;padding:13px 16px;font-weight:600;
  color:var(--wcr-text);font-size:14px;width:220px;
  vertical-align:top;padding-top:17px;
}
.wcr-ftbl td{padding:11px 16px;}
.wcr-ftbl tr{border-bottom:1px solid #f0f0f0;}
.wcr-ftbl tr:last-child{border-bottom:none;}
.wcr-ftbl input[type=text],.wcr-ftbl input[type=number],
.wcr-ftbl input[type=url],.wcr-ftbl input[type=date],
.wcr-ftbl input[type=password],.wcr-ftbl select,.wcr-ftbl textarea{
  border:1.5px solid var(--wcr-border);border-radius:6px;
  padding:8px 12px;font-size:14px;transition:var(--wcr-tr);
  width:100%;max-width:400px;color:var(--wcr-text);background:#fafafa;
}
.wcr-ftbl input:focus,.wcr-ftbl select:focus,.wcr-ftbl textarea:focus{
  outline:none;border-color:var(--wcr-primary-h);
  box-shadow:0 0 0 3px rgba(30,136,229,.15);background:var(--wcr-white);
}
.wcr-ftbl input[type=checkbox]{width:auto;accent-color:var(--wcr-primary-h);}
.wcr-hint{font-size:12px;color:var(--wcr-muted);margin-top:4px;}

/* ---- Badges ---- */
.wcr-badge{
  display:inline-flex;align-items:center;gap:4px;
  padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;
}
.wcr-badge.ok   {background:var(--wcr-ok-bg); color:var(--wcr-ok);}
.wcr-badge.err  {background:var(--wcr-err-bg);color:var(--wcr-err);}
.wcr-badge.warn {background:var(--wcr-warn-bg);color:var(--wcr-warn);}
.wcr-badge.info {background:var(--wcr-primary-bg);color:var(--wcr-primary);}
.wcr-badge.muted{background:#eceff1;color:#546e7a;}

/* ---- Buttons ---- */
.wcr-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 16px;border-radius:6px;font-size:13px;
  font-weight:600;cursor:pointer;border:none;
  transition:var(--wcr-tr);text-decoration:none;font-family:inherit;
}
.wcr-btn-primary{background:var(--wcr-primary-h);color:#fff;}
.wcr-btn-primary:hover{background:var(--wcr-primary);color:#fff;}
.wcr-btn-ok{background:var(--wcr-ok-h);color:#fff;}
.wcr-btn-ok:hover{background:var(--wcr-ok);color:#fff;}
.wcr-btn-err{background:var(--wcr-err-h);color:#fff;}
.wcr-btn-err:hover{background:var(--wcr-err);color:#fff;}
.wcr-btn-ghost{background:transparent;color:var(--wcr-primary-h);border:1.5px solid var(--wcr-primary-h);}
.wcr-btn-ghost:hover{background:var(--wcr-primary-bg);}
.wcr-btn-sm{padding:5px 10px;font-size:12px;}

/* ---- Enhanced Table ---- */
.wcr-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;}
.wcr-tbl th{
  background:var(--wcr-bg);padding:11px 15px;text-align:right;
  font-weight:600;color:var(--wcr-muted);font-size:12px;
  text-transform:uppercase;letter-spacing:.5px;
  border-bottom:2px solid var(--wcr-border);
}
.wcr-tbl td{padding:12px 15px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.wcr-tbl tbody tr:hover{background:#fafbff;}
.wcr-tbl tbody tr:last-child td{border-bottom:none;}

/* ---- Drag handle ---- */
.wcr-drag{cursor:grab;color:#bdbdbd;font-size:18px;transition:color .2s;}
.wcr-drag:hover{color:#757575;}
.wcr-drag:active{cursor:grabbing;}

/* ---- Gallery ---- */
.wcr-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:20px;}
.wcr-gitem{
  background:var(--wcr-white);border-radius:var(--wcr-r);
  box-shadow:var(--wcr-sh);overflow:hidden;transition:var(--wcr-tr);
}
.wcr-gitem:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.12);}
.wcr-gthumb{
  height:155px;overflow:hidden;background:var(--wcr-bg);
  display:flex;align-items:center;justify-content:center;position:relative;
}
.wcr-gthumb img{width:100%;height:100%;object-fit:cover;transition:transform .3s;}
.wcr-gitem:hover .wcr-gthumb img{transform:scale(1.06);}
.wcr-gthumb .pdf-icon{font-size:40px;color:#90a4ae;}
.wcr-ginfo{padding:12px 14px;}
.wcr-ginfo .order-no{font-weight:600;font-size:14px;color:var(--wcr-text);}
.wcr-ginfo .time-left{font-size:12px;color:var(--wcr-muted);margin-top:3px;}
.wcr-ginfo .actions{margin-top:10px;display:flex;gap:8px;}

/* ---- Lightbox ---- */
.wcr-lightbox{
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);
  z-index:999999;align-items:center;justify-content:center;
  backdrop-filter:blur(4px);
}
.wcr-lightbox.open{display:flex;}
.wcr-lightbox img{max-width:90%;max-height:88%;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.wcr-lb-close{
  position:absolute;top:18px;right:18px;color:#fff;cursor:pointer;
  background:rgba(255,255,255,.2);width:40px;height:40px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;transition:var(--wcr-tr);
  font-size:20px;border:none;
}
.wcr-lb-close:hover{background:rgba(255,255,255,.35);}

/* ---- SMS Log ---- */
.wcr-log-sent{color:var(--wcr-ok);}
.wcr-log-fail{color:var(--wcr-err);}

/* ---- Admin order section ---- */
.wcr-order-box{
  background:var(--wcr-white);border:1.5px solid var(--wcr-border);
  border-radius:var(--wcr-r);padding:18px 20px;margin-top:18px;
}
.wcr-order-box h4{margin:0 0 14px;font-size:14px;display:flex;align-items:center;gap:8px;}
.wcr-order-box h4 .dashicons{color:var(--wcr-primary-h);}
.wcr-order-actions{display:flex;gap:10px;flex-wrap:wrap;}

/* ---- Reject reason ---- */
.wcr-reason-wrap{margin-top:14px;}
.wcr-reason-wrap label{font-size:13px;font-weight:600;display:block;margin-bottom:5px;}
.wcr-reason-wrap textarea{
  width:100%;border:1.5px solid var(--wcr-border);
  border-radius:6px;padding:8px 10px;resize:vertical;
  font-family:inherit;font-size:13px;
}

/* ---- Tabs ---- */
.wcr-tabs{
  display:flex;gap:4px;border-bottom:2px solid var(--wcr-border);
  margin-bottom:0;padding:0;
}
.wcr-tab{
  padding:9px 20px;cursor:pointer;border-radius:8px 8px 0 0;
  font-size:14px;font-weight:500;color:var(--wcr-muted);
  border:none;background:transparent;transition:var(--wcr-tr);
  position:relative;bottom:-2px;font-family:inherit;
}
.wcr-tab.active{
  background:var(--wcr-white);color:var(--wcr-primary-h);
  border:2px solid var(--wcr-border);border-bottom:2px solid var(--wcr-white);
}
.wcr-tab:hover:not(.active){background:var(--wcr-bg);}
.wcr-tab-pane{display:none;padding-top:22px;}
.wcr-tab-pane.active{display:block;}

/* ---- Card chip design for bank cards in list ---- */
.wcr-bank-chip{
  background:linear-gradient(135deg,#1565c0,#42a5f5);
  color:#fff;border-radius:8px;padding:10px 14px;
  display:inline-block;font-size:12px;direction:ltr;
  font-family:monospace;letter-spacing:2px;
}

/* ---- Reset button ---- */
.wcr-reset-btn{
  background:#fff;border:1.5px solid var(--wcr-err-h);color:var(--wcr-err-h);
  border-radius:6px;padding:8px 18px;cursor:pointer;font-weight:600;
  font-size:13px;transition:var(--wcr-tr);font-family:inherit;
}
.wcr-reset-btn:hover{background:var(--wcr-err-bg);}

/* ---- Responsive ---- */
@media(max-width:782px){
  .wcr-stats{grid-template-columns:1fr 1fr;}
  .wcr-gallery{grid-template-columns:1fr 1fr;}
  .wcr-ftbl th{display:block;width:100%;padding-bottom:4px;}
  .wcr-ftbl td{display:block;padding-top:4px;}
  .wcr-ftbl tr{display:block;border-bottom:2px solid var(--wcr-border);padding-bottom:12px;margin-bottom:8px;}
}
        ';
    }

    // =========================================================================
    // FRONTEND CSS
    // =========================================================================

    // =========================================================================
    // TEXT DOMAIN
    // =========================================================================

    public function load_textdomain() {
        load_plugin_textdomain( 'wc-bank-receipt', false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function woocommerce_is_available() {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
    }

    // =========================================================================
    // ACTIVATION / DEACTIVATION / MIGRATION
    //
    // Data policy (documented in readme.txt and docs/):
    //   activate()   → create schema, protected dir, crons. Never touches existing rows.
    //   deactivate() → stop scheduled work only. Tables, options, order meta and
    //                  receipt files are ALL preserved.
    //   uninstall    → only removes data when the shop owner opted in
    //                  ("wc_receipt_delete_data_on_uninstall" = yes), else nothing.
    // =========================================================================

    /**
     * Static so it works on the real activation request, where WordPress has
     * already fired `plugins_loaded` before including this file.
     */
    public static function on_activate() {
        $plugin = self::get_instance();
        if ( ! $plugin->woocommerce_is_available() ) {
            return; // WordPress stays healthy; the admin notice explains what is missing.
        }
        $plugin->create_tables();
        $plugin->create_protected_directory();
        $plugin->schedule_events();
        update_option( 'wc_bank_receipt_db_version', self::DB_VERSION );
    }

    /** Deactivation only stops scheduled activities. */
    public static function on_deactivate() {
        self::get_instance()->unschedule_events();
    }

    /** Kept for backwards compatibility with sites that call these directly. */
    public function activate()   { self::on_activate(); }
    public function deactivate() { self::on_deactivate(); }

    public function schedule_events() {
        if ( ! $this->woocommerce_is_available() ) return;
        $events = array(
            'wc_receipt_cleanup_hook'  => 'hourly',
            'wc_receipt_reminder_hook' => 'hourly',
            'wc_receipt_expire_hook'   => 'hourly',
        );
        foreach ( $events as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
            }
        }
    }

    public function unschedule_events() {
        foreach ( array( 'wc_receipt_cleanup_hook', 'wc_receipt_reminder_hook', 'wc_receipt_expire_hook' ) as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
    }

    /**
     * Repeatable, additive migration. dbDelta only adds/updates columns and indexes,
     * so an existing installation keeps every row, option and file it had.
     */
    public function maybe_upgrade_db() {
        if ( ! $this->woocommerce_is_available() ) return;
        if ( get_option( 'wc_bank_receipt_db_version' ) === self::DB_VERSION ) return;
        $this->create_tables();
        $this->create_protected_directory();
        $this->schedule_events();
        update_option( 'wc_bank_receipt_db_version', self::DB_VERSION );
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc     = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . self::DB_PREFIX;

        dbDelta( "CREATE TABLE {$prefix}cards (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_name varchar(100) NOT NULL,
            bank_name varchar(100) NOT NULL,
            card_number varchar(20) NOT NULL,
            sheba varchar(26) DEFAULT NULL,
            account_holder varchar(150) NOT NULL,
            limit_amount bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            max_transactions int(11) UNSIGNED NOT NULL DEFAULT 0,
            expiry_date date DEFAULT NULL,
            used_amount bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            used_transactions int(11) UNSIGNED NOT NULL DEFAULT 0,
            priority int(11) UNSIGNED NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            enable_limit_amount tinyint(1) NOT NULL DEFAULT 1,
            enable_max_transactions tinyint(1) NOT NULL DEFAULT 1,
            enable_expiry_date tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $cc;" );

        dbDelta( "CREATE TABLE {$prefix}receipt_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            action varchar(20) NOT NULL,
            reason text DEFAULT NULL,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) $cc;" );

        dbDelta( "CREATE TABLE {$prefix}sms_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned DEFAULT NULL,
            recipient varchar(20) NOT NULL,
            message text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'sent',
            response text DEFAULT NULL,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) $cc;" );

        // Idempotency ledger: one row per (order, receipt file, transition).
        // The UNIQUE key is what makes "approve twice" / concurrent approvals
        // charge a card once and only once - enforced by the database itself.
        dbDelta( "CREATE TABLE {$prefix}transitions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            receipt_uid varchar(64) NOT NULL DEFAULT '',
            transition varchar(20) NOT NULL,
            card_id bigint(20) unsigned DEFAULT NULL,
            amount bigint(20) DEFAULT 0,
            user_id bigint(20) unsigned DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_transition (order_id,receipt_uid,transition),
            KEY order_id (order_id)
        ) $cc;" );

        // Order alert queue (new order / status change) that the admin popup reads.
        // The UNIQUE dedupe key is what stops one WooCommerce action from queueing the
        // same event twice, and the snapshot columns keep the popup readable even for
        // an order that was deleted afterwards.
        dbDelta( "CREATE TABLE {$prefix}events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(20) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            from_status varchar(30) NOT NULL DEFAULT '',
            to_status varchar(30) NOT NULL DEFAULT '',
            customer varchar(190) NOT NULL DEFAULT '',
            order_number varchar(50) NOT NULL DEFAULT '',
            total_display varchar(60) NOT NULL DEFAULT '',
            payment_title varchar(100) NOT NULL DEFAULT '',
            items_count int(11) unsigned NOT NULL DEFAULT 0,
            view_url varchar(255) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_gmt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            dedupe_key char(40) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event (dedupe_key),
            KEY order_id (order_id),
            KEY created_gmt (created_gmt)
        ) $cc;" );
    }

    /**
     * DESTRUCTIVE. Only reachable from an explicit, nonce+capability guarded admin
     * action (Settings → delete all plugin data). Never from deactivation.
     */
    private function remove_tables() {
        global $wpdb;
        $p = $wpdb->prefix . self::DB_PREFIX;
        $wpdb->query( "DROP TABLE IF EXISTS {$p}cards"       );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}receipt_log" );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}sms_log"     );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}transitions" );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}events"      );
    }

    /** DESTRUCTIVE. See remove_tables(). */
    private function delete_options() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE 'wc_receipt_%'
               OR option_name LIKE 'wc_sms_%'
               OR option_name LIKE 'wc_bank_card%'
               OR option_name  =  'wc_bank_receipt_db_version'
               OR option_name  =  'wcr_pending_admin_alerts'" );
    }

    /**
     * Idempotent: makes sure the reciepts folder exists AND is protected, even on
     * installs where activation never ran or the folder was recreated by an upload.
     */
    private function create_protected_directory() {
        $base = wp_upload_dir();
        if ( ! empty( $base['error'] ) ) return false;
        $dir = trailingslashit( $base['basedir'] ) . self::UPLOAD_DIR;
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return false;

        $rules = "# WC Bank Receipt — do not serve uploaded receipts directly.\n"
               . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
               . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
        $ht = $dir . '/.htaccess';
        $current = file_exists( $ht ) ? (string) @file_get_contents( $ht ) : '';
        if ( strpos( $current, 'Require all denied' ) === false || strpos( $current, 'Deny from all' ) === false ) {
            @file_put_contents( $ht, $rules );
        }
        if ( ! file_exists( $dir . '/index.php' ) ) {
            @file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
        }
        return true;
    }

    /** DESTRUCTIVE. Only from the explicit delete-all-data action. */
    private function remove_protected_directory() {
        $base = wp_upload_dir();
        $dir  = $base['basedir'] . '/' . self::UPLOAD_DIR;
        if ( is_dir( $dir ) ) $this->recursive_rmdir( $dir );
    }

    private function recursive_rmdir( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        foreach ( scandir( $dir ) as $f ) {
            if ( $f === '.' || $f === '..' ) continue;
            $path = "$dir/$f";
            is_dir( $path ) ? $this->recursive_rmdir( $path ) : unlink( $path );
        }
        rmdir( $dir );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function admin_menu() {
        add_menu_page( __( 'فیش بانکی', 'wc-bank-receipt' ), __( 'فیش بانکی', 'wc-bank-receipt' ),
            'manage_options', 'wc-receipt-settings', array( $this, 'settings_page' ),
            'dashicons-money-alt', 30 );

        add_submenu_page( 'wc-receipt-settings',
            __( 'تنظیمات', 'wc-bank-receipt' ), __( 'تنظیمات', 'wc-bank-receipt' ),
            'manage_options', 'wc-receipt-settings' );

        add_submenu_page( 'wc-receipt-settings',
            __( 'ظاهر و استایل', 'wc-bank-receipt' ), __( 'ظاهر و استایل', 'wc-bank-receipt' ),
            'manage_options', 'wc-receipt-appearance', array( $this, 'appearance_page' ) );

        add_submenu_page( 'wc-receipt-settings',
            __( 'مدیریت کارت‌ها', 'wc-bank-receipt' ), __( 'کارت‌های بانکی', 'wc-bank-receipt' ),
            'manage_options', 'wc-receipt-cards', array( $this, 'cards_page' ) );

        add_submenu_page( 'wc-receipt-settings',
            __( 'بررسی گروهی', 'wc-bank-receipt' ), __( 'بررسی گروهی', 'wc-bank-receipt' ),
            'manage_woocommerce', 'wc-receipt-bulk', array( $this, 'bulk_review_page' ) );

        add_submenu_page( 'wc-receipt-settings',
            __( 'گزارشات', 'wc-bank-receipt' ), __( 'گزارشات', 'wc-bank-receipt' ),
            'manage_woocommerce', 'wc-receipt-reports', array( $this, 'reports_page' ) );

        add_submenu_page( 'wc-receipt-settings',
            __( 'گالری فیش‌ها', 'wc-bank-receipt' ), __( 'گالری فیش‌ها', 'wc-bank-receipt' ),
            'manage_woocommerce', 'wc-receipt-gallery', array( $this, 'gallery_page' ) );

        /*
         * SMS screens are not registered in this release (see SMS_UI_ENABLED).
         * Not registering the menu page also removes the route: WordPress answers
         * `admin.php?page=wc-sms-settings` with “Sorry, you are not allowed to access
         * this page.” for everyone, including an administrator. No option, table or
         * credential is touched — the pages come back with the flag in the next phase.
         */
        if ( self::SMS_UI_ENABLED ) {
            add_submenu_page( 'wc-receipt-settings',
                __( 'پیام‌رسانی', 'wc-bank-receipt' ), __( 'پیام‌رسانی', 'wc-bank-receipt' ),
                'manage_options', 'wc-sms-settings', array( $this, 'sms_settings_page' ) );

            add_submenu_page( 'wc-receipt-settings',
                __( 'لاگ پیامک', 'wc-bank-receipt' ), __( 'لاگ پیامک', 'wc-bank-receipt' ),
                'manage_options', 'wc-sms-log', array( $this, 'sms_log_page' ) );
        }
    }

    // =========================================================================
    // REGISTER SETTINGS
    // =========================================================================

    public function register_settings() {
        register_setting( 'wc_receipt_group', 'wc_receipt_expire_hours',    'floatval'            );
        register_setting( 'wc_receipt_group', 'wc_receipt_cleanup_period',  'sanitize_text_field' );
        register_setting( 'wc_receipt_group', 'wc_receipt_custom_days',     'absint'              );
        register_setting( 'wc_receipt_group', 'wc_receipt_max_file_size',   'absint'              );
        register_setting( 'wc_receipt_group', 'wc_receipt_allowed_mimes',   array( $this, 'sanitize_mimes' ) );
        register_setting( 'wc_receipt_group', 'wc_receipt_keep_approved_files', array( $this, 'sanitize_yes_no' ) );
        register_setting( 'wc_receipt_group', 'wc_receipt_delete_data_on_uninstall', array( $this, 'sanitize_yes_no' ) );
        // Order alerts (popup + sound). Sound is the only part that is optional; the
        // visual alert is always queued for whoever may manage orders.
        register_setting( 'wc_receipt_group', 'wc_receipt_alerts_sound', array( $this, 'sanitize_yes_no' ) );

        /*
         * Appearance: one option in its own options group.
         *
         * A separate group is not cosmetic — `options.php` updates every option of the
         * submitted group, so a second group is what guarantees that saving the look can
         * never write a payment/retention setting (and vice versa). The whole appearance
         * state is one array with one sanitize callback, validated field by field.
         */
        register_setting(
            WCR_Design_Tokens::GROUP,
            WCR_Design_Tokens::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( 'WCR_Design_Tokens', 'sanitize' ),
                'default'           => array(),
                'show_in_rest'      => false,
            )
        );

        // Credentials are secrets: an empty submit keeps the stored value, and “-”
        // explicitly clears it. They are never rendered back into the page.
        foreach ( array( 'kavenegar_apikey', 'melli_password', 'custom_apikey' ) as $secret ) {
            register_setting(
                'wc_sms_group',
                "wc_sms_{$secret}",
                function ( $value ) use ( $secret ) {
                    $value = sanitize_text_field( (string) $value );
                    if ( '-' === $value ) return '';
                    if ( '' === $value ) return get_option( "wc_sms_{$secret}", '' );
                    return $value;
                }
            );
        }

        foreach ( array( 'provider','kavenegar_sender',
                         'melli_username','melli_sender',
                         'custom_url','custom_sender',
                         'admin_phone','reminder_before_hours' ) as $k ) {
            register_setting( 'wc_sms_group', "wc_sms_{$k}",
                $k === 'custom_url' ? 'esc_url_raw' : ( $k === 'reminder_before_hours' ? 'absint' : 'sanitize_text_field' ) );
        }

        foreach ( array( 'approved','rejected','reminder','cancelled','admin_upload' ) as $t ) {
            register_setting( 'wc_sms_group', "wc_sms_{$t}_tpl",    'sanitize_text_field' );
            register_setting( 'wc_sms_group', "wc_sms_{$t}_sender",  'sanitize_text_field' );
        }
    }

    public function sanitize_mimes( $input ) {
        if ( ! is_array( $input ) ) return array();
        $allowed = array( 'image/jpeg', 'image/png', 'application/pdf' );
        return array_values( array_intersect( array_map( 'sanitize_mime_type', $input ), $allowed ) );
    }

    public function sanitize_yes_no( $input ) {
        return ( 'yes' === $input ) ? 'yes' : 'no';
    }

    // =========================================================================
    // SETTINGS PAGE
    // =========================================================================

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), '', array( 'response' => 403 ) );
        }

        $notice       = '';
        $notice_class = 'notice-success';

        // Reset settings only: keeps cards, logs, order metadata and receipt files.
        if ( isset( $_POST['reset_settings'] ) && check_admin_referer( 'reset_settings_nonce' ) ) {
            $this->delete_options();
            $this->maybe_upgrade_db();
            $notice = __( 'تنظیمات افزونه به مقادیر پیش‌فرض بازگشت. کارت‌ها، لاگ‌ها، متادیتای سفارش‌ها و فایل‌های فیش دست‌نخورده ماندند.', 'wc-bank-receipt' );
        }

        // Destructive: only with an explicit typed confirmation, and clearly separated.
        if ( isset( $_POST['delete_all_data'] ) && check_admin_referer( 'delete_all_data_nonce' ) ) {
            $confirm = isset( $_POST['confirm_delete'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_delete'] ) ) : '';
            if ( 'حذف' !== $confirm ) {
                $notice       = __( 'برای حذف دائمی باید عبارت «حذف» را دقیقاً در کادر تأیید بنویسید. هیچ داده‌ای حذف نشد.', 'wc-bank-receipt' );
                $notice_class = 'notice-error';
            } else {
                $this->remove_tables();
                $this->delete_options();
                $this->remove_protected_directory();
                $this->maybe_upgrade_db();
                $notice = __( 'تمام داده‌های افزونه (کارت‌ها، لاگ‌ها، تنظیمات و فایل‌های فیش) حذف شد. متادیتای سفارش‌ها دست‌نخورده است.', 'wc-bank-receipt' );
            }
        }
        ?>

        <?php if ( $notice ) : ?>
            <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>
        ?>

        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-admin-settings"></span>
                <h1><?php _e( 'تنظیمات فیش بانکی', 'wc-bank-receipt' ); ?></h1>
            </div>

            <div class="wcr-card">
                <h2><span class="dashicons dashicons-clock"></span> <?php _e( 'تنظیمات عمومی', 'wc-bank-receipt' ); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'wc_receipt_group' ); ?>
                    <table class="wcr-ftbl">
                        <tr>
                            <th><?php _e( 'مهلت ارسال فیش', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <input type="number" step="0.5" min="0.5" name="wc_receipt_expire_hours"
                                    value="<?php echo esc_attr( get_option( 'wc_receipt_expire_hours', 2 ) ); ?>"
                                    style="max-width:120px;">
                                <span class="wcr-hint"><?php _e( 'ساعت — مهلت مشتری برای بارگذاری فیش', 'wc-bank-receipt' ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'حداکثر حجم فیش', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <input type="number" min="100" name="wc_receipt_max_file_size"
                                    value="<?php echo esc_attr( get_option( 'wc_receipt_max_file_size', 2048 ) ); ?>"
                                    style="max-width:120px;">
                                <span class="wcr-hint">KB</span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'فرمت‌های مجاز', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <?php $mimes = get_option( 'wc_receipt_allowed_mimes', array( 'image/jpeg','image/png','application/pdf' ) ); ?>
                                <label style="margin-left:12px;"><input type="checkbox" name="wc_receipt_allowed_mimes[]" value="image/jpeg" <?php checked( in_array( 'image/jpeg', $mimes ) ); ?>> JPEG</label>
                                <label style="margin-left:12px;"><input type="checkbox" name="wc_receipt_allowed_mimes[]" value="image/png"  <?php checked( in_array( 'image/png',  $mimes ) ); ?>> PNG</label>
                                <label><input type="checkbox" name="wc_receipt_allowed_mimes[]" value="application/pdf" <?php checked( in_array( 'application/pdf', $mimes ) ); ?>> PDF</label>
                                <p class="wcr-hint"><?php _e( 'این محدودیت فقط روی آپلود فیش اعمال می‌شود و سایر بخش‌های سایت را تحت تأثیر نمی‌گذارد.', 'wc-bank-receipt' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'نگهداری فیش‌های تأییدشده', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <?php $keep_approved = get_option( 'wc_receipt_keep_approved_files', 'yes' ); ?>
                                <label>
                                    <input type="checkbox" name="wc_receipt_keep_approved_files" value="yes" <?php checked( $keep_approved, 'yes' ); ?>>
                                    <?php esc_html_e( 'فایل فیش سفارش‌های تأییدشده هرگز خودکار حذف نشود (پیشنهادی)', 'wc-bank-receipt' ); ?>
                                </label>
                                <p class="wcr-hint"><?php esc_html_e( 'فیش تأییدشده سند پرداخت مشتری است؛ با غیرفعال کردن این گزینه، فقط فایل سفارش‌هایی که تأیید نشده‌اند پاک می‌شود. حذف فایل هرگز سفارش یا لاگ را تغییر نمی‌دهد.', 'wc-bank-receipt' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'بازه حذف خودکار فیش‌ها', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <select name="wc_receipt_cleanup_period" id="cleanup_period">
                                    <option value="1"      <?php selected( get_option( 'wc_receipt_cleanup_period','1' ),'1'       ); ?>><?php _e( 'روزانه',   'wc-bank-receipt' ); ?></option>
                                    <option value="7"      <?php selected( get_option( 'wc_receipt_cleanup_period'     ),'7'       ); ?>><?php _e( 'هفتگی',    'wc-bank-receipt' ); ?></option>
                                    <option value="30"     <?php selected( get_option( 'wc_receipt_cleanup_period'     ),'30'      ); ?>><?php _e( 'ماهانه',   'wc-bank-receipt' ); ?></option>
                                    <option value="custom" <?php selected( get_option( 'wc_receipt_cleanup_period'     ),'custom'  ); ?>><?php _e( 'سفارشی',   'wc-bank-receipt' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr id="custom_days_row" style="<?php echo get_option( 'wc_receipt_cleanup_period' ) === 'custom' ? '' : 'display:none;'; ?>">
                            <th><?php _e( 'تعداد روز سفارشی', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <input type="number" min="1" name="wc_receipt_custom_days"
                                    value="<?php echo esc_attr( get_option( 'wc_receipt_custom_days', 1 ) ); ?>"
                                    style="max-width:100px;">
                                <span class="wcr-hint"><?php _e( 'روز', 'wc-bank-receipt' ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'حذف داده‌ها هنگام پاک کردن افزونه', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_receipt_delete_data_on_uninstall" value="yes" <?php checked( get_option( 'wc_receipt_delete_data_on_uninstall', 'no' ), 'yes' ); ?>>
                                    <?php esc_html_e( 'با «Remove» کردن افزونه، جدول‌ها، تنظیمات و فایل‌های فیش هم پاک شوند', 'wc-bank-receipt' ); ?>
                                </label>
                                <p class="wcr-hint"><?php esc_html_e( 'پیش‌فرض: خیر. داده‌ها حتی پس از حذف افزونه باقی می‌مانند تا سفارش‌های قدیمی سالم بمانند.', 'wc-bank-receipt' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'اعلان سفارش‌ها', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_receipt_alerts_sound" value="yes" <?php checked( get_option( 'wc_receipt_alerts_sound', 'yes' ), 'yes' ); ?>>
                                    <?php esc_html_e( 'پخش صدای هشدار برای سفارش جدید و تغییر وضعیت سفارش', 'wc-bank-receipt' ); ?>
                                </label>
                                <p class="wcr-hint"><?php esc_html_e( 'اعلان تصویری همیشه برای کاربران دارای دسترسی مدیریت سفارش نمایش داده می‌شود؛ این گزینه فقط صدا را خاموش یا روشن می‌کند. صدا از داخل مرورگر ساخته می‌شود و به فایل یا اینترنت وابسته نیست.', 'wc-bank-receipt' ); ?></p>
                                <p>
                                    <button type="button" class="button" id="wcr-test-sound"><?php esc_html_e( 'آزمون صدا', 'wc-bank-receipt' ); ?></button>
                                    <span class="wcr-hint" id="wcr-test-sound-msg" aria-live="polite"></span>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'ذخیره تنظیمات', 'wc-bank-receipt' ) ); ?>
                </form>
            </div>

            <div class="wcr-card">
                <h2><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'سیاست داده‌ها', 'wc-bank-receipt' ); ?></h2>
                <ul style="list-style:disc;padding-inline-start:22px;color:#546e7a;font-size:14px;line-height:2;">
                    <li><?php esc_html_e( 'غیرفعال کردن افزونه هیچ داده‌ای را حذف نمی‌کند؛ فقط زمان‌بندی‌ها متوقف می‌شوند.', 'wc-bank-receipt' ); ?></li>
                    <li><?php esc_html_e( 'به‌روزرسانی افزونه جدول‌ها را فقط کامل می‌کند و هیچ سطر یا فایلی را پاک نمی‌کند.', 'wc-bank-receipt' ); ?></li>
                    <li><?php esc_html_e( 'حذف کامل داده‌ها فقط از همین صفحه و با تأیید صریح انجام می‌شود.', 'wc-bank-receipt' ); ?></li>
                    <li><?php esc_html_e( 'این نسخه هیچ داده‌ای را به سرور توسعه‌دهنده ارسال نمی‌کند (بدون telemetry).', 'wc-bank-receipt' ); ?></li>
                </ul>
            </div>

            <div class="wcr-card">
                <h2><span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'بازنشانی تنظیمات', 'wc-bank-receipt' ); ?></h2>
                <p style="color:#757575;font-size:14px;"><?php esc_html_e( 'تنظیمات به مقادیر پیش‌فرض برمی‌گردد. کارت‌ها، لاگ‌ها، گزارش‌ها، متادیتای سفارش‌ها و فایل‌های فیش حفظ می‌شوند.', 'wc-bank-receipt' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'reset_settings_nonce' ); ?>
                    <button type="submit" name="reset_settings" class="wcr-reset-btn">
                        <?php esc_html_e( 'بازنشانی تنظیمات', 'wc-bank-receipt' ); ?>
                    </button>
                </form>
            </div>

            <div class="wcr-card" style="border:1.5px solid #ffcdd2;">
                <h2 style="border-color:#ffcdd2;"><span class="dashicons dashicons-warning" style="color:#e53935;"></span> <?php esc_html_e( 'حذف کامل داده‌های افزونه', 'wc-bank-receipt' ); ?></h2>
                <p style="color:#757575;font-size:14px;"><?php esc_html_e( 'این عملیات جدول‌های کارت/لاگ/پیامک، تنظیمات و تمام فایل‌های فیش را حذف می‌کند و قابل بازگشت نیست. متادیتای سفارش‌های ووکامرس دست‌نخورده می‌ماند. برای فعال‌سازی، عبارت «حذف» را در کادر زیر بنویسید.', 'wc-bank-receipt' ); ?>
                </p>
                <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'حذف کامل و غیرقابل بازگشت داده‌های افزونه. مطمئن هستید؟', 'wc-bank-receipt' ) ); ?>');">
                    <?php wp_nonce_field( 'delete_all_data_nonce' ); ?>
                    <input type="text" name="confirm_delete" value="" placeholder="حذف" style="max-width:120px;margin-left:10px;">
                    <button type="submit" name="delete_all_data" class="wcr-reset-btn">
                        ⚠️ <?php esc_html_e( 'حذف کامل داده‌های افزونه', 'wc-bank-receipt' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <script>
        document.getElementById('cleanup_period').addEventListener('change',function(){
            document.getElementById('custom_days_row').style.display = this.value==='custom' ? '' : 'none';
        });

        // Test button: the same locally generated beep the order popup uses. A click is
        // also the user gesture a browser with blocked autoplay is waiting for.
        (function(){
            var btn = document.getElementById('wcr-test-sound');
            var msg = document.getElementById('wcr-test-sound-msg');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) {
                    msg.textContent = '<?php echo esc_js( __( 'مرورگر شما از پخش صدا پشتیبانی نمی‌کند.', 'wc-bank-receipt' ) ); ?>';
                    return;
                }
                try {
                    var ctx = new Ctx();
                    if (ctx.state === 'suspended') { ctx.resume(); }
                    var t = ctx.currentTime;
                    var gain = ctx.createGain();
                    gain.connect(ctx.destination);
                    gain.gain.setValueAtTime(0.0001, t);
                    gain.gain.exponentialRampToValueAtTime(0.22, t + 0.05);
                    gain.gain.exponentialRampToValueAtTime(0.0001, t + 1.05);
                    [880, 660].forEach(function(freq, i){
                        var osc = ctx.createOscillator();
                        osc.type = 'sine';
                        osc.frequency.value = freq;
                        osc.connect(gain);
                        osc.start(t + i * 0.16);
                        osc.stop(t + 1.05);
                    });
                    msg.textContent = '<?php echo esc_js( __( 'صدای آزمایشی پخش شد.', 'wc-bank-receipt' ) ); ?>';
                } catch (e) {
                    msg.textContent = '<?php echo esc_js( __( 'پخش صدا در این مرورگر ممکن نشد.', 'wc-bank-receipt' ) ); ?>';
                }
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // CARDS PAGE
    // =========================================================================

    public function cards_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';

        // Save
        if ( isset( $_POST['save_card'] ) && check_admin_referer( 'card_nonce' ) ) {
            $id   = isset( $_POST['card_id'] ) ? absint( $_POST['card_id'] ) : 0;
            $data = array(
                'card_name'               => sanitize_text_field( wp_unslash( $_POST['card_name']       ?? '' ) ),
                'bank_name'               => sanitize_text_field( wp_unslash( $_POST['bank_name']       ?? '' ) ),
                'card_number'             => sanitize_text_field( wp_unslash( $_POST['card_number']     ?? '' ) ),
                'sheba'                   => sanitize_text_field( wp_unslash( $_POST['sheba']           ?? '' ) ),
                'account_holder'          => sanitize_text_field( wp_unslash( $_POST['account_holder']  ?? '' ) ),
                'limit_amount'            => absint( $_POST['limit_amount']     ?? 0 ),
                'max_transactions'        => absint( $_POST['max_transactions'] ?? 0 ),
                'expiry_date'             => sanitize_text_field( wp_unslash( $_POST['expiry_date']     ?? '' ) ),
                'is_active'               => isset( $_POST['is_active']               ) ? 1 : 0,
                'enable_limit_amount'     => isset( $_POST['enable_limit_amount']     ) ? 1 : 0,
                'enable_max_transactions' => isset( $_POST['enable_max_transactions'] ) ? 1 : 0,
                'enable_expiry_date'      => isset( $_POST['enable_expiry_date']      ) ? 1 : 0,
            );
            if ( $id ) {
                $wpdb->update( $table, $data, array( 'id' => $id ) );
            } else {
                $max   = $wpdb->get_var( "SELECT MAX(priority) FROM $table" );
                $data['priority'] = $max !== null ? $max + 1 : 0;
                $wpdb->insert( $table, $data );
            }
            echo '<div class="notice notice-success is-dismissible"><p>'
                . __( 'کارت با موفقیت ذخیره شد.', 'wc-bank-receipt' ) . '</p></div>';
        }

        // Delete
        if ( isset( $_GET['delete_card'] ) && check_admin_referer( 'delete_card_' . absint( $_GET['delete_card'] ) ) ) {
            $wpdb->delete( $table, array( 'id' => absint( $_GET['delete_card'] ) ) );
            echo '<div class="notice notice-success is-dismissible"><p>'
                . __( 'کارت حذف شد.', 'wc-bank-receipt' ) . '</p></div>';
        }

        $cards     = $wpdb->get_results( "SELECT * FROM $table ORDER BY priority ASC" );
        $edit_card = null;
        if ( isset( $_GET['edit_card'] ) ) {
            $edit_card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", absint( $_GET['edit_card'] ) ) );
        }
        $today = current_time( 'Y-m-d' );
        ?>
        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-credit-card"></span>
                <h1><?php _e( 'مدیریت کارت‌های بانکی', 'wc-bank-receipt' ); ?></h1>
            </div>

            <!-- Add/Edit Form -->
            <div class="wcr-card">
                <h2>
                    <span class="dashicons dashicons-<?php echo $edit_card ? 'edit' : 'plus-alt'; ?>"></span>
                    <?php echo $edit_card ? __( 'ویرایش کارت', 'wc-bank-receipt' ) : __( 'افزودن کارت جدید', 'wc-bank-receipt' ); ?>
                </h2>
                <form method="post">
                    <?php wp_nonce_field( 'card_nonce' ); ?>
                    <?php if ( $edit_card ) : ?>
                        <input type="hidden" name="card_id" value="<?php echo $edit_card->id; ?>">
                    <?php endif; ?>
                    <table class="wcr-ftbl">
                        <tr>
                            <th><?php _e( 'نام کارت (برچسب دلخواه)', 'wc-bank-receipt' ); ?></th>
                            <td><input type="text" name="card_name" value="<?php echo $edit_card ? esc_attr( $edit_card->card_name ) : ''; ?>" required placeholder="مثال: کارت اصلی ملی"></td>
                        </tr>
                        <tr>
                            <th><?php _e( 'نام بانک', 'wc-bank-receipt' ); ?></th>
                            <td><input type="text" name="bank_name" value="<?php echo $edit_card ? esc_attr( $edit_card->bank_name ) : ''; ?>" required placeholder="مثال: بانک ملی"></td>
                        </tr>
                        <tr>
                            <th><?php _e( 'شماره کارت', 'wc-bank-receipt' ); ?></th>
                            <td><input type="text" name="card_number" dir="ltr" value="<?php echo $edit_card ? esc_attr( $edit_card->card_number ) : ''; ?>" required placeholder="6037-XXXX-XXXX-XXXX"></td>
                        </tr>
                        <tr>
                            <th><?php _e( 'شماره شبا', 'wc-bank-receipt' ); ?></th>
                            <td><input type="text" name="sheba" dir="ltr" value="<?php echo $edit_card ? esc_attr( $edit_card->sheba ) : ''; ?>" placeholder="IR..."></td>
                        </tr>
                        <tr>
                            <th><?php _e( 'نام صاحب حساب', 'wc-bank-receipt' ); ?></th>
                            <td><input type="text" name="account_holder" value="<?php echo $edit_card ? esc_attr( $edit_card->account_holder ) : ''; ?>" required></td>
                        </tr>
                        <tr>
                            <th><?php _e( 'سقف مجموع پرداخت (تومان)', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="enable_limit_amount" value="1" <?php checked( $edit_card ? $edit_card->enable_limit_amount : 1 ); ?>> <?php _e( 'فعال', 'wc-bank-receipt' ); ?></label>
                                <br><input type="number" name="limit_amount" min="0" value="<?php echo $edit_card ? esc_attr( $edit_card->limit_amount ) : 0; ?>" style="margin-top:6px;max-width:160px;">
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'سقف تعداد تراکنش', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="enable_max_transactions" value="1" <?php checked( $edit_card ? $edit_card->enable_max_transactions : 1 ); ?>> <?php _e( 'فعال', 'wc-bank-receipt' ); ?></label>
                                <br><input type="number" name="max_transactions" min="0" value="<?php echo $edit_card ? esc_attr( $edit_card->max_transactions ) : 0; ?>" style="margin-top:6px;max-width:120px;">
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'تاریخ انقضا', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="enable_expiry_date" value="1" <?php checked( $edit_card ? $edit_card->enable_expiry_date : 1 ); ?>> <?php _e( 'فعال', 'wc-bank-receipt' ); ?></label>
                                <br><input type="date" name="expiry_date" value="<?php echo $edit_card ? esc_attr( $edit_card->expiry_date ) : ''; ?>" style="margin-top:6px;">
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'کارت فعال باشد', 'wc-bank-receipt' ); ?></th>
                            <td><input type="checkbox" name="is_active" value="1" <?php checked( $edit_card ? $edit_card->is_active : 1 ); ?>></td>
                        </tr>
                    </table>
                    <div style="margin-top:16px;display:flex;gap:10px;">
                        <?php submit_button( $edit_card ? __( 'به‌روزرسانی کارت', 'wc-bank-receipt' ) : __( 'افزودن کارت', 'wc-bank-receipt' ), 'primary', 'save_card', false ); ?>
                        <?php if ( $edit_card ) : ?>
                            <a href="<?php echo esc_url( remove_query_arg( array( 'edit_card', '_wpnonce' ) ) ); ?>" class="button"><?php _e( 'انصراف', 'wc-bank-receipt' ); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Card List -->
            <div class="wcr-card">
                <h2><span class="dashicons dashicons-list-view"></span> <?php _e( 'لیست کارت‌ها', 'wc-bank-receipt' ); ?>
                    <small style="font-size:12px;font-weight:400;color:#90a4ae;margin-right:8px;"><?php _e( '(برای مرتب‌سازی بکشید)', 'wc-bank-receipt' ); ?></small>
                </h2>
                <table class="wcr-tbl" id="cards-list-table">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th><?php _e( 'نام', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'بانک', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'شماره کارت', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'مصرف / سقف', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'تراکنش', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'انقضا', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'وضعیت', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'عملیات', 'wc-bank-receipt' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="card-sortable">
                    <?php if ( $cards ) : foreach ( $cards as $c ) :
                        $rem_amount = $c->limit_amount - $c->used_amount;
                        $rem_tx     = $c->max_transactions - $c->used_transactions;
                        $expired    = $c->enable_expiry_date && $c->expiry_date && $c->expiry_date < $today;
                        $lim_hit    = $c->enable_limit_amount && $rem_amount <= 0;
                        $tx_hit     = $c->enable_max_transactions && $rem_tx <= 0;
                        $active     = $c->is_active && ! $expired && ! $lim_hit && ! $tx_hit;
                        $reason_tip = '';
                        if ( ! $c->is_active )        $reason_tip = __( 'غیرفعال دستی', 'wc-bank-receipt' );
                        elseif ( $expired )            $reason_tip = __( 'منقضی شده',    'wc-bank-receipt' );
                        elseif ( $lim_hit )            $reason_tip = __( 'سقف مبلغ',     'wc-bank-receipt' );
                        elseif ( $tx_hit )             $reason_tip = __( 'سقف تراکنش',   'wc-bank-receipt' );
                    ?>
                        <tr data-card-id="<?php echo $c->id; ?>">
                            <td><span class="dashicons dashicons-menu wcr-drag"></span></td>
                            <td><strong><?php echo esc_html( $c->card_name ); ?></strong></td>
                            <td><?php echo esc_html( $c->bank_name ); ?></td>
                            <td><span class="wcr-bank-chip"><?php echo esc_html( $c->card_number ); ?></span></td>
                            <td>
                                <?php if ( $c->enable_limit_amount ) : ?>
                                    <span title="<?php esc_attr_e( 'مصرف‌شده', 'wc-bank-receipt' ); ?>"><?php echo number_format( $c->used_amount ); ?></span>
                                    / <?php echo number_format( $c->limit_amount ); ?>
                                <?php else : ?>
                                    <span class="wcr-badge muted"><?php _e( 'نامحدود', 'wc-bank-receipt' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $c->used_transactions; ?>
                                <?php echo $c->enable_max_transactions ? ' / ' . $c->max_transactions : ''; ?>
                            </td>
                            <td><?php echo ( $c->enable_expiry_date && $c->expiry_date ) ? $c->expiry_date : '<span class="wcr-badge muted">' . __( 'ندارد', 'wc-bank-receipt' ) . '</span>'; ?></td>
                            <td>
                                <?php if ( $active ) : ?>
                                    <span class="wcr-badge ok">✓ <?php _e( 'فعال', 'wc-bank-receipt' ); ?></span>
                                <?php else : ?>
                                    <span class="wcr-badge err" title="<?php echo esc_attr( $reason_tip ); ?>">✗ <?php _e( 'غیرفعال', 'wc-bank-receipt' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( 'edit_card', $c->id ) ); ?>" class="wcr-btn wcr-btn-ghost wcr-btn-sm"><?php _e( 'ویرایش', 'wc-bank-receipt' ); ?></a>
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'delete_card', $c->id ), 'delete_card_' . $c->id ) ); ?>"
                                   class="wcr-btn wcr-btn-err wcr-btn-sm"
                                   onclick="return confirm('<?php esc_attr_e( 'آیا مطمئن هستید؟', 'wc-bank-receipt' ); ?>');"><?php _e( 'حذف', 'wc-bank-receipt' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="9" style="text-align:center;padding:30px;color:#90a4ae;"><?php _e( 'هنوز کارتی تعریف نشده است.', 'wc-bank-receipt' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(function($){
            $('#card-sortable').sortable({
                handle:'.wcr-drag',
                placeholder:'ui-state-highlight',
                update:function(){
                    var order = [];
                    $('#card-sortable tr').each(function(){ order.push($(this).data('card-id')); });
                    $('#card-sortable').sortable('disable');
                    $.post(ajaxurl,{
                        action:'wc_receipt_reorder_cards',
                        order:order,
                        nonce:'<?php echo wp_create_nonce("reorder_cards_nonce"); ?>'
                    },function(r){
                        if(r.success) location.reload();
                        else alert('<?php _e("خطا در ذخیره ترتیب.","wc-bank-receipt"); ?>');
                    }).always(function(){ $('#card-sortable').sortable('enable'); });
                }
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // GET ACTIVE CARD / UPDATE USAGE
    // =========================================================================

    /** Single source of truth for “is this card usable right now?” */
    public function card_is_usable( $card ) {
        if ( ! $card ) return false;
        if ( empty( $card->is_active ) ) return false;
        if ( ! empty( $card->enable_limit_amount ) && ( (int) $card->limit_amount - (int) $card->used_amount ) <= 0 ) return false;
        if ( ! empty( $card->enable_max_transactions ) && ( (int) $card->max_transactions - (int) $card->used_transactions ) <= 0 ) return false;
        if ( ! empty( $card->enable_expiry_date ) && ! empty( $card->expiry_date ) && $card->expiry_date < current_time( 'Y-m-d' ) ) return false;
        return true;
    }

    /**
     * First usable card by priority, or false.
     * It never falls back to an expired / disabled / exhausted card, so the shop
     * can never publish a collection account that must not receive money.
     */
    private function get_active_card() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) return false;
        $cards = $wpdb->get_results( "SELECT * FROM $table WHERE is_active=1 ORDER BY priority ASC, id ASC" );
        foreach ( (array) $cards as $card ) {
            if ( $this->card_is_usable( $card ) ) return $card;
        }
        return false;
    }

    private function get_card_by_id( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
    }

    /**
     * Amount recorded against a card limit: whole currency units, rounded once.
     * Shops with a decimal currency can scale it with the wc_receipt_card_charge_amount filter.
     */
    public function amount_for_card( $order ) {
        return (int) round( (float) $order->get_total() );
    }

    private function update_card_usage( $card_id, $amount ) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET used_amount=used_amount+%d, used_transactions=used_transactions+1 WHERE id=%d",
            (int) $amount, absint( $card_id )
        ) );
    }

    // =========================================================================
    // AJAX: REORDER CARDS
    // =========================================================================

    public function ajax_reorder_cards() {
        check_ajax_referer( 'reorder_cards_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $order = isset( $_POST['order'] ) ? array_map( 'absint', $_POST['order'] ) : array();
        if ( empty( $order ) ) wp_send_json_error();
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        foreach ( $order as $i => $cid ) {
            $wpdb->update( $table, array( 'priority' => $i ), array( 'id' => $cid ) );
        }
        wp_send_json_success();
    }

    // =========================================================================
    // FRONTEND HANDLING
    // =========================================================================

    public function maybe_handle_frontend() {
        if ( ! $this->woocommerce_is_available() ) return;

        // handle_upload() validates the order-scoped nonce, ownership and every
        // precondition itself, so accepting the POST from any page is safe and
        // also lets the [wc_bank_receipt] shortcode work on custom pages.
        if ( isset( $_POST['submit_receipt'] ) ) {
            $this->handle_upload();
        }

        /*
         * The hooks stay registered even when the page content also contains the
         * shortcode: whether a page/block template really executes that content is not
         * something this plugin can know, so suppressing the automatic display on the
         * suspicion that the shortcode will render could lose the panel entirely.
         * Duplicates are instead prevented after the fact — the first path to render an
         * order marks it (see $auto_rendered_orders) and the other one skips.
         */
        if ( is_wc_endpoint_url( 'order-received' ) ) {
            // Fires on the classic thank-you template and on the block-based order
            // confirmation (its "additional information" block runs this hook too).
            add_action( 'woocommerce_thankyou_' . self::GATEWAY_ID, array( $this, 'display_receipt_form' ) );
        }
        if ( is_wc_endpoint_url( 'view-order' ) ) {
            add_action( 'woocommerce_view_order', array( $this, 'display_receipt_form' ) );
        }
    }

    /**
     * Is the current visitor allowed to see (and act on) this order’s receipt panel?
     *
     * One rule for every path: manager capability, order ownership, or the order key
     * of *this* order. An order id on its own is never authorization, and the same
     * helper is used by the automatic display, the shortcode, the widget, the upload
     * POST and the receipt viewer.
     */
    private function visitor_may_view_order( $order ) {
        if ( ! $order instanceof WC_Order ) return false;
        if ( current_user_can( self::CAP_MANAGE ) ) return true;
        $user_id = get_current_user_id();
        if ( $user_id && (int) $order->get_customer_id() === $user_id ) return true;
        return $this->request_has_valid_order_key( $order );
    }

    /**
     * Layer A — the single business decision behind the front-end panel.
     *
     * Answers “may this visitor see this order’s receipt panel, and what is the
     * current state?” and gathers every value the panel needs. It produces no HTML
     * and echoes nothing, so the automatic hooks, the shortcode and the Elementor
     * widget can never drift apart, and no display path can skip a check.
     *
     * @param WC_Order|int $order
     * @param array        $args {
     *     source: string, notice: string,
     *     style:  string  a preset slug for this panel only (shortcode `style=`),
     *     tokens: array   per-instance design tokens (Elementor widget controls)
     * }
     * @return array
     */
    private function receipt_panel_context( $order, $args = array() ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( absint( $order ) );
        }

        $args = wp_parse_args( $args, array( 'source' => 'auto', 'notice' => '', 'style' => '', 'tokens' => array() ) );

        /*
         * The appearance layer is resolved here — once per panel — so the three display
         * methods render the same component with the same values and no path can skip
         * it. `style` is a preset slug and `tokens` are per-instance overrides; both are
         * validated inside WCR_Design_Tokens, which stores only what it recognises and
         * never emits a value that is not a known token.
         */
        $instance = array(
            'preset' => is_scalar( $args['style'] ) ? (string) $args['style'] : '',
            'tokens' => is_array( $args['tokens'] ) ? $args['tokens'] : array(),
        );
        $decl = class_exists( 'WCR_Design_Tokens' )
            ? WCR_Design_Tokens::declarations( WCR_Design_Tokens::resolve( $instance ) )
            : '';

        $ctx = array(
            'order'         => null,
            'source'        => $args['source'],
            'notice'        => (string) $args['notice'],
            'decl'          => $decl,
            'instance'      => $instance,
            'may_view'      => false,
            'is_gateway'    => false,
            'has_receipt'   => false,
            'state'         => '',
            'reject_reason' => '',
            'card'          => null,
            'remaining'     => 0,
            'total'         => 0,
            'progress'      => 0,
            'unlimited'     => true,
            'closed'        => false,
            'show_upload'   => false,
            'accept'        => '',
            'ext_labels'    => '',
            'max_kb'        => 0,
        );

        if ( ! $order instanceof WC_Order ) return $ctx;

        $ctx['order']    = $order;
        $ctx['may_view'] = $this->visitor_may_view_order( $order );

        // The panel describes a card-to-card payment. Any other gateway gets nothing:
        // showing a bank card to a credit-card customer is noise, and the panel is
        // never a way to read another order’s data.
        $ctx['is_gateway'] = ( $order->get_payment_method() === self::GATEWAY_ID );
        if ( ! $ctx['may_view'] || ! $ctx['is_gateway'] ) {
            return $ctx;
        }

        $ctx['has_receipt']   = ( '' !== $this->receipt_file_name( $order ) );
        $ctx['state']         = (string) $order->get_meta( self::META_STATE );
        $ctx['reject_reason'] = (string) $order->get_meta( self::META_REJECT_REASON );
        $ctx['closed']        = in_array( $order->get_status(), array( 'failed', 'cancelled' ), true );

        // Merely reading the panel never mutates the order: the deadline is an
        // absolute timestamp and the scheduled job owns expiry.
        $expire_hours     = $this->expire_hours();
        $ctx['unlimited'] = ( $expire_hours <= 0 );
        $ctx['total']     = (int) round( $expire_hours * HOUR_IN_SECONDS );
        $ctx['remaining'] = $this->remaining_seconds( $order );
        if ( $ctx['total'] > 0 ) {
            $ctx['progress'] = (int) min( 100, max( 0, round( ( $ctx['remaining'] / $ctx['total'] ) * 100 ) ) );
        }

        // Use the card already assigned to this order (if any), else the active card.
        $assigned = absint( $order->get_meta( self::META_ASSIGNED_CARD ) );
        $card     = $assigned ? $this->get_card_by_id( $assigned ) : $this->get_active_card();
        if ( $card && ! $this->card_is_usable( $card ) ) {
            $card = $assigned ? $this->get_active_card() : $card; // never show a dead card
        }
        if ( ! $card && ! $ctx['has_receipt'] ) {
            $card = $this->get_active_card();
        }
        $ctx['card'] = $card;

        $ctx['show_upload'] = ( ( ! $ctx['has_receipt'] || 'rejected' === $ctx['state'] )
                                && ( $ctx['unlimited'] || $ctx['remaining'] > 0 ) );

        if ( $ctx['show_upload'] ) {
            $allowed           = $this->allowed_mimes();
            $ctx['accept']     = implode( ',', array_map( array( $this, 'mime_to_ext_dotted' ), $allowed ) );
            $ctx['ext_labels'] = implode( ', ', array_map( array( $this, 'mime_to_ext_upper' ), $allowed ) );
            $ctx['max_kb']     = absint( get_option( 'wc_receipt_max_file_size', 2048 ) );
        }

        return apply_filters( 'wcr_receipt_panel_context', $ctx, $order, $args );
    }

    /** @internal array_map helper for the `accept` attribute. */
    private function mime_to_ext_dotted( $mime ) {
        return '.' . $this->mime_to_ext( $mime );
    }

    /** @internal array_map helper for the human-readable format list. */
    private function mime_to_ext_upper( $mime ) {
        return strtoupper( $this->mime_to_ext( $mime ) );
    }

    /**
     * Guard for the automatic path: has this order’s panel already been printed in this
     * request? Marks it as printed when the answer is no.
     */
    private function panel_already_rendered( $order_id ) {
        if ( isset( self::$auto_rendered_orders[ $order_id ] ) ) return true;
        self::$auto_rendered_orders[ $order_id ] = true;
        return false;
    }

    /**
     * Read-only variant of the guard, for the explicit display methods (shortcode and
     * Elementor widget): “did the WooCommerce hook already print this order?”.
     *
     * @param int $order_id
     * @return bool
     */
    public function auto_panel_printed( $order_id ) {
        return isset( self::$auto_rendered_orders[ $order_id ] );
    }

    /**
     * Layer B — the only place the panel markup exists.
     *
     * Returns a fully escaped HTML string (never echoes), which is what lets the
     * three display methods share one implementation. Because the same string is
     * used everywhere, the markup carries no element ids, no inline event handlers
     * and no inline script: two panels on one page are two independent instances.
     *
     * Fires `wcr_receipt_panel_html` so a theme/plugin can restyle or replace the
     * markup without touching the logic above.
     */
    public function receipt_panel_html( $order, $args = array() ) {
        $ctx = $this->receipt_panel_context( $order, $args );

        if ( ! $ctx['order'] instanceof WC_Order ) return '';
        $order = $ctx['order'];

        $notice = $ctx['notice'];

        /*
         * The panel's design tokens ride on its own root element (`style="--wcrf-…"`),
         * never on `:root`/`body` and never as a `<style>` rule, so two panels with
         * different presets on one page keep their own look and no other element on
         * the page can be reached by the declarations.
         */
        $style = ( '' !== $ctx['decl'] ) ? ' style="' . esc_attr( $ctx['decl'] ) . '"' : '';

        // Denied (or wrong gateway): a neutral notice, and never a byte of order data.
        if ( ! $ctx['may_view'] || ! $ctx['is_gateway'] ) {
            if ( '' === $notice ) {
                $notice = __( 'اطلاعات این سفارش برای شما قابل نمایش نیست.', 'wc-bank-receipt' );
            }
            $denied = '<div class="wcrf-panel" dir="rtl"' . $style . '><div class="wcrf-notice">' . esc_html( $notice ) . '</div></div>';
            return (string) apply_filters( 'wcr_receipt_panel_denied_html', $denied, $order, $ctx );
        }

        $order_id = $order->get_id();

        ob_start();
        ?>
        <div class="wcrf-panel" dir="rtl"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above with esc_attr(). ?>
             data-wcr-order="<?php echo esc_attr( $order_id ); ?>" data-wcr-source="<?php echo esc_attr( $ctx['source'] ); ?>">
        <?php
        if ( '' !== $notice ) {
            echo '<div class="wcrf-notice">' . esc_html( $notice ) . '</div>';
        }

        if ( $ctx['closed'] ) {
            echo '<div class="wcrf-expired">⚠️ ' . esc_html__( 'مهلت ارسال فیش به پایان رسیده یا سفارش لغو شده است.', 'wc-bank-receipt' ) . '</div>';
            echo '</div>';
            return (string) apply_filters( 'wcr_receipt_panel_html', ob_get_clean(), $order, $ctx );
        }

        // One credit-card/BACS notice block per request, whatever rendered the panel.
        if ( ! self::$frontend_notices_printed && function_exists( 'wc_print_notices' ) ) {
            self::$frontend_notices_printed = true;
            wc_print_notices();
        }

        if ( 'approved' === $ctx['state'] ) {
            ?>
            <div class="wcrf-status-box approved">
                <div class="wcrf-status-title">✅ <?php esc_html_e( 'واریز تأیید شد', 'wc-bank-receipt' ); ?></div>
                <div class="wcrf-status-note"><?php esc_html_e( 'پرداخت شما تأیید و سفارشتان در حال پردازش است.', 'wc-bank-receipt' ); ?></div>
            </div>
            </div>
            <?php
            return (string) apply_filters( 'wcr_receipt_panel_html', ob_get_clean(), $order, $ctx );
        }

        if ( $ctx['card'] ) {
            echo $this->bank_card_markup( $ctx['card'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
        } else {
            echo '<div class="wcrf-no-card">⚠️ ' . esc_html__( 'در حال حاضر حساب بانکی فعالی برای واریز وجود ندارد. با مدیریت تماس بگیرید.', 'wc-bank-receipt' ) . '</div>';
        }

        if ( $ctx['remaining'] > 0 ) {
            /*
             * The initial countdown is rendered server-side instead of an ASCII "--:--:--"
             * placeholder: the number is real, so there is no empty flash before the script
             * ticks, and nothing in the markup can be rewritten by a content filter.
             * (WordPress' texturizer turns `--` into an en dash, which used to make the panel
             * markup differ between the classic and the block order-confirmation template.)
             */
            $secs_left = max( 0, (int) $ctx['remaining'] );
            $clock     = sprintf( '%02d:%02d:%02d', intdiv( $secs_left, HOUR_IN_SECONDS ), intdiv( $secs_left % HOUR_IN_SECONDS, MINUTE_IN_SECONDS ), $secs_left % MINUTE_IN_SECONDS );
            ?>
            <div class="wcrf-timer-box" data-wcr-timer
                 data-remaining="<?php echo absint( $ctx['remaining'] ); ?>"
                 data-total="<?php echo absint( $ctx['total'] ); ?>">
                <div class="wcrf-timer-lbl"><?php esc_html_e( 'فرصت باقی‌مانده:', 'wc-bank-receipt' ); ?></div>
                <strong class="wcrf-timer-val" aria-live="off"><?php echo esc_html( $clock ); ?></strong>
                <div class="wcrf-progress"><div class="wcrf-progress-fill" style="width:<?php echo absint( $ctx['progress'] ); ?>%"></div></div>
            </div>
            <?php
        }

        if ( $ctx['has_receipt'] ) {
            if ( 'rejected' === $ctx['state'] ) {
                echo '<div class="wcrf-status-box rejected">';
                echo '<div class="wcrf-status-title">❌ ' . esc_html__( 'فیش رد شد', 'wc-bank-receipt' ) . '</div>';
                if ( '' !== $ctx['reject_reason'] ) {
                    echo '<div class="wcrf-reject-reason">' . esc_html( $ctx['reject_reason'] ) . '</div>';
                }
                $this->receipt_view_affordance( $order, __( 'مشاهده فیش قبلی', 'wc-bank-receipt' ) );
                echo '</div>';
            } else {
                echo '<div class="wcrf-status-box pending">';
                echo '<div class="wcrf-status-title">⏳ ' . esc_html__( 'در انتظار بررسی مدیریت', 'wc-bank-receipt' ) . '</div>';
                $this->receipt_view_affordance( $order, __( 'مشاهده فیش ارسالی', 'wc-bank-receipt' ) );
                echo '</div>';
            }
        }

        if ( $ctx['show_upload'] ) {
            ?>
            <form method="post" enctype="multipart/form-data" class="wcrf-form">
                <?php echo $this->drop_zone_markup( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
                <input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">
                <?php
                /*
                 * The nonce is printed by hand instead of with wp_nonce_field(): that
                 * helper also emits id="receipt_nonce", which would be a duplicate id as
                 * soon as two panels share a page. The field name and the action are
                 * unchanged, so the POST contract stays exactly the same.
                 */
                ?>
                <input type="hidden" name="receipt_nonce" value="<?php echo esc_attr( wp_create_nonce( 'upload_receipt_' . $order_id ) ); ?>">
                <button type="submit" name="submit_receipt" class="wcrf-submit-btn">📤 <?php esc_html_e( 'ارسال فیش واریزی', 'wc-bank-receipt' ); ?></button>
            </form>
            <?php
        }

        echo '</div>'; // .wcrf-panel

        return (string) apply_filters( 'wcr_receipt_panel_html', ob_get_clean(), $order, $ctx );
    }

    /**
     * The bank-card block of the panel.
     *
     * Extracted into its own method so the «ظاهر و استایل» preview can show the *real*
     * component (with the real classes and therefore the real styling) instead of a
     * hand-written copy that would drift: one markup source for the live panel and the
     * preview. It owns no logic and prints nothing else.
     *
     * @param object $card Row of the cards table.
     * @return string
     */
    private function bank_card_markup( $card ) {
        ob_start();
        ?>
        <div class="wcrf-bank-card">
            <div class="bank-header">
                <div class="bank-name">🏦 <?php echo esc_html( $card->bank_name ); ?></div>
                <div class="card-logo" aria-hidden="true">💳</div>
            </div>
            <div class="card-number" data-wcr-copy-source><?php echo esc_html( $card->card_number ); ?></div>
            <div class="card-footer">
                <div>
                    <div class="holder"><?php echo esc_html( $card->account_holder ); ?></div>
                    <?php if ( ! empty( $card->sheba ) ) : ?>
                        <div class="sheba">IR: <?php echo esc_html( $card->sheba ); ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="wcrf-copy-btn" data-wcr-copy="<?php echo esc_attr( $card->card_number ); ?>">
                    <?php esc_html_e( 'کپی شماره', 'wc-bank-receipt' ); ?>
                </button>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The upload zone of the panel: drop area, format hint, file input and the preview
     * placeholders the script fills in. The input covers the whole zone, which is what
     * keeps the form usable with JavaScript disabled.
     *
     * @param array $ctx Panel context (or an equivalent array when previewing).
     * @return string
     */
    private function drop_zone_markup( $ctx ) {
        ob_start();
        ?>
        <div class="wcrf-drop-zone">
            <div class="wcrf-drop-icon" aria-hidden="true">📂</div>
            <div class="wcrf-drop-text"><?php esc_html_e( 'فایل را اینجا رها کنید یا برای انتخاب کلیک کنید', 'wc-bank-receipt' ); ?></div>
            <div class="wcrf-drop-hint"><?php printf( esc_html__( 'فرمت‌های مجاز: %s — حداکثر %s کیلوبایت', 'wc-bank-receipt' ), esc_html( $ctx['ext_labels'] ), esc_html( number_format_i18n( $ctx['max_kb'] ) ) ); ?></div>
            <input type="file" name="receipt_file" class="wcrf-file-input"
                   accept="<?php echo esc_attr( $ctx['accept'] ); ?>"
                   aria-label="<?php esc_attr_e( 'انتخاب فایل فیش', 'wc-bank-receipt' ); ?>" required>
            <div class="wcrf-preview-wrap" data-wcr-preview hidden>
                <img class="wcrf-preview-img" src="" alt="" data-wcr-preview-img>
                <span class="wcrf-file-name" data-wcr-file-name></span>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Layer C, method 1 of 3 — automatic display.
     *
     * Attached to `woocommerce_thankyou_{gateway}` and `woocommerce_view_order`, the
     * two hooks that run on both the classic and the block template. It only echoes
     * the shared renderer’s output, and only once per order per request, so a theme
     * that also embeds the shortcode never prints the panel twice.
     */
    public function display_receipt_form( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) return;
        if ( $this->panel_already_rendered( $order->get_id() ) ) return;

        $html = $this->receipt_panel_html( $order, array( 'source' => 'auto' ) );
        if ( '' === $html ) {
            unset( self::$auto_rendered_orders[ $order->get_id() ] );
            return;
        }

        self::enqueue_frontend_assets();
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the renderer.
    }

    // =========================================================================
    // RECEIPT STATE ENGINE
    //
    // A single place decides and performs every receipt/order transition. The
    // single, bulk, cron and AJAX paths all call apply_transition(), so
    // preconditions and side effects (card charge, log, history, SMS) can never
    // drift apart.
    //
    // Idempotency: each transition first claims a row in
    // {prefix}wc_bank_receipt_transitions, guarded by a UNIQUE key over
    // (order_id, receipt_uid, transition). A duplicate or concurrent request gets
    // a failed INSERT and is answered as “already done”, which is what stops a
    // second approval from charging a card twice.
    //
    // Receipt lifecycle:
    //   (no receipt) --upload--> pending_review --approve--> approved
    //                                      \--reject--> rejected --upload--> pending_review
    //   deadline passed while pending/rejected --expire--> failed
    //
    // Order status mapping (WooCommerce BACS flow):
    //   upload  → on-hold       approve → payment_complete() (processing/completed, date_paid set)
    //   reject  → pending       expire  → failed
    // =========================================================================

    /** MIME allow-list → safe file extension. */
    private function mime_to_ext( $mime ) {
        $map = array(
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'application/pdf' => 'pdf',
        );
        return $map[ $mime ] ?? '';
    }

    public function allowed_mimes() {
        $allowed = get_option( 'wc_receipt_allowed_mimes', array( 'image/jpeg', 'image/png', 'application/pdf' ) );
        return is_array( $allowed ) ? array_values( array_intersect( $allowed, array( 'image/jpeg', 'image/png', 'application/pdf' ) ) ) : array();
    }

    /** Stored receipt file name — new meta first, legacy public URL as fallback. */
    private function receipt_file_name( $order ) {
        $file = (string) $order->get_meta( self::META_FILE );
        if ( '' === $file ) {
            $url = (string) $order->get_meta( self::META_URL );
            if ( $url ) {
                $path = wp_parse_url( $url, PHP_URL_PATH );
                if ( $path ) {
                    $file = basename( $path );
                }
            }
        }
        return $file ? sanitize_file_name( wp_basename( $file ) ) : '';
    }

    /**
     * Meta filter selecting orders that reference a receipt.
     *
     * Both storage keys are accepted on purpose: this version records the file
     * name in `_wc_receipt_file`, while installs upgraded from earlier releases
     * only have the legacy public URL. Keying the gallery and the retention job
     * on a single key would make those receipts silently vanish from the admin
     * after an upgrade (and their files would never be cleaned up).
     */
    public static function receipt_meta_query() {
        return array(
            'relation' => 'OR',
            array( 'key' => self::META_FILE, 'compare' => 'EXISTS' ),
            array( 'key' => self::META_URL,  'compare' => 'EXISTS' ),
        );
    }

    public static function receipts_dir() {
        $base = wp_upload_dir();
        if ( ! empty( $base['error'] ) ) return '';
        return trailingslashit( $base['basedir'] ) . self::UPLOAD_DIR;
    }

    /**
     * Absolute path of the order’s receipt, resolved strictly inside the
     * trusted receipts folder (no URL string surgery, immune to home/site URL
     * differences, CDN rewrite rules or subdirectory installs).
     */
    public static function receipt_path( $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order );
        }
        if ( ! $order ) return '';
        $plugin = self::get_instance();
        $file   = $plugin->receipt_file_name( $order );
        $dir    = self::receipts_dir();
        if ( '' === $file || '' === $dir ) return '';

        $real_dir = realpath( $dir );
        $real     = realpath( $dir . '/' . $file );
        if ( ! $real_dir || ! $real ) return '';
        if ( strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) !== 0 ) return ''; // traversal / foreign file
        return $real;
    }

    /** Identity of the receipt *instance* a decision refers to. */
    private function receipt_uid( $order ) {
        $file = $this->receipt_file_name( $order );
        return $file ? hash( 'sha256', $file ) : '';
    }

    /** Absolute upload deadline (UTC timestamp), persisted per order. */
    private function deadline_for( $order ) {
        $deadline = (int) $order->get_meta( self::META_DEADLINE );
        if ( $deadline > 0 ) return $deadline;

        $hours  = $this->expire_hours();
        $state  = (string) $order->get_meta( self::META_STATE );
        $base   = 0;

        if ( 'rejected' === $state ) {
            // Legacy installs stored the rejection in local time; treat a value that
            // is still in the future as “now” rather than trusting a bad clock base.
            $legacy_rejected = (int) $order->get_meta( self::META_REJECTED_TIME );
            $base = ( $legacy_rejected > 0 && $legacy_rejected <= time() + DAY_IN_SECONDS ) ? $legacy_rejected : time();
        } elseif ( $order->get_date_created() ) {
            $base = (int) $order->get_date_created()->getTimestamp();
        } else {
            $base = time();
        }

        $deadline = $base + (int) round( $hours * HOUR_IN_SECONDS );
        $order->update_meta_data( self::META_DEADLINE, $deadline );
        $order->save();
        return $deadline;
    }

    private function expire_hours() {
        $hours = (float) get_option( 'wc_receipt_expire_hours', 2 );
        return $hours > 0 ? $hours : 0.0;
    }

    public function deadline_passed( $order ) {
        $hours = $this->expire_hours();
        if ( $hours <= 0 ) return false; // 0 = no deadline configured
        return time() > $this->deadline_for( $order );
    }

    private function remaining_seconds( $order ) {
        $hours = $this->expire_hours();
        if ( $hours <= 0 ) return -1; // unlimited
        return max( 0, $this->deadline_for( $order ) - time() );
    }

    private function history_append( $order, $action, $extra = array() ) {
        $history = $order->get_meta( self::META_HISTORY );
        if ( ! is_array( $history ) ) $history = array();
        $history[] = array_merge(
            array( 'action' => $action, 'time' => time(), 'user' => get_current_user_id() ),
            $extra
        );
        if ( count( $history ) > 50 ) $history = array_slice( $history, -50 );
        $order->update_meta_data( self::META_HISTORY, $history );
    }

    /**
     * Atomic claim. Returns false when this exact transition already happened
     * (duplicate click, retried request or two concurrent requests).
     */
    private function claim_transition( $order_id, $receipt_uid, $transition, $card_id = 0, $amount = 0 ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::DB_PREFIX . 'transitions';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            $this->create_tables();
        }
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (order_id, receipt_uid, transition, card_id, amount, user_id, created_at)
             VALUES (%d, %s, %s, %d, %d, %d, %s)",
            $order_id, substr( (string) $receipt_uid, 0, 64 ), $transition, absint( $card_id ), (int) $amount, get_current_user_id(), current_time( 'mysql' )
        ) );
        return (int) $inserted === 1;
    }

    /**
     * Claim key that makes the two decisions mutually exclusive per receipt instance.
     *
     * approve and reject are two *different* transition values, so a ledger keyed on
     * (order, receipt, transition) alone lets both of them through: the state gate of
     * apply_transition() reads order meta, and two concurrent requests each read it
     * before either writes. Both decisions therefore share one claim per receipt
     * instance. The lock is deliberately scoped to the instance (not to the order), so
     * a receipt that is rejected and uploaded again — a new instance, new uid — can
     * still be decided normally.
     */
    private function decision_claim_key( $transition, $uid ) {
        if ( in_array( $transition, array( 'approve', 'reject' ), true ) ) {
            return array( 'decision', $uid );
        }
        return array( $transition, $uid );
    }

    /**
     * Post-claim re-read of the authoritative receipt state.
     *
     * The precondition switch in apply_transition() runs on data loaded when the
     * request started. Between that read and the claim, another request may already
     * have decided the receipt (approve vs reject) or replaced the file (new upload).
     * The claim is the point where exactly one request wins, so the winner re-validates
     * against a forced fresh read before it touches order state. This also covers pairs
     * that legitimately do not share a claim key — upload, for instance, must not be
     * able to replace a receipt that was approved a moment ago.
     *
     * @return bool True when the transition may still be applied to this receipt.
     */
    private function claim_matches_current_state( $order, $transition, $uid ) {
        $order->read_meta_data( true ); // authoritative state straight from the database
        $state = (string) $order->get_meta( self::META_STATE );

        switch ( $transition ) {
            case 'approve':
            case 'reject':
                return 'pending_review' === $state && '' !== $uid && $this->receipt_uid( $order ) === $uid;
            case 'upload':
                return ! in_array( $state, array( 'approved', 'pending_review' ), true );
            case 'expire':
                return 'approved' !== $state;
        }
        return true;
    }

    private function transition_result( $ok, $code, $message ) {
        return array( 'ok' => (bool) $ok, 'code' => $code, 'message' => $message );
    }

    /**
     * Apply a receipt/order transition.
     *
     * @param WC_Order|int $order      Order object or id.
     * @param string       $transition upload|approve|reject|expire
     * @param array        $args       upload: file, card_id | reject: reason
     * @return array{ok:bool,code:string,message:string}
     */
    public function apply_transition( $order, $transition, $args = array() ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order );
        }
        if ( ! $order ) {
            return $this->transition_result( false, 'no_order', __( 'سفارش یافت نشد.', 'wc-bank-receipt' ) );
        }
        $order_id = $order->get_id();
        if ( $order->get_payment_method() !== self::GATEWAY_ID ) {
            return $this->transition_result( false, 'not_bacs', __( 'این سفارش با کارت به کارت پرداخت نشده است.', 'wc-bank-receipt' ) );
        }

        $state  = (string) $order->get_meta( self::META_STATE );
        $status = $order->get_status();
        $uid    = $this->receipt_uid( $order );
        $path   = self::receipt_path( $order );

        switch ( $transition ) {
            case 'upload':
                if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
                    return $this->transition_result( false, 'closed', __( 'مهلت ارسال فیش به پایان رسیده یا سفارش لغو شده است.', 'wc-bank-receipt' ) );
                }
                if ( 'approved' === $state ) {
                    return $this->transition_result( false, 'already_approved', __( 'فیش این سفارش قبلاً تأیید شده است.', 'wc-bank-receipt' ) );
                }
                if ( 'pending_review' === $state ) {
                    return $this->transition_result( false, 'under_review', __( 'فیش این سفارش در صف بررسی است.', 'wc-bank-receipt' ) );
                }
                if ( $this->deadline_passed( $order ) ) {
                    return $this->transition_result( false, 'expired', __( 'مهلت ارسال فیش به پایان رسیده است.', 'wc-bank-receipt' ) );
                }
                $uid = (string) ( $args['uid'] ?? '' );
                break;

            case 'approve':
            case 'reject':
                if ( ! $uid || ! $path ) {
                    return $this->transition_result( false, 'no_receipt', __( 'فیشی برای این سفارش ثبت نشده است.', 'wc-bank-receipt' ) );
                }
                if ( 'pending_review' !== $state ) {
                    return $this->transition_result( false, 'bad_state', __( 'این فیش در وضعیت «در انتظار بررسی» نیست.', 'wc-bank-receipt' ) );
                }
                break;

            case 'expire':
                if ( 'approved' === $state ) {
                    return $this->transition_result( false, 'approved', __( 'فیش تأییدشده منقضی نمی‌شود.', 'wc-bank-receipt' ) );
                }
                if ( 'pending' !== $status ) {
                    return $this->transition_result( false, 'not_pending', __( 'سفارش در وضعیت انتظار پرداخت نیست.', 'wc-bank-receipt' ) );
                }
                if ( ! $this->deadline_passed( $order ) ) {
                    return $this->transition_result( false, 'not_due', __( 'مهلت این سفارش هنوز تمام نشده است.', 'wc-bank-receipt' ) );
                }
                $uid = '';
                break;

            default:
                return $this->transition_result( false, 'unknown_transition', __( 'عملیات ناشناخته.', 'wc-bank-receipt' ) );
        }

        // ---- idempotency gate -------------------------------------------------
        $amount = isset( $args['amount'] ) ? (int) $args['amount'] : (int) round( (float) $order->get_total() );
        $card   = isset( $args['card_id'] ) ? absint( $args['card_id'] ) : absint( $order->get_meta( self::META_ASSIGNED_CARD ) );
        list( $claim_key, $claim_uid ) = $this->decision_claim_key( $transition, $uid );
        if ( ! $this->claim_transition( $order_id, $claim_uid, $claim_key, $card, $amount ) ) {
            return $this->transition_result( false, 'duplicate', __( 'این عملیات قبلاً برای همین فیش انجام شده است.', 'wc-bank-receipt' ) );
        }
        // Keep the audit ledger naming the real decision, not just the lock.
        if ( $claim_key !== $transition ) {
            $this->claim_transition( $order_id, $claim_uid, $transition, $card, $amount );
        }
        if ( ! $this->claim_matches_current_state( $order, $transition, $uid ) ) {
            return $this->transition_result( false, 'stale_state', __( 'این فیش همین حالا بررسی شده است؛ برای مشاهده وضعیت جدید صفحه را دوباره باز کنید.', 'wc-bank-receipt' ) );
        }

        // ---- effects ---------------------------------------------------------
        switch ( $transition ) {
            case 'upload':
                // Defence in depth: the name is reduced to a single base name, so a
                // caller cannot smuggle a path into order meta.
                $file = sanitize_file_name( wp_basename( (string) ( $args['file'] ?? '' ) ) );
                if ( '' === $file ) {
                    return $this->transition_result( false, 'no_file', __( 'فایل فیش یافت نشد.', 'wc-bank-receipt' ) );
                }
                $base = wp_upload_dir();
                $order->update_meta_data( self::META_FILE, $file );
                $order->update_meta_data( self::META_URL, trailingslashit( $base['baseurl'] ) . self::UPLOAD_DIR . '/' . $file );
                $order->update_meta_data( self::META_STATE, 'pending_review' );
                $order->update_meta_data( self::META_REMINDER_SENT, 0 );
                $order->update_meta_data( self::META_UPLOADED, time() );
                $order->update_meta_data( self::META_DEADLINE, time() + (int) round( $this->expire_hours() * HOUR_IN_SECONDS ) );
                $order->delete_meta_data( self::META_REJECT_REASON ); // previous reason moves to history
                if ( $card ) {
                    $order->update_meta_data( self::META_ASSIGNED_CARD, $card );
                }
                $this->history_append( $order, 'uploaded', array( 'file' => $file ) );
                $order->save();

                if ( ! $this->move_order_to( $order, 'on-hold', __( 'فیش بانکی آپلود شد.', 'wc-bank-receipt' ) ) ) {
                    return $this->transition_result( false, 'status_failed', __( 'فیش ذخیره شد اما تغییر وضعیت سفارش انجام نشد.', 'wc-bank-receipt' ) );
                }
                $this->log_receipt_action( $order_id, 'uploaded' );
                $this->send_admin_upload_sms( $order_id );
                return $this->transition_result( true, 'uploaded', __( 'فیش شما با موفقیت ارسال شد و در صف بررسی است.', 'wc-bank-receipt' ) );

            case 'approve':
                $order->update_meta_data( self::META_STATE, 'approved' );
                $order->delete_meta_data( self::META_REJECT_REASON );
                $this->history_append( $order, 'approved' );
                $order->save(); // meta BEFORE status so every hook sees the final state

                $charged = null;
                if ( $card ) {
                    $charged = $this->charge_card_once( $order, $card, $amount, $uid );
                }

                // Standard WooCommerce payment cycle: sets date_paid, fires
                // woocommerce_payment_complete and lets WC choose processing/completed.
                $order->payment_complete();
                $order->save();
                if ( ! $this->order_reached_paid_state( $order ) ) {
                    $this->log_receipt_action( $order_id, 'approve_status_failed', sprintf( 'status=%s paid=%s', $order->get_status(), $order->get_date_paid() ? 'yes' : 'no' ) );
                    return $this->transition_result( false, 'status_failed', __( 'فیش تأیید شد اما تکمیل پرداخت سفارش ناموفق بود؛ لطفاً وضعیت سفارش را بررسی کنید.', 'wc-bank-receipt' ) );
                }
                $this->log_receipt_action( $order_id, 'approved', $charged ? 'card#' . $card . ' amount=' . $charged : '' );
                return $this->transition_result( true, 'approved', __( 'فیش تأیید شد.', 'wc-bank-receipt' ) );

            case 'reject':
                $reason = isset( $args['reason'] ) ? sanitize_textarea_field( wp_unslash( $args['reason'] ) ) : '';
                $order->update_meta_data( self::META_STATE, 'rejected' );
                $order->update_meta_data( self::META_REJECT_REASON, $reason );
                $order->update_meta_data( self::META_REJECTED_TIME, time() );
                $order->update_meta_data( self::META_REMINDER_SENT, 0 );
                $order->update_meta_data( self::META_DEADLINE, time() + (int) round( $this->expire_hours() * HOUR_IN_SECONDS ) );
                $this->history_append( $order, 'rejected', array( 'reason' => $reason, 'file' => $this->receipt_file_name( $order ) ) );
                $order->save();

                if ( ! $this->move_order_to( $order, 'pending', __( 'فیش بانکی رد شد.', 'wc-bank-receipt' ) ) ) {
                    return $this->transition_result( false, 'status_failed', __( 'فیش رد شد اما تغییر وضعیت سفارش انجام نشد.', 'wc-bank-receipt' ) );
                }
                $this->log_receipt_action( $order_id, 'rejected', $reason );
                return $this->transition_result( true, 'rejected', __( 'فیش رد شد؛ مشتری می‌تواند فیش جدید ارسال کند.', 'wc-bank-receipt' ) );

            case 'expire':
                $this->history_append( $order, 'expired' );
                $order->save();
                if ( ! $this->move_order_to( $order, 'failed', __( 'پایان مهلت ارسال فیش.', 'wc-bank-receipt' ) ) ) {
                    return $this->transition_result( false, 'status_failed', __( 'مهلت تمام شد اما تغییر وضعیت سفارش انجام نشد.', 'wc-bank-receipt' ) );
                }
                $this->log_receipt_action( $order_id, 'expired' );
                return $this->transition_result( true, 'expired', __( 'مهلت ارسال فیش به پایان رسید.', 'wc-bank-receipt' ) );
        }

        return $this->transition_result( false, 'unknown_transition', __( 'عملیات ناشناخته.', 'wc-bank-receipt' ) );
    }

    /** Change the WooCommerce status and report honestly whether it took effect. */
    private function move_order_to( $order, $target, $note = '' ) {
        if ( $order->get_status() === $target ) return true;
        $order->update_status( $target, $note );
        if ( $order->get_status() === $target ) return true;
        $order->save();
        return $order->get_status() === $target;
    }

    private function order_reached_paid_state( $order ) {
        return in_array( $order->get_status(), array( 'processing', 'completed' ), true ) && (bool) $order->get_date_paid();
    }

    /**
     * Charge a card exactly once for this receipt instance.
     * Amounts are whole currency units (the card limits are integers too); the
     * filter below lets shops with decimal currencies scale the value.
     */
    private function charge_card_once( $order, $card_id, $amount, $uid ) {
        $amount = (int) apply_filters( 'wc_receipt_card_charge_amount', $amount, $order, $card_id );
        $claimed = $this->claim_transition( $order->get_id(), $uid, 'card_charge', $card_id, $amount );
        if ( ! $claimed ) return 0; // already charged for this receipt instance
        $this->update_card_usage( $card_id, $amount );
        return $amount;
    }

    // =========================================================================
    // UPLOAD FILTERS — every filter is gated by $receipt_upload_context so receipt
    // rules can never leak into other plugins’ uploads.
    // =========================================================================

    public function custom_upload_dir( $dir ) {
        if ( ! $this->receipt_upload_context ) return $dir;
        $dir['path']   = trailingslashit( $dir['basedir'] ) . self::UPLOAD_DIR;
        $dir['url']    = trailingslashit( $dir['baseurl'] ) . self::UPLOAD_DIR;
        $dir['subdir'] = '';
        return $dir;
    }

    public function restrict_upload_mimes( $mimes ) {
        if ( ! $this->receipt_upload_context ) return $mimes;
        $list = array();
        foreach ( $this->allowed_mimes() as $mime ) {
            $ext = $this->mime_to_ext( $mime );
            if ( $ext ) $list[ $ext ] = $mime;
        }
        return $list;
    }

    public function validate_upload( $file ) {
        if ( ! $this->receipt_upload_context ) return $file;
        if ( ! empty( $file['error'] ) ) return $file;

        $max = absint( get_option( 'wc_receipt_max_file_size', 2048 ) ) * 1024;
        if ( $max > 0 && (int) $file['size'] > $max ) {
            $file['error'] = sprintf( __( 'حجم فایل بیش از حد مجاز (%s KB) است.', 'wc-bank-receipt' ), number_format_i18n( $max / 1024 ) );
            return $file;
        }
        $mime = $this->detect_upload_mime( $file['tmp_name'] );
        if ( '' === $mime || ! in_array( $mime, $this->allowed_mimes(), true ) ) {
            $file['error'] = __( 'نوع واقعی فایل مجاز نیست یا قابل بررسی امن نیست.', 'wc-bank-receipt' );
        }
        return $file;
    }

    /** Real MIME type from file content; empty string when it cannot be verified. */
    private function detect_upload_mime( $path ) {
        if ( ! function_exists( 'finfo_open' ) || ! is_file( $path ) ) {
            return ''; // cannot verify safely → caller rejects the upload
        }
        $fi   = finfo_open( FILEINFO_MIME_TYPE );
        $mime = $fi ? finfo_file( $fi, $path ) : '';
        if ( $fi ) finfo_close( $fi );
        return is_string( $mime ) ? strtolower( trim( $mime ) ) : '';
    }

    // =========================================================================
    // HANDLE UPLOAD
    //
    // Authorization: logged-in owner, or a guest holding the order key of THAT
    // order. The nonce is bound to the order id, and the target order always comes
    // from the request while the permitted order comes from the session proof —
    // swapping order_id can therefore never reach somebody else’s order.
    // =========================================================================

    /** May the current visitor see/change this order’s receipt? */
    private function current_visitor_may_manage_receipt( $order ) {
        // One authorization rule for every path — display, upload and viewer.
        return $this->visitor_may_view_order( $order );
    }

    private function request_has_valid_order_key( $order ) {
        if ( ! $order instanceof WC_Order ) return false;
        $expected = (string) $order->get_order_key();
        if ( '' === $expected ) return false;
        foreach ( array( 'key', 'order_key' ) as $param ) {
            if ( empty( $_REQUEST[ $param ] ) ) continue;
            $given = sanitize_text_field( wp_unslash( $_REQUEST[ $param ] ) );
            if ( hash_equals( $expected, $given ) ) return true;
        }
        return false;
    }

    private function upload_error( $message, $order = null ) {
        wc_add_notice( $message, 'error' );
        if ( $order instanceof WC_Order ) {
            $this->log_receipt_action( $order->get_id(), 'upload_rejected', $message );
        }
        return false;
    }

    public function handle_upload() {
        if ( ! $this->woocommerce_is_available() ) return false;

        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        $nonce = isset( $_POST['receipt_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['receipt_nonce'] ) ) : '';
        if ( ! $order_id || ! $nonce || ! wp_verify_nonce( $nonce, 'upload_receipt_' . $order_id ) ) {
            return $this->upload_error( __( 'نشست شما منقضی شده است؛ لطفاً صفحه را دوباره باز کنید.', 'wc-bank-receipt' ) );
        }
        if ( ! $order ) {
            return $this->upload_error( __( 'سفارش یافت نشد.', 'wc-bank-receipt' ) );
        }
        if ( ! $this->current_visitor_may_manage_receipt( $order ) ) {
            return $this->upload_error( __( 'دسترسی غیرمجاز به این سفارش.', 'wc-bank-receipt' ), $order );
        }
        if ( $order->get_payment_method() !== self::GATEWAY_ID ) {
            return $this->upload_error( __( 'این سفارش با کارت به کارت پرداخت نشده است.', 'wc-bank-receipt' ), $order );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = isset( $_FILES['receipt_file'] ) ? $_FILES['receipt_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! is_array( $file ) || ! isset( $file['error'] ) || (int) $file['error'] !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
            return $this->upload_error( __( 'خطا در آپلود فایل. لطفاً دوباره تلاش کنید.', 'wc-bank-receipt' ), $order );
        }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            return $this->upload_error( __( 'فایل ارسالی معتبر نیست.', 'wc-bank-receipt' ), $order );
        }

        $max = absint( get_option( 'wc_receipt_max_file_size', 2048 ) ) * 1024;
        if ( $max > 0 && (int) $file['size'] > $max ) {
            return $this->upload_error( sprintf( __( 'حجم فایل بیش از حد مجاز (%s KB) است.', 'wc-bank-receipt' ), number_format_i18n( $max / 1024 ) ), $order );
        }

        $mime = $this->detect_upload_mime( $file['tmp_name'] );
        $ext  = $mime ? $this->mime_to_ext( $mime ) : '';
        if ( '' === $mime ) {
            return $this->upload_error( __( 'بررسی امن نوع فایل روی این سرور ممکن نیست؛ ارسال فیش رد شد. با مدیر سایت تماس بگیرید.', 'wc-bank-receipt' ), $order );
        }
        if ( ! $ext || ! in_array( $mime, $this->allowed_mimes(), true ) ) {
            return $this->upload_error( __( 'فرمت فایل مجاز نیست.', 'wc-bank-receipt' ), $order );
        }

        // Name and extension come from the verified content type — never from user input.
        $file['name'] = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
        $file['type'] = $mime;

        $this->create_protected_directory();

        $this->receipt_upload_context = true;
        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
        $this->receipt_upload_context = false;

        if ( ! empty( $upload['error'] ) ) {
            return $this->upload_error( $upload['error'], $order );
        }

        $card = $this->get_active_card();
        $card_id = $card ? (int) $card->id : 0;

        $result = $this->apply_transition(
            $order,
            'upload',
            array(
                // Store the file *name* only: an absolute server path in order meta
                // leaks the install layout and breaks as soon as the site moves.
                'file'    => wp_basename( $upload['file'] ),
                'uid'     => hash( 'sha256', (string) $upload['file'] ),
                'card_id' => $card_id,
            )
        );

        if ( empty( $result['ok'] ) ) {
            // No half-stored state: drop the fresh file and keep the previous one intact.
            if ( ! empty( $upload['file'] ) && file_exists( $upload['file'] ) ) {
                @unlink( $upload['file'] );
            }
            return $this->upload_error( $result['message'], $order );
        }

        wc_add_notice( $result['message'], 'success' );
        wp_safe_redirect( $this->order_page_url( $order ) );
        exit;
    }

    private function order_page_url( $order ) {
        if ( is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() ) {
            return wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
        }
        return $order->get_checkout_order_received_url();
    }

    // =========================================================================
    // SECURE RECEIPT VIEW — the only way a receipt is ever served.
    // Never redirects to a public file URL; authorizes the viewer (capability,
    // ownership or that order’s key) instead of trusting the nonce alone.
    // =========================================================================

    public function add_rewrite_endpoint() {
        add_rewrite_endpoint( 'wc_receipt_view', EP_ROOT );
    }

    /**
     * URL of the protected receipt viewer.
     * Guests receive the order key because that key is their only proof of ownership.
     */
    /**
     * A receipt viewer URL is a capability: whoever holds the URL can read the file.
     *
     * It is therefore only minted for a requester who is ALREADY authorized by role
     * or ownership, which means the URL never has to carry the order key (the guest
     * credential, valid for months) and a forwarded link is worthless to a stranger.
     *
     * Guests and other unauthorized visitors are not left without a way to see their
     * own receipt: {@see receipt_view_affordance()} embeds it inline in the order
     * panel they already opened using the order key.
     */
    public function receipt_view_url( $order ) {
        if ( ! $order instanceof WC_Order ) return '';

        $is_owner = ( is_user_logged_in() && get_current_user_id() > 0
                      && (int) $order->get_customer_id() === get_current_user_id() );
        if ( ! current_user_can( self::CAP_MANAGE ) && ! $is_owner ) return '';

        return add_query_arg(
            array(
                'wc_receipt_view' => $order->get_id(),
                'nonce'           => wp_create_nonce( 'view_receipt_' . $order->get_id() ),
            ),
            home_url( '/' )
        );
    }

    /**
     * Print the "view stored receipt" affordance for the customer panel: a
     * nonce-scoped link for authorized viewers, an inline (data URI) preview for
     * everyone else — so guests never obtain a shareable URL to the file.
     */
    private function receipt_view_affordance( $order, $label ) {
        $url = $this->receipt_view_url( $order );
        if ( '' !== $url ) {
            echo '<a href="' . esc_url( $url ) . '" class="wcrf-prev-link" target="_blank" rel="noopener noreferrer">👁 ' . esc_html( $label ) . '</a>';
            return;
        }
        $this->render_inline_receipt( $order, $label );
    }

    /**
     * Embed the stored receipt as a data URI. Returns false when the file is
     * missing, unreadable, larger than the configured upload limit, or of a type we
     * are not willing to hand to a browser — in that case the caller shows nothing
     * rather than a broken link.
     */
    private function render_inline_receipt( $order, $label ) {
        $path = self::receipt_path( $order );
        if ( '' === $path || ! is_readable( $path ) ) return false;

        $size = (int) @filesize( $path );
        $max  = max( 1, absint( get_option( 'wc_receipt_max_file_size', 2048 ) ) ) * 1024;
        if ( $size <= 0 || $size > $max ) return false;

        $mime = $this->detect_upload_mime( $path );
        if ( ! in_array( $mime, $this->allowed_mimes(), true ) ) return false;

        $bytes = @file_get_contents( $path );
        if ( false === $bytes ) return false;
        $data = 'data:' . $mime . ';base64,' . base64_encode( $bytes );

        if ( 'application/pdf' === $mime ) {
            echo '<a href="' . esc_attr( $data ) . '" download="' . esc_attr( basename( $path ) ) . '" class="wcrf-prev-link">📄 ' . esc_html( $label ) . '</a>';
        } else {
            echo '<a href="' . esc_attr( $data ) . '" target="_blank" rel="noopener noreferrer" class="wcrf-prev-link">'
                . '<img src="' . esc_attr( $data ) . '" alt="' . esc_attr( $label ) . '" class="wcrf-inline-receipt">'
                . '</a>';
        }
        return true;
    }

    private function deny_receipt_access( $message = '' ) {
        status_header( 403 );
        nocache_headers();
        wp_die(
            esc_html( $message ? $message : __( 'دسترسی غیرمجاز', 'wc-bank-receipt' ) ),
            esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ),
            array( 'response' => 403 )
        );
    }

    public function secure_receipt_view() {
        $view_id = get_query_var( 'wc_receipt_view' );
        if ( ! $view_id && isset( $_GET['wc_receipt_view'] ) ) {
            $view_id = absint( wp_unslash( $_GET['wc_receipt_view'] ) );
        }
        $view_id = absint( $view_id );
        if ( ! $view_id ) return;

        $order = wc_get_order( $view_id );
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

        if ( ! $order ) {
            $this->deny_receipt_access( __( 'سفارش یافت نشد.', 'wc-bank-receipt' ) );
        }
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'view_receipt_' . $view_id ) ) {
            $this->deny_receipt_access();
        }
        // A nonce is not an authorization: only a manager, or the customer who owns
        // the order, may stream the file. Guests reach their own receipt through the
        // inline preview in the order panel instead of a shareable URL.
        $is_owner = ( is_user_logged_in() && get_current_user_id() > 0
                      && (int) $order->get_customer_id() === get_current_user_id() );
        if ( ! current_user_can( self::CAP_MANAGE ) && ! $is_owner ) {
            $this->deny_receipt_access();
        }

        $path = self::receipt_path( $order );
        if ( ! $path ) {
            $this->deny_receipt_access( __( 'فیشی یافت نشد.', 'wc-bank-receipt' ) );
        }

        $mime = $this->detect_upload_mime( $path );
        $allowed = $this->allowed_mimes();
        if ( ! in_array( $mime, $allowed, true ) ) {
            // Anything unexpected is downloaded, never rendered inline.
            $mime       = 'application/octet-stream';
            $disposition = 'attachment';
        } else {
            $disposition = 'inline';
        }

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: ' . $disposition . '; filename="' . basename( $path ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; object-src 'none'" );
        header( 'Referrer-Policy: no-referrer' );
        readfile( $path );
        exit;
    }

    // =========================================================================
    // ADMIN ORDER SECTION
    // =========================================================================

    public function admin_order_section( $order ) {
        if ( ! current_user_can( self::CAP_MANAGE ) ) return;
        $file  = $this->receipt_file_name( $order );
        $path  = self::receipt_path( $order );
        if ( '' === $file ) return;
        $url    = self::receipt_path( $order ) ? $this->receipt_view_url( $order ) : '';
        $state  = $order->get_meta( self::META_STATE );
        $reason = $order->get_meta( self::META_REJECT_REASON );
        $nonce  = wp_create_nonce( self::NONCE_ADMIN );
        $oid    = $order->get_id();
        $is_img = (bool) preg_match( '/\.(jpe?g|png)$/i', $file );
        $missing = ( '' === $path );
        ?>

        <?php if ( $missing ) : ?>
            <p style="color:#c62828;font-size:13px;"><?php esc_html_e( 'فایل فیش روی سرور یافت نشد (ممکن است طبق سیاست نگهداری حذف شده باشد). متادیتای سفارش دست‌نخورده است.', 'wc-bank-receipt' ); ?></p>
        <?php endif; ?>
        <div class="wcr-order-box">
            <h4><span class="dashicons dashicons-money-alt"></span> <?php _e( 'بررسی فیش بانکی', 'wc-bank-receipt' ); ?></h4>

            <?php if ( $state === 'approved' ) : ?>
                <span class="wcr-badge ok" style="font-size:14px;padding:6px 14px;">✅ <?php _e( 'تأیید شده', 'wc-bank-receipt' ); ?></span>
            <?php elseif ( $state === 'rejected' ) : ?>
                <span class="wcr-badge err" style="font-size:14px;padding:6px 14px;">❌ <?php _e( 'رد شده', 'wc-bank-receipt' ); ?></span>
            <?php else : ?>
                <span class="wcr-badge warn" style="font-size:14px;padding:6px 14px;">⏳ <?php _e( 'در انتظار بررسی', 'wc-bank-receipt' ); ?></span>
            <?php endif; ?>

            <div class="wcr-order-actions" style="margin-top:14px;">
                <button type="button" class="wcr-btn wcr-btn-ghost" onclick="document.getElementById('wor-lb-<?php echo $oid; ?>').classList.add('open')">
                    👀 <?php _e( 'مشاهده فیش', 'wc-bank-receipt' ); ?>
                </button>
                <button type="button" class="wcr-btn wcr-btn-ok r-btn" data-action="approve" data-id="<?php echo $oid; ?>" <?php echo $state==='approved'?'disabled':''; ?>>
                    ✓ <?php _e( 'تأیید', 'wc-bank-receipt' ); ?>
                </button>
                <button type="button" class="wcr-btn wcr-btn-err r-btn" data-action="reject" data-id="<?php echo $oid; ?>" id="reject-btn-<?php echo $oid; ?>" <?php echo $state==='rejected'?'disabled':''; ?>>
                    ✗ <?php _e( 'رد فیش', 'wc-bank-receipt' ); ?>
                </button>
            </div>

            <div class="wcr-reason-wrap" id="reason-wrap-<?php echo $oid; ?>" style="display:<?php echo $state==='rejected'?'block':'none'; ?>">
                <label><?php _e( 'دلیل رد (نمایش به مشتری):', 'wc-bank-receipt' ); ?></label>
                <textarea id="reject-reason-<?php echo $oid; ?>" rows="2"><?php echo esc_textarea( $reason ); ?></textarea>
            </div>
        </div>

        <!-- Lightbox -->
        <div class="wcr-lightbox" id="wor-lb-<?php echo $oid; ?>" onclick="this.classList.remove('open')">
            <button class="wcr-lb-close" onclick="document.getElementById('wor-lb-<?php echo $oid; ?>').classList.remove('open')">✕</button>
            <?php if ( $missing ) : ?>
                <div style="background:#fff;padding:30px;border-radius:10px;text-align:center;">
                    <div style="font-size:60px;">📄</div>
                    <p><?php esc_html_e( 'فایل فیش موجود نیست.', 'wc-bank-receipt' ); ?></p>
                </div>
            <?php elseif ( $is_img ) : ?>
                <img src="<?php echo esc_url( $url ); ?>" onclick="event.stopPropagation()">
            <?php else : ?>
                <div style="background:#fff;padding:30px;border-radius:10px;text-align:center;">
                    <div style="font-size:60px;">📄</div>
                    <p><?php esc_html_e( 'فایل PDF است.', 'wc-bank-receipt' ); ?></p>
                    <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="wcr-btn wcr-btn-primary"><?php esc_html_e( 'دانلود / مشاهده', 'wc-bank-receipt' ); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            // Show reason box when reject button clicked
            $('#reject-btn-<?php echo (int) $oid; ?>').on('click',function(){
                $('#reason-wrap-<?php echo (int) $oid; ?>').show();
            });
            $('.r-btn').on('click',function(){
                var btn=$(this), action=btn.data('action'), oid=btn.data('id');
                if(action==='reject' && !$('#reject-reason-'+oid).val()){
                    if(!confirm('<?php echo esc_js( __( 'دلیل رد را وارد می‌کنید؟ (به مشتری نمایش داده می‌شود)', 'wc-bank-receipt' ) ); ?>')){ return; }
                }
                btn.prop('disabled',true).text('<?php echo esc_js( __( 'در حال ثبت...', 'wc-bank-receipt' ) ); ?>');
                $.post(ajaxurl,{
                    action:'wc_receipt_'+action,
                    order_id:oid,
                    nonce:'<?php echo esc_js( $nonce ); ?>',
                    reason:action==='reject' ? $('#reject-reason-'+oid).val() : ''
                },function(r){
                    if(r && r.success) { location.reload(); }
                    else {
                        btn.prop('disabled',false).text(action==='approve'?'✓ تأیید':'✗ رد فیش');
                        alert((r && r.data && r.data.message) ? r.data.message : '<?php echo esc_js( __( 'این عملیات انجام نشد.', 'wc-bank-receipt' ) ); ?>');
                    }
                }).fail(function(){ btn.prop('disabled',false); alert('<?php echo esc_js( __( 'ارتباط با سرور قطع شد.', 'wc-bank-receipt' ) ); ?>'); });
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // AJAX: APPROVE (single path through apply_transition)
    // =========================================================================

    /** Shared guard for every admin-ajax mutation: nonce first, capability second. */
    private function guard_admin_ajax() {
        if ( ! check_ajax_referer( self::NONCE_ADMIN, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'نشست شما منقضی شده است؛ صفحه را دوباره باز کنید.', 'wc-bank-receipt' ) ), 403 );
        }
        if ( ! current_user_can( self::CAP_MANAGE ) ) {
            wp_send_json_error( array( 'message' => __( 'شما اجازهٔ این عملیات را ندارید.', 'wc-bank-receipt' ) ), 403 );
        }
    }

    public function ajax_approve() {
        $this->guard_admin_ajax();
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $result   = $this->apply_transition( $order_id, 'approve' );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => $result['message'], 'code' => $result['code'] ) );
        }
        wp_send_json_success( array( 'message' => $result['message'] ) );
    }

    // =========================================================================
    // AJAX: REJECT (single path through apply_transition)
    // =========================================================================

    public function ajax_reject() {
        $this->guard_admin_ajax();
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
        $result   = $this->apply_transition( $order_id, 'reject', array( 'reason' => $reason ) );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => $result['message'], 'code' => $result['code'] ) );
        }
        wp_send_json_success( array( 'message' => $result['message'] ) );
    }

    // =========================================================================
    // BULK REVIEW PAGE (FIX #2 applied + improved UI)
    // =========================================================================

    public function bulk_review_page() {
        if ( ! current_user_can( self::CAP_MANAGE ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), array( 'response' => 403 ) );
        }

        $result_notice = '';
        if ( isset( $_POST['bulk_action_submit'] ) && check_admin_referer( 'bulk_review_nonce' ) ) {
            $action    = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
            $order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) : array();
            $reason    = isset( $_POST['bulk_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk_reason'] ) ) : '';
            $ok = $skipped = $failed = 0;

            // Bulk runs the exact same engine as the single path, one order at a time.
            foreach ( array_slice( $order_ids, 0, 200 ) as $oid ) {
                if ( ! in_array( $action, array( 'approve', 'reject' ), true ) ) break;
                $res = $this->apply_transition( $oid, $action, array( 'reason' => $reason ) );
                if ( ! empty( $res['ok'] ) ) {
                    $ok++;
                } elseif ( in_array( $res['code'], array( 'bad_state', 'duplicate', 'no_receipt', 'already_approved' ), true ) ) {
                    $skipped++;
                } else {
                    $failed++;
                }
            }
            $result_notice = sprintf(
                /* translators: %1$d done, %2$d skipped, %3$d failed */
                __( '%1$d سفارش انجام شد — %2$d رد شد (وضعیت نامعتبر) — %3$d ناموفق.', 'wc-bank-receipt' ),
                $ok, $skipped, $failed
            );
        }

        $per_page = 50;
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $query    = wc_get_orders( array(
            'status'     => array( 'on-hold' ),
            'limit'      => $per_page,
            'page'       => $paged,
            'paginate'   => true,
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_key'   => self::META_STATE,
            'meta_value' => 'pending_review',
        ) );
        $orders       = is_object( $query ) ? $query->orders : (array) $query;
        $total_orders = is_object( $query ) ? (int) $query->total : count( $orders );
        $page_count   = (int) ceil( $total_orders / $per_page );
        ?>

        <?php if ( $result_notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $result_notice ); ?></p></div>
        <?php endif; ?>
        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-search"></span>
                <h1><?php _e( 'بررسی گروهی فیش‌ها', 'wc-bank-receipt' ); ?></h1>
            </div>

            <?php if ( empty( $orders ) ) : ?>
                <div class="wcr-card" style="text-align:center;padding:40px;">
                    <div style="font-size:48px;margin-bottom:12px;">✅</div>
                    <p style="font-size:16px;color:#546e7a;"><?php _e( 'هیچ فیشی در انتظار بررسی نیست.', 'wc-bank-receipt' ); ?></p>
                </div>
            <?php else : ?>
            <div class="wcr-card">
                <h2><span class="dashicons dashicons-yes-alt"></span> <?php printf( esc_html__( '%d فیش در انتظار بررسی', 'wc-bank-receipt' ), (int) $total_orders ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'bulk_review_nonce' ); ?>
                    <table class="wcr-tbl">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="wcr-select-all"></th>
                                <th><?php _e( 'سفارش', 'wc-bank-receipt' ); ?></th>
                                <th><?php _e( 'مشتری', 'wc-bank-receipt' ); ?></th>
                                <th><?php _e( 'مبلغ', 'wc-bank-receipt' ); ?></th>
                                <th><?php _e( 'تاریخ', 'wc-bank-receipt' ); ?></th>
                                <th><?php _e( 'کارت', 'wc-bank-receipt' ); ?></th>
                                <th><?php _e( 'فیش', 'wc-bank-receipt' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $orders as $order ) :
                            $card_id = $order->get_meta( self::META_ASSIGNED_CARD );
                            $card    = $card_id ? $this->get_card_by_id( $card_id ) : null;
                        ?>
                            <tr>
                                <td><input type="checkbox" name="order_ids[]" value="<?php echo (int) $order->get_id(); ?>"></td>
                                <td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank">#<?php echo $order->get_id(); ?></a></td>
                                <td><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></td>
                                <td><?php echo wc_price( $order->get_total() ); ?></td>
                                <td><?php echo $order->get_date_created()->date_i18n( 'Y/m/d H:i' ); ?></td>
                                <td><?php echo $card ? esc_html( $card->bank_name . ' - ' . substr( $card->card_number, -4 ) ) : '—'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $this->receipt_view_url( $order ) ); ?>"
                                       target="_blank" rel="noopener" class="wcr-btn wcr-btn-ghost wcr-btn-sm">👁 <?php esc_html_e( 'مشاهده', 'wc-bank-receipt' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top:20px;padding:16px;background:#f8f9fa;border-radius:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                        <select name="bulk_action" style="padding:8px 12px;border-radius:6px;border:1.5px solid #e0e0e0;font-size:14px;min-width:160px;">
                            <option value=""><?php _e( 'انتخاب عملیات...', 'wc-bank-receipt' ); ?></option>
                            <option value="approve">✓ <?php _e( 'تأیید همه انتخابی‌ها', 'wc-bank-receipt' ); ?></option>
                            <option value="reject">✗ <?php _e( 'رد همه انتخابی‌ها', 'wc-bank-receipt' ); ?></option>
                        </select>
                        <input type="text" name="bulk_reason"
                               placeholder="<?php esc_attr_e( 'دلیل رد (در صورت انتخاب رد)', 'wc-bank-receipt' ); ?>"
                               style="padding:8px 12px;border-radius:6px;border:1.5px solid #e0e0e0;font-size:14px;flex:1;min-width:200px;">
                        <button type="submit" name="bulk_action_submit" class="wcr-btn wcr-btn-primary"><?php esc_html_e( 'اعمال', 'wc-bank-receipt' ); ?></button>
                    </div>
                </form>

                <?php if ( $page_count > 1 ) : ?>
                    <div class="tablenav" style="margin-top:16px;">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post( paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $page_count,
                                'current'   => $paged,
                            ) ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <script>
        document.getElementById('wcr-select-all') && document.getElementById('wcr-select-all').addEventListener('change',function(){
            document.querySelectorAll('input[name="order_ids[]"]').forEach(function(c){ c.checked = document.getElementById('wcr-select-all').checked; });
        });
        </script>
        <?php
    }

    // =========================================================================
    // ORDER LIST COLUMN (FIX #2: handles both CPT int and HPOS WC_Order)
    // =========================================================================

    public function add_order_column( $columns ) {
        $new = array();
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'order_status' ) {
                $new['receipt_status'] = __( 'وضعیت فیش', 'wc-bank-receipt' );
            }
        }
        return $new;
    }

    public function render_order_column( $column, $order_or_id ) {
        if ( $column !== 'receipt_status' ) return;
        // FIX: CPT list passes int, HPOS list passes WC_Order
        $order = is_numeric( $order_or_id ) ? wc_get_order( (int) $order_or_id ) : $order_or_id;
        if ( ! $order ) { echo '—'; return; }
        $state = $order->get_meta( self::META_STATE );
        switch ( $state ) {
            case 'approved':      echo '<span class="wcr-badge ok"  >✓ '  . __( 'تأیید',   'wc-bank-receipt' ) . '</span>'; break;
            case 'rejected':      echo '<span class="wcr-badge err" >✗ '  . __( 'رد',      'wc-bank-receipt' ) . '</span>'; break;
            case 'pending_review':echo '<span class="wcr-badge warn">⏳ ' . __( 'در انتظار','wc-bank-receipt' ) . '</span>'; break;
            default:              echo '—';
        }
    }

    // =========================================================================
    // REPORTS PAGE
    // =========================================================================

    public function reports_page() {
        if ( isset( $_GET['export_csv'] ) && check_admin_referer( 'export_csv_nonce' ) ) {
            $this->export_csv();
        }

        // Aggregates are computed in bounded batches: no shop-wide “limit => -1” load.
        $range   = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '90';
        $args    = array(
            'payment_method' => self::GATEWAY_ID,
            'status'         => array( 'on-hold', 'processing', 'completed', 'failed', 'cancelled', 'pending' ),
            'limit'          => 200,
            'return'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( 'all' !== $range ) {
            $args['date_created'] = '>=' . gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $range ) ) * DAY_IN_SECONDS ) );
        }

        $total = $approved = $rejected = $pending = $amount_approved = 0;
        for ( $page = 1; $page <= 25; $page++ ) {
            $args['page'] = $page;
            $ids = wc_get_orders( $args );
            if ( empty( $ids ) ) break;
            foreach ( $ids as $oid ) {
                $o = wc_get_order( $oid );
                if ( ! $o ) continue;
                $state = (string) $o->get_meta( self::META_STATE );
                $total++;
                if ( 'approved' === $state ) { $approved++; $amount_approved += (float) $o->get_total(); }
                elseif ( 'rejected' === $state ) $rejected++;
                elseif ( 'pending_review' === $state ) $pending++;
            }
            if ( count( $ids ) < 200 ) break;
        }
        $rate = $total > 0 ? round( $approved / $total * 100, 1 ) : 0;
        ?>
        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-chart-bar"></span>
                <h1><?php _e( 'گزارشات کارت به کارت', 'wc-bank-receipt' ); ?></h1>
            </div>

            <div class="wcr-stats">
                <div class="wcr-stat">
                    <div class="wcr-stat-ico"><span class="dashicons dashicons-cart"></span></div>
                    <div><div class="wcr-stat-val"><?php echo $total; ?></div><div class="wcr-stat-lbl"><?php _e( 'کل سفارشات', 'wc-bank-receipt' ); ?></div></div>
                </div>
                <div class="wcr-stat ok">
                    <div class="wcr-stat-ico"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div><div class="wcr-stat-val"><?php echo $approved; ?></div><div class="wcr-stat-lbl"><?php _e( 'تأیید شده', 'wc-bank-receipt' ); ?></div></div>
                </div>
                <div class="wcr-stat err">
                    <div class="wcr-stat-ico"><span class="dashicons dashicons-dismiss"></span></div>
                    <div><div class="wcr-stat-val"><?php echo $rejected; ?></div><div class="wcr-stat-lbl"><?php _e( 'رد شده', 'wc-bank-receipt' ); ?></div></div>
                </div>
                <div class="wcr-stat warn">
                    <div class="wcr-stat-ico"><span class="dashicons dashicons-clock"></span></div>
                    <div><div class="wcr-stat-val"><?php echo $pending; ?></div><div class="wcr-stat-lbl"><?php _e( 'در انتظار بررسی', 'wc-bank-receipt' ); ?></div></div>
                </div>
                <div class="wcr-stat">
                    <div class="wcr-stat-ico"><span class="dashicons dashicons-chart-pie"></span></div>
                    <div><div class="wcr-stat-val"><?php echo $rate; ?>%</div><div class="wcr-stat-lbl"><?php _e( 'نرخ تأیید', 'wc-bank-receipt' ); ?></div></div>
                </div>
                <div class="wcr-stat ok">
                    <div class="wcr-stat-ico"><span class="dashicons dashicons-money-alt"></span></div>
                    <div><div class="wcr-stat-val"><?php echo number_format( $amount_approved ); ?></div><div class="wcr-stat-lbl"><?php _e( 'مجموع تأیید (تومان)', 'wc-bank-receipt' ); ?></div></div>
                </div>
            </div>

            <!-- Progress bar -->
            <div class="wcr-card">
                <h3><span class="dashicons dashicons-performance"></span> <?php _e( 'نرخ تکمیل پرداخت', 'wc-bank-receipt' ); ?></h3>
                <div style="background:#e0e0e0;border-radius:8px;height:20px;overflow:hidden;margin-bottom:8px;">
                    <div style="width:<?php echo $rate; ?>%;background:linear-gradient(90deg,#2e7d32,#43a047);height:100%;border-radius:8px;transition:width .5s;"></div>
                </div>
                <small style="color:#757575;"><?php echo $approved; ?> از <?php echo $total; ?> سفارش تأیید شده (<?php echo $rate; ?>%)</small>

                <div style="margin-top:20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'export_csv' => 1, 'range' => $range ), admin_url( 'admin.php' ) ), 'export_csv_nonce' ) ); ?>"
                       class="wcr-btn wcr-btn-primary">
                        ⬇ <?php esc_html_e( 'خروجی CSV', 'wc-bank-receipt' ); ?>
                    </a>
                    <?php
                    $ranges = array( '30' => __( '۳۰ روز اخیر', 'wc-bank-receipt' ), '90' => __( '۹۰ روز اخیر', 'wc-bank-receipt' ), '365' => __( 'یک سال اخیر', 'wc-bank-receipt' ), 'all' => __( 'همه', 'wc-bank-receipt' ) );
                    foreach ( $ranges as $key => $label ) :
                        ?>
                        <a href="<?php echo esc_url( add_query_arg( 'range', $key ) ); ?>"
                           class="wcr-btn wcr-btn-ghost wcr-btn-sm" <?php echo $range === (string) $key ? 'style="background:#e3f2fd;"' : ''; ?>>
                            <?php echo esc_html( $label ); ?>
                        </a>
                    <?php endforeach; ?>
                    <small style="color:#757575;"><?php printf( esc_html__( 'بازهٔ گزارش: %s', 'wc-bank-receipt' ), 'all' === $range ? esc_html__( 'همه', 'wc-bank-receipt' ) : sprintf( esc_html__( '%s روز', 'wc-bank-receipt' ), esc_html( $range ) ) ); ?></small>
                </div>
            </div>
        </div>
        <?php
    }

    private function export_csv() {
        if ( ! current_user_can( self::CAP_MANAGE ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), '', array( 'response' => 403 ) );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=card-transactions-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
        fputcsv( $out, array( 'شماره سفارش', 'تاریخ', 'مبلغ', 'وضعیت فیش', 'کارت استفاده‌شده', 'دلیل رد' ) );

        $range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'all';
        $args  = array(
            'payment_method' => self::GATEWAY_ID,
            'limit'          => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( 'all' !== $range ) {
            $args['date_created'] = '>=' . gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $range ) ) * DAY_IN_SECONDS ) );
        }

        // Streamed in batches: constant memory no matter how many orders exist.
        for ( $page = 1; $page <= 500; $page++ ) {
            $args['page'] = $page;
            $orders = wc_get_orders( $args );
            if ( empty( $orders ) ) break;
            foreach ( $orders as $order ) {
                $state = (string) $order->get_meta( self::META_STATE );
                $lbl   = 'approved' === $state ? 'تأیید' : ( 'rejected' === $state ? 'رد' : ( 'pending_review' === $state ? 'در انتظار' : 'بدون فیش' ) );
                $cinfo = '';
                $cid   = absint( $order->get_meta( self::META_ASSIGNED_CARD ) );
                if ( $cid ) {
                    $c = $this->get_card_by_id( $cid );
                    if ( $c ) $cinfo = $c->bank_name . ' - ' . substr( (string) $c->card_number, -4 );
                }
                $created = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d H:i' ) : '';
                fputcsv( $out, array( $order->get_id(), $created, $order->get_total(), $lbl, $cinfo, (string) $order->get_meta( self::META_REJECT_REASON ) ) );
            }
            if ( count( $orders ) < 200 ) break;
        }
        fclose( $out );
        exit;
    }

    private function log_receipt_action( $order_id, $action, $reason = '' ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::DB_PREFIX . 'receipt_log', array(
            'order_id'  => $order_id,
            'action'    => $action,
            'reason'    => $reason,
            'timestamp' => current_time( 'mysql' ),
        ) );
    }

    // =========================================================================
    // GALLERY PAGE
    // =========================================================================

    public function gallery_page() {
        if ( ! current_user_can( self::CAP_MANAGE ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), array( 'response' => 403 ) );
        }

        $notice = '';
        $notice_class = 'notice-success';
        if ( isset( $_GET['delete_receipt'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
            if ( ! wp_verify_nonce( $nonce, 'delete_receipt' ) ) {
                $notice = __( 'درخواست نامعتبر است.', 'wc-bank-receipt' );
                $notice_class = 'notice-error';
            } else {
                $deleted = $this->delete_receipt_file( absint( $_GET['delete_receipt'] ), 'gallery' );
                $notice  = $deleted
                    ? __( 'فایل فیش حذف شد و مرجع آن از سفارش پاک شد.', 'wc-bank-receipt' )
                    : __( 'حذف فایل موفق نبود؛ مرجع فیش دست‌نخورده باقی ماند تا داده گمراه‌کننده ایجاد نشود.', 'wc-bank-receipt' );
                $notice_class = $deleted ? 'notice-success' : 'notice-error';
            }
        }

        $per_page = 24;
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $query    = wc_get_orders( array(
            'status'   => array( 'pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled' ),
            'limit'    => $per_page,
            'page'     => $paged,
            'paginate' => true,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => self::receipt_meta_query(),
        ) );
        $orders       = is_object( $query ) ? $query->orders : (array) $query;
        $total_orders = is_object( $query ) ? (int) $query->total : count( $orders );
        $page_count   = (int) ceil( $total_orders / $per_page );

        $period      = get_option( 'wc_receipt_cleanup_period', '1' );
        $days        = $period === 'custom' ? absint( get_option( 'wc_receipt_custom_days', 1 ) ) : absint( $period );
        $delete_secs = max( 1, $days ) * DAY_IN_SECONDS;
        $keep_approved = get_option( 'wc_receipt_keep_approved_files', 'yes' ) === 'yes';
        ?>

        <?php if ( $notice ) : ?>
            <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>
        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-format-gallery"></span>
                <h1><?php _e( 'گالری فیش‌های بانکی', 'wc-bank-receipt' ); ?></h1>
            </div>

            <?php if ( empty( $orders ) ) : ?>
                <div class="wcr-card" style="text-align:center;padding:40px;">
                    <div style="font-size:48px;margin-bottom:12px;">🖼</div>
                    <p style="font-size:15px;color:#546e7a;"><?php _e( 'هنوز فیشی آپلود نشده است.', 'wc-bank-receipt' ); ?></p>
                </div>
            <?php else : ?>
            <div class="wcr-card">
                <div class="wcr-gallery">
                <?php foreach ( $orders as $order ) :
                    $oid       = $order->get_id();
                    $url       = $order->get_meta( self::META_URL );
                    if ( ! $url ) continue;
                    $state     = $order->get_meta( self::META_STATE );
                    $file      = $this->receipt_file_name( $order );
                    $exists    = (bool) self::receipt_path( $order );
                    $uploaded  = (int) $order->get_meta( self::META_UPLOADED );
                    if ( ! $uploaded ) {
                        $uploaded = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
                    }
                    if ( 'approved' === $state && $keep_approved ) {
                        $time_left = -1; // retained by policy
                    } else {
                        $time_left = max( 0, ( $uploaded + $delete_secs ) - time() );
                    }
                    $rem_d     = floor( max( 0, $time_left ) / DAY_IN_SECONDS );
                    $rem_h     = floor( ( max( 0, $time_left ) % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
                    $is_img    = (bool) preg_match( '/\.(jpe?g|png)$/i', $file );
                    $view_url  = $this->receipt_view_url( $order );
                    $del_url   = wp_nonce_url( add_query_arg( array('page'=>'wc-receipt-gallery','delete_receipt'=>$oid), admin_url('admin.php') ), 'delete_receipt' );
                    $badge_class = $state === 'approved' ? 'ok' : ( $state === 'rejected' ? 'err' : 'warn' );
                    $badge_lbl   = $state === 'approved' ? 'تأیید' : ( $state === 'rejected' ? 'رد شده' : 'در انتظار' );
                ?>
                    <div class="wcr-gitem">
                        <div class="wcr-gthumb">
                            <?php if ( $exists && $is_img ) : ?>
                                <a href="<?php echo esc_url($view_url); ?>" target="_blank">
                                    <img src="<?php echo esc_url($view_url); ?>" alt="فیش #<?php echo $oid; ?>">
                                </a>
                            <?php elseif ( $exists ) : ?>
                                <a href="<?php echo esc_url($view_url); ?>" target="_blank" class="pdf-icon">📄</a>
                            <?php else : ?>
                                <span style="color:#b0bec5;font-size:32px;">🗑</span>
                            <?php endif; ?>
                            <span class="wcr-badge <?php echo $badge_class; ?>" style="position:absolute;top:8px;right:8px;font-size:11px;"><?php echo $badge_lbl; ?></span>
                        </div>
                        <div class="wcr-ginfo">
                            <div class="order-no"><?php _e('سفارش','wc-bank-receipt'); ?> #<?php echo $oid; ?></div>
                            <div class="time-left">
                                <?php if ( ! $exists ) : ?>
                                    <span style="color:#e53935;"><?php esc_html_e( 'فایل حذف شده', 'wc-bank-receipt' ); ?></span>
                                <?php elseif ( $time_left < 0 ) : ?>
                                    <span style="color:#2e7d32;"><?php esc_html_e( 'نگهداری دائمی (تأییدشده)', 'wc-bank-receipt' ); ?></span>
                                <?php elseif ( $time_left <= 0 ) : ?>
                                    <span style="color:#e65100;"><?php esc_html_e( 'آماده حذف', 'wc-bank-receipt' ); ?></span>
                                <?php else : ?>
                                    ⏳ <?php printf( esc_html__( '%1$d روز %2$d ساعت', 'wc-bank-receipt' ), (int) $rem_d, (int) $rem_h ); ?>
                                <?php endif; ?>
                            </div>
                            <div class="actions">
                                <?php if ( $exists ) : ?>
                                <a href="<?php echo esc_url($view_url); ?>" target="_blank" class="wcr-btn wcr-btn-ghost wcr-btn-sm">👁</a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($del_url); ?>" class="wcr-btn wcr-btn-err wcr-btn-sm"
                                   onclick="return confirm('<?php esc_attr_e('آیا مطمئنید؟','wc-bank-receipt'); ?>');">🗑</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <?php if ( $page_count > 1 ) : ?>
                    <div class="tablenav" style="margin-top:16px;">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post( paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $page_count,
                                'current'   => $paged,
                            ) ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Delete a receipt file and keep file/metadata consistent.
     * Returns false (and keeps every reference) when the file could not be removed,
     * so the UI never claims a deletion that did not happen.
     *
     * The decision record (META_STATE) and the history stay: only the *file* is gone.
     */
    private function delete_receipt_file( $order_id, $context = 'manual' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;
        $file = $this->receipt_file_name( $order );
        if ( '' === $file ) return false;

        $path = self::receipt_path( $order );
        if ( '' !== $path ) {
            if ( ! @unlink( $path ) ) {
                $this->log_receipt_action( $order->get_id(), 'file_delete_failed', $context . ':' . $file );
                return false;
            }
        }

        $this->history_append( $order, 'file_deleted', array( 'file' => $file, 'context' => $context ) );
        $order->delete_meta_data( self::META_FILE );
        $order->delete_meta_data( self::META_URL );
        $order->delete_meta_data( self::META_UPLOADED );
        $order->save();
        $this->log_receipt_action( $order->get_id(), 'file_deleted', $context . ':' . $file );
        return true;
    }

    /**
     * Retention policy (see docs/retention-policy.md):
     *  - approved receipts are kept by default (evidence of payment);
     *  - other receipts are removed after the configured period, counted from the
     *    upload time (not from the order date);
     *  - runs in batches so a large shop never loads everything at once.
     */
    public function cleanup_old_files() {
        if ( ! $this->woocommerce_is_available() ) return;
        $period = get_option( 'wc_receipt_cleanup_period', '1' );
        if ( $period === 'custom' ) $period = get_option( 'wc_receipt_custom_days', 1 );
        $days   = max( 1, absint( $period ) );
        $cutoff = time() - ( $days * DAY_IN_SECONDS );
        $keep_approved = get_option( 'wc_receipt_keep_approved_files', 'yes' ) === 'yes';

        for ( $page = 1; $page <= 20; $page++ ) {
            $ids = wc_get_orders( array(
                'status'   => array( 'pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled' ),
                'limit'    => 50,
                'page'     => $page,
                'return'     => 'ids',
                'meta_query' => self::receipt_meta_query(),
                'orderby'    => 'date',
                'order'      => 'ASC',
            ) );
            if ( empty( $ids ) ) break;

            foreach ( $ids as $oid ) {
                $order = wc_get_order( $oid );
                if ( ! $order ) continue;
                if ( $keep_approved && 'approved' === (string) $order->get_meta( self::META_STATE ) ) continue;
                $uploaded = (int) $order->get_meta( self::META_UPLOADED );
                if ( ! $uploaded && $order->get_date_created() ) {
                    $uploaded = (int) $order->get_date_created()->getTimestamp();
                }
                if ( $uploaded && $uploaded > $cutoff ) continue;
                $this->delete_receipt_file( $oid, 'cleanup' );
            }
            if ( count( $ids ) < 50 ) break;
        }
    }

    /**
     * Deadline enforcement. Runs from WP-Cron so it is independent of page views:
     * an order that never gets a receipt no longer waits for the customer to come
     * back before it is closed.
     */
    public function expire_overdue_orders() {
        if ( ! $this->woocommerce_is_available() ) return;
        if ( $this->expire_hours() <= 0 ) return;

        $expired = 0;
        for ( $page = 1; $page <= 10; $page++ ) {
            $ids = wc_get_orders( array(
                'status'          => array( 'pending' ),
                'payment_method'  => self::GATEWAY_ID,
                'limit'           => 50,
                'page'            => $page,
                'return'          => 'ids',
                'orderby'         => 'date',
                'order'           => 'ASC',
            ) );
            if ( empty( $ids ) ) break;
            foreach ( $ids as $oid ) {
                $order = wc_get_order( $oid );
                if ( ! $order ) continue;
                if ( 'approved' === (string) $order->get_meta( self::META_STATE ) ) continue;
                if ( ! $this->deadline_passed( $order ) ) continue;
                $result = $this->apply_transition( $order, 'expire' );
                if ( ! empty( $result['ok'] ) ) $expired++;
            }
            if ( count( $ids ) < 50 ) break;
        }
        return $expired;
    }

    // =========================================================================
    // SMS
    // =========================================================================

    /** Default templates, also used as the fallback when an option is empty. */
    private function sms_templates() {
        return array(
            'approved'     => __( 'مشتری گرامی، فیش سفارش {order_id} تأیید شد.', 'wc-bank-receipt' ),
            'rejected'     => __( 'مشتری گرامی، فیش سفارش {order_id} رد شد. لطفاً مجدد ارسال کنید.', 'wc-bank-receipt' ),
            'reminder'     => __( 'مشتری گرامی، مهلت پرداخت سفارش {order_id} رو به اتمام است.', 'wc-bank-receipt' ),
            'cancelled'    => __( 'سفارش {order_id} به دلیل عدم پرداخت لغو شد.', 'wc-bank-receipt' ),
            'admin_upload' => __( 'فیش جدید برای سفارش {order_id} بارگذاری شد.', 'wc-bank-receipt' ),
        );
    }

    /**
     * Status-driven SMS with a per-transition dedupe gate: one message per order
     * per transition, even if an admin toggles the status back and forth.
     */
    public function send_sms_on_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( ! self::SMS_UI_ENABLED ) return;   // SMS off in this release
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order || ! $this->woocommerce_is_available() ) return;

        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) return;

        $state = (string) $order->get_meta( self::META_STATE );
        $event = '';
        if ( 'processing' === $new_status && 'approved' === $state ) {
            $event = 'approved';
        } elseif ( 'completed' === $new_status && 'approved' === $state ) {
            $event = 'approved';
        } elseif ( 'pending' === $new_status && 'rejected' === $state ) {
            $event = 'rejected';
        } elseif ( 'failed' === $new_status && 'approved' !== $state ) {
            $event = 'cancelled';
        }
        if ( '' === $event ) return;

        // Dedupe: claim a row keyed by this transition and receipt instance.
        if ( ! $this->claim_transition( $order_id, $this->receipt_uid( $order ), 'sms_' . $event ) ) {
            return;
        }

        $templates = $this->sms_templates();
        $tpl  = get_option( "wc_sms_{$event}_tpl", $templates[ $event ] );
        if ( empty( $tpl ) ) $tpl = $templates[ $event ];
        $this->send_sms( $phone, str_replace( '{order_id}', $order_id, $tpl ), $event, $order_id );
    }

    private function send_admin_upload_sms( $order_id ) {
        $admin_phone = get_option( 'wc_sms_admin_phone' );
        if ( empty( $admin_phone ) ) return;
        $templates = $this->sms_templates();
        $tpl = get_option( 'wc_sms_admin_upload_tpl', $templates['admin_upload'] );
        $this->send_sms( $admin_phone, str_replace( '{order_id}', $order_id, $tpl ), 'admin_upload', $order_id );
    }

    /**
     * One reminder per receipt cycle, based on the same absolute deadline as the
     * customer countdown (never a separate, drifting schedule). Processes pending
     * orders in bounded batches.
     */
    public function send_payment_reminders() {
        // The reminder is an SMS-only feature: with SMS off the cron job does nothing and
        // no order is ever flagged as “reminded”.
        if ( ! self::SMS_UI_ENABLED ) return;
        if ( ! $this->woocommerce_is_available() ) return;
        $reminder_hours = absint( get_option( 'wc_sms_reminder_before_hours', 1 ) );
        if ( ! $reminder_hours ) return;
        if ( $this->expire_hours() <= 0 ) return;
        $reminder_secs = $reminder_hours * HOUR_IN_SECONDS;

        for ( $page = 1; $page <= 10; $page++ ) {
            $ids = wc_get_orders( array(
                'status'         => array( 'pending' ),
                'payment_method' => self::GATEWAY_ID,
                'limit'          => 50,
                'page'           => $page,
                'return'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'ASC',
            ) );
            if ( empty( $ids ) ) break;

            foreach ( $ids as $oid ) {
                $order = wc_get_order( $oid );
                if ( ! $order ) continue;
                if ( 'approved' === (string) $order->get_meta( self::META_STATE ) ) continue;
                if ( $order->get_meta( self::META_REMINDER_SENT ) ) continue;

                $remaining = $this->remaining_seconds( $order );
                if ( $remaining > 0 && $remaining <= $reminder_secs ) {
                    $phone = $order->get_billing_phone();
                    if ( $phone ) {
                        $templates = $this->sms_templates();
                        $tpl = get_option( 'wc_sms_reminder_tpl', $templates['reminder'] );
                        $this->send_sms( $phone, str_replace( '{order_id}', $order->get_id(), $tpl ), 'reminder', $order->get_id() );
                    }
                    // Flag regardless of provider outcome: one reminder per cycle.
                    // Failures are visible in the SMS log.
                    $order->update_meta_data( self::META_REMINDER_SENT, 1 );
                    $order->save();
                }
            }
            if ( count( $ids ) < 50 ) break;
        }
    }

    /**
     * Send one SMS and record what the provider actually answered.
     * Statuses: sent | failed | unknown.
     */
    private function send_sms( $phone, $message, $event_type = '', $order_id = 0 ) {
        /*
         * Single gate for the whole delivery layer.
         * SMS is switched off in this release (SMS_UI_ENABLED): no UI, and above all no
         * message leaves the shop. Nothing is sent, nothing is logged — while the
         * providers, templates and stored credentials stay exactly as they were, so the
         * next phase only has to flip the flag back on.
         */
        if ( ! self::SMS_UI_ENABLED ) return false;

        $phone    = preg_replace( '/[^0-9+]/', '', (string) $phone );
        $provider = get_option( 'wc_sms_provider', 'kavenegar' );
        $sender   = '';
        if ( $event_type ) {
            $es = get_option( "wc_sms_{$event_type}_sender" );
            if ( ! empty( $es ) ) $sender = $es;
        }
        if ( empty( $sender ) ) $sender = get_option( "wc_sms_{$provider}_sender", '' );

        if ( '' === $phone ) {
            $result = array( 'status' => 'failed', 'response' => 'Missing recipient' );
        } else {
            $result = $this->send_sms_raw( $phone, $message, $provider, $sender );
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::DB_PREFIX . 'sms_log', array(
            'order_id'  => $order_id,
            'recipient' => $phone,
            'message'   => $message,
            'status'    => $result['status'],
            'response'  => substr( (string) $result['response'], 0, 500 ),
            'timestamp' => current_time( 'mysql' ),
        ) );

        return $result['status'];
    }

    /** @return array{status:string,response:string} */
    private function send_sms_raw( $phone, $message, $provider, $sender = '' ) {
        switch ( $provider ) {
            case 'kavenegar':
                $key = get_option( 'wc_sms_kavenegar_apikey' );
                if ( empty( $key ) ) return array( 'status' => 'failed', 'response' => 'API key missing' );
                $sender   = $sender ?: get_option( 'wc_sms_kavenegar_sender', '10008663' );
                $response = wp_remote_post(
                    'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json',
                    array(
                        'body'    => array( 'receptor' => $phone, 'sender' => $sender, 'message' => $message ),
                        'timeout' => 15,
                    )
                );
                return $this->evaluate_sms_response( $response, 'kavenegar' );

            case 'melli':
                $user = get_option( 'wc_sms_melli_username' );
                $pass = get_option( 'wc_sms_melli_password' );
                if ( empty( $user ) || empty( $pass ) ) return array( 'status' => 'failed', 'response' => 'Missing credentials' );
                $sender   = $sender ?: get_option( 'wc_sms_melli_sender' );
                $response = wp_remote_post(
                    'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
                    array(
                        'body'    => array( 'username' => $user, 'password' => $pass, 'to' => $phone, 'from' => $sender, 'text' => $message ),
                        'timeout' => 15,
                    )
                );
                return $this->evaluate_sms_response( $response, 'melli' );

            case 'custom':
                $url    = get_option( 'wc_sms_custom_url' );
                $apikey = get_option( 'wc_sms_custom_apikey' );
                if ( empty( $url ) ) return array( 'status' => 'failed', 'response' => 'URL missing' );
                $sender = $sender ?: get_option( 'wc_sms_custom_sender' );
                $args   = array( 'body' => array( 'to' => $phone, 'message' => $message, 'sender' => $sender ), 'timeout' => 15 );
                if ( ! empty( $apikey ) ) $args['headers'] = array( 'Authorization' => 'Bearer ' . $apikey );
                return $this->evaluate_sms_response( wp_remote_post( $url, $args ), 'custom' );
        }
        return array( 'status' => 'failed', 'response' => 'Unknown SMS provider' );
    }

    /**
     * A send only counts as “sent” when the transport AND the provider say so.
     * - WP_Error / non-2xx / provider error payload → failed
     * - 2xx with a body we cannot parse → unknown (never silently “sent”)
     */
    private function evaluate_sms_response( $response, $provider ) {
        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'failed', 'response' => $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return array( 'status' => 'failed', 'response' => 'HTTP ' . $code . ' — ' . $body );
        }

        if ( 'custom' === $provider ) {
            return array( 'status' => 'sent', 'response' => $body ? $body : 'HTTP ' . $code );
        }

        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) {
            return array( 'status' => 'unknown', 'response' => 'Unparsable response: ' . $body );
        }

        if ( 'kavenegar' === $provider ) {
            $status = isset( $json['return']['status'] ) ? (int) $json['return']['status'] : 0;
            if ( $status >= 200 && $status < 300 ) {
                return array( 'status' => 'sent', 'response' => $body );
            }
            return array( 'status' => 'failed', 'response' => 'API status ' . $status . ' — ' . ( $json['return']['message'] ?? '' ) );
        }

        if ( 'melli' === $provider ) {
            $ret = isset( $json['RetStatus'] ) ? (int) $json['RetStatus'] : 0;
            if ( 1 === $ret ) {
                return array( 'status' => 'sent', 'response' => $body );
            }
            return array( 'status' => 'failed', 'response' => 'API status ' . $ret . ' — ' . ( $json['StrRetStatus'] ?? '' ) );
        }

        return array( 'status' => 'unknown', 'response' => $body );
    }

    // =========================================================================
    // SMS LOG PAGE
    // =========================================================================

    public function sms_log_page() {
        if ( ! self::SMS_UI_ENABLED ) {
            wp_die( esc_html__( 'این بخش فعلاً غیرفعال است.', 'wc-bank-receipt' ), '', array( 'response' => 403 ) );
        }
        global $wpdb;
        $table    = $wpdb->prefix . self::DB_PREFIX . 'sms_log';
        $per_page = 20;
        $page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $logs     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
        ?>
        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-email-alt"></span>
                <h1><?php _e( 'تاریخچه پیامک‌ها', 'wc-bank-receipt' ); ?></h1>
            </div>
            <div class="wcr-card">
                <table class="wcr-tbl">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php _e( 'سفارش', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'گیرنده', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'متن', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'وضعیت', 'wc-bank-receipt' ); ?></th>
                            <th><?php _e( 'زمان', 'wc-bank-receipt' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $logs ) : foreach ( $logs as $l ) : ?>
                        <tr>
                            <td><?php echo $l->id; ?></td>
                            <td><?php echo $l->order_id ? '<a href="' . esc_url(get_edit_post_link($l->order_id)) . '">#'.$l->order_id.'</a>' : '—'; ?></td>
                            <td><?php echo esc_html($l->recipient); ?></td>
                            <td title="<?php echo esc_attr($l->message); ?>"><?php echo esc_html(mb_strimwidth($l->message,0,55,'...')); ?></td>
                            <td><?php echo $l->status==='sent' ? '<span class="wcr-badge ok">✓ ارسال</span>' : '<span class="wcr-badge err">✗ ناموفق</span>'; ?></td>
                            <td><?php echo esc_html($l->timestamp); ?></td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="6" style="text-align:center;padding:30px;color:#90a4ae;"><?php _e('هنوز پیامکی ارسال نشده.','wc-bank-receipt'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php
                $pages = ceil( $total / $per_page );
                if ( $pages > 1 ) {
                    echo '<div class="tablenav" style="margin-top:16px;"><div class="tablenav-pages">';
                    echo paginate_links( array(
                        'base'=>add_query_arg('paged','%#%'),
                        'format'=>'','prev_text'=>'&laquo;','next_text'=>'&raquo;',
                        'total'=>$pages,'current'=>$page,
                    ) );
                    echo '</div></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // SMS SETTINGS PAGE
    // =========================================================================

    /** Show only that a secret exists — never the value itself. */
    private function masked_secret( $option_name ) {
        $value = (string) get_option( $option_name, '' );
        if ( '' === $value ) return '';
        $tail = strlen( $value ) > 4 ? substr( $value, -4 ) : '';
        return '••••••••' . $tail;
    }

    public function sms_settings_page() {
        // The SMS tab is not registered in this release, and the route is closed as well:
        // a direct URL must not reach the (intact) credentials UI.
        if ( ! self::SMS_UI_ENABLED ) {
            wp_die( esc_html__( 'این بخش فعلاً غیرفعال است.', 'wc-bank-receipt' ), '', array( 'response' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), '', array( 'response' => 403 ) );
        }
        $provider = get_option('wc_sms_provider','kavenegar');
        ?>
        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-smartphone"></span>
                <h1><?php _e('تنظیمات پیام‌رسانی','wc-bank-receipt'); ?></h1>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('wc_sms_group'); ?>

                <div class="wcr-card">
                    <h2><span class="dashicons dashicons-admin-generic"></span> <?php _e('تنظیمات سرویس','wc-bank-receipt'); ?></h2>

                    <div class="wcr-tabs" id="sms-tabs">
                        <?php foreach(self::SMS_PROVIDERS as $k=>$lbl): ?>
                            <button type="button" class="wcr-tab<?php echo $provider===$k?' active':''; ?>" data-tab="<?php echo $k; ?>"><?php echo $lbl; ?></button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Kavenegar -->
                    <div class="wcr-tab-pane<?php echo $provider==='kavenegar'?' active':''; ?>" id="tab-kavenegar">
                        <table class="wcr-ftbl">
                            <tr><th><?php _e('کلید API','wc-bank-receipt'); ?></th><td><input type="password" name="wc_sms_kavenegar_apikey" value="" placeholder="<?php echo esc_attr( $this->masked_secret( 'wc_sms_kavenegar_apikey' ) ); ?>" dir="ltr" autocomplete="new-password"><p class="wcr-hint"><?php esc_html_e( 'خالی گذاشتن = بدون تغییر · «-» = پاک‌کردن مقدار ذخیره‌شده', 'wc-bank-receipt' ); ?></p></td></tr>
                            <tr><th><?php _e('شماره فرستنده','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_kavenegar_sender" value="<?php echo esc_attr(get_option('wc_sms_kavenegar_sender','10008663')); ?>" dir="ltr"></td></tr>
                        </table>
                    </div>

                    <!-- Melli -->
                    <div class="wcr-tab-pane<?php echo $provider==='melli'?' active':''; ?>" id="tab-melli">
                        <table class="wcr-ftbl">
                            <tr><th><?php _e('نام کاربری','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_melli_username" value="<?php echo esc_attr(get_option('wc_sms_melli_username')); ?>" dir="ltr"></td></tr>
                            <tr><th><?php _e('رمز عبور','wc-bank-receipt'); ?></th><td><input type="password" name="wc_sms_melli_password" value="" placeholder="<?php echo esc_attr( $this->masked_secret( 'wc_sms_melli_password' ) ); ?>" dir="ltr" autocomplete="new-password"><p class="wcr-hint"><?php esc_html_e( 'خالی گذاشتن = بدون تغییر · «-» = پاک‌کردن مقدار ذخیره‌شده', 'wc-bank-receipt' ); ?></p></td></tr>
                            <tr><th><?php _e('شماره فرستنده','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_melli_sender" value="<?php echo esc_attr(get_option('wc_sms_melli_sender')); ?>" dir="ltr"></td></tr>
                        </table>
                    </div>

                    <!-- Custom -->
                    <div class="wcr-tab-pane<?php echo $provider==='custom'?' active':''; ?>" id="tab-custom">
                        <table class="wcr-ftbl">
                            <tr><th><?php _e('آدرس API','wc-bank-receipt'); ?></th><td><input type="url" name="wc_sms_custom_url" value="<?php echo esc_attr(get_option('wc_sms_custom_url')); ?>" dir="ltr" placeholder="https://..."></td></tr>
                            <tr><th><?php _e('کلید API','wc-bank-receipt'); ?></th><td><input type="password" name="wc_sms_custom_apikey" value="" placeholder="<?php echo esc_attr( $this->masked_secret( 'wc_sms_custom_apikey' ) ); ?>" dir="ltr" autocomplete="new-password"><p class="wcr-hint"><?php esc_html_e( 'خالی گذاشتن = بدون تغییر · «-» = پاک‌کردن مقدار ذخیره‌شده', 'wc-bank-receipt' ); ?></p></td></tr>
                            <tr><th><?php _e('شماره فرستنده','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_custom_sender" value="<?php echo esc_attr(get_option('wc_sms_custom_sender')); ?>" dir="ltr"></td></tr>
                        </table>
                    </div>

                    <!-- Hidden field for selected provider -->
                    <input type="hidden" name="wc_sms_provider" id="sms_provider_val" value="<?php echo esc_attr($provider); ?>">
                </div>

                <div class="wcr-card">
                    <h2><span class="dashicons dashicons-format-chat"></span> <?php _e('قالب‌های پیامکی','wc-bank-receipt'); ?></h2>
                    <p class="wcr-hint" style="margin-bottom:16px;"><?php _e('متغیر {order_id} با شماره سفارش جایگزین می‌شود.','wc-bank-receipt'); ?></p>
                    <table class="wcr-ftbl">
                    <?php
                    $tpls = array(
                        'approved'     => array(__('تأیید فیش','wc-bank-receipt'),      __('مشتری گرامی، فیش سفارش {order_id} تأیید شد.','wc-bank-receipt')),
                        'rejected'     => array(__('رد فیش','wc-bank-receipt'),          __('مشتری گرامی، فیش سفارش {order_id} رد شد. لطفاً مجدد ارسال کنید.','wc-bank-receipt')),
                        'reminder'     => array(__('یادآوری پرداخت','wc-bank-receipt'),  __('مشتری گرامی، مهلت پرداخت سفارش {order_id} رو به اتمام است.','wc-bank-receipt')),
                        'cancelled'    => array(__('ابطال سفارش','wc-bank-receipt'),     __('سفارش {order_id} به دلیل عدم پرداخت لغو شد.','wc-bank-receipt')),
                        'admin_upload' => array(__('اطلاع به مدیر (آپلود جدید)','wc-bank-receipt'), __('فیش جدید برای سفارش {order_id} بارگذاری شد.','wc-bank-receipt')),
                    );
                    foreach($tpls as $key=>list($label,$default)): ?>
                        <tr>
                            <th><?php echo $label; ?></th>
                            <td>
                                <textarea name="wc_sms_<?php echo $key; ?>_tpl" rows="2" class="large-text"><?php echo esc_textarea(get_option("wc_sms_{$key}_tpl",$default)); ?></textarea>
                                <p class="wcr-hint"><?php _e('خط اختصاصی (اختیاری):','wc-bank-receipt'); ?> <input type="text" name="wc_sms_<?php echo $key; ?>_sender" value="<?php echo esc_attr(get_option("wc_sms_{$key}_sender")); ?>" dir="ltr" style="max-width:160px;"></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <th><?php _e('شماره مدیر','wc-bank-receipt'); ?></th>
                            <td><input type="text" name="wc_sms_admin_phone" value="<?php echo esc_attr(get_option('wc_sms_admin_phone')); ?>" dir="ltr" placeholder="09xxxxxxxxx"></td>
                        </tr>
                        <tr>
                            <th><?php _e('یادآوری چند ساعت قبل از مهلت','wc-bank-receipt'); ?></th>
                            <td>
                                <input type="number" min="0" name="wc_sms_reminder_before_hours" value="<?php echo esc_attr(get_option('wc_sms_reminder_before_hours',1)); ?>" style="max-width:80px;">
                                <span class="wcr-hint"><?php _e('ساعت — صفر=غیرفعال','wc-bank-receipt'); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(__('ذخیره تنظیمات پیامک','wc-bank-receipt')); ?>
            </form>
        </div>
        <script>
        (function(){
            var tabs = document.querySelectorAll('.wcr-tab');
            var pval = document.getElementById('sms_provider_val');
            tabs.forEach(function(tab){
                tab.addEventListener('click',function(){
                    tabs.forEach(function(t){ t.classList.remove('active'); });
                    document.querySelectorAll('.wcr-tab-pane').forEach(function(p){ p.classList.remove('active'); });
                    tab.classList.add('active');
                    document.getElementById('tab-'+tab.dataset.tab).classList.add('active');
                    pval.value = tab.dataset.tab;
                });
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // ADMIN ORDER ALERTS — EVENT QUEUE, POPUP & SOUND
    //
    // Two independent events are queued as rows in `…_events`:
    //   new_order     — an order was placed, whatever the payment method;
    //   status_change — an order moved status through the standard WooCommerce flow.
    // Every manager polls with their own cursor, so an event is delivered once per
    // user and acknowledging it never hides it from another manager.
    // =========================================================================

    /** Is the audible alert switched on? The popup itself is always queued. */
    private function alert_sound_enabled() {
        return ( 'yes' === get_option( 'wc_receipt_alerts_sound', 'yes' ) );
    }

    /** WooCommerce status slugs are stored without the `wc-` prefix or case noise. */
    private static function normalize_status( $status ) {
        return ltrim( strtolower( (string) $status ), 'wc-' );
    }

    /**
     * Localised status name. An empty value stays empty — the UI says “نامشخص”
     * rather than this method inventing a status.
     */
    private function status_label( $status ) {
        $status = self::normalize_status( $status );
        if ( '' === $status ) return '';
        if ( function_exists( 'wc_get_order_status_name' ) ) {
            $name = (string) wc_get_order_status_name( $status );
            if ( '' !== $name ) return $name;
        }
        return $status;
    }

    /**
     * Plain text out of a formatted HTML fragment.
     *
     * WooCommerce formats money as HTML (`<span class="woocommerce-Price-currencySymbol">&#xfdfc;</span>`),
     * and the popup writes values into the DOM as text. Stripping the tags alone would
     * leave literal entities on screen, so they are decoded here, once, on the server.
     */
    private function plain_text( $html ) {
        return html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /** Formatted customer name, from the order itself. */
    private function order_customer_name( $order ) {
        if ( method_exists( $order, 'get_formatted_billing_full_name' ) ) {
            return (string) $order->get_formatted_billing_full_name();
        }
        return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    }

    private function order_items_count( $order ) {
        if ( method_exists( $order, 'get_item_count' ) ) {
            return (int) $order->get_item_count();
        }
        return count( $order->get_items() );
    }

    /**
     * Append one event to the queue.
     *
     * The row is a snapshot: the popup stays readable after the order is deleted, and a
     * value that is genuinely unknown is stored empty instead of being guessed. The
     * UNIQUE dedupe key makes the insert itself the duplicate check, so two concurrent
     * requests cannot both queue the same event.
     */
    private function record_alert_event( $event_type, $order, $from_status = '', $to_status = '' ) {
        if ( ! ( $order instanceof WC_Order ) ) return 0;

        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'events';

        $order_id    = (int) $order->get_id();
        $from_status = self::normalize_status( $from_status );
        $to_status   = self::normalize_status( $to_status );

        // A repeat of the *same* transition inside one short window collides on this key
        // and is rejected. A genuine repeat later (minutes, hours) has a different window
        // and is kept as its own event.
        $window     = (int) floor( time() / self::ALERT_DEDUPE_WINDOW );
        $dedupe_key = md5( $event_type . '|' . $order_id . '|' . $from_status . '|' . $to_status . '|' . $window );

        $suppress = $wpdb->suppress_errors( true );
        $inserted = $wpdb->insert(
            $table,
            array(
                'event_type'    => (string) $event_type,
                'order_id'      => $order_id,
                'from_status'   => $from_status,
                'to_status'     => $to_status,
                'customer'      => $this->order_customer_name( $order ),
                'order_number'  => (string) $order->get_order_number(),
                'total_display' => $this->plain_text( $order->get_formatted_order_total() ),
                'payment_title' => (string) $order->get_payment_method_title(),
                'items_count'   => $this->order_items_count( $order ),
                'view_url'      => esc_url_raw( (string) $order->get_edit_order_url() ),
                'user_id'       => get_current_user_id(),
                'created_at'    => current_time( 'mysql' ),
                'created_gmt'   => gmdate( 'Y-m-d H:i:s' ),
                'dedupe_key'    => $dedupe_key,
            )
        );
        $wpdb->suppress_errors( $suppress );

        if ( ! $inserted ) {
            return 0;   // the same event is already queued
        }
        $this->prune_alert_events();
        return (int) $wpdb->insert_id;
    }

    /**
     * Keep the queue bounded: nothing older than ALERT_KEEP_DAYS and never more than
     * ALERT_KEEP_ROWS rows. Runs on a small fraction of the inserts so the write path
     * stays cheap on a busy shop.
     */
    private function prune_alert_events() {
        if ( 1 !== mt_rand( 1, 20 ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'events';

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_gmt < %s",
            gmdate( 'Y-m-d H:i:s', time() - self::ALERT_KEEP_DAYS * DAY_IN_SECONDS )
        ) );

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count <= self::ALERT_KEEP_ROWS ) return;

        $cutoff = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
            self::ALERT_KEEP_ROWS - 1
        ) );
        if ( $cutoff ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", (int) $cutoff ) );
        }
    }

    /**
     * `woocommerce_new_order` — an order was placed, through any payment method.
     *
     * Older WooCommerce versions pass only the id, so the order is looked up when the
     * second argument is missing.
     */
    public function record_new_order_event( $order_id, $order = null ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! ( $order instanceof WC_Order ) ) return;

        $order_id = (int) $order->get_id();

        // Remember it for the rest of this request: WooCommerce applies the first status
        // right after creation (pending → on-hold/processing) and that internal step is
        // the same event for the shop owner, not a second one.
        self::$alert_created_orders[ $order_id ] = true;

        // The order is still empty at this point in a real checkout; the row is written
        // now so the event survives an interrupted request, and its snapshot is filled in
        // as soon as the order is complete.
        $row_id = $this->record_alert_event( 'new_order', $order, '', $order->get_status() );
        if ( $row_id ) {
            self::$alert_new_order_rows[ $order_id ] = $row_id;
        }
    }

    /**
     * Refresh the snapshot of the new-order rows written during this request.
     *
     * Runs once the checkout (or the admin/REST/CLI flow) has finished filling the order,
     * so the popup shows the actual customer name, total, item count and payment method —
     * and those values are still there if the order is deleted later. Each row is handled
     * exactly once; the values that stay unavailable are left empty rather than guessed.
     */
    public function refresh_new_order_snapshots() {
        if ( ! self::$alert_new_order_rows ) return;

        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'events';
        $rows  = self::$alert_new_order_rows;
        self::$alert_new_order_rows = array();

        foreach ( $rows as $order_id => $row_id ) {
            $order = wc_get_order( $order_id );
            if ( ! ( $order instanceof WC_Order ) ) continue;

            $wpdb->update(
                $table,
                array(
                    'customer'      => $this->order_customer_name( $order ),
                    'order_number'  => (string) $order->get_order_number(),
                    'total_display' => $this->plain_text( $order->get_formatted_order_total() ),
                    'payment_title' => (string) $order->get_payment_method_title(),
                    'items_count'   => $this->order_items_count( $order ),
                    'view_url'      => esc_url_raw( (string) $order->get_edit_order_url() ),
                    'to_status'     => self::normalize_status( $order->get_status() ),
                ),
                array( 'id' => (int) $row_id )
            );
        }
    }

    /**
     * `woocommerce_order_status_changed` — the one standard flow every status change goes
     * through: orders list, order editor, REST API, payment gateways and this plugin's own
     * approve/reject actions. Deliberately not limited to card-to-card orders.
     */
    public function record_status_change_event( $order_id, $from_status, $to_status, $order = null ) {
        $order_id = (int) $order_id;
        if ( ! ( $order instanceof WC_Order ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! ( $order instanceof WC_Order ) ) return;

        // The status WooCommerce gives a brand-new order in the same request is not a
        // separate alert: the new-order event already covers it. On the way out, the
        // completed order also gives us the real snapshot for that event.
        if ( isset( self::$alert_created_orders[ $order_id ] ) ) {
            $this->refresh_new_order_snapshots();
            return;
        }
        if ( self::normalize_status( $from_status ) === self::normalize_status( $to_status ) ) return;

        $this->record_alert_event( 'status_change', $order, $from_status, $to_status );
    }

    /**
     * The alert queue carries customer names and order totals: managers only. The nonce is
     * a request-integrity check on top of the capability, never instead of it.
     */
    private function guard_alert_ajax() {
        if ( ! check_ajax_referer( 'wcr_admin_alert_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'درخواست نامعتبر است.', 'wc-bank-receipt' ) ), 403 );
        }
        if ( ! current_user_can( self::CAP_MANAGE ) ) {
            wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز', 'wc-bank-receipt' ) ), 403 );
        }
    }

    /**
     * Per-user cursor kept in user meta: it survives reloads (old events are never
     * re-alerted) and keeps two managers from consuming each other's alerts.
     *
     * @return int Event id, or -1 when this user has never polled.
     */
    private function alert_cursor( $user_id = 0 ) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $stored  = get_user_meta( $user_id, 'wcr_alert_cursor', true );
        return ( '' === $stored || null === $stored || false === $stored ) ? -1 : (int) $stored;
    }

    /**
     * One event, shaped for the popup.
     *
     * The stored row is a snapshot, so a deleted order still reads sensibly; while the
     * order exists the manager-visible values are read live through the WooCommerce API —
     * that is what makes the “current status” line truthful.
     */
    private function alert_event_payload( $row ) {
        $order_id = (int) $row->order_id;
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        $exists   = ( $order instanceof WC_Order );

        $type     = ( 'new_order' === (string) $row->event_type ) ? 'new_order' : 'status_change';
        $customer = (string) $row->customer;
        $total    = $this->plain_text( $row->total_display );
        $payment  = (string) $row->payment_title;
        $items    = (int) $row->items_count;
        $view_url = (string) $row->view_url;
        $from     = self::normalize_status( $row->from_status );
        $to       = self::normalize_status( $row->to_status );

        if ( $exists ) {
            $to       = self::normalize_status( $order->get_status() );
            $customer = $this->order_customer_name( $order );
            $total    = $this->plain_text( $order->get_formatted_order_total() );
            $payment  = (string) $order->get_payment_method_title();
            $items    = $this->order_items_count( $order );
            $view_url = (string) $order->get_edit_order_url();
        }

        $created = strtotime( (string) $row->created_gmt . ' +0000' );

        return array(
            'id'                => (int) $row->id,
            'type'              => $type,
            'type_label'        => 'new_order' === $type
                ? __( 'سفارش جدید', 'wc-bank-receipt' )
                : __( 'تغییر وضعیت سفارش', 'wc-bank-receipt' ),
            'order_id'          => $order_id,
            'order_number'      => '' !== (string) $row->order_number ? (string) $row->order_number : (string) $order_id,
            'customer'          => $customer,
            'customer_label'    => '' !== $customer ? $customer : __( 'مشتری مهمان (بدون نام)', 'wc-bank-receipt' ),
            'total'             => '' !== $total ? $total : '',
            'payment'           => '' !== $payment ? $payment : __( 'نامشخص', 'wc-bank-receipt' ),
            'status'            => $to,
            'status_label'      => '' !== $to ? $this->status_label( $to ) : __( 'نامشخص', 'wc-bank-receipt' ),
            'from_status'       => $from,
            'from_status_label' => '' !== $from ? $this->status_label( $from ) : '',
            'to_status'         => $to,
            'to_status_label'   => '' !== $to ? $this->status_label( $to ) : '',
            'items_count'       => $items,
            'time'              => $created ? (int) $created : 0,
            'time_display'      => $created ? wp_date( 'Y/m/d H:i:s', $created ) : '',
            'view_url'          => esc_url_raw( $view_url ),
            'order_exists'      => $exists,
        );
    }

    /**
     * Deliver the events this user has not acknowledged yet, oldest first.
     *
     * The cursor is advanced by the client (ajax_alerts_dismiss) once the popup has been
     * rendered, so a lost response cannot swallow an event. A user who has never polled
     * starts from “now” instead of replaying the whole retention window.
     */
    public function ajax_alerts_poll() {
        $this->guard_alert_ajax();

        global $wpdb;
        $table  = $wpdb->prefix . self::DB_PREFIX . 'events';
        $cursor = $this->alert_cursor();
        $sound  = $this->alert_sound_enabled();

        if ( $cursor < 0 ) {
            $cursor = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
            update_user_meta( get_current_user_id(), 'wcr_alert_cursor', $cursor );
            wp_send_json_success( array(
                'events'   => array(),
                'cursor'   => $cursor,
                'has_more' => false,
                'sound'    => $sound,
            ) );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $cursor,
            self::ALERT_BATCH_MAX
        ) );
        if ( ! is_array( $rows ) ) $rows = array();

        $events  = array();
        $last_id = $cursor;
        foreach ( $rows as $row ) {
            $events[] = $this->alert_event_payload( $row );
            $last_id  = (int) $row->id;
        }

        $has_more = false;
        if ( $events ) {
            $has_more = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id > %d",
                $last_id
            ) );
        }

        wp_send_json_success( array(
            'events'   => $events,
            'cursor'   => $last_id,
            'has_more' => $has_more,
            'sound'    => $sound,
        ) );
    }

    /**
     * Acknowledge events after they were shown.
     *
     * Rows are never deleted here: every manager owns a cursor, so one admin closing the
     * popup cannot swallow another admin's alerts.
     */
    public function ajax_alerts_dismiss() {
        $this->guard_alert_ajax();

        global $wpdb;
        $table   = $wpdb->prefix . self::DB_PREFIX . 'events';
        $user_id = get_current_user_id();

        $current = $this->alert_cursor( $user_id );
        if ( $current < 0 ) $current = 0;

        $last_id = isset( $_POST['last_id'] ) ? absint( wp_unslash( $_POST['last_id'] ) ) : 0;
        if ( ! $last_id ) {
            wp_send_json_success( array( 'cursor' => $current ) );
        }

        // Clamped to what actually exists: a stale or forged id can only ever move the
        // caller's own cursor forward, and never past the queue.
        $max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
        $target = min( $last_id, $max_id );

        if ( $target > $current ) {
            update_user_meta( $user_id, 'wcr_alert_cursor', $target );
        }

        wp_send_json_success( array( 'cursor' => max( $target, $current ) ) );
    }

    /**
     * The alert popup and its poller.
     *
     * Rendered only in wp-admin and only for users who may manage orders: the markup
     * carries the alert nonce and the endpoint behind it answers with customer names and
     * order totals. Every customer-supplied string reaches the DOM through textContent,
     * never through innerHTML.
     */
    public function render_admin_alert_popup() {
        if ( ! is_admin() || ! current_user_can( self::CAP_MANAGE ) ) return;

        $cfg = array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcr_admin_alert_nonce' ),
            'interval' => max( 3000, (int) apply_filters( 'wc_receipt_alert_poll_interval', 10000 ) ),
            'drain'    => 700,
            'cooldown' => max( 1000, (int) apply_filters( 'wc_receipt_alert_sound_cooldown', 4000 ) ),
            'sound'    => $this->alert_sound_enabled(),
            'labels'   => array(
                'title'        => __( 'اعلان سفارش', 'wc-bank-receipt' ),
                'order'        => __( 'سفارش', 'wc-bank-receipt' ),
                'customer'     => __( 'مشتری', 'wc-bank-receipt' ),
                'total'        => __( 'مبلغ کل', 'wc-bank-receipt' ),
                'payment'      => __( 'روش پرداخت', 'wc-bank-receipt' ),
                'items'        => __( 'تعداد اقلام', 'wc-bank-receipt' ),
                'status'       => __( 'وضعیت فعلی سفارش', 'wc-bank-receipt' ),
                'orderState'   => __( 'وضعیت سفارش', 'wc-bank-receipt' ),
                'transition'   => __( 'تغییر وضعیت', 'wc-bank-receipt' ),
                'event'        => __( 'نوع رویداد', 'wc-bank-receipt' ),
                'time'         => __( 'تاریخ و ساعت رویداد', 'wc-bank-receipt' ),
                'unknown'      => __( 'نامشخص', 'wc-bank-receipt' ),
                'view'         => __( 'مشاهده سفارش', 'wc-bank-receipt' ),
                'close'        => __( 'بستن', 'wc-bank-receipt' ),
                'closeAll'     => __( 'بستن همه', 'wc-bank-receipt' ),
                'remaining'    => __( 'رویداد دیگر در صف', 'wc-bank-receipt' ),
                'orderGone'    => __( 'حذف‌شده — اطلاعات از لحظه رویداد', 'wc-bank-receipt' ),
                'soundOn'      => __( 'فعال‌سازی صدا', 'wc-bank-receipt' ),
                'soundBlocked' => __( 'مرورگر پخش خودکار صدا را مسدود کرده است. برای فعال‌سازی صدای هشدار کلیک کنید.', 'wc-bank-receipt' ),
                'noAudio'      => __( 'مرورگر شما از پخش صدا پشتیبانی نمی‌کند؛ اعلان تصویری نمایش داده می‌شود.', 'wc-bank-receipt' ),
            ),
        );
        ?>
        <style>
        #wcr-alert-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.66);z-index:100000;align-items:center;justify-content:center;padding:20px;direction:rtl;font-family:Tahoma,"Segoe UI",sans-serif;}
        #wcr-alert-overlay.wcr-active{display:flex;}
        #wcr-alert-modal{background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.45);width:100%;max-width:560px;max-height:88vh;overflow:auto;animation:wcr-alert-pop .25s ease;}
        @keyframes wcr-alert-pop{from{transform:translateY(14px) scale(.97);opacity:0;}to{transform:none;opacity:1;}}
        #wcr-alert-modal .wcr-alert-top{display:flex;gap:14px;align-items:center;padding:20px 22px 12px;}
        #wcr-alert-modal .wcr-alert-bell{line-height:0;color:#f0b429;}
        #wcr-alert-modal .wcr-alert-bell svg{width:32px;height:32px;display:block;}
        #wcr-alert-modal h2{margin:0;font-size:18px;color:#0f172a;}
        #wcr-alert-modal .wcr-alert-sub{margin:2px 0 0;color:#64748b;font-size:13px;}
        #wcr-alert-modal .wcr-alert-badge{margin-inline-start:auto;font-size:12px;font-weight:700;padding:5px 10px;border-radius:999px;white-space:nowrap;}
        #wcr-alert-modal .wcr-alert-badge.is-new{background:#e3f2fd;color:#1565c0;}
        #wcr-alert-modal .wcr-alert-badge.is-change{background:#fff3e0;color:#e65100;}
        #wcr-alert-modal dl{display:grid;grid-template-columns:auto 1fr;gap:8px 14px;margin:0;padding:4px 22px 8px;font-size:14px;}
        #wcr-alert-modal dt{color:#64748b;margin:0;}
        #wcr-alert-modal dd{margin:0;color:#0f172a;font-weight:600;word-break:break-word;}
        #wcr-alert-modal dd.wcr-alert-transition{color:#e65100;}
        #wcr-alert-modal .wcr-alert-queue{margin:10px 22px 0;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;color:#475569;}
        #wcr-alert-modal .wcr-alert-queue b{color:#0f172a;}
        #wcr-alert-modal .wcr-alert-queue ul{margin:6px 0 0;padding-inline-start:18px;}
        #wcr-alert-modal .wcr-alert-queue button{background:none;border:none;padding:0;color:#1565c0;cursor:pointer;font:inherit;text-align:start;}
        #wcr-alert-modal .wcr-alert-blocked{margin:10px 22px 0;padding:10px 12px;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;font-size:13px;color:#8d6e00;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
        #wcr-alert-modal .wcr-alert-actions{display:flex;gap:10px;justify-content:flex-end;padding:16px 22px 20px;flex-wrap:wrap;}
        #wcr-alert-modal .wcr-alert-btn{font:inherit;font-weight:600;font-size:14px;padding:10px 16px;border-radius:9px;cursor:pointer;text-decoration:none;border:1px solid transparent;display:inline-block;line-height:1.4;}
        #wcr-alert-modal .wcr-alert-primary{background:#1565c0;color:#fff;}
        #wcr-alert-modal .wcr-alert-primary:hover{background:#0d47a1;color:#fff;}
        #wcr-alert-modal .wcr-alert-ghost{background:#f1f5f9;color:#334155;border-color:#e2e8f0;}
        #wcr-alert-modal [hidden]{display:none !important;}
        </style>

        <div id="wcr-alert-overlay" aria-hidden="true">
            <div id="wcr-alert-modal" role="dialog" aria-modal="true" aria-labelledby="wcr-alert-title">
                <div class="wcr-alert-top">
                    <!-- Inline SVG, not an emoji: WordPress core swaps emoji characters for images
                         from its CDN, which would make the popup depend on the network. -->
                    <span class="wcr-alert-bell" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor" focusable="false" aria-hidden="true"><path d="M12 2a2 2 0 0 0-2 2v.6C7.1 5.6 5.5 8 5.5 11v4l-1.6 2.1A1 1 0 0 0 4.7 19h14.6a1 1 0 0 0 .8-1.9L18.5 15v-4c0-3-1.6-5.4-4.5-6.4V4a2 2 0 0 0-2-2Zm0 20a2.8 2.8 0 0 0 2.7-2H9.3A2.8 2.8 0 0 0 12 22Z"/></svg></span>
                    <div>
                        <h2 id="wcr-alert-title"><?php echo esc_html( $cfg['labels']['title'] ); ?></h2>
                        <p class="wcr-alert-sub" id="wcr-alert-sub"></p>
                    </div>
                    <span class="wcr-alert-badge" id="wcr-alert-badge"></span>
                </div>
                <dl id="wcr-alert-facts"></dl>
                <div class="wcr-alert-queue" id="wcr-alert-queue" hidden></div>
                <div class="wcr-alert-blocked" id="wcr-alert-blocked" hidden>
                    <span id="wcr-alert-blocked-msg"></span>
                    <button type="button" class="wcr-alert-ghost" id="wcr-alert-sound"></button>
                </div>
                <div class="wcr-alert-actions">
                    <a class="wcr-alert-btn wcr-alert-primary" id="wcr-alert-view" href="#"
                       target="_blank" rel="noopener noreferrer" hidden><?php echo esc_html( $cfg['labels']['view'] ); ?></a>
                    <button type="button" class="wcr-alert-ghost" id="wcr-alert-close"></button>
                </div>
            </div>
        </div>

        <script>
        window.WCR_ALERT_CFG = <?php echo wp_json_encode( $cfg ); ?>;
        (function () {
            var cfg = window.WCR_ALERT_CFG;
            var overlay = document.getElementById('wcr-alert-overlay');
            if (!cfg || !overlay) return;

            var L          = cfg.labels;
            var factsBox   = document.getElementById('wcr-alert-facts');
            var queueBox   = document.getElementById('wcr-alert-queue');
            var badgeEl    = document.getElementById('wcr-alert-badge');
            var titleEl    = document.getElementById('wcr-alert-title');
            var subEl      = document.getElementById('wcr-alert-sub');
            var viewBtn    = document.getElementById('wcr-alert-view');
            var closeBtn   = document.getElementById('wcr-alert-close');
            var soundBtn   = document.getElementById('wcr-alert-sound');
            var blockedBox = document.getElementById('wcr-alert-blocked');
            var blockedMsg = document.getElementById('wcr-alert-blocked-msg');

            var queue    = [];   // delivered events, oldest first
            var cursor   = 0;    // highest id acknowledged for this user
            var seen     = {};   // ids already shown during this page load
            var inflight = false;
            var timer    = null;
            var backoff  = 0;
            var drains   = 0;
            var lastBeep = 0;
            var audio    = null;
            var soundOn  = !!cfg.sound;

            /* ---------------------------------------------------------------- *
             * Transport
             * ---------------------------------------------------------------- */
            function post(action, extra, done) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', cfg.ajaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
                    var data = null;
                    try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                    var ok = xhr.status >= 200 && xhr.status < 300 && data && data.success;
                    done(ok ? data.data : null);
                };
                try {
                    xhr.send('action=' + action + '&nonce=' + encodeURIComponent(cfg.nonce) + (extra || ''));
                } catch (e) {
                    if (typeof done === 'function') done(null);
                }
            }

            function ack() {
                if (cursor <= 0) return;
                post('wcr_alerts_dismiss', '&last_id=' + cursor, function () {});
            }

            function schedule(ms) {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (document.hidden) { schedule(cfg.interval); return; }
                    poll();
                }, ms);
            }

            function poll() {
                if (inflight) return;
                inflight = true;
                post('wcr_alerts_poll', '', function (data) {
                    inflight = false;
                    if (!data) {
                        // A temporary failure must never stop the alerts for good:
                        // back off and keep trying.
                        backoff = Math.min(backoff ? backoff * 2 : 5000, 60000);
                        schedule(backoff);
                        return;
                    }
                    backoff = 0;
                    if (data.sound === false) soundOn = false;

                    var fresh = [];
                    (data.events || []).forEach(function (e) {
                        if (!seen[e.id]) { seen[e.id] = true; fresh.push(e); }
                    });

                    if (fresh.length) {
                        queue = queue.concat(fresh);
                        render();
                        play(false);
                    }
                    if (typeof data.cursor === 'number' && data.cursor > cursor) {
                        cursor = data.cursor;
                        ack();                      // shown → acknowledged
                    }
                    if (data.has_more && drains < 5) {
                        drains++;
                        schedule(cfg.drain);
                    } else {
                        drains = 0;
                        schedule(cfg.interval);
                    }
                });
            }

            /* ---------------------------------------------------------------- *
             * Sound — generated locally with the Web Audio API: no file, no network.
             * A blocked or failing beep never prevents the popup from showing.
             * ---------------------------------------------------------------- */
            function audioContext() {
                if (audio) return audio;
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return null;
                try { audio = new Ctx(); } catch (e) { audio = null; }
                return audio;
            }

            function showBlocked(msg) {
                blockedMsg.textContent = msg || L.soundBlocked;
                soundBtn.textContent = L.soundOn;
                soundBtn.hidden = !soundOn;
                blockedBox.hidden = false;
            }

            function beep(ctx) {
                var t    = ctx.currentTime;
                var gain = ctx.createGain();
                gain.connect(ctx.destination);
                gain.gain.setValueAtTime(0.0001, t);
                gain.gain.exponentialRampToValueAtTime(0.22, t + 0.05);
                gain.gain.exponentialRampToValueAtTime(0.0001, t + 1.05);
                [880, 660].forEach(function (freq, i) {
                    var osc = ctx.createOscillator();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    osc.connect(gain);
                    osc.start(t + i * 0.16);
                    osc.stop(t + 1.05);
                });
            }

            function play(force) {
                if (!soundOn) return false;
                var now = Date.now();
                // Burst control: a wave of status changes beeps once, not once per event.
                if (!force && lastBeep && (now - lastBeep) < cfg.cooldown) return false;

                var ctx = audioContext();
                if (!ctx) { showBlocked(L.noAudio); return false; }

                var running = false;
                try {
                    if (ctx.state === 'suspended') ctx.resume();
                    running = (ctx.state === 'running');
                } catch (e) { running = false; }
                if (!running) { showBlocked(L.soundBlocked); return false; }

                try {
                    beep(ctx);
                    lastBeep = now;
                    blockedBox.hidden = true;
                    return true;
                } catch (e) {
                    showBlocked(L.soundBlocked);
                    return false;
                }
            }

            /* ---------------------------------------------------------------- *
             * Rendering — every value goes in as text, never as HTML.
             * ---------------------------------------------------------------- */
            function fact(label, value, className) {
                var dt = document.createElement('dt');
                dt.textContent = label;
                var dd = document.createElement('dd');
                dd.textContent = (value === '' || value === null || typeof value === 'undefined')
                    ? L.unknown
                    : String(value);
                if (className) dd.className = className;
                factsBox.appendChild(dt);
                factsBox.appendChild(dd);
            }

            function render() {
                var e = queue[0];
                if (!e) {
                    overlay.classList.remove('wcr-active');
                    overlay.setAttribute('aria-hidden', 'true');
                    return;
                }

                badgeEl.textContent = e.type_label;
                badgeEl.className = 'wcr-alert-badge ' + (e.type === 'new_order' ? 'is-new' : 'is-change');
                titleEl.textContent = L.order + ' #' + e.order_number;
                subEl.textContent = e.type_label + ' — ' + e.time_display;

                while (factsBox.firstChild) factsBox.removeChild(factsBox.firstChild);
                fact(L.customer, e.customer_label);
                fact(L.total, e.total);
                fact(L.payment, e.payment);
                fact(L.items, e.items_count);
                if (e.order_exists) {
                    fact(L.status, e.status_label);
                } else {
                    fact(L.orderState, L.orderGone);
                }
                if (e.type === 'status_change') {
                    fact(L.transition,
                        (e.from_status_label || L.unknown) + ' → ' + (e.to_status_label || L.unknown),
                        'wcr-alert-transition');
                }
                fact(L.event, e.type_label);
                fact(L.time, e.time_display);

                var url = e.view_url || '';
                if (e.order_exists && /^https?:\/\//i.test(url)) {
                    viewBtn.setAttribute('href', url);
                    viewBtn.hidden = false;
                } else {
                    viewBtn.removeAttribute('href');
                    viewBtn.hidden = true;
                }

                // Queue strip: everything still waiting behind the event on screen.
                while (queueBox.firstChild) queueBox.removeChild(queueBox.firstChild);
                if (queue.length > 1) {
                    var head = document.createElement('div');
                    head.appendChild(document.createTextNode((queue.length - 1) + ' ' + L.remaining + ' '));
                    var strong = document.createElement('b');
                    strong.textContent = '(' + queue.length + ')';
                    head.appendChild(strong);
                    queueBox.appendChild(head);

                    var ul = document.createElement('ul');
                    queue.slice(1).forEach(function (item) {
                        var li  = document.createElement('li');
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = '#' + item.order_number + ' — ' + item.type_label + ' — ' + item.time_display;
                        btn.addEventListener('click', function () {
                            queue = [item].concat(queue.filter(function (q) { return q.id !== item.id; }));
                            render();
                        });
                        li.appendChild(btn);
                        ul.appendChild(li);
                    });
                    queueBox.appendChild(ul);
                    queueBox.hidden = false;
                    closeBtn.textContent = L.closeAll;
                } else {
                    queueBox.hidden = true;
                    closeBtn.textContent = L.close;
                }

                overlay.classList.add('wcr-active');
                overlay.setAttribute('aria-hidden', 'false');
            }

            function closeCurrent() {
                if (!queue.length) return;
                queue.shift();
                render();
            }

            closeBtn.addEventListener('click', function () {
                // The label is “close” for a single card and “close all” while more
                // events wait, so the button has to honour it: clear the whole queue.
                // Closing one card at a time stays available with the Escape key.
                if (queue.length > 1) {
                    queue = [];
                    render();
                } else {
                    closeCurrent();
                }
            });
            soundBtn.addEventListener('click', function () {
                // A real click is the user gesture an autoplay-blocked browser waits for.
                play(true);
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && overlay.classList.contains('wcr-active')) closeCurrent();
            });
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) { clearTimeout(timer); if (!inflight) poll(); }
            });
            // The first interaction with any admin page also unlocks the audio context, so
            // the next event beeps without the admin having to click anything.
            ['click', 'keydown'].forEach(function (evt) {
                document.addEventListener(evt, function () {
                    var ctx = audioContext();
                    if (ctx && ctx.state === 'suspended') { try { ctx.resume(); } catch (e) {} }
                    if (ctx && ctx.state === 'running' && !blockedBox.hidden) blockedBox.hidden = true;
                }, { once: true });
            });

            schedule(1500);
        })();
        </script>
        <?php
    }

    // =========================================================================
    // APPEARANCE SCREEN («ظاهر و استایل»)
    //
    // One screen with one job: turn design tokens into a saved appearance. It writes
    // exactly one option (WCR_Design_Tokens::OPTION) and never touches orders,
    // receipts, cards, logs or files. The preview is a *sample*: it needs a form to
    // show, so it is built from the same markup helpers the live panel uses, with
    // placeholder values — never a real order, a real card row or customer data.
    // =========================================================================

    /**
     * The appearance settings screen.
     */
    public function appearance_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'دسترسی غیرمجاز', 'wc-bank-receipt' ), '', array( 'response' => 403 ) );
        }

        $notice = '';

        /*
         * Reset the *appearance* only. Deliberately a separate, nonce-checked action:
         * the destructive data reset lives on the settings screen and means something
         * else entirely. Cards, orders, receipts, logs and files are not read here.
         */
        if ( isset( $_POST['reset_appearance'] ) && check_admin_referer( 'reset_appearance_nonce' ) ) {
            delete_option( WCR_Design_Tokens::OPTION );
            $notice = __( 'ظاهر پنل به پیش‌فرض افزونه بازگشت. کارت‌ها، سفارش‌ها، فیش‌ها، لاگ‌ها و تنظیمات دیگر دست‌نخورده ماندند.', 'wc-bank-receipt' );
        }

        $settings = WCR_Design_Tokens::get();
        $resolved = WCR_Design_Tokens::resolve();
        $palette  = WCR_Design_Tokens::theme_palette();
        $schema   = WCR_Design_Tokens::schema();
        ?>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>

        <div class="wcr-wrap">
            <div class="wcr-header">
                <span class="dashicons dashicons-art"></span>
                <h1><?php esc_html_e( 'ظاهر و استایل', 'wc-bank-receipt' ); ?></h1>
            </div>

            <p class="wcr-hint" style="margin-bottom:18px;">
                <?php esc_html_e( 'همهٔ تنظیمات این صفحه فقط روی پنل فیش بانکی (کارت‌های نمونه، شورت‌کد و ویجت المنتور) اثر دارد. هیچ‌کدام از این مقدارها روی دکمه‌ها، ورودی‌ها، تیترها یا فاصله‌های بقیهٔ سایت اثر نمی‌گذارد و هیچ فونت خارجی بارگذاری نمی‌شود.', 'wc-bank-receipt' ); ?>
            </p>

            <div class="wcr-card" style="margin-bottom:22px;">
                <h2><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'پیش‌نمایش', 'wc-bank-receipt' ); ?></h2>
                <p class="wcr-hint">
                    <?php esc_html_e( 'این نمونهٔ زنده با داده‌های نمایشی ساخته می‌شود و به هیچ سفارش، کارت یا اطلاعات مشتری وابسته نیست.', 'wc-bank-receipt' ); ?>
                    <span class="wcr-badge info" data-wcr-appearance-state><?php esc_html_e( 'بر اساس تنظیمات ذخیره‌شده', 'wc-bank-receipt' ); ?></span>
                </p>
                <?php echo $this->appearance_preview_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( WCR_Design_Tokens::GROUP ); ?>

                <div class="wcr-card">
                    <h2><span class="dashicons dashicons-admin-customizer"></span> <?php esc_html_e( 'حالت طراحی', 'wc-bank-receipt' ); ?></h2>
                    <table class="wcr-ftbl">
                        <tr>
                            <th><?php esc_html_e( 'حالت', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <select name="wc_receipt_appearance[mode]" data-wcr-appearance-mode>
                                    <?php foreach ( WCR_Design_Tokens::modes() as $key => $mode ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['mode'], $key ); ?>><?php echo esc_html( $mode['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wcr-hint">
                                    <?php
                                    foreach ( WCR_Design_Tokens::modes() as $key => $mode ) {
                                        echo esc_html( $mode['label'] . ': ' . $mode['hint'] ) . '<br>';
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'پیش‌تنظیم', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <select name="wc_receipt_appearance[preset]" data-wcr-appearance-preset>
                                    <?php foreach ( WCR_Design_Tokens::presets() as $key => $preset ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" data-wcr-preset-hint="<?php echo esc_attr( $preset['hint'] ); ?>" <?php selected( $settings['preset'], $key ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wcr-hint"><?php esc_html_e( 'فقط در حالت «پیش‌تنظیم» اعمال می‌شود. هر مقداری که در پایین خودتان پر کنید، روی پیش‌تنظیم اولویت دارد.', 'wc-bank-receipt' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'چیدمان', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <select name="wc_receipt_appearance[density]" data-wcr-appearance-density>
                                    <?php foreach ( WCR_Design_Tokens::densities() as $key => $density ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['density'], $key ); ?>><?php echo esc_html( $density['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wcr-hint"><?php esc_html_e( 'فشرده برای ستون‌های باریک و سایدبار؛ در حالت «سفارشی» فاصلهٔ داخلی و بین اجزا از مقدارهای پایین صفحه استفاده می‌کند.', 'wc-bank-receipt' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'هماهنگی رنگ تأکیدی', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_receipt_appearance[couple]" value="yes" data-wcr-appearance-couple <?php checked( $settings['couple'], 'yes' ); ?>>
                                    <?php esc_html_e( 'رنگ اصلی، رنگ دکمهٔ ارسال و کارت بانکی را هم تنظیم کند', 'wc-bank-receipt' ); ?>
                                </label>
                                <p class="wcr-hint"><?php esc_html_e( 'با تغییر «رنگ اصلی» در بخش رنگ‌ها، سایهٔ هاور، دکمهٔ ارسال و گرادیان کارت بانکی از همان رنگ ساخته می‌شوند. هر رنگی که خودتان در فیلد دکمه/کارت وارد کنید یا از پالت قالب انتخاب کنید، همیشه اولویت دارد.', 'wc-bank-receipt' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'هماهنگی با پالت قالب', 'wc-bank-receipt' ); ?></th>
                            <td>
                                <?php foreach ( WCR_Design_Tokens::links() as $link_key => $link ) : ?>
                                    <?php
                                    $stored_slug = isset( $settings['links'][ $link_key ] ) ? $settings['links'][ $link_key ] : '';
                                    ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <?php echo esc_html( $link['label'] ); ?><br>
                                        <select name="wc_receipt_appearance[links][<?php echo esc_attr( $link_key ); ?>]" style="max-width:420px;" data-wcr-appearance-link="<?php echo esc_attr( $link_key ); ?>">
                                            <option value=""><?php esc_html_e( 'غیرفعال — رنگ پیش‌فرض افزونه', 'wc-bank-receipt' ); ?></option>
                                            <?php foreach ( $palette as $slug => $name ) : ?>
                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $stored_slug, $slug ); ?>>--wp--preset--color--<?php echo esc_html( $slug ); ?> (<?php echo esc_html( $name ); ?>)</option>
                                            <?php endforeach; ?>
                                            <?php if ( '' !== $stored_slug && ! isset( $palette[ $stored_slug ] ) ) : ?>
                                                <option value="<?php echo esc_attr( $stored_slug ); ?>" selected><?php echo esc_html( $stored_slug ); ?> <?php esc_html_e( '(در پالت فعلی نیست)', 'wc-bank-receipt' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </label>
                                <?php endforeach; ?>
                                <p class="wcr-hint">
                                    <?php esc_html_e( 'این سه گزینه فقط رنگ‌های قالب را «می‌خوانند» و هیچ‌وقت آن‌ها را تغییر نمی‌دهند. قالب‌های بلاکی و قالب‌هایی که theme.json دارند این رنگ‌ها را در اختیار می‌گذارند؛ در قالب‌های کلاسیک که این متغیرها وجود ندارند، همان رنگ پیش‌فرض افزونه استفاده می‌شود.', 'wc-bank-receipt' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php foreach ( WCR_Design_Tokens::groups() as $group_key => $group_label ) : ?>
                    <div class="wcr-card">
                        <h2><span class="dashicons dashicons-art"></span> <?php echo esc_html( $group_label ); ?></h2>
                        <table class="wcr-ftbl">
                            <?php
                            foreach ( $schema as $token => $spec ) :
                                if ( $group_key !== $spec['group'] ) {
                                    continue;
                                }
                                $this->appearance_field( $token, $spec, $settings, $resolved );
                            endforeach;
                            ?>
                        </table>
                    </div>
                <?php endforeach; ?>

                <?php submit_button( __( 'ذخیره ظاهر', 'wc-bank-receipt' ) ); ?>
            </form>

            <div class="wcr-card">
                <h2><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'بازنشانی ظاهر', 'wc-bank-receipt' ); ?></h2>
                <p class="wcr-hint"><?php esc_html_e( 'ظاهر پنل به پیش‌فرض افزونه برمی‌گردد. کارت‌های بانکی، سفارش‌ها، فیش‌ها، تنظیمات دیگر و لاگ‌ها دست‌نخورده می‌مانند.', 'wc-bank-receipt' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'reset_appearance_nonce' ); ?>
                    <button type="submit" name="reset_appearance" value="1" class="wcr-btn wcr-btn-ghost"><?php esc_html_e( 'بازگشت به ظاهر پیش‌فرض', 'wc-bank-receipt' ); ?></button>
                </form>
                <p class="wcr-hint">
                    <?php esc_html_e( 'فرم را دست‌نخورده رها کنید (خالی) تا هر مقدار «خودکار» بماند؛ «خودکار» یعنی مقدار حالت انتخاب‌شده بالا استفاده شود.', 'wc-bank-receipt' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * One row of the appearance form, rendered from the schema.
     *
     * A field is a *text* input plus helpers rather than a fixed colour/number widget,
     * because every token must be resettable to “automatic”: an empty field means “the
     * mode/theme layer decides”, which a native `input[type=color]` cannot express. The
     * value that the field submits is always the plain value — validation happens on the
     * server in WCR_Design_Tokens::sanitize().
     *
     * @param string $token    Token name (`--wcrf-…`).
     * @param array  $spec     Schema entry.
     * @param array  $settings Stored settings.
     * @param array  $resolved Currently effective values.
     */
    private function appearance_field( $token, $spec, $settings, $resolved ) {
        $name     = WCR_Design_Tokens::OPTION . '[tokens][' . $token . ']';
        $stored   = isset( $settings['tokens'][ $token ] ) ? (string) $settings['tokens'][ $token ] : '';
        $fallback = isset( $resolved[ $token ] ) ? (string) $resolved[ $token ] : '';
        $label    = isset( $spec['label'] ) ? $spec['label'] : $token;
        $hint     = isset( $spec['hint'] ) ? $spec['hint'] : '';
        /* A number input cannot show a unit, so the placeholder is the bare number;
         * a keyword (e.g. the default line-height `inherit`) has no placeholder at all
         * and is explained by the hint below the field instead. */
        $placeholder = $fallback;
        if ( 'length' === $spec['type'] ) {
            $placeholder = preg_replace( '/[a-z%]+$/', '', $fallback );
        } elseif ( 'ratio' === $spec['type'] && ! preg_match( '/^\d/', $fallback ) ) {
            $placeholder = '';
        }
        $hint    .= $stored ? '' : ' ' . sprintf(
            /* translators: %s: the value that applies while the field is left empty. */
            __( '(خودکار: %s)', 'wc-bank-receipt' ),
            $fallback
        );
        ?>
        <tr>
            <th><?php echo esc_html( $label ); ?></th>
            <td>
                <?php if ( 'enum' === $spec['type'] ) : ?>
                    <select name="<?php echo esc_attr( $name ); ?>" data-wcr-token="<?php echo esc_attr( $token ); ?>" style="max-width:420px;">
                        <option value=""><?php esc_html_e( 'خودکار', 'wc-bank-receipt' ); ?></option>
                        <?php foreach ( $spec['options'] as $key => $option ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $stored, $key ); ?>><?php echo esc_html( $option['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ( 'color' === $spec['type'] ) : ?>
                    <?php $swatch = preg_match( '/^#[0-9a-f]{3,6}$/i', $stored ) ? $stored : ( preg_match( '/^#[0-9a-f]{3,6}$/i', $fallback ) ? $fallback : '#ffffff' ); ?>
                    <div class="wcr-style-row">
                        <input type="text" class="wcr-token-text" name="<?php echo esc_attr( $name ); ?>"
                            value="<?php echo esc_attr( $stored ); ?>"
                            data-wcr-token="<?php echo esc_attr( $token ); ?>"
                            placeholder="<?php echo esc_attr( $fallback ); ?>">
                        <input type="color" class="wcr-token-swatch" value="<?php echo esc_attr( $swatch ); ?>"
                            data-wcr-swatch-for="<?php echo esc_attr( $token ); ?>"
                            aria-label="<?php echo esc_attr( sprintf( __( 'انتخاب رنگ برای %s', 'wc-bank-receipt' ), $label ) ); ?>">
                        <button type="button" class="wcr-btn wcr-btn-ghost wcr-token-clear" data-wcr-clear="<?php echo esc_attr( $token ); ?>"><?php esc_html_e( 'خودکار', 'wc-bank-receipt' ); ?></button>
                    </div>
                <?php elseif ( 'length' === $spec['type'] && ! empty( $spec['ranges'] ) && count( $spec['ranges'] ) > 1 ) : ?>
                    <?php
                    $parts  = $this->appearance_length_parts( $spec, $stored );
                    $base   = WCR_Design_Tokens::OPTION . '[tokens][' . $token . ']';
                    ?>
                    <div class="wcr-style-row">
                        <input type="number" step="any" style="max-width:110px;"
                            name="<?php echo esc_attr( $base ); ?>[num]"
                            value="<?php echo esc_attr( $parts['num'] ); ?>"
                            data-wcr-token-num="<?php echo esc_attr( $token ); ?>"
                            placeholder="<?php echo esc_attr( $fallback ); ?>">
                        <select name="<?php echo esc_attr( $base ); ?>[unit]" style="max-width:120px;" data-wcr-token-unit="<?php echo esc_attr( $token ); ?>">
                            <option value=""><?php esc_html_e( 'خودکار', 'wc-bank-receipt' ); ?></option>
                            <?php foreach ( array_keys( $spec['ranges'] ) as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $parts['unit'], $unit ); ?>><?php echo esc_html( $unit ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="wcr-btn wcr-btn-ghost wcr-token-clear" data-wcr-clear="<?php echo esc_attr( $token ); ?>"><?php esc_html_e( 'خودکار', 'wc-bank-receipt' ); ?></button>
                    </div>
                <?php else : ?>
                    <?php
                    $is_ratio = ( 'ratio' === $spec['type'] );
                    $num      = $stored;
                    if ( 'length' === $spec['type'] && '' !== $stored ) {
                        $num = (string) floatval( $stored );
                    }
                    ?>
                    <div class="wcr-style-row">
                        <input type="number" style="max-width:110px;"
                            name="<?php echo esc_attr( $name ); ?>"
                            value="<?php echo esc_attr( $num ); ?>"
                            step="<?php echo esc_attr( isset( $spec['step'] ) ? $spec['step'] : 1 ); ?>"
                            <?php if ( ! $is_ratio ) : ?>min="<?php echo esc_attr( $spec['min'] ); ?>" max="<?php echo esc_attr( $spec['max'] ); ?>"<?php endif; ?>
                            data-wcr-token="<?php echo esc_attr( $token ); ?>"
                            placeholder="<?php echo esc_attr( $placeholder ); ?>">
                        <span class="wcr-hint"><?php echo esc_html( $is_ratio ? '' : 'px' ); ?></span>
                        <button type="button" class="wcr-btn wcr-btn-ghost wcr-token-clear" data-wcr-clear="<?php echo esc_attr( $token ); ?>"><?php esc_html_e( 'خودکار', 'wc-bank-receipt' ); ?></button>
                    </div>
                <?php endif; ?>
                <?php if ( '' !== $hint ) : ?>
                    <p class="wcr-hint"><?php echo esc_html( $hint ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Split a stored type-scale value (`16px`, `1.2em`) into its number and unit.
     *
     * @param array  $spec   Schema entry (for the accepted units).
     * @param string $stored
     * @return array { num: string, unit: string }
     */
    private function appearance_length_parts( $spec, $stored ) {
        $units = array_keys( $spec['ranges'] );
        if ( preg_match( '/^(\d+(?:\.\d+)?)([a-z]+)$/', $stored, $m ) && in_array( $m[2], $units, true ) ) {
            return array( 'num' => $m[1], 'unit' => $m[2] );
        }
        if ( preg_match( '/^\d+(?:\.\d+)?$/', $stored ) ) {
            return array( 'num' => $stored, 'unit' => 'px' );
        }
        return array( 'num' => '', 'unit' => '' );
    }

    /**
     * The live preview: the real component — same classes, same stylesheet, same script —
     * filled with sample values.
     *
     * It is not a second renderer: the bank card and the upload zone come from the very
     * helpers the customer-facing panel uses, and each of them is explicitly a sample
     * (no order id, no account row, no nonce, no upload target). That is what keeps the
     * preview honest *and* independent of any customer data.
     *
     * @return string
     */
    private function appearance_preview_html() {
        $decl = WCR_Design_Tokens::declarations( WCR_Design_Tokens::resolve() );

        $card = (object) array(
            'bank_name'      => __( 'بانک نمونه', 'wc-bank-receipt' ),
            'card_number'    => '0000 0000 0000 0000',
            'account_holder' => __( 'نام و نام خانوادگی دارندهٔ حساب', 'wc-bank-receipt' ),
            'sheba'          => 'IR000000000000000000000000',
        );

        $ctx = array(
            'accept'     => '.jpg,.png,.pdf',
            'ext_labels' => 'JPEG, PNG, PDF',
            'max_kb'     => 2048,
        );

        ob_start();
        ?>
        <div class="wcrf-panel" dir="rtl" data-wcr-appearance-preview<?php echo ( '' !== $decl ) ? ' style="' . esc_attr( $decl ) . '"' : ''; ?>>
            <?php echo $this->bank_card_markup( $card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            <div class="wcrf-timer-box" data-wcr-timer data-remaining="6300" data-total="7200">
                <div class="wcrf-timer-lbl"><?php esc_html_e( 'فرصت باقی‌مانده:', 'wc-bank-receipt' ); ?></div>
                <strong class="wcrf-timer-val" aria-live="off">01:45:00</strong>
                <div class="wcrf-progress"><div class="wcrf-progress-fill" style="width:87%"></div></div>
            </div>
            <div class="wcrf-status-box pending">
                <div class="wcrf-status-title">⏳ <?php esc_html_e( 'در انتظار بررسی مدیریت', 'wc-bank-receipt' ); ?></div>
                <?php /* A sample link with no target: the preview must not navigate anywhere. */ ?>
                <span class="wcrf-prev-link"><?php esc_html_e( 'مشاهده فیش ارسالی', 'wc-bank-receipt' ); ?></span>
            </div>
            <div class="wcrf-form">
                <?php echo $this->drop_zone_markup( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                <button type="button" class="wcrf-submit-btn">📤 <?php esc_html_e( 'ارسال فیش واریزی', 'wc-bank-receipt' ); ?></button>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The data the live preview needs, for wp_localize_script.
     *
     * Only values and labels — no behaviour, no markup. The script recomputes the same
     * resolution the PHP resolver does (defaults → preset → theme links → your fields →
     * density) so the preview keeps up with the form; the *saved* value is always the
     * one the server validated.
     *
     * @return array
     */
    private function appearance_script_config() {
        $schema   = WCR_Design_Tokens::schema();
        $tokens   = array();
        $presets  = array();

        foreach ( $schema as $name => $spec ) {
            $entry = array(
                'type'    => $spec['type'],
                'default' => WCR_Design_Tokens::css_default( $name ),
                'ranges'  => isset( $spec['ranges'] ) ? $spec['ranges'] : null,
                'min'     => isset( $spec['min'] ) ? $spec['min'] : null,
                'max'     => isset( $spec['max'] ) ? $spec['max'] : null,
            );
            if ( 'enum' === $spec['type'] ) {
                $entry['options'] = array();
                foreach ( $spec['options'] as $key => $option ) {
                    $entry['options'][ $key ] = $option['value'];
                }
            }
            $tokens[ $name ] = $entry;
        }

        foreach ( WCR_Design_Tokens::presets() as $key => $preset ) {
            $presets[ $key ] = $preset['tokens'];
        }

        $densities = array();
        foreach ( WCR_Design_Tokens::densities() as $key => $density ) {
            $densities[ $key ] = isset( $density['tokens'] ) ? $density['tokens'] : array();
        }

        /* Per link, the tokens it drives and the `var()` template with a placeholder for
         * the colour slug, so the script writes the same expression the server writes. */
        $links = array();
        foreach ( WCR_Design_Tokens::links() as $key => $link ) {
            $map = array();
            foreach ( $link['tokens'] as $name ) {
                $map[ $name ] = WCR_Design_Tokens::theme_expression( $name, '__SLUG__' );
            }
            $links[ $key ] = $map;
        }

        return array(
            'tokens'    => $tokens,
            'presets'   => $presets,
            'densities' => $densities,
            'links'     => $links,
            'defaults'  => WCR_Design_Tokens::defaults(),
            'strings'   => array(
                'saved' => __( 'بر اساس تنظیمات ذخیره‌شده', 'wc-bank-receipt' ),
                'dirty' => __( 'پیش‌نمایش تغییرات ذخیره‌نشده (تا ذخیره نکنید روی سایت اعمال نمی‌شود)', 'wc-bank-receipt' ),
            ),
        );
    }

    // FRONTEND ENTRY POINTS
    // =========================================================================

    /**
     * Resolve the order a display method should describe.
     *
     * Explicit id first (shortcode attribute / widget control), then the order the
     * current page is about. Returns null when there is no order context at all —
     * the caller decides what an empty context looks like.
     *
     * @param int $explicit_id
     * @return WC_Order|null
     */
    public function resolve_context_order( $explicit_id = 0 ) {
        if ( ! $this->woocommerce_is_available() ) return null;

        $order_id = absint( $explicit_id );
        if ( ! $order_id ) {
            foreach ( array( 'order-received', 'view-order' ) as $qv ) {
                $order_id = absint( get_query_var( $qv ) );
                if ( $order_id ) break;
            }
        }
        if ( ! $order_id ) return null;

        $order = wc_get_order( $order_id );
        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * Does the content of the current page already render the panel itself?
     *
     * True when the page (or the Elementor layout stored in post meta, which never
     * appears in post_content) contains the shortcode. The automatic hooks stay quiet
     * in that case, so the panel is never printed twice on one page.
     */
    private static function page_renders_shortcode() {
        $post = get_post();
        if ( ! $post instanceof WP_Post ) return false;

        if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) return true;

        $elementor = get_post_meta( $post->ID, '_elementor_data', true );
        return ( is_string( $elementor ) && '' !== $elementor && strpos( $elementor, self::SHORTCODE ) !== false );
    }

    /**
     * Layer C, method 2 of 3 — the public `[wc_bank_receipt]` shortcode.
     *
     * Safe wherever it is placed: a page without an order context renders nothing
     * (never a random order), and a visitor who is not allowed to see the order gets
     * a neutral notice with no order data. Authorization happens in layer A, so the
     * shortcode cannot be used to read somebody else’s order — an order id in the
     * attribute is a *selector*, never a credential.
     *
     * @param array $atts { order_id: int, style: string }
     * @return string
     */
    public function shortcode_receipt_panel( $atts = array() ) {
        if ( ! $this->woocommerce_is_available() ) return '';

        $atts  = shortcode_atts( array( 'order_id' => 0, 'style' => '' ), $atts, self::SHORTCODE );
        $order = $this->resolve_context_order( $atts['order_id'] );

        if ( ! $order ) {
            return (string) apply_filters( 'wcr_shortcode_empty_html', '', $atts );
        }

        // The WooCommerce endpoint hook already printed this order’s panel in this
        // request — e.g. the shortcode sits on the checkout/thank-you page. Rendering it
        // again would duplicate the panel, so this placement stays empty.
        if ( $this->auto_panel_printed( $order->get_id() ) ) {
            return '';
        }

        self::enqueue_frontend_assets();

        /*
         * `style` picks a visual preset for **this placement only** (whitelisted inside
         * the design-token layer; an unknown value is ignored and the global appearance
         * applies). It never influences authorization, the order context or the upload.
         */
        return $this->receipt_panel_html(
            $order,
            array(
                'source' => 'shortcode',
                'style'  => is_scalar( $atts['style'] ) ? (string) $atts['style'] : '',
            )
        );
    }

    /**
     * Layer C, method 3 of 3 — a thin Elementor widget.
     *
     * The hook only fires when Elementor is active, and the widget class is declared
     * here (never at file scope), so a site without Elementor can never fatal on a
     * missing base class. The widget reads no order, authorizes nothing and uploads
     * nothing: it collects an optional order id and calls the shared renderer, which
     * keeps one implementation for logic, markup, style and behaviour.
     *
     * @param object $widgets_manager Elementor widget manager.
     */
    public function register_elementor_widget( $widgets_manager ) {
        if ( ! class_exists( '\Elementor\Widget_Base' ) ) return;
        if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) return;

        // The widget class is loaded from its own file only here — i.e. only once
        // Elementor has confirmed it is running — so a site without Elementor never
        // parses a class that extends a missing base class.
        require_once plugin_dir_path( __FILE__ ) . 'includes/elementor-widget.php';
        if ( ! class_exists( 'WCR_Elementor_Receipt_Panel' ) ) return;

        $widgets_manager->register( new WCR_Elementor_Receipt_Panel() );
    }
}

// =============================================================================
// BOOTSTRAP
//
// Lifecycle hooks must be registered at file scope: WordPress includes this file
// during the activation/deactivation request, where `plugins_loaded` has already
// fired. Registering them behind `plugins_loaded` is why the shipped version never
// created its schema on activation.
//
// The RUNTIME hooks, however, must wait until `plugins_loaded` (priority 20, i.e.
// after WooCommerce's own bootstrap at priority 10). WooCommerce sorts *after*
// this plugin in `active_plugins`, so booting the runtime at file scope would run
// `woocommerce_is_available()` before WooCommerce's file was even included and
// silently leave the plugin inert on the front end.
// =============================================================================

register_activation_hook( __FILE__, array( 'WC_Bank_Receipt_Pro', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'WC_Bank_Receipt_Pro', 'on_deactivate' ) );

function wc_bank_receipt_pro() {
    return WC_Bank_Receipt_Pro::get_instance();
}

/*
 * The shortcode is registered here rather than in the constructor.
 *
 * With WooCommerce inactive the runtime deliberately never boots, so a constructor
 * registration would leave `[wc_bank_receipt]` visible as raw text on the page.
 * Registered at file scope it renders nothing until WooCommerce is back, and the
 * panel itself stays unavailable (no card, no upload, no data).
 */
add_shortcode(
    WC_Bank_Receipt_Pro::SHORTCODE,
    function ( $atts = array() ) {
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
            return '';
        }
        return WC_Bank_Receipt_Pro::get_instance()->shortcode_receipt_panel( $atts );
    }
);

add_action( 'plugins_loaded', 'wc_bank_receipt_pro', 20 );

// Requests that include this file after `plugins_loaded` (activation, deactivation,
// WP-CLI, manual includes) still need a booted instance.
if ( did_action( 'plugins_loaded' ) ) {
    wc_bank_receipt_pro();
}

// WooCommerce HPOS (custom order tables) compatibility. WooCommerce fires
// `before_woocommerce_init` while it is being included, so we both listen for it
// and declare immediately when it has already fired (load-order independent).
$wcr_declare_hpos_compat = function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
};
add_action( 'before_woocommerce_init', $wcr_declare_hpos_compat );
if ( did_action( 'before_woocommerce_init' ) ) {
    $wcr_declare_hpos_compat();
}