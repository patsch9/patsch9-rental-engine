<?php
/** Regression coverage for the 2026-11 Google review findings. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RMWC_RUN_UPGRADE_TESTS' ) ) {
    throw new RuntimeException( 'Requires disposable database and RMWC_RUN_UPGRADE_TESTS=1.' );
}

$checks = 0;
$check = static function( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};

$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] );
if ( ! $admin_ids ) {
    throw new RuntimeException( 'Administrator fixture is missing.' );
}
wp_set_current_user( (int) $admin_ids[0] );

$parent = new WC_Product_Variable();
$parent->set_name( 'Review variable rental' );
$parent->set_status( 'publish' );
$parent->save();
$parent_id = $parent->get_id();
update_post_meta( $parent_id, '_rmwc_rental_enabled', 'yes' );

$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_regular_price( '10' );
$variation->set_status( 'publish' );
$variation->save();
$variation_id = $variation->get_id();

$check( $parent_id === RMWC_Product_Context::rental_config_id( $parent_id, $variation_id ), 'Variation inherits rental configuration from parent' );
$check( false === RMWC_V2_Shipping::instance()->product_needs_shipping( true, $variation ), 'Inherited rental variation does not trigger normal shipping' );
update_post_meta( $parent_id, '_rmwc_rental_enabled', 'no' );
update_post_meta( $parent_id, '_rmwc_accessory_only', 'yes' );
$check( $parent_id === RMWC_Product_Context::accessory_config_id( $variation_id ), 'Accessory variation inherits parent configuration' );
$check( false === RMWC_V2_Shipping::instance()->product_needs_shipping( true, $variation ), 'Inherited accessory variation does not trigger normal shipping' );

$product = new WC_Product_Simple();
$product->set_name( 'Review rental fixture' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->save();
$product_id = $product->get_id();
update_post_meta( $product_id, '_rmwc_rental_enabled', 'yes' );
update_post_meta( $product_id, '_rmwc_capacity', 3 );
update_post_meta( $product_id, '_rmwc_pickup_time', '12:00' );
update_post_meta( $product_id, '_rmwc_return_time', '10:00' );
update_post_meta( $product_id, '_rmwc_min_days', 1 );
update_post_meta( $product_id, '_rmwc_max_days', 0 );
update_post_meta( $product_id, '_rmwc_advance_days', 0 );

$order = wc_create_order();
foreach ( [ 'first', 'second' ] as $marker ) {
    $item = new WC_Order_Item_Product();
    $item->set_product( $product );
    $item->set_quantity( 1 );
    $item->add_meta_data( '_rmwc_rental_data', [ 'product_id' => $product_id, 'marker' => $marker ], true );
    $order->add_item( $item );
}
$order->save();
$items = array_values( $order->get_items( 'line_item' ) );
$workflow_method = new ReflectionMethod( RMWC_V2_Workflow::instance(), 'rental_data_for_booking' );
$workflow_method->setAccessible( true );
$matched = $workflow_method->invoke( RMWC_V2_Workflow::instance(), [ 'order_item_id' => $items[1]->get_id(), 'product_id' => $product_id ], $order );
$check( 'second' === ( $matched['marker'] ?? '' ), 'Workflow resolves duplicate products by order item ID' );

global $wpdb;
$plugin = RMWC_Plugin::instance();
$admin_actions = new ReflectionMethod( $plugin, 'admin_handle_actions' );
$admin_actions->setAccessible( true );
$old_request_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';

$blocked_start = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+30 days' );
$blocked_end = $blocked_start->modify( '+1 day' );
update_post_meta( $product_id, '_rmwc_blocked_pickup_days', [ (int) $blocked_start->format( 'N' ) ] );
$before_manual = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $wpdb->prefix . 'rmwc_bookings', $product_id ) );
$_POST = [
    'rmwc_action' => 'add_manual',
    'rmwc_admin_nonce' => wp_create_nonce( 'rmwc_admin_action' ),
    'product_id' => $product_id,
    'start_date' => $blocked_start->format( 'Y-m-d' ),
    'end_date' => $blocked_end->format( 'Y-m-d' ),
    'quantity' => 1,
];
$admin_actions->invoke( $plugin );
$after_manual = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $wpdb->prefix . 'rmwc_bookings', $product_id ) );
$check( $before_manual === $after_manual, 'Manual booking obeys product interval rules' );
update_post_meta( $product_id, '_rmwc_blocked_pickup_days', [] );

$period_order = wc_create_order();
$period_item = new WC_Order_Item_Product();
$period_item->set_product( $product );
$period_item->set_quantity( 1 );
$old_start = $blocked_start->modify( '+5 days' );
$old_end = $old_start->modify( '+2 days' );
$new_start = $old_start->modify( '+3 days' );
$new_end = $new_start->modify( '+2 days' );
$period_item->add_meta_data( '_rmwc_rental_data', [
    'product_id' => $product_id,
    'start' => $old_start->format( 'Y-m-d' ),
    'end' => $old_end->format( 'Y-m-d' ),
    'start_at' => $old_start->format( 'Y-m-d' ) . ' 12:00:00',
    'end_at' => $old_end->format( 'Y-m-d' ) . ' 10:00:00',
    'days' => 2,
], true );
$period_item->add_meta_data( __( 'Rental start', 'patsch9-rental-engine' ), 'old canonical', true );
$period_item->add_meta_data( __( 'Pickup / rental start', 'patsch9-rental-engine' ), 'stale duplicate', true );
$period_item->add_meta_data( 'Abholung', 'legacy duplicate', true );
$period_item->add_meta_data( __( 'Return', 'patsch9-rental-engine' ), 'old return', true );
$period_order->add_item( $period_item );
$period_order->save();
$period_item_id = $period_item->get_id();

$wpdb->insert( $wpdb->prefix . 'rmwc_bookings', [
    'product_id' => $product_id,
    'order_id' => $period_order->get_id(),
    'order_item_id' => $period_item_id,
    'source' => 'order',
    'start_date' => $old_start->format( 'Y-m-d' ),
    'end_date' => $old_end->format( 'Y-m-d' ),
    'start_at' => $old_start->format( 'Y-m-d' ) . ' 12:00:00',
    'end_at' => $old_end->format( 'Y-m-d' ) . ' 10:00:00',
    'quantity' => 1,
    'customer_name' => '',
    'customer_email' => '',
    'note' => '',
    'status' => 'reserved',
    'workflow_status' => 'reserved',
    'created_at' => current_time( 'mysql' ),
] );
$booking_id = (int) $wpdb->insert_id;
if ( ! $booking_id ) {
    throw new RuntimeException( 'Could not create booking fixture.' );
}
$_POST = [
    'rmwc_action' => 'update_booking',
    'rmwc_admin_nonce' => wp_create_nonce( 'rmwc_admin_action' ),
    'booking_id' => $booking_id,
    'start_date' => $new_start->format( 'Y-m-d' ),
    'end_date' => $new_end->format( 'Y-m-d' ),
    'note' => '',
];
$admin_actions->invoke( $plugin );
$period_order = wc_get_order( $period_order->get_id() );
$period_item = $period_order->get_item( $period_item_id );
$rental_data = $period_item->get_meta( '_rmwc_rental_data', true );
$check( $new_start->format( 'Y-m-d' ) === ( $rental_data['start'] ?? '' ), 'Admin booking update synchronizes rental data' );
$check( $period_item->meta_exists( __( 'Rental start', 'patsch9-rental-engine' ) ), 'Canonical rental-start metadata remains' );
$check( ! $period_item->meta_exists( __( 'Pickup / rental start', 'patsch9-rental-engine' ) ), 'Obsolete pickup metadata is removed' );
$check( ! $period_item->meta_exists( 'Abholung' ), 'Historic pickup metadata is removed' );

$documents_source = file_get_contents( RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-documents.php' );
$admin_source = file_get_contents( RMWC_DIR . 'assets/admin.js' );
$frontend_source = file_get_contents( RMWC_DIR . 'assets/rental.js' );
$uninstall_source = file_get_contents( RMWC_DIR . 'uninstall.php' );
$inventory_source = file_get_contents( RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-inventory.php' );
$materials_source = file_get_contents( RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-materials.php' );
$check( false !== strpos( $documents_source, 'str_replace( "\xc2\xa0"' ), 'Deposit PDFs normalize non-breaking spaces explicitly' );
$check( false === strpos( $admin_source, "+' €'" ), 'Admin deposit preview does not hard-code euro formatting' );
$check( false === strpos( $frontend_source, 'alert(' ), 'Rental frontend uses inline feedback instead of alerts' );
foreach ( [ '_rmwc_document_integrity_warning', '_rmwc_contract_email_document_id', '_rmwc_contract_email_last_failed_at', '_rmwc_mobile_action_note_signature', '_rmwc_rental_accessory_customer_qty', '_transient_rmwc_global_rate_%' ] as $cleanup_key ) {
    $check( false !== strpos( $uninstall_source, $cleanup_key ), 'Uninstall cleanup contains ' . $cleanup_key );
}
$check( false !== strpos( $inventory_source, 'RMWC_Product_Context::rental_config_id' ), 'Inventory uses shared variation resolver' );
$check( false !== strpos( $materials_source, 'RMWC_Product_Context::rental_config_id' ), 'Materials use shared variation resolver' );

$_POST = [];
if ( null === $old_request_method ) {
    unset( $_SERVER['REQUEST_METHOD'] );
} else {
    $_SERVER['REQUEST_METHOD'] = $old_request_method;
}
$wpdb->delete( $wpdb->prefix . 'rmwc_bookings', [ 'id' => $booking_id ], [ '%d' ] );
$period_order->delete( true );
$order->delete( true );
$product->delete( true );
$variation->delete( true );
$parent->delete( true );
WP_CLI::success( $checks . ' Google review regression checks passed.' );
