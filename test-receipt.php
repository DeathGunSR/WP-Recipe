<?php
/**
 * Plugin Name:  WC Bank Receipt Ultimate Control
 * Description:  مدیریت  فیش بانکی ، پنل پیامکی چندگانه، چرخش کارت و گالری فیش‌ها
 * Version:      7.1.0
 * Author:       Alireza
 * Text Domain:  wc-bank-receipt
 * Domain Path:  /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Bank_Receipt_Pro {

    const META_URL           = '_wc_receipt_url';
    const META_STATE         = '_wc_receipt_state';
    const META_REJECTED_TIME = '_wc_receipt_rejected_time';
    const META_REJECT_REASON = '_wc_receipt_reject_reason';
    const META_ASSIGNED_CARD = '_wc_receipt_assigned_card_id';
    const META_REMINDER_SENT = '_wc_receipt_reminder_sent';
    const GATEWAY_ID         = 'bacs';
    const UPLOAD_DIR         = 'bank-receipts';
    const DB_PREFIX          = 'wc_bank_receipt_';
    const SMS_PROVIDERS      = array(
        'kavenegar' => 'کاوه‌نگار',
        'melli'     => 'ملی پیامک',
        'custom'    => 'سفارشی (درگاه دلخواه)',
    );

    private static $instance = null;

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
        add_action( 'plugins_loaded',         array( $this, 'load_textdomain'        ) );
        register_activation_hook(   __FILE__,  array( $this, 'activate'              ) );
        register_deactivation_hook( __FILE__,  array( $this, 'deactivate'            ) );

        add_action( 'admin_menu',             array( $this, 'admin_menu'             ) );
        add_action( 'admin_init',             array( $this, 'register_settings'      ) );
        add_action( 'admin_init',             array( $this, 'maybe_upgrade_db'       ) );
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

        add_filter( 'woocommerce_can_order_be_changed_to',
                    array( $this, 'allow_pending_from_on_hold' ), 10, 3 );

        if ( ! wp_next_scheduled( 'wc_receipt_cleanup_hook' ) )
            wp_schedule_event( time(), 'hourly', 'wc_receipt_cleanup_hook' );
        if ( ! wp_next_scheduled( 'wc_receipt_reminder_hook' ) )
            wp_schedule_event( time(), 'hourly', 'wc_receipt_reminder_hook' );

        add_action( 'wc_receipt_cleanup_hook',  array( $this, 'cleanup_old_files'      ) );
        add_action( 'wc_receipt_reminder_hook', array( $this, 'send_payment_reminders' ) );

        add_action( 'wp', array( $this, 'maybe_handle_frontend' ) );

        // FIX #1 applied: all three upload filters are now context-gated.
        add_filter( 'upload_dir',                 array( $this, 'custom_upload_dir'    ) );
        add_filter( 'upload_mimes',               array( $this, 'restrict_upload_mimes') );
        add_filter( 'wp_handle_upload_prefilter', array( $this, 'validate_upload'      ) );

        add_action( 'woocommerce_order_status_changed',
                    array( $this, 'send_sms_on_status_change' ), 10, 4 );

        add_action( 'init',              array( $this, 'add_rewrite_endpoint' ) );
        add_action( 'template_redirect', array( $this, 'secure_receipt_view'  ) );

        // =========================================================================
        // ADMIN NEW ORDER ALERT (POPUP & SOUND)
        // =========================================================================
        add_action( 'woocommerce_new_order', array( $this, 'trigger_admin_new_order_alert' ), 10, 2 );
        add_action( 'wp_ajax_wcr_check_new_orders', array( $this, 'ajax_check_new_orders' ) );
        add_action( 'wp_ajax_wcr_dismiss_new_orders', array( $this, 'ajax_dismiss_new_orders' ) );
        add_action( 'admin_footer', array( $this, 'render_admin_alert_popup' ) );
    }

    // =========================================================================
    // ASSET ENQUEUE
    // =========================================================================

    public function admin_enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wc-receipt' ) === false &&
             strpos( $hook, 'wc-sms'     ) === false ) return;

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_register_style( 'wcr-admin', false, array(), '7.1.0' );
        wp_enqueue_style(  'wcr-admin' );
        wp_add_inline_style( 'wcr-admin', $this->get_admin_css() );
    }

    public function frontend_enqueue_assets() {
        if ( ! is_wc_endpoint_url( 'order-received' ) &&
             ! is_wc_endpoint_url( 'view-order'     ) ) return;

        wp_register_style( 'wcr-frontend', false, array(), '7.1.0' );
        wp_enqueue_style(  'wcr-frontend' );
        wp_add_inline_style( 'wcr-frontend', $this->get_frontend_css() );
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

    private function get_frontend_css() {
        return '
#wc-receipt-container{
  --wcrf-pri:#1565c0;--wcrf-pri-h:#1e88e5;
  --wcrf-ok:#2e7d32;--wcrf-err:#c62828;--wcrf-warn:#e65100;
  --wcrf-border:#e0e0e0;
  font-family:Tahoma,sans-serif;direction:rtl;text-align:right;
  max-width:580px;margin:28px 0;
}

/* Bank card */
.wcrf-bank-card{
  background:linear-gradient(135deg,#1565c0 0%,#1e88e5 55%,#42a5f5 100%);
  color:#fff;border-radius:18px;padding:24px 26px;margin-bottom:20px;
  box-shadow:0 8px 32px rgba(21,101,192,.3);position:relative;overflow:hidden;
}
.wcrf-bank-card::before{
  content:"";position:absolute;top:-60%;right:-40%;
  width:200%;height:220%;
  background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.12) 0%,transparent 55%);
  pointer-events:none;
}
.wcrf-bank-card .bank-header{
  display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;
}
.wcrf-bank-card .bank-name{font-size:13px;opacity:.88;font-weight:600;}
.wcrf-bank-card .card-logo{font-size:28px;}
.wcrf-bank-card .card-number{
  font-size:20px;letter-spacing:5px;font-family:monospace;
  margin-bottom:18px;direction:ltr;text-align:center;
  background:rgba(0,0,0,.15);border-radius:8px;padding:8px;
}
.wcrf-bank-card .card-footer{display:flex;justify-content:space-between;align-items:flex-end;}
.wcrf-bank-card .holder{font-size:14px;font-weight:600;}
.wcrf-bank-card .sheba{font-size:11px;opacity:.8;direction:ltr;text-align:right;margin-top:3px;}
.wcrf-copy-btn{
  background:rgba(255,255,255,.22);border:1px solid rgba(255,255,255,.45);
  color:#fff;padding:5px 12px;border-radius:6px;cursor:pointer;
  font-size:12px;transition:all .2s;font-family:inherit;
}
.wcrf-copy-btn:hover{background:rgba(255,255,255,.35);}
.wcrf-copy-btn.copied{background:rgba(255,255,255,.45);}

/* Timer */
.wcrf-timer-box{
  background:#fff;border:1.5px solid var(--wcrf-border);
  border-radius:12px;padding:14px 18px;margin-bottom:18px;
  display:flex;align-items:center;gap:12px;
}
.wcrf-timer-box.urgent{border-color:#e53935;background:#fff8f8;}
.wcrf-timer-lbl{font-size:13px;color:#666;white-space:nowrap;}
.wcrf-timer-val{font-size:19px;font-weight:700;color:var(--wcrf-pri-h);font-family:monospace;direction:ltr;}
.wcrf-timer-val.urgent{color:#e53935;}
.wcrf-progress{flex:1;height:6px;background:#e0e0e0;border-radius:3px;overflow:hidden;}
.wcrf-progress-fill{height:100%;background:linear-gradient(90deg,var(--wcrf-pri),var(--wcrf-pri-h));border-radius:3px;transition:width 1s linear;}

/* Status boxes */
.wcrf-status-box{
  background:#fff;border-radius:12px;padding:18px 20px;
  margin-bottom:18px;border-right:4px solid var(--wcrf-border);
}
.wcrf-status-box.approved{border-right-color:#43a047;background:#f1f8e9;}
.wcrf-status-box.rejected{border-right-color:#e53935;background:#fff8f8;}
.wcrf-status-box.pending {border-right-color:#fb8c00;background:#fff8e1;}
.wcrf-status-title{font-size:15px;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:7px;}
.wcrf-status-box.approved .wcrf-status-title{color:#2e7d32;}
.wcrf-status-box.rejected .wcrf-status-title{color:#c62828;}
.wcrf-status-box.pending  .wcrf-status-title{color:#e65100;}
.wcrf-reject-reason{
  background:rgba(198,40,40,.08);border-radius:6px;
  padding:8px 12px;font-size:13px;color:#c62828;margin-top:8px;
}
.wcrf-prev-link{
  font-size:13px;color:var(--wcrf-pri-h);text-decoration:none;
  display:inline-flex;align-items:center;gap:4px;margin-top:8px;
}
.wcrf-prev-link:hover{text-decoration:underline;}

/* Upload zone */
.wcrf-drop-zone{
  border:2.5px dashed #b0bec5;border-radius:12px;
  padding:30px 20px;text-align:center;cursor:pointer;
  transition:all .3s;background:#fafafa;
}
.wcrf-drop-zone:hover,.wcrf-drop-zone.drag-over{border-color:var(--wcrf-pri-h);background:#e3f2fd;}
.wcrf-drop-icon{font-size:44px;color:#b0bec5;transition:all .3s;margin-bottom:10px;}
.wcrf-drop-zone:hover .wcrf-drop-icon,.wcrf-drop-zone.drag-over .wcrf-drop-icon{color:var(--wcrf-pri-h);transform:translateY(-4px);}
.wcrf-drop-text{font-size:15px;color:#546e7a;margin-bottom:6px;}
.wcrf-drop-hint{font-size:12px;color:#90a4ae;}
.wcrf-preview-wrap{margin-top:14px;display:none;align-items:center;justify-content:center;gap:12px;}
.wcrf-preview-wrap img{max-width:190px;max-height:150px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.15);}
.wcrf-file-name{font-size:12px;color:#546e7a;background:#eceff1;padding:3px 10px;border-radius:4px;}

/* Submit button */
.wcrf-submit-btn{
  display:block;width:100%;padding:14px;
  background:linear-gradient(135deg,var(--wcrf-pri),var(--wcrf-pri-h));
  color:#fff;border:none;border-radius:10px;font-size:15px;
  font-weight:600;cursor:pointer;margin-top:14px;transition:all .3s;
  font-family:inherit;
}
.wcrf-submit-btn:hover{background:linear-gradient(135deg,#0d47a1,var(--wcrf-pri));transform:translateY(-1px);box-shadow:0 4px 16px rgba(21,101,192,.4);}

/* No card warning */
.wcrf-no-card{
  background:#fff8e1;border-right:4px solid #fb8c00;
  border-radius:10px;padding:16px 18px;color:#e65100;font-size:14px;
}

/* Expired */
.wcrf-expired{
  background:#ffebee;border-right:4px solid #e53935;
  border-radius:10px;padding:16px 18px;color:#c62828;font-size:15px;font-weight:600;
}
        ';
    }

    // =========================================================================
    // TEXT DOMAIN
    // =========================================================================

    public function load_textdomain() {
        load_plugin_textdomain( 'wc-bank-receipt', false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    // =========================================================================
    // ACTIVATION / DEACTIVATION
    // =========================================================================

    public function activate() {
        $this->create_tables();
        $this->create_protected_directory();
    }

    public function deactivate() {
        $this->remove_tables();
        $this->delete_options();
        $this->remove_protected_directory();
        wp_clear_scheduled_hook( 'wc_receipt_cleanup_hook'  );
        wp_clear_scheduled_hook( 'wc_receipt_reminder_hook' );
    }

    public function maybe_upgrade_db() {
        if ( version_compare( get_option( 'wc_bank_receipt_db_version', '1.0' ), '7.1.0', '<' ) ) {
            $this->create_tables();
            update_option( 'wc_bank_receipt_db_version', '7.1.0' );
        }
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
    }

    private function remove_tables() {
        global $wpdb;
        $p = $wpdb->prefix . self::DB_PREFIX;
        $wpdb->query( "DROP TABLE IF EXISTS {$p}cards"       );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}receipt_log" );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}sms_log"     );
    }

    private function delete_options() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE 'wc_receipt_%'
               OR option_name LIKE 'wc_sms_%'
               OR option_name LIKE 'wc_bank_card%'
               OR option_name  =  'wc_bank_receipt_db_version'
               OR option_name  =  'wcr_pending_admin_alerts'" );
    }

    private function create_protected_directory() {
        $base = wp_upload_dir();
        $dir  = $base['basedir'] . '/' . self::UPLOAD_DIR;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
            file_put_contents( $dir . '/index.php',  '<?php // Silence is golden.' );
        }
    }

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

        add_submenu_page( 'wc-receipt-settings',
            __( 'پیام‌رسانی', 'wc-bank-receipt' ), __( 'پیام‌رسانی', 'wc-bank-receipt' ),
            'manage_options', 'wc-sms-settings', array( $this, 'sms_settings_page' ) );

        add_submenu_page( 'wc-receipt-settings',
            __( 'لاگ پیامک', 'wc-bank-receipt' ), __( 'لاگ پیامک', 'wc-bank-receipt' ),
            'manage_options', 'wc-sms-log', array( $this, 'sms_log_page' ) );
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

        foreach ( array( 'provider','kavenegar_apikey','kavenegar_sender',
                         'melli_username','melli_password','melli_sender',
                         'custom_url','custom_apikey','custom_sender',
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
        return array_map( 'sanitize_mime_type', $input );
    }

    // =========================================================================
    // SETTINGS PAGE
    // =========================================================================

    public function settings_page() {
        if ( isset( $_POST['reset_plugin'] ) && check_admin_referer( 'reset_plugin_nonce' ) ) {
            $this->deactivate();
            $this->activate();
            echo '<div class="notice notice-success is-dismissible"><p>'
                . __( 'افزونه با موفقیت بازنشانی شد.', 'wc-bank-receipt' ) . '</p></div>';
        }
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
                    </table>
                    <?php submit_button( __( 'ذخیره تنظیمات', 'wc-bank-receipt' ) ); ?>
                </form>
            </div>

            <div class="wcr-card" style="border:1.5px solid #ffcdd2;">
                <h2 style="border-color:#ffcdd2;"><span class="dashicons dashicons-warning" style="color:#e53935;"></span> <?php _e( 'بازنشانی افزونه', 'wc-bank-receipt' ); ?></h2>
                <p style="color:#757575;font-size:14px;"><?php _e( 'با این عملیات تمام داده‌های افزونه (کارت‌ها، لاگ‌ها، تنظیمات) حذف و دوباره ساخته می‌شوند.', 'wc-bank-receipt' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'reset_plugin_nonce' ); ?>
                    <button type="submit" name="reset_plugin" class="wcr-reset-btn"
                        onclick="return confirm('<?php esc_attr_e( 'تمام داده‌ها حذف خواهند شد. مطمئن هستید؟', 'wc-bank-receipt' ); ?>');">
                        ⚠️ <?php _e( 'بازنشانی کامل', 'wc-bank-receipt' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <script>
        document.getElementById('cleanup_period').addEventListener('change',function(){
            document.getElementById('custom_days_row').style.display = this.value==='custom' ? '' : 'none';
        });
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

    private function get_active_card() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        $cards = $wpdb->get_results( "SELECT * FROM $table WHERE is_active=1 ORDER BY priority ASC" );
        $today = current_time( 'Y-m-d' );
        foreach ( $cards as $card ) {
            if ( $card->enable_limit_amount    && $card->limit_amount - $card->used_amount <= 0 ) continue;
            if ( $card->enable_max_transactions && $card->max_transactions - $card->used_transactions <= 0 ) continue;
            if ( $card->enable_expiry_date     && $card->expiry_date && $card->expiry_date < $today ) continue;
            return $card;
        }
        return ! empty( $cards ) ? $cards[0] : false;
    }

    private function get_card_by_id( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
    }

    private function update_card_usage( $card_id, $amount ) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_PREFIX . 'cards';
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET used_amount=used_amount+%d, used_transactions=used_transactions+1 WHERE id=%d",
            absint( $amount ), $card_id
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
        if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) {
            add_action( 'woocommerce_thankyou_' . self::GATEWAY_ID, array( $this, 'display_receipt_form' ) );
            add_action( 'woocommerce_view_order', array( $this, 'display_receipt_form' ) );
            if ( isset( $_POST['submit_receipt'] ) ) {
                $this->handle_upload();
            }
        }
    }

    public function display_receipt_form( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== self::GATEWAY_ID ) return;

        // Access check
        $ok = false;
        if ( is_user_logged_in() && get_current_user_id() == $order->get_customer_id() ) $ok = true;
        elseif ( isset( $_GET['key'] ) && $_GET['key'] === $order->get_order_key() ) $ok = true;
        if ( ! $ok ) return;

        $url           = $order->get_meta( self::META_URL );
        $state         = $order->get_meta( self::META_STATE );
        $reject_reason = $order->get_meta( self::META_REJECT_REASON );
        $expire_hours  = (float) get_option( 'wc_receipt_expire_hours', 2 );
        $gmt_offset    = (float) get_option( 'gmt_offset' );

        $start_time = ( $state === 'rejected' && $order->get_meta( self::META_REJECTED_TIME ) )
            ? $order->get_meta( self::META_REJECTED_TIME )
            : ( $order->get_date_created()->getTimestamp() + $gmt_offset * HOUR_IN_SECONDS );

        $total_seconds    = $expire_hours * HOUR_IN_SECONDS;
        $remaining_time   = ( $start_time + $total_seconds ) - current_time( 'timestamp' );

        // Auto-fail on deadline
        if ( $order->get_status() === 'pending' && $state !== 'approved' && $remaining_time <= 0 ) {
            $order->update_status( 'failed', __( 'پایان مهلت ارسال فیش.', 'wc-bank-receipt' ) );
            $remaining_time = 0;
        }

        // FIX #2: Use the card already assigned to this order (if any), else get active card
        $assigned_card_id = $order->get_meta( self::META_ASSIGNED_CARD );
        $card = $assigned_card_id ? $this->get_card_by_id( $assigned_card_id ) : $this->get_active_card();

        echo '<div id="wc-receipt-container">';

        if ( in_array( $order->get_status(), array( 'failed', 'cancelled' ) ) ) {
            echo '<div class="wcrf-expired">⚠️ ' . __( 'مهلت ارسال فیش به پایان رسیده یا سفارش لغو شده است.', 'wc-bank-receipt' ) . '</div>';
            echo '</div>';
            return;
        }

        if ( $state === 'approved' ) {
            echo '<div class="wcrf-status-box approved">';
            echo '<div class="wcrf-status-title">✅ ' . __( 'واریز تأیید شد', 'wc-bank-receipt' ) . '</div>';
            echo '<div style="font-size:14px;color:#555;">' . __( 'پرداخت شما تأیید و سفارشتان در حال پردازش است.', 'wc-bank-receipt' ) . '</div>';
            echo '</div></div>';
            return;
        }

        // Bank card display
        if ( $card ) {
            echo '<div class="wcrf-bank-card">';
            echo '<div class="bank-header"><div class="bank-name">🏦 ' . esc_html( $card->bank_name ) . '</div><div class="card-logo">💳</div></div>';
            echo '<div class="card-number">' . esc_html( $card->card_number ) . '</div>';
            echo '<div class="card-footer">';
            echo '<div><div class="holder">' . esc_html( $card->account_holder ) . '</div>';
            if ( ! empty( $card->sheba ) ) echo '<div class="sheba">IR: ' . esc_html( $card->sheba ) . '</div>';
            echo '</div>';
            echo '<button type="button" class="wcrf-copy-btn" data-copy="' . esc_attr( $card->card_number ) . '" onclick="wcrCopy(this)">' . __( 'کپی شماره', 'wc-bank-receipt' ) . '</button>';
            echo '</div></div>';
        } else {
            echo '<div class="wcrf-no-card">⚠️ ' . __( 'در حال حاضر حساب بانکی فعالی برای واریز وجود ندارد. با مدیریت تماس بگیرید.', 'wc-bank-receipt' ) . '</div>';
        }

        // Timer
        if ( $remaining_time > 0 ) {
            $progress_pct = min( 100, max( 0, round( ( $remaining_time / $total_seconds ) * 100 ) ) );
            ?>
            <div class="wcrf-timer-box" id="wcrf-timer-box">
                <div class="wcrf-timer-lbl"><?php _e( 'فرصت باقی‌مانده:', 'wc-bank-receipt' ); ?></div>
                <strong class="wcrf-timer-val" id="wcrf-timer" data-time="<?php echo absint( $remaining_time ); ?>">--:--:--</strong>
                <div class="wcrf-progress"><div class="wcrf-progress-fill" id="wcrf-progress" style="width:<?php echo $progress_pct; ?>%"></div></div>
            </div>
            <script>
            (function(){
                var t = document.getElementById('wcrf-timer');
                var p = document.getElementById('wcrf-progress');
                var box = document.getElementById('wcrf-timer-box');
                var total = <?php echo absint( $total_seconds ); ?>;
                var sec   = parseInt(t.getAttribute('data-time'));
                function update(){
                    if(sec<=0){ t.innerText='<?php _e("پایان مهلت!","wc-bank-receipt"); ?>'; location.reload(); return; }
                    var h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60), s=sec%60;
                    t.innerText = (h?String(h).padStart(2,'0')+':':'') + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
                    p.style.width = Math.round((sec/total)*100) + '%';
                    if(sec<300){ t.classList.add('urgent'); box.classList.add('urgent'); }
                    sec--;
                }
                update(); setInterval(update,1000);
            })();
            </script>
            <?php
        }

        // Receipt status
        if ( $url ) {
            $view_url = home_url( '?wc_receipt_view=' . $order->get_id() . '&nonce=' . wp_create_nonce( 'view_receipt_' . $order->get_id() ) );
            if ( $state === 'rejected' ) {
                echo '<div class="wcrf-status-box rejected">';
                echo '<div class="wcrf-status-title">❌ ' . __( 'فیش رد شد', 'wc-bank-receipt' ) . '</div>';
                if ( $reject_reason ) echo '<div class="wcrf-reject-reason">' . esc_html( $reject_reason ) . '</div>';
                echo '<a href="' . esc_url( $view_url ) . '" class="wcrf-prev-link" target="_blank">👁 ' . __( 'مشاهده فیش قبلی', 'wc-bank-receipt' ) . '</a>';
                echo '</div>';
            } else {
                echo '<div class="wcrf-status-box pending">';
                echo '<div class="wcrf-status-title">⏳ ' . __( 'در انتظار بررسی مدیریت', 'wc-bank-receipt' ) . '</div>';
                echo '<a href="' . esc_url( $view_url ) . '" class="wcrf-prev-link" target="_blank">👁 ' . __( 'مشاهده فیش ارسالی', 'wc-bank-receipt' ) . '</a>';
                echo '</div>';
            }
        }

        // Upload form
        if ( ( ! $url || $state === 'rejected' ) && $remaining_time > 0 ) {
            $allowed_mimes = get_option( 'wc_receipt_allowed_mimes', array( 'image/jpeg','image/png','application/pdf' ) );
            $accept_str    = implode( ',', array_map( function($m){ return '.'.(str_contains($m,'pdf')?'pdf':(str_contains($m,'png')?'png':'jpg')); }, $allowed_mimes ) );
            ?>
            <form method="post" enctype="multipart/form-data" id="receipt-upload-form" style="margin-top:4px;">
                <div class="wcrf-drop-zone" id="wcrf-drop-zone">
                    <div class="wcrf-drop-icon">📂</div>
                    <div class="wcrf-drop-text"><?php _e( 'فایل را اینجا رها کنید یا برای انتخاب کلیک کنید', 'wc-bank-receipt' ); ?></div>
                    <div class="wcrf-drop-hint"><?php printf( __( 'فرمت‌های مجاز: %s — حداکثر %s کیلوبایت', 'wc-bank-receipt' ), strtoupper( implode(', ', array_map( fn($m)=>explode('/',$m)[1], $allowed_mimes ) ) ), get_option('wc_receipt_max_file_size',2048) ); ?></div>
                    <input type="file" name="receipt_file" id="wcrf-file-input" accept="<?php echo esc_attr($accept_str); ?>" required style="display:none">
                    <div class="wcrf-preview-wrap" id="wcrf-preview-wrap">
                        <img id="wcrf-preview-img" src="" alt="">
                        <span class="wcrf-file-name" id="wcrf-file-name"></span>
                    </div>
                </div>
                <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                <?php wp_nonce_field( 'upload_receipt_nonce', 'receipt_nonce' ); ?>
                <button type="submit" name="submit_receipt" class="wcrf-submit-btn">📤 <?php _e( 'ارسال فیش واریزی', 'wc-bank-receipt' ); ?></button>
            </form>
            <script>
            function wcrCopy(btn){
                navigator.clipboard.writeText(btn.dataset.copy).then(function(){
                    btn.textContent='✓ کپی شد'; btn.classList.add('copied');
                    setTimeout(function(){ btn.textContent='کپی شماره'; btn.classList.remove('copied'); },2000);
                });
            }
            (function(){
                var dz=document.getElementById('wcrf-drop-zone');
                var fi=document.getElementById('wcrf-file-input');
                var pw=document.getElementById('wcrf-preview-wrap');
                var pi=document.getElementById('wcrf-preview-img');
                var fn=document.getElementById('wcrf-file-name');
                dz.addEventListener('click',function(e){ if(e.target!==fi) fi.click(); });
                dz.addEventListener('dragover',function(e){ e.preventDefault(); dz.classList.add('drag-over'); });
                dz.addEventListener('dragleave',function(){ dz.classList.remove('drag-over'); });
                dz.addEventListener('drop',function(e){
                    e.preventDefault(); dz.classList.remove('drag-over');
                    fi.files=e.dataTransfer.files; showPreview(fi.files[0]);
                });
                fi.addEventListener('change',function(){ showPreview(this.files[0]); });
                function showPreview(file){
                    if(!file) return;
                    fn.textContent=file.name; pw.style.display='flex';
                    if(file.type.match('image.*')){
                        var r=new FileReader();
                        r.onload=function(e){ pi.src=e.target.result; pi.style.display='block'; };
                        r.readAsDataURL(file);
                    } else { pi.style.display='none'; }
                }
            })();
            </script>
            <?php
        }

        echo '</div>'; // #wc-receipt-container
    }

    // =========================================================================
    // UPLOAD FILTERS (FIX #1: all gated by $this->receipt_upload_context)
    // =========================================================================

    public function custom_upload_dir( $dir ) {
        if ( ! $this->receipt_upload_context ) return $dir; // ← FIX #1
        $dir['path']   = $dir['basedir'] . '/' . self::UPLOAD_DIR;
        $dir['url']    = $dir['baseurl'] . '/' . self::UPLOAD_DIR;
        $dir['subdir'] = '';
        return $dir;
    }

    public function restrict_upload_mimes( $mimes ) {
        if ( ! $this->receipt_upload_context ) return $mimes; // ← FIX #1
        $allowed = get_option( 'wc_receipt_allowed_mimes', array( 'image/jpeg','image/png','application/pdf' ) );
        $new = array();
        foreach ( $allowed as $mime ) {
            $ext = explode( '/', $mime )[1];
            $new[ $ext ] = $mime;
        }
        return $new;
    }

    public function validate_upload( $file ) {
        if ( ! $this->receipt_upload_context ) return $file; // ← FIX #1
        $max = absint( get_option( 'wc_receipt_max_file_size', 2048 ) ) * 1024;
        if ( $file['size'] > $max ) {
            $file['error'] = sprintf( __( 'حجم فایل بیش از حد مجاز (%s KB) است.', 'wc-bank-receipt' ), $max / 1024 );
            return $file;
        }
        if ( ! function_exists( 'finfo_open' ) ) return $file;
        $fi   = finfo_open( FILEINFO_MIME_TYPE );
        $mime = finfo_file( $fi, $file['tmp_name'] );
        finfo_close( $fi );
        $allowed = get_option( 'wc_receipt_allowed_mimes', array( 'image/jpeg','image/png','application/pdf' ) );
        if ( ! in_array( $mime, $allowed, true ) ) {
            $file['error'] = __( 'فرمت فایل مجاز نیست.', 'wc-bank-receipt' );
        }
        return $file;
    }

    // =========================================================================
    // HANDLE UPLOAD (FIX #1 context flag | FIX #2 save card + meta before status)
    // =========================================================================

    public function handle_upload() {
        if ( empty( $_POST['receipt_nonce'] ) || ! wp_verify_nonce( $_POST['receipt_nonce'], 'upload_receipt_nonce' ) ) return;

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( get_current_user_id() !== $order->get_customer_id() ) wp_die( __( 'دسترسی غیرمجاز', 'wc-bank-receipt' ) );

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = $_FILES['receipt_file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wc_add_notice( __( 'خطا در آپلود فایل. لطفاً دوباره تلاش کنید.', 'wc-bank-receipt' ), 'error' );
            return;
        }

        $ext          = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $allowed_exts = array( 'jpg','jpeg','png','pdf' );
        if ( ! in_array( $ext, $allowed_exts, true ) ) {
            wc_add_notice( __( 'فرمت فایل مجاز نیست.', 'wc-bank-receipt' ), 'error' );
            return;
        }
        $file['name'] = md5( uniqid( '', true ) ) . '.' . $ext;

        // FIX #1: Enable context so our filters apply only for THIS upload
        $this->receipt_upload_context = true;
        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
        $this->receipt_upload_context = false;

        if ( ! empty( $upload['error'] ) ) {
            wc_add_notice( $upload['error'], 'error' );
            return;
        }

        // FIX #2a: Get active card and save its ID for tracking
        $card = $this->get_active_card();

        $order->update_meta_data( self::META_URL,           $upload['url']  );
        $order->update_meta_data( self::META_STATE,         'pending_review' );
        $order->update_meta_data( self::META_REMINDER_SENT, 0               );
        if ( $card ) {
            $order->update_meta_data( self::META_ASSIGNED_CARD, $card->id );
        }

        // FIX #2b: Save meta BEFORE calling update_status() so SMS hook gets correct state
        $order->save();
        $order->update_status( 'on-hold', __( 'فیش بانکی آپلود شد.', 'wc-bank-receipt' ) );

        $this->log_receipt_action( $order_id, 'uploaded' );
        $this->send_admin_upload_sms( $order_id );

        wp_safe_redirect( $_SERVER['REQUEST_URI'] );
        exit;
    }

    // =========================================================================
    // SECURE RECEIPT VIEW
    // =========================================================================

    public function add_rewrite_endpoint() {
        add_rewrite_endpoint( 'wc_receipt_view', EP_ROOT );
    }

    public function secure_receipt_view() {
        $view_id = get_query_var( 'wc_receipt_view' );
        if ( ! $view_id ) {
            // Fallback: check query string for ?wc_receipt_view=
            if ( isset( $_GET['wc_receipt_view'] ) ) $view_id = absint( $_GET['wc_receipt_view'] );
        }
        if ( ! $view_id ) return;

        $order_id = absint( $view_id );
        $nonce    = sanitize_text_field( $_GET['nonce'] ?? '' );
        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'view_receipt_' . $order_id ) ) {
            wp_die( __( 'دسترسی غیرمجاز', 'wc-bank-receipt' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( __( 'سفارش یافت نشد.', 'wc-bank-receipt' ) );
        $url = $order->get_meta( self::META_URL );
        if ( ! $url ) wp_die( __( 'فیشی یافت نشد.', 'wc-bank-receipt' ) );
        wp_redirect( $url );
        exit;
    }

    // =========================================================================
    // ADMIN ORDER SECTION
    // =========================================================================

    public function admin_order_section( $order ) {
        $url   = $order->get_meta( self::META_URL );
        if ( ! $url ) return;
        $state  = $order->get_meta( self::META_STATE );
        $reason = $order->get_meta( self::META_REJECT_REASON );
        $nonce  = wp_create_nonce( 'receipt_admin_nonce' );
        $oid    = $order->get_id();
        $is_img = (bool) preg_match( '/\.(jpe?g|png)$/i', $url );
        ?>
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
            <?php if ( $is_img ) : ?>
                <img src="<?php echo esc_url( $url ); ?>" onclick="event.stopPropagation()">
            <?php else : ?>
                <div style="background:#fff;padding:30px;border-radius:10px;text-align:center;">
                    <div style="font-size:60px;">📄</div>
                    <p><?php _e( 'فایل PDF است.', 'wc-bank-receipt' ); ?></p>
                    <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="wcr-btn wcr-btn-primary"><?php _e( 'دانلود / مشاهده', 'wc-bank-receipt' ); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            // Show reason box when reject button clicked
            $('#reject-btn-<?php echo $oid; ?>').on('click',function(){
                $('#reason-wrap-<?php echo $oid; ?>').show();
            });
            $('.r-btn').on('click',function(){
                var btn=$(this), action=btn.data('action'), oid=btn.data('id');
                btn.prop('disabled',true).text('<?php _e("در حال ثبت...","wc-bank-receipt"); ?>');
                $.post(ajaxurl,{
                    action:'wc_receipt_'+action,
                    order_id:oid,
                    nonce:'<?php echo $nonce; ?>',
                    reason:action==='reject' ? $('#reject-reason-'+oid).val() : ''
                },function(r){
                    if(r.success) location.reload();
                    else{ btn.prop('disabled',false).text(action==='approve'?'✓ تأیید':'✗ رد فیش'); alert('<?php _e("خطا رخ داد.","wc-bank-receipt"); ?>'); }
                }).fail(function(){ btn.prop('disabled',false); alert('<?php _e("ارتباط با سرور قطع شد.","wc-bank-receipt"); ?>'); });
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // AJAX: APPROVE (FIX #2: save meta before update_status)
    // =========================================================================

    public function ajax_approve() {
        check_ajax_referer( 'receipt_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) { wp_send_json_error(); return; }

        // FIX #2: update meta and SAVE before changing status
        // so woocommerce_order_status_changed hook sees the correct state.
        $order->update_meta_data( self::META_STATE, 'approved' );
        $order->save();

        // Update card usage after meta is saved
        $card_id = $order->get_meta( self::META_ASSIGNED_CARD );
        if ( $card_id ) {
            $this->update_card_usage( (int) $card_id, (float) $order->get_total() );
        }

        $order->update_status( 'processing', __( 'فیش بانکی تأیید شد.', 'wc-bank-receipt' ) );
        $this->log_receipt_action( $order_id, 'approved' );

        wp_send_json_success();
    }

    // =========================================================================
    // AJAX: REJECT (FIX #2: save meta before update_status)
    // =========================================================================

    public function ajax_reject() {
        check_ajax_referer( 'receipt_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) { wp_send_json_error(); return; }

        $reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );

        // FIX #2: save meta before changing status
        $order->update_meta_data( self::META_STATE,         'rejected'             );
        $order->update_meta_data( self::META_REJECT_REASON, $reason                );
        $order->update_meta_data( self::META_REJECTED_TIME, current_time('timestamp') );
        $order->update_meta_data( self::META_REMINDER_SENT, 0                      );
        $order->save();

        $order->update_status( 'pending', __( 'فیش بانکی رد شد.', 'wc-bank-receipt' ) );
        $this->log_receipt_action( $order_id, 'rejected', $reason );

        wp_send_json_success();
    }

    // =========================================================================
    // BULK REVIEW PAGE (FIX #2 applied + improved UI)
    // =========================================================================

    public function bulk_review_page() {
        if ( isset( $_POST['bulk_action_submit'] ) && check_admin_referer( 'bulk_review_nonce' ) ) {
            $action    = sanitize_text_field( $_POST['bulk_action'] ?? '' );
            $order_ids = array_map( 'absint', $_POST['order_ids'] ?? array() );
            $done      = 0;

            foreach ( $order_ids as $oid ) {
                $order = wc_get_order( $oid );
                if ( ! $order ) continue;

                if ( $action === 'approve' ) {
                    // FIX #2 in bulk: save meta before status change
                    $order->update_meta_data( self::META_STATE, 'approved' );
                    $order->save();
                    $card_id = $order->get_meta( self::META_ASSIGNED_CARD );
                    if ( $card_id ) $this->update_card_usage( (int) $card_id, (float) $order->get_total() );
                    $order->update_status( 'processing', __( 'فیش تأیید شد (گروهی).', 'wc-bank-receipt' ) );
                    $this->log_receipt_action( $oid, 'approved' );

                } elseif ( $action === 'reject' ) {
                    $reason = sanitize_textarea_field( wp_unslash( $_POST['bulk_reason'] ?? '' ) );
                    // FIX #2 in bulk
                    $order->update_meta_data( self::META_STATE,         'rejected'             );
                    $order->update_meta_data( self::META_REJECT_REASON, $reason                );
                    $order->update_meta_data( self::META_REJECTED_TIME, current_time('timestamp') );
                    $order->save();
                    $order->update_status( 'pending', __( 'فیش رد شد (گروهی).', 'wc-bank-receipt' ) );
                    $this->log_receipt_action( $oid, 'rejected', $reason );
                }
                $done++;
            }

            echo '<div class="notice notice-success is-dismissible"><p>'
                . sprintf( __( 'عملیات روی %d سفارش انجام شد.', 'wc-bank-receipt' ), $done )
                . '</p></div>';
        }

        $orders = wc_get_orders( array(
            'status'     => array( 'on-hold' ),
            'limit'      => -1,
            'meta_key'   => self::META_STATE,
            'meta_value' => 'pending_review',
        ) );
        ?>
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
                <h2><span class="dashicons dashicons-yes-alt"></span> <?php printf( __( '%d فیش در انتظار بررسی', 'wc-bank-receipt' ), count($orders) ); ?></h2>
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
                                <td><input type="checkbox" name="order_ids[]" value="<?php echo $order->get_id(); ?>"></td>
                                <td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank">#<?php echo $order->get_id(); ?></a></td>
                                <td><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></td>
                                <td><?php echo wc_price( $order->get_total() ); ?></td>
                                <td><?php echo $order->get_date_created()->date_i18n( 'Y/m/d H:i' ); ?></td>
                                <td><?php echo $card ? esc_html( $card->bank_name . ' - ' . substr( $card->card_number, -4 ) ) : '—'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( home_url( '?wc_receipt_view=' . $order->get_id() . '&nonce=' . wp_create_nonce( 'view_receipt_' . $order->get_id() ) ) ); ?>"
                                       target="_blank" class="wcr-btn wcr-btn-ghost wcr-btn-sm">👁 <?php _e( 'مشاهده', 'wc-bank-receipt' ); ?></a>
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
                        <button type="submit" name="bulk_action_submit" class="wcr-btn wcr-btn-primary"><?php _e( 'اعمال', 'wc-bank-receipt' ); ?></button>
                    </div>
                </form>
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

        $order_ids = wc_get_orders( array(
            'payment_method' => self::GATEWAY_ID,
            'status'  => array( 'on-hold','processing','completed','failed','cancelled','pending' ),
            'limit'   => -1,
            'return'  => 'ids',
        ) );

        $total = count( $order_ids );
        $approved = $rejected = $pending = $amount_approved = 0;

        foreach ( $order_ids as $oid ) {
            $o     = wc_get_order( $oid );
            $state = $o ? $o->get_meta( self::META_STATE ) : '';
            if ( $state === 'approved'      ) { $approved++; $amount_approved += $o->get_total(); }
            elseif ( $state === 'rejected'  )   $rejected++;
            elseif ( $state === 'pending_review' ) $pending++;
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

                <div style="margin-top:20px;">
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'export_csv', 1 ), 'export_csv_nonce' ) ); ?>"
                       class="wcr-btn wcr-btn-primary">
                        ⬇ <?php _e( 'خروجی CSV', 'wc-bank-receipt' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function export_csv() {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=card-transactions-' . date( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF).chr(0xBB).chr(0xBF) );
        fputcsv( $out, array( 'شماره سفارش','تاریخ','مبلغ (تومان)','وضعیت فیش','کارت استفاده‌شده' ) );
        foreach ( wc_get_orders( array( 'payment_method' => self::GATEWAY_ID, 'limit' => -1 ) ) as $order ) {
            $state = $order->get_meta( self::META_STATE );
            $lbl   = $state === 'approved' ? 'تأیید' : ( $state === 'rejected' ? 'رد' : 'نامشخص' );
            $cinfo = '';
            $cid   = $order->get_meta( self::META_ASSIGNED_CARD );
            if ( $cid ) {
                $c = $this->get_card_by_id( $cid );
                if ( $c ) $cinfo = $c->bank_name . ' - ' . substr( $c->card_number, -4 );
            }
            fputcsv( $out, array( $order->get_id(), $order->get_date_created()->date_i18n('Y/m/d H:i'), $order->get_total(), $lbl, $cinfo ) );
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
        if ( isset( $_GET['delete_receipt'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'delete_receipt' ) ) {
            $this->delete_receipt_file( absint( $_GET['delete_receipt'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . __( 'فیش حذف شد.', 'wc-bank-receipt' ) . '</p></div>';
        }

        $orders = wc_get_orders( array(
            'status'   => array( 'pending','on-hold','processing','completed','failed','cancelled' ),
            'limit'    => -1,
            'meta_key' => self::META_URL,
        ) );

        $period      = get_option( 'wc_receipt_cleanup_period', '1' );
        $days        = $period === 'custom' ? absint( get_option( 'wc_receipt_custom_days', 1 ) ) : absint( $period );
        $delete_secs = $days * DAY_IN_SECONDS;
        $gmt_offset  = (float) get_option( 'gmt_offset' );
        ?>
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
                    $path      = str_replace( home_url('/'), ABSPATH, $url );
                    $exists    = file_exists( $path );
                    $created   = $order->get_date_created()->getTimestamp() + $gmt_offset * HOUR_IN_SECONDS;
                    $time_left = max( 0, ( $created + $delete_secs ) - current_time('timestamp') );
                    $rem_d     = floor( $time_left / DAY_IN_SECONDS );
                    $rem_h     = floor( ( $time_left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
                    $is_img    = (bool) preg_match( '/\.(jpe?g|png)$/i', $url );
                    $view_url  = home_url( '?wc_receipt_view='.$oid.'&nonce='.wp_create_nonce('view_receipt_'.$oid) );
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
                                    <span style="color:#e53935;"><?php _e('فایل حذف شده','wc-bank-receipt'); ?></span>
                                <?php elseif ( $time_left <= 0 ) : ?>
                                    <span style="color:#e65100;"><?php _e('آماده حذف','wc-bank-receipt'); ?></span>
                                <?php else : ?>
                                    ⏳ <?php printf('%dروز %dساعت', $rem_d, $rem_h); ?>
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
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function delete_receipt_file( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $url = $order->get_meta( self::META_URL );
        if ( $url ) {
            $path = str_replace( home_url('/'), ABSPATH, $url );
            if ( file_exists( $path ) ) unlink( $path );
            $order->delete_meta_data( self::META_URL           );
            $order->delete_meta_data( self::META_STATE         );
            $order->delete_meta_data( self::META_ASSIGNED_CARD );
            $order->save();
        }
    }

    public function cleanup_old_files() {
        $period = get_option( 'wc_receipt_cleanup_period', '1' );
        if ( $period === 'custom' ) $period = get_option( 'wc_receipt_custom_days', 1 );
        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );
        $orders = wc_get_orders( array(
            'status'       => array( 'pending','on-hold','processing','completed','failed','cancelled' ),
            'date_created' => '<' . $threshold,
            'limit'        => 50,
            'return'       => 'ids',
            'meta_key'     => self::META_URL,
        ) );
        foreach ( $orders as $oid ) $this->delete_receipt_file( $oid );
    }

    // =========================================================================
    // SMS
    // =========================================================================

    public function send_sms_on_status_change( $order_id, $old_status, $new_status, $order ) {
        $state = $order->get_meta( self::META_STATE );
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) return;

        $tpl = '';
        if ( $new_status === 'processing' && $state === 'approved' ) {
            $tpl = get_option( 'wc_sms_approved_tpl', __( 'مشتری گرامی، فیش سفارش {order_id} تأیید شد.', 'wc-bank-receipt' ) );
        } elseif ( $new_status === 'pending' && $state === 'rejected' ) {
            $tpl = get_option( 'wc_sms_rejected_tpl', __( 'مشتری گرامی، فیش سفارش {order_id} رد شد. لطفاً مجدد ارسال کنید.', 'wc-bank-receipt' ) );
        } elseif ( $new_status === 'failed' && $state !== 'approved' ) {
            $tpl = get_option( 'wc_sms_cancelled_tpl', __( 'سفارش {order_id} به دلیل عدم پرداخت لغو شد.', 'wc-bank-receipt' ) );
        }

        if ( $tpl ) {
            $msg = str_replace( '{order_id}', $order_id, $tpl );
            $evt = ( $state === 'approved' ) ? 'approved' : ( ( $state === 'rejected' ) ? 'rejected' : 'cancelled' );
            $this->send_sms( $phone, $msg, $evt, $order_id );
        }
    }

    private function send_admin_upload_sms( $order_id ) {
        $admin_phone = get_option( 'wc_sms_admin_phone' );
        if ( empty( $admin_phone ) ) return;
        $tpl = get_option( 'wc_sms_admin_upload_tpl', __( 'فیش جدید برای سفارش {order_id} بارگذاری شد.', 'wc-bank-receipt' ) );
        $this->send_sms( $admin_phone, str_replace( '{order_id}', $order_id, $tpl ), 'admin_upload', $order_id );
    }

    public function send_payment_reminders() {
        $reminder_hours = absint( get_option( 'wc_sms_reminder_before_hours', 1 ) );
        if ( ! $reminder_hours ) return;
        $expire_hours   = (float) get_option( 'wc_receipt_expire_hours', 2 );
        $gmt_offset     = (float) get_option( 'gmt_offset' );
        $expiry_secs    = $expire_hours * HOUR_IN_SECONDS;
        $reminder_secs  = $reminder_hours * HOUR_IN_SECONDS;

        $orders = wc_get_orders( array(
            'status' => 'pending', 'limit' => -1,
            'payment_method' => self::GATEWAY_ID,
        ) );
        foreach ( $orders as $order ) {
            if ( $order->get_meta( self::META_STATE ) === 'approved' ) continue;
            if ( $order->get_meta( self::META_REMINDER_SENT ) ) continue;
            $start     = $order->get_meta( self::META_REJECTED_TIME ) ?: ( $order->get_date_created()->getTimestamp() + $gmt_offset * HOUR_IN_SECONDS );
            $remaining = ( $start + $expiry_secs ) - current_time('timestamp');
            if ( $remaining > 0 && $remaining <= $reminder_secs ) {
                $phone = $order->get_billing_phone();
                if ( $phone ) {
                    $tpl = get_option( 'wc_sms_reminder_tpl', __( 'مشتری گرامی، مهلت پرداخت سفارش {order_id} رو به اتمام است.', 'wc-bank-receipt' ) );
                    $this->send_sms( $phone, str_replace('{order_id}', $order->get_id(), $tpl), 'reminder', $order->get_id() );
                }
                $order->update_meta_data( self::META_REMINDER_SENT, 1 );
                $order->save();
            }
        }
    }

    private function send_sms( $phone, $message, $event_type = '', $order_id = 0 ) {
        $provider = get_option( 'wc_sms_provider', 'kavenegar' );
        $sender   = '';
        if ( $event_type ) {
            $es = get_option( "wc_sms_{$event_type}_sender" );
            if ( ! empty( $es ) ) $sender = $es;
        }
        if ( empty( $sender ) ) $sender = get_option( "wc_sms_{$provider}_sender", '' );

        $response = $this->send_sms_raw( $phone, $message, $provider, $sender );
        $status   = is_wp_error( $response ) ? 'failed' : 'sent';
        $body     = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::DB_PREFIX . 'sms_log', array(
            'order_id'  => $order_id,
            'recipient' => $phone,
            'message'   => $message,
            'status'    => $status,
            'response'  => substr( $body, 0, 500 ),
            'timestamp' => current_time( 'mysql' ),
        ) );
    }

    private function send_sms_raw( $phone, $message, $provider, $sender = '' ) {
        switch ( $provider ) {
            case 'kavenegar':
                $key    = get_option('wc_sms_kavenegar_apikey');
                $sender = $sender ?: get_option('wc_sms_kavenegar_sender','10008663');
                if ( empty($key) ) return new WP_Error('no_api','API key missing');
                return wp_remote_post("https://api.kavenegar.com/v1/{$key}/sms/send.json", array(
                    'body' => array('receptor'=>$phone,'sender'=>$sender,'message'=>$message),
                    'timeout'=>15,
                ));
            case 'melli':
                $user = get_option('wc_sms_melli_username');
                $pass = get_option('wc_sms_melli_password');
                $sender = $sender ?: get_option('wc_sms_melli_sender');
                if ( empty($user)||empty($pass) ) return new WP_Error('no_creds','Missing credentials');
                return wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', array(
                    'body' => array('username'=>$user,'password'=>$pass,'to'=>$phone,'from'=>$sender,'text'=>$message),
                    'timeout'=>15,
                ));
            case 'custom':
                $url    = get_option('wc_sms_custom_url');
                $apikey = get_option('wc_sms_custom_apikey');
                $sender = $sender ?: get_option('wc_sms_custom_sender');
                if ( empty($url) ) return new WP_Error('no_url','URL missing');
                $args = array('body'=>array('to'=>$phone,'message'=>$message,'sender'=>$sender),'timeout'=>15);
                if ( ! empty($apikey) ) $args['headers'] = array('Authorization'=>'Bearer '.$apikey);
                return wp_remote_post($url,$args);
        }
        return new WP_Error('unknown_provider','Unknown SMS provider');
    }

    // =========================================================================
    // SMS LOG PAGE
    // =========================================================================

    public function sms_log_page() {
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

    public function sms_settings_page() {
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
                            <tr><th><?php _e('کلید API','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_kavenegar_apikey" value="<?php echo esc_attr(get_option('wc_sms_kavenegar_apikey')); ?>" dir="ltr"></td></tr>
                            <tr><th><?php _e('شماره فرستنده','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_kavenegar_sender" value="<?php echo esc_attr(get_option('wc_sms_kavenegar_sender','10008663')); ?>" dir="ltr"></td></tr>
                        </table>
                    </div>

                    <!-- Melli -->
                    <div class="wcr-tab-pane<?php echo $provider==='melli'?' active':''; ?>" id="tab-melli">
                        <table class="wcr-ftbl">
                            <tr><th><?php _e('نام کاربری','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_melli_username" value="<?php echo esc_attr(get_option('wc_sms_melli_username')); ?>" dir="ltr"></td></tr>
                            <tr><th><?php _e('رمز عبور','wc-bank-receipt'); ?></th><td><input type="password" name="wc_sms_melli_password" value="<?php echo esc_attr(get_option('wc_sms_melli_password')); ?>" dir="ltr"></td></tr>
                            <tr><th><?php _e('شماره فرستنده','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_melli_sender" value="<?php echo esc_attr(get_option('wc_sms_melli_sender')); ?>" dir="ltr"></td></tr>
                        </table>
                    </div>

                    <!-- Custom -->
                    <div class="wcr-tab-pane<?php echo $provider==='custom'?' active':''; ?>" id="tab-custom">
                        <table class="wcr-ftbl">
                            <tr><th><?php _e('آدرس API','wc-bank-receipt'); ?></th><td><input type="url" name="wc_sms_custom_url" value="<?php echo esc_attr(get_option('wc_sms_custom_url')); ?>" dir="ltr" placeholder="https://..."></td></tr>
                            <tr><th><?php _e('کلید API','wc-bank-receipt'); ?></th><td><input type="text" name="wc_sms_custom_apikey" value="<?php echo esc_attr(get_option('wc_sms_custom_apikey')); ?>" dir="ltr"></td></tr>
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
    // ADMIN NEW ORDER ALERT (POPUP & SOUND)
    // =========================================================================

    public function trigger_admin_new_order_alert( $order_id, $order = null ) {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order || $order->get_payment_method() !== self::GATEWAY_ID ) {
            return;
        }
        
        $alerts = get_option( 'wcr_pending_admin_alerts', array() );
        if ( ! is_array( $alerts ) ) $alerts = array();
        
        $exists = false;
        foreach ( $alerts as $a ) {
            if ( $a['order_id'] == $order_id ) {
                $exists = true;
                break;
            }
        }
        
        if ( ! $exists ) {
            $alerts[] = array(
                'order_id' => $order_id,
                'time'     => current_time( 'timestamp' ),
            );
            update_option( 'wcr_pending_admin_alerts', $alerts );
        }
    }

    public function ajax_check_new_orders() {
        check_ajax_referer( 'wcr_admin_alert_nonce', 'nonce' );
        $alerts = get_option( 'wcr_pending_admin_alerts', array() );
        if ( ! is_array( $alerts ) ) $alerts = array();
        
        $response = array();
        foreach ( $alerts as $a ) {
            $order = wc_get_order( $a['order_id'] );
            if ( $order ) {
                $response[] = array(
                    'order_id' => $a['order_id'],
                    'view_url' => $order->get_edit_order_url(),
                    'total'    => $order->get_formatted_order_total(),
                    'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                );
            }
        }
        
        wp_send_json_success( array( 'alerts' => $response ) );
    }

    public function ajax_dismiss_new_orders() {
        check_ajax_referer( 'wcr_admin_alert_nonce', 'nonce' );
        delete_option( 'wcr_pending_admin_alerts' );
        wp_send_json_success();
    }

    public function render_admin_alert_popup() {
        $nonce = wp_create_nonce( 'wcr_admin_alert_nonce' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        #wcr-admin-alert-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            z-index: 9999999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
        }
        #wcr-admin-alert-overlay.active {
            display: flex;
            animation: wcr-fade-in 0.3s ease;
        }
        @keyframes wcr-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        #wcr-admin-alert-modal {
            background: #fff;
            padding: 40px 30px;
            border-radius: 16px;
            box-shadow: 0 25px 65px rgba(0,0,0,0.5);
            text-align: center;
            max-width: 480px;
            width: 92%;
            direction: rtl;
            font-family: Tahoma, "Segoe UI", sans-serif;
            animation: wcr-pop-in 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
        }
        @keyframes wcr-pop-in {
            from { transform: scale(0.8) translateY(20px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }
        #wcr-admin-alert-modal .icon {
            font-size: 68px;
            margin-bottom: 16px;
            color: #1565c0;
            display: inline-block;
            animation: wcr-ring 1.5s infinite ease-in-out;
        }
        @keyframes wcr-ring {
            0% { transform: rotate(0); }
            10% { transform: rotate(15deg); }
            20% { transform: rotate(-15deg); }
            30% { transform: rotate(10deg); }
            40% { transform: rotate(-10deg); }
            50% { transform: rotate(0); }
            100% { transform: rotate(0); }
        }
        #wcr-admin-alert-modal h2 {
            margin: 0 0 12px;
            color: #1565c0;
            font-size: 22px;
            font-weight: 700;
        }
        #wcr-admin-alert-modal p {
            color: #546e7a;
            font-size: 15px;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        #wcr-admin-alert-modal .order-list {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 24px;
            max-height: 150px;
            overflow-y: auto;
            text-align: right;
        }
        #wcr-admin-alert-modal .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        #wcr-admin-alert-modal .order-item:last-child {
            border-bottom: none;
        }
        #wcr-admin-alert-modal .btn-group {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        #wcr-admin-alert-modal button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        #wcr-admin-alert-modal .btn-primary {
            background: #1565c0;
            color: #fff;
        }
        #wcr-admin-alert-modal .btn-primary:hover {
            background: #0d47a1;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(21,101,192,0.3);
        }
        #wcr-admin-alert-modal .btn-secondary {
            background: #eceff1;
            color: #455a64;
        }
        #wcr-admin-alert-modal .btn-secondary:hover {
            background: #cfd8dc;
        }
        </style>

        <div id="wcr-admin-alert-overlay">
            <div id="wcr-admin-alert-modal">
                <div class="icon">🔔</div>
                <h2>سفارش جدید ثبت شد!</h2>
                <p>یک یا چند سفارش جدید با روش پرداخت کارت به کارت ثبت شده است.</p>
                <div class="order-list" id="wcr-order-list"></div>
                <div class="btn-group">
                    <button type="button" class="btn-primary" id="wcr-view-orders-btn">مشاهده سفارشات</button>
                    <button type="button" class="btn-secondary" id="wcr-dismiss-alert-btn">متوجه شدم</button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var ajaxurl = '<?php echo esc_url( $ajax_url ); ?>';
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var overlay = document.getElementById('wcr-admin-alert-overlay');
            var orderList = document.getElementById('wcr-order-list');
            var viewBtn = document.getElementById('wcr-view-orders-btn');
            var dismissBtn = document.getElementById('wcr-dismiss-alert-btn');
            
            var audioCtx = null;
            var alarmInterval = null;
            var isAlertActive = false;
            var lastAlertCount = 0;

            function initAudio() {
                if (!audioCtx) {
                    try {
                        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    } catch(e) {
                        console.warn('Web Audio API is not supported.');
                    }
                }
            }

            function playRing() {
                if (!audioCtx) return;
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
                
                var osc1 = audioCtx.createOscillator();
                var osc2 = audioCtx.createOscillator();
                var gainNode = audioCtx.createGain();
                
                osc1.connect(gainNode);
                osc2.connect(gainNode);
                gainNode.connect(audioCtx.destination);
                
                osc1.frequency.value = 880;
                osc2.frequency.value = 660;
                osc1.type = 'sine';
                osc2.type = 'sine';
                
                var now = audioCtx.currentTime;
                gainNode.gain.setValueAtTime(0.001, now);
                gainNode.gain.exponentialRampToValueAtTime(0.4, now + 0.05);
                gainNode.gain.exponentialRampToValueAtTime(0.001, now + 1.2);
                
                osc1.start(now);
                osc2.start(now);
                osc1.stop(now + 1.2);
                osc2.stop(now + 1.2);
            }

            function startAlarm() {
                if (isAlertActive) return;
                initAudio();
                isAlertActive = true;
                playRing();
                alarmInterval = setInterval(playRing, 2000);
            }

            function stopAlarm() {
                isAlertActive = false;
                if (alarmInterval) {
                    clearInterval(alarmInterval);
                    alarmInterval = null;
                }
            }

            function checkNewOrders() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data.alerts && res.data.alerts.length > 0) {
                                if (res.data.alerts.length !== lastAlertCount || !isAlertActive) {
                                    showAlert(res.data.alerts);
                                    lastAlertCount = res.data.alerts.length;
                                }
                            } else {
                                if (isAlertActive) {
                                    hideAlert();
                                    lastAlertCount = 0;
                                }
                            }
                        } catch(e) {}
                    }
                };
                xhr.send('action=wcr_check_new_orders&nonce=' + nonce);
            }

            function showAlert(alerts) {
                orderList.innerHTML = '';
                var firstUrl = '';
                alerts.forEach(function(alert) {
                    var item = document.createElement('div');
                    item.className = 'order-item';
                    item.innerHTML = '<span>سفارش #' + alert.order_id + ' (' + alert.customer + ')</span><strong>' + alert.total + '</strong>';
                    orderList.appendChild(item);
                    if (!firstUrl) firstUrl = alert.view_url;
                });
                
                viewBtn.onclick = function() {
                    if (firstUrl) window.open(firstUrl, '_blank');
                    dismissAlert();
                };
                
                overlay.classList.add('active');
                startAlarm();
            }

            function hideAlert() {
                overlay.classList.remove('active');
                stopAlarm();
            }

            function dismissAlert() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('action=wcr_dismiss_new_orders&nonce=' + nonce);
                hideAlert();
                lastAlertCount = 0;
            }

            dismissBtn.addEventListener('click', dismissAlert);
            
            // Try to init audio on first user interaction to bypass autoplay policies
            document.body.addEventListener('click', function() {
                initAudio();
            }, { once: true });

            // Check every 10 seconds
            checkNewOrders();
            setInterval(checkNewOrders, 10000);
        })();
        </script>
        <?php
    }

    // =========================================================================
    // MISC FILTERS
    // =========================================================================

    public function allow_pending_from_on_hold( $allowed, $new_status, $order ) {
        if ( is_a( $order, 'WC_Order' ) && $order->get_status() === 'on-hold' && $new_status === 'pending' ) {
            return true;
        }
        return $allowed;
    }
}

// Bootstrap
function wc_bank_receipt_pro() {
    return WC_Bank_Receipt_Pro::get_instance();
}
add_action( 'plugins_loaded', 'wc_bank_receipt_pro', 5 );