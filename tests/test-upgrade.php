<?php
/** Integration regressions. ONLY in an explicitly disposable wp-env database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RMWC_RUN_UPGRADE_TESTS' ) ) {
    throw new RuntimeException( 'Requires disposable test database and RMWC_RUN_UPGRADE_TESTS=1.' );
}
global $wpdb;
$wpdb->suppress_errors( true );
$checks = 0;
$check = static function ( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};
$reset = static function () {
    delete_option( 'rmwc_legacy_migration_version' );
    delete_option( 'rmwc_legacy_migration_conflict' );
};
$fault = static function ( $pattern, $callback ) {
    $hit = false;
    $filter = static function ( $sql ) use ( $pattern, &$hit ) {
        if ( ! $hit && preg_match( $pattern, $sql ) ) {
            $hit = true;
            return 'RMWC_INTENTIONAL_DATABASE_ERROR';
        }
        return $sql;
    };
    add_filter( 'query', $filter );
    try {
        $result = $callback();
    } finally {
        remove_filter( 'query', $filter );
    }
    if ( ! $hit ) {
        throw new RuntimeException( 'Fault injection did not execute: ' . $pattern );
    }
    return $result;
};
$old_table = $wpdb->prefix . 'clr_bookings';
$new_table = $wpdb->prefix . 'rmwc_bookings';
$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $new_table ) );
$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $old_table, $new_table ) );
$wpdb->insert( $old_table, [ 'product_id' => 999, 'created_at' => current_time( 'mysql' ) ] );
$reset();
$check( false === $fault( '/SELECT COUNT\(\*\).*clr_bookings/', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed COUNT stops migration' );
$check( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old_table ) ), 'COUNT failure preserves populated legacy table' );
$check( 0 === (int) get_option( 'rmwc_legacy_migration_version', 0 ), 'COUNT failure does not mark completion' );
$check( false === $fault( '/SHOW TABLES LIKE/', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed existence query stops migration' );
$check( false === $fault( '/DROP TABLE/', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed empty-table DROP stops migration' );
$check( false === $fault( '/RENAME TABLE/', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed RENAME stops migration' );
$check( true === RMWC_Legacy_Migration::run(), 'Interrupted table migration resumes' );
$check( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new_table ) ), 'Atomic table rename preserves rows' );
$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $old_table, $new_table ) );
$wpdb->insert( $old_table, [ 'product_id' => 888, 'created_at' => current_time( 'mysql' ) ] );
$reset();
$check( false === RMWC_Legacy_Migration::run(), 'Two populated tables block automatic merge' );
$check( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old_table ) ), 'Conflict preserves legacy reservations' );
$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $old_table ) );
$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $new_table ) );

add_option( 'clr_fixture', [ 'value' => 'unchanged bytes' ], '', true );
get_option( 'rmwc_fixture' ); // Prime a negative option cache entry.
$reset();
$check( false === $fault( '/INSERT INTO .*options.*rmwc_fixture/s', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed option INSERT stops migration' );
$check( [ 'value' => 'unchanged bytes' ] === get_option( 'clr_fixture' ), 'Failed option copy preserves source' );
$check( 0 === (int) get_option( 'rmwc_legacy_migration_version', 0 ), 'Option failure does not mark completion' );
$check( true === RMWC_Legacy_Migration::run(), 'Option migration retries successfully' );
$check( [ 'value' => 'unchanged bytes' ] === get_option( 'rmwc_fixture' ), 'Negative option cache invalidated' );
delete_option( 'rmwc_fixture' );
add_option( 'clr_fixture', 'old' );
add_option( 'rmwc_fixture', 'new' );
$reset();
$check( false === RMWC_Legacy_Migration::run(), 'Differing option values require manual resolution' );
$check( 'old' === get_option( 'clr_fixture' ) && 'new' === get_option( 'rmwc_fixture' ), 'Both option values preserved' );
delete_option( 'clr_fixture' );
delete_option( 'rmwc_fixture' );

$product = new WC_Product_Simple();
$product->set_name( 'Upgrade fixture rental' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product_id = $product->save();
update_post_meta( $product_id, '_clr_rental_enabled', 'yes' );
update_post_meta( $product_id, '_clr_unit_price', '69' );
update_post_meta( $product_id, '_clr_capacity', '5' );
update_post_meta( $product_id, '_clr_allow_pickup', 'yes' );
update_post_meta( $product_id, '_clr_pickup_time', '12:00' );
update_post_meta( $product_id, '_clr_return_time', '10:00' );
$check( 'yes' === get_post_meta( $product_id, '_clr_rental_enabled', true ), 'Legacy product cache primed' );
get_post_meta( $product_id, '_rmwc_rental_enabled', true );
$reset();
$check( false === $fault( '/UPDATE .* AS legacy/', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed metadata UPDATE stops migration' );
$check( 0 === (int) get_option( 'rmwc_legacy_migration_version', 0 ), 'Metadata failure does not mark completion' );
$check( true === RMWC_Legacy_Migration::run(), 'Metadata migration retries successfully' );
$check( 'yes' === get_post_meta( $product_id, '_rmwc_rental_enabled', true ), 'Warmed product metadata cache invalidated' );
update_post_meta( $product_id, '_clr_unit_price', '80' );
$reset();
$check( false === RMWC_Legacy_Migration::run(), 'Differing metadata collision blocks migration' );
$check( '80' === get_post_meta( $product_id, '_clr_unit_price', true ) && '69' === get_post_meta( $product_id, '_rmwc_unit_price', true ), 'Both differing metadata values preserved' );
delete_post_meta( $product_id, '_clr_unit_price' );
update_post_meta( $product_id, '_clr_unit_price', '69' );
$reset();
$check( false === $fault( '/DELETE legacy FROM/', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed duplicate cleanup stops migration' );
$check( true === RMWC_Legacy_Migration::run(), 'Duplicate cleanup resumes without changing canonical value' );
$check( '' === get_post_meta( $product_id, '_clr_unit_price', true ), 'Identical duplicate removed' );

// Real second database connection, not a lock mock.
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock = 'rmwc_migrate_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
$reset();
$check( false === RMWC_Legacy_Migration::run(), 'Concurrent connection cannot enter migration' );
$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
$other->close();
$check( true === RMWC_Legacy_Migration::run(), 'Migration proceeds once other connection releases lock' );
$check( true === RMWC_Legacy_Migration::run(), 'Completed migration is idempotent' );
$reset();
$check( false === $fault( '/INSERT INTO .*options.*rmwc_legacy_migration_version/s', [ 'RMWC_Legacy_Migration', 'run' ] ), 'Failed completion marker stops migration' );
$check( 0 === (int) get_option( 'rmwc_legacy_migration_version', 0 ), 'Completion marker failure remains retryable' );
$check( true === RMWC_Legacy_Migration::run(), 'Completion marker can be written on retry' );

// Real WooCommerce order storage with datastore caching enabled.
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
update_option( 'woocommerce_hpos_datastore_caching_enabled', 'yes' );
$order = wc_create_order();
$order->update_meta_data( '_clr_invoice_payment_terms_addition', 'Stored terms' );
$fee = new WC_Order_Item_Fee();
$fee->set_name( 'Security deposit' );
$fee->set_amount( 150 );
$fee->set_total( 150 );
$fee->set_tax_status( 'none' );
$fee->add_meta_data( '_clr_deposit', 'yes' );
$fee->add_meta_data( '_clr_fee_type', 'deposit' );
$order->add_item( $fee );
$order->save();
$order_id = $order->get_id();
$fee_id = $fee->get_id();
wc_get_order( $order_id )->get_meta( '_clr_invoice_payment_terms_addition', true );
( new WC_Order_Item_Fee( $fee_id ) )->get_meta( '_clr_deposit', true );
$reset();
$check( true === RMWC_Legacy_Migration::run(), 'Order-item and HPOS metadata migration succeeds' );
$fresh_order = wc_get_order( $order_id );
$check( 'Stored terms' === $fresh_order->get_meta( '_rmwc_invoice_payment_terms_addition', true ), 'HPOS cached metadata reloaded after migration' );
$check( 'yes' === ( new WC_Order_Item_Fee( $fee_id ) )->get_meta( '_rmwc_deposit', true ), 'Fee cache reloaded after migration' );

// Real cart/session: both session and persistent carts pass the same load filter.
wc_load_cart();
$start = gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
$end = gmdate( 'Y-m-d', time() + 8 * DAY_IN_SECONDS );
$legacy = [ 'product_id' => $product_id, 'start' => $start, 'end' => $end, 'fulfilment' => 'pickup', 'rent_price' => 1, 'deposit' => 0 ];
$restored = apply_filters( 'woocommerce_get_cart_item_from_session', [ 'product_id' => $product_id, 'variation_id' => 0, 'quantity' => 1, 'data' => wc_get_product( $product_id ), 'clr_rental' => $legacy ], [], 'fixture' );
$check( isset( $restored['rmwc_rental'] ) && ! isset( $restored['clr_rental'] ), 'Legacy session/persistent cart entry restored' );
$accessory = RMWC_Legacy_Migration::restore_cart_item( [ 'clr_rental_accessory' => 'yes', 'clr_rental_accessory_parent' => 'fixture', 'clr_rental_accessory_customer_qty' => 2 ] );
$check( 'fixture' === $accessory['rmwc_rental_accessory_parent'] && 2 === $accessory['rmwc_rental_accessory_customer_qty'], 'Legacy accessory link/quantity restored' );
WC()->session->set( 'clr_v2_deposit_choice', 'online' );
WC()->cart->cart_contents = [ 'fixture' => $restored ];
RMWC_Legacy_Migration::restore_session( WC()->cart );
$check( 'online' === WC()->session->get( 'rmwc_v2_deposit_choice' ), 'Legacy deposit session choice restored' );
RMWC_Plugin::instance()->set_cart_item_prices( WC()->cart );
$check( 69.0 === (float) WC()->cart->cart_contents['fixture']['data']->get_price(), 'Legacy rental price recalculated from server configuration' );
$item = new WC_Order_Item_Product();
RMWC_Plugin::instance()->order_line_item_meta( $item, 'fixture', WC()->cart->cart_contents['fixture'], $order );
$check( is_array( $item->get_meta( '_rmwc_rental_data', true ) ), 'Restored rental saved on order line' );
WC()->cart->cart_contents['fixture']['rmwc_rental']['start'] = 'invalid-date';
RMWC_Plugin::instance()->set_cart_item_prices( WC()->cart );
wc_clear_notices();
RMWC_Plugin::instance()->validate_cart_availability();
$check( wc_notice_count( 'error' ) > 0, 'Invalid restored period blocks checkout' );
WC()->cart->cart_contents['fixture'] = $restored;
wc_clear_notices();
RMWC_Legacy_Migration::guard_cart();
$check( wc_notice_count( 'error' ) > 0, 'Migration failure blocks affected classic cart' );
$errors = new WP_Error();
RMWC_Legacy_Migration::guard_store_cart( $errors, WC()->cart );
$check( $errors->has_errors(), 'Migration failure blocks affected Store API cart' );
$blocked = false;
try {
    RMWC_Legacy_Migration::guard_store_checkout( $order, null );
} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
    $blocked = true;
}
$check( $blocked, 'Migration failure blocks Store API checkout' );
$ordinary = new WC_Product_Simple();
$ordinary->set_name( 'Ordinary fixture' );
$ordinary->set_regular_price( 10 );
$ordinary->save();
$check( true === RMWC_Legacy_Migration::guard_purchasable( true, $ordinary ), 'Ordinary products remain purchasable during failure' );
$check( false === RMWC_Legacy_Migration::guard_purchasable( true, $product ), 'Rental product blocked during failure' );

// Inclusive-end prerelease interval conversion, checked UPDATE and restart.
$wpdb->insert( $new_table, [ 'product_id' => $product_id, 'start_date' => '2026-01-10', 'end_date' => '2026-01-10', 'created_at' => current_time( 'mysql' ) ] );
delete_option( 'rmwc_legacy_migration_done_v1' );
$method = new ReflectionMethod( RMWC_Plugin::class, 'migrate_legacy_bookings' );
$callback = static fn() => $method->invoke( RMWC_Plugin::instance() );
$check( false === $fault( '/UPDATE .*rmwc_bookings/', $callback ), 'Interval UPDATE failure stops conversion' );
$check( 'yes' !== get_option( 'rmwc_legacy_migration_done_v1', 'no' ), 'Interval failure does not mark completion' );
$check( true === $callback(), 'Legacy interval conversion resumes' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT start_at, end_at FROM %i LIMIT 1', $new_table ), ARRAY_A );
$check( '2026-01-10 12:00:00' === $row['start_at'] && '2026-01-11 10:00:00' === $row['end_at'], 'Inclusive end converts to next-day return with configured times' );

// Optional cross-repo integration in the same real order, without API calls.
$bridge = WP_PLUGIN_DIR . '/patsch9-accounting-bridge/includes/class-lexware-api-client.php';
if ( file_exists( $bridge ) ) {
    require_once $bridge;
    $client = ( new ReflectionClass( 'PATSACBR_API_Client' ) )->newInstanceWithoutConstructor();
    $format = new ReflectionMethod( $client, 'format_line_items' );
    $lines = $format->invoke( $client, $fresh_order, false, 'net' );
    $check( 'text' === $lines[0]['type'] && ! isset( $lines[0]['unitPrice'] ), 'Migrated 150 EUR deposit exports only as informational text' );
}
$ordinary->delete( true );
$product->delete( true );
$order->delete( true );
WC()->cart->empty_cart();
WP_CLI::success( $checks . ' upgrade/checkout regression checks passed.' );
