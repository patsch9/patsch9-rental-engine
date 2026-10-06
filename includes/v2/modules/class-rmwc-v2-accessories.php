<?php
/**
 * Hidden rental-only accessory products.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Accessories {
    private static $instance = null;
    private $internal_add = false;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'product_type_options', [ $this, 'product_type_option' ], 30 );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_option' ], 60 );
        add_filter( 'woocommerce_product_is_visible', [ $this, 'hide_from_catalog' ], 30, 2 );
        add_filter( 'woocommerce_product_query_meta_query', [ $this, 'exclude_from_product_queries' ], 30, 2 );
        add_filter( 'woocommerce_shortcode_products_query', [ $this, 'exclude_from_shortcode_queries' ], 30, 3 );
        add_filter( 'woocommerce_rest_product_object_query', [ $this, 'exclude_from_rest_queries' ], 30, 2 );
        add_action( 'pre_get_posts', [ $this, 'exclude_from_public_wp_queries' ], 30 );
        add_filter( 'woocommerce_is_purchasable', [ $this, 'block_direct_purchase' ], 30, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', [ $this, 'block_direct_purchase' ], 30, 2 );
        add_filter( 'woocommerce_cart_item_is_purchasable', [ $this, 'keep_linked_cart_items_purchasable' ], 30, 4 );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_direct_add_to_cart' ], 5, 6 );
        add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'block_store_api_direct_add_to_cart' ], 5, 2 );
        add_action( 'template_redirect', [ $this, 'block_public_single_view' ], 1 );
    }

    public function product_type_option( $options ) {
        $options['clr_accessory_only'] = [
            'id'            => '_clr_accessory_only',
            'wrapper_class' => 'show_if_simple show_if_variable',
            'label'         => __( 'Miet-Zubehör', 'patsch9-rental-engine' ),
            'description'   => __( 'Dieses Produkt ausschließlich als Zubehör eines Mietartikels verwenden und nicht einzeln im Shop anbieten.', 'patsch9-rental-engine' ),
            'default'       => 'no',
        ];
        return $options;
    }

    public function save_product_option( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        // WooCommerce product-type options are saved in the same product form;
        // use our existing product nonce when available, otherwise do nothing.
        $nonce = isset( $_POST['clr_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_product_nonce'] ) ) : '';
        if ( $nonce && wp_verify_nonce( $nonce, 'clr_save_product_' . $post_id ) ) {
            $product   = wc_get_product( $post_id );
            $accessory = isset( $_POST['_clr_accessory_only'] ) ? 'yes' : 'no';
            // Variable products are supported as hidden accessory containers. A concrete
            // variation must later be selected in the rental-product configuration; the
            // variable parent itself is never added to the cart.
            if ( ! $product || ! ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) ) {
                $accessory = 'no';
            }
            if ( 'yes' === get_post_meta( $post_id, '_clr_rental_enabled', true ) && 'yes' === $accessory ) {
                $accessory = 'no';
            }
            update_post_meta( $post_id, '_clr_accessory_only', $accessory );
        }
    }

    /**
     * Rental-only accessory products are implementation details of a rental
     * configuration. Customers must not browse or purchase them standalone.
     * Editors can still preview the product while configuring the shop.
     */
    public function block_public_single_view() {
        if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        $product_id = get_queried_object_id();
        if ( ! $product_id || 'yes' !== get_post_meta( $product_id, '_clr_accessory_only', true ) ) {
            return;
        }
        if ( current_user_can( 'edit_post', $product_id ) ) {
            return;
        }

        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
    }

    public function hide_from_catalog( $visible, $product_id ) {
        $product_id = absint( $product_id );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        $config_id  = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product_id;
        if ( $config_id && 'yes' === get_post_meta( $config_id, '_clr_accessory_only', true ) ) {
            return false;
        }
        return $visible;
    }

    private function public_exclusion_meta_query( $meta_query ) {
        $meta_query = is_array( $meta_query ) ? $meta_query : [];
        $meta_query[] = [
            'relation' => 'OR',
            [
                'key'     => '_clr_accessory_only',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_clr_accessory_only',
                'value'   => 'yes',
                'compare' => '!=',
            ],
        ];
        return $meta_query;
    }

    public function exclude_from_product_queries( $meta_query, $query = null ) {
        unset( $query );
        if ( is_admin() ) {
            return $meta_query;
        }
        return $this->public_exclusion_meta_query( $meta_query );
    }

    public function exclude_from_shortcode_queries( $query_args, $attributes = [], $type = '' ) {
        unset( $attributes, $type );
        if ( ! is_array( $query_args ) ) {
            return $query_args;
        }
        $query_args['meta_query'] = $this->public_exclusion_meta_query( $query_args['meta_query'] ?? [] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small catalog; required to exclude implementation-only products consistently.
        return $query_args;
    }

    public function exclude_from_rest_queries( $args, $request = null ) {
        unset( $request );
        // Keep authenticated wp-admin product searches intact. Public Store API
        // and product collection requests must not expose rental-only products.
        if ( current_user_can( 'edit_products' ) && is_admin() ) {
            return $args;
        }
        $args['meta_query'] = $this->public_exclusion_meta_query( $args['meta_query'] ?? [] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small catalog; required to exclude implementation-only products consistently.
        return $args;
    }

    public function exclude_from_public_wp_queries( $query ) {
        if ( is_admin() || ! $query instanceof WP_Query ) {
            return;
        }
        $post_type = $query->get( 'post_type' );
        $is_product_query = 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) );
        if ( ! $is_product_query ) {
            return;
        }
        $query->set( 'meta_query', $this->public_exclusion_meta_query( $query->get( 'meta_query' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small catalog; required to hide rental-only products from block/product queries.
    }



    private function accessory_config_id( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return 0;
        }
        $product_id = $product->get_id();
        if ( $product->is_type( 'variation' ) && 'yes' !== get_post_meta( $product_id, '_clr_accessory_only', true ) ) {
            $product_id = $product->get_parent_id();
        }
        return absint( $product_id );
    }

    private function is_rental_accessory_product( $product ) {
        $product_id = $this->accessory_config_id( $product );
        return $product_id && 'yes' === get_post_meta( $product_id, '_clr_accessory_only', true );
    }

    private function is_valid_linked_accessory_in_current_cart( $product ) {
        if ( ! $this->is_rental_accessory_product( $product ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        $source_id = absint( $product->get_id() );
        $cart      = WC()->cart->get_cart();
        foreach ( $cart as $cart_key => $item ) {
            if ( 'yes' !== ( $item['clr_rental_accessory'] ?? '' ) ) {
                continue;
            }
            $item_source_id = absint( $item['clr_rental_accessory_source_id'] ?? 0 );
            $item_product   = $item['data'] ?? null;
            if ( ! $item_source_id && $item_product instanceof WC_Product ) {
                $item_source_id = absint( $item_product->get_id() );
            }
            if ( $source_id !== $item_source_id ) {
                continue;
            }

            $parent_key = (string) ( $item['clr_rental_accessory_parent'] ?? '' );
            if ( '' === $parent_key || ! isset( $cart[ $parent_key ] ) ) {
                continue;
            }
            $parent = $cart[ $parent_key ];
            if ( empty( $parent['clr_rental'] ) || ! is_array( $parent['clr_rental'] ) ) {
                continue;
            }
            return true;
        }
        return false;
    }

    private function product_from_add_to_cart_ids( $product_id, $variation_id = 0 ) {
        $lookup_id = absint( $variation_id ) ?: absint( $product_id );
        return $lookup_id ? wc_get_product( $lookup_id ) : false;
    }

    public function block_direct_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        unset( $quantity, $variations, $cart_item_data );
        if ( $this->internal_add ) {
            return $passed;
        }
        $product = $this->product_from_add_to_cart_ids( $product_id, $variation_id );
        if ( $product && $this->is_rental_accessory_product( $product ) ) {
            wc_add_notice( __( 'Dieses Miet-Zubehör kann nur zusammen mit dem zugehörigen Mietartikel ausgewählt werden.', 'patsch9-rental-engine' ), 'error' );
            return false;
        }
        return $passed;
    }

    public function block_store_api_direct_add_to_cart( $product, $request ) {
        unset( $request );
        if ( $this->internal_add || ! $product instanceof WC_Product || ! $this->is_rental_accessory_product( $product ) ) {
            return;
        }
        throw new Exception( esc_html__( 'Dieses Miet-Zubehör kann nur zusammen mit dem zugehörigen Mietartikel ausgewählt werden.', 'patsch9-rental-engine' ) );
    }

    public function keep_linked_cart_items_purchasable( $purchasable, $cart_item_key, $values, $product ) {
        unset( $cart_item_key );
        if ( $purchasable ) {
            return true;
        }
        if ( ! is_array( $values ) || 'yes' !== ( $values['clr_rental_accessory'] ?? '' ) ) {
            return $purchasable;
        }
        if ( ! $product instanceof WC_Product ) {
            return $purchasable;
        }

        $product_id = $product->get_id();
        if ( $product->is_type( 'variation' ) && 'yes' !== get_post_meta( $product_id, '_clr_accessory_only', true ) ) {
            $product_id = $product->get_parent_id();
        }
        if ( ! $product_id || 'yes' !== get_post_meta( $product_id, '_clr_accessory_only', true ) ) {
            return $purchasable;
        }
        if ( 'publish' !== get_post_status( $product_id ) ) {
            return false;
        }
        if ( ! $product->is_in_stock() ) {
            return false;
        }
        if ( $product->managing_stock() ) {
            $required_qty = max( 1, absint( $values['quantity'] ?? 1 ) );
            if ( ! $product->has_enough_stock( $required_qty ) ) {
                return false;
            }
        }
        return true;
    }
    public function block_direct_purchase( $purchasable, $product ) {
        if ( ! $product instanceof WC_Product || ! $this->is_rental_accessory_product( $product ) ) {
            return $purchasable;
        }

        if ( $this->internal_add ) {
            return true;
        }

        // The Cart/Checkout Blocks validate existing cart lines through
        // $product->is_purchasable() directly and do not use the classic
        // woocommerce_cart_item_is_purchasable filter. Allow the product only
        // when it is already present as a properly linked rental-accessory line.
        // Separate add-to-cart guards above still reject direct purchases.
        if ( $this->is_valid_linked_accessory_in_current_cart( $product ) ) {
            return true;
        }

        return false;
    }

    /**
     * Secure internal API for future material/package modules.
     * Direct customer requests never set the runtime guard.
     */
    public function add_to_cart_for_rental( $product_id, $quantity, array $cart_item_data ) {
        $product = wc_get_product( absint( $product_id ) );
        if ( ! WC()->cart || ! $product ) {
            return false;
        }

        $variation_id   = 0;
        $variation      = [];
        $cart_product_id = $product->get_id();

        if ( $product instanceof WC_Product_Variation ) {
            $parent_id = $product->get_parent_id();
            if ( ! $parent_id || 'yes' !== get_post_meta( $parent_id, '_clr_accessory_only', true ) ) {
                return false;
            }
            $variation_id    = $product->get_id();
            $variation       = $product->get_variation_attributes();
            $cart_product_id = $parent_id;
        } elseif ( $product->is_type( 'simple' ) ) {
            if ( 'yes' !== get_post_meta( $product->get_id(), '_clr_accessory_only', true ) ) {
                return false;
            }
        } else {
            // A variable parent is only a container. The rental configuration must
            // point to one concrete variation, never to an arbitrary/default child.
            return false;
        }

        if ( 'publish' !== get_post_status( $cart_product_id ) || ! $product->is_in_stock() ) {
            return false;
        }

        $quantity = max( 1, min( 10000, absint( $quantity ) ) );
        if ( $product->managing_stock() && ! $product->has_enough_stock( $quantity ) ) {
            return false;
        }

        $cart_item_data['clr_rental_accessory']           = 'yes';
        $cart_item_data['clr_rental_accessory_source_id'] = $product->get_id();
        $this->internal_add = true;
        try {
            return WC()->cart->add_to_cart( $cart_product_id, $quantity, $variation_id, $variation, $cart_item_data );
        } finally {
            $this->internal_add = false;
        }
    }
}
