<?php
/**
 * Uninstall cleanup for Patsch9 Rental Engine for WooCommerce.
 *
 * @package RMWC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'rmwc_delete_data_on_uninstall', 'no' ) ) {
    return;
}

global $wpdb;

wp_clear_scheduled_hook( 'rmwc_hourly_reminders' );
wp_clear_scheduled_hook( 'rmwc_expire_unpaid_order' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'rmwc_expire_unpaid_order' );
}

$rmwc_tables = [
    $wpdb->prefix . 'rmwc_bookings',
    $wpdb->prefix . 'rmwc_terms_versions',
    $wpdb->prefix . 'rmwc_documents',
    $wpdb->prefix . 'rmwc_assets',
    $wpdb->prefix . 'rmwc_asset_assignments',
];
foreach ( $rmwc_tables as $rmwc_table ) {
    // Identifiers are constructed exclusively from the trusted WordPress prefix and plugin constant suffixes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup; cached reads/writes are not appropriate during removal.
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $rmwc_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit opt-in uninstall cleanup of plugin-owned tables; identifier is prepared with %i.
}

$rmwc_product_meta_keys = [
    '_rmwc_rental_enabled',
    '_rmwc_billing_unit',
    '_rmwc_unit_price',
    '_rmwc_capacity',
    '_rmwc_pickup_time',
    '_rmwc_return_time',
    '_rmwc_min_days',
    '_rmwc_max_days',
    '_rmwc_buffer_hours',
    '_rmwc_buffer_days',
    '_rmwc_advance_days',
    '_rmwc_deposit',
    '_rmwc_deposit_notice',
    '_rmwc_allow_pickup',
    '_rmwc_allow_delivery',
    '_rmwc_allow_delivery_return',
    '_rmwc_delivery_fee',
    '_rmwc_pricing_rules',
    '_rmwc_weekend_enabled',
    '_rmwc_weekend_price',
    '_rmwc_weekend_start_day',
    '_rmwc_weekend_end_day',
    '_rmwc_blocked_pickup_days',
    '_rmwc_blocked_return_days',
    '_rmwc_weekday_prices',
    '_rmwc_addons',
    '_rmwc_delivery_zones',
    '_rmwc_deposit_mode',
    '_rmwc_terms_required',
    '_rmwc_product_terms_content',
    '_rmwc_product_terms_versions',
    '_rmwc_product_terms_active_id',
    '_rmwc_asset_tracking',
    '_rmwc_accessory_only',
    '_rmwc_v2_accessory_options',
    '_rmwc_handover_checklist',
    '_rmwc_return_checklist',
    '_rmwc_workflow_signature_enabled',
];
foreach ( $rmwc_product_meta_keys as $rmwc_meta_key ) {
    delete_post_meta_by_key( $rmwc_meta_key );
}

$rmwc_options = [
    'rmwc_db_version',
    'rmwc_legacy_migration_version',
    'rmwc_legacy_migration_conflict',
    'rmwc_legacy_migration_done_v1',
    'rmwc_delivery_origin',
    'rmwc_google_routes_enabled',
    'rmwc_google_routes_api_key',
    'rmwc_reminder_enabled',
    'rmwc_reminder_hours',
    'rmwc_reminder_subject',
    'rmwc_reminder_body',
    'rmwc_delete_data_on_uninstall',
    'rmwc_unpaid_hold_minutes',
    'rmwc_v2_offline_gateway_ids',
    'woocommerce_rmwc_document_settings',
];
foreach ( $rmwc_options as $rmwc_option ) {
    delete_option( $rmwc_option );
}


$rmwc_order_meta_keys = [
    '_rmwc_booking_conflict',
    '_rmwc_deposit_status',
    '_rmwc_deposit_refunded',
    '_rmwc_deposit_retained',
    '_rmwc_deposit_returned_at',
    '_rmwc_deposit_retention_reason',
    '_rmwc_deposit_note',
    '_rmwc_deposit_required_total',
    '_rmwc_deposit_online_expected',
    '_rmwc_deposit_checkout_choice',
    '_rmwc_invoice_qr_disable',
    '_rmwc_v2_deposit_ledger',
    '_rmwc_invoice_payment_terms_addition',
    '_rmwc_invoice_payment_terms_extra',
    '_rmwc_deposit_online_receipt_mailed',
    '_rmwc_deposit_refund_uncertain',
    '_rmwc_deposit_refund',
    '_rmwc_deposit_refund_method',
    '_rmwc_terms_snapshots',
    '_rmwc_product_terms_snapshots',
];
foreach ( $rmwc_order_meta_keys as $rmwc_meta_key ) {
    delete_post_meta_by_key( $rmwc_meta_key );
}

$rmwc_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$rmwc_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rmwc_hpos_meta_table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $rmwc_table_exists === $rmwc_hpos_meta_table ) {
    foreach ( $rmwc_order_meta_keys as $rmwc_meta_key ) {
        $wpdb->delete( $rmwc_hpos_meta_table, [ 'meta_key' => $rmwc_meta_key ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit opt-in uninstall cleanup; cache is irrelevant and the meta-key scan runs only during destructive uninstall.
    }
}

$rmwc_order_item_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
foreach ( [ '_rmwc_booking_admin_cancelled', '_rmwc_rental_data', '_rmwc_fee_type', '_rmwc_deposit', '_rmwc_terms_version_id', '_rmwc_terms_version', '_rmwc_terms_hash', '_rmwc_terms_accepted_at', '_rmwc_product_terms_snapshot', '_rmwc_rental_accessory', '_rmwc_rental_parent_product', '_rmwc_rental_accessory_group', '_rmwc_rental_accessory_label', '_rmwc_rental_accessory_mode' ] as $rmwc_meta_key ) {
    $wpdb->delete( $rmwc_order_item_meta_table, [ 'meta_key' => $rmwc_meta_key ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit opt-in uninstall cleanup; cache is irrelevant and the meta-key scan runs only during destructive uninstall.
}

$rmwc_patterns = [
    '_transient_rmwc_route_%',
    '_transient_timeout_rmwc_route_%',
    '_transient_rmwc_rate_%',
    '_transient_timeout_rmwc_rate_%',
    '_transient_rmwc_v2_deposit_choice_product_ids',
    '_transient_timeout_rmwc_v2_deposit_choice_product_ids',
];
foreach ( $rmwc_patterns as $rmwc_pattern ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup; cached reads/writes are not appropriate during removal.
    $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s", $wpdb->options, $wpdb->esc_like( rtrim( $rmwc_pattern, '%' ) ) . '%' ) );
}
