<?php
/**
 * Uninstall routine for WC Bank Receipt Ultimate Control.
 *
 * Data policy: deleting the plugin does NOT delete shop data unless the owner
 * explicitly opted in from Settings → «فیش بانکی» → «حذف دادهها هنگام پاک کردن افزونه».
 * (Option: wc_receipt_delete_data_on_uninstall = yes)
 *
 * Deactivation never reaches this file, so disable/re-enable keeps every table,
 * option, order meta value and receipt file.
 *
 * @package WC_Bank_Receipt
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wcr_opt_in = get_option( 'wc_receipt_delete_data_on_uninstall', 'no' );
if ( 'yes' !== $wcr_opt_in ) {
	return; // keep everything
}

global $wpdb;

// 1. Plugin tables.
foreach ( array( 'cards', 'receipt_log', 'sms_log', 'transitions', 'events' ) as $wcr_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wc_bank_receipt_' . $wcr_table ); // phpcs:ignore WordPress.DB
}

// 2. Plugin options.
$wpdb->query( // phpcs:ignore WordPress.DB
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'wc_receipt_%'
	    OR option_name LIKE 'wc_sms_%'
	    OR option_name LIKE 'wc_bank_card%'
	    OR option_name  =  'wc_bank_receipt_db_version'
	    OR option_name  =  'wcr_pending_admin_alerts'"
);

// 3. Receipt files (uploaded payment proofs).
$wcr_uploads = wp_upload_dir();
if ( empty( $wcr_uploads['error'] ) ) {
	$wcr_dir = trailingslashit( $wcr_uploads['basedir'] ) . 'bank-receipts';
	if ( is_dir( $wcr_dir ) ) {
		$wcr_items = scandir( $wcr_dir );
		foreach ( (array) $wcr_items as $wcr_item ) {
			if ( '.' === $wcr_item || '..' === $wcr_item ) {
				continue;
			}
			$wcr_path = $wcr_dir . '/' . $wcr_item;
			if ( is_file( $wcr_path ) ) {
				@unlink( $wcr_path );
			}
		}
		@rmdir( $wcr_dir );
	}
}

// 4. Order meta written by the plugin (only when the owner opted in).
$wcr_meta_keys = array(
	'_wc_receipt_url',
	'_wc_receipt_file',
	'_wc_receipt_state',
	'_wc_receipt_rejected_time',
	'_wc_receipt_reject_reason',
	'_wc_receipt_assigned_card_id',
	'_wc_receipt_reminder_sent',
	'_wc_receipt_deadline',
	'_wc_receipt_uploaded_time',
	'_wc_receipt_history',
);

foreach ( $wcr_meta_keys as $wcr_key ) {
	// HPOS order meta table plus the legacy post meta table, when present.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s", $wcr_key ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $wcr_key ) ); // phpcs:ignore WordPress.DB
}

// 5. Per-user alert cursors (they only exist to order the alert popup).
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'wcr_alert_cursor'" ); // phpcs:ignore WordPress.DB

// 6. Scheduled work.
wp_clear_scheduled_hook( 'wc_receipt_cleanup_hook' );
wp_clear_scheduled_hook( 'wc_receipt_reminder_hook' );
wp_clear_scheduled_hook( 'wc_receipt_expire_hook' );
