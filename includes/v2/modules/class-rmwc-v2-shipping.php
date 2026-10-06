<?php
/**
 * WooCommerce shipping integration.
 *
 * Rental fulfilment is selected inside the rental workflow. A rental product
 * therefore behaves like a virtual product for WooCommerce shipping only,
 * without changing the actual WooCommerce "virtual" product setting.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Shipping {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'woocommerce_product_needs_shipping', [ $this, 'product_needs_shipping' ], 20, 2 );
    }

    public function product_needs_shipping( $needs_shipping, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $needs_shipping;
        }

        $product_id = $product->get_id();
        if ( $product->is_type( 'variation' ) ) {
            $parent_id = $product->get_parent_id();
            if ( 'yes' === get_post_meta( $parent_id, '_clr_rental_enabled', true ) ) {
                return false;
            }
        }

        if ( 'yes' === get_post_meta( $product_id, '_clr_rental_enabled', true ) ) {
            return false;
        }

        if ( 'yes' === get_post_meta( $product_id, '_clr_accessory_only', true ) ) {
            return false;
        }

        return $needs_shipping;
    }
}
