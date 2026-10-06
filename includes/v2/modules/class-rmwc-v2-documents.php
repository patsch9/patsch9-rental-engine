<?php
/**
 * Rental contracts and immutable PDF documents.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Documents {
    private static $instance = null;
    private $admin_footer_forms = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_order_status_processing', [ $this, 'ensure_contract_for_order' ], 30 );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'ensure_contract_for_order' ], 30 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'ensure_contract_for_order' ], 30 );
        // Retry the automatic contract mail on the first meaningful order
        // status if the checkout-time send did not succeed. The persisted
        // document ID makes the operation idempotent and prevents duplicates.
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_send_contract_email_for_order' ], 40 );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'maybe_send_contract_email_for_order' ], 40 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_send_contract_email_for_order' ], 40 );
        add_action( 'add_meta_boxes', [ $this, 'add_order_meta_box' ], 30 );
        add_action( 'admin_footer', [ $this, 'render_admin_footer_forms' ], 100 );
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'account_documents' ], 30 );
        add_action( 'admin_post_rmwc_download_document', [ $this, 'download_document' ] );
        add_action( 'admin_post_nopriv_rmwc_download_document', [ $this, 'download_document' ] );
        add_filter( 'woocommerce_email_attachments', [ $this, 'standard_email_attachments' ], 30, 4 );
        add_filter( 'woocommerce_email_classes', [ $this, 'register_email_class' ], 30 );
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_privacy_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_privacy_eraser' ] );
        add_action( 'rmwc_order_booking_changed', [ $this, 'create_booking_change_document' ], 20, 4 );
        add_action( 'rmwc_bookings_created', [ $this, 'send_initial_contract_email' ], 30, 2 );
        add_action( 'admin_post_rmwc_send_contract_email', [ $this, 'admin_send_contract_email' ] );
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_documents';
    }

    private function order_has_rental( WC_Order $order ) {
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item->get_meta( '_clr_rental_data', true ) ) {
                return true;
            }
        }
        return false;
    }

    public function create_document( WC_Order $order, $type, array $snapshot, $amount = 0.0, $event_key = '' ) {
        $type = sanitize_key( $type );
        if ( ! in_array( $type, [ 'contract', 'contract_revision', 'deposit_received', 'deposit_refund', 'handover_protocol', 'return_protocol' ], true ) ) {
            return new WP_Error( 'clr_document_type', __( 'Ungültiger Dokumenttyp.', 'patsch9-rental-engine' ) );
        }
        $event_key = sanitize_key( $event_key );
        if ( '' !== $event_key ) {
            $event_key = 'o' . $order->get_id() . '_' . $event_key;
        }

        global $wpdb;
        $table = $this->table();
        if ( $event_key ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE event_key = %s LIMIT 1", $table, $event_key ) );
            if ( $existing ) {
                return $existing;
            }
        }

        $snapshot_json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $snapshot_json ) {
            return new WP_Error( 'clr_document_json', __( 'Dokumentdaten konnten nicht verarbeitet werden.', 'patsch9-rental-engine' ) );
        }
        if ( strlen( $snapshot_json ) > 2 * MB_IN_BYTES ) {
            return new WP_Error( 'clr_document_size', __( 'Die Dokumentdaten sind für eine sichere Verarbeitung zu groß.', 'patsch9-rental-engine' ) );
        }
        $hash = hash( 'sha256', $snapshot_json );
        $amount = max( 0, (float) wc_format_decimal( $amount ) );
        $inserted = $wpdb->insert(
            $table,
            [
                'order_id'       => $order->get_id(),
                'type'           => $type,
                'document_number'=> '',
                'amount'         => $amount,
                'currency'       => substr( sanitize_text_field( $order->get_currency() ), 0, 3 ),
                'snapshot'       => $snapshot_json,
                'snapshot_hash'  => $hash,
                'event_key'      => '' !== $event_key ? $event_key : null,
                'created_at'     => current_time( 'mysql', true ),
                'created_by'     => get_current_user_id(),
            ],
            [ '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            // A concurrent request may have created the same immutable event
            // between the read above and this INSERT. The UNIQUE key is the
            // final authority; return the already persisted document instead
            // of producing a duplicate or a false failure.
            if ( '' !== $event_key ) {
                $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE event_key = %s LIMIT 1", $table, $event_key ) );
                if ( $existing ) {
                    return $existing;
                }
            }
            return new WP_Error( 'clr_document_db', __( 'Dokument konnte nicht gespeichert werden.', 'patsch9-rental-engine' ) );
        }
        $id = (int) $wpdb->insert_id;
        $prefixes = [
            'contract'          => 'MV',
            'contract_revision' => 'MVA',
            'deposit_received'  => 'KQ',
            'deposit_refund'    => 'KR',
            'handover_protocol' => 'UP',
            'return_protocol'   => 'RP',
        ];
        $number = sprintf( '%s-%s-%06d', $prefixes[ $type ], wp_date( 'Y' ), $id );
        $wpdb->update( $table, [ 'document_number' => $number ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

        /**
         * Fires after an immutable rental document has been persisted.
         * Useful for optional integrations without coupling them to this plugin.
         */
        do_action( 'rmwc_document_created', $id, $type, $order, $snapshot );

        return $id;
    }

    private function hydrate_document_row( array $row ) {
        $snapshot_json = isset( $row['snapshot'] ) && is_string( $row['snapshot'] ) ? $row['snapshot'] : '';
        $stored_hash   = strtolower( sanitize_text_field( $row['snapshot_hash'] ?? '' ) );
        if ( '' === $snapshot_json || strlen( $snapshot_json ) > 2 * MB_IN_BYTES || ! preg_match( '/^[a-f0-9]{64}$/', $stored_hash ) ) {
            return null;
        }

        $actual_hash = hash( 'sha256', $snapshot_json );
        if ( ! hash_equals( $stored_hash, $actual_hash ) ) {
            return null;
        }

        $snapshot = json_decode( $snapshot_json, true, 64 );
        if ( ! is_array( $snapshot ) ) {
            return null;
        }
        $row['snapshot_data'] = $snapshot;
        return $row;
    }

    public function get_document( $document_id ) {
        global $wpdb;
        $document_id = absint( $document_id );
        if ( ! $document_id ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $this->table(), $document_id ), ARRAY_A );
        return is_array( $row ) ? $this->hydrate_document_row( $row ) : null;
    }

    public function documents_for_order( $order_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC", $this->table(), absint( $order_id ) ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $documents = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $document = $this->hydrate_document_row( $row );
            if ( $document ) {
                $documents[] = $document;
            }
        }
        return $documents;
    }

    public function ensure_contract_for_order( $order_id ) {
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || ! $this->order_has_rental( $order ) ) {
            return 0;
        }
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND type = 'contract' ORDER BY id ASC LIMIT 1", $this->table(), $order->get_id() ) );
        if ( $existing ) {
            $document = $this->get_document( $existing );
            if ( ! $document ) {
                if ( 'yes' !== $order->get_meta( '_clr_document_integrity_warning', true ) ) {
                    $order->add_order_note( __( 'Vermietung: Die Integritätsprüfung eines gespeicherten Mietdokuments ist fehlgeschlagen. Das Dokument wurde aus Sicherheitsgründen nicht ausgeliefert. Bitte technisch prüfen.', 'patsch9-rental-engine' ) );
                    $order->update_meta_data( '_clr_document_integrity_warning', 'yes' );
                    $order->save_meta_data();
                }
                return 0;
            }
            $document_terms         = is_array( $document['snapshot_data']['terms'] ?? null ) ? $document['snapshot_data']['terms'] : [];
            $document_product_terms = is_array( $document['snapshot_data']['product_terms'] ?? null ) ? $document['snapshot_data']['product_terms'] : [];
            $terms_module           = RMWC_V2_Terms::instance();
            $accepted_terms         = $terms_module->order_terms_snapshots( $order );
            $accepted_product_terms = $terms_module->order_product_terms_snapshots( $order );

            // Never rewrite an immutable contract. If an accepted terms
            // snapshot exists but an earlier document missed that appendix,
            // create an explicit correction revision instead.
            if ( ( ( ! $document_terms && $accepted_terms ) || ( ! $document_product_terms && $accepted_product_terms ) ) && is_array( $document['snapshot_data'] ?? null ) ) {
                $snapshot                  = $document['snapshot_data'];
                $snapshot['terms']         = $accepted_terms;
                $snapshot['product_terms'] = $accepted_product_terms;
                $snapshot['correction']    = [
                    'type'                     => 'missing_terms_appendix',
                    'original_document_number' => sanitize_text_field( $document['document_number'] ?? '' ),
                ];
                $corrected = $this->create_document( $order, 'contract_revision', $snapshot, 0, 'contract_terms_repair_v2' );
                if ( ! is_wp_error( $corrected ) && $corrected ) {
                    $order->add_order_note( __( 'Vermietung: Eine korrigierte Vertragsfassung mit den ursprünglich akzeptierten Mietbedingungen wurde erzeugt.', 'patsch9-rental-engine' ) );
                    return (int) $corrected;
                }
            }
            return $existing;
        }

        $snapshot = $this->build_contract_snapshot( $order );
        $created = $this->create_document( $order, 'contract', $snapshot, 0, 'contract_v1' );
        return is_wp_error( $created ) ? 0 : (int) $created;
    }

    private function build_contract_snapshot( WC_Order $order ) {
        $items       = [];
        $accessories = [];
        $fees        = [];
        $deposit_required = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( 'yes' === $item->get_meta( '_clr_rental_accessory', true ) ) {
                $accessories[] = [
                    'name'     => sanitize_text_field( $item->get_name() ),
                    'quantity' => max( 1, (int) $item->get_quantity() ),
                    'group'    => sanitize_text_field( $item->get_meta( '_clr_rental_accessory_group', true ) ),
                ];
                continue;
            }

            $rental = $item->get_meta( '_clr_rental_data', true );
            if ( ! is_array( $rental ) ) {
                continue;
            }
            $qty = max( 1, (int) $item->get_quantity() );
            $deposit_required += max( 0, (float) ( $rental['deposit'] ?? 0 ) ) * $qty;
            $items[] = [
                'name'         => sanitize_text_field( $item->get_name() ),
                'quantity'     => $qty,
                'pickup'       => sanitize_text_field( $rental['start_at'] ?? '' ),
                'return'       => sanitize_text_field( $rental['end_at'] ?? '' ),
                'days'         => absint( $rental['days'] ?? 0 ),
                'fulfilment'   => sanitize_key( $rental['fulfilment'] ?? '' ),
                'delivery'     => sanitize_text_field( $rental['delivery_address'] ?? '' ),
                'addons'       => isset( $rental['addons'] ) && is_array( $rental['addons'] ) ? $rental['addons'] : [],
                'deposit'      => max( 0, (float) ( $rental['deposit'] ?? 0 ) ) * $qty,
                'deposit_mode' => sanitize_key( $rental['deposit_method'] ?? 'online' ),
            ];
        }
        foreach ( $order->get_items( 'fee' ) as $fee_item ) {
            $fee_type = sanitize_key( $fee_item->get_meta( '_clr_fee_type', true ) );
            if ( 'deposit' === $fee_type ) {
                continue; // Refundable security deposit is documented separately below.
            }
            $fees[] = [
                'name' => sanitize_text_field( $fee_item->get_name() ),
                'type' => $fee_type,
            ];
        }

        $terms_module  = RMWC_V2_Terms::instance();
        $terms         = $terms_module->order_terms_snapshots( $order );
        $product_terms = $terms_module->order_product_terms_snapshots( $order );

        $store_address = array_values(
            array_filter(
                [
                    sanitize_text_field( get_option( 'woocommerce_store_address', '' ) ),
                    sanitize_text_field( get_option( 'woocommerce_store_address_2', '' ) ),
                    trim( sanitize_text_field( get_option( 'woocommerce_store_postcode', '' ) ) . ' ' . sanitize_text_field( get_option( 'woocommerce_store_city', '' ) ) ),
                ]
            )
        );
        $customer_address = array_values(
            array_filter(
                [
                    sanitize_text_field( $order->get_billing_address_1() ),
                    sanitize_text_field( $order->get_billing_address_2() ),
                    trim( sanitize_text_field( $order->get_billing_postcode() ) . ' ' . sanitize_text_field( $order->get_billing_city() ) ),
                    sanitize_text_field( $order->get_billing_country() ),
                ]
            )
        );

        return [
            'order_id'         => $order->get_id(),
            'order_number'     => sanitize_text_field( $order->get_order_number() ),
            'created_at'       => current_time( 'mysql', true ),
            'site_name'        => sanitize_text_field( get_bloginfo( 'name' ) ),
            'store_address'    => implode( "\n", $store_address ),
            'customer'         => [
                'name'    => sanitize_text_field( $order->get_formatted_billing_full_name() ),
                'company' => sanitize_text_field( $order->get_billing_company() ),
                'email'   => sanitize_email( $order->get_billing_email() ),
                'phone'   => sanitize_text_field( $order->get_billing_phone() ),
                'address' => implode( "\n", $customer_address ),
            ],
            'items'            => $items,
            'accessories'      => $accessories,
            'fees'             => $fees,
            'deposit_required' => $deposit_required,
            'currency'         => sanitize_text_field( $order->get_currency() ),
            'terms'            => $terms,
            'product_terms'    => $product_terms,
        ];
    }

    public function render_pdf( array $document ) {
        $snapshot = $document['snapshot_data'] ?? [];
        $type = $document['type'] ?? '';
        if ( in_array( $type, [ 'contract', 'contract_revision' ], true ) ) {
            return $this->render_contract_pdf( $document, $snapshot );
        }
        if ( in_array( $type, [ 'handover_protocol', 'return_protocol' ], true ) ) {
            return $this->render_workflow_pdf( $document, $snapshot );
        }
        return $this->render_deposit_pdf( $document, $snapshot );
    }

    private function render_contract_pdf( array $document, array $snapshot ) {
        $is_terms_correction = 'missing_terms_appendix' === sanitize_key( $snapshot['correction']['type'] ?? '' );
        $title = $is_terms_correction
            ? __( 'Mietvertrag – korrigierte Fassung', 'patsch9-rental-engine' )
            : ( 'contract_revision' === ( $document['type'] ?? '' ) ? __( 'Mietvertragsänderung', 'patsch9-rental-engine' ) : __( 'Mietvertrag', 'patsch9-rental-engine' ) );
        $blocks = [
            [ 'type' => 'row', 'label' => __( 'Vertragsnummer', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $document['document_number'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Bestellung', 'patsch9-rental-engine' ), 'value' => '#' . sanitize_text_field( $snapshot['order_number'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Erstellt', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $document['created_at'] ?? ( $snapshot['created_at'] ?? '' ) ) ],
            [ 'type' => 'spacer', 'height' => 4 ],
            [ 'type' => 'section', 'title' => __( 'Vertragsparteien', 'patsch9-rental-engine' ) ],
        ];

        $store = sanitize_text_field( $snapshot['site_name'] ?? get_bloginfo( 'name' ) );
        $store_address = trim( (string) ( $snapshot['store_address'] ?? '' ) );
        if ( '' !== $store_address ) {
            $store .= "\n" . $store_address;
        }
        $blocks[] = [ 'type' => 'row', 'label' => __( 'Vermieter', 'patsch9-rental-engine' ), 'value' => $store ];

        $customer = is_array( $snapshot['customer'] ?? null ) ? $snapshot['customer'] : [];
        $customer_lines = [];
        if ( ! empty( $customer['name'] ) ) {
            $customer_lines[] = sanitize_text_field( $customer['name'] );
        }
        if ( ! empty( $customer['company'] ) ) {
            $customer_lines[] = sanitize_text_field( $customer['company'] );
        }
        $address = trim( (string) ( $customer['address'] ?? '' ) );
        // Older 2.0.x snapshots stored WooCommerce's formatted billing address
        // after stripping HTML, which could concatenate the lines. Recover the
        // common German street/postcode form for a readable legacy rendering.
        if ( '' !== $address && false === strpos( $address, "\n" ) ) {
            $name = sanitize_text_field( $customer['name'] ?? '' );
            if ( '' !== $name && 0 === strpos( $address, $name ) ) {
                $address = trim( substr( $address, strlen( $name ) ) );
            }
            if ( preg_match( '/^(.*?)(\d{5})\s+(.+)$/u', $address, $matches ) ) {
                $address = trim( $matches[1] ) . "\n" . trim( $matches[2] . ' ' . $matches[3] );
            }
        }
        if ( '' !== $address ) {
            foreach ( preg_split( "/\r\n?|\n/u", $address ) ?: [] as $address_line ) {
                $address_line = sanitize_text_field( $address_line );
                if ( '' !== $address_line ) {
                    $customer_lines[] = $address_line;
                }
            }
        }
        if ( ! empty( $customer['email'] ) ) {
            $customer_lines[] = __( 'E-Mail:', 'patsch9-rental-engine' ) . ' ' . sanitize_email( $customer['email'] );
        }
        if ( ! empty( $customer['phone'] ) ) {
            $customer_lines[] = __( 'Telefon:', 'patsch9-rental-engine' ) . ' ' . sanitize_text_field( $customer['phone'] );
        }
        $blocks[] = [ 'type' => 'row', 'label' => __( 'Mieter', 'patsch9-rental-engine' ), 'value' => implode( "\n", $customer_lines ) ];

        $blocks[] = [ 'type' => 'section', 'title' => __( 'Mietgegenstände und Mietzeitraum', 'patsch9-rental-engine' ) ];
        foreach ( $snapshot['items'] ?? [] as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $blocks[] = [
                'type' => 'text',
                'bold' => true,
                'size' => 10.4,
                'text' => sprintf( '%dx %s', max( 1, absint( $item['quantity'] ?? 1 ) ), sanitize_text_field( $item['name'] ?? '' ) ),
            ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Mietbeginn', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $item['pickup'] ?? '' ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Rückgabe', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $item['return'] ?? '' ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Transport / Übergabe', 'patsch9-rental-engine' ), 'value' => $this->fulfilment_label( $item['fulfilment'] ?? '' ) ];
            if ( ! empty( $item['delivery'] ) ) {
                $blocks[] = [ 'type' => 'row', 'label' => __( 'Liefer-/Abholadresse', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $item['delivery'] ) ];
            }
            foreach ( $item['addons'] ?? [] as $addon ) {
                if ( ! is_array( $addon ) ) {
                    continue;
                }
                $blocks[] = [
                    'type' => 'bullet',
                    'text' => sanitize_text_field( $addon['group'] ?? __( 'Zusatzoption', 'patsch9-rental-engine' ) ) . ': ' . sanitize_text_field( $addon['label'] ?? '' ),
                ];
            }
            $blocks[] = [ 'type' => 'spacer', 'height' => 5 ];
        }

        if ( ! empty( $snapshot['accessories'] ) ) {
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Miet-Zubehör / Verbrauchsmaterial', 'patsch9-rental-engine' ) ];
            foreach ( $snapshot['accessories'] as $accessory ) {
                if ( ! is_array( $accessory ) ) {
                    continue;
                }
                $prefix = ! empty( $accessory['group'] ) ? sanitize_text_field( $accessory['group'] ) . ': ' : '';
                $blocks[] = [ 'type' => 'bullet', 'text' => $prefix . sprintf( '%dx %s', max( 1, absint( $accessory['quantity'] ?? 1 ) ), sanitize_text_field( $accessory['name'] ?? '' ) ) ];
            }
        }

        if ( ! empty( $snapshot['fees'] ) ) {
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Weitere Leistungen', 'patsch9-rental-engine' ) ];
            foreach ( $snapshot['fees'] as $fee ) {
                if ( is_array( $fee ) && ! empty( $fee['name'] ) ) {
                    $blocks[] = [ 'type' => 'bullet', 'text' => sanitize_text_field( $fee['name'] ) ];
                }
            }
        }

        if ( (float) ( $snapshot['deposit_required'] ?? 0 ) > 0 ) {
            $currency = sanitize_text_field( $snapshot['currency'] ?? 'EUR' );
            $amount   = html_entity_decode( wp_strip_all_tags( wc_price( (float) $snapshot['deposit_required'], [ 'currency' => $currency ] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $deposit_modes = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn( $item ) => is_array( $item ) ? sanitize_key( $item['deposit_mode'] ?? '' ) : '',
                            $snapshot['items'] ?? []
                        )
                    )
                )
            );
            $deposit_method = in_array( 'cash', $deposit_modes, true )
                ? __( 'Separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' )
                : __( 'Online mit der Bestellung hinterlegt', 'patsch9-rental-engine' );
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Kaution', 'patsch9-rental-engine' ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Sicherheitsleistung', 'patsch9-rental-engine' ), 'value' => trim( $amount ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Hinterlegung', 'patsch9-rental-engine' ), 'value' => $deposit_method ];
            $blocks[] = [ 'type' => 'notice', 'text' => __( 'Die Kaution ist eine rückzahlbare Sicherheitsleistung. Rückzahlung und ein möglicher Einbehalt richten sich nach den akzeptierten Mietbedingungen.', 'patsch9-rental-engine' ) ];
        }

        $terms         = is_array( $snapshot['terms'] ?? null ) ? $snapshot['terms'] : [];
        $product_terms = is_array( $snapshot['product_terms'] ?? null ) ? $snapshot['product_terms'] : [];
        if ( $terms || $product_terms ) {
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Vertragsgrundlage / Mietbedingungen', 'patsch9-rental-engine' ) ];
            foreach ( $terms as $terms_snapshot ) {
                if ( ! is_array( $terms_snapshot ) ) {
                    continue;
                }
                $label = __( 'Allgemeine Mietbedingungen', 'patsch9-rental-engine' ) . ': ' . __( 'Version', 'patsch9-rental-engine' ) . ' ' . sanitize_text_field( $terms_snapshot['version'] ?? '' );
                if ( ! empty( $terms_snapshot['accepted_at'] ) ) {
                    $label .= ' - ' . __( 'akzeptiert', 'patsch9-rental-engine' ) . ' ' . $this->display_datetime( $terms_snapshot['accepted_at'] );
                }
                $blocks[] = [ 'type' => 'bullet', 'text' => $label ];
            }
            foreach ( $product_terms as $terms_snapshot ) {
                if ( ! is_array( $terms_snapshot ) ) {
                    continue;
                }
                $name  = sanitize_text_field( $terms_snapshot['product_name'] ?? __( 'Mietartikel', 'patsch9-rental-engine' ) );
                $label = sprintf(
                    /* translators: %s: rental product name. */
                    __( 'Ergänzende Mietbedingungen – %s', 'patsch9-rental-engine' ),
                    $name
                );
                if ( ! empty( $terms_snapshot['version'] ) ) {
                    $label .= ': ' . sanitize_text_field( $terms_snapshot['version'] );
                }
                if ( ! empty( $terms_snapshot['accepted_at'] ) ) {
                    $label .= ' - ' . __( 'akzeptiert', 'patsch9-rental-engine' ) . ' ' . $this->display_datetime( $terms_snapshot['accepted_at'] );
                }
                $blocks[] = [ 'type' => 'bullet', 'text' => $label ];
            }
            $blocks[] = [
                'type' => 'notice',
                'text' => __( 'Die zum Bestellzeitpunkt akzeptierten allgemeinen und artikelspezifischen Mietbedingungen sind Bestandteil dieses Mietvertrags und werden auf den folgenden Seiten vollständig als Anlagen wiedergegeben.', 'patsch9-rental-engine' ),
            ];

            foreach ( $terms as $terms_snapshot ) {
                if ( ! is_array( $terms_snapshot ) ) {
                    continue;
                }
                $blocks[] = [ 'type' => 'page_break' ];
                $blocks[] = [ 'type' => 'section', 'title' => __( 'Anlage: Allgemeine Mietbedingungen', 'patsch9-rental-engine' ) ];
                $blocks[] = [ 'type' => 'row', 'label' => __( 'Version', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $terms_snapshot['version'] ?? '' ) ];
                if ( ! empty( $terms_snapshot['accepted_at'] ) ) {
                    $blocks[] = [ 'type' => 'row', 'label' => __( 'Akzeptiert am', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $terms_snapshot['accepted_at'] ) ];
                }
                $hash = sanitize_text_field( $terms_snapshot['hash'] ?? '' );
                if ( '' !== $hash ) {
                    $blocks[] = [ 'type' => 'row', 'label' => __( 'Prüfsumme', 'patsch9-rental-engine' ), 'value' => $hash ];
                }
                $blocks[] = [ 'type' => 'spacer', 'height' => 5 ];
                foreach ( RMWC_PDF::html_to_lines( $terms_snapshot['content'] ?? '' ) as $terms_line ) {
                    $blocks[] = [ 'type' => 'text', 'size' => 9.3, 'text' => $terms_line ];
                }
            }

            foreach ( $product_terms as $terms_snapshot ) {
                if ( ! is_array( $terms_snapshot ) ) {
                    continue;
                }
                $name = sanitize_text_field( $terms_snapshot['product_name'] ?? __( 'Mietartikel', 'patsch9-rental-engine' ) );
                $blocks[] = [ 'type' => 'page_break' ];
                $blocks[] = [
                    'type'  => 'section',
                    'title' => sprintf(
                        /* translators: %s: rental product name. */
                        __( 'Anlage: Ergänzende Mietbedingungen – %s', 'patsch9-rental-engine' ),
                        $name
                    ),
                ];
                $blocks[] = [ 'type' => 'row', 'label' => __( 'Mietartikel', 'patsch9-rental-engine' ), 'value' => $name ];
                if ( ! empty( $terms_snapshot['version'] ) ) {
                    $blocks[] = [ 'type' => 'row', 'label' => __( 'Version', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $terms_snapshot['version'] ) ];
                }
                if ( ! empty( $terms_snapshot['accepted_at'] ) ) {
                    $blocks[] = [ 'type' => 'row', 'label' => __( 'Akzeptiert am', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $terms_snapshot['accepted_at'] ) ];
                }
                $hash = sanitize_text_field( $terms_snapshot['hash'] ?? '' );
                if ( '' !== $hash ) {
                    $blocks[] = [ 'type' => 'row', 'label' => __( 'Prüfsumme', 'patsch9-rental-engine' ), 'value' => $hash ];
                }
                $blocks[] = [ 'type' => 'spacer', 'height' => 5 ];
                foreach ( RMWC_PDF::html_to_lines( $terms_snapshot['content'] ?? '' ) as $terms_line ) {
                    $blocks[] = [ 'type' => 'text', 'size' => 9.3, 'text' => $terms_line ];
                }
            }
        }

        if ( $is_terms_correction ) {
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Korrekturhinweis', 'patsch9-rental-engine' ) ];
            $blocks[] = [
                'type' => 'notice',
                'text' => sprintf(
                    /* translators: %s: original rental-contract document number. */
                    __( 'Diese Fassung ergänzt die beim ursprünglichen Dokument %s technisch nicht eingebettete, bei der Bestellung akzeptierte Mietbedingungsfassung. Die übrigen Vertragsdaten bleiben unverändert.', 'patsch9-rental-engine' ),
                    sanitize_text_field( $snapshot['correction']['original_document_number'] ?? '' )
                ),
            ];
        }

        if ( 'contract_revision' === ( $document['type'] ?? '' ) && ! empty( $snapshot['change'] ) && is_array( $snapshot['change'] ) ) {
            $change = $snapshot['change'];
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Dokumentierte Vertragsänderung', 'patsch9-rental-engine' ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Buchung', 'patsch9-rental-engine' ), 'value' => '#' . absint( $change['booking_id'] ?? 0 ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Vorher', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $change['old_period'] ?? '' ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Jetzt', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $change['new_period'] ?? '' ) ];
        }

        return RMWC_PDF::build_styled( $title, $blocks, '', sanitize_text_field( $snapshot['site_name'] ?? get_bloginfo( 'name' ) ) );
    }

    private function render_deposit_pdf( array $document, array $snapshot ) {
        $is_refund     = 'deposit_refund' === ( $document['type'] ?? '' );
        $has_retention = $is_refund && ! empty( $snapshot['retained'] );
        $title         = $is_refund ? ( $has_retention ? __( 'Kautionsabrechnung', 'patsch9-rental-engine' ) : __( 'Kautionsrückzahlungsquittung', 'patsch9-rental-engine' ) ) : __( 'Kautionsquittung', 'patsch9-rental-engine' );
        $currency      = sanitize_text_field( $document['currency'] ?? 'EUR' );
        $plain_price   = static function( $amount ) use ( $currency ) {
            $value = html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, [ 'currency' => $currency ] ) ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
            return trim( str_replace( "Â ", ' ', $value ) );
        };

        $blocks = [
            [ 'type' => 'section', 'title' => __( 'Belegdetails', 'patsch9-rental-engine' ) ],
            [ 'type' => 'row', 'label' => __( 'Belegnummer', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $document['document_number'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Bestellung', 'patsch9-rental-engine' ), 'value' => '#' . sanitize_text_field( $snapshot['order_number'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Datum', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['date'] ?? '' ) ],
            [ 'type' => 'section', 'title' => __( 'Mieter', 'patsch9-rental-engine' ) ],
            [ 'type' => 'row', 'label' => __( 'Name', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['customer_name'] ?? '' ) ],
            [ 'type' => 'section', 'title' => $is_refund ? __( 'Auszahlung / Verrechnung', 'patsch9-rental-engine' ) : __( 'Erhaltene Sicherheitsleistung', 'patsch9-rental-engine' ) ],
            [ 'type' => 'row', 'label' => $is_refund ? __( 'Zurückgezahlt', 'patsch9-rental-engine' ) : __( 'Erhalten', 'patsch9-rental-engine' ), 'value' => $plain_price( $document['amount'] ?? 0 ) ],
            [ 'type' => 'row', 'label' => __( 'Zahlungsart', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['method_label'] ?? '' ) ],
        ];

        if ( isset( $snapshot['remaining'] ) ) {
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Verbleibend / noch offen', 'patsch9-rental-engine' ), 'value' => $plain_price( $snapshot['remaining'] ) ];
        }
        if ( ! empty( $snapshot['retained'] ) ) {
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Einbehalten', 'patsch9-rental-engine' ), 'value' => $plain_price( $snapshot['retained'] ) ];
        }
        if ( ! empty( $snapshot['reason'] ) ) {
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Begründung / Hinweis', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['reason'] ) ];
        }

        $blocks[] = [ 'type' => 'section', 'title' => __( 'Bestätigung', 'patsch9-rental-engine' ) ];
        $blocks[] = [
            'type' => 'notice',
            'text' => $is_refund
                ? ( $has_retention
                    ? __( 'Dieser Beleg dokumentiert die Rückzahlung der Teilkaution sowie den ausgewiesenen Einbehalt gemäß Mietbedingungen.', 'patsch9-rental-engine' )
                    : __( 'Dieser Beleg bestätigt die Rückzahlung der oben genannten Kaution bzw. Teilkaution gemäß Mietbedingungen.', 'patsch9-rental-engine' ) )
                : __( 'Dieser Beleg bestätigt den Erhalt der oben genannten rückzahlbaren Sicherheitsleistung gemäß Mietbedingungen.', 'patsch9-rental-engine' ),
        ];
        $blocks[] = [
            'type' => 'text',
            'size' => 8.8,
            'text' => __( 'Dieser Beleg wurde elektronisch erstellt und bedarf keiner Unterschrift.', 'patsch9-rental-engine' ),
        ];

        return RMWC_PDF::build_styled( $title, $blocks, '', sanitize_text_field( get_bloginfo( 'name' ) ) );
    }

    private function render_workflow_pdf( array $document, array $snapshot ) {
        $is_return = 'return_protocol' === ( $document['type'] ?? '' );
        $title     = $is_return ? __( 'Rückgabeprotokoll', 'patsch9-rental-engine' ) : __( 'Übergabeprotokoll', 'patsch9-rental-engine' );
        $blocks    = [
            [ 'type' => 'row', 'label' => __( 'Protokollnummer', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $document['document_number'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Bestellung', 'patsch9-rental-engine' ), 'value' => '#' . sanitize_text_field( $snapshot['order_number'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Buchung', 'patsch9-rental-engine' ), 'value' => '#' . absint( $snapshot['booking_id'] ?? 0 ) ],
            [ 'type' => 'row', 'label' => __( 'Dokumentiert am', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $snapshot['event_at'] ?? '' ) ],
            [ 'type' => 'section', 'title' => __( 'Vermietung', 'patsch9-rental-engine' ) ],
            [ 'type' => 'row', 'label' => __( 'Mieter', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['customer_name'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Mietartikel', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['product_name'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Mietbeginn', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $snapshot['start_at'] ?? '' ) ],
            [ 'type' => 'row', 'label' => __( 'Rückgabe', 'patsch9-rental-engine' ), 'value' => $this->display_datetime( $snapshot['end_at'] ?? '' ) ],
        ];

        if ( ! empty( $snapshot['fulfilment'] ) ) {
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Transport / Übergabe', 'patsch9-rental-engine' ), 'value' => $this->fulfilment_label( $snapshot['fulfilment'] ) ];
            if ( ! empty( $snapshot['delivery_address'] ) && in_array( sanitize_key( $snapshot['fulfilment'] ), [ 'delivery', 'delivery_return' ], true ) ) {
                $blocks[] = [ 'type' => 'row', 'label' => __( 'Liefer-/Abholadresse', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['delivery_address'] ) ];
            }
        }

        $deposit = is_array( $snapshot['deposit'] ?? null ) ? $snapshot['deposit'] : [];
        if ( (float) ( $deposit['required'] ?? 0 ) > 0 ) {
            $currency = sanitize_text_field( $document['currency'] ?? 'EUR' );
            $plain_price = static function( $amount ) use ( $currency ) {
                $value = html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, [ 'currency' => $currency ] ) ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
                return trim( str_replace( "\xc2\xa0", ' ', $value ) );
            };
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Kaution', 'patsch9-rental-engine' ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Erforderlich', 'patsch9-rental-engine' ), 'value' => $plain_price( $deposit['required'] ?? 0 ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Erhalten', 'patsch9-rental-engine' ), 'value' => $plain_price( $deposit['received'] ?? 0 ) ];
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Zurückgezahlt', 'patsch9-rental-engine' ), 'value' => $plain_price( $deposit['refunded'] ?? 0 ) ];
            if ( (float) ( $deposit['retained'] ?? 0 ) > 0.0001 ) {
                $blocks[] = [ 'type' => 'row', 'label' => __( 'Einbehalten', 'patsch9-rental-engine' ), 'value' => $plain_price( $deposit['retained'] ?? 0 ) ];
            }
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Noch offen / hinterlegt', 'patsch9-rental-engine' ), 'value' => $plain_price( $deposit['available'] ?? 0 ) ];
            if ( ! $is_return && (float) ( $deposit['missing'] ?? 0 ) > 0.0001 ) {
                $blocks[] = [ 'type' => 'notice', 'text' => __( 'Zum Zeitpunkt des Protokolls war die erforderliche Kaution noch nicht vollständig erfasst.', 'patsch9-rental-engine' ) ];
            }
        }

        $assets = is_array( $snapshot['assets'] ?? null ) ? $snapshot['assets'] : [];
        if ( $assets ) {
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Zugeordnete Geräte und dokumentierter Ausgangszustand', 'patsch9-rental-engine' ) ];
            foreach ( $assets as $asset ) {
                if ( ! is_array( $asset ) ) {
                    continue;
                }
                $label = sanitize_text_field( $asset['inventory_number'] ?? '' );
                if ( ! empty( $asset['serial_number'] ) ) {
                    $label .= ' - ' . sprintf(
                        /* translators: %s: device serial number. */
                        __( 'Seriennummer %s', 'patsch9-rental-engine' ),
                        sanitize_text_field( $asset['serial_number'] )
                    );
                }
                if ( '' !== $label ) {
                    $blocks[] = [ 'type' => 'text', 'bold' => true, 'size' => 10.1, 'text' => $label ];
                }
                if ( '' !== trim( (string) ( $asset['known_damage'] ?? '' ) ) ) {
                    $blocks[] = [
                        'type' => 'notice',
                        'text' => __( 'Bereits vor der Übergabe im Inventar dokumentiert: ', 'patsch9-rental-engine' ) . sanitize_textarea_field( $asset['known_damage'] ),
                    ];
                } else {
                    $blocks[] = [ 'type' => 'text', 'size' => 9.4, 'text' => __( 'Keine bekannten Vorschäden / Gebrauchsspuren im Inventar hinterlegt.', 'patsch9-rental-engine' ) ];
                }
            }
        }

        $blocks[] = [ 'type' => 'section', 'title' => $is_return ? __( 'Rückgabe-Checkliste', 'patsch9-rental-engine' ) : __( 'Übergabe-Checkliste', 'patsch9-rental-engine' ) ];
        foreach ( $snapshot['checklist'] ?? [] as $item ) {
            if ( is_array( $item ) ) {
                $status_labels = [
                    'ok'    => __( 'In Ordnung', 'patsch9-rental-engine' ),
                    'issue' => __( 'Abweichung / fehlt', 'patsch9-rental-engine' ),
                    'na'    => __( 'Nicht zutreffend', 'patsch9-rental-engine' ),
                ];
                $status = sanitize_key( $item['status'] ?? '' );
                $label  = sanitize_text_field( $item['label'] ?? '' );
                $text   = ( $status_labels[ $status ] ?? __( 'Bewertet', 'patsch9-rental-engine' ) ) . ': ' . $label;
                if ( '' !== trim( (string) ( $item['note'] ?? '' ) ) ) {
                    $text .= ' – ' . sanitize_textarea_field( $item['note'] );
                }
                $blocks[] = [ 'type' => 'bullet', 'text' => $text ];
                continue;
            }
            $blocks[] = [ 'type' => 'bullet', 'text' => __( 'Bestätigt: ', 'patsch9-rental-engine' ) . sanitize_text_field( $item ) ];
        }

        if ( ! empty( $snapshot['notes'] ) ) {
            $blocks[] = [ 'type' => 'section', 'title' => __( 'Notizen / neu festgestellte Auffälligkeiten', 'patsch9-rental-engine' ) ];
            $blocks[] = [ 'type' => 'notice', 'text' => sanitize_textarea_field( $snapshot['notes'] ) ];
        }
        if ( ! empty( $snapshot['staff_name'] ) ) {
            $blocks[] = [ 'type' => 'row', 'label' => __( 'Bearbeitet von', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $snapshot['staff_name'] ) ];
        }
        $blocks[] = [
            'type' => 'notice',
            'text' => $is_return
                ? __( 'Die Rückgabe wurde mit den oben dokumentierten Angaben abgeschlossen.', 'patsch9-rental-engine' )
                : __( 'Die Übergabe wurde mit den oben dokumentierten Angaben abgeschlossen.', 'patsch9-rental-engine' ),
        ];

        return RMWC_PDF::build_styled(
            $title,
            $blocks,
            is_string( $snapshot['signature_jpeg'] ?? '' ) ? $snapshot['signature_jpeg'] : '',
            sanitize_text_field( get_bloginfo( 'name' ) )
        );
    }

    private function fulfilment_label( $mode ) {
        $labels = [
            'pickup'          => __( 'Abholung und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
            'delivery'        => __( 'Lieferung durch Vermieter und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
            'delivery_return' => __( 'Lieferung und Abholung durch Vermieter', 'patsch9-rental-engine' ),
        ];
        $mode = sanitize_key( (string) $mode );
        return $labels[ $mode ] ?? sanitize_text_field( (string) $mode );
    }

    private function display_datetime( $value ) {
        try {
            $dt = new DateTimeImmutable( sanitize_text_field( (string) $value ), wp_timezone() );
            return wp_date( 'd.m.Y H:i', $dt->getTimestamp(), wp_timezone() ) . ' Uhr';
        } catch ( Exception $e ) {
            return sanitize_text_field( (string) $value );
        }
    }

    private function document_filename( array $document ) {
        $snapshot = is_array( $document['snapshot_data'] ?? null ) ? $document['snapshot_data'] : [];
        $type     = sanitize_key( $document['type'] ?? '' );
        if ( in_array( $type, [ 'contract', 'contract_revision' ], true ) ) {
            $prefix = 'missing_terms_appendix' === sanitize_key( $snapshot['correction']['type'] ?? '' ) ? 'mietvertrag-korrigiert' : 'mietvertrag';
        } elseif ( 'handover_protocol' === $type ) {
            $prefix = 'uebergabeprotokoll';
        } elseif ( 'return_protocol' === $type ) {
            $prefix = 'rueckgabeprotokoll';
        } elseif ( 'deposit_received' === $type ) {
            $prefix = 'kautionsquittung';
        } elseif ( 'deposit_refund' === $type ) {
            $prefix = 'kautionsrueckzahlung';
        } else {
            $prefix = 'mietdokument';
        }
        $base = sanitize_file_name( $prefix . '-' . ( $document['document_number'] ?? $document['id'] ) . '.pdf' );
        return $base ?: 'mietdokument.pdf';
    }

    /**
     * Create a restricted temporary attachment whose actual filename ends in
     * .pdf. wp_tempnam() intentionally returns a .tmp path, which mail clients
     * then expose verbatim even when the payload itself is a valid PDF.
     */
    private function write_temporary_pdf( $filename, $pdf_bytes, $error_code, $error_message ) {
        // Do not use wp_tempnam() for mail attachments. Its physical filename
        // intentionally ends in .tmp and some mail stacks preserve that original
        // temp name even after a rename. Create the attachment with its final
        // .pdf basename from the start so PHPMailer always sees a PDF filename.
        $safe_name = sanitize_file_name( $filename );
        $stem      = sanitize_file_name( pathinfo( $safe_name, PATHINFO_FILENAME ) );
        if ( '' === $stem ) {
            $stem = 'mietdokument';
        }
        $bytes  = (string) $pdf_bytes;
        $length = strlen( $bytes );
        if ( $length < 8 || $length > 15 * MB_IN_BYTES || ! str_starts_with( $bytes, '%PDF-' ) ) {
            return new WP_Error( $error_code, $error_message );
        }

        $temp_dir = trailingslashit( get_temp_dir() );
        $system_temp = function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '';
        if ( is_string( $system_temp ) && '' !== $system_temp && is_dir( $system_temp ) && is_writable( $system_temp ) ) {
            // Prefer the operating-system temp directory so generated customer
            // documents are not placed below a potentially web-accessible WP path.
            $temp_dir = trailingslashit( $system_temp );
        }
        for ( $attempt = 0; $attempt < 8; $attempt++ ) {
            $suffix   = strtolower( wp_generate_password( 16, false, false ) );
            $pdf_path = $temp_dir . $stem . '-' . $suffix . '.pdf';
            $old_umask = umask( 0077 );
            $handle   = @fopen( $pdf_path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive transient file; restrictive umask is set before creation.
            umask( $old_umask );
            if ( false === $handle ) {
                continue;
            }
            @chmod( $pdf_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Defense in depth after restrictive creation mode.
            $offset    = 0;
            $write_ok  = true;
            while ( $offset < $length ) {
                $written = fwrite( $handle, substr( $bytes, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Generated transient attachment in OS temp directory.
                if ( false === $written || 0 === $written ) {
                    $write_ok = false;
                    break;
                }
                $offset += $written;
            }
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            if ( ! $write_ok || $offset !== $length ) {
                @unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
                continue;
            }
            return $pdf_path;
        }
        return new WP_Error( $error_code, $error_message );
    }

    public function temporary_pdf( array $document ) {
        return $this->write_temporary_pdf(
            $this->document_filename( $document ),
            $this->render_pdf( $document ),
            'clr_tempfile',
            __( 'Temporäre PDF-Datei konnte nicht angelegt werden.', 'patsch9-rental-engine' )
        );
    }

    private function download_token( array $document, WC_Order $order, $expires ) {
        $material = absint( $document['id'] ?? 0 ) . '|' . $order->get_id() . '|' . $order->get_order_key() . '|' . sanitize_text_field( $document['snapshot_hash'] ?? '' ) . '|' . absint( $expires );
        return hash_hmac( 'sha256', $material, wp_salt( 'auth' ) );
    }

    public function signed_download_url( array $document, WC_Order $order ) {
        $args = [
            'action'      => 'rmwc_download_document',
            'document_id' => absint( $document['id'] ?? 0 ),
            'order_id'    => $order->get_id(),
        ];

        $user_id = get_current_user_id();
        $authorized_session = current_user_can( 'manage_woocommerce' ) || ( $user_id && (int) $order->get_user_id() === $user_id );
        if ( $authorized_session ) {
            $args['_wpnonce'] = wp_create_nonce( 'rmwc_download_' . absint( $document['id'] ?? 0 ) . '_' . $order->get_id() );
        } else {
            $expires         = time() + HOUR_IN_SECONDS;
            $args['expires'] = $expires;
            $args['token']   = $this->download_token( $document, $order, $expires );
        }

        return add_query_arg( $args, admin_url( 'admin-post.php' ) );
    }

    private function can_download( array $document, WC_Order $order, $token, $expires, $nonce ) {
        $user_id = get_current_user_id();
        $authorized_session = current_user_can( 'manage_woocommerce' ) || ( $user_id && (int) $order->get_user_id() === $user_id );
        if ( $authorized_session ) {
            return is_string( $nonce ) && wp_verify_nonce( $nonce, 'rmwc_download_' . absint( $document['id'] ?? 0 ) . '_' . $order->get_id() );
        }

        $expires = absint( $expires );
        if ( ! $expires || $expires < time() || $expires > time() + 2 * HOUR_IN_SECONDS ) {
            return false;
        }
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return false;
        }
        $expected = $this->download_token( $document, $order, $expires );
        return hash_equals( $expected, strtolower( $token ) );
    }

    public function download_document() {
        $document_id = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0;
        $order_id    = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
        $token       = isset( $_GET['token'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['token'] ) ) ) : '';
        $expires     = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
        $nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $document = $this->get_document( $document_id );
        $order = wc_get_order( $order_id );
        if ( ! $document || ! $order || (int) $document['order_id'] !== $order->get_id() || ! $this->can_download( $document, $order, $token, $expires, $nonce ) ) {
            status_header( 403 );
            wp_die( esc_html__( 'Dieses Dokument kann nicht heruntergeladen werden.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $pdf = $this->render_pdf( $document );
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, nosnippet' );
        header( "Content-Security-Policy: sandbox; default-src 'none'" );
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $this->document_filename( $document ) . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF response.
        exit;
    }

    public function add_order_meta_box() {
        $screens = [ 'shop_order' ];
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box( 'clr_v2_documents', __( 'Mietdokumente', 'patsch9-rental-engine' ), [ $this, 'render_order_meta_box' ], $screen, 'side', 'default' );
        }
    }

    public function render_order_meta_box( $object ) {
        $order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
        if ( ! $order || ! $this->order_has_rental( $order ) ) {
            echo '<p>' . esc_html__( 'Keine Mietdokumente.', 'patsch9-rental-engine' ) . '</p>';
            return;
        }
        $this->ensure_contract_for_order( $order->get_id() );
        $documents = $this->documents_for_order( $order->get_id() );
        if ( ! $documents ) {
            echo '<p>' . esc_html__( 'Noch keine Dokumente vorhanden.', 'patsch9-rental-engine' ) . '</p>';
            return;
        }
        echo '<ul class="clr-v2-document-list">';
        foreach ( $documents as $document ) {
            $url = $this->signed_download_url( $document, $order );
            echo '<li><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $document['document_number'] ) . '</strong></a><br><small>' . esc_html( $this->type_label( $document['type'] ) ) . '</small></li>';
        }
        echo '</ul>';

        $contract_id = 0;
        foreach ( $documents as $document ) {
            if ( 'contract' === ( $document['type'] ?? '' ) ) {
                $contract_id = absint( $document['id'] ?? 0 );
                break;
            }
        }
        if ( $contract_id ) {
            $mail_status = isset( $_GET['clr_contract_mail'] ) ? sanitize_key( wp_unslash( $_GET['clr_contract_mail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display notice.
            if ( 'sent' === $mail_status ) {
                echo '<p class="clr-v2-document-mail-notice">' . esc_html__( 'Mietvertrag und Mietbedingungen wurden erneut per E-Mail versendet.', 'patsch9-rental-engine' ) . '</p>';
            } elseif ( 'failed' === $mail_status ) {
                echo '<p class="clr-v2-document-mail-error">' . esc_html__( 'Die E-Mail konnte nicht versendet werden. Bitte WooCommerce-E-Mail-/SMTP-Konfiguration prüfen.', 'patsch9-rental-engine' ) . '</p>';
            }
            $form_id = 'clr-v2-contract-mail-' . $order->get_id();
            $this->admin_footer_forms[ $form_id ] = [
                'action'   => 'rmwc_send_contract_email',
                'order_id' => $order->get_id(),
                'nonce'    => wp_create_nonce( 'rmwc_send_contract_email_' . $order->get_id() ),
            ];
            echo '<div class="clr-v2-contract-mail-form">';
            echo '<button form="' . esc_attr( $form_id ) . '" type="submit" class="button button-secondary">' . esc_html__( 'Mietvertrag per E-Mail senden', 'patsch9-rental-engine' ) . '</button>';
            echo '<p class="description">' . esc_html__( 'Sendet den Mietvertrag inklusive der zum Bestellzeitpunkt akzeptierten Mietbedingungen. Die Bedingungen sind im Vertrags-PDF vollständig als Anlage enthalten und werden zusätzlich separat beigefügt.', 'patsch9-rental-engine' ) . '</p>';
            echo '</div>';
        }
    }

    public function render_admin_footer_forms() {
        if ( ! $this->admin_footer_forms ) {
            return;
        }
        foreach ( $this->admin_footer_forms as $form_id => $form ) {
            echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="clr-v2-standalone-admin-form" style="display:none">';
            echo '<input type="hidden" name="action" value="' . esc_attr( $form['action'] ) . '">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $form['order_id'] ) . '">';
            echo '<input type="hidden" name="clr_contract_email_nonce" value="' . esc_attr( $form['nonce'] ) . '">';
            echo '</form>';
        }
        $this->admin_footer_forms = [];
    }

    public function account_documents( $order ) {
        if ( ! $order instanceof WC_Order || ! $this->order_has_rental( $order ) ) {
            return;
        }
        $documents = $this->documents_for_order( $order->get_id() );
        if ( ! $documents ) {
            return;
        }
        echo '<section class="woocommerce-order-details clr-v2-account-documents"><h2>' . esc_html__( 'Mietdokumente', 'patsch9-rental-engine' ) . '</h2><ul>';
        foreach ( $documents as $document ) {
            echo '<li><a href="' . esc_url( $this->signed_download_url( $document, $order ) ) . '">' . esc_html( $this->type_label( $document['type'] ) . ' ' . $document['document_number'] ) . '</a></li>';
        }
        echo '</ul></section>';
    }

    private function type_label( $type ) {
        $labels = [
            'contract'          => __( 'Mietvertrag', 'patsch9-rental-engine' ),
            'contract_revision' => __( 'Mietvertragsänderung', 'patsch9-rental-engine' ),
            'deposit_received'  => __( 'Kautionsquittung', 'patsch9-rental-engine' ),
            'deposit_refund'    => __( 'Kautionsrückzahlungsquittung', 'patsch9-rental-engine' ),
            'handover_protocol' => __( 'Übergabeprotokoll', 'patsch9-rental-engine' ),
            'return_protocol'   => __( 'Rückgabeprotokoll', 'patsch9-rental-engine' ),
        ];
        return $labels[ $type ] ?? __( 'Mietdokument', 'patsch9-rental-engine' );
    }

    public function standard_email_attachments( $attachments, $email_id, $order, $email = null ) {
        unset( $email );
        if ( ! $order instanceof WC_Order || ! in_array( $email_id, [ 'customer_processing_order', 'customer_on_hold_order', 'customer_invoice' ], true ) || ! $this->order_has_rental( $order ) ) {
            return $attachments;
        }
        $contract_id = $this->ensure_contract_for_order( $order->get_id() );
        if ( $contract_id ) {
            $document = $this->get_document( $contract_id );
            if ( $document ) {
                $tmp = $this->temporary_pdf( $document );
                if ( ! is_wp_error( $tmp ) ) {
                    $attachments[] = $tmp;
                    $this->schedule_temp_cleanup( $tmp );
                }
            }
        }
        $terms_tmp = $this->temporary_terms_pdf( $order );
        if ( $terms_tmp && ! is_wp_error( $terms_tmp ) ) {
            $attachments[] = $terms_tmp;
            $this->schedule_temp_cleanup( $terms_tmp );
        }
        return $attachments;
    }

    private function temporary_terms_pdf( WC_Order $order ) {
        $terms_module      = RMWC_V2_Terms::instance();
        $snapshots         = $terms_module->order_terms_snapshots( $order );
        $product_snapshots = $terms_module->order_product_terms_snapshots( $order );
        if ( ! $snapshots && ! $product_snapshots ) {
            return false;
        }
        $lines = [];
        if ( $snapshots ) {
            $lines[] = 'Allgemeine Mietbedingungen';
            $lines[] = '';
            foreach ( $snapshots as $snapshot ) {
                $lines[] = 'Version ' . sanitize_text_field( $snapshot['version'] ?? '' );
                if ( ! empty( $snapshot['accepted_at'] ) ) {
                    $lines[] = 'Akzeptiert am ' . $this->display_datetime( $snapshot['accepted_at'] );
                }
                $lines[] = '';
                $lines = array_merge( $lines, RMWC_PDF::html_to_lines( $snapshot['content'] ?? '' ) );
                $lines[] = '';
            }
        }
        foreach ( $product_snapshots as $snapshot ) {
            if ( ! is_array( $snapshot ) ) {
                continue;
            }
            $lines[] = '';
            $lines[] = 'Ergänzende Mietbedingungen – ' . sanitize_text_field( $snapshot['product_name'] ?? 'Mietartikel' );
            if ( ! empty( $snapshot['version'] ) ) {
                $lines[] = sanitize_text_field( $snapshot['version'] );
            }
            if ( ! empty( $snapshot['accepted_at'] ) ) {
                $lines[] = 'Akzeptiert am ' . $this->display_datetime( $snapshot['accepted_at'] );
            }
            $lines[] = '';
            $lines = array_merge( $lines, RMWC_PDF::html_to_lines( $snapshot['content'] ?? '' ) );
            $lines[] = '';
        }
        return $this->write_temporary_pdf(
            'mietbedingungen-bestellung-' . sanitize_file_name( $order->get_order_number() ) . '.pdf',
            RMWC_PDF::build( 'Akzeptierte Mietbedingungen', $lines ),
            'clr_terms_temp',
            __( 'Mietbedingungen-PDF konnte nicht angelegt werden.', 'patsch9-rental-engine' )
        );
    }

    private function schedule_temp_cleanup( $path ) {
        register_shutdown_function(
            static function() use ( $path ) {
                if ( is_string( $path ) && is_file( $path ) ) {
                    @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup of generated temp file.
                }
            }
        );
    }

    public function register_email_class( $emails ) {
        if ( ! class_exists( 'RMWC_Email_Document', false ) ) {
            require_once RMWC_DIR . 'includes/v2/emails/class-rmwc-email-document.php';
        }
        if ( class_exists( 'RMWC_Email_Document', false ) ) {
            $emails['RMWC_Email_Document'] = new RMWC_Email_Document();
        }
        return $emails;
    }

    public function send_document_email( WC_Order $order, $document_id, $subject, $heading, $body ) {
        $document = $this->get_document( $document_id );
        if ( ! $document || (int) $document['order_id'] !== $order->get_id() || ! is_email( $order->get_billing_email() ) ) {
            return false;
        }

        $temporary_paths = [];
        $tmp = $this->temporary_pdf( $document );
        if ( is_wp_error( $tmp ) ) {
            return false;
        }
        $temporary_paths[] = $tmp;

        // A rental contract is only complete together with the exact terms
        // snapshot accepted for this order. Attach that immutable version too.
        if ( in_array( $document['type'] ?? '', [ 'contract', 'contract_revision' ], true ) ) {
            $terms_tmp = $this->temporary_terms_pdf( $order );
            if ( $terms_tmp && ! is_wp_error( $terms_tmp ) ) {
                $temporary_paths[] = $terms_tmp;
            }
        }

        $sent = false;
        try {
            if ( ! class_exists( 'RMWC_Email_Document', false ) ) {
                require_once RMWC_DIR . 'includes/v2/emails/class-rmwc-email-document.php';
            }

            $rental_email = null;
            $mailer       = WC()->mailer();
            $emails       = $mailer ? $mailer->get_emails() : [];
            foreach ( is_array( $emails ) ? $emails : [] as $email_instance ) {
                if ( $email_instance instanceof RMWC_Email_Document ) {
                    $rental_email = $email_instance;
                    break;
                }
            }
            // Some mailer/plugin combinations initialize WooCommerce emails
            // before our filter is applied. Do not silently skip the rental
            // mail in that case; instantiate the dedicated WC_Email directly.
            if ( ! $rental_email && class_exists( 'RMWC_Email_Document', false ) ) {
                $rental_email = new RMWC_Email_Document();
            }

            if ( $rental_email instanceof RMWC_Email_Document ) {
                $sent = $rental_email->trigger_document(
                    $order,
                    sanitize_text_field( $subject ),
                    sanitize_text_field( $heading ),
                    wp_kses_post( $body ),
                    $temporary_paths
                );
            }
        } catch ( Throwable $exception ) {
            // Contract delivery must never make checkout/order processing fail.
            // The caller records a visible order note and can retry manually.
            $sent = false;
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Mietdokument-E-Mail konnte nicht versendet werden: ' . substr( sanitize_text_field( $exception->getMessage() ), 0, 500 ),
                    [ 'source' => 'patsch9-rental-engine' ]
                );
            }
        } finally {
            foreach ( $temporary_paths as $temporary_path ) {
                if ( is_string( $temporary_path ) && is_file( $temporary_path ) ) {
                    @unlink( $temporary_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Immediate cleanup after WooCommerce mail send returns.
                }
            }
        }
        return (bool) $sent;
    }

    /**
     * Status-hook fallback for contract delivery.
     *
     * @param int $order_id Order ID.
     * @return bool
     */
    public function maybe_send_contract_email_for_order( $order_id ) {
        return $this->send_initial_contract_email( absint( $order_id ), [] );
    }

    /**
     * Send the first contract mail once the rental booking exists.
     *
     * @param WC_Order|int $order Order object or ID.
     * @param int[]        $booking_ids Created booking IDs.
     */
    public function send_initial_contract_email( $order, $booking_ids = [] ) {
        unset( $booking_ids );
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( absint( $order ) );
        }
        if ( ! $order || ! $this->order_has_rental( $order ) || ! is_email( $order->get_billing_email() ) ) {
            return false;
        }

        $already_sent = absint( $order->get_meta( '_clr_contract_email_document_id', true ) );
        if ( $already_sent ) {
            return true;
        }

        $contract_id = $this->ensure_contract_for_order( $order->get_id() );
        if ( ! $contract_id ) {
            return false;
        }

        $sent = $this->send_document_email(
            $order,
            $contract_id,
            __( 'Ihr Mietvertrag zu Ihrer Bestellung #{order_number}', 'patsch9-rental-engine' ),
            __( 'Mietvertrag und Mietbedingungen', 'patsch9-rental-engine' ),
            __( 'Vielen Dank für Ihre Mietbestellung. Im Anhang erhalten Sie Ihren Mietvertrag. Die von Ihnen bei der Bestellung akzeptierte Fassung der Mietbedingungen ist im Mietvertrag vollständig als Anlage enthalten und wird zusätzlich als separates PDF beigefügt. Bitte bewahren Sie die Dokumente für den Mietzeitraum auf.', 'patsch9-rental-engine' )
        );
        if ( $sent ) {
            $order->update_meta_data( '_clr_contract_email_document_id', $contract_id );
            $order->delete_meta_data( '_clr_contract_email_last_failed_at' );
            $order->save();
            $order->add_order_note( __( 'Vermietung: Mietvertrag und akzeptierte Mietbedingungen wurden automatisch per E-Mail versendet.', 'patsch9-rental-engine' ) );
        } else {
            $last_failed = (string) $order->get_meta( '_clr_contract_email_last_failed_at', true );
            $now         = current_time( 'mysql', true );
            // Avoid filling the order notes when several status hooks run in a
            // short period, but keep a visible diagnostic for failed sends.
            if ( '' === $last_failed || strtotime( $last_failed . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
                $order->add_order_note( __( 'Vermietung: Der automatische Versand des Mietvertrags ist fehlgeschlagen. Bitte WooCommerce-E-Mail-/SMTP-Konfiguration prüfen oder den Vertrag über „Mietdokumente“ erneut senden.', 'patsch9-rental-engine' ) );
            }
            $order->update_meta_data( '_clr_contract_email_last_failed_at', $now );
            $order->save();
        }
        return (bool) $sent;
    }

    /**
     * Manually resend contract and accepted terms from the order screen.
     */
    public function admin_send_contract_email() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $nonce    = isset( $_POST['clr_contract_email_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_contract_email_nonce'] ) ) : '';
        if ( ! $order_id || ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_send_contract_email_' . $order_id ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || ! $this->order_has_rental( $order ) ) {
            wp_die( esc_html__( 'Mietbestellung nicht gefunden.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }
        $contract_id = $this->ensure_contract_for_order( $order_id );
        $sent = $contract_id
            ? $this->send_document_email(
                $order,
                $contract_id,
                __( 'Ihr Mietvertrag zu Ihrer Bestellung #{order_number}', 'patsch9-rental-engine' ),
                __( 'Mietvertrag und Mietbedingungen', 'patsch9-rental-engine' ),
                __( 'Im Anhang erhalten Sie erneut Ihren Mietvertrag inklusive der vollständig beigefügten, von Ihnen akzeptierten Mietbedingungen. Die Mietbedingungen werden zusätzlich als separates PDF angehängt.', 'patsch9-rental-engine' )
            )
            : false;

        if ( $sent ) {
            $order->update_meta_data( '_clr_contract_email_document_id', $contract_id );
            $order->save();
            $order->add_order_note( __( 'Vermietung: Mietvertrag und akzeptierte Mietbedingungen wurden manuell erneut per E-Mail versendet.', 'patsch9-rental-engine' ) );
        }

        $redirect = $order->get_edit_order_url();
        $redirect = add_query_arg( 'clr_contract_mail', $sent ? 'sent' : 'failed', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function create_booking_change_document( $order, $booking_id, $old_row = [], $new_row = [] ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( absint( $order ) );
        }
        if ( ! $order || ! $this->order_has_rental( $order ) ) {
            return 0;
        }
        $snapshot = $this->build_contract_snapshot( $order );
        $old_row = is_array( $old_row ) ? $old_row : [];
        $new_row = is_array( $new_row ) ? $new_row : [];
        $snapshot['change'] = [
            'booking_id' => absint( $booking_id ),
            'old_period' => sanitize_text_field( ( $old_row['start_at'] ?? '' ) . ' – ' . ( $old_row['end_at'] ?? '' ) ),
            'new_period' => sanitize_text_field( ( $new_row['start_at'] ?? '' ) . ' – ' . ( $new_row['end_at'] ?? '' ) ),
        ];
        $event_seed = wp_json_encode( $snapshot['change'] );
        $event_key = 'booking_change_b' . absint( $booking_id ) . '_' . substr( hash( 'sha256', (string) $event_seed ), 0, 16 );
        $created = $this->create_document( $order, 'contract_revision', $snapshot, 0, $event_key );
        if ( ! is_wp_error( $created ) && $created ) {
            $this->send_document_email(
                $order,
                (int) $created,
                __( 'Aktualisierung Ihres Mietvertrags', 'patsch9-rental-engine' ),
                __( 'Mietvertragsänderung', 'patsch9-rental-engine' ),
                __( 'Die Daten Ihrer Vermietung wurden geändert. Die aktualisierte Vertragsdokumentation finden Sie im Anhang.', 'patsch9-rental-engine' )
            );
        }
        return is_wp_error( $created ) ? 0 : (int) $created;
    }

    public function register_privacy_exporter( $exporters ) {
        $exporters['patsch9-rental-engine-documents'] = [
            'exporter_friendly_name' => __( ' Mietdokumente', 'patsch9-rental-engine' ),
            'callback'               => [ $this, 'privacy_exporter' ],
        ];
        return $exporters;
    }

    public function privacy_exporter( $email_address, $page = 1 ) {
        if ( 1 !== (int) $page || ! is_email( $email_address ) ) {
            return [ 'data' => [], 'done' => true ];
        }
        $orders = wc_get_orders( [ 'billing_email' => sanitize_email( $email_address ), 'limit' => 100, 'return' => 'ids' ] );
        $data = [];
        foreach ( $orders as $order_id ) {
            foreach ( $this->documents_for_order( $order_id ) as $document ) {
                $data[] = [
                    'group_id'    => 'patsch9-rental-engine-documents',
                    'group_label' => __( 'Mietdokumente', 'patsch9-rental-engine' ),
                    'item_id'     => 'clr-document-' . (int) $document['id'],
                    'data'        => [
                        [ 'name' => __( 'Dokumentnummer', 'patsch9-rental-engine' ), 'value' => $document['document_number'] ],
                        [ 'name' => __( 'Dokumenttyp', 'patsch9-rental-engine' ), 'value' => $this->type_label( $document['type'] ) ],
                        [ 'name' => __( 'Erstellt', 'patsch9-rental-engine' ), 'value' => $document['created_at'] ],
                    ],
                ];
            }
        }
        return [ 'data' => $data, 'done' => true ];
    }

    public function register_privacy_eraser( $erasers ) {
        $erasers['patsch9-rental-engine-documents'] = [
            'eraser_friendly_name' => __( ' Mietdokumente', 'patsch9-rental-engine' ),
            'callback'             => [ $this, 'privacy_eraser' ],
        ];
        return $erasers;
    }

    public function privacy_eraser( $email_address, $page = 1 ) {
        unset( $page );
        if ( ! is_email( $email_address ) ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }
        // Rental documents are business records linked to WooCommerce orders.
        // Do not silently destroy them via the privacy tool; report retention.
        $orders = wc_get_orders( [ 'billing_email' => sanitize_email( $email_address ), 'limit' => 1, 'return' => 'ids' ] );
        return [
            'items_removed'  => false,
            'items_retained' => ! empty( $orders ),
            'messages'       => $orders ? [ __( 'Mietverträge und Kautionsbelege werden als Geschäftsunterlagen zusammen mit der zugehörigen WooCommerce-Bestellung aufbewahrt.', 'patsch9-rental-engine' ) ] : [],
            'done'           => true,
        ];
    }
}
