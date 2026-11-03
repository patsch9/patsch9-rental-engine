<?php
/**
 * Resolve canonical WooCommerce product IDs for rental configuration.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_Product_Context {
    private static function variation_parent_id( $product_id ) {
        $product = $product_id ? wc_get_product( absint( $product_id ) ) : false;
        if ( ! $product instanceof WC_Product || ! $product->is_type( 'variation' ) ) {
            return 0;
        }
        return absint( $product->get_parent_id() );
    }

    public static function rental_config_id( $product_id, $variation_id = 0 ) {
        $product_id   = absint( $product_id );
        $variation_id = absint( $variation_id );

        if ( $variation_id && 'yes' === get_post_meta( $variation_id, '_rmwc_rental_enabled', true ) ) {
            return $variation_id;
        }
        if ( $product_id && 'yes' === get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            return $product_id;
        }

        $candidate_id = $variation_id ?: $product_id;
        $parent_id    = self::variation_parent_id( $candidate_id );
        if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_rmwc_rental_enabled', true ) ) {
            return $parent_id;
        }

        return 0;
    }

    public static function accessory_config_id( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return 0;
        }
        if ( 'yes' === get_post_meta( $product_id, '_rmwc_accessory_only', true ) ) {
            return $product_id;
        }

        $parent_id = self::variation_parent_id( $product_id );
        if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_rmwc_accessory_only', true ) ) {
            return $parent_id;
        }

        return 0;
    }
}
