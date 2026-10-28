<?php
/** Required rental-accessory regressions. ONLY in an explicitly disposable wp-env database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RMWC_RUN_UPGRADE_TESTS' ) ) {
    throw new RuntimeException( 'Requires disposable test database and RMWC_RUN_UPGRADE_TESTS=1.' );
}

$checks = 0;
$check = static function ( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};

wc_load_cart();
WC()->cart->empty_cart( true );
wc_clear_notices();

$rental = new WC_Product_Simple();
$rental->set_name( 'Required accessory rental fixture' );
$rental->set_status( 'publish' );
$rental->set_regular_price( '50' );
$rental_id = $rental->save();
update_post_meta( $rental_id, '_rmwc_rental_enabled', 'yes' );

$accessory_a = new WC_Product_Simple();
$accessory_a->set_name( 'Required accessory A' );
$accessory_a->set_status( 'publish' );
$accessory_a->set_regular_price( '5' );
$accessory_a_id = $accessory_a->save();
update_post_meta( $accessory_a_id, '_rmwc_accessory_only', 'yes' );

$accessory_b = new WC_Product_Simple();
$accessory_b->set_name( 'Required accessory B' );
$accessory_b->set_status( 'publish' );
$accessory_b->set_regular_price( '6' );
$accessory_b_id = $accessory_b->save();
update_post_meta( $accessory_b_id, '_rmwc_accessory_only', 'yes' );

update_post_meta(
    $rental_id,
    '_rmwc_v2_accessory_options',
    [
        [
            'group'      => 'Required material',
            'product_id' => $accessory_a_id,
            'label'      => 'Option A',
            'required'   => 1,
            'selection'  => 'multiple',
            'mode'       => 'once',
        ],
        [
            'group'      => 'Required material',
            'product_id' => $accessory_b_id,
            'label'      => 'Option B',
            'required'   => 1,
            'selection'  => 'multiple',
            'mode'       => 'once',
        ],
    ]
);

$parent_key  = 'rmwc-required-parent';
$child_a_key = 'rmwc-required-a';
$child_b_key = 'rmwc-required-b';
$parent = [
    'product_id'   => $rental_id,
    'variation_id' => 0,
    'quantity'     => 1,
    'data'         => wc_get_product( $rental_id ),
    'rmwc_rental'  => [
        'start' => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
        'end'   => gmdate( 'Y-m-d', time() + 8 * DAY_IN_SECONDS ),
    ],
];
$child_a = [
    'product_id'                            => $accessory_a_id,
    'variation_id'                          => 0,
    'quantity'                              => 1,
    'data'                                  => wc_get_product( $accessory_a_id ),
    'rmwc_rental_accessory'                 => 'yes',
    'rmwc_rental_accessory_source_id'       => $accessory_a_id,
    'rmwc_rental_accessory_parent'          => $parent_key,
    'rmwc_rental_accessory_parent_product'  => $rental_id,
    'rmwc_rental_accessory_group'           => 'Required material',
];
$child_b = [
    'product_id'                            => $accessory_b_id,
    'variation_id'                          => 0,
    'quantity'                              => 1,
    'data'                                  => wc_get_product( $accessory_b_id ),
    'rmwc_rental_accessory'                 => 'yes',
    'rmwc_rental_accessory_source_id'       => $accessory_b_id,
    'rmwc_rental_accessory_parent'          => $parent_key,
    'rmwc_rental_accessory_parent_product'  => $rental_id,
    'rmwc_rental_accessory_group'           => 'Required material',
];

WC()->cart->cart_contents = [
    $parent_key  => $parent,
    $child_a_key => $child_a,
    $child_b_key => $child_b,
];

$check( false !== has_action( 'woocommerce_check_cart_items', [ RMWC_Required_Accessories::class, 'validate_cart' ] ), 'Required accessory validation is registered on WooCommerce cart checks' );

wc_clear_notices();
RMWC_Required_Accessories::validate_cart();
$check( 0 === wc_notice_count( 'error' ), 'Required group is valid while at least one linked accessory is present' );

$two_item_link = apply_filters( 'woocommerce_cart_item_remove_link', 'REMOVE', $child_a_key );
$check( 'REMOVE' === $two_item_link, 'One of multiple items in a required group remains removable' );

WC()->cart->remove_cart_item( $child_a_key );
$check( 0 === wc_notice_count( 'error' ), 'Removing one of multiple required-group items keeps the cart valid' );

$last_item_link = apply_filters( 'woocommerce_cart_item_remove_link', 'REMOVE', $child_b_key );
$check( '' === $last_item_link, 'Classic cart hides removal for the last valid item in a required group' );

wc_clear_notices();
WC()->cart->remove_cart_item( $child_b_key );
$check( wc_notice_count( 'error' ) > 0, 'Removing the last required accessory immediately reports an error' );

wc_clear_notices();
RMWC_Required_Accessories::validate_cart();
$check( wc_notice_count( 'error' ) > 0, 'Missing required accessory blocks server-side cart and checkout validation' );

wc_clear_notices();
WC()->cart->cart_contents = [
    $parent_key  => $parent,
    $child_a_key => $child_a,
];
RMWC_Required_Accessories::validate_cart();
$check( 0 === wc_notice_count( 'error' ), 'Restoring a valid required accessory clears the integrity failure' );

WC()->cart->empty_cart( true );
wp_delete_post( $accessory_b_id, true );
wp_delete_post( $accessory_a_id, true );
wp_delete_post( $rental_id, true );
wc_clear_notices();

WP_CLI::success( sprintf( '%d required-accessory regression checks passed.', $checks ) );
