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

        $product_id   = $product->get_id();
        $variation_id = $product->is_type( 'variation' ) ? $product_id : 0;
        if ( RMWC_Product_Context::rental_config_id( $product_id, $variation_id ) ) {
            return false;
        }
        if ( RMWC_Product_Context::accessory_config_id( $product_id ) ) {
            return false;
        }

        return $needs_shipping;
    }
}
