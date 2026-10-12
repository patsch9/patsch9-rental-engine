<?php
/**
 * Resumable migration of the historic rental namespace.
 *
 * @package RMWC
 */
defined( 'ABSPATH' ) || exit;

final class RMWC_Legacy_Migration {
    private const VERSION = 2;
    private const VERSION_OPTION = 'rmwc_legacy_migration_version';
    private const CONFLICT_OPTION = 'rmwc_legacy_migration_conflict';

    private static function legacy_prefix() {
        return 'clr_';
    }

    /** Run under a database lock; never mark a failed/partial run complete. */
    public static function run() {
        global $wpdb;
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
            return true;
        }
        $lock = 'rmwc_migrate_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Database lock serializes schema/data changes across requests.
        $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
        if ( '1' !== (string) $locked ) {
            return self::stop();
        }
        try {
            self::clear_option_cache( self::VERSION_OPTION );
            if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
                return true;
            }
            if ( ! self::migrate_tables() || ! self::migrate_options() || ! self::migrate_meta_tables() || ! self::cleanup_ephemeral_legacy_state() ) {
                return self::stop();
            }
            if ( ! update_option( self::VERSION_OPTION, self::VERSION, false ) ) {
                return self::stop();
            }
            delete_option( self::CONFLICT_OPTION );
            return true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release this connection's migration lock even after failure.
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function stop() {
        update_option( self::CONFLICT_OPTION, 'yes', false );
        add_action( 'admin_notices', [ __CLASS__, 'render_conflict_notice' ] );
        return false;
    }

    /** @return bool|null Null is an error, never evidence of an absent table. */
    private static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh schema state is required for migration decisions.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return '' !== $wpdb->last_error ? null : $table === $found;
    }

    /** @return int|null Null is an error, never an empty table. */
    private static function row_count( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Never cache a destructive migration decision.
        $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
        return '' !== $wpdb->last_error || null === $count || ! ctype_digit( (string) $count ) ? null : (int) $count;
    }

    private static function migrate_tables() {
        global $wpdb;
        foreach ( [ 'bookings', 'terms_versions', 'documents', 'assets', 'asset_assignments' ] as $suffix ) {
            $old = $wpdb->prefix . self::legacy_prefix() . $suffix;
            $new = $wpdb->prefix . 'rmwc_' . $suffix;
            $old_exists = self::table_exists( $old );
            $new_exists = self::table_exists( $new );
            if ( null === $old_exists || null === $new_exists ) {
                return false;
            }
            if ( ! $old_exists ) {
                continue;
            }
            if ( $new_exists ) {
                $old_count = self::row_count( $old );
                $new_count = self::row_count( $new );
                if ( null === $old_count || null === $new_count || ( $old_count > 0 && $new_count > 0 ) ) {
                    return false;
                }
                $empty = 0 === $old_count ? $old : $new;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Both successful fresh counts verified this table is empty, under the migration lock.
                if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $empty ) ) ) {
                    return false;
                }
                if ( $empty === $old ) {
                    continue;
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic rename preserves all existing rows and indexes.
            if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) ) ) {
                return false;
            }
        }
        return true;
    }

    private static function clear_option_cache( $name ) {
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    private static function migrate_options() {
        global $wpdb;
        $prefix = self::legacy_prefix();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Discover the historic settings without trusting stale option caches.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
        if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
            return false;
        }
        foreach ( $rows as $row ) {
            $new = 'rmwc_' . substr( $row['option_name'], strlen( $prefix ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare raw serialized bytes before copying/deleting settings.
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $new ), ARRAY_A );
            if ( '' !== $wpdb->last_error ) {
                return false;
            }
            if ( null !== $existing && $existing['option_value'] !== $row['option_value'] ) {
                return false; // Preserve both differing values for manual resolution.
            }
            if ( null === $existing ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve exact serialized bytes and autoload; invalidate caches below.
                $inserted = $wpdb->insert( $wpdb->options, [ 'option_name' => $new, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ], [ '%s', '%s', '%s' ] );
                self::clear_option_cache( $new );
                if ( false === $inserted ) {
                    return false;
                }
            }
            self::clear_option_cache( $new );
            if ( ! delete_option( $row['option_name'] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function migrate_meta_tables() {
        global $wpdb;
        foreach ( [ [ $wpdb->postmeta, 'post_id', 'post' ], [ $wpdb->prefix . 'woocommerce_order_itemmeta', 'order_item_id', 'order_item' ], [ $wpdb->prefix . 'wc_orders_meta', 'order_id', 'order' ] ] as $spec ) {
            $exists = self::table_exists( $spec[0] );
            if ( null === $exists || ( $exists && ! self::migrate_meta_table( ...$spec ) ) ) {
                return false;
            }
        }
        return true;
    }

    private static function migrate_meta_table( $table, $field, $type ) {
        global $wpdb;
        $old = '_' . self::legacy_prefix();
        $new = '_rmwc_';
        $start = strlen( $old ) + 1;
        $like = $wpdb->esc_like( $old ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Differing duplicate values must never be discarded by a migration.
        $conflicts = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i AS legacy INNER JOIN %i AS current ON current.%i = legacy.%i AND current.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) WHERE legacy.meta_key LIKE %s AND BINARY legacy.meta_value <> BINARY current.meta_value', $table, $table, $field, $field, $new, $start, $like ) );
        if ( '' !== $wpdb->last_error || null === $conflicts || (int) $conflicts > 0 ) {
            return false;
        }
        // Invalidate BEFORE SQL too: if the request dies just after an UPDATE, a
        // previously populated persistent cache must not survive the next retry.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capture affected IDs before their keys change, including previous partial runs.
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT %i FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s', $field, $table, $like, $wpdb->esc_like( $new ) . '%' ) );
        if ( '' !== $wpdb->last_error || ! is_array( $ids ) ) {
            return false;
        }
        self::invalidate_meta_caches( $ids, $type );
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- In-place key rename; no existing canonical value is overwritten.
            $updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i AS legacy LEFT JOIN %i AS current ON current.%i = legacy.%i AND current.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) SET legacy.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) WHERE legacy.meta_key LIKE %s AND current.%i IS NULL', $table, $table, $field, $field, $new, $start, $new, $start, $like, $field ) );
            if ( false === $updated ) {
                return false;
            }
            // Delete only byte-identical duplicates. Differing historical values survive.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verified duplicate cleanup; caches invalidated in finally.
            $deleted = $wpdb->query( $wpdb->prepare( 'DELETE legacy FROM %i AS legacy INNER JOIN %i AS current ON current.%i = legacy.%i AND current.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) WHERE legacy.meta_key LIKE %s AND BINARY legacy.meta_value = BINARY current.meta_value', $table, $table, $field, $field, $new, $start, $like ) );
            return false !== $deleted;
        } finally {
            self::invalidate_meta_caches( $ids, $type );
        }
    }

    private static function invalidate_meta_caches( $ids, $type ) {
        $order_ids = 'order' === $type || 'post' === $type ? array_map( 'intval', $ids ) : [];
        foreach ( $ids as $id ) {
            wp_cache_delete( (int) $id, $type . '_meta' );
            if ( 'order_item' === $type && function_exists( 'wc_get_order_id_by_order_item_id' ) ) {
                $order_ids[] = wc_get_order_id_by_order_item_id( (int) $id );
            }
            if ( 'post' === $type ) {
                clean_post_cache( (int) $id );
            }
            if ( class_exists( 'WC_Cache_Helper' ) ) {
                WC_Cache_Helper::invalidate_cache_group( 'object_' . (int) $id );
                if ( 'post' === $type ) {
                    WC_Cache_Helper::invalidate_cache_group( 'product_' . (int) $id );
                }
            }
        }
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::invalidate_cache_group( 'orders' );
        }
        $order_ids = array_values( array_unique( array_filter( $order_ids ) ) );
        if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' ) && function_exists( 'wc_get_container' ) ) {
            $store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
            if ( is_callable( [ $store, 'clear_cached_data' ] ) ) {
                $store->clear_cached_data( $order_ids );
            }
        }
        if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) && function_exists( 'wc_get_container' ) ) {
            $cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );
            foreach ( $order_ids as $id ) {
                $cache->remove( (int) $id );
            }
        }
    }

    private static function cleanup_ephemeral_legacy_state() {
        global $wpdb;
        $prefix = self::legacy_prefix();
        $cleared = wp_clear_scheduled_hook( $prefix . 'hourly_reminders', [], true );
        if ( is_wp_error( $cleared ) || false === $cleared ) {
            return false;
        }
        // Historic caches are not authoritative and must never become settings.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time deletion of obsolete transients; new keys have a different namespace.
        return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_' . $prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%' ) );
    }

    /** Compatibility belongs to the session boundary, never to public actions. */
    public static function restore_cart_item( $item ) {
        foreach ( array_keys( $item ) as $key ) {
            if ( in_array( $key, array_map( static fn( $suffix ) => self::legacy_prefix() . $suffix, [ 'rental', 'rental_accessory', 'rental_accessory_parent', 'rental_accessory_parent_product', 'rental_accessory_group', 'rental_accessory_label', 'rental_accessory_mode', 'rental_accessory_customer_qty' ] ), true ) ) {
                $new = 'rmwc_' . substr( $key, strlen( self::legacy_prefix() ) );
                if ( ! array_key_exists( $new, $item ) ) {
                    $item[ $new ] = $item[ $key ];
                }
                unset( $item[ $key ] );
            }
        }
        if ( isset( $item['rmwc_rental'] ) && ! is_array( $item['rmwc_rental'] ) ) {
            $item['rmwc_rental'] = [ 'validation_error' => __( 'The rental configuration for this cart item is no longer available. Please remove the item and add it again.', 'patsch9-rental-engine' ) ];
        }
        return $item;
    }

    public static function restore_session( $cart ) {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $old = self::legacy_prefix() . 'v2_deposit_choice';
            $choice = WC()->session->get( $old );
            if ( null !== $choice ) {
                if ( null === WC()->session->get( 'rmwc_v2_deposit_choice' ) && in_array( $choice, [ 'cash', 'online' ], true ) ) {
                    WC()->session->set( 'rmwc_v2_deposit_choice', $choice );
                }
                WC()->session->__unset( $old );
            }
        }
        foreach ( $cart->cart_contents as $key => $item ) {
            $cart->cart_contents[ $key ] = self::restore_cart_item( $item );
        }
    }

    /** On migration failure, block only rental products and affected carts. */
    public static function register_checkout_guards() {
        add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'guard_purchasable' ], PHP_INT_MAX, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'guard_purchasable' ], PHP_INT_MAX, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'guard_add_to_cart' ], PHP_INT_MAX, 5 );
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'guard_cart' ] );
        add_action( 'woocommerce_store_api_cart_errors', [ __CLASS__, 'guard_store_cart' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'guard_store_checkout' ], 1, 2 );
    }

    private static function affected_product( $id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- During failed migration metadata caches may be stale; fail closed on query errors.
        $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d AND meta_key IN (%s, %s, %s, %s) AND meta_value = %s', $wpdb->postmeta, $id, '_' . self::legacy_prefix() . 'rental_enabled', '_rmwc_rental_enabled', '_' . self::legacy_prefix() . 'accessory_only', '_rmwc_accessory_only', 'yes' ) );
        return '' !== $wpdb->last_error || null === $count || (int) $count > 0;
    }

    public static function guard_purchasable( $purchasable, $product ) {
        return self::affected_product( $product->get_id() ) || ( $product->get_parent_id() && self::affected_product( $product->get_parent_id() ) ) ? false : $purchasable;
    }

    private static function checkout_message() {
        return __( 'Rental bookings are temporarily unavailable while rental data is being upgraded. Please try again later or contact the shop.', 'patsch9-rental-engine' );
    }

    public static function guard_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        if ( self::affected_product( $product_id ) || ( $variation_id && self::affected_product( $variation_id ) ) ) {
            wc_add_notice( self::checkout_message(), 'error' );
            return false;
        }
        return $passed;
    }

    private static function affected_cart( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            $item = self::restore_cart_item( $item );
            if ( isset( $item['rmwc_rental'] ) || isset( $item['rmwc_rental_accessory_parent'] ) || self::affected_product( (int) $item['product_id'] ) || ( ! empty( $item['variation_id'] ) && self::affected_product( (int) $item['variation_id'] ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function guard_cart() {
        if ( WC()->cart && self::affected_cart( WC()->cart ) ) {
            wc_add_notice( self::checkout_message(), 'error' );
        }
    }

    public static function guard_store_cart( $errors, $cart ) {
        if ( self::affected_cart( $cart ) ) {
            $errors->add( 'rmwc_migration_pending', self::checkout_message() );
        }
    }

    public static function guard_store_checkout( $order, $request ) {
        if ( WC()->cart && self::affected_cart( WC()->cart ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rmwc_migration_pending', esc_html( self::checkout_message() ), 503 );
        }
    }

    public static function render_conflict_notice() {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Patsch9 Rental Engine migration stopped.', 'patsch9-rental-engine' ) . '</strong> ' . esc_html__( 'Rental data could not be upgraded safely. Existing data has been preserved and rental checkout is blocked. Please back up the database, check the database error log and resolve any conflicting legacy and current data before retrying.', 'patsch9-rental-engine' ) . '</p></div>';
        }
    }
}
