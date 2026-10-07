<?php

defined( 'ABSPATH' ) || exit;

final class RMWC_Plugin {
    private static $instance = null;
    private $admin_page_hook = '';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Product configuration.
        add_filter( 'product_type_options', [ $this, 'admin_product_type_options' ] );
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'admin_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'admin_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        // Frontend.
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_rental_form' ], 12 );
        add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );
        add_action( 'wp_ajax_rmwc_availability', [ $this, 'ajax_availability' ] );
        add_action( 'wp_ajax_nopriv_rmwc_availability', [ $this, 'ajax_availability' ] );
        add_action( 'wp_ajax_rmwc_delivery_quote', [ $this, 'ajax_delivery_quote' ] );
        add_action( 'wp_ajax_nopriv_rmwc_delivery_quote', [ $this, 'ajax_delivery_quote' ] );
        add_action( 'wp_ajax_clr_availability', [ $this, 'ajax_availability' ] ); // Legacy 1.0.x alias.
        add_action( 'wp_ajax_nopriv_clr_availability', [ $this, 'ajax_availability' ] ); // Legacy 1.0.x alias.
        add_action( 'wp_ajax_clr_delivery_quote', [ $this, 'ajax_delivery_quote' ] ); // Legacy 1.0.x alias.
        add_action( 'wp_ajax_nopriv_clr_delivery_quote', [ $this, 'ajax_delivery_quote' ] ); // Legacy 1.0.x alias.

        // Cart and checkout.
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 20, 5 );
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 20, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 20, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'set_cart_item_prices' ], 20 );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_cart_fees' ], 20 );
        add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart_availability' ] );
        add_filter( 'woocommerce_is_purchasable', [ $this, 'rental_is_purchasable' ], 20, 2 );
        add_filter( 'woocommerce_get_price_html', [ $this, 'rental_price_html' ], 20, 2 );

        // Orders/bookings lifecycle.
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'order_line_item_meta' ], 20, 4 );
        add_action( 'woocommerce_checkout_create_order_fee_item', [ $this, 'order_fee_meta' ], 20, 4 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'reserve_order_bookings' ], 20, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'reserve_store_api_order_bookings' ], 20, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'ensure_order_bookings' ] );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'ensure_order_bookings' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'ensure_order_bookings' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'cancel_order_bookings' ] );
        add_action( 'woocommerce_order_status_failed', [ $this, 'cancel_order_bookings' ] );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'cancel_order_bookings' ] );
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 20, 4 );
        add_action( 'rmwc_expire_unpaid_order', [ $this, 'expire_unpaid_order' ], 10, 1 );
        add_filter( 'woocommerce_cancel_unpaid_order', [ $this, 'protect_offline_rental_from_wc_unpaid_cancellation' ], 20, 2 );

        // Admin.
        add_action( 'admin_menu', [ $this, 'admin_menu' ], 99 );
        add_action( 'add_meta_boxes', [ $this, 'add_deposit_meta_box' ] );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_deposit_meta_box' ], 20, 2 );

        // Reminder cron.
        add_action( 'rmwc_hourly_reminders', [ $this, 'send_due_reminders' ] );

        // Privacy tools / privacy policy integration for duplicated booking data.
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_privacy_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_privacy_eraser' ] );
        add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );

        $this->migrate_legacy_bookings();
        // Migrate the historic short cron hook to the namespaced hook.
        if ( wp_next_scheduled( 'clr_hourly_reminders' ) ) {
            wp_clear_scheduled_hook( 'clr_hourly_reminders' );
        }
        if ( ! wp_next_scheduled( 'rmwc_hourly_reminders' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'rmwc_hourly_reminders' );
        }
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_bookings';
    }

    /**
     * MySQL named locks are server-global. Scope the lock to this site/database
     * so identical product IDs on another WordPress install cannot collide.
     */
    private function booking_lock_name( $product_id ) {
        global $wpdb;
        $scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id();
        return 'clr_' . substr( hash( 'sha256', $scope ), 0, 24 ) . '_' . absint( $product_id );
    }


    private function is_valid_date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
        return $date && $date->format( 'Y-m-d' ) === $value;
    }

    private function sanitize_time_value( $value, $fallback ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
    }

    private function limit_text( $value, $length ) {
        $value  = (string) $value;
        $length = max( 1, (int) $length );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $length );
        }
        return substr( $value, 0, $length );
    }

    private function sanitize_address( $value ) {
        $value = sanitize_text_field( is_string( $value ) ? $value : '' );
        $value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
        return trim( $this->limit_text( (string) $value, 250 ) );
    }

    private function request_rate_allowed( $bucket, $limit, $window = MINUTE_IN_SECONDS ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
        if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            $ip = 'unknown';
        }
        // Store only a site-keyed fingerprint, never the visitor IP itself.
        $fingerprint = hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
        $key = 'rmwc_rate_' . sanitize_key( $bucket ) . '_' . substr( $fingerprint, 0, 32 );
        $count = (int) get_transient( $key );
        if ( $count >= max( 1, (int) $limit ) ) {
            return false;
        }
        set_transient( $key, $count + 1, max( 1, (int) $window ) );
        return true;
    }


    private function global_rate_allowed( $bucket, $limit, $window = MINUTE_IN_SECONDS ) {
        global $wpdb;
        $bucket = sanitize_key( $bucket );
        $key    = 'rmwc_global_rate_' . $bucket;
        $scope  = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $bucket;
        $lock   = 'clr_rate_' . substr( hash( 'sha256', $scope ), 0, 40 );
        $got    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes the global abuse counter across concurrent requests.
        if ( 1 !== $got ) {
            // Under unusual contention, deny rather than allow an expensive
            // request to bypass the shared budget.
            return false;
        }

        try {
            $count = (int) get_transient( $key );
            if ( $count >= max( 1, (int) $limit ) ) {
                return false;
            }
            set_transient( $key, $count + 1, max( 1, (int) $window ) );
            return true;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the rate-counter serialization lock.
        }
    }

    private function public_rental_product( $product_id ) {
        if ( ! $this->is_rental( $product_id ) ) {
            return false;
        }
        if ( 'publish' === get_post_status( $product_id ) ) {
            return true;
        }
        return current_user_can( 'edit_post', $product_id );
    }

    private function is_rental( $product_id ) {
        return $product_id && 'yes' === get_post_meta( $product_id, '_clr_rental_enabled', true );
    }

    private function config_product_id( $product_id, $variation_id = 0 ) {
        if ( $variation_id && $this->is_rental( $variation_id ) ) {
            return $variation_id;
        }
        if ( $this->is_rental( $product_id ) ) {
            return $product_id;
        }
        if ( $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( $variation && $variation->is_type( 'variation' ) && $this->is_rental( $variation->get_parent_id() ) ) {
                return $variation->get_parent_id();
            }
        }
        return 0;
    }

    private function pickup_time( $product_id ) {
        $value = get_post_meta( $product_id, '_clr_pickup_time', true );
        return $this->sanitize_time_value( $value, '12:00' );
    }

    private function return_time( $product_id ) {
        $value = get_post_meta( $product_id, '_clr_return_time', true );
        return $this->sanitize_time_value( $value, '10:00' );
    }

    private function capacity( $product_id ) {
        $capacity = max( 1, (int) get_post_meta( $product_id, '_clr_capacity', true ) );
        return max( 1, (int) apply_filters( 'rmwc_capacity', $capacity, absint( $product_id ) ) );
    }

    private function buffer_hours( $product_id ) {
        $hours = get_post_meta( $product_id, '_clr_buffer_hours', true );
        if ( '' !== $hours ) {
            return max( 0, (int) $hours );
        }
        // Migration path from 0.1.x.
        return max( 0, (int) get_post_meta( $product_id, '_clr_buffer_days', true ) * 24 );
    }

    private function sanitize_weekday_list( $value ) {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $days = [];
        foreach ( array_slice( $value, 0, 7 ) as $day ) {
            $day = absint( $day );
            if ( $day >= 1 && $day <= 7 ) {
                $days[] = $day;
            }
        }
        $days = array_values( array_unique( $days ) );
        sort( $days, SORT_NUMERIC );
        return $days;
    }

    private function blocked_pickup_days( $product_id ) {
        return $this->sanitize_weekday_list( get_post_meta( $product_id, '_clr_blocked_pickup_days', true ) );
    }

    private function blocked_return_days( $product_id ) {
        return $this->sanitize_weekday_list( get_post_meta( $product_id, '_clr_blocked_return_days', true ) );
    }

    private function weekday_prices( $product_id ) {
        $stored = get_post_meta( $product_id, '_clr_weekday_prices', true );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        $prices = [];
        for ( $day = 1; $day <= 7; $day++ ) {
            if ( ! array_key_exists( $day, $stored ) && ! array_key_exists( (string) $day, $stored ) ) {
                continue;
            }
            $raw = array_key_exists( $day, $stored ) ? $stored[ $day ] : $stored[ (string) $day ];
            if ( '' === (string) $raw || ! is_numeric( $raw ) ) {
                continue;
            }
            $prices[ $day ] = min( 100000000, max( 0, (float) $raw ) );
        }
        return $prices;
    }

    private function validate_handover_weekdays( $product_id, $start_date, $end_date ) {
        if ( ! $this->is_valid_date( (string) $start_date ) || ! $this->is_valid_date( (string) $end_date ) ) {
            return new WP_Error( 'clr_dates', 'Bitte einen gültigen Mietzeitraum wählen.' );
        }

        $tz    = wp_timezone();
        $start = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $tz );
        $end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $tz );
        if ( ! $start || ! $end ) {
            return new WP_Error( 'clr_dates', 'Bitte einen gültigen Mietzeitraum wählen.' );
        }

        $weekdays = $this->weekday_options();
        $start_day = (int) $start->format( 'N' );
        $end_day   = (int) $end->format( 'N' );

        if ( in_array( $start_day, $this->blocked_pickup_days( $product_id ), true ) ) {
            return new WP_Error(
                'clr_pickup_weekday_blocked',
                sprintf( 'Am %s ist keine Abholung bzw. kein Mietbeginn möglich. Bitte einen anderen Starttag wählen.', $weekdays[ $start_day ] ?? 'gewählten Wochentag' )
            );
        }
        if ( in_array( $end_day, $this->blocked_return_days( $product_id ), true ) ) {
            return new WP_Error(
                'clr_return_weekday_blocked',
                sprintf( 'Am %s ist keine Rückgabe möglich. Bitte einen anderen Rückgabetag wählen.', $weekdays[ $end_day ] ?? 'gewählten Wochentag' )
            );
        }

        return true;
    }

    /**
     * Add the rental switch to WooCommerce's product-type option row, next to
     * options such as "Virtual" and "Downloadable". Keeping the existing
     * meta key preserves backwards compatibility with all 1.x releases.
     *
     * @param array $options Existing WooCommerce product type options.
     * @return array
     */
    public function admin_product_type_options( $options ) {
        $options['clr_rental_enabled'] = [
            'id'            => '_clr_rental_enabled',
            'wrapper_class' => 'show_if_simple show_if_variable',
            'label'         => __( 'Vermietung', 'patsch9-rental-engine' ),
            'description'   => __( 'Dieses WooCommerce-Produkt als Mietartikel anbieten.', 'patsch9-rental-engine' ),
            'default'       => 'no',
        ];

        return $options;
    }

    public function admin_tab( $tabs ) {
        $tabs['clr_rental'] = [
            'label'    => 'Vermietung',
            'target'   => 'clr_rental_product_data',
            'class'    => [ 'show_if_simple', 'show_if_variable' ],
            'priority' => 35,
        ];
        return $tabs;
    }

    public function admin_panel() {
        global $post;
        $id = $post ? $post->ID : 0;

        $addons        = get_post_meta( $id, '_clr_addons', true );
        $pricing_rules = get_post_meta( $id, '_clr_pricing_rules', true );
        $delivery      = get_post_meta( $id, '_clr_delivery_zones', true );
        $addons        = is_array( $addons ) ? $addons : [];
        $pricing_rules = is_array( $pricing_rules ) ? $pricing_rules : [];
        $delivery      = is_array( $delivery ) ? $delivery : [];
        $blocked_pickup_days = $this->blocked_pickup_days( $id );
        $blocked_return_days = $this->blocked_return_days( $id );
        $weekday_prices      = $this->weekday_prices( $id );
        ?>
        <div id="clr_rental_product_data" class="panel woocommerce_options_panel hidden">
            <?php wp_nonce_field( 'clr_save_product_' . $id, 'clr_product_nonce' ); ?>
            <div class="options_group">
                <h4 style="padding:0 12px;margin-bottom:0">Grunddaten & Zeiten</h4>
                <?php
                woocommerce_wp_select( [
                    'id'          => '_clr_billing_unit',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'       => 'Standard-Abrechnung',
                    'options'     => [ 'day' => 'pro Tag', 'week' => 'pro Woche (7 Miettage)', 'month' => 'pro Monat (30 Miettage)' ],
                    'description' => 'Wird verwendet, wenn keine Preisstaffel oder Wochenendregel greift. Bei Tagesabrechnung können einzelne Wochentage den Standardpreis überschreiben.',
                    'desc_tip'    => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_unit_price',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Standard-Mietpreis',
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_capacity',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Verfügbare Anzahl',
                    'type'              => 'number',
                    'value'             => get_post_meta( $id, '_clr_capacity', true ) ?: 1,
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                    'description'       => 'Wie viele identische Geräte dieses Produkts gleichzeitig vermietet werden können.',
                    'desc_tip'          => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'          => '_clr_pickup_time',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'       => 'Abhol-/Mietbeginn',
                    'type'        => 'time',
                    'value'       => $this->pickup_time( $id ),
                    'description' => 'Beispiel: 12:00. Ab diesem Zeitpunkt beginnt die Buchung am gewählten Startdatum.',
                    'desc_tip'    => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'          => '_clr_return_time',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'       => 'Rückgabezeit',
                    'type'        => 'time',
                    'value'       => $this->return_time( $id ),
                    'description' => 'Beispiel: 10:00. Bis zu diesem Zeitpunkt muss der Artikel am Rückgabedatum zurück sein.',
                    'desc_tip'    => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_min_days',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Mindestmietdauer',
                    'type'              => 'number',
                    'value'             => get_post_meta( $id, '_clr_min_days', true ) ?: 1,
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                    'description'       => 'Miettage werden aus der tatsächlichen Zeitspanne berechnet und auf volle Tage aufgerundet.',
                    'desc_tip'          => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_max_days',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Maximale Mietdauer',
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
                    'description'       => '0 oder leer = unbegrenzt.',
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_buffer_hours',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Puffer nach Rückgabe (Std.)',
                    'type'              => 'number',
                    'value'             => get_post_meta( $id, '_clr_buffer_hours', true ) !== '' ? get_post_meta( $id, '_clr_buffer_hours', true ) : $this->buffer_hours( $id ),
                    'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
                    'description'       => 'Zusätzliche Sperrzeit nach der Rückgabe, z. B. 2 Stunden für Reinigung und Kontrolle.',
                    'desc_tip'          => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_advance_days',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Max. Vorausbuchung (Tage)',
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
                    'description'       => '0 oder leer = unbegrenzt.',
                ] );
                ?>
            </div>

            <div class="options_group clr-weekday-section">
                <div class="clr-product-section-head">
                    <h4><?php esc_html_e( 'Wochentage & Sperrtage', 'patsch9-rental-engine' ); ?></h4>
                    <p class="description"><?php esc_html_e( 'Lege fest, an welchen Wochentagen Abholung und Rückgabe möglich sind und ob an einzelnen Miettagen ein abweichender Tagespreis gilt. Laufende Vermietungen dürfen gesperrte Übergabetage überbrücken.', 'patsch9-rental-engine' ); ?></p>
                </div>
                <div class="clr-weekday-cards">
                    <?php foreach ( $this->weekday_options() as $day_number => $day_label ) : ?>
                        <section class="clr-weekday-card">
                            <h5><?php echo esc_html( $day_label ); ?></h5>
                            <label class="clr-weekday-toggle">
                                <input type="checkbox" name="clr_blocked_pickup_days[]" value="<?php echo esc_attr( $day_number ); ?>" <?php checked( in_array( $day_number, $blocked_pickup_days, true ) ); ?>>
                                <span>
                                    <strong><?php esc_html_e( 'Keine Abholung', 'patsch9-rental-engine' ); ?></strong>
                                    <small><?php esc_html_e( 'Kein Mietbeginn an diesem Tag', 'patsch9-rental-engine' ); ?></small>
                                </span>
                            </label>
                            <label class="clr-weekday-toggle">
                                <input type="checkbox" name="clr_blocked_return_days[]" value="<?php echo esc_attr( $day_number ); ?>" <?php checked( in_array( $day_number, $blocked_return_days, true ) ); ?>>
                                <span>
                                    <strong><?php esc_html_e( 'Keine Rückgabe', 'patsch9-rental-engine' ); ?></strong>
                                    <small><?php esc_html_e( 'Keine Rückgabe an diesem Tag', 'patsch9-rental-engine' ); ?></small>
                                </span>
                            </label>
                            <div class="clr-weekday-price">
                                <label for="clr_weekday_price_<?php echo esc_attr( $day_number ); ?>"><?php esc_html_e( 'Tagespreis', 'patsch9-rental-engine' ); ?></label>
                                <span class="clr-money-input">
                                    <input type="number"
                                           id="clr_weekday_price_<?php echo esc_attr( $day_number ); ?>"
                                           min="0"
                                           step="0.01"
                                           name="clr_weekday_prices[<?php echo esc_attr( $day_number ); ?>]"
                                           value="<?php echo esc_attr( array_key_exists( $day_number, $weekday_prices ) ? $weekday_prices[ $day_number ] : '' ); ?>"
                                           placeholder="<?php esc_attr_e( 'Standardpreis', 'patsch9-rental-engine' ); ?>">
                                    <span aria-hidden="true"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
                                </span>
                                <small><?php esc_html_e( 'Leer = Standard-Mietpreis', 'patsch9-rental-engine' ); ?></small>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="options_group clr-deposit-handover-section">
                <h4 class="clr-rental-section-title">Kaution & Übergabe</h4>
                <?php
                woocommerce_wp_text_input( [
                    'id'                => '_clr_deposit',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Kaution',
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
                    'description'       => 'Rückzahlbare Sicherheitsleistung je vermietetem Gerät.',
                    'desc_tip'          => true,
                ] );
                woocommerce_wp_textarea_input( [
                    'id'          => '_clr_deposit_notice',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'       => 'Hinweis zur Kaution',
                    'placeholder' => 'z. B. Wird nach Prüfung der Rückgabe zurückerstattet.',
                    'description' => 'Optionaler Freitext, der beim Kautionsbetrag auf der Produktseite angezeigt wird.',
                    'desc_tip'    => true,
                ] );
                /**
                 * V2 extension point for deposit/payment settings. Kept inside
                 * the existing product panel so optional modules remain one
                 * installable rental plugin.
                 */
                do_action( 'rmwc_product_panel_after_handover', $id );
                woocommerce_wp_checkbox( [
                    'id'            => '_clr_allow_pickup',
                    'wrapper_class' => 'clr-rental-field-row',
                    'label'         => 'Abholung erlauben',
                ] );
                woocommerce_wp_checkbox( [
                    'id'            => '_clr_allow_delivery',
                    'wrapper_class' => 'clr-rental-field-row',
                    'label'         => 'Lieferung erlauben',
                    'description'   => 'Ermöglicht: Lieferung durch Vermieter und Rückgabe durch Kunde.',
                    'desc_tip'      => true,
                ] );
                woocommerce_wp_checkbox( [
                    'id'            => '_clr_allow_delivery_return',
                    'wrapper_class' => 'clr-rental-field-row',
                    'label'         => 'Lieferung + Abholung erlauben',
                    'value'         => get_post_meta( $id, '_clr_allow_delivery_return', true ),
                    'description'   => 'Ermöglicht: Lieferung und spätere Abholung durch den Vermieter. Der Transportpreis wird je Fahrt berechnet (Hin- und Rückfahrt = 2 × Preis).',
                    'desc_tip'      => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_delivery_fee',
                    'wrapper_class'     => 'clr-rental-field-row',
                    'label'             => 'Fallback-Transportpauschale je Fahrt',
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
                    'description'       => 'Wird nur verwendet, wenn keine Lieferzonen definiert sind. Der Betrag gilt je Fahrt; bei Lieferung und Abholung wird er zweimal berechnet.',
                    'desc_tip'          => true,
                ] );
                ?>
            </div>

            <div class="options_group clr-pricing-wrap">
                <h4 style="padding:0 12px;margin-bottom:6px">Flexible Preisstaffeln</h4>
                <p style="padding:0 12px" class="description">Preis-Priorität: Wochenendtarif → passende Preisstaffel → Wochentagspreise → Standardpreis. Beispiel: ab 7 Miettagen Paketpreis 129 € inkl. 7 Tage, danach 15 € je weiterem Miettag.</p>
                <table class="widefat clr-pricing-table clr-admin-table">
                    <thead><tr><th>Ab Tagen</th><th>Bis Tage</th><th>Paketpreis</th><th>Enthaltene Tage</th><th>Weiterer Tag</th><th></th></tr></thead>
                    <tbody><?php foreach ( $pricing_rules as $i => $rule ) $this->pricing_admin_row( $i, $rule ); ?></tbody>
                </table>
                <p style="padding:0 12px"><button type="button" class="button clr-add-pricing">+ Preisstaffel</button></p>
                <?php
                woocommerce_wp_checkbox( [
                    'id'          => '_clr_weekend_enabled',
                    'label'       => 'Wochenendtarif aktiv',
                    'description' => 'Greift nur bei exakt passendem Start-/Rückgabe-Wochentag und genau der daraus folgenden Dauer (z. B. Freitag bis Montag = 3 Miettage).',
                    'desc_tip'    => true,
                ] );
                woocommerce_wp_text_input( [
                    'id'                => '_clr_weekend_price',
                    'label'             => 'Wochenendpreis',
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
                ] );
                woocommerce_wp_select( [
                    'id'      => '_clr_weekend_start_day',
                    'label'   => 'Wochenende beginnt',
                    'value'   => get_post_meta( $id, '_clr_weekend_start_day', true ) ?: 5,
                    'options' => $this->weekday_options(),
                ] );
                woocommerce_wp_select( [
                    'id'      => '_clr_weekend_end_day',
                    'label'   => 'Wochenende endet',
                    'value'   => get_post_meta( $id, '_clr_weekend_end_day', true ) ?: 1,
                    'options' => $this->weekday_options(),
                ] );
                ?>
            </div>

            <div class="options_group clr-addons-wrap">
                <h4 style="padding:0 12px;margin-bottom:6px">Zusatzoptionen &amp; Miet-Zubehör</h4>
                <p style="padding:0 12px" class="description">Für Materialgrößen und bepreiste Zubehörartikel verwende Miet-Zubehör. Einfache Zusatzoptionen sind für Leistungen oder Aufschläge gedacht, die keinen eigenen Lagerartikel benötigen.</p>
                <?php do_action( 'rmwc_product_panel_accessories', $id ); ?>
                <details class="clr-simple-addons" <?php if ( $addons ) : ?>open<?php endif; ?>>
                    <summary><?php esc_html_e( 'Einfache Zusatzoptionen (ohne eigenen WooCommerce-Artikel)', 'patsch9-rental-engine' ); ?></summary>
                    <div class="clr-simple-addons-inner">
                        <table class="widefat clr-addons-table clr-admin-table">
                            <thead><tr><th>Gruppe</th><th>Option</th><th>Preis</th><th>Abrechnung</th><th></th></tr></thead>
                            <tbody><?php foreach ( $addons as $i => $addon ) { $this->addon_admin_row( $i, $addon ); } ?></tbody>
                        </table>
                        <p><button type="button" class="button clr-add-addon">+ Einfache Zusatzoption</button></p>
                    </div>
                </details>
            </div>

            <div class="options_group clr-delivery-wrap">
                <h4 style="padding:0 12px;margin-bottom:6px">Lieferzonen</h4>
                <p style="padding:0 12px" class="description">Zonen werden nach maximaler Fahrstrecke je einfacher Fahrt ausgewertet. Der Preis gilt je Fahrt. Bei „Lieferung und Abholung durch Vermieter“ wird derselbe Fahrpreis zweimal berechnet. Mit Google Routes API erfolgt die Kilometerermittlung automatisch; ohne API kann der Kunde die Zone manuell auswählen.</p>
                <table class="widefat clr-delivery-table clr-admin-table">
                    <thead><tr><th>Bezeichnung</th><th>Bis km</th><th>Preis je Fahrt</th><th></th></tr></thead>
                    <tbody><?php foreach ( $delivery as $i => $zone ) $this->delivery_admin_row( $i, $zone ); ?></tbody>
                </table>
                <p style="padding:0 12px"><button type="button" class="button clr-add-delivery">+ Lieferzone</button></p>
            </div>
            <?php do_action( 'rmwc_product_panel_v2_end', $id ); ?>
        </div>
        <?php
    }

    private function weekday_options() {
        return [ 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag' ];
    }

    private function pricing_admin_row( $i, $rule = [] ) {
        ?>
        <tr>
            <td><input type="number" min="1" step="1" name="clr_pricing[<?php echo esc_attr( $i ); ?>][min_days]" value="<?php echo esc_attr( $rule['min_days'] ?? 1 ); ?>"></td>
            <td><input type="number" min="0" step="1" name="clr_pricing[<?php echo esc_attr( $i ); ?>][max_days]" value="<?php echo esc_attr( $rule['max_days'] ?? '' ); ?>" placeholder="∞"></td>
            <td><input type="number" min="0" step="0.01" name="clr_pricing[<?php echo esc_attr( $i ); ?>][package_price]" value="<?php echo esc_attr( $rule['package_price'] ?? 0 ); ?>"></td>
            <td><input type="number" min="1" step="1" name="clr_pricing[<?php echo esc_attr( $i ); ?>][included_days]" value="<?php echo esc_attr( $rule['included_days'] ?? ( $rule['min_days'] ?? 1 ) ); ?>"></td>
            <td><input type="number" min="0" step="0.01" name="clr_pricing[<?php echo esc_attr( $i ); ?>][extra_day_price]" value="<?php echo esc_attr( $rule['extra_day_price'] ?? '' ); ?>"></td>
            <td><button type="button" class="button clr-remove-row">×</button></td>
        </tr>
        <?php
    }

    private function addon_admin_row( $i, $addon = [] ) {
        $mode = $addon['mode'] ?? 'once';
        ?>
        <tr>
            <td><input type="text" name="clr_addons[<?php echo esc_attr( $i ); ?>][group]" value="<?php echo esc_attr( $addon['group'] ?? '' ); ?>" placeholder="Material"></td>
            <td><input type="text" name="clr_addons[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $addon['label'] ?? '' ); ?>" placeholder="für 20 Personen"></td>
            <td><input type="number" step="0.01" min="0" name="clr_addons[<?php echo esc_attr( $i ); ?>][price]" value="<?php echo esc_attr( $addon['price'] ?? 0 ); ?>"></td>
            <td><select name="clr_addons[<?php echo esc_attr( $i ); ?>][mode]"><option value="once" <?php selected( $mode, 'once' ); ?>>einmalig</option><option value="unit" <?php selected( $mode, 'unit' ); ?>>pro Mieteinheit</option><option value="day" <?php selected( $mode, 'day' ); ?>>pro Miettag</option></select></td>
            <td><button type="button" class="button clr-remove-row">×</button></td>
        </tr>
        <?php
    }

    private function delivery_admin_row( $i, $zone = [] ) {
        ?>
        <tr>
            <td><input type="text" name="clr_delivery_zones[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $zone['label'] ?? '' ); ?>" placeholder="bis 10 km"></td>
            <td><input type="number" step="0.1" min="0.1" name="clr_delivery_zones[<?php echo esc_attr( $i ); ?>][max_km]" value="<?php echo esc_attr( $zone['max_km'] ?? '' ); ?>"></td>
            <td><input type="number" step="0.01" min="0" name="clr_delivery_zones[<?php echo esc_attr( $i ); ?>][price]" value="<?php echo esc_attr( $zone['price'] ?? 0 ); ?>"></td>
            <td><button type="button" class="button clr-remove-row">×</button></td>
        </tr>
        <?php
    }

    public function save_product_fields( $post_id ) {
        if ( ! $post_id || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['clr_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clr_save_product_' . $post_id ) ) {
            return;
        }

        $checkboxes = [ '_clr_rental_enabled', '_clr_allow_pickup', '_clr_allow_delivery', '_clr_allow_delivery_return', '_clr_weekend_enabled' ];
        foreach ( $checkboxes as $key ) {
            update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
        }

        $billing_unit = isset( $_POST['_clr_billing_unit'] ) ? sanitize_key( wp_unslash( $_POST['_clr_billing_unit'] ) ) : 'day';
        update_post_meta( $post_id, '_clr_billing_unit', in_array( $billing_unit, [ 'day', 'week', 'month' ], true ) ? $billing_unit : 'day' );
        update_post_meta( $post_id, '_clr_pickup_time', $this->sanitize_time_value( isset( $_POST['_clr_pickup_time'] ) ? sanitize_text_field( wp_unslash( $_POST['_clr_pickup_time'] ) ) : '', '12:00' ) );
        update_post_meta( $post_id, '_clr_return_time', $this->sanitize_time_value( isset( $_POST['_clr_return_time'] ) ? sanitize_text_field( wp_unslash( $_POST['_clr_return_time'] ) ) : '', '10:00' ) );
        foreach ( [ '_clr_weekend_start_day', '_clr_weekend_end_day' ] as $key ) {
            $value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 1;
            update_post_meta( $post_id, $key, min( 7, max( 1, $value ) ) );
        }

        $raw_blocked_pickup = isset( $_POST['clr_blocked_pickup_days'] )
            ? map_deep( wp_unslash( $_POST['clr_blocked_pickup_days'] ), 'sanitize_text_field' )
            : [];
        $blocked_pickup = $this->sanitize_weekday_list( is_array( $raw_blocked_pickup ) ? $raw_blocked_pickup : [] );
        $raw_blocked_return = isset( $_POST['clr_blocked_return_days'] )
            ? map_deep( wp_unslash( $_POST['clr_blocked_return_days'] ), 'sanitize_text_field' )
            : [];
        $blocked_return = $this->sanitize_weekday_list( is_array( $raw_blocked_return ) ? $raw_blocked_return : [] );
        update_post_meta( $post_id, '_clr_blocked_pickup_days', $blocked_pickup );
        update_post_meta( $post_id, '_clr_blocked_return_days', $blocked_return );

        $weekday_prices = [];
        $raw_weekday_prices = isset( $_POST['clr_weekday_prices'] )
            ? map_deep( wp_unslash( $_POST['clr_weekday_prices'] ), 'sanitize_text_field' )
            : [];
        $raw_weekday_prices = is_array( $raw_weekday_prices ) ? $raw_weekday_prices : [];
        for ( $day = 1; $day <= 7; $day++ ) {
            if ( ! isset( $raw_weekday_prices[ $day ] ) && ! isset( $raw_weekday_prices[ (string) $day ] ) ) {
                continue;
            }
            $raw = isset( $raw_weekday_prices[ $day ] ) ? $raw_weekday_prices[ $day ] : $raw_weekday_prices[ (string) $day ];
            if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
                continue;
            }
            $weekday_prices[ $day ] = min( 100000000, max( 0, (float) wc_format_decimal( $raw ) ) );
        }
        update_post_meta( $post_id, '_clr_weekday_prices', $weekday_prices );

        $decimal_fields = [ '_clr_unit_price', '_clr_deposit', '_clr_delivery_fee', '_clr_weekend_price' ];
        foreach ( $decimal_fields as $key ) {
            $raw_value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '0';
            $value = (float) wc_format_decimal( $raw_value );
            update_post_meta( $post_id, $key, min( 100000000, max( 0, $value ) ) );
        }
        $deposit_notice = isset( $_POST['_clr_deposit_notice'] )
            ? $this->limit_text( sanitize_textarea_field( wp_unslash( $_POST['_clr_deposit_notice'] ) ), 500 )
            : '';
        update_post_meta( $post_id, '_clr_deposit_notice', $deposit_notice );
        $integer_limits = [
            '_clr_capacity'     => [ 1, 10000 ],
            '_clr_min_days'     => [ 1, 3660 ],
            '_clr_max_days'     => [ 0, 3660 ],
            '_clr_buffer_hours' => [ 0, 8760 ],
            '_clr_advance_days' => [ 0, 3650 ],
        ];
        foreach ( $integer_limits as $key => $limits ) {
            $value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : $limits[0];
            update_post_meta( $post_id, $key, min( $limits[1], max( $limits[0], $value ) ) );
        }

        $pricing = [];
        /* phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Product nonce/capability verified above; every nested value is validated below. */
        $raw_pricing = isset( $_POST['clr_pricing'] ) && is_array( $_POST['clr_pricing'] ) ? array_slice( wp_unslash( $_POST['clr_pricing'] ), 0, 100 ) : [];
        foreach ( $raw_pricing as $rule ) {
            if ( ! is_array( $rule ) || ! isset( $rule['package_price'] ) || '' === trim( (string) $rule['package_price'] ) ) {
                continue;
            }
            $min = min( 3660, max( 1, absint( $rule['min_days'] ?? 1 ) ) );
            $max = min( 3660, max( 0, absint( $rule['max_days'] ?? 0 ) ) );
            if ( $max && $max < $min ) {
                $max = $min;
            }
            $pricing[] = [
                'min_days'        => $min,
                'max_days'        => $max,
                'package_price'   => min( 100000000, max( 0, (float) wc_format_decimal( $rule['package_price'] ?? 0 ) ) ),
                'included_days'   => min( 3660, max( 1, absint( $rule['included_days'] ?? $min ) ) ),
                'extra_day_price' => '' === (string) ( $rule['extra_day_price'] ?? '' ) ? '' : min( 100000000, max( 0, (float) wc_format_decimal( $rule['extra_day_price'] ) ) ),
            ];
        }
        usort( $pricing, static function( $a, $b ) { return $a['min_days'] <=> $b['min_days']; } );
        update_post_meta( $post_id, '_clr_pricing_rules', $pricing );

        $addons = [];
        /* phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Product nonce/capability verified above; every nested value is validated below. */
        $raw_addons = isset( $_POST['clr_addons'] ) && is_array( $_POST['clr_addons'] ) ? array_slice( wp_unslash( $_POST['clr_addons'] ), 0, 100 ) : [];
        foreach ( $raw_addons as $addon ) {
            if ( ! is_array( $addon ) ) {
                continue;
            }
            $group = $this->limit_text( sanitize_text_field( $addon['group'] ?? '' ), 100 );
            $label = $this->limit_text( sanitize_text_field( $addon['label'] ?? '' ), 150 );
            if ( ! $group || ! $label ) {
                continue;
            }
            $mode = sanitize_key( $addon['mode'] ?? 'once' );
            $addons[] = [
                'group' => $group,
                'label' => $label,
                'price' => min( 100000000, max( 0, (float) wc_format_decimal( $addon['price'] ?? 0 ) ) ),
                'mode'  => in_array( $mode, [ 'once', 'unit', 'day' ], true ) ? $mode : 'once',
            ];
        }
        update_post_meta( $post_id, '_clr_addons', $addons );

        $zones = [];
        /* phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Product nonce/capability verified above; every nested value is validated below. */
        $raw_zones = isset( $_POST['clr_delivery_zones'] ) && is_array( $_POST['clr_delivery_zones'] ) ? array_slice( wp_unslash( $_POST['clr_delivery_zones'] ), 0, 100 ) : [];
        foreach ( $raw_zones as $zone ) {
            if ( ! is_array( $zone ) ) {
                continue;
            }
            $label  = $this->limit_text( sanitize_text_field( $zone['label'] ?? '' ), 120 );
            $max_km = min( 5000, max( 0, (float) wc_format_decimal( $zone['max_km'] ?? 0 ) ) );
            if ( ! $label || $max_km <= 0 ) {
                continue;
            }
            $zones[] = [
                'label'  => $label,
                'max_km' => $max_km,
                'price'  => min( 100000000, max( 0, (float) wc_format_decimal( $zone['price'] ?? 0 ) ) ),
            ];
        }
        usort( $zones, static function( $a, $b ) { return $a['max_km'] <=> $b['max_km']; } );
        update_post_meta( $post_id, '_clr_delivery_zones', $zones );
    }

    public function admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( $screen && 'product' === $screen->post_type && in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
            wp_enqueue_editor();
            wp_enqueue_script( 'patsch9-rental-engine-admin-product', RMWC_URL . 'assets/admin.js', [ 'jquery', 'wc-enhanced-select' ], RMWC_VERSION, true );
            wp_enqueue_style( 'patsch9-rental-engine-admin', RMWC_URL . 'assets/admin.css', [], RMWC_VERSION );
        }

        if ( $this->admin_page_hook && $hook === $this->admin_page_hook ) {
            wp_enqueue_style( 'patsch9-rental-engine-admin', RMWC_URL . 'assets/admin.css', [], RMWC_VERSION );
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }
    }

    public function frontend_assets() {
        if ( ! is_product() ) {
            return;
        }
        global $post;
        if ( ! $post || ! $this->is_rental( $post->ID ) ) {
            return;
        }

        $pricing_rules = get_post_meta( $post->ID, '_clr_pricing_rules', true );
        $pricing_rules = is_array( $pricing_rules ) ? $pricing_rules : [];

        wp_enqueue_style( 'patsch9-rental-engine-frontend', RMWC_URL . 'assets/rental.css', [], RMWC_VERSION );
        wp_enqueue_script( 'patsch9-rental-engine-frontend', RMWC_URL . 'assets/rental.js', [ 'jquery' ], RMWC_VERSION, true );
        wp_localize_script( 'patsch9-rental-engine-frontend', 'RMWCData', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'rmwc_frontend' ),
            'productId'      => $post->ID,
            'currency'       => get_woocommerce_currency(),
            'currencySymbol' => get_woocommerce_currency_symbol(),
            'locale'         => str_replace( '_', '-', get_locale() ),
            'pricingRules'   => $pricing_rules,
            'weekdayPrices'  => $this->weekday_prices( $post->ID ),
            'blockedPickupDays' => $this->blocked_pickup_days( $post->ID ),
            'blockedReturnDays' => $this->blocked_return_days( $post->ID ),
            'weekend'        => [
                'enabled'  => 'yes' === get_post_meta( $post->ID, '_clr_weekend_enabled', true ),
                'price'    => (float) get_post_meta( $post->ID, '_clr_weekend_price', true ),
                'startDay' => (int) ( get_post_meta( $post->ID, '_clr_weekend_start_day', true ) ?: 5 ),
                'endDay'   => (int) ( get_post_meta( $post->ID, '_clr_weekend_end_day', true ) ?: 1 ),
            ],
        ] );
    }

    public function render_rental_form() {
        global $product;
        if ( ! $product || ! $this->is_rental( $product->get_id() ) ) {
            return;
        }

        $id           = $product->get_id();
        $addons       = get_post_meta( $id, '_clr_addons', true );
        $zones        = get_post_meta( $id, '_clr_delivery_zones', true );
        $addons       = is_array( $addons ) ? $addons : [];
        $zones        = is_array( $zones ) ? $zones : [];
        $groups       = [];
        $pickup       = 'yes' === get_post_meta( $id, '_clr_allow_pickup', true );
        $delivery     = 'yes' === get_post_meta( $id, '_clr_allow_delivery', true );
        $delivery_return = 'yes' === get_post_meta( $id, '_clr_allow_delivery_return', true );
        $deposit        = (float) get_post_meta( $id, '_clr_deposit', true );
        $deposit_notice = $this->limit_text( (string) get_post_meta( $id, '_clr_deposit_notice', true ), 500 );
        $unit           = get_post_meta( $id, '_clr_billing_unit', true ) ?: 'day';
        $unit_price   = (float) get_post_meta( $id, '_clr_unit_price', true );
        $delivery_fee = (float) get_post_meta( $id, '_clr_delivery_fee', true );
        $auto_distance= $this->distance_api_ready();
        $blocked_pickup_days = $this->blocked_pickup_days( $id );
        $blocked_return_days = $this->blocked_return_days( $id );
        $weekday_labels      = $this->weekday_options();

        foreach ( $addons as $idx => $addon ) {
            $groups[ $addon['group'] ][ $idx ] = $addon;
        }
        ?>
        <div class="clr-rental-box"
             data-product-id="<?php echo esc_attr( $id ); ?>"
             data-unit="<?php echo esc_attr( $unit ); ?>"
             data-unit-price="<?php echo esc_attr( $unit_price ); ?>"
             data-pickup-time="<?php echo esc_attr( $this->pickup_time( $id ) ); ?>"
             data-return-time="<?php echo esc_attr( $this->return_time( $id ) ); ?>"
             data-capacity="<?php echo esc_attr( $this->capacity( $id ) ); ?>"
             data-fallback-delivery="<?php echo esc_attr( $delivery_fee ); ?>">
            <h3>Mietzeitraum</h3>
            <?php wp_nonce_field( 'clr_add_rental_' . $id, 'clr_rental_nonce' ); ?>

            <p class="clr-time-info">
                <strong>Abholung/Mietbeginn:</strong> <?php echo esc_html( $this->pickup_time( $id ) ); ?> Uhr<br>
                <strong>Rückgabe:</strong> <?php echo esc_html( $this->return_time( $id ) ); ?> Uhr am gewählten Rückgabetag
            </p>
            <?php if ( $blocked_pickup_days || $blocked_return_days ) : ?>
                <p class="clr-hint clr-weekday-restrictions">
                    <?php if ( $blocked_pickup_days ) : ?>
                        <strong>Keine Abholung/Mietbeginn:</strong>
                        <?php echo esc_html( implode( ', ', array_map( static function( $day ) use ( $weekday_labels ) { return $weekday_labels[ $day ] ?? ''; }, $blocked_pickup_days ) ) ); ?>
                    <?php endif; ?>
                    <?php if ( $blocked_pickup_days && $blocked_return_days ) : ?><br><?php endif; ?>
                    <?php if ( $blocked_return_days ) : ?>
                        <strong>Keine Rückgabe:</strong>
                        <?php echo esc_html( implode( ', ', array_map( static function( $day ) use ( $weekday_labels ) { return $weekday_labels[ $day ] ?? ''; }, $blocked_return_days ) ) ); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <div id="clr-calendar" class="clr-calendar" aria-label="Buchungskalender"></div>
            <div class="clr-date-summary">
                <label>Mietbeginn <input type="date" name="clr_start_date" id="clr_start_date" required></label>
                <label>Rückgabedatum <input type="date" name="clr_end_date" id="clr_end_date" required></label>
            </div>

            <?php foreach ( $groups as $group => $items ) : ?>
                <p class="form-row form-row-wide clr-addon-group">
                    <label><?php echo esc_html( $group ); ?></label>
                    <select name="clr_addon[<?php echo esc_attr( sanitize_key( $group ) ); ?>]">
                        <option value="">Keine Auswahl</option>
                        <?php foreach ( $items as $idx => $addon ) : ?>
                            <option value="<?php echo esc_attr( $idx ); ?>" data-price="<?php echo esc_attr( (float) $addon['price'] ); ?>" data-mode="<?php echo esc_attr( $addon['mode'] ); ?>">
                                <?php echo esc_html( $addon['label'] ); ?> (+<?php echo wp_kses_post( wc_price( (float) $addon['price'] ) ); ?><?php echo esc_html( 'unit' === $addon['mode'] ? ' / Mieteinheit' : ( 'day' === $addon['mode'] ? ' / Miettag' : '' ) ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endforeach; ?>

            <?php if ( $pickup || $delivery || $delivery_return ) : ?>
                <fieldset class="clr-fulfilment">
                    <legend>Transport / Übergabe</legend>
                    <?php if ( $pickup ) : ?>
                        <label><input type="radio" name="clr_fulfilment" value="pickup" <?php checked( true ); ?>> Abholung und Rückgabe durch Kunde</label>
                    <?php endif; ?>
                    <?php if ( $delivery ) : ?>
                        <label><input type="radio" name="clr_fulfilment" value="delivery" <?php checked( ! $pickup ); ?>> Lieferung durch Vermieter und Rückgabe durch Kunde</label>
                    <?php endif; ?>
                    <?php if ( $delivery_return ) : ?>
                        <label><input type="radio" name="clr_fulfilment" value="delivery_return" <?php checked( ! $pickup && ! $delivery ); ?>> Lieferung und Abholung durch Vermieter</label>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>

            <?php if ( $delivery || $delivery_return ) : ?>
                <div class="clr-delivery-fields" <?php if ( $pickup ) : ?>hidden<?php endif; ?>>
                    <label>Liefer-/Abholadresse (verbindlich für diesen Mietartikel)
                        <input type="text" name="clr_delivery_address" id="clr_delivery_address" maxlength="250" autocomplete="street-address" placeholder="Straße, Hausnummer, PLZ Ort">
                    </label>
                    <?php if ( $zones ) : ?>
                        <?php if ( $auto_distance ) : ?>
                            <p class="clr-hint">Datenschutzhinweis: Zur automatischen Berechnung der Fahrstrecke wird die eingegebene Lieferadresse an die Google Routes API übertragen.</p>
                            <div class="clr-delivery-quote-actions">
                                <button type="button" class="button clr-quote-delivery">Transportkosten berechnen</button>
                            </div>
                            <input type="hidden" name="clr_delivery_zone" id="clr_delivery_zone" value="">
                            <input type="hidden" name="clr_delivery_distance" id="clr_delivery_distance" value="">
                            <p id="clr_delivery_result" class="clr-delivery-result" aria-live="polite"></p>
                        <?php else : ?>
                            <label>Lieferzone
                                <select name="clr_delivery_zone" id="clr_delivery_zone">
                                    <option value="">Bitte wählen</option>
                                    <?php foreach ( $zones as $i => $zone ) : ?>
                                        <option value="<?php echo esc_attr( $i ); ?>" data-price="<?php echo esc_attr( (float) $zone['price'] ); ?>">
                                            <?php echo esc_html( $zone['label'] ); ?> – <?php echo esc_html( $zone['max_km'] ); ?> km (<?php echo wp_kses_post( wc_price( (float) $zone['price'] ) ); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <p class="clr-hint">Die Lieferzone wird manuell gewählt; die Lieferadresse wird trotzdem verbindlich in der Bestellung gespeichert.</p>
                        <?php endif; ?>
                    <?php elseif ( $delivery_fee > 0 ) : ?>
                        <p>Transportpauschale je Fahrt: <?php echo wp_kses_post( wc_price( $delivery_fee ) ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="clr-price-preview" aria-live="polite">
                <div class="clr-rental-price-line">
                    <span class="clr-price-label"><?php esc_html_e( 'Mietpreis', 'patsch9-rental-engine' ); ?></span>
                    <strong id="clr_price_preview" class="clr-price-value">–</strong>
                </div>
                <small id="clr_duration_preview" class="clr-duration-preview"></small>
                <?php if ( $deposit > 0 ) : ?>
                    <div class="clr-deposit-notice">
                        <span class="clr-deposit-label"><?php esc_html_e( 'Kaution', 'patsch9-rental-engine' ); ?></span>
                        <strong><?php echo wp_kses_post( wc_price( $deposit ) ); ?> <?php esc_html_e( 'je Gerät', 'patsch9-rental-engine' ); ?></strong>
                        <?php if ( '' !== trim( $deposit_notice ) ) : ?><span class="clr-deposit-copy"><?php echo nl2br( esc_html( $deposit_notice ) ); ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php do_action( 'rmwc_frontend_after_deposit', $id, $deposit ); ?>
            </div>
        </div>
        <?php
    }

    public function ajax_availability() {
        check_ajax_referer( 'rmwc_frontend', 'nonce' );
        if ( ! $this->request_rate_allowed( 'availability', 120 ) || ! $this->global_rate_allowed( 'availability', 1200 ) ) {
            wp_send_json_error( [ 'message' => 'Zu viele Verfügbarkeitsabfragen. Bitte kurz warten.' ], 429 );
        }

        $product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
        if ( ! $product_id || ! $this->public_rental_product( $product_id ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Mietprodukt.' ], 400 );
        }

        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-01' );
        $to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-t', strtotime( '+2 months' ) );
        if ( ! $this->is_valid_date( $from ) || ! $this->is_valid_date( $to ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiger Kalenderzeitraum.' ], 400 );
        }
        $from_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, wp_timezone() );
        $to_date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, wp_timezone() );
        if ( ! $from_date || ! $to_date || $to_date < $from_date || $to_date > $from_date->modify( '+400 days' ) ) {
            wp_send_json_error( [ 'message' => 'Der angefragte Kalenderzeitraum ist zu groß.' ], 400 );
        }

        $from_at = $from . ' 00:00:00';
        $to_at   = $to . ' 23:59:59';
        $rows    = $this->booking_rows( $product_id, $from_at, $to_at );
        $items   = [];
        $buffer  = $this->buffer_hours( $product_id );

        foreach ( $rows as $row ) {
            try {
                $end = new DateTimeImmutable( $row['end_at'], wp_timezone() );
            } catch ( Exception $e ) {
                continue;
            }
            if ( $buffer ) {
                $end = $end->modify( '+' . $buffer . ' hours' );
            }
            $items[] = [
                'startAt' => (string) $row['start_at'],
                'endAt'   => $end->format( 'Y-m-d H:i:s' ),
                'qty'     => max( 1, (int) $row['quantity'] ),
            ];
        }

        wp_send_json_success( [
            'bookings'    => $items,
            'capacity'    => $this->capacity( $product_id ),
            'pickupTime'  => $this->pickup_time( $product_id ),
            'returnTime'  => $this->return_time( $product_id ),
            'minDays'     => max( 1, (int) get_post_meta( $product_id, '_clr_min_days', true ) ),
            'maxDays'     => max( 0, (int) get_post_meta( $product_id, '_clr_max_days', true ) ),
            'advanceDays' => max( 0, (int) get_post_meta( $product_id, '_clr_advance_days', true ) ),
            'bufferHours' => $buffer,
            'blockedPickupDays' => $this->blocked_pickup_days( $product_id ),
            'blockedReturnDays' => $this->blocked_return_days( $product_id ),
        ] );
    }

    private function build_interval( $product_id, $start_date, $end_date ) {
        if ( ! $this->is_valid_date( (string) $start_date ) || ! $this->is_valid_date( (string) $end_date ) ) {
            return new WP_Error( 'clr_dates', 'Bitte einen gültigen Mietzeitraum wählen.' );
        }

        $tz    = wp_timezone();
        $start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $start_date . ' ' . $this->pickup_time( $product_id ), $tz );
        $end   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $end_date . ' ' . $this->return_time( $product_id ), $tz );

        if ( ! $start || ! $end || $start->format( 'Y-m-d H:i' ) !== $start_date . ' ' . $this->pickup_time( $product_id ) || $end->format( 'Y-m-d H:i' ) !== $end_date . ' ' . $this->return_time( $product_id ) ) {
            return new WP_Error( 'clr_dates', 'Bitte einen gültigen Mietzeitraum wählen.' );
        }
        if ( $end <= $start ) {
            return new WP_Error( 'clr_order', 'Die Rückgabe muss zeitlich nach der Abholung liegen.' );
        }

        // A rental day is a configured calendar cycle (pickup time -> return
        // time), not a rigid 24-hour block. Calendar-date arithmetic also avoids
        // DST transitions turning a normal one-day rental into two charged days.
        $calendar_start = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $tz );
        $calendar_end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $tz );
        $calendar_days  = $calendar_start && $calendar_end ? (int) $calendar_start->diff( $calendar_end )->format( '%r%a' ) : 0;
        $days           = max( 1, $calendar_days );

        return [
            'start'      => $start,
            'end'        => $end,
            'start_at'   => $start->format( 'Y-m-d H:i:s' ),
            'end_at'     => $end->format( 'Y-m-d H:i:s' ),
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'days'       => $days,
        ];
    }

    private function rental_units( $product_id, $days ) {
        $unit = get_post_meta( $product_id, '_clr_billing_unit', true ) ?: 'day';
        if ( 'week' === $unit ) {
            return max( 1, (int) ceil( $days / 7 ) );
        }
        if ( 'month' === $unit ) {
            return max( 1, (int) ceil( $days / 30 ) );
        }
        return max( 1, (int) $days );
    }

    private function weekend_span_days( $start_day, $end_day ) {
        $start_day = min( 7, max( 1, (int) $start_day ) );
        $end_day   = min( 7, max( 1, (int) $end_day ) );
        $span      = ( $end_day - $start_day + 7 ) % 7;

        // The same weekday means the following week's occurrence, not zero days.
        return 0 === $span ? 7 : $span;
    }

    private function calculate_rent_price( $product_id, $days, $start_date, $end_date ) {
        $days = max( 1, (int) $days );

        if ( 'yes' === get_post_meta( $product_id, '_clr_weekend_enabled', true ) ) {
            $start         = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date );
            $end           = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date );
            $want_start    = (int) ( get_post_meta( $product_id, '_clr_weekend_start_day', true ) ?: 5 );
            $want_end      = (int) ( get_post_meta( $product_id, '_clr_weekend_end_day', true ) ?: 1 );
            $weekend_price = (float) get_post_meta( $product_id, '_clr_weekend_price', true );
            $weekend_days  = $this->weekend_span_days( $want_start, $want_end );
            if (
                $start &&
                $end &&
                $days === $weekend_days &&
                (int) $start->format( 'N' ) === $want_start &&
                (int) $end->format( 'N' ) === $want_end &&
                $weekend_price >= 0
            ) {
                return [ 'price' => $weekend_price, 'label' => 'Wochenendtarif', 'units' => 1 ];
            }
        }

        $rules = get_post_meta( $product_id, '_clr_pricing_rules', true );
        $rules = is_array( $rules ) ? $rules : [];
        $matches = [];
        foreach ( $rules as $rule ) {
            $min = max( 1, (int) ( $rule['min_days'] ?? 1 ) );
            $max = max( 0, (int) ( $rule['max_days'] ?? 0 ) );
            if ( $days >= $min && ( 0 === $max || $days <= $max ) ) {
                $matches[] = $rule;
            }
        }
        if ( $matches ) {
            usort( $matches, function( $a, $b ) {
                $amin = (int) ( $a['min_days'] ?? 1 );
                $bmin = (int) ( $b['min_days'] ?? 1 );
                if ( $amin !== $bmin ) {
                    return $bmin <=> $amin;
                }
                $amax = (int) ( $a['max_days'] ?? 0 );
                $bmax = (int) ( $b['max_days'] ?? 0 );
                if ( 0 === $amax ) $amax = PHP_INT_MAX;
                if ( 0 === $bmax ) $bmax = PHP_INT_MAX;
                return $amax <=> $bmax;
            } );
            $rule     = $matches[0];
            $included = max( 1, (int) ( $rule['included_days'] ?? $rule['min_days'] ?? 1 ) );
            $price    = max( 0, (float) ( $rule['package_price'] ?? 0 ) );
            if ( $days > $included && '' !== (string) ( $rule['extra_day_price'] ?? '' ) ) {
                $price += ( $days - $included ) * max( 0, (float) $rule['extra_day_price'] );
            }
            return [ 'price' => $price, 'label' => 'Preisstaffel', 'units' => 1 ];
        }

        $unit = get_post_meta( $product_id, '_clr_billing_unit', true ) ?: 'day';
        $weekday_prices = $this->weekday_prices( $product_id );
        if ( 'day' === $unit && $weekday_prices ) {
            $start = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
            if ( $start ) {
                $standard = max( 0, (float) get_post_meta( $product_id, '_clr_unit_price', true ) );
                $price = 0.0;
                $used_override = false;
                for ( $offset = 0; $offset < $days; $offset++ ) {
                    $rental_day = $start->modify( '+' . $offset . ' days' );
                    $weekday = (int) $rental_day->format( 'N' );
                    if ( array_key_exists( $weekday, $weekday_prices ) ) {
                        $price += max( 0, (float) $weekday_prices[ $weekday ] );
                        $used_override = true;
                    } else {
                        $price += $standard;
                    }
                }
                return [
                    'price' => $price,
                    'label' => $used_override ? 'Wochentagstarif' : 'Standardtarif',
                    'units' => $days,
                ];
            }
        }

        $units = $this->rental_units( $product_id, $days );
        $price = max( 0, (float) get_post_meta( $product_id, '_clr_unit_price', true ) ) * $units;
        return [ 'price' => $price, 'label' => 'Standardtarif', 'units' => $units ];
    }

    private function validate_interval_rules_for_product( $product_id, $start_date, $end_date ) {
        $interval = $this->build_interval( $product_id, $start_date, $end_date );
        if ( is_wp_error( $interval ) ) {
            return $interval;
        }

        $today = new DateTimeImmutable( 'today', wp_timezone() );
        $now   = new DateTimeImmutable( 'now', wp_timezone() );
        if ( $interval['start'] < $now ) {
            return new WP_Error( 'clr_past', 'Der Mietbeginn darf nicht in der Vergangenheit liegen.' );
        }

        $weekday_check = $this->validate_handover_weekdays( $product_id, $start_date, $end_date );
        if ( is_wp_error( $weekday_check ) ) {
            return $weekday_check;
        }

        $min = max( 1, (int) get_post_meta( $product_id, '_clr_min_days', true ) );
        $max = max( 0, (int) get_post_meta( $product_id, '_clr_max_days', true ) );
        if ( $interval['days'] < $min ) {
            return new WP_Error( 'clr_min', sprintf( 'Die Mindestmietdauer beträgt %d Miettag(e).', $min ) );
        }
        if ( $max && $interval['days'] > $max ) {
            return new WP_Error( 'clr_max', sprintf( 'Die maximale Mietdauer beträgt %d Miettag(e).', $max ) );
        }

        $advance = max( 0, (int) get_post_meta( $product_id, '_clr_advance_days', true ) );
        if ( $advance && $interval['start'] > $today->modify( '+' . $advance . ' days' )->setTime( 23, 59, 59 ) ) {
            return new WP_Error( 'clr_advance', 'Dieser Zeitraum liegt außerhalb des erlaubten Buchungsfensters.' );
        }

        return $interval;
    }

    private function validate_interval_for_product( $product_id, $start_date, $end_date, $quantity = 1, $exclude_order = 0, $extra_intervals = [], $exclude_booking = 0 ) {
        $interval = $this->validate_interval_rules_for_product( $product_id, $start_date, $end_date );
        if ( is_wp_error( $interval ) ) {
            return $interval;
        }

        $requested = array_merge( $extra_intervals, [ [
            'start_at' => $interval['start_at'],
            'end_at'   => $interval['end_at'],
            'quantity' => max( 1, (int) $quantity ),
        ] ] );

        if ( ! $this->capacity_available( $product_id, $requested, $exclude_order, $exclude_booking ) ) {
            return new WP_Error( 'clr_unavailable', 'Für den gewählten Zeitraum ist nicht mehr genügend Bestand verfügbar.' );
        }

        return $interval;
    }

    private function booking_rows( $product_id, $from_at, $to_at, $exclude_order = 0, $exclude_booking = 0 ) {
        global $wpdb;
        $table  = $this->table();
        $buffer = $this->buffer_hours( $product_id );
        if ( $exclude_order ) {
            $sql = $wpdb->prepare(
                "SELECT * FROM %i WHERE product_id = %d AND status IN ('reserved','blocked') AND start_at IS NOT NULL AND end_at IS NOT NULL AND start_at < %s AND DATE_ADD(end_at, INTERVAL %d HOUR) > %s AND order_id != %d AND id != %d ORDER BY start_at",
                $table,
                $product_id,
                $to_at,
                $buffer,
                $from_at,
                $exclude_order,
                absint( $exclude_booking )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM %i WHERE product_id = %d AND status IN ('reserved','blocked') AND start_at IS NOT NULL AND end_at IS NOT NULL AND start_at < %s AND DATE_ADD(end_at, INTERVAL %d HOUR) > %s AND id != %d ORDER BY start_at",
                $table,
                $product_id,
                $to_at,
                $buffer,
                $from_at,
                absint( $exclude_booking )
            );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared in every branch immediately above; availability uses the plugin's own transactional table and must be current.
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    private function capacity_available( $product_id, array $requested_intervals, $exclude_order = 0, $exclude_booking = 0 ) {
        if ( ! $requested_intervals ) {
            return true;
        }

        $starts = array_column( $requested_intervals, 'start_at' );
        $buffer = $this->buffer_hours( $product_id );
        $ends   = [];
        foreach ( $requested_intervals as $interval ) {
            $requested_end = new DateTimeImmutable( $interval['end_at'], wp_timezone() );
            if ( $buffer ) {
                $requested_end = $requested_end->modify( '+' . $buffer . ' hours' );
            }
            $ends[] = $requested_end->format( 'Y-m-d H:i:s' );
        }
        sort( $starts );
        sort( $ends );
        $from = reset( $starts );
        $to   = end( $ends );

        $events = [];
        foreach ( $this->booking_rows( $product_id, $from, $to, $exclude_order, $exclude_booking ) as $row ) {
            $end = new DateTimeImmutable( $row['end_at'], wp_timezone() );
            if ( $buffer ) {
                $end = $end->modify( '+' . $buffer . ' hours' );
            }
            $events[] = [ 'time' => $row['start_at'], 'delta' => (int) $row['quantity'], 'kind' => 1 ];
            $events[] = [ 'time' => $end->format( 'Y-m-d H:i:s' ), 'delta' => - (int) $row['quantity'], 'kind' => -1 ];
        }

        foreach ( $requested_intervals as $interval ) {
            $qty = max( 1, (int) ( $interval['quantity'] ?? 1 ) );
            $requested_end = new DateTimeImmutable( $interval['end_at'], wp_timezone() );
            if ( $buffer ) {
                $requested_end = $requested_end->modify( '+' . $buffer . ' hours' );
            }
            $events[] = [ 'time' => $interval['start_at'], 'delta' => $qty, 'kind' => 1 ];
            $events[] = [ 'time' => $requested_end->format( 'Y-m-d H:i:s' ), 'delta' => -$qty, 'kind' => -1 ];
        }

        usort( $events, function( $a, $b ) {
            if ( $a['time'] === $b['time'] ) {
                // End events first, so return at 10:00 and new start at 10:00 do not overlap.
                return $a['kind'] <=> $b['kind'];
            }
            return strcmp( $a['time'], $b['time'] );
        } );

        $load = 0;
        $capacity = $this->capacity( $product_id );
        foreach ( $events as $event ) {
            $load += $event['delta'];
            if ( $load > $capacity ) {
                return false;
            }
        }
        return true;
    }

    private function insert_booking_with_lock( $product_id, array $data, array $interval ) {
        global $wpdb;
        $lock_name = $this->booking_lock_name( $product_id );
        $got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Booking advisory locks must reflect current database state and are intentionally not cached.
        if ( 1 !== $got_lock ) {
            return new WP_Error( 'clr_booking_lock', 'Der Mietbestand konnte gerade nicht sicher gesperrt werden. Bitte erneut versuchen.' );
        }
        try {
            if ( ! $this->capacity_available( $product_id, [ $interval ] ) ) {
                return new WP_Error( 'clr_booking_capacity', 'Für diesen Zeitraum ist nicht genügend Kapazität frei.' );
            }
            $ok = $wpdb->insert( $this->table(), $data, [ '%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's transactional booking table must not be cached.
            if ( false === $ok ) {
                return new WP_Error( 'clr_booking_database', 'Der Zeitraum konnte nicht gespeichert werden. Bitte erneut versuchen.' );
            }
            return (int) $wpdb->insert_id;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the matching booking advisory lock immediately; caching is invalid here.
        }
    }

    private function cart_intervals_for_product( $product_id ) {
        $intervals = [];
        if ( ! WC()->cart ) {
            return $intervals;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['clr_rental'] ) || (int) $item['clr_rental']['product_id'] !== (int) $product_id ) {
                continue;
            }
            $r = $item['clr_rental'];
            if ( empty( $r['start_at'] ) || empty( $r['end_at'] ) ) {
                continue;
            }
            $intervals[] = [
                'start_at' => sanitize_text_field( (string) $r['start_at'] ),
                'end_at'   => sanitize_text_field( (string) $r['end_at'] ),
                'quantity' => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
            ];
        }
        return $intervals;
    }

    private function google_routes_api_key() {
        if ( defined( 'RMWC_GOOGLE_ROUTES_API_KEY' ) && is_scalar( RMWC_GOOGLE_ROUTES_API_KEY ) ) {
            return $this->limit_text( sanitize_text_field( trim( (string) RMWC_GOOGLE_ROUTES_API_KEY ) ), 255 );
        }

        return $this->limit_text( sanitize_text_field( trim( (string) get_option( 'clr_google_routes_api_key', '' ) ) ), 255 );
    }

    private function distance_api_ready() {
        return 'yes' === get_option( 'clr_google_routes_enabled', 'no' ) && (bool) ( $this->google_routes_api_key() && trim( (string) get_option( 'clr_delivery_origin', '' ) ) );
    }

    private function get_delivery_zones( $product_id ) {
        $zones = get_post_meta( $product_id, '_clr_delivery_zones', true );
        return is_array( $zones ) ? array_values( $zones ) : [];
    }

    private function match_delivery_zone( $product_id, $distance_km ) {
        foreach ( $this->get_delivery_zones( $product_id ) as $index => $zone ) {
            if ( $distance_km <= (float) $zone['max_km'] ) {
                return [
                    'index'  => $index,
                    'label'  => $zone['label'],
                    'max_km' => (float) $zone['max_km'],
                    'price'  => (float) $zone['price'],
                ];
            }
        }
        return new WP_Error( 'clr_delivery_range', 'Diese Adresse liegt außerhalb des konfigurierten Liefergebiets.' );
    }

    private function google_route_distance_km( $destination ) {
        if ( ! $this->distance_api_ready() ) {
            return new WP_Error( 'clr_route_not_configured', 'Die automatische Entfernungsberechnung ist nicht aktiviert.' );
        }
        $api_key = $this->google_routes_api_key();
        $origin  = $this->sanitize_address( get_option( 'clr_delivery_origin', '' ) );
        $destination = $this->sanitize_address( $destination );
        if ( strlen( $destination ) < 5 ) {
            return new WP_Error( 'clr_destination', 'Bitte eine vollständige Lieferadresse angeben.' );
        }

        // Build a non-reversible destination fingerprint only for the short-lived
        // database advisory lock below. Route responses are deliberately not
        // cached locally because Google Maps Platform restricts storage of most
        // Routes API content.
        $route_material    = strtolower( $origin . '|' . $destination );
        $route_fingerprint = substr( hash_hmac( 'sha256', $route_material, wp_salt( 'auth' ) ), 0, 32 );

        global $wpdb;
        $route_scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $route_fingerprint;
        $route_lock  = 'clr_route_' . substr( hash( 'sha256', $route_scope ), 0, 38 );
        $route_got   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $route_lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents concurrent duplicate paid route requests for one destination.
        if ( 1 !== $route_got ) {
            return new WP_Error( 'clr_route_busy', 'Die automatische Entfernungsberechnung ist gerade ausgelastet. Bitte kurz erneut versuchen.' );
        }

        try {
            // Protect the paid third-party API from distributed abuse. Every
            // successful quote is a fresh outbound request; responses are not
            // persisted locally.
            $hourly_limit = (int) apply_filters( 'rmwc_routes_hourly_limit', 60 );
            $hourly_limit = max( 10, min( 1000, $hourly_limit ) );
            if ( ! $this->global_rate_allowed( 'routes_outbound', $hourly_limit, HOUR_IN_SECONDS ) ) {
                return new WP_Error( 'clr_route_rate', 'Die automatische Entfernungsberechnung ist vorübergehend ausgelastet. Bitte später erneut versuchen.' );
            }

            $response = wp_safe_remote_post( 'https://routes.googleapis.com/directions/v2:computeRoutes', [
                'timeout'             => 12,
                'redirection'         => 0,
                'limit_response_size' => 1048576,
                'headers'             => [
                    'Content-Type'     => 'application/json; charset=utf-8',
                    'X-Goog-Api-Key'   => $api_key,
                    'X-Goog-FieldMask' => 'routes.distanceMeters',
                ],
                'body'                => wp_json_encode( [
                    'origin'      => [ 'address' => $origin ],
                    'destination' => [ 'address' => $destination ],
                    'travelMode'  => 'DRIVE',
                    'units'       => 'METRIC',
                ] ),
            ] );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'clr_route_http', 'Die Lieferentfernung konnte derzeit nicht ermittelt werden.' );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $data = is_string( $body ) && strlen( $body ) <= 1048576 ? json_decode( $body, true, 32 ) : null;
            if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['routes'][0]['distanceMeters'] ) || ! is_numeric( $data['routes'][0]['distanceMeters'] ) ) {
                return new WP_Error( 'clr_route_api', 'Die Lieferadresse konnte nicht zuverlässig berechnet werden. Bitte Adresse prüfen oder Abholung wählen.' );
            }
            $distance = round( max( 0, (float) $data['routes'][0]['distanceMeters'] ) / 1000, 1 );
            if ( $distance > 10000 ) {
                return new WP_Error( 'clr_route_api', 'Die ermittelte Lieferentfernung ist nicht plausibel.' );
            }
            return $distance;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $route_lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the per-route request lock.
        }
    }

    public function ajax_delivery_quote() {
        check_ajax_referer( 'rmwc_frontend', 'nonce' );
        if ( ! $this->request_rate_allowed( 'delivery_quote', 20 ) || ! $this->global_rate_allowed( 'delivery_quote', 180 ) ) {
            wp_send_json_error( [ 'message' => 'Zu viele Entfernungsabfragen. Bitte später erneut versuchen.' ], 429 );
        }
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        $address    = $this->sanitize_address( isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '' );
        if ( ! $product_id || ! $this->public_rental_product( $product_id ) || 'yes' !== get_post_meta( $product_id, '_clr_allow_delivery', true ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Mietprodukt oder Lieferung nicht aktiviert.' ], 400 );
        }
        if ( ! $this->distance_api_ready() ) {
            wp_send_json_error( [ 'message' => 'Automatische Entfernungsberechnung ist nicht aktiviert.' ], 400 );
        }
        if ( strlen( $address ) < 5 || strlen( $address ) > 250 ) {
            wp_send_json_error( [ 'message' => 'Bitte eine vollständige Lieferadresse angeben.' ], 400 );
        }
        $distance = $this->google_route_distance_km( $address );
        if ( is_wp_error( $distance ) ) {
            wp_send_json_error( [ 'message' => $distance->get_error_message() ], 400 );
        }
        $zone = $this->match_delivery_zone( $product_id, $distance );
        if ( is_wp_error( $zone ) ) {
            wp_send_json_error( [ 'message' => $zone->get_error_message(), 'distance' => $distance ], 400 );
        }
        wp_send_json_success( [
            'distance'  => $distance,
            'zone'      => $zone,
            'priceText' => html_entity_decode( wp_strip_all_tags( wc_price( $zone['price'] ) ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ),
        ] );
    }

    private function delivery_data_from_request( $product_id, $address_input = '', $zone_input = '' ) {
        if ( 'yes' !== get_post_meta( $product_id, '_clr_allow_delivery', true ) ) {
            return new WP_Error( 'clr_delivery_disabled', 'Für dieses Produkt ist keine Lieferung möglich.' );
        }

        $zones   = $this->get_delivery_zones( $product_id );
        $address = $this->sanitize_address( sanitize_text_field( (string) $address_input ) );
        if ( strlen( $address ) < 5 || strlen( $address ) > 250 ) {
            return new WP_Error( 'clr_destination', 'Bitte eine vollständige Lieferadresse angeben.' );
        }
        if ( ! $zones ) {
            return [
                'fee'        => max( 0, (float) get_post_meta( $product_id, '_clr_delivery_fee', true ) ),
                'zone'       => 'Lieferpauschale',
                'zone_index' => '',
                'distance'   => '',
                'address'    => $address,
            ];
        }

        if ( $this->distance_api_ready() ) {
            if ( strlen( $address ) < 5 ) {
                return new WP_Error( 'clr_destination', 'Bitte eine vollständige Lieferadresse angeben.' );
            }
            // Add-to-cart is another server-side quote path; rate-limit it as well.
            if ( ! $this->request_rate_allowed( 'delivery_cart', 20 ) || ! $this->global_rate_allowed( 'delivery_cart', 180 ) ) {
                return new WP_Error( 'clr_rate', 'Zu viele Entfernungsabfragen. Bitte später erneut versuchen.' );
            }
            $distance = $this->google_route_distance_km( $address );
            if ( is_wp_error( $distance ) ) {
                return $distance;
            }
            $zone = $this->match_delivery_zone( $product_id, $distance );
            if ( is_wp_error( $zone ) ) {
                return $zone;
            }
            return [ 'fee' => $zone['price'], 'zone' => $zone['label'], 'zone_index' => $zone['index'], 'distance' => $distance, 'address' => $address ];
        }

        $zone_raw = trim( sanitize_text_field( (string) $zone_input ) );
        if ( '' === $zone_raw || ! ctype_digit( $zone_raw ) ) {
            return new WP_Error( 'clr_delivery_zone', 'Bitte eine gültige Lieferzone auswählen.' );
        }
        $zone_index = absint( $zone_raw );
        if ( ! isset( $zones[ $zone_index ] ) ) {
            return new WP_Error( 'clr_delivery_zone', 'Bitte eine gültige Lieferzone wählen.' );
        }
        return [
            'fee'      => (float) $zones[ $zone_index ]['price'],
            'zone'       => sanitize_text_field( $zones[ $zone_index ]['label'] ),
            'zone_index' => $zone_index,
            'distance'   => '',
            'address'  => $address,
        ];
    }

    /** Verify the signed rental form request for a product. */
    public function frontend_rental_nonce_valid( $product_id ) {
        $nonce = isset( $_POST['clr_rental_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_rental_nonce'] ) ) : '';
        return $nonce && wp_verify_nonce( $nonce, 'clr_add_rental_' . absint( $product_id ) );
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        $config_id = $this->config_product_id( $product_id, $variation_id );
        if ( ! $config_id ) {
            return $passed;
        }

        $rental_nonce = isset( $_POST['clr_rental_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_rental_nonce'] ) ) : '';
        if ( ! $rental_nonce || ! wp_verify_nonce( $rental_nonce, 'clr_add_rental_' . absint( $product_id ) ) ) {
            wc_add_notice( 'Die Mietdaten konnten nicht verifiziert werden. Bitte Seite neu laden.', 'error' );
            return false;
        }

        $start = sanitize_text_field( wp_unslash( $_POST['clr_start_date'] ?? '' ) );
        $end   = sanitize_text_field( wp_unslash( $_POST['clr_end_date'] ?? '' ) );
        $extra = $this->cart_intervals_for_product( $config_id );
        $valid = $this->validate_interval_for_product( $config_id, $start, $end, max( 1, (int) $quantity ), 0, $extra );
        if ( is_wp_error( $valid ) ) {
            wc_add_notice( $valid->get_error_message(), 'error' );
            return false;
        }

        $fulfilment = isset( $_POST['clr_fulfilment'] ) ? sanitize_key( wp_unslash( $_POST['clr_fulfilment'] ) ) : '';
        $pickup_ok    = 'yes' === get_post_meta( $config_id, '_clr_allow_pickup', true );
        $delivery_ok  = 'yes' === get_post_meta( $config_id, '_clr_allow_delivery', true );
        $delivery_return_ok = 'yes' === get_post_meta( $config_id, '_clr_allow_delivery_return', true );
        if ( ( $pickup_ok || $delivery_ok || $delivery_return_ok ) && ! in_array( $fulfilment, [ 'pickup', 'delivery', 'delivery_return' ], true ) ) {
            wc_add_notice( 'Bitte eine gültige Transport-/Übergabeart wählen.', 'error' );
            return false;
        }
        if ( 'pickup' === $fulfilment && ! $pickup_ok ) {
            wc_add_notice( 'Abholung und Rückgabe durch den Kunden ist für dieses Produkt nicht verfügbar.', 'error' );
            return false;
        }
        if ( in_array( $fulfilment, [ 'delivery', 'delivery_return' ], true ) ) {
            if ( 'delivery' === $fulfilment && ! $delivery_ok ) {
                wc_add_notice( 'Lieferung durch den Vermieter ist für dieses Produkt nicht verfügbar.', 'error' );
                return false;
            }
            if ( 'delivery_return' === $fulfilment && ! $delivery_return_ok ) {
                wc_add_notice( 'Lieferung und Abholung durch den Vermieter ist für dieses Produkt nicht verfügbar.', 'error' );
                return false;
            }
            $delivery_address = isset( $_POST['clr_delivery_address'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_address'] ) ) : '';
            $delivery_zone    = isset( $_POST['clr_delivery_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_zone'] ) ) : '';
            $delivery = $this->delivery_data_from_request( $config_id, $delivery_address, $delivery_zone );
            if ( is_wp_error( $delivery ) ) {
                wc_add_notice( $delivery->get_error_message(), 'error' );
                return false;
            }
        }

        return $passed;
    }

    public function add_cart_item_data( $data, $product_id, $variation_id ) {
        $config_id = $this->config_product_id( $product_id, $variation_id );
        if ( ! $config_id ) {
            return $data;
        }
        // The same signed form must still be valid when cart-item metadata is built.
        $rental_nonce = isset( $_POST['clr_rental_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_rental_nonce'] ) ) : '';
        if ( ! $rental_nonce || ! wp_verify_nonce( $rental_nonce, 'clr_add_rental_' . absint( $product_id ) ) ) {
            return $data;
        }

        $start    = sanitize_text_field( wp_unslash( $_POST['clr_start_date'] ?? '' ) );
        $end      = sanitize_text_field( wp_unslash( $_POST['clr_end_date'] ?? '' ) );
        $interval = $this->build_interval( $config_id, $start, $end );
        if ( is_wp_error( $interval ) ) {
            return $data;
        }

        $pricing = $this->calculate_rent_price( $config_id, $interval['days'], $start, $end );
        $rental  = [
            'product_id'    => $config_id,
            'start'         => $start,
            'end'           => $end,
            'start_at'      => $interval['start_at'],
            'end_at'        => $interval['end_at'],
            'days'          => $interval['days'],
            'units'         => $pricing['units'],
            'rent_price'    => $pricing['price'],
            'pricing_label' => $pricing['label'],
            'addons'        => [],
            'addon_indexes'  => [],
            'fulfilment'    => '',
            'delivery_fee'  => 0,
            'transport_legs' => 0,
            'delivery_zone' => '',
            'delivery_zone_index' => '',
            'delivery_distance' => '',
            'delivery_address'  => '',
            'deposit'       => (float) get_post_meta( $config_id, '_clr_deposit', true ),
            'pickup_time'   => $this->pickup_time( $config_id ),
            'return_time'   => $this->return_time( $config_id ),
        ];

        $addons   = get_post_meta( $config_id, '_clr_addons', true );
        $addons   = is_array( $addons ) ? $addons : [];
        $selected_raw = isset( $_POST['clr_addon'] ) ? map_deep( wp_unslash( $_POST['clr_addon'] ), 'sanitize_text_field' ) : [];
        $selected_raw = is_array( $selected_raw ) ? $selected_raw : [];
        $selected = [];
        foreach ( array_slice( $selected_raw, 0, 50 ) as $selected_value ) {
            $selected_value = is_scalar( $selected_value ) ? trim( (string) $selected_value ) : '';
            if ( '' === $selected_value || ! ctype_digit( $selected_value ) ) {
                continue;
            }
            $selected[] = absint( $selected_value );
        }
        $selected = array_values( array_unique( $selected ) );
        foreach ( $selected as $idx ) {
            if ( ! isset( $addons[ $idx ] ) ) {
                continue;
            }
            $addon = $addons[ $idx ];
            $rental['addon_indexes'][] = $idx;
            $rental['addons'][] = [
                'group' => sanitize_text_field( $addon['group'] ),
                'label' => sanitize_text_field( $addon['label'] ),
                'price' => (float) $addon['price'],
                'mode'  => in_array( $addon['mode'], [ 'once', 'unit', 'day' ], true ) ? $addon['mode'] : 'once',
            ];
        }

        $fulfilment = sanitize_key( wp_unslash( $_POST['clr_fulfilment'] ?? '' ) );
        $pickup      = 'yes' === get_post_meta( $config_id, '_clr_allow_pickup', true );
        $delivery_ok = 'yes' === get_post_meta( $config_id, '_clr_allow_delivery', true );
        $delivery_return_ok = 'yes' === get_post_meta( $config_id, '_clr_allow_delivery_return', true );
        if ( in_array( $fulfilment, [ 'delivery', 'delivery_return' ], true )
            && ( ( 'delivery' === $fulfilment && $delivery_ok ) || ( 'delivery_return' === $fulfilment && $delivery_return_ok ) ) ) {
            $delivery_address = isset( $_POST['clr_delivery_address'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_address'] ) ) : '';
            $delivery_zone    = isset( $_POST['clr_delivery_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_zone'] ) ) : '';
            $delivery = $this->delivery_data_from_request( $config_id, $delivery_address, $delivery_zone );
            if ( ! is_wp_error( $delivery ) ) {
                $legs = 'delivery_return' === $fulfilment ? 2 : 1;
                $rental['fulfilment']       = $fulfilment;
                $rental['transport_legs']   = $legs;
                $rental['delivery_fee']     = (float) $delivery['fee'] * $legs;
                $rental['delivery_zone']    = $delivery['zone'];
                $rental['delivery_zone_index'] = $delivery['zone_index'] ?? '';
                $rental['delivery_distance']= $delivery['distance'];
                $rental['delivery_address'] = $delivery['address'];
            }
        } elseif ( $pickup ) {
            $rental['fulfilment'] = 'pickup';
            $rental['transport_legs'] = 0;
        } elseif ( $delivery_ok || $delivery_return_ok ) {
            $fallback_mode = $delivery_ok ? 'delivery' : 'delivery_return';
            $delivery_address = isset( $_POST['clr_delivery_address'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_address'] ) ) : '';
            $delivery_zone    = isset( $_POST['clr_delivery_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_zone'] ) ) : '';
            $delivery = $this->delivery_data_from_request( $config_id, $delivery_address, $delivery_zone );
            if ( ! is_wp_error( $delivery ) ) {
                $legs = 'delivery_return' === $fallback_mode ? 2 : 1;
                $rental['fulfilment'] = $fallback_mode;
                $rental['transport_legs'] = $legs;
                $rental['delivery_fee'] = (float) $delivery['fee'] * $legs;
                $rental['delivery_zone'] = $delivery['zone'];
                $rental['delivery_zone_index'] = $delivery['zone_index'] ?? '';
                $rental['delivery_distance'] = $delivery['distance'];
                $rental['delivery_address'] = $delivery['address'];
            }
        }

        $data['clr_rental'] = $rental;
        $data['unique_key'] = md5( wp_json_encode( $rental ) . microtime( true ) );
        return $data;
    }

    private function fulfilment_label( $mode ) {
        $mode = sanitize_key( (string) $mode );
        $labels = [
            'pickup'          => __( 'Abholung und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
            'delivery'        => __( 'Lieferung durch Vermieter und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
            'delivery_return' => __( 'Lieferung und Abholung durch Vermieter', 'patsch9-rental-engine' ),
        ];
        return $labels[ $mode ] ?? '';
    }

    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item['clr_rental'] ) ) {
            return $item_data;
        }
        $r = $cart_item['clr_rental'];
        $item_data[] = [ 'key' => 'Mietbeginn', 'value' => $this->format_datetime_display( $r['start_at'] ) ];
        $item_data[] = [ 'key' => 'Rückgabe', 'value' => $this->format_datetime_display( $r['end_at'] ) ];
        $item_data[] = [ 'key' => 'Mietdauer', 'value' => sprintf( '%d Miettag(e) – %s', (int) $r['days'], $r['pricing_label'] ) ];
        foreach ( $r['addons'] as $addon ) {
            $item_data[] = [ 'key' => $addon['group'], 'value' => $addon['label'] ];
        }
        if ( ! empty( $r['fulfilment'] ) ) {
            $item_data[] = [ 'key' => 'Transport / Übergabe', 'value' => $this->fulfilment_label( $r['fulfilment'] ) ];
        }
        if ( in_array( sanitize_key( $r['fulfilment'] ?? '' ), [ 'delivery', 'delivery_return' ], true ) && ! empty( $r['delivery_zone'] ) ) {
            $value = $r['delivery_zone'];
            if ( '' !== (string) $r['delivery_distance'] ) {
                $value .= ' (' . wc_format_localized_decimal( $r['delivery_distance'] ) . ' km je Fahrt)';
            }
            if ( 'delivery_return' === sanitize_key( $r['fulfilment'] ?? '' ) ) {
                $value .= ' – Hin- und Rückfahrt';
            }
            $item_data[] = [ 'key' => 'Lieferzone', 'value' => $value ];
        }
        if ( ! empty( $r['delivery_address'] ) ) {
            $item_data[] = [ 'key' => 'Liefer-/Abholadresse', 'value' => $r['delivery_address'] ];
        }
        if ( (float) ( $r['deposit'] ?? 0 ) > 0 ) {
            $qty            = max( 1, absint( $cart_item['quantity'] ?? 1 ) );
            $deposit_total  = (float) $r['deposit'] * $qty;
            $deposit_amount = html_entity_decode(
                wp_strip_all_tags( wc_price( $deposit_total ) ),
                ENT_QUOTES | ENT_HTML5,
                get_bloginfo( 'charset' ) ?: 'UTF-8'
            );
            $deposit_mode = sanitize_key( $r['deposit_mode'] ?? 'choice' );

            if ( 'online' === $deposit_mode ) {
                $deposit_text = sprintf(
                    /* translators: %s: formatted refundable deposit amount. */
                    __( '%s – online mit der Bestellung hinterlegen', 'patsch9-rental-engine' ),
                    $deposit_amount
                );
            } elseif ( 'cash' === $deposit_mode ) {
                $deposit_text = sprintf(
                    /* translators: %s: formatted refundable deposit amount. */
                    __( '%s – separat: Überweisung oder bar bei Abholung', 'patsch9-rental-engine' ),
                    $deposit_amount
                );
            } else {
                $deposit_text = sprintf(
                    /* translators: %s: formatted refundable deposit amount. */
                    __( '%s – Auswahl im Checkout: online oder separat (Überweisung / bar bei Abholung)', 'patsch9-rental-engine' ),
                    $deposit_amount
                );
            }

            $item_data[] = [
                'key'   => 'Kaution',
                'value' => $deposit_text,
            ];
        }
        return $item_data;
    }

    private function format_datetime_display( $mysql_datetime ) {
        try {
            $dt = new DateTimeImmutable( $mysql_datetime, wp_timezone() );
            return wp_date( 'd.m.Y H:i', $dt->getTimestamp(), wp_timezone() ) . ' Uhr';
        } catch ( Exception $e ) {
            return $mysql_datetime;
        }
    }

    private function rehydrate_cart_fulfilment( $product_id, array $rental ) {
        $mode = sanitize_key( $rental['fulfilment'] ?? '' );
        $pickup_allowed   = 'yes' === get_post_meta( $product_id, '_clr_allow_pickup', true );
        $delivery_allowed = 'yes' === get_post_meta( $product_id, '_clr_allow_delivery', true );
        $delivery_return_allowed = 'yes' === get_post_meta( $product_id, '_clr_allow_delivery_return', true );

        if ( 'pickup' === $mode ) {
            if ( ! $pickup_allowed ) {
                return new WP_Error( 'clr_cart_pickup', 'Abholung und Rückgabe durch den Kunden ist für diesen Mietartikel nicht mehr verfügbar.' );
            }
            return [ 'fulfilment' => 'pickup', 'transport_legs' => 0, 'delivery_fee' => 0.0, 'delivery_zone' => '', 'delivery_zone_index' => '', 'delivery_distance' => '', 'delivery_address' => '' ];
        }

        if ( ! in_array( $mode, [ 'delivery', 'delivery_return' ], true ) ) {
            if ( $pickup_allowed || $delivery_allowed || $delivery_return_allowed ) {
                return new WP_Error( 'clr_cart_fulfilment', 'Bitte die Transport-/Übergabeart für den Mietartikel erneut auswählen.' );
            }
            return [ 'fulfilment' => '' ];
        }

        if ( 'delivery' === $mode && ! $delivery_allowed ) {
            return new WP_Error( 'clr_cart_delivery', 'Lieferung durch den Vermieter ist für diesen Mietartikel nicht mehr verfügbar.' );
        }
        if ( 'delivery_return' === $mode && ! $delivery_return_allowed ) {
            return new WP_Error( 'clr_cart_delivery_return', 'Lieferung und Abholung durch den Vermieter ist für diesen Mietartikel nicht mehr verfügbar.' );
        }

        $legs = 'delivery_return' === $mode ? 2 : 1;
        $address = $this->sanitize_address( $rental['delivery_address'] ?? '' );
        if ( strlen( $address ) < 5 || strlen( $address ) > 250 ) {
            return new WP_Error( 'clr_cart_delivery_address', 'Die Liefer-/Abholadresse des Mietartikels ist ungültig. Bitte Artikel neu konfigurieren.' );
        }
        $zones = $this->get_delivery_zones( $product_id );
        if ( ! $zones ) {
            return [
                'fulfilment' => $mode,
                'transport_legs' => $legs,
                'delivery_fee' => max( 0, (float) get_post_meta( $product_id, '_clr_delivery_fee', true ) ) * $legs,
                'delivery_zone' => 'Transportpauschale',
                'delivery_zone_index' => '',
                'delivery_distance' => '',
                'delivery_address' => $address,
            ];
        }
        $distance_raw = $rental['delivery_distance'] ?? '';
        if ( '' !== (string) $distance_raw && is_numeric( $distance_raw ) ) {
            $zone = $this->match_delivery_zone( $product_id, max( 0, (float) $distance_raw ) );
            if ( is_wp_error( $zone ) ) {
                return $zone;
            }
            return [
                'fulfilment' => $mode,
                'transport_legs' => $legs,
                'delivery_fee' => (float) $zone['price'] * $legs,
                'delivery_zone' => sanitize_text_field( $zone['label'] ),
                'delivery_zone_index' => (int) $zone['index'],
                'delivery_distance' => (float) $distance_raw,
                'delivery_address' => $address,
            ];
        }
        $zone_index_raw = $rental['delivery_zone_index'] ?? '';
        if ( '' === (string) $zone_index_raw || ! ctype_digit( (string) $zone_index_raw ) || ! isset( $zones[ (int) $zone_index_raw ] ) ) {
            return new WP_Error( 'clr_cart_delivery_zone', 'Die Lieferzone des Mietartikels ist nicht mehr gültig. Bitte Artikel neu konfigurieren.' );
        }
        $zone_index = (int) $zone_index_raw;
        return [
            'fulfilment' => $mode,
            'transport_legs' => $legs,
            'delivery_fee' => max( 0, (float) $zones[ $zone_index ]['price'] ) * $legs,
            'delivery_zone' => sanitize_text_field( $zones[ $zone_index ]['label'] ),
            'delivery_zone_index' => $zone_index,
            'delivery_distance' => '',
            'delivery_address' => $address,
        ];
    }

    public function set_cart_item_prices( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $cart_item_key => $item ) {
            if ( empty( $item['clr_rental'] ) || empty( $item['data'] ) ) {
                continue;
            }
            $r          = $item['clr_rental'];
            $product_id = absint( $r['product_id'] ?? 0 );
            if ( ! $product_id || ! $this->is_rental( $product_id ) ) {
                continue;
            }
            $interval = $this->build_interval( $product_id, (string) ( $r['start'] ?? '' ), (string) ( $r['end'] ?? '' ) );
            if ( is_wp_error( $interval ) ) {
                continue;
            }
            $pricing = $this->calculate_rent_price( $product_id, $interval['days'], $interval['start_date'], $interval['end_date'] );
            $price   = max( 0, (float) $pricing['price'] );

            $configured_addons = get_post_meta( $product_id, '_clr_addons', true );
            $configured_addons = is_array( $configured_addons ) ? $configured_addons : [];
            $indexes = isset( $r['addon_indexes'] ) && is_array( $r['addon_indexes'] ) ? array_slice( array_unique( array_map( 'absint', $r['addon_indexes'] ) ), 0, 50 ) : [];
            // Backward-compatible recovery for carts created with 1.0.0.
            if ( ! $indexes && ! empty( $r['addons'] ) && is_array( $r['addons'] ) ) {
                foreach ( $r['addons'] as $old_addon ) {
                    foreach ( $configured_addons as $idx => $configured ) {
                        if ( ( $old_addon['group'] ?? '' ) === ( $configured['group'] ?? '' ) && ( $old_addon['label'] ?? '' ) === ( $configured['label'] ?? '' ) ) {
                            $indexes[] = (int) $idx;
                            break;
                        }
                    }
                }
            }
            $canonical_addons = [];
            foreach ( array_unique( $indexes ) as $idx ) {
                if ( ! isset( $configured_addons[ $idx ] ) ) {
                    continue;
                }
                $addon = $configured_addons[ $idx ];
                $mode = in_array( $addon['mode'] ?? 'once', [ 'once', 'unit', 'day' ], true ) ? $addon['mode'] : 'once';
                $addon_price = max( 0, (float) ( $addon['price'] ?? 0 ) );
                $multiplier = 'unit' === $mode ? max( 1, (int) $pricing['units'] ) : ( 'day' === $mode ? max( 1, (int) $interval['days'] ) : 1 );
                $price += $addon_price * $multiplier;
                $canonical_addons[] = [
                    'group' => sanitize_text_field( $addon['group'] ?? '' ),
                    'label' => sanitize_text_field( $addon['label'] ?? '' ),
                    'price' => $addon_price,
                    'mode'  => $mode,
                ];
            }

            $item['data']->set_price( max( 0, $price ) );
            // Rehydrate security-sensitive values from authoritative product configuration.
            if ( isset( $cart->cart_contents[ $cart_item_key ]['clr_rental'] ) ) {
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['start_at']      = $interval['start_at'];
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['end_at']        = $interval['end_at'];
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['days']          = $interval['days'];
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['units']         = $pricing['units'];
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['rent_price']    = $pricing['price'];
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['pricing_label'] = $pricing['label'];
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['addons']        = $canonical_addons;
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['addon_indexes'] = array_values( array_unique( $indexes ) );
                $cart->cart_contents[ $cart_item_key ]['clr_rental']['deposit']       = max( 0, (float) get_post_meta( $product_id, '_clr_deposit', true ) );
                $fulfilment = $this->rehydrate_cart_fulfilment( $product_id, $cart->cart_contents[ $cart_item_key ]['clr_rental'] );
                if ( ! is_wp_error( $fulfilment ) ) {
                    unset( $cart->cart_contents[ $cart_item_key ]['clr_rental']['validation_error'] );
                    foreach ( $fulfilment as $field => $value ) {
                        $cart->cart_contents[ $cart_item_key ]['clr_rental'][ $field ] = $value;
                    }
                } else {
                    $cart->cart_contents[ $cart_item_key ]['clr_rental']['validation_error'] = $fulfilment->get_error_message();
                }
            }
        }
    }

    public function add_cart_fees( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        $delivery_totals = [];
        $deposit_totals  = [];
        foreach ( $cart->get_cart() as $item ) {
            if ( empty( $item['clr_rental'] ) ) {
                continue;
            }
            $r          = $item['clr_rental'];
            $name       = ! empty( $item['data'] ) ? $item['data']->get_name() : 'Mietartikel';
            $qty        = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $product_id = (int) ( $r['product_id'] ?? 0 );
            if ( in_array( sanitize_key( $r['fulfilment'] ?? '' ), [ 'delivery', 'delivery_return' ], true ) && (float) $r['delivery_fee'] > 0 ) {
                if ( ! isset( $delivery_totals[ $product_id ] ) ) {
                    $delivery_totals[ $product_id ] = [
                        'name'      => $name,
                        'amount'    => 0.0,
                        'tax_class' => ! empty( $item['data'] ) ? $item['data']->get_tax_class() : '',
                    ];
                }
                // Lieferung wird pro Mietposition/Transport berechnet, nicht pro identischem Gerät.
                $delivery_totals[ $product_id ]['amount'] += (float) $r['delivery_fee'];
            }
            $current_deposit = max( 0, (float) get_post_meta( $product_id, '_clr_deposit', true ) );
            $deposit_method  = sanitize_key( $r['deposit_method'] ?? 'online' );
            if ( $current_deposit > 0 && 'online' === $deposit_method ) {
                if ( ! isset( $deposit_totals[ $product_id ] ) ) {
                    $deposit_totals[ $product_id ] = [ 'name' => $name, 'amount' => 0.0 ];
                }
                $deposit_totals[ $product_id ]['amount'] += $current_deposit * $qty;
            }
        }
        foreach ( $delivery_totals as $row ) {
            $cart->add_fee( 'Transport – ' . $row['name'], $row['amount'], true, $row['tax_class'] );
        }
        foreach ( $deposit_totals as $row ) {
            $cart->add_fee( 'Kaution – ' . $row['name'], $row['amount'], false );
        }
    }

    public function validate_cart_availability() {
        if ( ! WC()->cart ) {
            return;
        }
        $by_product = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['clr_rental'] ) ) {
                continue;
            }
            $r = $item['clr_rental'];
            if ( ! empty( $r['validation_error'] ) ) {
                wc_add_notice( sanitize_text_field( $r['validation_error'] ), 'error' );
                continue;
            }
            $interval_check = $this->validate_interval_rules_for_product( (int) $r['product_id'], (string) ( $r['start'] ?? '' ), (string) ( $r['end'] ?? '' ) );
            if ( is_wp_error( $interval_check ) ) {
                wc_add_notice( $interval_check->get_error_message(), 'error' );
                continue;
            }
            $fulfilment_check = $this->rehydrate_cart_fulfilment( (int) $r['product_id'], $r );
            if ( is_wp_error( $fulfilment_check ) ) {
                wc_add_notice( $fulfilment_check->get_error_message(), 'error' );
                continue;
            }
            $by_product[ (int) $r['product_id'] ][] = [
                'start_at' => $interval_check['start_at'],
                'end_at'   => $interval_check['end_at'],
                'quantity' => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
            ];
        }
        foreach ( $by_product as $product_id => $intervals ) {
            if ( ! $this->capacity_available( $product_id, $intervals ) ) {
                $product = wc_get_product( $product_id );
                wc_add_notice( sprintf( '%s: Für mindestens einen gewählten Zeitraum ist nicht mehr genügend Bestand verfügbar.', $product ? $product->get_name() : 'Mietartikel' ), 'error' );
            }
        }
    }

    public function rental_is_purchasable( $purchasable, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $purchasable;
        }
        $config_id = $this->config_product_id( $product->get_id(), $product->is_type( 'variation' ) ? $product->get_id() : 0 );
        if ( ! $config_id ) {
            return $purchasable;
        }
        if ( $purchasable ) {
            return true;
        }
        // Rental pricing is stored separately from Woo's regular price, but all
        // other core purchasability safeguards remain in force.
        $post_status = get_post_status( $product->get_id() );
        if ( 'publish' !== $post_status && ! current_user_can( 'edit_post', $product->get_id() ) ) {
            return false;
        }
        if ( $product->is_type( [ 'external', 'grouped' ] ) ) {
            return false;
        }
        $configured_price = get_post_meta( $config_id, '_clr_unit_price', true );
        $rules = get_post_meta( $config_id, '_clr_pricing_rules', true );
        $weekend = 'yes' === get_post_meta( $config_id, '_clr_weekend_enabled', true );
        $weekday_prices = $this->weekday_prices( $config_id );
        if ( '' === (string) $configured_price && empty( $rules ) && ! $weekend && empty( $weekday_prices ) ) {
            return false;
        }
        return true;
    }

    public function rental_price_html( $html, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $html;
        }
        $config_id = $this->is_rental( $product->get_id() ) ? $product->get_id() : ( $product->is_type( 'variation' ) && $this->is_rental( $product->get_parent_id() ) ? $product->get_parent_id() : 0 );
        if ( ! $config_id ) {
            return $html;
        }
        $price  = (float) get_post_meta( $config_id, '_clr_unit_price', true );
        $unit   = get_post_meta( $config_id, '_clr_billing_unit', true ) ?: 'day';
        if ( 'day' === $unit ) {
            $weekday_prices = $this->weekday_prices( $config_id );
            if ( $weekday_prices ) {
                $candidates = array_values( $weekday_prices );
                if ( '' !== (string) get_post_meta( $config_id, '_clr_unit_price', true ) ) {
                    $candidates[] = max( 0, $price );
                }
                if ( $candidates ) {
                    $price = min( $candidates );
                }
            }
        }
        $labels = [ 'day' => 'Miettag', 'week' => 'Woche', 'month' => '30 Tage' ];
        return '<span class="price clr-catalog-price">ab ' . wc_price( $price ) . ' / ' . esc_html( $labels[ $unit ] ?? 'Miettag' ) . '</span>';
    }

    public function order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values['clr_rental'] ) ) {
            return;
        }
        $r = $values['clr_rental'];
        $item->add_meta_data( '_clr_rental_data', $r, true );
        $item->add_meta_data( 'Mietbeginn', $this->format_datetime_display( $r['start_at'] ), true );
        $item->add_meta_data( 'Rückgabe', $this->format_datetime_display( $r['end_at'] ), true );
        $item->add_meta_data( 'Mietdauer', sprintf( '%d Miettag(e) – %s', (int) $r['days'], $r['pricing_label'] ), true );
        foreach ( $r['addons'] as $addon ) {
            $item->add_meta_data( $addon['group'], $addon['label'], false );
        }
        if ( ! empty( $r['fulfilment'] ) ) {
            $item->add_meta_data( 'Transport / Übergabe', $this->fulfilment_label( $r['fulfilment'] ), true );
        }
        if ( in_array( sanitize_key( $r['fulfilment'] ?? '' ), [ 'delivery', 'delivery_return' ], true ) ) {
            if ( ! empty( $r['delivery_zone'] ) ) {
                $item->add_meta_data( 'Lieferzone', $r['delivery_zone'], true );
            }
            if ( '' !== (string) ( $r['delivery_distance'] ?? '' ) ) {
                $item->add_meta_data( 'Lieferentfernung', wc_format_localized_decimal( $r['delivery_distance'] ) . ' km je Fahrt', true );
            }
            if ( ! empty( $r['delivery_address'] ) ) {
                $item->add_meta_data( 'Liefer-/Abholadresse', $r['delivery_address'], true );
            }
        }
        if ( (float) ( $r['deposit'] ?? 0 ) > 0 ) {
            $item->add_meta_data(
                'Kautionshinterlegung',
                'cash' === sanitize_key( $r['deposit_method'] ?? 'online' ) ? 'Separat überweisen oder bei Abholung bar hinterlegen' : 'Online mit Bestellung hinterlegt',
                true
            );
        }
    }

    public function order_fee_meta( $item, $fee_key, $fee, $order ) {
        unset( $fee_key, $order );
        if ( ! $item instanceof WC_Order_Item_Fee ) {
            return;
        }
        if ( 0 === strpos( $item->get_name(), 'Kaution – ' ) ) {
            /*
             * A refundable rental deposit is not consideration for a taxable
             * supply. WC_Checkout creates non-taxable cart fees with an empty
             * tax class but WC_Order_Item_Fee itself defaults to tax_status
             * "taxable". This matters especially for Checkout Blocks / Store
             * API because WooCommerce recalculates the draft order after fee
             * lines have been copied from the cart. Explicitly persist the fee
             * as non-taxable and preserve the exact security-deposit amount so
             * no plugin/order recalculation can interpret the gross amount as a
             * taxable standard-rate fee and reduce it to a net amount.
             */
            $amount = isset( $fee->amount ) ? max( 0, (float) $fee->amount ) : max( 0, (float) $item->get_amount() );
            $item->set_tax_status( 'none' );
            $item->set_tax_class( '' );
            $item->set_amount( wc_format_decimal( $amount, wc_get_price_decimals() ) );
            $item->set_total( wc_format_decimal( $amount, wc_get_price_decimals() ) );
            $item->set_total_tax( 0 );
            $item->set_taxes( [ 'total' => [] ] );
            $item->add_meta_data( '_clr_deposit', 'yes', true );
            $item->add_meta_data( '_clr_fee_type', 'deposit', true );
        } elseif ( 0 === strpos( $item->get_name(), 'Lieferung – ' ) || 0 === strpos( $item->get_name(), 'Transport – ' ) ) {
            $item->add_meta_data( '_clr_fee_type', 'delivery', true );
        }
    }

    public function reserve_order_bookings( $order_id, $posted_data = null, $order = null ) {
        $order = $order ?: wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $result = $this->create_bookings_for_order( $order );
        if ( is_wp_error( $result ) ) {
            $order->update_meta_data( '_clr_booking_conflict', $result->get_error_code() );
            $order->add_order_note( 'Vermietung: Bestellung vor Zahlung gestoppt – ' . $result->get_error_message() );
            $order->save();
            throw new Exception( esc_html( $result->get_error_message() ) );
        }
    }

    public function reserve_store_api_order_bookings( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $result = $this->create_bookings_for_order( $order );
        if ( is_wp_error( $result ) ) {
            $order->update_meta_data( '_clr_booking_conflict', $result->get_error_code() );
            $order->add_order_note( 'Vermietung: Bestellung vor Zahlung gestoppt – ' . $result->get_error_message() );
            $order->save();
            throw new Exception( esc_html( $result->get_error_message() ) );
        }
    }

    public function ensure_order_bookings( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $result = $this->create_bookings_for_order( $order );
        if ( is_wp_error( $result ) ) {
            $order->update_meta_data( '_clr_booking_conflict', $result->get_error_code() );
            $order->add_order_note( 'ACHTUNG Vermietung: ' . $result->get_error_message() . ' Bestellung bitte manuell prüfen.' );
            $order->save();
        }
    }

    private function create_bookings_for_order( WC_Order $order ) {
        global $wpdb;
        $table  = $this->table();
        $groups = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            // An administrator may deliberately cancel only the rental reservation
            // while keeping the WooCommerce order for documentation/accounting.
            // Persist that decision on the order item so later status changes do
            // not silently recreate the cancelled reservation.
            if ( 'yes' === $item->get_meta( '_clr_booking_admin_cancelled', true ) ) {
                continue;
            }
            $r = $item->get_meta( '_clr_rental_data', true );
            if ( ! is_array( $r ) || empty( $r['product_id'] ) || empty( $r['start'] ) || empty( $r['end'] ) ) {
                continue;
            }
            $product_id = absint( $r['product_id'] );
            if ( ! $product_id || ! $this->is_rental( $product_id ) ) {
                return new WP_Error( 'clr_booking_product', 'Ein Mietartikel ist nicht mehr gültig konfiguriert.' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND order_item_id = %d AND status IN ('reserved','blocked') LIMIT 1", $table, $order->get_id(), $item_id ) );
            if ( $exists ) {
                continue;
            }
            $interval = $this->build_interval( $product_id, sanitize_text_field( $r['start'] ), sanitize_text_field( $r['end'] ) );
            if ( is_wp_error( $interval ) ) {
                return new WP_Error( 'clr_booking_dates', 'Ein Mietzeitraum der Bestellung ist ungültig.' );
            }
            $qty = max( 1, (int) $item->get_quantity() );
            if ( $qty > $this->capacity( $product_id ) ) {
                return new WP_Error( 'clr_booking_capacity', 'Die angeforderte Menge überschreitet den verfügbaren Mietbestand.' );
            }
            $groups[ $product_id ][] = [
                'item_id'  => (int) $item_id,
                'interval' => $interval,
                'quantity' => $qty,
            ];
        }

        if ( ! $groups ) {
            return true;
        }
        ksort( $groups, SORT_NUMERIC );
        $locks = [];
        $inserted_ids = [];
        try {
            foreach ( array_keys( $groups ) as $product_id ) {
                $lock_name = $this->booking_lock_name( $product_id );
                $got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Booking advisory locks must reflect current database state and are intentionally not cached.
                if ( 1 !== $got_lock ) {
                    return new WP_Error( 'clr_booking_lock', 'Der Mietbestand konnte gerade nicht sicher reserviert werden. Bitte Bestellung erneut versuchen.' );
                }
                $locks[] = $lock_name;
            }

            // Re-check everything while all relevant product locks are held.
            foreach ( $groups as $product_id => $entries ) {
                $intervals = [];
                foreach ( $entries as $entry ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                    $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND order_item_id = %d AND status IN ('reserved','blocked') LIMIT 1", $table, $order->get_id(), $entry['item_id'] ) );
                    if ( $exists ) {
                        continue;
                    }
                    $intervals[] = [
                        'start_at' => $entry['interval']['start_at'],
                        'end_at'   => $entry['interval']['end_at'],
                        'quantity' => $entry['quantity'],
                    ];
                }
                if ( $intervals && ! $this->capacity_available( $product_id, $intervals, 0 ) ) {
                    return new WP_Error( 'clr_booking_conflict', 'Der gewünschte Mietzeitraum wurde gerade anderweitig gebucht. Bitte Zeitraum neu wählen.' );
                }
            }

            foreach ( $groups as $product_id => $entries ) {
                foreach ( $entries as $entry ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                    $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND order_item_id = %d AND status IN ('reserved','blocked') LIMIT 1", $table, $order->get_id(), $entry['item_id'] ) );
                    if ( $exists ) {
                        continue;
                    }
                    $interval = $entry['interval'];
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                    $ok = $wpdb->insert( $table, [
                        'product_id'     => $product_id,
                        'order_id'       => $order->get_id(),
                        'order_item_id'  => $entry['item_id'],
                        'source'         => 'order',
                        'start_date'     => $interval['start_date'],
                        'end_date'       => $interval['end_date'],
                        'start_at'       => $interval['start_at'],
                        'end_at'         => $interval['end_at'],
                        'quantity'       => $entry['quantity'],
                        'customer_name'  => $this->limit_text( sanitize_text_field( $order->get_formatted_billing_full_name() ), 190 ),
                        'customer_email' => $this->limit_text( sanitize_email( $order->get_billing_email() ), 190 ),
                        'note'           => '',
                        'status'         => 'reserved',
                        'created_at'     => current_time( 'mysql' ),
                    ], [ '%d','%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s' ] );
                    if ( false === $ok ) {
                        foreach ( $inserted_ids as $booking_id ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                            $wpdb->delete( $table, [ 'id' => $booking_id ], [ '%d' ] );
                        }
                        return new WP_Error( 'clr_booking_database', 'Die Mietreservierung konnte nicht sicher gespeichert werden. Es wurde nichts belastet; bitte erneut versuchen.' );
                    }
                    $inserted_ids[] = (int) $wpdb->insert_id;
                }
            }

            $finalized = apply_filters( 'rmwc_finalize_booking_reservation', true, $order, $inserted_ids );
            if ( is_wp_error( $finalized ) || false === $finalized ) {
                foreach ( $inserted_ids as $booking_id ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                    $wpdb->delete( $table, [ 'id' => $booking_id ], [ '%d' ] );
                    do_action( 'rmwc_booking_cancelled', $booking_id, $order->get_id() );
                }
                return is_wp_error( $finalized )
                    ? $finalized
                    : new WP_Error( 'clr_booking_finalize', __( 'Die Mietreservierung konnte nicht vollständig vorbereitet werden. Bitte erneut versuchen.', 'patsch9-rental-engine' ) );
            }
            do_action( 'rmwc_bookings_created', $order, $inserted_ids );

            if ( $this->order_deposit_total( $order ) > 0 && ! $order->get_meta( '_clr_deposit_status', true ) ) {
                $order->update_meta_data( '_clr_deposit_status', 'offen' );
            }
            $order->delete_meta_data( '_clr_booking_conflict' );
            $order->save();
            $this->schedule_unpaid_expiry( $order );
            return true;
        } finally {
            foreach ( array_reverse( $locks ) as $lock_name ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the matching booking advisory lock immediately; caching is invalid here.
            }
        }
    }

    private function unpaid_hold_minutes() {
        $minutes = (int) get_option( 'clr_unpaid_hold_minutes', 60 );
        return max( 5, min( 1440, $minutes ) );
    }

    /**
     * Gateways that intentionally do not confirm payment immediately, e.g.
     * invoice, bank transfer, cheque or cash on delivery. Keep this in sync
     * with the V2 deposit module and expose the same filter so custom invoice
     * gateways only need to be configured once.
     */
    private function offline_gateway_ids() {
        $stored = get_option( 'clr_v2_offline_gateway_ids', 'bacs,cheque,cod' );
        $ids    = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,;]+/', (string) $stored ) ?: [] ) ) );
        return apply_filters( 'rmwc_offline_gateway_ids', $ids );
    }

    private function order_uses_offline_gateway( WC_Order $order ) {
        $gateway_id = sanitize_key( (string) $order->get_payment_method() );
        return '' !== $gateway_id && in_array( $gateway_id, $this->offline_gateway_ids(), true );
    }

    private function order_has_active_rental_reservation( WC_Order $order ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        return 0 < (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE order_id = %d AND status IN ('reserved','blocked')",
                $this->table(),
                $order->get_id()
            )
        );
    }

    private function unpaid_expiry_applies( WC_Order $order ) {
        if ( $order->is_paid() || ! $order->has_status( 'pending' ) ) {
            return false;
        }
        return ! $this->order_uses_offline_gateway( $order );
    }

    private function schedule_unpaid_expiry( WC_Order $order ) {
        if ( ! $this->unpaid_expiry_applies( $order ) ) {
            $this->unschedule_unpaid_expiry( $order->get_id() );
            return;
        }
        $order_id = $order->get_id();
        $run_at   = time() + ( $this->unpaid_hold_minutes() * MINUTE_IN_SECONDS );
        if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
            if ( ! as_has_scheduled_action( 'rmwc_expire_unpaid_order', [ $order_id ], 'patsch9-rental-engine' ) ) {
                as_schedule_single_action( $run_at, 'rmwc_expire_unpaid_order', [ $order_id ], 'patsch9-rental-engine', true );
            }
            return;
        }
        if ( ! wp_next_scheduled( 'rmwc_expire_unpaid_order', [ $order_id ] ) ) {
            wp_schedule_single_event( $run_at, 'rmwc_expire_unpaid_order', [ $order_id ] );
        }
    }

    private function unschedule_unpaid_expiry( $order_id ) {
        $order_id = absint( $order_id );
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'rmwc_expire_unpaid_order', [ $order_id ], 'patsch9-rental-engine' );
        }
        wp_clear_scheduled_hook( 'rmwc_expire_unpaid_order', [ $order_id ] );
    }

    public function handle_order_status_change( $order_id, $from, $to, $order ) {
        unset( $from );
        if ( 'pending' !== $to ) {
            $this->unschedule_unpaid_expiry( $order_id );
        } elseif ( $order instanceof WC_Order ) {
            $this->schedule_unpaid_expiry( $order );
        }
    }

    public function expire_unpaid_order( $order_id ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return;
        }

        global $wpdb;
        $scope     = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $order_id;
        $lock_name = 'clr_exp_' . substr( hash( 'sha256', $scope ), 0, 40 );
        $locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes duplicate expiry workers for one order.
        if ( 1 !== $locked ) {
            return;
        }

        try {
            // Reload only after the lock was acquired so a second scheduled
            // worker cannot act on the same stale order state.
            $order = wc_get_order( $order_id );
            if ( ! $order || ! $this->unpaid_expiry_applies( $order ) ) {
                return;
            }
            if ( ! $this->order_has_active_rental_reservation( $order ) ) {
                return;
            }
            // Re-check the payment/status immediately before the state change.
            $order = wc_get_order( $order_id );
            if ( ! $order || ! $this->unpaid_expiry_applies( $order ) ) {
                return;
            }
            $order->update_status( 'cancelled', 'Vermietung: unbezahlte Online-Reservierung nach ' . $this->unpaid_hold_minutes() . ' Minuten automatisch freigegeben.' );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the matching application-level lock.
        }
    }

    /**
     * WooCommerce has its own pending-order cancellation based on the global
     * stock-hold timeout. Do not let that cancel a rental paid through an
     * explicitly configured offline gateway such as invoice/bank transfer.
     * Manual cancellation and gateway-driven failures are not affected.
     */
    public function protect_offline_rental_from_wc_unpaid_cancellation( $should_cancel, $order ) {
        if ( ! $should_cancel || ! $order instanceof WC_Order ) {
            return $should_cancel;
        }
        if ( ! $this->order_uses_offline_gateway( $order ) ) {
            return $should_cancel;
        }
        if ( ! $this->order_has_active_rental_reservation( $order ) ) {
            return $should_cancel;
        }
        return false;
    }

    public function cancel_order_bookings( $order_id ) {
        global $wpdb;
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d AND status <> %s', $this->table(), $order_id, 'cancelled' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $wpdb->update( $this->table(), [ 'status' => 'cancelled' ], [ 'order_id' => $order_id ], [ '%s' ], [ '%d' ] );
        foreach ( array_map( 'absint', is_array( $ids ) ? $ids : [] ) as $booking_id ) {
            if ( $booking_id ) {
                do_action( 'rmwc_booking_cancelled', $booking_id, $order_id );
            }
        }
    }

    private function order_deposit_total( WC_Order $order ) {
        $total = 0.0;
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            if ( 'yes' === $fee->get_meta( '_clr_deposit', true ) || 0 === strpos( $fee->get_name(), 'Kaution – ' ) ) {
                $total += (float) $fee->get_total();
            }
        }
        return $total;
    }

    public function add_deposit_meta_box() {
        $screens = [ 'shop_order' ];
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box( 'clr_deposit_box', 'Mietkaution', [ $this, 'render_deposit_meta_box' ], $screen, 'side', 'default' );
        }
    }

    public function render_deposit_meta_box( $object ) {
        $order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
        if ( ! $order ) {
            return;
        }
        $total = $this->order_deposit_total( $order );
        if ( $total <= 0 ) {
            echo '<p>Diese Bestellung enthält keine Mietkaution.</p>';
            return;
        }
        wp_nonce_field( 'clr_save_deposit_' . $order->get_id(), 'clr_deposit_nonce' );
        $status   = $order->get_meta( '_clr_deposit_status', true ) ?: 'offen';
        $refunded = $order->get_meta( '_clr_deposit_refunded', true );
        $retained = $order->get_meta( '_clr_deposit_retained', true );
        $returned_at = $order->get_meta( '_clr_deposit_returned_at', true );
        $reason   = $order->get_meta( '_clr_deposit_retention_reason', true ) ?: 'none';
        $note     = $order->get_meta( '_clr_deposit_note', true );
        echo '<p><strong>Kaution gesamt:</strong> ' . wp_kses_post( wc_price( $total, [ 'currency' => $order->get_currency() ] ) ) . '</p>';
        echo '<p><label>Status<br><select name="clr_deposit_status" style="width:100%">';
        foreach ( [ 'offen' => 'Offen', 'erhalten' => 'Erhalten', 'zurueckgezahlt' => 'Vollständig zurückgezahlt', 'teilweise' => 'Teilweise einbehalten', 'einbehalten' => 'Vollständig einbehalten', 'erlassen' => 'Erlassen' ] as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Zurückgezahlt<br><input type="number" min="0" step="0.01" name="clr_deposit_refunded" value="' . esc_attr( $refunded ) . '" style="width:100%"></label></p>';
        echo '<p><label>Einbehalten<br><input type="number" min="0" step="0.01" name="clr_deposit_retained" value="' . esc_attr( $retained ) . '" style="width:100%"></label></p>';
        echo '<p><label>Grund für Einbehalt<br><select name="clr_deposit_retention_reason" style="width:100%">';
        foreach ( [ 'none' => 'Kein Einbehalt', 'damage' => 'Beschädigung', 'cleaning' => 'Nicht korrekt gereinigt / Reinigungsaufwand', 'loss' => 'Verlust / Nichtrückgabe', 'other' => 'Sonstiger Grund' ] as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $reason, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Rückzahlung/Abschluss am<br><input type="date" name="clr_deposit_returned_at" value="' . esc_attr( $returned_at ) . '" style="width:100%"></label></p>';
        echo '<p><label>Notiz / Schaden<br><textarea name="clr_deposit_note" rows="3" style="width:100%">' . esc_textarea( $note ) . '</textarea></label></p>';
    }

    public function save_deposit_meta_box( $order_id, $order = null ) {
        if ( empty( $_POST['clr_deposit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clr_deposit_nonce'] ) ), 'clr_save_deposit_' . $order_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $old_status = sanitize_key( (string) $order->get_meta( '_clr_deposit_status', true ) );
        $allowed = [ 'offen', 'erhalten', 'zurueckgezahlt', 'teilweise', 'einbehalten', 'erlassen' ];
        $status  = sanitize_key( wp_unslash( $_POST['clr_deposit_status'] ?? 'offen' ) );
        $status  = in_array( $status, $allowed, true ) ? $status : 'offen';
        $order->update_meta_data( '_clr_deposit_status', $status );
        $total = max( 0, $this->order_deposit_total( $order ) );
        $refunded_raw = isset( $_POST['clr_deposit_refunded'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_deposit_refunded'] ) ) : '0';
        $retained_raw = isset( $_POST['clr_deposit_retained'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_deposit_retained'] ) ) : '0';
        $refunded = min( $total, max( 0, (float) wc_format_decimal( $refunded_raw ) ) );
        $retained = min( $total, max( 0, (float) wc_format_decimal( $retained_raw ) ) );
        if ( $refunded + $retained > $total ) {
            $retained = max( 0, $total - $refunded );
        }
        $order->update_meta_data( '_clr_deposit_refunded', $refunded );
        $order->update_meta_data( '_clr_deposit_retained', $retained );
        $allowed_reasons = [ 'none', 'damage', 'cleaning', 'loss', 'other' ];
        $reason = sanitize_key( wp_unslash( $_POST['clr_deposit_retention_reason'] ?? 'none' ) );
        if ( 0.0 === $retained ) {
            $reason = 'none';
        }
        $order->update_meta_data( '_clr_deposit_retention_reason', in_array( $reason, $allowed_reasons, true ) ? $reason : 'none' );
        $returned_at = sanitize_text_field( wp_unslash( $_POST['clr_deposit_returned_at'] ?? '' ) );
        if ( $returned_at && ! $this->is_valid_date( $returned_at ) ) {
            $returned_at = '';
        }
        $order->update_meta_data( '_clr_deposit_returned_at', $returned_at );
        $order->update_meta_data( '_clr_deposit_note', $this->limit_text( sanitize_textarea_field( wp_unslash( $_POST['clr_deposit_note'] ?? '' ) ), 2000 ) );
        $order->save();
        do_action( 'rmwc_deposit_saved', $order, $old_status, $status, $refunded, $retained );
    }

    public function admin_menu() {
        $hook = add_submenu_page( 'woocommerce', 'Vermietungen', 'Vermietungen', 'manage_woocommerce', 'clr-rentals', [ $this, 'admin_bookings_page' ] );
        $this->admin_page_hook = is_string( $hook ) ? $hook : '';
    }

    private function admin_booking_row( $booking_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table(), absint( $booking_id ) ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    private function admin_handle_actions() {
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
        if ( 'POST' !== $request_method ) {
            return '';
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return '<div class="notice notice-error"><p>Keine Berechtigung.</p></div>';
        }

        $action = sanitize_key( wp_unslash( $_POST['clr_action'] ?? '' ) );
        if ( ! $action ) {
            return '';
        }
        check_admin_referer( 'clr_admin_action', 'clr_admin_nonce' );

        if ( 'save_settings' === $action ) {
            $origin = $this->sanitize_address( isset( $_POST['clr_delivery_origin'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_delivery_origin'] ) ) : '' );
            update_option( 'clr_delivery_origin', $origin, false );
            update_option( 'clr_google_routes_enabled', isset( $_POST['clr_google_routes_enabled'] ) ? 'yes' : 'no', false );
            $hold_minutes = isset( $_POST['clr_unpaid_hold_minutes'] ) ? absint( wp_unslash( $_POST['clr_unpaid_hold_minutes'] ) ) : 60;
            update_option( 'clr_unpaid_hold_minutes', max( 5, min( 1440, $hold_minutes ) ), false );
            if ( ! defined( 'RMWC_GOOGLE_ROUTES_API_KEY' ) ) {
                if ( isset( $_POST['clr_google_routes_clear_key'] ) ) {
                    delete_option( 'clr_google_routes_api_key' );
                } elseif ( isset( $_POST['clr_google_routes_api_key'] ) ) {
                    $new_key = trim( sanitize_text_field( wp_unslash( $_POST['clr_google_routes_api_key'] ) ) );
                    if ( '' !== $new_key ) {
                        update_option( 'clr_google_routes_api_key', $this->limit_text( $new_key, 255 ), false );
                    }
                }
            }
            update_option( 'clr_reminder_enabled', isset( $_POST['clr_reminder_enabled'] ) ? 'yes' : 'no', false );
            update_option( 'clr_reminder_hours', min( 8760, max( 1, absint( wp_unslash( $_POST['clr_reminder_hours'] ?? 24 ) ) ) ), false );
            update_option( 'clr_reminder_subject', $this->limit_text( sanitize_text_field( wp_unslash( $_POST['clr_reminder_subject'] ?? 'Erinnerung an Ihre Miete: %product%' ) ), 200 ), false );
            update_option( 'clr_reminder_body', $this->limit_text( sanitize_textarea_field( wp_unslash( $_POST['clr_reminder_body'] ?? '' ) ), 5000 ), false );
            update_option( 'clr_delete_data_on_uninstall', isset( $_POST['clr_delete_data_on_uninstall'] ) ? 'yes' : 'no', false );
            do_action( 'rmwc_save_v2_settings' );
            return '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>';
        }

        if ( 'update_booking' === $action ) {
            global $wpdb;
            $booking_id = isset( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0;
            $row = $this->admin_booking_row( $booking_id );
            if ( ! $row ) {
                return '<div class="notice notice-error"><p>' . esc_html__( 'Buchung nicht gefunden.', 'patsch9-rental-engine' ) . '</p></div>';
            }
            if ( ! in_array( $row['status'], [ 'reserved', 'blocked' ], true ) || 'reserved' !== sanitize_key( $row['workflow_status'] ?? 'reserved' ) ) {
                return '<div class="notice notice-error"><p>' . esc_html__( 'Nur noch nicht übergebene aktive Buchungen können bearbeitet werden.', 'patsch9-rental-engine' ) . '</p></div>';
            }

            $product_id = absint( $row['product_id'] );
            $quantity   = 'order' === $row['source']
                ? max( 1, absint( $row['quantity'] ) )
                : min( $this->capacity( $product_id ), max( 1, absint( wp_unslash( $_POST['quantity'] ?? $row['quantity'] ) ) ) );

            $lock_name = $this->booking_lock_name( $product_id );
            $got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Booking advisory locks must reflect current database state and are intentionally not cached.
            if ( 1 !== $got_lock ) {
                return '<div class="notice notice-error"><p>' . esc_html__( 'Die Buchung konnte gerade nicht sicher gesperrt werden. Bitte erneut versuchen.', 'patsch9-rental-engine' ) . '</p></div>';
            }

            try {
                if ( 'block' === $row['source'] ) {
                    $start = $this->parse_admin_datetime( sanitize_text_field( wp_unslash( $_POST['block_start'] ?? '' ) ) );
                    $end   = $this->parse_admin_datetime( sanitize_text_field( wp_unslash( $_POST['block_end'] ?? '' ) ) );
                    if ( ! $start || ! $end || $end <= $start ) {
                        return '<div class="notice notice-error"><p>' . esc_html__( 'Bitte einen gültigen Sperrzeitraum angeben.', 'patsch9-rental-engine' ) . '</p></div>';
                    }
                    $interval = [
                        'start_at'   => $start->format( 'Y-m-d H:i:s' ),
                        'end_at'     => $end->format( 'Y-m-d H:i:s' ),
                        'start_date' => $start->format( 'Y-m-d' ),
                        'end_date'   => $end->format( 'Y-m-d' ),
                    ];
                    if ( ! $this->capacity_available(
                        $product_id,
                        [ [ 'start_at' => $interval['start_at'], 'end_at' => $interval['end_at'], 'quantity' => $quantity ] ],
                        0,
                        $booking_id
                    ) ) {
                        return '<div class="notice notice-error"><p>' . esc_html__( 'Für den geänderten Zeitraum ist nicht genügend Bestand verfügbar.', 'patsch9-rental-engine' ) . '</p></div>';
                    }
                } else {
                    $start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
                    $end_date   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
                    $interval   = $this->validate_interval_for_product( $product_id, $start_date, $end_date, $quantity, 0, [], $booking_id );
                    if ( is_wp_error( $interval ) ) {
                        return '<div class="notice notice-error"><p>' . esc_html( $interval->get_error_message() ) . '</p></div>';
                    }
                }

                $updated = [
                    'start_date' => $interval['start_date'],
                    'end_date'   => $interval['end_date'],
                    'start_at'   => $interval['start_at'],
                    'end_at'     => $interval['end_at'],
                    'quantity'   => $quantity,
                    'note'       => substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? $row['note'] ) ), 0, 2000 ),
                ];
                if ( 'manual' === $row['source'] ) {
                    $updated['customer_name']  = substr( sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? $row['customer_name'] ) ), 0, 190 );
                    $email = sanitize_email( wp_unslash( $_POST['customer_email'] ?? $row['customer_email'] ) );
                    if ( $email && ! is_email( $email ) ) {
                        return '<div class="notice notice-error"><p>' . esc_html__( 'Bitte eine gültige E-Mail-Adresse angeben.', 'patsch9-rental-engine' ) . '</p></div>';
                    }
                    $updated['customer_email'] = substr( $email, 0, 190 );
                }

                $new_row = array_merge( $row, $updated );
                $workflow_result = apply_filters( 'rmwc_validate_booking_update', true, $row, $new_row );
                if ( is_wp_error( $workflow_result ) || false === $workflow_result ) {
                    $message = is_wp_error( $workflow_result ) ? $workflow_result->get_error_message() : __( 'Die Gerätezuordnung konnte für den neuen Zeitraum nicht angepasst werden.', 'patsch9-rental-engine' );
                    return '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
                }

                $formats = [];
                foreach ( array_keys( $updated ) as $key ) {
                    $formats[] = 'quantity' === $key ? '%d' : '%s';
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                $db_updated = $wpdb->update( $this->table(), $updated, [ 'id' => $booking_id ], $formats, [ '%d' ] );
                if ( false === $db_updated ) {
                    // The workflow filter may already have moved physical device
                    // assignments to the proposed interval. Restore the previous
                    // assignment state before returning the database error.
                    apply_filters( 'rmwc_validate_booking_update', true, $new_row, $row );
                    return '<div class="notice notice-error"><p>' . esc_html__( 'Die Buchungsänderung konnte nicht gespeichert werden. Der vorherige Zustand wurde wiederhergestellt.', 'patsch9-rental-engine' ) . '</p></div>';
                }
                if ( 'order' === $row['source'] && $row['order_id'] && $row['order_item_id'] ) {
                    $order = wc_get_order( (int) $row['order_id'] );
                    $item  = $order ? $order->get_item( (int) $row['order_item_id'] ) : false;
                    if ( ! $order || ! $item instanceof WC_Order_Item_Product ) {
                        $restore = [];
                        $restore_formats = [];
                        foreach ( array_keys( $updated ) as $key ) {
                            $restore[ $key ] = $row[ $key ];
                            $restore_formats[] = 'quantity' === $key ? '%d' : '%s';
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                        $wpdb->update( $this->table(), $restore, [ 'id' => $booking_id ], $restore_formats, [ '%d' ] );
                        apply_filters( 'rmwc_validate_booking_update', true, $new_row, $row );
                        return '<div class="notice notice-error"><p>' . esc_html__( 'Die WooCommerce-Bestellposition zur Buchung wurde nicht gefunden. Die Änderung wurde zurückgesetzt.', 'patsch9-rental-engine' ) . '</p></div>';
                    }

                    $old_rental = $item->get_meta( '_clr_rental_data', true );
                    $old_pickup = $item->get_meta( 'Abholung', true );
                    $old_return = $item->get_meta( 'Rückgabe', true );
                    if ( ! is_array( $old_rental ) ) {
                        $restore = [];
                        $restore_formats = [];
                        foreach ( array_keys( $updated ) as $key ) {
                            $restore[ $key ] = $row[ $key ];
                            $restore_formats[] = 'quantity' === $key ? '%d' : '%s';
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                        $wpdb->update( $this->table(), $restore, [ 'id' => $booking_id ], $restore_formats, [ '%d' ] );
                        apply_filters( 'rmwc_validate_booking_update', true, $new_row, $row );
                        return '<div class="notice notice-error"><p>' . esc_html__( 'Die Mietdaten der WooCommerce-Bestellposition sind unvollständig. Die Änderung wurde zurückgesetzt.', 'patsch9-rental-engine' ) . '</p></div>';
                    }

                    try {
                        $rental = $old_rental;
                        $rental['start']    = $interval['start_date'];
                        $rental['end']      = $interval['end_date'];
                        $rental['start_at'] = $interval['start_at'];
                        $rental['end_at']   = $interval['end_at'];
                        if ( isset( $interval['days'] ) ) {
                            $rental['days'] = max( 1, absint( $interval['days'] ) );
                        }
                        $item->update_meta_data( '_clr_rental_data', $rental );
                        $item->update_meta_data( 'Abholung', $this->format_datetime_display( $interval['start_at'] ) );
                        $item->update_meta_data( 'Rückgabe', $this->format_datetime_display( $interval['end_at'] ) );
                        $item->save();
                        $order->add_order_note( sprintf( 'Vermietung: Zeitraum der Buchung #%d administrativ geändert. Finanzielle Positionen wurden dadurch nicht automatisch neu berechnet.', $booking_id ) );
                        $order->save();
                    } catch ( Throwable $throwable ) {
                        $restore = [];
                        $restore_formats = [];
                        foreach ( array_keys( $updated ) as $key ) {
                            $restore[ $key ] = $row[ $key ];
                            $restore_formats[] = 'quantity' === $key ? '%d' : '%s';
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                        $wpdb->update( $this->table(), $restore, [ 'id' => $booking_id ], $restore_formats, [ '%d' ] );
                        apply_filters( 'rmwc_validate_booking_update', true, $new_row, $row );

                        // Best-effort restoration of order-item metadata if a persistence error happened mid-update.
                        try {
                            $item->update_meta_data( '_clr_rental_data', $old_rental );
                            $item->update_meta_data( 'Abholung', $old_pickup );
                            $item->update_meta_data( 'Rückgabe', $old_return );
                            $item->save();
                        } catch ( Throwable $restore_error ) {
                            // Do not expose internal exception details to the administrator or logs by default.
                        }
                        return '<div class="notice notice-error"><p>' . esc_html__( 'Die Bestellposition konnte nicht sicher aktualisiert werden. Buchung und Gerätezuordnung wurden auf den vorherigen Stand zurückgesetzt.', 'patsch9-rental-engine' ) . '</p></div>';
                    }
                    do_action( 'rmwc_order_booking_changed', $order, $booking_id, $row, $new_row );
                }

                do_action( 'rmwc_booking_updated', $booking_id, $row, $new_row );
                return '<div class="notice notice-success"><p>' . esc_html__( 'Buchung wurde aktualisiert.', 'patsch9-rental-engine' ) . '</p></div>';
            } finally {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the matching booking advisory lock immediately; caching is invalid here.
            }
        }

        if ( 'delete_booking' === $action ) {
            global $wpdb;
            $booking_id = isset( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0;
            $row = $this->admin_booking_row( $booking_id );
            if ( ! $row ) {
                return '<div class="notice notice-error"><p>' . esc_html__( 'Buchung nicht gefunden.', 'patsch9-rental-engine' ) . '</p></div>';
            }
            if ( ! in_array( sanitize_key( $row['status'] ?? '' ), [ 'reserved', 'blocked' ], true ) || 'reserved' !== sanitize_key( $row['workflow_status'] ?? 'reserved' ) ) {
                return '<div class="notice notice-error"><p>' . esc_html__( 'Eine bereits übergebene, zurückgegebene oder anderweitig abgeschlossene Buchung kann aus Nachweisgründen nicht gelöscht oder storniert werden.', 'patsch9-rental-engine' ) . '</p></div>';
            }
            if ( 'order' === $row['source'] ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                $wpdb->update( $this->table(), [ 'status' => 'cancelled' ], [ 'id' => $booking_id ], [ '%s' ], [ '%d' ] );
                do_action( 'rmwc_booking_cancelled', $booking_id, absint( $row['order_id'] ) );
                $order = wc_get_order( (int) $row['order_id'] );
                if ( $order ) {
                    $item = $row['order_item_id'] ? $order->get_item( (int) $row['order_item_id'] ) : false;
                    if ( $item instanceof WC_Order_Item_Product ) {
                        $item->update_meta_data( '_clr_booking_admin_cancelled', 'yes' );
                        $item->save();
                    }
                    $order->add_order_note( sprintf( 'Vermietung: Buchung #%d wurde in der Mietverwaltung storniert. Die Bestellung selbst wurde nicht automatisch storniert; die Mietreservierung wird bei späteren Statuswechseln nicht neu erzeugt.', $booking_id ) );
                    $order->save();
                }
                return '<div class="notice notice-success"><p>' . esc_html__( 'Die Bestellbuchung wurde storniert und bleibt aus Nachweisgründen in der Historie erhalten.', 'patsch9-rental-engine' ) . '</p></div>';
            }

            do_action( 'rmwc_booking_cancelled', $booking_id, 0 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $wpdb->delete( $this->table(), [ 'id' => $booking_id ], [ '%d' ] );
            return '<div class="notice notice-success"><p>' . esc_html__( 'Manuelle Buchung/Sperre wurde gelöscht.', 'patsch9-rental-engine' ) . '</p></div>';
        }

        if ( 'cancel_booking' === $action ) {
            global $wpdb;
            $booking_id = absint( wp_unslash( $_POST['booking_id'] ?? 0 ) );
            if ( $booking_id ) {
                $row = $this->admin_booking_row( $booking_id );
                if ( ! $row ) {
                    return '<div class="notice notice-error"><p>' . esc_html__( 'Buchung nicht gefunden.', 'patsch9-rental-engine' ) . '</p></div>';
                }
                if ( ! in_array( sanitize_key( $row['status'] ?? '' ), [ 'reserved', 'blocked' ], true ) || 'reserved' !== sanitize_key( $row['workflow_status'] ?? 'reserved' ) ) {
                    return '<div class="notice notice-error"><p>' . esc_html__( 'Eine bereits übergebene oder abgeschlossene Buchung kann nicht mehr freigegeben werden.', 'patsch9-rental-engine' ) . '</p></div>';
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                $updated = $wpdb->update( $this->table(), [ 'status' => 'cancelled' ], [ 'id' => $booking_id ], [ '%s' ], [ '%d' ] );
                if ( false === $updated ) {
                    return '<div class="notice notice-error"><p>' . esc_html__( 'Die Buchung konnte nicht freigegeben werden.', 'patsch9-rental-engine' ) . '</p></div>';
                }
                do_action( 'rmwc_booking_cancelled', $booking_id, absint( $row['order_id'] ) );
                return '<div class="notice notice-success"><p>' . esc_html__( 'Buchung/Sperre freigegeben.', 'patsch9-rental-engine' ) . '</p></div>';
            }
        }

        if ( 'add_manual' === $action ) {
            $product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
            $manual_product = wc_get_product( $product_id );
            if ( $manual_product && $manual_product->is_type( 'variation' ) && $this->is_rental( $manual_product->get_parent_id() ) ) {
                $product_id = $manual_product->get_parent_id();
            }
            $start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
            $end_date   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
            $qty        = min( $this->capacity( $product_id ), max( 1, absint( wp_unslash( $_POST['quantity'] ?? 1 ) ) ) );
            if ( ! $this->is_rental( $product_id ) ) {
                return '<div class="notice notice-error"><p>Bitte ein gültiges Mietprodukt auswählen.</p></div>';
            }
            $interval = $this->build_interval( $product_id, $start_date, $end_date );
            if ( is_wp_error( $interval ) ) {
                return '<div class="notice notice-error"><p>' . esc_html( $interval->get_error_message() ) . '</p></div>';
            }
            $email = $this->limit_text( sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) ), 190 );
            if ( $email && ! is_email( $email ) ) {
                return '<div class="notice notice-error"><p>Bitte eine gültige E-Mail-Adresse angeben.</p></div>';
            }
            $result = $this->insert_booking_with_lock( $product_id, [
                'product_id' => $product_id,
                'source' => 'manual',
                'start_date' => $start_date,
                'end_date' => $end_date,
                'start_at' => $interval['start_at'],
                'end_at' => $interval['end_at'],
                'quantity' => $qty,
                'customer_name' => $this->limit_text( sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ), 190 ),
                'customer_email' => $email,
                'note' => $this->limit_text( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 2000 ),
                'status' => 'reserved',
                'created_at' => current_time( 'mysql' ),
            ], [ 'start_at' => $interval['start_at'], 'end_at' => $interval['end_at'], 'quantity' => $qty ] );
            if ( is_wp_error( $result ) ) {
                return '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
            }
            $assignment = apply_filters( 'rmwc_finalize_manual_booking', true, absint( $result ) );
            if ( is_wp_error( $assignment ) || false === $assignment ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                $wpdb->delete( $this->table(), [ 'id' => absint( $result ) ], [ '%d' ] );
                $message = is_wp_error( $assignment ) ? $assignment->get_error_message() : __( 'Die Gerätezuordnung konnte nicht abgeschlossen werden.', 'patsch9-rental-engine' );
                return '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
            }
            do_action( 'rmwc_manual_booking_created', absint( $result ) );
            return '<div class="notice notice-success"><p>Manuelle Buchung angelegt.</p></div>';
        }

        if ( 'add_block' === $action ) {
            $product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
            $manual_product = wc_get_product( $product_id );
            if ( $manual_product && $manual_product->is_type( 'variation' ) && $this->is_rental( $manual_product->get_parent_id() ) ) {
                $product_id = $manual_product->get_parent_id();
            }
            if ( ! $this->is_rental( $product_id ) ) {
                return '<div class="notice notice-error"><p>Bitte ein gültiges Mietprodukt auswählen.</p></div>';
            }
            $start = $this->parse_admin_datetime( sanitize_text_field( wp_unslash( $_POST['block_start'] ?? '' ) ) );
            $end   = $this->parse_admin_datetime( sanitize_text_field( wp_unslash( $_POST['block_end'] ?? '' ) ) );
            if ( ! $start || ! $end || $end <= $start ) {
                return '<div class="notice notice-error"><p>Bitte einen gültigen Sperrzeitraum angeben.</p></div>';
            }
            $qty = absint( wp_unslash( $_POST['quantity'] ?? 0 ) );
            if ( $qty <= 0 ) {
                $qty = $this->capacity( $product_id );
            }
            $qty = min( $qty, $this->capacity( $product_id ) );
            $start_at = $start->format( 'Y-m-d H:i:s' );
            $end_at   = $end->format( 'Y-m-d H:i:s' );
            $result = $this->insert_booking_with_lock( $product_id, [
                'product_id' => $product_id,
                'source' => 'block',
                'start_date' => $start->format( 'Y-m-d' ),
                'end_date' => $end->format( 'Y-m-d' ),
                'start_at' => $start_at,
                'end_at' => $end_at,
                'quantity' => $qty,
                'customer_name' => '',
                'customer_email' => '',
                'note' => $this->limit_text( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 2000 ),
                'status' => 'blocked',
                'created_at' => current_time( 'mysql' ),
            ], [ 'start_at' => $start_at, 'end_at' => $end_at, 'quantity' => $qty ] );
            if ( is_wp_error( $result ) ) {
                return '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
            }
            return '<div class="notice notice-success"><p>Sperrzeit angelegt.</p></div>';
        }

        return '';
    }

    private function parse_admin_datetime( $value ) {
        if ( ! $value ) {
            return false;
        }
        $dt = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
        return $dt && $dt->format( 'Y-m-d\TH:i' ) === $value ? $dt : false;
    }

    public function admin_bookings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $notice = $this->admin_handle_actions();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view/filter parameter; no state-changing action is performed.
        $tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'calendar' ) );
        if ( ! in_array( $tab, [ 'calendar', 'workflow', 'settings', 'terms', 'inventory' ], true ) ) {
            $tab = 'calendar';
        }
        ?>
        <div class="wrap clr-admin-wrap">
            <h1>Vermietungen</h1>
            <?php echo wp_kses_post( $notice ); ?>
            <nav class="nav-tab-wrapper">
                <a class="<?php echo esc_attr( 'nav-tab' . ( 'calendar' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=calendar' ) ); ?>">Kalender & Buchungen</a>
                <a class="<?php echo esc_attr( 'nav-tab' . ( 'inventory' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=inventory' ) ); ?>">Geräte</a>
                <a class="<?php echo esc_attr( 'nav-tab' . ( 'workflow' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=workflow' ) ); ?>">Übergabe &amp; Rückgabe</a>
                <a class="<?php echo esc_attr( 'nav-tab' . ( 'terms' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=terms' ) ); ?>">Mietbedingungen</a>
                <a class="<?php echo esc_attr( 'nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=settings' ) ); ?>">Einstellungen</a>
            </nav>
            <?php
            if ( 'settings' === $tab ) {
                $this->render_admin_settings();
            } elseif ( 'calendar' === $tab ) {
                $this->render_admin_calendar_and_bookings();
            } else {
                do_action( 'rmwc_render_admin_tab_' . $tab );
            }
            ?>
        </div>
        <?php
    }

    private function render_admin_settings() {
        $origin  = get_option( 'clr_delivery_origin', '' );
        $api_key_from_constant = defined( 'RMWC_GOOGLE_ROUTES_API_KEY' );
        $has_api_key = '' !== $this->google_routes_api_key();
        $routes_enabled = 'yes' === get_option( 'clr_google_routes_enabled', 'no' );
        $enabled = 'yes' === get_option( 'clr_reminder_enabled', 'no' );
        $hours   = max( 1, (int) get_option( 'clr_reminder_hours', 24 ) );
        $subject = get_option( 'clr_reminder_subject', 'Erinnerung an Ihre Miete: %product%' );
        $body    = get_option( 'clr_reminder_body', "Hallo %name%,\n\nwir erinnern an Ihre bevorstehende Miete von %product%.\nAbholung/Mietbeginn: %pickup%\nRückgabe: %return%\n\nViele Grüße" );
        ?>
        <form method="post" class="clr-settings-form">
            <?php wp_nonce_field( 'clr_admin_action', 'clr_admin_nonce' ); ?>
            <input type="hidden" name="clr_action" value="save_settings">
            <h2>Lieferentfernung</h2>
            <p>Wenn Startadresse und Google-Routes-API-Key gesetzt sind, berechnet das Plugin die tatsächliche Fahrstrecke serverseitig und ordnet automatisch die passende Lieferzone des Produkts zu.</p>
            <table class="form-table"><tbody>
                <tr><th>Externer Dienst</th><td><label><input type="checkbox" name="clr_google_routes_enabled" value="1" <?php checked( $routes_enabled ); ?>> Google Routes API ausdrücklich aktivieren</label><p class="description"><strong>Datenschutz:</strong> Nur wenn aktiviert, wird bei der Entfernungsberechnung die vom Kunden eingegebene Lieferadresse zusammen mit der Startadresse an Google übertragen. Ohne Aktivierung erfolgen keine Google-Routes-Anfragen.</p></td></tr>
                <tr><th><label for="clr_delivery_origin">Startadresse</label></th><td><input class="regular-text" maxlength="250" type="text" id="clr_delivery_origin" name="clr_delivery_origin" value="<?php echo esc_attr( $origin ); ?>" placeholder="z. B. Besenhausen 1, 37133 Friedland"></td></tr>
                <tr><th><label for="clr_google_routes_api_key">Google Routes API Key</label></th><td><?php if ( $api_key_from_constant ) : ?><p><strong><?php esc_html_e( 'API-Key wird über wp-config.php bereitgestellt.', 'patsch9-rental-engine' ); ?></strong></p><p class="description"><?php esc_html_e( 'Der Key wird dadurch nicht in der WordPress-Datenbank gespeichert und kann hier nicht geändert werden.', 'patsch9-rental-engine' ); ?></p><?php else : ?><input class="regular-text" maxlength="255" type="password" id="clr_google_routes_api_key" name="clr_google_routes_api_key" value="" placeholder="<?php echo esc_attr( $has_api_key ? __( 'API-Key gespeichert – leer lassen zum Beibehalten', 'patsch9-rental-engine' ) : '' ); ?>" autocomplete="new-password"><p class="description"><?php esc_html_e( 'Der gespeicherte Key wird aus Sicherheitsgründen nicht zurück in das HTML-Formular geschrieben. Noch sicherer ist die Bereitstellung über RMWC_GOOGLE_ROUTES_API_KEY in wp-config.php.', 'patsch9-rental-engine' ); ?></p><?php if ( $has_api_key ) : ?><label><input type="checkbox" name="clr_google_routes_clear_key" value="1"> <?php esc_html_e( 'gespeicherten API-Key löschen', 'patsch9-rental-engine' ); ?></label><?php endif; ?><?php endif; ?></td></tr>
            </tbody></table>

            <h2>Erinnerungsmails</h2>
            <table class="form-table"><tbody>
                <tr><th>Aktiv</th><td><label><input type="checkbox" name="clr_reminder_enabled" value="1" <?php checked( $enabled ); ?>> Kunden vor der Miete erinnern</label></td></tr>
                <tr><th><label for="clr_reminder_hours">Vorlauf in Stunden</label></th><td><input type="number" min="1" step="1" id="clr_reminder_hours" name="clr_reminder_hours" value="<?php echo esc_attr( $hours ); ?>"></td></tr>
                <tr><th><label for="clr_reminder_subject">Betreff</label></th><td><input class="large-text" type="text" id="clr_reminder_subject" name="clr_reminder_subject" value="<?php echo esc_attr( $subject ); ?>"></td></tr>
                <tr><th><label for="clr_reminder_body">Text</label></th><td><textarea class="large-text" rows="8" id="clr_reminder_body" name="clr_reminder_body"><?php echo esc_textarea( $body ); ?></textarea><p class="description">Platzhalter: %name%, %product%, %pickup%, %return%, %order%</p></td></tr>
            </tbody></table>

            <h2>Daten bei Deinstallation</h2>
            <h2>Buchungsreservierung</h2>
            <table class="form-table"><tbody><tr><th><label for="clr_unpaid_hold_minutes">Unbezahlte Online-Reservierung halten</label></th><td><input type="number" min="5" max="1440" step="1" id="clr_unpaid_hold_minutes" name="clr_unpaid_hold_minutes" value="<?php echo esc_attr( max( 5, min( 1440, (int) get_option( 'clr_unpaid_hold_minutes', 60 ) ) ) ); ?>"> Minuten<p class="description">Nur unbezahlte Pending-Bestellungen mit einer Online-Zahlungsart werden nach dieser Frist automatisch storniert und der Mietbestand freigegeben. Zahlungsarten aus der Liste „Offline-Zahlungsarten“ (z. B. Rechnung/Überweisung) sind ausgenommen und bleiben bis zur manuellen bzw. gatewayseitigen Statusänderung reserviert. Das Plugin schützt diese Mietbestellungen zusätzlich vor der WooCommerce-eigenen Pending-Stornierung.</p></td></tr></tbody></table>
            <table class="form-table"><tbody><tr><th>Bereinigung</th><td><label><input type="checkbox" name="clr_delete_data_on_uninstall" value="1" <?php checked( 'yes' === get_option( 'clr_delete_data_on_uninstall', 'no' ) ); ?>> Buchungstabelle und Plugin-Einstellungen beim Löschen des Plugins endgültig entfernen</label><p class="description">Standardmäßig bleiben Buchungsdaten erhalten, damit Geschäftsunterlagen nicht versehentlich durch eine Plugin-Deinstallation gelöscht werden.</p></td></tr></tbody></table>
            <?php do_action( 'rmwc_render_admin_tab_settings_after' ); ?>
            <?php submit_button( 'Einstellungen speichern' ); ?>
        </form>
        <?php
    }

    private function rental_product_search_field( $name ) {
        echo '<select class="wc-product-search" style="width:100%" name="' . esc_attr( $name ) . '" data-placeholder="Mietprodukt suchen…" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select>';
    }

    private function render_admin_calendar_and_bookings() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view/filter parameter; no state-changing action is performed.
        $month = sanitize_text_field( wp_unslash( $_GET['month'] ?? wp_date( 'Y-m' ) ) );
        $month_start = preg_match( '/^\d{4}-\d{2}$/', $month ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', wp_timezone() ) : false;
        if ( ! $month_start || $month_start->format( 'Y-m' ) !== $month ) {
            $month = wp_date( 'Y-m' );
            $month_start = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', wp_timezone() );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view/filter parameter; no state-changing action is performed.
        $product_filter = absint( wp_unslash( $_GET['product_id'] ?? 0 ) );
        $month_end   = $month_start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
        $prev = $month_start->modify( '-1 month' )->format( 'Y-m' );
        $next = $month_start->modify( '+1 month' )->format( 'Y-m' );

        global $wpdb;
        if ( $product_filter ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $calendar_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE status IN ('reserved','blocked') AND start_at <= %s AND end_at >= %s AND product_id = %d ORDER BY start_at",
                    $this->table(),
                    $month_end->format( 'Y-m-d H:i:s' ),
                    $month_start->format( 'Y-m-d H:i:s' ),
                    $product_filter
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $calendar_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE status IN ('reserved','blocked') AND start_at <= %s AND end_at >= %s ORDER BY start_at",
                    $this->table(),
                    $month_end->format( 'Y-m-d H:i:s' ),
                    $month_start->format( 'Y-m-d H:i:s' )
                ),
                ARRAY_A
            );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $list_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY start_at DESC, id DESC LIMIT 500", $this->table() ), ARRAY_A );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view/filter parameter; no state-changing action is performed.
        $edit_booking_id = isset( $_GET['edit_booking'] ) ? absint( wp_unslash( $_GET['edit_booking'] ) ) : 0;
        $edit_row = $edit_booking_id ? $this->admin_booking_row( $edit_booking_id ) : null;
        ?>
        <?php if ( $edit_row ) :
            $edit_product = wc_get_product( (int) $edit_row['product_id'] );
            $edit_is_block = 'block' === $edit_row['source'];
            $edit_start_local = $edit_is_block && $edit_row['start_at'] ? ( new DateTimeImmutable( $edit_row['start_at'], wp_timezone() ) )->format( 'Y-m-d\TH:i' ) : '';
            $edit_end_local   = $edit_is_block && $edit_row['end_at'] ? ( new DateTimeImmutable( $edit_row['end_at'], wp_timezone() ) )->format( 'Y-m-d\TH:i' ) : '';
            ?>
            <section class="clr-admin-card clr-booking-edit-card">
                <div class="clr-v2-card-heading">
                    <div>
                        <?php /* translators: %d: internal rental booking ID. */ ?>
                        <h2><?php echo esc_html( sprintf( __( 'Buchung #%d bearbeiten', 'patsch9-rental-engine' ), (int) $edit_row['id'] ) ); ?></h2>
                        <p><?php echo esc_html( $edit_product ? $edit_product->get_name() : '#' . (int) $edit_row['product_id'] ); ?></p>
                    </div>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=calendar' ) ); ?>"><?php esc_html_e( 'Abbrechen', 'patsch9-rental-engine' ); ?></a>
                </div>
                <?php if ( 'reserved' !== sanitize_key( $edit_row['workflow_status'] ?? 'reserved' ) ) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e( 'Diese Vermietung wurde bereits übergeben und kann deshalb nicht mehr nachträglich umdatiert werden. Die Historie und Protokolle bleiben unveränderlich.', 'patsch9-rental-engine' ); ?></p></div>
                <?php else : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'clr_admin_action', 'clr_admin_nonce' ); ?>
                        <input type="hidden" name="clr_action" value="update_booking">
                        <input type="hidden" name="booking_id" value="<?php echo esc_attr( $edit_row['id'] ); ?>">
                        <?php if ( $edit_is_block ) : ?>
                            <div class="clr-form-cols">
                                <p><label><?php esc_html_e( 'Von', 'patsch9-rental-engine' ); ?><input type="datetime-local" name="block_start" value="<?php echo esc_attr( $edit_start_local ); ?>" required></label></p>
                                <p><label><?php esc_html_e( 'Bis', 'patsch9-rental-engine' ); ?><input type="datetime-local" name="block_end" value="<?php echo esc_attr( $edit_end_local ); ?>" required></label></p>
                            </div>
                        <?php else : ?>
                            <div class="clr-form-cols">
                                <p><label><?php esc_html_e( 'Abholdatum', 'patsch9-rental-engine' ); ?><input type="date" name="start_date" value="<?php echo esc_attr( $edit_row['start_date'] ); ?>" required></label></p>
                                <p><label><?php esc_html_e( 'Rückgabedatum', 'patsch9-rental-engine' ); ?><input type="date" name="end_date" value="<?php echo esc_attr( $edit_row['end_date'] ); ?>" required></label></p>
                            </div>
                        <?php endif; ?>
                        <?php if ( 'order' !== $edit_row['source'] ) : ?>
                            <p><label><?php esc_html_e( 'Anzahl', 'patsch9-rental-engine' ); ?><input type="number" name="quantity" min="1" max="<?php echo esc_attr( $this->capacity( (int) $edit_row['product_id'] ) ); ?>" value="<?php echo esc_attr( $edit_row['quantity'] ); ?>" required></label></p>
                        <?php else : ?>
                            <p><strong><?php esc_html_e( 'Anzahl:', 'patsch9-rental-engine' ); ?></strong> <?php echo esc_html( $edit_row['quantity'] ); ?> <span class="description"><?php esc_html_e( 'Die Menge einer bezahlten Bestellung wird hier bewusst nicht verändert.', 'patsch9-rental-engine' ); ?></span></p>
                        <?php endif; ?>
                        <?php if ( 'manual' === $edit_row['source'] ) : ?>
                            <div class="clr-form-cols">
                                <p><label><?php esc_html_e( 'Kundenname', 'patsch9-rental-engine' ); ?><input type="text" name="customer_name" maxlength="190" value="<?php echo esc_attr( $edit_row['customer_name'] ); ?>"></label></p>
                                <p><label><?php esc_html_e( 'E-Mail', 'patsch9-rental-engine' ); ?><input type="email" name="customer_email" maxlength="190" value="<?php echo esc_attr( $edit_row['customer_email'] ); ?>"></label></p>
                            </div>
                        <?php endif; ?>
                        <p><label><?php esc_html_e( 'Notiz', 'patsch9-rental-engine' ); ?><textarea name="note" rows="3" maxlength="2000"><?php echo esc_textarea( $edit_row['note'] ); ?></textarea></label></p>
                        <?php if ( 'order' === $edit_row['source'] ) : ?>
                            <p class="description"><?php esc_html_e( 'Eine Terminänderung aktualisiert die Mietdaten und Verfügbarkeit, nicht automatisch den bereits berechneten Bestellpreis. Die Änderung wird als Bestellnotiz protokolliert.', 'patsch9-rental-engine' ); ?></p>
                        <?php endif; ?>
                        <?php submit_button( __( 'Buchung speichern', 'patsch9-rental-engine' ), 'primary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <div class="clr-admin-grid">
            <section class="clr-admin-card">
                <h2>Manuelle Buchung</h2>
                <form method="post">
                    <?php wp_nonce_field( 'clr_admin_action', 'clr_admin_nonce' ); ?>
                    <input type="hidden" name="clr_action" value="add_manual">
                    <p><label>Produkt<?php $this->rental_product_search_field( 'product_id' ); ?></label></p>
                    <div class="clr-form-cols"><p><label>Abholdatum<input type="date" name="start_date" required></label></p><p><label>Rückgabedatum<input type="date" name="end_date" required></label></p></div>
                    <p><label>Anzahl<input type="number" name="quantity" min="1" step="1" value="1" required></label></p>
                    <p><label>Kundenname<input type="text" name="customer_name"></label></p>
                    <p><label>E-Mail<input type="email" name="customer_email"></label></p>
                    <p><label>Notiz<textarea name="note" rows="2"></textarea></label></p>
                    <button class="button button-primary">Buchung anlegen</button>
                </form>
            </section>
            <section class="clr-admin-card">
                <h2>Zeitraum sperren</h2>
                <form method="post">
                    <?php wp_nonce_field( 'clr_admin_action', 'clr_admin_nonce' ); ?>
                    <input type="hidden" name="clr_action" value="add_block">
                    <p><label>Produkt<?php $this->rental_product_search_field( 'product_id' ); ?></label></p>
                    <div class="clr-form-cols"><p><label>Von<input type="datetime-local" name="block_start" required></label></p><p><label>Bis<input type="datetime-local" name="block_end" required></label></p></div>
                    <p><label>Anzahl<input type="number" name="quantity" min="0" step="1" value="0"><span class="description">0 = gesamte Produktkapazität sperren</span></label></p>
                    <p><label>Grund/Notiz<textarea name="note" rows="3" placeholder="z. B. Wartung, eigene Veranstaltung"></textarea></label></p>
                    <button class="button">Sperre anlegen</button>
                </form>
            </section>
        </div>

        <section class="clr-admin-card clr-calendar-card">
            <div class="clr-admin-cal-head">
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&month=' . $prev . ( $product_filter ? '&product_id=' . $product_filter : '' ) ) ); ?>">‹</a>
                <h2><?php echo esc_html( wp_date( 'F Y', $month_start->getTimestamp(), wp_timezone() ) ); ?></h2>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&month=' . $next . ( $product_filter ? '&product_id=' . $product_filter : '' ) ) ); ?>">›</a>
            </div>
            <?php $this->render_month_calendar( $month_start, $calendar_rows ); ?>
        </section>

        <section class="clr-admin-card">
            <h2>Letzte Buchungen und Sperren</h2>
            <table class="widefat striped"><thead><tr><th>ID</th><th>Quelle</th><th>Produkt</th><th>Zeitraum</th><th>Kunde</th><th>Bestellung</th><th>Anzahl</th><th>Kaution</th><th>Status</th><th></th></tr></thead><tbody>
            <?php if ( ! $list_rows ) : ?><tr><td colspan="10">Noch keine Buchungen.</td></tr><?php endif; ?>
            <?php foreach ( $list_rows as $row ) :
                $p = wc_get_product( $row['product_id'] );
                $o = $row['order_id'] ? wc_get_order( $row['order_id'] ) : null;
                $deposit_status = $o ? ( $o->get_meta( '_clr_deposit_status', true ) ?: '–' ) : '–';
                ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td><?php echo esc_html( $row['source'] ); ?></td>
                    <td><?php echo esc_html( $p ? $p->get_name() : '#' . $row['product_id'] ); ?></td>
                    <td><?php echo esc_html( $this->format_datetime_display( $row['start_at'] ) . ' – ' . $this->format_datetime_display( $row['end_at'] ) ); ?></td>
                    <td><?php echo esc_html( $row['customer_name'] ?: '–' ); ?></td>
                    <td><?php if ( $o ) : ?><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">#<?php echo (int) $o->get_id(); ?></a><?php else : ?>–<?php endif; ?></td>
                    <td><?php echo (int) $row['quantity']; ?></td>
                    <td><?php echo esc_html( $deposit_status ); ?></td>
                    <td><?php echo esc_html( $row['status'] ); ?></td>
                    <td class="clr-row-actions">
                        <?php if ( in_array( $row['status'], [ 'reserved', 'blocked' ], true ) && 'reserved' === sanitize_key( $row['workflow_status'] ?? 'reserved' ) ) : ?>
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=calendar&edit_booking=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'patsch9-rental-engine' ); ?></a>
                            <form method="post" onsubmit="return confirm('<?php echo esc_js( 'order' === $row['source'] ? __( 'Diese Bestellbuchung wirklich stornieren? Die WooCommerce-Bestellung selbst bleibt bestehen.', 'patsch9-rental-engine' ) : __( 'Diesen Eintrag wirklich dauerhaft löschen?', 'patsch9-rental-engine' ) ); ?>');">
                                <?php wp_nonce_field( 'clr_admin_action', 'clr_admin_nonce' ); ?>
                                <input type="hidden" name="clr_action" value="delete_booking">
                                <input type="hidden" name="booking_id" value="<?php echo esc_attr( $row['id'] ); ?>">
                                <button type="submit" class="button-link-delete"><?php echo esc_html( 'order' === $row['source'] ? __( 'Stornieren', 'patsch9-rental-engine' ) : __( 'Löschen', 'patsch9-rental-engine' ) ); ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
        <?php
    }

    private function render_month_calendar( DateTimeImmutable $month_start, array $rows ) {
        $first_weekday = (int) $month_start->format( 'N' );
        $days_in_month = (int) $month_start->format( 't' );
        echo '<div class="clr-admin-calendar">';
        foreach ( [ 'Mo','Di','Mi','Do','Fr','Sa','So' ] as $day ) {
            echo '<div class="clr-admin-weekday">' . esc_html( $day ) . '</div>';
        }
        for ( $i = 1; $i < $first_weekday; $i++ ) {
            echo '<div class="clr-admin-day is-empty"></div>';
        }
        for ( $day = 1; $day <= $days_in_month; $day++ ) {
            $date = $month_start->setDate( (int) $month_start->format( 'Y' ), (int) $month_start->format( 'm' ), $day );
            $day_start = $date->setTime( 0, 0, 0 );
            $day_end   = $date->setTime( 23, 59, 59 );
            echo '<div class="clr-admin-day"><strong class="clr-admin-daynum">' . (int) $day . '</strong>';
            foreach ( $rows as $row ) {
                $start = new DateTimeImmutable( $row['start_at'], wp_timezone() );
                $end   = new DateTimeImmutable( $row['end_at'], wp_timezone() );
                if ( $start <= $day_end && $end >= $day_start ) {
                    $p = wc_get_product( $row['product_id'] );
                    $label = ( $p ? $p->get_name() : '#' . $row['product_id'] ) . ' ×' . (int) $row['quantity'];
                    $event_class = 'blocked' === $row['status'] ? 'is-block' : 'is-booking';
                    echo '<div class="' . esc_attr( 'clr-admin-event ' . $event_class ) . '" title="' . esc_attr( $this->format_datetime_display( $row['start_at'] ) . ' – ' . $this->format_datetime_display( $row['end_at'] ) ) . '">' . esc_html( $label ) . '</div>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
    }

    public function send_due_reminders() {
        if ( 'yes' !== get_option( 'clr_reminder_enabled', 'no' ) ) {
            return;
        }
        global $wpdb;
        $scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id();
        $lock  = 'clr_rem_' . substr( hash( 'sha256', $scope ), 0, 32 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $got   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
        if ( 1 !== $got ) {
            return;
        }
        try {
            $hours = max( 1, (int) get_option( 'clr_reminder_hours', 24 ) );
            $now   = new DateTimeImmutable( 'now', wp_timezone() );
            $until = $now->modify( '+' . $hours . ' hours' );
            $table = $this->table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE status = 'reserved' AND reminder_sent_at IS NULL AND customer_email != '' AND start_at > %s AND start_at <= %s ORDER BY start_at LIMIT 100",
                    $table,
                    $now->format( 'Y-m-d H:i:s' ),
                    $until->format( 'Y-m-d H:i:s' )
                ),
                ARRAY_A
            );

            $subject_tpl = get_option( 'clr_reminder_subject', 'Erinnerung an Ihre Miete: %product%' );
            $body_tpl    = get_option( 'clr_reminder_body', "Hallo %name%,\n\nwir erinnern an Ihre bevorstehende Miete von %product%.\nAbholung/Mietbeginn: %pickup%\nRückgabe: %return%\n\nViele Grüße" );

            foreach ( $rows as $row ) {
                $product = wc_get_product( $row['product_id'] );
                $order   = $row['order_id'] ? wc_get_order( $row['order_id'] ) : null;
                $replace = [
                    '%name%'    => $row['customer_name'] ?: 'Guten Tag',
                    '%product%' => $product ? $product->get_name() : 'Mietartikel',
                    '%pickup%'  => $this->format_datetime_display( $row['start_at'] ),
                    '%return%'  => $this->format_datetime_display( $row['end_at'] ),
                    '%order%'   => $order ? '#' . $order->get_order_number() : '',
                ];
                $recipient = sanitize_email( $row['customer_email'] );
                if ( ! is_email( $recipient ) ) {
                    continue;
                }
                $subject = sanitize_text_field( strtr( $subject_tpl, $replace ) );
                $message = sanitize_textarea_field( strtr( $body_tpl, $replace ) );
                $mailer  = function_exists( 'WC' ) && WC() ? WC()->mailer() : null;
                if ( $mailer instanceof WC_Emails ) {
                    $body = $mailer->wrap_message( $subject, $message );
                    if ( $mailer->send( $recipient, $subject, $body ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                        $wpdb->update( $table, [ 'reminder_sent_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row['id'] ], [ '%s' ], [ '%d' ] );
                    }
                }
            }
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private function migrate_legacy_bookings() {
        if ( 'yes' === get_option( 'clr_legacy_migration_done_v1', 'no' ) ) {
            return;
        }
        global $wpdb;
        $table = $this->table();
        for ( $batch = 0; $batch < 20; $batch++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, product_id, start_date, end_date FROM %i WHERE (start_at IS NULL OR start_at = '0000-00-00 00:00:00') AND start_date IS NOT NULL AND end_date IS NOT NULL LIMIT 500", $table ), ARRAY_A );
            if ( ! $rows ) {
                break;
            }
            foreach ( $rows as $row ) {
                $legacy_end = DateTimeImmutable::createFromFormat( '!Y-m-d', $row['end_date'], wp_timezone() );
                $legacy_end_date = $legacy_end ? $legacy_end->modify( '+1 day' )->format( 'Y-m-d' ) : $row['end_date'];
                $interval = $this->build_interval( (int) $row['product_id'], $row['start_date'], $legacy_end_date );
                if ( is_wp_error( $interval ) ) {
                    // Mark impossible legacy rows as cancelled rather than silently blocking forever.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                    $wpdb->update( $table, [ 'status' => 'cancelled' ], [ 'id' => (int) $row['id'] ], [ '%s' ], [ '%d' ] );
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                $wpdb->update( $table, [ 'start_at' => $interval['start_at'], 'end_at' => $interval['end_at'] ], [ 'id' => (int) $row['id'] ], [ '%s','%s' ], [ '%d' ] );
            }
        }
        update_option( 'clr_legacy_migration_done_v1', 'yes', false );
    }


    public function register_privacy_exporter( $exporters ) {
        $exporters['patsch9-rental-engine'] = [
            'exporter_friendly_name' => __( ' – Vermietungsbuchungen', 'patsch9-rental-engine' ),
            'callback'               => [ $this, 'privacy_exporter' ],
        ];
        return $exporters;
    }

    public function privacy_exporter( $email_address, $page = 1 ) {
        global $wpdb;
        $email = sanitize_email( $email_address );
        if ( ! is_email( $email ) ) {
            return [ 'data' => [], 'done' => true ];
        }
        $page     = max( 1, absint( $page ) );
        $per_page = 50;
        $offset   = ( $page - 1 ) * $per_page;
        $table    = $this->table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $table, $email, $per_page, $offset ), ARRAY_A );
        $data = [];
        foreach ( $rows as $row ) {
            $product = wc_get_product( (int) $row['product_id'] );
            $data[] = [
                'group_id'    => 'patsch9-rental-engine-bookings',
                'group_label' => __( 'Vermietungsbuchungen', 'patsch9-rental-engine' ),
                'item_id'     => 'booking-' . (int) $row['id'],
                'data'        => [
                    [ 'name' => __( 'Produkt', 'patsch9-rental-engine' ), 'value' => $product ? $product->get_name() : '#' . (int) $row['product_id'] ],
                    [ 'name' => __( 'Mietbeginn', 'patsch9-rental-engine' ), 'value' => (string) $row['start_at'] ],
                    [ 'name' => __( 'Rückgabe', 'patsch9-rental-engine' ), 'value' => (string) $row['end_at'] ],
                    [ 'name' => __( 'Anzahl', 'patsch9-rental-engine' ), 'value' => (int) $row['quantity'] ],
                    [ 'name' => __( 'Name', 'patsch9-rental-engine' ), 'value' => (string) $row['customer_name'] ],
                    [ 'name' => __( 'E-Mail', 'patsch9-rental-engine' ), 'value' => (string) $row['customer_email'] ],
                    [ 'name' => __( 'Notiz', 'patsch9-rental-engine' ), 'value' => (string) $row['note'] ],
                    [ 'name' => __( 'Bestellung', 'patsch9-rental-engine' ), 'value' => (int) $row['order_id'] ],
                ],
            ];
        }
        return [ 'data' => $data, 'done' => count( $rows ) < $per_page ];
    }

    public function register_privacy_eraser( $erasers ) {
        $erasers['patsch9-rental-engine'] = [
            'eraser_friendly_name' => __( ' – Vermietungsbuchungen', 'patsch9-rental-engine' ),
            'callback'             => [ $this, 'privacy_eraser' ],
        ];
        return $erasers;
    }

    public function privacy_eraser( $email_address, $page = 1 ) {
        global $wpdb;
        $email = sanitize_email( $email_address );
        if ( ! is_email( $email ) ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }

        // The eraser mutates the result set. Therefore, always take the next
        // batch from the beginning instead of using OFFSET, which could skip
        // rows after earlier batches have been anonymized.
        $per_page = 50;
        $table    = $this->table();
        $now      = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM %i WHERE customer_email = %s AND NOT (status IN ('reserved','blocked') AND end_at IS NOT NULL AND end_at >= %s) ORDER BY id ASC LIMIT %d",
                $table,
                $email,
                $now,
                $per_page
            ),
            ARRAY_A
        );

        $removed = false;
        foreach ( $rows as $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $updated = $wpdb->update(
                $table,
                [
                    'customer_name'  => '',
                    'customer_email' => '',
                    'note'           => '',
                ],
                [ 'id' => (int) $row['id'] ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            if ( false !== $updated ) {
                $removed = true;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $retained = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM %i WHERE customer_email = %s AND status IN ('reserved','blocked') AND end_at IS NOT NULL AND end_at >= %s LIMIT 1",
                $table,
                $email,
                $now
            )
        );
        $messages = [];
        if ( $retained ) {
            $messages[] = __( 'Personenbezogene Daten aktiver oder zukünftiger Vermietungen wurden vorerst beibehalten, damit die Vermietung erfüllt werden kann.', 'patsch9-rental-engine' );
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => count( $rows ) < $per_page,
        ];
    }

    public function add_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $content = '<p>' . esc_html__( 'Dieses Plugin speichert für Vermietungsbuchungen Produkt, Mietzeitraum, Anzahl sowie bei Buchungen Name und E-Mail-Adresse zusätzlich in einer eigenen Buchungstabelle. Diese Daten dienen der Verfügbarkeitsprüfung, Verwaltung und optionalen Erinnerung vor Mietbeginn.', 'patsch9-rental-engine' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Version 2 speichert außerdem die jeweils akzeptierte Version der Mietbedingungen als Bestell-Snapshot sowie Mietverträge und Kautionsquittungen als unveränderliche Geschäftsdokumente. Dokument-Snapshots können Kundendaten, Mietgegenstände, Mietzeitraum, Kautionsvorgänge und die akzeptierten Mietbedingungen enthalten. Physische Geräte können mit Inventar- und Seriennummern verwaltet und Vermietungen zugeordnet werden.', 'patsch9-rental-engine' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Als Miet-Zubehör markierte WooCommerce-Produkte werden aus dem öffentlichen Shop ausgeblendet und nur im Zusammenhang mit einem konfigurierten Mietartikel verwendet. Dabei gelten weiterhin die normalen WooCommerce-Daten zu Preis, Steuer und Lagerbestand des Zubehörprodukts.', 'patsch9-rental-engine' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Wenn die optionale automatische Lieferentfernung ausdrücklich aktiviert ist, werden Startadresse und die vom Kunden eingegebene Lieferadresse zur Streckenberechnung an die Google Routes API übertragen. Ohne diese Aktivierung sendet das Plugin keine Lieferadressen an Google.', 'patsch9-rental-engine' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Zum Schutz öffentlicher Verfügbarkeits- und Entfernungsabfragen vor Missbrauch verwendet das Plugin kurzlebige Rate-Limit-Einträge. Dafür wird die IP-Adresse nicht im Klartext gespeichert, sondern nur ein mit dem WordPress-Salt gebildeter, kurzlebiger Hash verwendet.', 'patsch9-rental-engine' ) . '</p>';
        wp_add_privacy_policy_content( __( 'Rental Manager for WooCommerce', 'patsch9-rental-engine' ), wp_kses_post( $content ) );
    }

}
