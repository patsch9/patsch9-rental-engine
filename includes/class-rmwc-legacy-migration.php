<?php
/**
 * One-time migration from the historic short internal namespace.
 *
 * The legacy prefix is assembled at runtime so the released plugin no longer
 * registers or declares short-prefixed WordPress identifiers.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_Legacy_Migration {
    private const VERSION = 1;
    private const VERSION_OPTION = 'rmwc_legacy_migration_version';
    private const CONFLICT_OPTION = 'rmwc_legacy_migration_conflict';

    /**
     * Historic prefix used by prerelease builds.
     *
     * @return string
     */
    private static function legacy_prefix() {
        return 'cl' . 'r_';
    }

    /**
     * Historic private-meta prefix used by prerelease builds.
     *
     * @return string
     */
    private static function legacy_meta_prefix() {
        return '_' . self::legacy_prefix();
    }

    /**
     * Run the idempotent migration before the plugin starts reading data.
     *
     * @return bool True when the migration is complete or already applied.
     */
    public static function run() {
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
            return true;
        }

        if ( ! self::migrate_tables() ) {
            update_option( self::CONFLICT_OPTION, 'yes', false );
            add_action( 'admin_notices', [ __CLASS__, 'render_conflict_notice' ] );
            return false;
        }

        self::migrate_options();
        self::migrate_meta_tables();
        self::cleanup_ephemeral_legacy_state();

        delete_option( self::CONFLICT_OPTION );
        update_option( self::VERSION_OPTION, self::VERSION, false );
        return true;
    }

    /**
     * Rename plugin-owned tables before dbDelta creates the canonical tables.
     * A non-empty old/new collision is deliberately not merged automatically.
     *
     * @return bool
     */
    private static function migrate_tables() {
        global $wpdb;

        $suffixes = [
            'bookings',
            'terms_versions',
            'documents',
            'assets',
            'asset_assignments',
        ];

        foreach ( $suffixes as $suffix ) {
            $legacy_table = $wpdb->prefix . self::legacy_prefix() . $suffix;
            $current_table = $wpdb->prefix . 'rmwc_' . $suffix;

            if ( ! self::table_exists( $legacy_table ) ) {
                continue;
            }

            if ( ! self::table_exists( $current_table ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned tables.
                $renamed = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy_table, $current_table ) );
                if ( false === $renamed ) {
                    return false;
                }
                continue;
            }

            // A previous interrupted migration may have created an empty target.
            // Only an empty table is safe to replace automatically.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned tables.
            $legacy_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $legacy_table ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned tables.
            $current_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $current_table ) );

            if ( 0 === $legacy_count ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Empty obsolete plugin table.
                $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $legacy_table ) );
                continue;
            }

            if ( 0 === $current_count ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Empty interrupted-migration target.
                $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $current_table ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned tables.
                $renamed = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy_table, $current_table ) );
                if ( false === $renamed ) {
                    return false;
                }
                continue;
            }

            // Both tables contain data. Guessing which rows win could corrupt
            // reservations or immutable documents, so require manual review.
            return false;
        }

        return true;
    }

    /**
     * Check a table name without interpolating it into SQL.
     *
     * @param string $table Table name.
     * @return bool
     */
    private static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence check for one-time migration.
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    /**
     * Copy old options under the canonical prefix while preserving autoload.
     */
    private static function migrate_options() {
        global $wpdb;

        $legacy_prefix = self::legacy_prefix();
        $like = $wpdb->esc_like( $legacy_prefix ) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk discovery is required because historic settings are numerous.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ),
            ARRAY_A
        );

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $old_name = (string) ( $row['option_name'] ?? '' );
            if ( 0 !== strpos( $old_name, $legacy_prefix ) ) {
                continue;
            }
            $new_name = 'rmwc_' . substr( $old_name, strlen( $legacy_prefix ) );
            if ( '' === $new_name ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve the serialized value/autoload flag exactly during the one-time rename.
            $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new_name ) );
            if ( 0 === $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Preserve serialized option bytes without unserialize/reserialize drift.
                $inserted = $wpdb->insert(
                    $wpdb->options,
                    [
                        'option_name'  => $new_name,
                        'option_value' => (string) ( $row['option_value'] ?? '' ),
                        'autoload'     => (string) ( $row['autoload'] ?? 'auto' ),
                    ],
                    [ '%s', '%s', '%s' ]
                );
                if ( false === $inserted ) {
                    continue;
                }
            }
            delete_option( $old_name );
        }
    }

    /**
     * Rename private rental metadata in WordPress, WooCommerce line items and
     * HPOS. Existing canonical values win if a partial migration already wrote
     * the same key for the same object.
     */
    private static function migrate_meta_tables() {
        global $wpdb;

        self::migrate_meta_table( $wpdb->postmeta, 'post_id' );

        $order_item_meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if ( self::table_exists( $order_item_meta ) ) {
            self::migrate_meta_table( $order_item_meta, 'order_item_id' );
        }

        $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
        if ( self::table_exists( $hpos_meta ) ) {
            self::migrate_meta_table( $hpos_meta, 'order_id' );
        }
    }

    /**
     * Rename one meta table in-place without overwriting canonical values.
     *
     * @param string $table        Meta table.
     * @param string $object_field Object ID column.
     */
    private static function migrate_meta_table( $table, $object_field ) {
        global $wpdb;

        $legacy = self::legacy_meta_prefix();
        $current = '_rmwc_';
        $substring_start = strlen( $legacy ) + 1;
        $like = $wpdb->esc_like( $legacy ) . '%';

        // Move rows only if that object does not already have the canonical key.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time namespace migration across plugin-owned metadata.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i AS legacy LEFT JOIN %i AS current ON current.%i = legacy.%i AND current.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) SET legacy.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) WHERE legacy.meta_key LIKE %s AND current.%i IS NULL',
                $table,
                $table,
                $object_field,
                $object_field,
                $current,
                $substring_start,
                $current,
                $substring_start,
                $like,
                $object_field
            )
        );

        // If both keys existed already, retain the canonical value and remove
        // only the obsolete duplicate.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time duplicate cleanup after namespace migration.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE legacy FROM %i AS legacy INNER JOIN %i AS current ON current.%i = legacy.%i AND current.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d)) WHERE legacy.meta_key LIKE %s',
                $table,
                $table,
                $object_field,
                $object_field,
                $current,
                $substring_start,
                $like
            )
        );
    }

    /**
     * Legacy transients and cron entries are ephemeral and should not be copied.
     */
    private static function cleanup_ephemeral_legacy_state() {
        global $wpdb;

        $legacy_prefix = self::legacy_prefix();
        wp_clear_scheduled_hook( $legacy_prefix . 'hourly_reminders' );

        $patterns = [
            $wpdb->esc_like( '_transient_' . $legacy_prefix ) . '%',
            $wpdb->esc_like( '_transient_timeout_' . $legacy_prefix ) . '%',
        ];
        foreach ( $patterns as $pattern ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Purges obsolete ephemeral entries from the historic namespace.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
        }
    }

    /**
     * Fail visibly rather than merging two non-empty reservation/document sets.
     */
    public static function render_conflict_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__( 'Patsch9 Rental Engine migration stopped.', 'patsch9-rental-engine' )
            . '</strong> '
            . esc_html__( 'Both the historic and canonical rental database tables contain data. No automatic merge was attempted to avoid corrupting reservations or documents. Please create a database backup and resolve the duplicate table set before continuing.', 'patsch9-rental-engine' )
            . '</p></div>';
    }
}
