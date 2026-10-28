<?php

defined( 'ABSPATH' ) || exit;

/**
 * Adjusts WooCommerce catalog buttons for products that require rental data.
 *
 * Rental products cannot be added safely from a product loop because the
 * rental period and related configuration are collected on the product page.
 */
final class RMWC_Catalog_Button {
    /**
     * Register catalog-related WooCommerce filters.
     */
    public static function init() {
        add_filter( 'woocommerce_product_add_to_cart_url', [ __CLASS__, 'add_to_cart_url' ], 20, 2 );
        add_filter( 'woocommerce_product_add_to_cart_text', [ __CLASS__, 'add_to_cart_text' ], 20, 2 );
        add_filter( 'woocommerce_product_add_to_cart_description', [ __CLASS__, 'add_to_cart_description' ], 20, 2 );
        add_filter( 'woocommerce_product_supports', [ __CLASS__, 'product_supports' ], 20, 3 );
        add_filter( 'woocommerce_loop_add_to_cart_args', [ __CLASS__, 'loop_add_to_cart_args' ], 20, 2 );
    }

    /**
     * Determine whether a WooCommerce product uses rental configuration.
     *
     * Variations inherit the rental configuration of their parent unless the
     * variation itself is explicitly configured as a rental item.
     *
     * @param WC_Product $product Product instance.
     * @return bool
     */
    private static function is_rental_product( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }

        $product_id = $product->get_id();
        if ( $product_id && 'yes' === get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            return true;
        }

        $parent_id = $product->get_parent_id();
        return $parent_id && 'yes' === get_post_meta( $parent_id, '_rmwc_rental_enabled', true );
    }

    /**
     * Send rental catalog buttons to the product page instead of an add-to-cart URL.
     *
     * @param string     $url     Existing add-to-cart URL.
     * @param WC_Product $product Product instance.
     * @return string
     */
    public static function add_to_cart_url( $url, $product ) {
        if ( ! self::is_rental_product( $product ) ) {
            return $url;
        }

        return $product->get_permalink();
    }

    /**
     * Use a neutral selection CTA for rental products in product loops.
     *
     * The existing translated "Select" string is reused so the bundled German
     * catalog remains complete and no untranslated UI string is introduced.
     *
     * @param string     $text    Existing button text.
     * @param WC_Product $product Product instance.
     * @return string
     */
    public static function add_to_cart_text( $text, $product ) {
        if ( ! self::is_rental_product( $product ) ) {
            return $text;
        }

        /**
         * Filters the catalog CTA used for rental products.
         *
         * @param string     $text    Rental catalog button text.
         * @param WC_Product $product Product instance.
         */
        return (string) apply_filters(
            'rmwc_rental_catalog_button_text',
            __( 'Select', 'patsch9-rental-engine' ),
            $product
        );
    }

    /**
     * Keep the accessible label consistent with the changed catalog action.
     *
     * @param string     $description Existing button description.
     * @param WC_Product $product     Product instance.
     * @return string
     */
    public static function add_to_cart_description( $description, $product ) {
        if ( ! self::is_rental_product( $product ) ) {
            return $description;
        }

        return sprintf(
            '%1$s: %2$s',
            __( 'Select', 'patsch9-rental-engine' ),
            wp_strip_all_tags( $product->get_name() )
        );
    }

    /**
     * Rental products must never use WooCommerce loop AJAX add-to-cart.
     *
     * @param bool       $supports Whether the product supports the feature.
     * @param string     $feature  Feature name.
     * @param WC_Product $product  Product instance.
     * @return bool
     */
    public static function product_supports( $supports, $feature, $product ) {
        if ( 'ajax_add_to_cart' === $feature && self::is_rental_product( $product ) ) {
            return false;
        }

        return $supports;
    }

    /**
     * Defensively remove an AJAX class added by themes or other integrations.
     *
     * WooCommerce core already omits the class when ajax_add_to_cart support is
     * disabled. Keeping this filter makes the behavior robust when a theme has
     * modified the loop arguments before this plugin runs.
     *
     * @param array      $args    Loop button arguments.
     * @param WC_Product $product Product instance.
     * @return array
     */
    public static function loop_add_to_cart_args( $args, $product ) {
        if ( ! self::is_rental_product( $product ) || empty( $args['class'] ) ) {
            return $args;
        }

        $classes = preg_split( '/\s+/', trim( (string) $args['class'] ) );
        $classes = array_filter(
            $classes,
            static function( $class_name ) {
                return 'ajax_add_to_cart' !== $class_name;
            }
        );
        $args['class'] = implode( ' ', $classes );

        if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
            $args['attributes'] = [];
        }
        $args['attributes']['aria-label'] = self::add_to_cart_description( '', $product );

        return $args;
    }
}
