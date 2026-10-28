<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps mandatory rental-accessory groups valid after a rental was added.
 *
 * The product form already validates required accessory groups before adding a
 * rental. This class closes the later cart lifecycle gap: customers must not be
 * able to remove the last valid item of a required group and then check out.
 */
final class RMWC_Required_Accessories {
    /**
     * Register cart integrity hooks.
     */
    public static function init() {
        add_filter( 'woocommerce_cart_item_remove_link', [ __CLASS__, 'cart_item_remove_link' ], 40, 2 );
        add_action( 'woocommerce_cart_item_removed', [ __CLASS__, 'cart_item_removed' ], 40, 2 );
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'validate_cart' ], 45 );
    }

    /**
     * Determine whether an accessory product is valid for rental configuration.
     *
     * @param WC_Product $product Product instance.
     * @return bool
     */
    private static function accessory_is_allowed( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }

        if ( $product instanceof WC_Product_Variation ) {
            return 'yes' === get_post_meta( $product->get_parent_id(), '_rmwc_accessory_only', true );
        }

        return $product->is_type( 'simple' ) && 'yes' === get_post_meta( $product->get_id(), '_rmwc_accessory_only', true );
    }

    /**
     * Return configured required groups and every valid option belonging to them.
     *
     * A group's required state is group-wide. If one stored row marks the group
     * required, any configured option from that group may satisfy it.
     *
     * @param int $product_id Rental product ID.
     * @return array<string,array{label:string,options:array<int,bool>}>
     */
    private static function required_groups( $product_id ) {
        $rows = get_post_meta( absint( $product_id ), '_rmwc_v2_accessory_options', true );
        if ( ! is_array( $rows ) ) {
            return [];
        }

        $groups = [];
        foreach ( array_slice( $rows, 0, 300 ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $group_label = sanitize_text_field( $row['group'] ?? '' );
            $option_id   = absint( $row['product_id'] ?? 0 );
            $option      = $option_id ? wc_get_product( $option_id ) : false;
            if ( '' === $group_label || ! $option_id || ! $option || ! self::accessory_is_allowed( $option ) ) {
                continue;
            }

            $group_key = sanitize_title( $group_label );
            if ( '' === $group_key ) {
                continue;
            }
            if ( ! isset( $groups[ $group_key ] ) ) {
                $groups[ $group_key ] = [
                    'label'    => $group_label,
                    'required' => false,
                    'options'  => [],
                ];
            }

            $groups[ $group_key ]['required'] = $groups[ $group_key ]['required'] || ! empty( $row['required'] );
            $groups[ $group_key ]['options'][ $option_id ] = true;
        }

        foreach ( $groups as $group_key => $group ) {
            if ( empty( $group['required'] ) ) {
                unset( $groups[ $group_key ] );
                continue;
            }
            unset( $groups[ $group_key ]['required'] );
        }

        return $groups;
    }

    /**
     * Resolve the rental configuration product represented by a cart line.
     *
     * @param array $item Cart item.
     * @return int
     */
    private static function rental_product_id( array $item ) {
        $variation_id = absint( $item['variation_id'] ?? 0 );
        if ( $variation_id && 'yes' === get_post_meta( $variation_id, '_rmwc_rental_enabled', true ) ) {
            return $variation_id;
        }

        return absint( $item['product_id'] ?? 0 );
    }

    /**
     * Resolve the concrete accessory product/variation represented by a cart line.
     *
     * @param array $item Cart item.
     * @return int
     */
    private static function accessory_source_id( array $item ) {
        $source_id = absint( $item['rmwc_rental_accessory_source_id'] ?? 0 );
        if ( $source_id ) {
            return $source_id;
        }

        $variation_id = absint( $item['variation_id'] ?? 0 );
        return $variation_id ?: absint( $item['product_id'] ?? 0 );
    }

    /**
     * Check whether a required group still has at least one valid linked line.
     *
     * @param array  $cart       Current cart contents.
     * @param string $parent_key Parent rental cart key.
     * @param string $group_key  Sanitized group key.
     * @param array  $group      Required group configuration.
     * @return bool
     */
    private static function group_is_satisfied( array $cart, $parent_key, $group_key, array $group ) {
        foreach ( $cart as $item ) {
            if ( 'yes' !== ( $item['rmwc_rental_accessory'] ?? '' ) ) {
                continue;
            }
            if ( $parent_key !== (string) ( $item['rmwc_rental_accessory_parent'] ?? '' ) ) {
                continue;
            }
            if ( $group_key !== sanitize_title( (string) ( $item['rmwc_rental_accessory_group'] ?? '' ) ) ) {
                continue;
            }

            $source_id = self::accessory_source_id( $item );
            if ( $source_id && isset( $group['options'][ $source_id ] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count valid linked items for a required group.
     *
     * @param array  $cart       Current cart contents.
     * @param string $parent_key Parent rental cart key.
     * @param string $group_key  Sanitized group key.
     * @param array  $group      Required group configuration.
     * @return int
     */
    private static function group_item_count( array $cart, $parent_key, $group_key, array $group ) {
        $count = 0;
        foreach ( $cart as $item ) {
            if ( 'yes' !== ( $item['rmwc_rental_accessory'] ?? '' ) ) {
                continue;
            }
            if ( $parent_key !== (string) ( $item['rmwc_rental_accessory_parent'] ?? '' ) ) {
                continue;
            }
            if ( $group_key !== sanitize_title( (string) ( $item['rmwc_rental_accessory_group'] ?? '' ) ) ) {
                continue;
            }

            $source_id = self::accessory_source_id( $item );
            if ( $source_id && isset( $group['options'][ $source_id ] ) ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Resolve a required-group context for a linked accessory line.
     *
     * @param array $item Cart item.
     * @param array $cart Current cart contents.
     * @return array|false
     */
    private static function required_context_for_accessory( array $item, array $cart ) {
        if ( 'yes' !== ( $item['rmwc_rental_accessory'] ?? '' ) ) {
            return false;
        }

        $parent_key = (string) ( $item['rmwc_rental_accessory_parent'] ?? '' );
        if ( '' === $parent_key || ! isset( $cart[ $parent_key ] ) || ! is_array( $cart[ $parent_key ] ) ) {
            return false;
        }

        $product_id = self::rental_product_id( $cart[ $parent_key ] );
        $group_key  = sanitize_title( (string) ( $item['rmwc_rental_accessory_group'] ?? '' ) );
        if ( ! $product_id || '' === $group_key ) {
            return false;
        }

        $groups = self::required_groups( $product_id );
        if ( ! isset( $groups[ $group_key ] ) ) {
            return false;
        }

        $source_id = self::accessory_source_id( $item );
        if ( ! $source_id || ! isset( $groups[ $group_key ]['options'][ $source_id ] ) ) {
            return false;
        }

        return [
            'parent_key' => $parent_key,
            'group_key'  => $group_key,
            'group'      => $groups[ $group_key ],
        ];
    }

    /**
     * Hide the classic-cart remove action only for the last valid item in a
     * required group. Multiple selected items remain individually removable.
     *
     * @param string $link          Existing remove link HTML.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public static function cart_item_remove_link( $link, $cart_item_key ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $link;
        }

        $cart = WC()->cart->get_cart();
        if ( ! isset( $cart[ $cart_item_key ] ) || ! is_array( $cart[ $cart_item_key ] ) ) {
            return $link;
        }

        $context = self::required_context_for_accessory( $cart[ $cart_item_key ], $cart );
        if ( false === $context ) {
            return $link;
        }

        $count = self::group_item_count( $cart, $context['parent_key'], $context['group_key'], $context['group'] );
        return $count <= 1 ? '' : $link;
    }

    /**
     * Report an immediately invalidated required group after a cart-line removal.
     *
     * This is primarily user feedback. The authoritative checkout guard remains
     * validate_cart(), so Store API or crafted requests cannot bypass the rule.
     *
     * @param string  $removed_key Removed cart item key.
     * @param WC_Cart $cart        Cart instance.
     */
    public static function cart_item_removed( $removed_key, $cart ) {
        if ( ! $cart instanceof WC_Cart || empty( $cart->removed_cart_contents[ $removed_key ] ) || ! is_array( $cart->removed_cart_contents[ $removed_key ] ) ) {
            return;
        }

        $removed = $cart->removed_cart_contents[ $removed_key ];
        if ( 'yes' !== ( $removed['rmwc_rental_accessory'] ?? '' ) ) {
            return;
        }

        $current = $cart->get_cart();
        $context = self::required_context_for_accessory( $removed, $current );
        if ( false === $context ) {
            return;
        }
        if ( self::group_is_satisfied( $current, $context['parent_key'], $context['group_key'], $context['group'] ) ) {
            return;
        }

        wc_add_notice(
            sprintf(
                /* translators: %s: accessory option group label. */
                __( 'Please select at least one option for "%s".', 'patsch9-rental-engine' ),
                $context['group']['label']
            ),
            'error'
        );
    }

    /**
     * Block cart and checkout whenever a rental no longer satisfies a required
     * accessory group. WooCommerce runs this hook in classic and Store API cart
     * validation, so the rule is enforced independently of the frontend UI.
     */
    public static function validate_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $cart = WC()->cart->get_cart();
        foreach ( $cart as $parent_key => $item ) {
            if ( empty( $item['rmwc_rental'] ) || ! is_array( $item['rmwc_rental'] ) ) {
                continue;
            }

            $product_id = self::rental_product_id( $item );
            if ( ! $product_id ) {
                continue;
            }

            foreach ( self::required_groups( $product_id ) as $group_key => $group ) {
                if ( self::group_is_satisfied( $cart, (string) $parent_key, $group_key, $group ) ) {
                    continue;
                }

                wc_add_notice(
                    sprintf(
                        /* translators: %s: accessory option group label. */
                        __( 'Please select at least one option for "%s".', 'patsch9-rental-engine' ),
                        $group['label']
                    ),
                    'error'
                );
            }
        }
    }
}
