<?php
/**
 * Uninstall cleanup for Patsch9 Rental Engine for WooCommerce.
 *
 * @package RMWC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'clr_delete_data_on_uninstall', 'no' ) ) {
    return;
}

global $wpdb;

wp_clear_scheduled_hook( 'rmwc_hourly_reminders' );
wp_clear_scheduled_hook( 'clr_hourly_reminders' ); // Legacy 1.0.x hook.
wp_clear_scheduled_hook( 'rmwc_expire_unpaid_order' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'rmwc_expire_unpaid_order' );
}

$rmwc_tables = [
    $wpdb->prefix . 'clr_bookings',
    $wpdb->prefix . 'clr_terms_versions',
    $wpdb->prefix . 'clr_documents',
    $wpdb->prefix . 'clr_assets',
    $wpdb->prefix . 'clr_asset_assignments',
];
foreach ( $rmwc_tables as $rmwc_table ) {
    // Identifiers are constructed exclusively from the trusted WordPress prefix and plugin constant suffixes.
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $rmwc_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$rmwc_product_meta_keys = [
    '_clr_rental_enabled',
    '_clr_billing_unit',
    '_clr_unit_price',
    '_clr_capacity',
    '_clr_pickup_time',
    '_clr_return_time',
    '_clr_min_days',
    '_clr_max_days',
    '_clr_buffer_hours',
    '_clr_buffer_days',
    '_clr_advance_days',
    '_clr_deposit',
    '_clr_deposit_notice',
    '_clr_allow_pickup',
    '_clr_allow_delivery',
    '_clr_allow_delivery_return',
    '_clr_delivery_fee',
    '_clr_pricing_rules',
    '_clr_weekend_enabled',
    '_clr_weekend_price',
    '_clr_weekend_start_day',
    '_clr_weekend_end_day',
    '_clr_blocked_pickup_days',
    '_clr_blocked_return_days',
    '_clr_weekday_prices',
    '_clr_addons',
    '_clr_delivery_zones',
    '_clr_deposit_mode',
    '_clr_terms_required',
    '_clr_product_terms_content',
    '_clr_product_terms_versions',
    '_clr_product_terms_active_id',
    '_clr_asset_tracking',
    '_clr_accessory_only',
    '_clr_v2_accessory_options',
    '_clr_handover_checklist',
    '_clr_return_checklist',
    '_clr_workflow_signature_enabled',
];
foreach ( $rmwc_product_meta_keys as $rmwc_meta_key ) {
    delete_post_meta_by_key( $rmwc_meta_key );
}

$rmwc_options = [
    'clr_db_version',
    'clr_legacy_migration_done_v1',
    'clr_delivery_origin',
    'clr_google_routes_enabled',
    'clr_google_routes_api_key',
    'clr_reminder_enabled',
    'clr_reminder_hours',
    'clr_reminder_subject',
    'clr_reminder_body',
    'clr_delete_data_on_uninstall',
    'clr_unpaid_hold_minutes',
    'clr_v2_offline_gateway_ids',
    'woocommerce_rmwc_document_settings',
];
foreach ( $rmwc_options as $rmwc_option ) {
    delete_option( $rmwc_option );
}


$rmwc_order_meta_keys = [
    '_clr_booking_conflict',
    '_clr_deposit_status',
    '_clr_deposit_refunded',
    '_clr_deposit_retained',
    '_clr_deposit_returned_at',
    '_clr_deposit_retention_reason',
    '_clr_deposit_note',
    '_clr_deposit_required_total',
    '_clr_deposit_online_expected',
    '_clr_deposit_checkout_choice',
    '_clr_invoice_qr_disable',
    '_clr_v2_deposit_ledger',
    '_clr_invoice_payment_terms_addition',
    '_clr_invoice_payment_terms_extra',
    '_clr_deposit_online_receipt_mailed',
    '_clr_deposit_refund_uncertain',
    '_clr_deposit_refund',
    '_clr_deposit_refund_method',
    '_clr_terms_snapshots',
    '_clr_product_terms_snapshots',
];
foreach ( $rmwc_order_meta_keys as $rmwc_meta_key ) {
    delete_post_meta_by_key( $rmwc_meta_key );
}

$rmwc_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$rmwc_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rmwc_hpos_meta_table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $rmwc_table_exists === $rmwc_hpos_meta_table ) {
    foreach ( $rmwc_order_meta_keys as $rmwc_meta_key ) {
        $wpdb->delete( $rmwc_hpos_meta_table, [ 'meta_key' => $rmwc_meta_key ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}

$rmwc_order_item_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
foreach ( [ '_clr_booking_admin_cancelled', '_clr_rental_data', '_clr_fee_type', '_clr_deposit', '_clr_terms_version_id', '_clr_terms_version', '_clr_terms_hash', '_clr_terms_accepted_at', '_clr_product_terms_snapshot', '_clr_rental_accessory', '_clr_rental_parent_product', '_clr_rental_accessory_group', '_clr_rental_accessory_label', '_clr_rental_accessory_mode' ] as $rmwc_meta_key ) {
    $wpdb->delete( $rmwc_order_item_meta_table, [ 'meta_key' => $rmwc_meta_key ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

$rmwc_patterns = [
    '_transient_rmwc_route_%',
    '_transient_timeout_rmwc_route_%',
    '_transient_rmwc_rate_%',
    '_transient_timeout_rmwc_rate_%',
    '_transient_clr_route_%',
    '_transient_timeout_clr_route_%',
    '_transient_clr_rate_%',
    '_transient_timeout_clr_rate_%',
    '_transient_clr_v2_deposit_choice_product_ids',
    '_transient_timeout_clr_v2_deposit_choice_product_ids',
];
foreach ( $rmwc_patterns as $rmwc_pattern ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s", $wpdb->options, $wpdb->esc_like( rtrim( $rmwc_pattern, '%' ) ) . '%' ) );
}
