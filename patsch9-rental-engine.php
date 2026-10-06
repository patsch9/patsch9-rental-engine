<?php
/**
 * Plugin Name: Patsch9 Rental Engine for WooCommerce
 * Plugin URI: https://github.com/patsch9/patsch9-rental-engine
 * Description: Vermietungs- und Gerätebuchungen für WooCommerce mit Verfügbarkeit, Preisen, Kautionen, Bedingungen, Dokumenten und Übergabe-/Rückgabe-Workflow.
 * Version: 2026.10.0
 * Author: Patrick Schmidt
 * Author URI: https://github.com/patsch9
 * Text Domain: patsch9-rental-engine
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * Requires at least: 6.9.5
 * Requires PHP: 8.2
 * WC requires at least: 10.9.4
 * WC tested up to: 10.9.4
 */

defined( 'ABSPATH' ) || exit;

define( 'RMWC_VERSION', '2026.10.0' );
define( 'RMWC_DB_VERSION', '2.0.26' );
define( 'RMWC_FILE', __FILE__ );
define( 'RMWC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMWC_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        // V2 supports both the classic checkout and Cart/Checkout blocks.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

function rmwc_install_database() {
    global $wpdb;

    $table   = $wpdb->prefix . 'clr_bookings';
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        order_id bigint(20) unsigned NOT NULL DEFAULT 0,
        order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source varchar(20) NOT NULL DEFAULT 'order',
        start_date date NULL,
        end_date date NULL,
        start_at datetime NULL,
        end_at datetime NULL,
        quantity int(11) unsigned NOT NULL DEFAULT 1,
        customer_name varchar(190) NOT NULL DEFAULT '',
        customer_email varchar(190) NOT NULL DEFAULT '',
        note text NULL,
        status varchar(20) NOT NULL DEFAULT 'reserved',
        workflow_status varchar(30) NOT NULL DEFAULT 'reserved',
        handover_at datetime NULL,
        return_at datetime NULL,
        reminder_sent_at datetime NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY product_times (product_id, start_at, end_at),
        KEY order_id (order_id),
        KEY status (status),
        KEY reminder (status, reminder_sent_at, start_at),
        KEY customer_email (customer_email(100))
    ) {$charset};";

    dbDelta( $sql );

    $terms_table = $wpdb->prefix . 'clr_terms_versions';
    $terms_sql = "CREATE TABLE {$terms_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        version varchar(50) NOT NULL,
        content longtext NOT NULL,
        content_hash char(64) NOT NULL,
        active tinyint(1) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY version (version),
        KEY active (active),
        KEY created_at (created_at)
    ) {$charset};";
    dbDelta( $terms_sql );

    $documents_table = $wpdb->prefix . 'clr_documents';
    $documents_sql = "CREATE TABLE {$documents_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        order_id bigint(20) unsigned NOT NULL,
        type varchar(40) NOT NULL,
        document_number varchar(80) NOT NULL DEFAULT '',
        amount decimal(18,4) NOT NULL DEFAULT 0,
        currency char(3) NOT NULL DEFAULT 'EUR',
        snapshot longtext NOT NULL,
        snapshot_hash char(64) NOT NULL,
        event_key varchar(100) NULL DEFAULT NULL,
        created_at datetime NOT NULL,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY event_key (event_key),
        KEY order_type (order_id, type),
        KEY document_number (document_number),
        KEY created_at (created_at)
    ) {$charset};";
    dbDelta( $documents_sql );

    $assets_table = $wpdb->prefix . 'clr_assets';
    $assets_sql = "CREATE TABLE {$assets_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        inventory_number varchar(100) NOT NULL,
        serial_number varchar(190) NOT NULL DEFAULT '',
        status varchar(30) NOT NULL DEFAULT 'available',
        purchase_date date NULL,
        last_service_date date NULL,
        notes text NULL,
        known_damage text NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY inventory_number (inventory_number),
        KEY product_status (product_id, status),
        KEY serial_number (serial_number(100))
    ) {$charset};";
    dbDelta( $assets_sql );

    $assignments_table = $wpdb->prefix . 'clr_asset_assignments';
    $assignments_sql = "CREATE TABLE {$assignments_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        asset_id bigint(20) unsigned NOT NULL,
        booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
        order_id bigint(20) unsigned NOT NULL DEFAULT 0,
        start_at datetime NULL,
        end_at datetime NULL,
        status varchar(30) NOT NULL DEFAULT 'reserved',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY asset_times (asset_id, start_at, end_at),
        KEY booking_id (booking_id),
        KEY order_id (order_id)
    ) {$charset};";
    dbDelta( $assignments_sql );

    update_option( 'clr_db_version', RMWC_DB_VERSION, false );
}

function rmwc_activate() {
    rmwc_install_database();
    if ( ! wp_next_scheduled( 'rmwc_hourly_reminders' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'rmwc_hourly_reminders' );
    }
}
register_activation_hook( __FILE__, 'rmwc_activate' );

function rmwc_deactivate() {
    wp_clear_scheduled_hook( 'rmwc_hourly_reminders' );
    wp_clear_scheduled_hook( 'clr_hourly_reminders' ); // Legacy 1.0.x hook.
    wp_clear_scheduled_hook( 'rmwc_expire_unpaid_order' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        // Empty args + no group cancels every pending occurrence of this unique hook,
        // including actions carrying an order ID argument.
        as_unschedule_all_actions( 'rmwc_expire_unpaid_order' );
    }
}
register_deactivation_hook( __FILE__, 'rmwc_deactivate' );

add_action( 'plugins_loaded', function() {
    if ( get_option( 'clr_db_version' ) !== RMWC_DB_VERSION ) {
        rmwc_install_database();
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Patsch9 Rental Engine for WooCommerce', 'patsch9-rental-engine' ) . '</strong> ' . esc_html__( 'benötigt WooCommerce.', 'patsch9-rental-engine' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both fragments escaped above.
        } );
        return;
    }
    if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.9.4', '<' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Patsch9 Rental Engine for WooCommerce', 'patsch9-rental-engine' ) . '</strong> ' . esc_html__( 'benötigt WooCommerce 10.9.4 oder höher.', 'patsch9-rental-engine' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both fragments escaped above.
        } );
        return;
    }

    require_once RMWC_DIR . 'includes/class-rmwc-plugin.php';
    RMWC_Plugin::instance();

    require_once RMWC_DIR . 'includes/v2/class-rmwc-v2.php';
    RMWC_V2::instance();
}, 20 );
