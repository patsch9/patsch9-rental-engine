<?php
/**
 * Optional compatibility helpers for third-party/admin clients.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Integrations {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Germanized / EU Order Withdrawal Button exposes these filters for
        // extension-defined product classifications.
        add_filter( 'eu_owb_woocommerce_product_type_options', [ $this, 'germanized_product_types' ], 30 );
        add_filter( 'eu_owb_woocommerce_product_matches_type', [ $this, 'germanized_product_matches_type' ], 30, 3 );

        // Keep the human-readable deposit wording correct for old orders too.
        add_filter( 'woocommerce_order_item_display_meta_value', [ $this, 'display_order_item_meta_value' ], 30, 3 );

        // Germanized adds an "incl. VAT" summary row to order e-mails when
        // prices are displayed including tax. With mixed tax rates that row can
        // carry the percentage of only one tax class while showing the combined
        // tax amount. Replace that ambiguous aggregate row for rental orders by
        // one row per actual WooCommerce order tax line.
        add_filter( 'woocommerce_get_order_item_totals', [ $this, 'itemize_mixed_order_tax_totals' ], 999999, 3 );

        // WooCommerce mobile clients consume the authenticated Orders REST API.
        // Add rental workflow information to standard order/line-item metadata
        // for shop staff without exposing admin workflow links to customers.
        add_filter( 'woocommerce_rest_prepare_shop_order_object', [ $this, 'rest_order_rental_details' ], 30, 3 );

        // Persist staff-visible order custom fields so the official WooCommerce
        // apps can actually discover them. Runtime-only REST metadata is not
        // sufficient for the app's Custom Fields screen.
        add_action( 'rmwc_bookings_created', [ $this, 'sync_mobile_order_meta' ], 60, 2 );
        add_action( 'rmwc_booking_updated', [ $this, 'sync_mobile_order_meta_from_booking_change' ], 60, 3 );
        add_action( 'rmwc_booking_cancelled', [ $this, 'sync_mobile_order_meta_from_booking_cancel' ], 60, 2 );
        add_action( 'rmwc_workflow_status_changed', [ $this, 'sync_mobile_order_meta_from_workflow' ], 60, 3 );
        add_action( 'rmwc_deposit_saved', [ $this, 'sync_mobile_order_meta_from_deposit' ], 60, 5 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'sync_mobile_order_meta_by_id' ], 70 );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'sync_mobile_order_meta_by_id' ], 70 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'sync_mobile_order_meta_by_id' ], 70 );
    }

    public function germanized_product_types( $options ) {
        if ( ! is_array( $options ) ) {
            $options = [];
        }
        $options['rmwc']           = __( 'Vermietung', 'patsch9-rental-engine' );
        $options['rmwc_accessory'] = __( 'Miet-Zubehör', 'patsch9-rental-engine' );
        return $options;
    }

    private function product_config_id( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return 0;
        }
        if ( $product instanceof WC_Product_Variation ) {
            return absint( $product->get_parent_id() ?: $product->get_id() );
        }
        return absint( $product->get_id() );
    }

    public function germanized_product_matches_type( $matches_type, $product, $types ) {
        if ( ! $product instanceof WC_Product ) {
            return $matches_type;
        }
        $types     = array_map( 'sanitize_key', (array) $types );
        $config_id = $this->product_config_id( $product );
        if ( ! $config_id ) {
            return $matches_type;
        }

        if ( in_array( 'rmwc', $types, true ) && 'yes' === get_post_meta( $config_id, '_clr_rental_enabled', true ) ) {
            return true;
        }
        if ( in_array( 'rmwc_accessory', $types, true ) && 'yes' === get_post_meta( $config_id, '_clr_accessory_only', true ) ) {
            return true;
        }
        return $matches_type;
    }

    private function order_is_rental_order( WC_Order $order ) {
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            if ( is_array( $item->get_meta( '_clr_rental_data', true ) ) || 'yes' === $item->get_meta( '_clr_rental_accessory', true ) ) {
                return true;
            }
        }
        return false;
    }

    private function order_tax_rate_rows( WC_Order $order ) {
        $rates = [];
        foreach ( $order->get_items( 'tax' ) as $tax_item ) {
            if ( ! $tax_item instanceof WC_Order_Item_Tax ) {
                continue;
            }
            $amount = (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
            if ( abs( $amount ) < 0.000001 ) {
                continue;
            }

            if ( is_callable( [ $tax_item, 'get_rate_percent' ] ) ) {
                $percent = (float) $tax_item->get_rate_percent();
            } else {
                $rate_id = absint( $tax_item->get_rate_id() );
                $percent = $rate_id && is_callable( [ 'WC_Tax', 'get_rate_percent_value' ] ) ? (float) WC_Tax::get_rate_percent_value( $rate_id ) : 0.0;
            }
            $key = number_format( $percent, 4, '.', '' );
            if ( ! isset( $rates[ $key ] ) ) {
                $rates[ $key ] = [
                    'percent' => $percent,
                    'amount'  => 0.0,
                ];
            }
            $rates[ $key ]['amount'] += $amount;
        }
        uasort(
            $rates,
            static function( $a, $b ) {
                return (float) $b['percent'] <=> (float) $a['percent'];
            }
        );
        return array_values( $rates );
    }

    private function tax_percentage_label( $percent ) {
        $decimals = abs( (float) $percent - round( (float) $percent ) ) < 0.00001 ? 0 : 2;
        return number_format_i18n( (float) $percent, $decimals );
    }

    /**
     * Display every actually stored VAT rate separately for mixed-rate rental
     * orders. This only changes presentation; order item taxes and totals remain
     * untouched and therefore stay identical for Lexware/accounting exports.
     */
    public function itemize_mixed_order_tax_totals( $total_rows, $order, $tax_display ) {
        if ( ! is_array( $total_rows ) || ! $order instanceof WC_Order || ! $this->order_is_rental_order( $order ) ) {
            return $total_rows;
        }

        $rates = $this->order_tax_rate_rows( $order );
        if ( count( $rates ) < 2 ) {
            return $total_rows;
        }

        $first_tax_position = null;
        $clean_rows         = [];
        foreach ( $total_rows as $key => $row ) {
            $label  = is_array( $row ) ? wp_strip_all_tags( (string) ( $row['label'] ?? '' ) ) : '';
            $type   = is_array( $row ) ? sanitize_key( (string) ( $row['type'] ?? '' ) ) : '';
            $is_tax = 'tax' === $type || ( '' !== $label && preg_match( '/(?:MwSt\.?|Umsatzsteuer|Mehrwertsteuer|VAT)/iu', $label ) );
            if ( $is_tax ) {
                if ( null === $first_tax_position ) {
                    $first_tax_position = count( $clean_rows );
                }
                continue;
            }
            $clean_rows[ $key ] = $row;
        }

        // Germanized normally appends its inclusive-tax notice close to the
        // order total/payment rows. If no aggregate row was found, place our
        // itemized rows directly after the grand total.
        if ( null === $first_tax_position ) {
            $keys = array_keys( $clean_rows );
            $order_total_index = array_search( 'order_total', $keys, true );
            $first_tax_position = false === $order_total_index ? count( $clean_rows ) : $order_total_index + 1;
        }

        $tax_rows = [];
        foreach ( $rates as $rate ) {
            $percent = $this->tax_percentage_label( $rate['percent'] );
            if ( 'incl' === $tax_display ) {
                $label = sprintf(
                    /* translators: %s: tax percentage. */
                    __( 'inkl. %s %% MwSt.', 'patsch9-rental-engine' ),
                    $percent
                );
            } else {
                $label = sprintf(
                    /* translators: %s: tax percentage. */
                    __( '%s %% MwSt.', 'patsch9-rental-engine' ),
                    $percent
                );
            }
            $tax_rows[ 'clr_tax_' . sanitize_title( (string) $rate['percent'] ) ] = [
                'type'  => 'tax',
                'label' => $label . ':',
                'value' => wc_price( $rate['amount'], [ 'currency' => $order->get_currency() ] ),
            ];
        }

        $before = array_slice( $clean_rows, 0, $first_tax_position, true );
        $after  = array_slice( $clean_rows, $first_tax_position, null, true );
        return $before + $tax_rows + $after;
    }

    public function display_order_item_meta_value( $display_value, $meta, $item ) {
        if ( ! $item instanceof WC_Order_Item_Product || ! is_object( $meta ) || 'Kautionshinterlegung' !== (string) ( $meta->key ?? '' ) ) {
            return $display_value;
        }
        $rental = $item->get_meta( '_clr_rental_data', true );
        if ( ! is_array( $rental ) || (float) ( $rental['deposit'] ?? 0 ) <= 0 ) {
            return $display_value;
        }
        return 'cash' === sanitize_key( $rental['deposit_method'] ?? 'online' )
            ? __( 'Separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' )
            : __( 'Online mit Bestellung hinterlegt', 'patsch9-rental-engine' );
    }

    private function booking_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_bookings';
    }

    private function assignment_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_asset_assignments';
    }

    private function asset_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_assets';
    }

    private function workflow_status_label( $status ) {
        $labels = [
            'reserved'    => __( 'Reserviert', 'patsch9-rental-engine' ),
            'handed_over' => __( 'Übergeben', 'patsch9-rental-engine' ),
            'returned'    => __( 'Zurückgegeben', 'patsch9-rental-engine' ),
        ];
        $status = sanitize_key( $status );
        return $labels[ $status ] ?? sanitize_text_field( $status );
    }

    private function append_rest_meta( array &$meta_data, $key, $value, $id = 0 ) {
        $key   = sanitize_text_field( $key );
        $value = sanitize_text_field( $value );
        if ( '' === $key || '' === $value ) {
            return;
        }
        foreach ( $meta_data as $row ) {
            if ( is_array( $row ) && (string) ( $row['key'] ?? '' ) === $key ) {
                return;
            }
        }
        $meta_data[] = [ 'id' => absint( $id ), 'key' => $key, 'value' => $value ];
    }

    private function mobile_order_summary( WC_Order $order ) {
        $periods        = [];
        $statuses       = [];
        $asset_labels   = [];
        $action_labels  = [];
        $deposit_labels = [];
        $transport_labels = [];

        global $wpdb;
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $rental = $item->get_meta( '_clr_rental_data', true );
            if ( ! is_array( $rental ) ) {
                continue;
            }

            $product_name = sanitize_text_field( $item->get_name() );
            $start_at     = sanitize_text_field( $rental['start_at'] ?? '' );
            $end_at       = sanitize_text_field( $rental['end_at'] ?? '' );
            if ( $start_at && $end_at ) {
                $periods[] = sprintf(
                    '%s: %s - %s',
                    $product_name,
                    wp_date( 'd.m.Y H:i', strtotime( $start_at ) ),
                    wp_date( 'd.m.Y H:i', strtotime( $end_at ) )
                );
            }
            $fulfilment = sanitize_key( $rental['fulfilment'] ?? '' );
            $transport_map = [
                'pickup'          => __( 'Abholung und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
                'delivery'        => __( 'Lieferung durch Vermieter und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
                'delivery_return' => __( 'Lieferung und Abholung durch Vermieter', 'patsch9-rental-engine' ),
            ];
            if ( isset( $transport_map[ $fulfilment ] ) ) {
                $transport_labels[] = $product_name . ': ' . $transport_map[ $fulfilment ];
            }

            if ( (float) ( $rental['deposit'] ?? 0 ) > 0 ) {
                $deposit_labels[] = sprintf(
                    '%s: %s',
                    $product_name,
                    'cash' === sanitize_key( $rental['deposit_method'] ?? 'online' )
                        ? __( 'Separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' )
                        : __( 'Online mit Bestellung hinterlegt', 'patsch9-rental-engine' )
                );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $booking = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE order_id = %d AND order_item_id = %d AND status <> 'cancelled' ORDER BY id DESC LIMIT 1",
                    $this->booking_table(),
                    $order->get_id(),
                    absint( $item_id )
                ),
                ARRAY_A
            );
            if ( ! is_array( $booking ) ) {
                continue;
            }

            $status       = sanitize_key( $booking['workflow_status'] ?? 'reserved' );
            $status_label = $this->workflow_status_label( $status );
            $statuses[]   = $product_name . ': ' . $status_label;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $assets = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT a.inventory_number FROM %i aa INNER JOIN %i a ON a.id = aa.asset_id WHERE aa.booking_id = %d AND aa.status <> 'cancelled' ORDER BY a.inventory_number, a.id",
                    $this->assignment_table(),
                    $this->asset_table(),
                    absint( $booking['id'] )
                )
            );
            if ( is_array( $assets ) && $assets ) {
                $asset_labels[] = $product_name . ': ' . implode( ', ', array_map( 'sanitize_text_field', $assets ) );
            }

            $mode = '';
            if ( 'reserved' === $status ) {
                $mode = 'handover';
            } elseif ( 'handed_over' === $status ) {
                $mode = 'return';
            }
            if ( $mode ) {
                $label = 'handover' === $mode ? __( 'Übergabe öffnen', 'patsch9-rental-engine' ) : __( 'Rückgabe öffnen', 'patsch9-rental-engine' );
                $url   = add_query_arg(
                    [
                        'page'       => 'clr-rentals',
                        'tab'        => 'workflow',
                        'mode'       => $mode,
                        'booking_id' => absint( $booking['id'] ),
                    ],
                    admin_url( 'admin.php' )
                );
                $action_labels[] = $product_name . ': ' . $label . ' – ' . $url;
            }
        }

        return [
            'Vermietung: Zeitraum' => implode( ' | ', array_unique( $periods ) ),
            'Vermietung: Status'   => implode( ' | ', array_unique( $statuses ) ),
            'Vermietung: Geräte'    => implode( ' | ', array_unique( $asset_labels ) ),
            'Vermietung: Transport' => implode( ' | ', array_unique( $transport_labels ) ),
            'Vermietung: Kaution'   => implode( ' | ', array_unique( $deposit_labels ) ),
            'Vermietung: Aktion'    => implode( ' | ', array_unique( $action_labels ) ),
        ];
    }

    public function sync_mobile_order_meta( $order, $booking_ids = [] ) {
        unset( $booking_ids );
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( absint( $order ) );
        }
        if ( ! $order ) {
            return;
        }
        $summary = $this->mobile_order_summary( $order );
        $has_rental = false;
        foreach ( $summary as $key => $value ) {
            if ( '' !== $value ) {
                $order->update_meta_data( $key, $value );
                $has_rental = true;
            } else {
                $order->delete_meta_data( $key );
            }
        }
        if ( ! $has_rental ) {
            $order->save_meta_data();
            return;
        }

        $order->save_meta_data();

        // The mobile apps always expose private order notes. Add the current
        // workflow URL once per state so staff can copy/tap it even if their
        // app version does not render URL custom fields interactively.
        $action = (string) ( $summary['Vermietung: Aktion'] ?? '' );
        if ( '' !== $action ) {
            $signature = hash( 'sha256', $action );
            if ( ! hash_equals( (string) $order->get_meta( '_clr_mobile_action_note_signature', true ), $signature ) ) {
                $order->add_order_note( 'Vermietung – mobile Aktion: ' . $action );
                $order->update_meta_data( '_clr_mobile_action_note_signature', $signature );
                $order->save_meta_data();
            }
        }
    }

    public function sync_mobile_order_meta_by_id( $order_id ) {
        $this->sync_mobile_order_meta( absint( $order_id ), [] );
    }

    public function sync_mobile_order_meta_from_booking_change( $booking_id, $old_row, $new_row ) {
        unset( $booking_id );
        $row = is_array( $new_row ) ? $new_row : ( is_array( $old_row ) ? $old_row : [] );
        if ( ! empty( $row['order_id'] ) ) {
            $this->sync_mobile_order_meta_by_id( absint( $row['order_id'] ) );
        }
    }

    public function sync_mobile_order_meta_from_booking_cancel( $booking_id, $order_id ) {
        unset( $booking_id );
        if ( $order_id ) {
            $this->sync_mobile_order_meta_by_id( absint( $order_id ) );
        }
    }

    public function sync_mobile_order_meta_from_workflow( $order, $booking_id, $status ) {
        unset( $booking_id, $status );
        $this->sync_mobile_order_meta( $order, [] );
    }

    public function sync_mobile_order_meta_from_deposit( $order, $old_status, $status, $refunded, $retained ) {
        unset( $old_status, $status, $refunded, $retained );
        $this->sync_mobile_order_meta( $order, [] );
    }

    /**
     * Add staff-only workflow hints to WooCommerce order REST responses.
     * Unknown REST fields are intentionally avoided; information is placed in
     * the standard line_items.meta_data collection already consumed by clients.
     */
    public function rest_order_rental_details( $response, $order, $request ) {
        unset( $request );
        if ( ! $response instanceof WP_REST_Response || ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
            return $response;
        }

        // Repair/populate persisted app-visible custom fields on first staff
        // access too, so orders created before this build become useful in the
        // mobile app without requiring a status change.
        $this->sync_mobile_order_meta( $order, [] );
        $order = wc_get_order( $order->get_id() ) ?: $order;
        $data = $response->get_data();
        if ( empty( $data['line_items'] ) || ! is_array( $data['line_items'] ) ) {
            return $response;
        }

        $order_meta    = isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ? $data['meta_data'] : [];
        $periods       = [];
        $statuses      = [];
        $asset_labels  = [];
        $action_labels = [];
        $deposit_labels = [];

        global $wpdb;
        foreach ( $data['line_items'] as &$line_item ) {
            $item_id = absint( $line_item['id'] ?? 0 );
            $item    = $item_id ? $order->get_item( $item_id ) : false;
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $rental = $item->get_meta( '_clr_rental_data', true );
            if ( ! is_array( $rental ) ) {
                continue;
            }

            $product_name = sanitize_text_field( $item->get_name() );
            $start_at     = sanitize_text_field( $rental['start_at'] ?? '' );
            $end_at       = sanitize_text_field( $rental['end_at'] ?? '' );
            if ( $start_at && $end_at ) {
                $periods[] = sprintf(
                    '%s: %s - %s',
                    $product_name,
                    wp_date( 'd.m.Y H:i', strtotime( $start_at ) ),
                    wp_date( 'd.m.Y H:i', strtotime( $end_at ) )
                );
            }
            if ( (float) ( $rental['deposit'] ?? 0 ) > 0 ) {
                $deposit_labels[] = sprintf(
                    '%s: %s',
                    $product_name,
                    'cash' === sanitize_key( $rental['deposit_method'] ?? 'online' )
                        ? __( 'Separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' )
                        : __( 'Online mit Bestellung hinterlegt', 'patsch9-rental-engine' )
                );
            }

            $meta_data = isset( $line_item['meta_data'] ) && is_array( $line_item['meta_data'] ) ? $line_item['meta_data'] : [];
            foreach ( $meta_data as &$meta_row ) {
                if ( is_array( $meta_row ) && 'Kautionshinterlegung' === (string) ( $meta_row['key'] ?? '' ) && (float) ( $rental['deposit'] ?? 0 ) > 0 ) {
                    $meta_row['value'] = 'cash' === sanitize_key( $rental['deposit_method'] ?? 'online' )
                        ? __( 'Separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' )
                        : __( 'Online mit Bestellung hinterlegt', 'patsch9-rental-engine' );
                }
            }
            unset( $meta_row );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $booking = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE order_id = %d AND order_item_id = %d AND status <> 'cancelled' ORDER BY id DESC LIMIT 1",
                    $this->booking_table(),
                    $order->get_id(),
                    $item_id
                ),
                ARRAY_A
            );
            if ( is_array( $booking ) ) {
                $status       = sanitize_key( $booking['workflow_status'] ?? 'reserved' );
                $status_label = $this->workflow_status_label( $status );
                $this->append_rest_meta( $meta_data, __( 'Mietstatus', 'patsch9-rental-engine' ), $status_label );
                $statuses[] = $product_name . ': ' . $status_label;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
                $assets = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT a.inventory_number FROM %i aa INNER JOIN %i a ON a.id = aa.asset_id WHERE aa.booking_id = %d AND aa.status <> 'cancelled' ORDER BY a.inventory_number, a.id",
                        $this->assignment_table(),
                        $this->asset_table(),
                        absint( $booking['id'] )
                    )
                );
                if ( is_array( $assets ) && $assets ) {
                    $assets_text = implode( ', ', array_map( 'sanitize_text_field', $assets ) );
                    $this->append_rest_meta( $meta_data, __( 'Mietgerät(e)', 'patsch9-rental-engine' ), $assets_text );
                    $asset_labels[] = $product_name . ': ' . $assets_text;
                }

                $mode = '';
                if ( 'reserved' === $status ) {
                    $mode = 'handover';
                } elseif ( 'handed_over' === $status ) {
                    $mode = 'return';
                }
                if ( $mode ) {
                    $label = 'handover' === $mode ? __( 'Übergabe öffnen', 'patsch9-rental-engine' ) : __( 'Rückgabe öffnen', 'patsch9-rental-engine' );
                    $url   = add_query_arg(
                        [
                            'page'       => 'clr-rentals',
                            'tab'        => 'workflow',
                            'mode'       => $mode,
                            'booking_id' => absint( $booking['id'] ),
                        ],
                        admin_url( 'admin.php' )
                    );
                    $action_text = $label . ': ' . $url;
                    $this->append_rest_meta( $meta_data, __( 'Mietaktion', 'patsch9-rental-engine' ), $action_text );
                    $action_labels[] = $product_name . ': ' . $action_text;
                }
            }
            $line_item['meta_data'] = $meta_data;
        }
        unset( $line_item );

        // The official WooCommerce apps expose order custom fields. Mirror the
        // most important rental information there as staff-only REST metadata;
        // line-item metadata above remains useful for other API clients.
        $persisted_ids = [];
        foreach ( $order->get_meta_data() as $meta_object ) {
            if ( is_object( $meta_object ) && isset( $meta_object->key ) ) {
                $persisted_ids[ (string) $meta_object->key ] = absint( $meta_object->id ?? 0 );
            }
        }
        $order_fields = [
            'Vermietung: Zeitraum' => implode( ' | ', array_unique( $periods ) ),
            'Vermietung: Status'   => implode( ' | ', array_unique( $statuses ) ),
            'Vermietung: Geräte'   => implode( ' | ', array_unique( $asset_labels ) ),
            'Vermietung: Kaution'  => implode( ' | ', array_unique( $deposit_labels ) ),
            'Vermietung: Aktion'   => implode( ' | ', array_unique( $action_labels ) ),
        ];
        foreach ( $order_fields as $key => $value ) {
            if ( '' !== $value ) {
                $this->append_rest_meta( $order_meta, $key, $value, $persisted_ids[ $key ] ?? 0 );
            }
        }
        $data['meta_data'] = $order_meta;

        $response->set_data( $data );
        return $response;
    }
}
