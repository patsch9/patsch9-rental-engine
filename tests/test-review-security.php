<?php
/** Request-authentication regressions. ONLY in an explicitly disposable database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RMWC_RUN_UPGRADE_TESTS' ) ) {
    throw new RuntimeException( 'Requires disposable database and RMWC_RUN_UPGRADE_TESTS=1.' );
}
$checks = 0;
$check = static function( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};
class RMWC_Review_Denied extends RuntimeException {}
$die = static function() {
    return static function( $message, $title, $args ) {
        throw new RMWC_Review_Denied( (string) ( $args['response'] ?? 500 ) );
    };
};
add_filter( 'wp_die_handler', $die );
add_filter( 'wp_die_ajax_handler', $die );
add_filter( 'wp_die_json_handler', $die );
$admin = (int) get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] )[0];
$subscriber = wp_create_user( 'rmwc-review-subscriber', wp_generate_password(), 'review@example.invalid' );
if ( is_wp_error( $subscriber ) ) { throw new RuntimeException( 'Could not create fixture user.' ); }
$product = new WC_Product_Simple();
$product->set_name( 'Review authorization fixture' );
$product->save();
$product_id = $product->get_id();
update_post_meta( $product_id, '_rmwc_rental_enabled', 'yes' );
$targets = [
    [ RMWC_V2_Terms::instance(), 'save_terms', 'rmwc_terms_nonce', 'rmwc_save_terms' ],
    [ RMWC_V2_Inventory::instance(), 'save_asset', 'rmwc_v2_asset_nonce', 'rmwc_asset_save' ],
    [ RMWC_V2_Inventory::instance(), 'delete_asset', 'rmwc_v2_asset_delete_nonce', 'rmwc_asset_delete_123' ],
    [ RMWC_V2_Workflow::instance(), 'handle_handover', 'rmwc_workflow_nonce', 'rmwc_workflow_handover_123' ],
    [ RMWC_V2_Workflow::instance(), 'handle_return', 'rmwc_workflow_nonce', 'rmwc_workflow_return_123' ],
    [ RMWC_V2_Deposits::instance(), 'admin_receive', 'rmwc_v2_deposit_nonce', 'rmwc_deposit_receive_123' ],
    [ RMWC_V2_Deposits::instance(), 'admin_refund', 'rmwc_v2_deposit_nonce', 'rmwc_deposit_refund_123' ],
    [ RMWC_V2_Deposits::instance(), 'admin_clear_uncertain', 'rmwc_v2_deposit_nonce', 'rmwc_deposit_clear_uncertain_123' ],
    [ RMWC_V2_Documents::instance(), 'admin_send_contract_email', 'rmwc_contract_email_nonce', 'rmwc_send_contract_email_123' ],
];
foreach ( $targets as [ $object, $method, $field, $action ] ) {
    foreach ( [ [ 0, 'valid' ], [ $subscriber, 'valid' ], [ $admin, 'missing' ], [ $admin, 'wrong' ] ] as [ $user, $nonce_kind ] ) {
        wp_set_current_user( $user );
        $_POST = [ 'order_id' => 123, 'booking_id' => 123, 'asset_id' => 123 ];
        if ( 'missing' !== $nonce_kind ) {
            $_POST[ $field ] = wp_create_nonce( 'wrong' === $nonce_kind ? 'unrelated-action' : $action );
        }
        $denied = false;
        try { $object->$method(); } catch ( RMWC_Review_Denied $exception ) { $denied = '403' === $exception->getMessage(); }
        $check( $denied, $method . ': rejects ' . $user . '/' . $nonce_kind . ' before processing' );
    }
}

$product_handlers = [
    [ RMWC_Plugin::instance(), 'save_product_fields' ],
    [ RMWC_V2_Deposits::instance(), 'save_product_fields' ],
    [ RMWC_V2_Terms::instance(), 'save_product_fields' ],
    [ RMWC_V2_Workflow::instance(), 'save_product_fields' ],
    [ RMWC_V2_Materials::instance(), 'save_product_fields' ],
    [ RMWC_V2_Inventory::instance(), 'save_product_field' ],
    [ RMWC_V2_Accessories::instance(), 'save_product_option' ],
];
foreach ( [ [ 0, true ], [ $subscriber, true ], [ $admin, false ] ] as [ $user, $valid ] ) {
    wp_set_current_user( $user );
    $_POST = [ 'rmwc_product_nonce' => wp_create_nonce( $valid ? 'rmwc_save_product_' . $product_id : 'wrong-action' ), '_rmwc_rental_enabled' => 'yes', '_rmwc_capacity' => 999 ];
    foreach ( $product_handlers as [ $object, $method ] ) {
        $before = get_post_meta( $product_id );
        $object->$method( $product_id );
        $check( $before === get_post_meta( $product_id ), get_class( $object ) . ': unauthorized product save preserves metadata' );
    }
}

$settings = RMWC_V2_Deposits::instance();
update_option( 'rmwc_v2_offline_gateway_ids', 'bacs' );
foreach ( [ [ 0, true ], [ $subscriber, true ], [ $admin, false ] ] as [ $user, $valid ] ) {
    wp_set_current_user( $user );
    $_POST = [ 'rmwc_admin_nonce' => wp_create_nonce( $valid ? 'rmwc_admin_action' : 'wrong-action' ), 'rmwc_v2_offline_gateway_ids' => 'cheque' ];
    $settings->save_settings();
    $check( 'bacs' === get_option( 'rmwc_v2_offline_gateway_ids' ), 'Direct settings hook requires capability and nonce' );
}
wp_set_current_user( $admin );
$_POST = [ 'rmwc_admin_nonce' => wp_create_nonce( 'rmwc_admin_action' ), 'rmwc_v2_offline_gateway_ids' => 'cheque' ];
$settings->save_settings();
$check( 'cheque' === get_option( 'rmwc_v2_offline_gateway_ids' ), 'Authorized settings request still works' );

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$plugin = RMWC_Plugin::instance();
$delivery_allowed = new ReflectionMethod( $plugin, 'delivery_allowed' );
$delivery_allowed->setAccessible( true );
update_post_meta( $product_id, '_rmwc_allow_delivery', 'no' );
update_post_meta( $product_id, '_rmwc_allow_delivery_return', 'yes' );
$check( true === $delivery_allowed->invoke( $plugin, $product_id ), 'Delivery/collection-only configuration is accepted by server-side delivery paths' );
update_post_meta( $product_id, '_rmwc_allow_delivery_return', 'no' );
$check( false === $delivery_allowed->invoke( $plugin, $product_id ), 'Delivery remains disabled when neither transport mode is enabled' );

$request_rate = new ReflectionMethod( $plugin, 'request_rate_allowed' );
$request_rate->setAccessible( true );
$rate_bucket = 'review_client_' . strtolower( wp_generate_password( 8, false, false ) );
$ip_one = static function() { return '203.0.113.10'; };
$ip_two = static function() { return '203.0.113.11'; };
add_filter( 'rmwc_client_ip', $ip_one );
$check( true === $request_rate->invoke( $plugin, $rate_bucket, 1, MINUTE_IN_SECONDS ), 'Filtered client IP receives its own rate-limit budget' );
remove_filter( 'rmwc_client_ip', $ip_one );
add_filter( 'rmwc_client_ip', $ip_two );
$check( true === $request_rate->invoke( $plugin, $rate_bucket, 1, MINUTE_IN_SECONDS ), 'A second validated filtered client IP receives a separate budget' );
remove_filter( 'rmwc_client_ip', $ip_two );

$public_rate = new ReflectionMethod( $plugin, 'public_request_rate_allowed' );
$public_rate->setAccessible( true );
$global_bucket = 'review_global_' . strtolower( wp_generate_password( 8, false, false ) );
$global_ip = '203.0.113.12';
$global_ip_filter = static function() use ( $global_ip ) { return $global_ip; };
add_filter( 'rmwc_client_ip', $global_ip_filter );
$fingerprint = hash_hmac( 'sha256', $global_ip, wp_salt( 'nonce' ) );
$client_key = 'rmwc_rate_' . sanitize_key( $global_bucket ) . '_' . substr( $fingerprint, 0, 32 );
delete_transient( $client_key );
set_transient( 'rmwc_global_rate_' . sanitize_key( $global_bucket ), 1, MINUTE_IN_SECONDS );
$check(
    false === $public_rate->invoke( $plugin, $global_bucket, 1, 1, MINUTE_IN_SECONDS ) && false === get_transient( $client_key ),
    'Exhausted global budget short-circuits before creating a per-client transient'
);
remove_filter( 'rmwc_client_ip', $global_ip_filter );
delete_transient( 'rmwc_global_rate_' . sanitize_key( $global_bucket ) );
delete_transient( $client_key );

set_current_screen( 'dashboard' );
$check( ! rmwc_dependency_notice_allowed(), 'Dependency notice hidden on dashboard' );
set_current_screen( 'edit-post' );
$check( ! rmwc_dependency_notice_allowed(), 'Dependency notice hidden on unrelated post screen' );
set_current_screen( 'plugins' );
$check( rmwc_dependency_notice_allowed(), 'Dependency notice visible in plugin management' );
wp_set_current_user( $subscriber );
$check( ! rmwc_dependency_notice_allowed(), 'Dependency notice hidden from unauthorized users' );

$_POST = [];
remove_filter( 'wp_die_handler', $die );
remove_filter( 'wp_die_ajax_handler', $die );
remove_filter( 'wp_die_json_handler', $die );
wp_set_current_user( $admin );
$product->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $subscriber );
WP_CLI::success( $checks . ' review authorization and notice checks passed.' );
