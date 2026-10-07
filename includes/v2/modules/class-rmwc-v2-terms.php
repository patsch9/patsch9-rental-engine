<?php
/**
 * Versioned global rental terms.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Terms {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rmwc_render_admin_tab_terms', [ $this, 'render_admin_tab' ] );
        add_action( 'admin_post_rmwc_save_terms', [ $this, 'save_terms' ] );
        add_action( 'rmwc_product_panel_after_handover', [ $this, 'product_fields' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields' ], 30 );
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_acceptance' ], 13 );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_acceptance' ], 30, 5 );
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_snapshot' ], 30, 3 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'line_item_snapshot' ], 30, 4 );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'order_snapshot' ], 30, 2 );
        // Checkout Blocks / Store API do not run the classic checkout-order
        // hook. Persist the exact accepted terms snapshot on the real order
        // before the Store API processes payment.
        add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'store_api_order_snapshot' ], 30, 1 );
        add_action( 'woocommerce_email_after_order_table', [ $this, 'email_terms_note' ], 25, 4 );
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_terms_versions';
    }

    private function content_integrity_hash( $content ) {
        return hash( 'sha256', (string) $content );
    }

    private function content_integrity_matches( $content, $stored_hash ) {
        $stored_hash = strtolower( sanitize_text_field( $stored_hash ) );
        return preg_match( '/^[a-f0-9]{64}$/', $stored_hash ) && hash_equals( $stored_hash, $this->content_integrity_hash( $content ) );
    }

    private function validate_terms_row( $row ) {
        if ( ! is_array( $row ) ) {
            return null;
        }
        $content = (string) ( $row['content'] ?? '' );
        $hash    = sanitize_text_field( $row['content_hash'] ?? '' );
        if ( strlen( $content ) > 500000 || ! $this->content_integrity_matches( $content, $hash ) ) {
            return null;
        }
        return $row;
    }

    public function active_terms() {
        global $wpdb;
        $table = $this->table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, version, content, content_hash, created_at FROM %i WHERE active = 1 ORDER BY id DESC LIMIT 1", $table ), ARRAY_A );
        return $this->validate_terms_row( $row );
    }

    public function terms_by_id( $id ) {
        global $wpdb;
        $id = absint( $id );
        if ( ! $id ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, version, content, content_hash, created_at FROM %i WHERE id = %d LIMIT 1", $this->table(), $id ), ARRAY_A );
        return $this->validate_terms_row( $row );
    }

    private function product_requires_terms( $product_id ) {
        if ( 'yes' !== get_post_meta( $product_id, '_clr_rental_enabled', true ) ) {
            return false;
        }
        $value = get_post_meta( $product_id, '_clr_terms_required', true );
        return '' === $value || 'yes' === $value;
    }

    private function product_terms_history( $product_id ) {
        $history = get_post_meta( absint( $product_id ), '_clr_product_terms_versions', true );
        return is_array( $history ) ? array_values( array_filter( $history, 'is_array' ) ) : [];
    }

    /**
     * Return the currently active immutable supplemental terms version.
     *
     * @param int $product_id Product ID.
     * @return array|null
     */
    public function active_product_terms( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return null;
        }
        $active_id = absint( get_post_meta( $product_id, '_clr_product_terms_active_id', true ) );
        if ( ! $active_id ) {
            return null;
        }
        foreach ( $this->product_terms_history( $product_id ) as $row ) {
            if ( $active_id !== absint( $row['id'] ?? 0 ) ) {
                continue;
            }
            $content = (string) ( $row['content'] ?? '' );
            $hash    = sanitize_text_field( $row['hash'] ?? '' );
            if ( strlen( $content ) > 250000 || ! $this->content_integrity_matches( $content, $hash ) ) {
                return null;
            }
            return $row;
        }
        return null;
    }


    private function product_terms_version_display( array $row ) {
        $created_at = sanitize_text_field( $row['created_at'] ?? '' );
        if ( '' !== $created_at ) {
            $stamp = mysql2date( 'Y-m-d H:i', $created_at, false );
            if ( '' !== $stamp ) {
                return $stamp;
            }
        }

        $version = sanitize_text_field( $row['version'] ?? '' );
        if ( preg_match( '/Version\s+\d+\s+[–-]\s+(.+)/u', $version, $matches ) ) {
            $legacy = trim( (string) $matches[1] );
            $dt     = date_create_from_format( 'd.m.Y H:i', $legacy, wp_timezone() );
            if ( $dt instanceof DateTimeInterface ) {
                return $dt->format( 'Y-m-d H:i' );
            }
            return $legacy;
        }
        return $version;
    }

    private function format_product_terms_content( $content ) {
        $content = trim( (string) $content );
        if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
            return '';
        }

        $content = preg_replace( '/\R/u', "
", $content );
        $content = preg_replace( '/\s+##\s+/u', "
## ", $content );
        $content = preg_replace( '/\s+#\s+/u', "
# ", $content );

        if ( $content !== wp_strip_all_tags( $content ) ) {
            return wpautop( wp_kses_post( $content ) );
        }

        $lines      = preg_split( "/\n+/u", $content ) ?: [];
        $html       = '';
        $paragraph  = [];
        $list_type  = '';

        $close_paragraph = static function() use ( &$html, &$paragraph ) {
            if ( ! $paragraph ) {
                return;
            }
            $text = implode( ' ', array_map( 'trim', $paragraph ) );
            if ( '' !== $text ) {
                $html .= '<p>' . esc_html( $text ) . '</p>';
            }
            $paragraph = [];
        };
        $close_list = static function() use ( &$html, &$list_type ) {
            if ( '' !== $list_type ) {
                $html .= '</' . $list_type . '>';
                $list_type = '';
            }
        };

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                $close_paragraph();
                $close_list();
                continue;
            }
            if ( preg_match( '/^##+\s*(.+)$/u', $line, $matches ) ) {
                $close_paragraph();
                $close_list();
                $html .= '<h4>' . esc_html( trim( (string) $matches[1] ) ) . '</h4>';
                continue;
            }
            if ( preg_match( '/^#\s*(.+)$/u', $line, $matches ) ) {
                $close_paragraph();
                $close_list();
                $html .= '<h3>' . esc_html( trim( (string) $matches[1] ) ) . '</h3>';
                continue;
            }
            if ( preg_match( '/^[-*]\s+(.+)$/u', $line, $matches ) ) {
                $close_paragraph();
                if ( 'ul' !== $list_type ) {
                    $close_list();
                    $html .= '<ul>';
                    $list_type = 'ul';
                }
                $html .= '<li>' . esc_html( trim( (string) $matches[1] ) ) . '</li>';
                continue;
            }
            if ( preg_match( '/^\d+[\.)]\s+(.+)$/u', $line, $matches ) ) {
                $close_paragraph();
                if ( 'ol' !== $list_type ) {
                    $close_list();
                    $html .= '<ol>';
                    $list_type = 'ol';
                }
                $html .= '<li>' . esc_html( trim( (string) $matches[1] ) ) . '</li>';
                continue;
            }
            $close_list();
            $paragraph[] = $line;
        }

        $close_paragraph();
        $close_list();
        return $html;
    }

    private function save_product_terms_version( $product_id, $content ) {
        $product_id = absint( $product_id );
        $content    = trim( wp_kses_post( $content ) );
        if ( strlen( $content ) > 250000 ) {
            wp_die( esc_html__( 'Die ergänzenden Mietbedingungen sind zu groß. Bitte kürzen Sie den Inhalt.', 'patsch9-rental-engine' ), '', [ 'response' => 413 ] );
        }
        update_post_meta( $product_id, '_clr_product_terms_content', $content );

        if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
            update_post_meta( $product_id, '_clr_product_terms_active_id', 0 );
            return;
        }

        $current = $this->active_product_terms( $product_id );
        if ( is_array( $current ) && ! empty( $current['hash'] ) && $this->content_integrity_matches( $content, $current['hash'] ) ) {
            return;
        }
        $hash = $this->content_integrity_hash( $content );

        $history = $this->product_terms_history( $product_id );
        $next_id = 1;
        foreach ( $history as $row ) {
            $next_id = max( $next_id, absint( $row['id'] ?? 0 ) + 1 );
        }
        $now = current_datetime();
        $history[] = [
            'id'         => $next_id,
            'version'    => $now->format( 'Y-m-d H:i' ),
            'hash'       => $hash,
            'content'    => $content,
            'created_at' => $now->format( 'Y-m-d H:i:s' ),
            'created_by' => get_current_user_id(),
        ];
        update_post_meta( $product_id, '_clr_product_terms_versions', $history );
        update_post_meta( $product_id, '_clr_product_terms_active_id', $next_id );
    }

    public function product_fields( $product_id ) {
        $value = get_post_meta( $product_id, '_clr_terms_required', true );
        if ( '' === $value ) {
            $value = 'yes';
        }
        woocommerce_wp_checkbox(
            [
                'id'            => '_clr_terms_required',
                'wrapper_class' => 'clr-rental-field-row',
                'label'         => __( 'Mietbedingungen bestätigen', 'patsch9-rental-engine' ),
                'value'         => $value,
                'description'   => __( 'Kunden müssen die allgemeinen und – sofern hinterlegt – die ergänzenden artikelspezifischen Mietbedingungen vor dem Hinzufügen zum Warenkorb bestätigen.', 'patsch9-rental-engine' ),
                'desc_tip'      => true,
            ]
        );

        $active  = $this->active_product_terms( $product_id );
        $content = (string) get_post_meta( $product_id, '_clr_product_terms_content', true );
        ?>
        <div class="form-field clr-rental-field-row clr-product-terms-editor-row">
            <label for="clr_product_terms_content_editor"><?php esc_html_e( 'Ergänzende Mietbedingungen für diesen Artikel', 'patsch9-rental-engine' ); ?></label>
            <div class="clr-product-terms-editor-wrap">
                <p class="description clr-product-terms-editor-help"><?php esc_html_e( 'Optional. Hier nur die Besonderheiten dieses Mietartikels festhalten, z. B. zulässige Nutzung, Reinigung, Transport oder Bedienvorgaben. Bei jeder inhaltlichen Änderung wird automatisch eine neue unveränderliche Version erzeugt; bereits akzeptierte Bestellungen behalten ihren Snapshot.', 'patsch9-rental-engine' ); ?></p>
                <?php
                wp_editor(
                    $content,
                    'clr_product_terms_content_editor',
                    [
                        'textarea_name' => '_clr_product_terms_content',
                        'textarea_rows' => 14,
                        'media_buttons' => false,
                        'teeny'         => false,
                        'quicktags'     => true,
                        'tinymce'       => true,
                    ]
                );
                ?>
            </div>
        </div>
        <?php
        if ( $active ) {
            echo '<p class="form-field clr-product-terms-version"><label>' . esc_html__( 'Aktive Zusatzfassung', 'patsch9-rental-engine' ) . '</label><span><strong>' . esc_html__( 'Version', 'patsch9-rental-engine' ) . ' ' . esc_html( $this->product_terms_version_display( $active ) ) . '</strong><br><small>' . esc_html__( 'Prüfsumme:', 'patsch9-rental-engine' ) . ' ' . esc_html( $active['hash'] ?? '' ) . '</small></span></p>';
        }
    }

    public function save_product_fields( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['clr_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clr_save_product_' . $post_id ) ) {
            return;
        }
        update_post_meta( $post_id, '_clr_terms_required', isset( $_POST['_clr_terms_required'] ) ? 'yes' : 'no' );
        $product_terms = isset( $_POST['_clr_product_terms_content'] ) ? wp_kses_post( wp_unslash( $_POST['_clr_product_terms_content'] ) ) : '';
        $this->save_product_terms_version( $post_id, $product_terms );
    }

    public function render_acceptance() {
        global $product;
        if ( ! $product instanceof WC_Product ) {
            return;
        }
        $product_id = $product->get_id();
        if ( ! $this->product_requires_terms( $product_id ) ) {
            return;
        }
        $terms         = $this->active_terms();
        $product_terms = $this->active_product_terms( $product_id );
        $has_general   = $terms && '' !== trim( wp_strip_all_tags( $terms['content'] ?? '' ) );
        $has_product   = $product_terms && '' !== trim( wp_strip_all_tags( $product_terms['content'] ?? '' ) );
        if ( ! $has_general && ! $has_product ) {
            return;
        }
        ?>
        <div class="clr-v2-terms-acceptance">
            <label class="clr-v2-checkbox-row">
                <input type="checkbox" name="clr_terms_accepted" value="yes" required>
                <span><?php
                if ( $has_general && $has_product ) {
                    esc_html_e( 'Ich habe die allgemeinen Mietbedingungen sowie die ergänzenden Mietbedingungen für diesen Artikel gelesen und akzeptiere sie.', 'patsch9-rental-engine' );
                } elseif ( $has_product ) {
                    esc_html_e( 'Ich habe die ergänzenden Mietbedingungen für diesen Artikel gelesen und akzeptiere sie.', 'patsch9-rental-engine' );
                } else {
                    esc_html_e( 'Ich habe die Mietbedingungen gelesen und akzeptiere sie.', 'patsch9-rental-engine' );
                }
                ?></span>
            </label>
            <?php if ( $has_general ) : ?>
                <details class="clr-v2-terms-details">
                    <summary><?php
                    /* translators: %s: rental terms version. */
                    echo esc_html( sprintf( __( 'Allgemeine Mietbedingungen anzeigen (Version %s)', 'patsch9-rental-engine' ), $terms['version'] ) );
                    ?></summary>
                    <div class="clr-v2-terms-content"><?php echo wp_kses_post( $terms['content'] ); ?></div>
                </details>
            <?php endif; ?>
            <?php if ( $has_product ) : ?>
                <details class="clr-v2-terms-details clr-v2-product-terms-details">
                    <summary><?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: rental product name, 2: supplemental terms version. */
                            __( 'Ergänzende Mietbedingungen – %1$s (Version %2$s)', 'patsch9-rental-engine' ),
                            $product->get_name(),
                            $this->product_terms_version_display( $product_terms )
                        )
                    );
                    ?></summary>
                    <div class="clr-v2-terms-content clr-v2-product-terms-content"><?php echo wp_kses_post( $this->format_product_terms_content( $product_terms['content'] ?? '' ) ); ?></div>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    private function frontend_nonce_valid( $product_id ) {
        $nonce = isset( $_POST['clr_rental_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_rental_nonce'] ) ) : '';
        return $nonce && wp_verify_nonce( $nonce, 'clr_add_rental_' . absint( $product_id ) );
    }

    public function validate_acceptance( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        unset( $quantity, $variations );
        $config_id = $variation_id && 'yes' === get_post_meta( $variation_id, '_clr_rental_enabled', true ) ? $variation_id : $product_id;
        if ( ! $this->product_requires_terms( $config_id ) ) {
            return $passed;
        }
        if ( ! $this->frontend_nonce_valid( $product_id ) ) {
            wc_add_notice( __( 'Die Mietdaten konnten nicht verifiziert werden. Bitte Seite neu laden.', 'patsch9-rental-engine' ), 'error' );
            return false;
        }
        $has_general = (bool) $this->active_terms();
        $has_product = (bool) $this->active_product_terms( $config_id );
        if ( ! $has_product && $config_id !== $product_id ) {
            $has_product = (bool) $this->active_product_terms( $product_id );
        }
        if ( ! $has_general && ! $has_product ) {
            return $passed;
        }
        $accepted = isset( $_POST['clr_terms_accepted'] ) ? sanitize_key( wp_unslash( $_POST['clr_terms_accepted'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- frontend_nonce_valid() verified the product-specific rental nonce immediately above.
        if ( 'yes' !== $accepted ) {
            wc_add_notice( __( 'Bitte bestätigen Sie die Mietbedingungen.', 'patsch9-rental-engine' ), 'error' );
            return false;
        }
        return $passed;
    }

    public function add_cart_snapshot( $data, $product_id, $variation_id ) {
        $config_id = $variation_id && 'yes' === get_post_meta( $variation_id, '_clr_rental_enabled', true ) ? $variation_id : $product_id;
        if ( empty( $data['clr_rental'] ) || ! $this->product_requires_terms( $config_id ) ) {
            return $data;
        }
        if ( ! $this->frontend_nonce_valid( $product_id ) ) {
            return $data;
        }

        $accepted_at = current_time( 'mysql', true );
        $terms       = $this->active_terms();
        if ( $terms ) {
            $data['clr_rental']['terms'] = [
                'id'          => (int) $terms['id'],
                'version'     => sanitize_text_field( $terms['version'] ),
                'hash'        => sanitize_text_field( $terms['content_hash'] ),
                'accepted_at' => $accepted_at,
            ];
        }

        $terms_product_id = $config_id;
        $product_terms   = $this->active_product_terms( $terms_product_id );
        if ( ! $product_terms && $config_id !== $product_id ) {
            $terms_product_id = $product_id;
            $product_terms    = $this->active_product_terms( $terms_product_id );
        }
        if ( $product_terms ) {
            $product_obj = wc_get_product( $terms_product_id );
            $data['clr_rental']['product_terms'] = [
                'product_id'   => $terms_product_id,
                'product_name' => $product_obj ? sanitize_text_field( $product_obj->get_name() ) : sanitize_text_field( get_the_title( $config_id ) ),
                'id'           => absint( $product_terms['id'] ?? 0 ),
                'version'      => sanitize_text_field( $this->product_terms_version_display( $product_terms ) ),
                'hash'         => sanitize_text_field( $product_terms['hash'] ?? '' ),
                'content'      => wp_kses_post( $product_terms['content'] ?? '' ),
                'accepted_at'  => $accepted_at,
            ];
        }
        return $data;
    }

    public function line_item_snapshot( $item, $cart_item_key, $values, $order ) {
        unset( $cart_item_key, $order );
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return;
        }
        if ( ! empty( $values['clr_rental']['terms'] ) && is_array( $values['clr_rental']['terms'] ) ) {
            $terms = $values['clr_rental']['terms'];
            $item->add_meta_data( '_clr_terms_version_id', absint( $terms['id'] ?? 0 ), true );
            $item->add_meta_data( '_clr_terms_version', sanitize_text_field( $terms['version'] ?? '' ), true );
            $item->add_meta_data( '_clr_terms_hash', sanitize_text_field( $terms['hash'] ?? '' ), true );
            $item->add_meta_data( '_clr_terms_accepted_at', sanitize_text_field( $terms['accepted_at'] ?? '' ), true );
        }
        if ( ! empty( $values['clr_rental']['product_terms'] ) && is_array( $values['clr_rental']['product_terms'] ) ) {
            $snapshot = $values['clr_rental']['product_terms'];
            $snapshot['content'] = wp_kses_post( $snapshot['content'] ?? '' );
            $item->add_meta_data( '_clr_product_terms_snapshot', $snapshot, true );
        }
    }

    public function order_snapshot( $order, $data = [] ) {
        unset( $data );
        if ( ! $order instanceof WC_Order || ! WC()->cart ) {
            return;
        }
        $snapshots         = [];
        $product_snapshots = [];
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['clr_rental']['terms']['id'] ) ) {
                $terms = $this->terms_by_id( absint( $cart_item['clr_rental']['terms']['id'] ) );
                if ( $terms ) {
                    $snapshots[ (int) $terms['id'] ] = [
                        'id'          => (int) $terms['id'],
                        'version'     => sanitize_text_field( $terms['version'] ),
                        'hash'        => sanitize_text_field( $terms['content_hash'] ),
                        'content'     => wp_kses_post( $terms['content'] ),
                        'accepted_at' => sanitize_text_field( $cart_item['clr_rental']['terms']['accepted_at'] ?? current_time( 'mysql', true ) ),
                    ];
                }
            }

            if ( ! empty( $cart_item['clr_rental']['product_terms'] ) && is_array( $cart_item['clr_rental']['product_terms'] ) ) {
                $row = $cart_item['clr_rental']['product_terms'];
                $content = wp_kses_post( $row['content'] ?? '' );
                $hash    = sanitize_text_field( $row['hash'] ?? '' );
                if ( '' !== $content && ( '' === $hash || $this->content_integrity_matches( $content, $hash ) ) ) {
                    $key = absint( $row['product_id'] ?? 0 ) . '|' . $hash;
                    $product_snapshots[ $key ] = [
                        'product_id'   => absint( $row['product_id'] ?? 0 ),
                        'product_name' => sanitize_text_field( $row['product_name'] ?? '' ),
                        'id'           => absint( $row['id'] ?? 0 ),
                        'version'      => sanitize_text_field( $row['version'] ?? '' ),
                        'hash'         => $hash,
                        'content'      => $content,
                        'accepted_at'  => sanitize_text_field( $row['accepted_at'] ?? current_time( 'mysql', true ) ),
                    ];
                }
            }
        }
        if ( $snapshots ) {
            $order->update_meta_data( '_clr_terms_snapshots', array_values( $snapshots ) );
        }
        if ( $product_snapshots ) {
            $order->update_meta_data( '_clr_product_terms_snapshots', array_values( $product_snapshots ) );
        }
    }

    /**
     * Persist rental-term snapshots during Checkout Block / Store API checkout.
     *
     * @param WC_Order $order Persisted checkout order.
     */
    public function store_api_order_snapshot( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $this->order_snapshot( $order, [] );
        // The Store API hook operates on the persisted order. Explicitly save
        // extension-owned metadata here so later booking/document hooks can
        // read it from a freshly loaded WC_Order instance.
        $order->save_meta_data();
    }

    /**
     * Return the immutable terms snapshots accepted for an order.
     *
     * 2.0.5-2.0.8 did not persist _clr_terms_snapshots on Checkout Block
     * orders. The accepted version identifier was nevertheless retained inside
     * the rental line-item snapshot. Recover from that source and persist the
     * complete immutable version so existing affected orders can be repaired
     * without substituting today's active terms.
     */
    public function order_terms_snapshots( WC_Order $order ) {
        $snapshots = $order->get_meta( '_clr_terms_snapshots', true );
        if ( is_array( $snapshots ) && $snapshots ) {
            $valid = [];
            foreach ( $snapshots as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $content = wp_kses_post( (string) ( $row['content'] ?? '' ) );
                $hash    = sanitize_text_field( $row['hash'] ?? '' );
                if ( '' === trim( wp_strip_all_tags( $content ) ) || ! $this->content_integrity_matches( $content, $hash ) ) {
                    continue;
                }
                $valid[] = [
                    'id'          => absint( $row['id'] ?? 0 ),
                    'version'     => sanitize_text_field( $row['version'] ?? '' ),
                    'hash'        => $hash,
                    'content'     => $content,
                    'accepted_at' => sanitize_text_field( $row['accepted_at'] ?? '' ),
                ];
            }
            if ( $valid ) {
                return $valid;
            }
        }

        $recovered = [];
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $version_id = absint( $item->get_meta( '_clr_terms_version_id', true ) );
            $version    = sanitize_text_field( $item->get_meta( '_clr_terms_version', true ) );
            $hash       = sanitize_text_field( $item->get_meta( '_clr_terms_hash', true ) );
            $accepted_at = sanitize_text_field( $item->get_meta( '_clr_terms_accepted_at', true ) );

            // The primary rental line-item snapshot contains the exact cart
            // state and survives Checkout Block order creation even on builds
            // where the dedicated terms line-item hook did not run.
            $rental = $item->get_meta( '_clr_rental_data', true );
            if ( is_array( $rental ) && is_array( $rental['terms'] ?? null ) ) {
                $embedded = $rental['terms'];
                if ( ! $version_id ) {
                    $version_id = absint( $embedded['id'] ?? 0 );
                }
                if ( '' === $version ) {
                    $version = sanitize_text_field( $embedded['version'] ?? '' );
                }
                if ( '' === $hash ) {
                    $hash = sanitize_text_field( $embedded['hash'] ?? '' );
                }
                if ( '' === $accepted_at ) {
                    $accepted_at = sanitize_text_field( $embedded['accepted_at'] ?? '' );
                }
            }

            if ( ! $version_id || isset( $recovered[ $version_id ] ) ) {
                continue;
            }
            $terms = $this->terms_by_id( $version_id );
            if ( ! $terms ) {
                continue;
            }
            $stored_hash = sanitize_text_field( $terms['content_hash'] ?? '' );
            // Never silently replace an accepted version with content whose
            // immutable hash does not match the recorded acceptance snapshot.
            if ( '' !== $hash && '' !== $stored_hash && ! hash_equals( $stored_hash, $hash ) ) {
                continue;
            }

            $recovered[ $version_id ] = [
                'id'          => $version_id,
                'version'     => '' !== $version ? $version : sanitize_text_field( $terms['version'] ?? '' ),
                'hash'        => '' !== $hash ? $hash : $stored_hash,
                'content'     => wp_kses_post( $terms['content'] ?? '' ),
                'accepted_at' => '' !== $accepted_at ? $accepted_at : sanitize_text_field( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '' ),
            ];
        }

        if ( ! $recovered ) {
            return [];
        }

        $snapshots = array_values( $recovered );
        $order->update_meta_data( '_clr_terms_snapshots', $snapshots );
        $order->save_meta_data();
        return $snapshots;
    }

    /**
     * Return immutable supplemental product-term snapshots accepted for an order.
     *
     * @param WC_Order $order Order.
     * @return array
     */
    public function order_product_terms_snapshots( WC_Order $order ) {
        $snapshots = $order->get_meta( '_clr_product_terms_snapshots', true );
        if ( is_array( $snapshots ) && $snapshots ) {
            $valid = [];
            foreach ( $snapshots as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $content = wp_kses_post( (string) ( $row['content'] ?? '' ) );
                $hash    = sanitize_text_field( $row['hash'] ?? '' );
                if ( '' === trim( wp_strip_all_tags( $content ) ) || ! $this->content_integrity_matches( $content, $hash ) ) {
                    continue;
                }
                $valid[] = [
                    'product_id'   => absint( $row['product_id'] ?? 0 ),
                    'product_name' => sanitize_text_field( $row['product_name'] ?? '' ),
                    'id'           => absint( $row['id'] ?? 0 ),
                    'version'      => sanitize_text_field( $row['version'] ?? '' ),
                    'hash'         => $hash,
                    'content'      => $content,
                    'accepted_at'  => sanitize_text_field( $row['accepted_at'] ?? '' ),
                ];
            }
            if ( $valid ) {
                return $valid;
            }
        }

        $recovered = [];
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $snapshot = $item->get_meta( '_clr_product_terms_snapshot', true );
            if ( ! is_array( $snapshot ) || empty( $snapshot['content'] ) ) {
                $rental = $item->get_meta( '_clr_rental_data', true );
                if ( is_array( $rental ) && is_array( $rental['product_terms'] ?? null ) ) {
                    $snapshot = $rental['product_terms'];
                }
            }
            if ( ! is_array( $snapshot ) ) {
                continue;
            }

            $content = wp_kses_post( $snapshot['content'] ?? '' );
            $hash    = sanitize_text_field( $snapshot['hash'] ?? '' );
            if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
                continue;
            }
            if ( '' !== $hash && ! $this->content_integrity_matches( $content, $hash ) ) {
                continue;
            }
            if ( '' === $hash ) {
                $hash = $this->content_integrity_hash( $content );
            }

            $product_id = absint( $snapshot['product_id'] ?? $item->get_product_id() );
            $key        = $product_id . '|' . $hash;
            $recovered[ $key ] = [
                'product_id'   => $product_id,
                'product_name' => sanitize_text_field( $snapshot['product_name'] ?? $item->get_name() ),
                'id'           => absint( $snapshot['id'] ?? 0 ),
                'version'      => sanitize_text_field( $snapshot['version'] ?? '' ),
                'hash'         => $hash,
                'content'      => $content,
                'accepted_at'  => sanitize_text_field( $snapshot['accepted_at'] ?? ( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '' ) ),
            ];
        }

        if ( ! $recovered ) {
            return [];
        }
        $snapshots = array_values( $recovered );
        $order->update_meta_data( '_clr_product_terms_snapshots', $snapshots );
        $order->save_meta_data();
        return $snapshots;
    }

    public function email_terms_note( $order, $sent_to_admin, $plain_text, $email ) {
        unset( $email );
        if ( $sent_to_admin || ! $order instanceof WC_Order ) {
            return;
        }
        $snapshots         = $this->order_terms_snapshots( $order );
        $product_snapshots = $this->order_product_terms_snapshots( $order );
        if ( ! $snapshots && ! $product_snapshots ) {
            return;
        }

        $versions = array_filter( array_map( static fn( $row ) => sanitize_text_field( $row['version'] ?? '' ), $snapshots ) );
        $product_labels = [];
        foreach ( $product_snapshots as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name    = sanitize_text_field( $row['product_name'] ?? '' );
            $version = $this->product_terms_version_display( $row );
            if ( '' !== $name ) {
                $product_labels[] = trim( $name . ( '' !== $version ? ' (Version ' . $version . ')' : '' ) );
            }
        }

        if ( $plain_text ) {
            if ( $versions ) {
                echo "
" . esc_html__( 'Allgemeine Mietbedingungen:', 'patsch9-rental-engine' ) . ' ' . esc_html( implode( ', ', $versions ) ) . "
";
            }
            if ( $product_labels ) {
                echo esc_html__( 'Ergänzende Mietbedingungen:', 'patsch9-rental-engine' ) . ' ' . esc_html( implode( '; ', $product_labels ) ) . "
";
            }
            return;
        }
        if ( $versions ) {
            echo '<p><strong>' . esc_html__( 'Allgemeine Mietbedingungen:', 'patsch9-rental-engine' ) . '</strong> ' . esc_html( implode( ', ', $versions ) ) . '</p>';
        }
        if ( $product_labels ) {
            echo '<p><strong>' . esc_html__( 'Ergänzende Mietbedingungen:', 'patsch9-rental-engine' ) . '</strong> ' . esc_html( implode( '; ', $product_labels ) ) . '</p>';
        }
    }

    private function terms_lock_name() {
        global $wpdb;
        $scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id();
        return 'clr_terms_' . substr( hash( 'sha256', $scope ), 0, 32 );
    }

    public function render_admin_tab() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ) );
        }
        global $wpdb;
        $active = $this->active_terms();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $history = $wpdb->get_results( $wpdb->prepare( "SELECT id, version, content_hash, active, created_at, created_by FROM %i ORDER BY id DESC LIMIT 30", $this->table() ), ARRAY_A );
        ?>
        <div class="clr-v2-admin-card">
            <h2><?php esc_html_e( 'Allgemeine Mietbedingungen', 'patsch9-rental-engine' ); ?></h2>
            <p><?php esc_html_e( 'Jede Änderung wird als neue, unveränderliche Version gespeichert. Bereits abgeschlossene Bestellungen behalten ihren akzeptierten Snapshot.', 'patsch9-rental-engine' ); ?></p>
            <?php if ( $active ) : ?>
                <p><strong><?php esc_html_e( 'Aktive Version:', 'patsch9-rental-engine' ); ?></strong> <?php echo esc_html( $active['version'] ); ?> · <?php echo esc_html( mysql2date( 'd.m.Y H:i', $active['created_at'] ) ); ?></p>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'Noch keine Mietbedingungen hinterlegt.', 'patsch9-rental-engine' ); ?></strong></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="rmwc_save_terms">
                <?php wp_nonce_field( 'rmwc_save_terms', 'rmwc_terms_nonce' ); ?>
                <p>
                    <label for="clr_terms_version"><strong><?php esc_html_e( 'Versionsbezeichnung', 'patsch9-rental-engine' ); ?></strong></label><br>
                    <input type="text" class="regular-text" maxlength="50" id="clr_terms_version" name="clr_terms_version" placeholder="z. B. 2026-08-13">
                </p>
                <?php
                wp_editor(
                    $active ? $active['content'] : '',
                    'clr_terms_content_editor',
                    [
                        'textarea_name' => 'clr_terms_content',
                        'textarea_rows' => 18,
                        'media_buttons' => false,
                        'teeny'         => false,
                    ]
                );
                submit_button( __( 'Als neue Version speichern und aktivieren', 'patsch9-rental-engine' ) );
                ?>
            </form>
        </div>

        <div class="clr-v2-admin-card">
            <h2><?php esc_html_e( 'Versionshistorie', 'patsch9-rental-engine' ); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Version', 'patsch9-rental-engine' ); ?></th><th><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></th><th><?php esc_html_e( 'Erstellt', 'patsch9-rental-engine' ); ?></th><th><?php esc_html_e( 'Prüfsumme', 'patsch9-rental-engine' ); ?></th></tr></thead>
                <tbody>
                <?php if ( ! $history ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'Noch keine Versionen vorhanden.', 'patsch9-rental-engine' ); ?></td></tr>
                <?php else : foreach ( $history as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row['version'] ); ?></strong></td>
                        <td><?php echo (int) $row['active'] === 1 ? esc_html__( 'Aktiv', 'patsch9-rental-engine' ) : esc_html__( 'Archiv', 'patsch9-rental-engine' ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $row['created_at'] ) ); ?></td>
                        <td><code><?php echo esc_html( substr( $row['content_hash'], 0, 16 ) ); ?>…</code></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function save_terms() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $nonce = isset( $_POST['rmwc_terms_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_terms_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_save_terms' ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        $version = isset( $_POST['clr_terms_version'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_terms_version'] ) ) : '';
        $version = substr( $version, 0, 50 );
        if ( '' === $version ) {
            $version = wp_date( 'Y-m-d-His' );
        }
        $content = isset( $_POST['clr_terms_content'] ) ? wp_kses_post( wp_unslash( $_POST['clr_terms_content'] ) ) : '';
        $content = trim( $content );
        if ( strlen( $content ) > 500000 ) {
            wp_die( esc_html__( 'Die allgemeinen Mietbedingungen sind zu groß. Bitte kürzen Sie den Inhalt.', 'patsch9-rental-engine' ), '', [ 'response' => 413 ] );
        }
        $redirect_base = [ 'page' => 'clr-rentals', 'tab' => 'terms' ];
        if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'clr_error' => 'empty' ] ), admin_url( 'admin.php' ) ) );
            exit;
        }
        $hash = $this->content_integrity_hash( $content );

        global $wpdb;
        $table     = $this->table();
        $lock_name = $this->terms_lock_name();
        $locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Application-level serialization for immutable terms versions.
        if ( 1 !== $locked ) {
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'clr_error' => 'busy' ] ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $saved = false;
        try {
            $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $existing_version = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE version = %s LIMIT 1", $table, $version ) );
            if ( $existing_version ) {
                $version .= '-' . wp_date( 'His' );
            }

            $deactivated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET active = 0 WHERE active = 1", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional custom-table update; caching would be unsafe here.
            if ( false === $deactivated ) {
                throw new RuntimeException( 'Could not deactivate previous terms version.' );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $inserted = $wpdb->insert(
                $table,
                [
                    'version'      => $version,
                    'content'      => $content,
                    'content_hash' => $hash,
                    'active'       => 1,
                    'created_at'   => current_time( 'mysql', true ),
                    'created_by'   => get_current_user_id(),
                ],
                [ '%s', '%s', '%s', '%d', '%s', '%d' ]
            );
            if ( false === $inserted ) {
                throw new RuntimeException( 'Could not insert terms version.' );
            }

            $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $saved = true;
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            /**
             * Fires when persisting a rental-terms version failed. No term
             * content is exposed in the action arguments.
             */
            do_action( 'rmwc_terms_save_failed', $exception );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release application lock acquired above.
        }

        wp_safe_redirect(
            add_query_arg(
                array_merge( $redirect_base, $saved ? [ 'clr_saved' => '1' ] : [ 'clr_error' => 'db' ] ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
