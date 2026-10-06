<?php
/**
 * Asset assignment, handover and return workflows.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Workflow {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rmwc_product_panel_v2_end', [ $this, 'product_fields' ], 10 );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields' ], 90 );

        add_filter( 'rmwc_finalize_booking_reservation', [ $this, 'assign_new_bookings' ], 10, 3 );
        add_filter( 'rmwc_finalize_manual_booking', [ $this, 'assign_manual_booking' ], 10, 2 );
        add_action( 'rmwc_booking_cancelled', [ $this, 'cancel_booking_assignments' ], 10, 2 );
        add_filter( 'rmwc_validate_booking_update', [ $this, 'reassign_updated_booking' ], 10, 3 );

        add_action( 'rmwc_render_admin_tab_workflow', [ $this, 'render_admin_tab' ] );
        add_action( 'admin_post_rmwc_workflow_handover', [ $this, 'handle_handover' ] );
        add_action( 'admin_post_rmwc_workflow_return', [ $this, 'handle_return' ] );

        add_action( 'add_meta_boxes', [ $this, 'add_order_meta_box' ], 45 );
    }

    private function booking_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_bookings';
    }

    private function asset_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_assets';
    }

    private function assignment_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_asset_assignments';
    }

    private function clean_checklist( $value ) {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $out = [];
        foreach ( array_slice( $value, 0, 50 ) as $item ) {
            $item = sanitize_text_field( is_string( $item ) ? $item : '' );
            $item = trim( $item );
            if ( '' !== $item ) {
                $out[] = function_exists( 'mb_substr' ) ? mb_substr( $item, 0, 220 ) : substr( $item, 0, 220 );
            }
        }
        return array_values( array_unique( $out ) );
    }

    private function checklist( $product_id, $type ) {
        $key = 'handover' === $type ? '_clr_handover_checklist' : '_clr_return_checklist';
        return $this->clean_checklist( get_post_meta( absint( $product_id ), $key, true ) );
    }

    public function product_fields( $product_id ) {
        $handover = $this->checklist( $product_id, 'handover' );
        $return   = $this->checklist( $product_id, 'return' );
        $signature = 'yes' === get_post_meta( $product_id, '_clr_workflow_signature_enabled', true );
        ?>
        <div class="options_group clr-workflow-config">
            <div class="clr-product-section-head">
                <h4><?php esc_html_e( 'Übergabe & Rückgabe', 'patsch9-rental-engine' ); ?></h4>
                <p class="description"><?php esc_html_e( 'Definiere produktspezifische Prüfpunkte. Beim Abschluss einer Übergabe oder Rückgabe werden die bestätigten Punkte unveränderlich im jeweiligen PDF-Protokoll gespeichert.', 'patsch9-rental-engine' ); ?></p>
            </div>
            <div class="clr-checklist-config-grid">
                <section class="clr-checklist-config-card">
                    <div class="clr-checklist-config-head">
                        <h5><?php esc_html_e( 'Übergabe-Checkliste', 'patsch9-rental-engine' ); ?></h5>
                        <button type="button" class="button clr-add-checklist-item" data-target="handover"><?php esc_html_e( '+ Punkt', 'patsch9-rental-engine' ); ?></button>
                    </div>
                    <div class="clr-checklist-items" data-checklist="handover">
                        <?php foreach ( $handover as $item ) : ?>
                            <div class="clr-checklist-item"><input type="text" maxlength="220" name="clr_handover_checklist[]" value="<?php echo esc_attr( $item ); ?>"><button type="button" class="button-link-delete clr-remove-checklist-item" aria-label="<?php esc_attr_e( 'Prüfpunkt entfernen', 'patsch9-rental-engine' ); ?>">&times;</button></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Beispiel: Gerät sauber, Zubehör vollständig, Einweisung erfolgt.', 'patsch9-rental-engine' ); ?></p>
                </section>
                <section class="clr-checklist-config-card">
                    <div class="clr-checklist-config-head">
                        <h5><?php esc_html_e( 'Rückgabe-Checkliste', 'patsch9-rental-engine' ); ?></h5>
                        <button type="button" class="button clr-add-checklist-item" data-target="return"><?php esc_html_e( '+ Punkt', 'patsch9-rental-engine' ); ?></button>
                    </div>
                    <div class="clr-checklist-items" data-checklist="return">
                        <?php foreach ( $return as $item ) : ?>
                            <div class="clr-checklist-item"><input type="text" maxlength="220" name="clr_return_checklist[]" value="<?php echo esc_attr( $item ); ?>"><button type="button" class="button-link-delete clr-remove-checklist-item" aria-label="<?php esc_attr_e( 'Prüfpunkt entfernen', 'patsch9-rental-engine' ); ?>">&times;</button></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Beispiel: Kessel gereinigt, Zubehör vollständig, keine sichtbaren Schäden.', 'patsch9-rental-engine' ); ?></p>
                </section>
            </div>
            <p class="form-field">
                <label for="_clr_workflow_signature_enabled"><?php esc_html_e( 'Unterschrift', 'patsch9-rental-engine' ); ?></label>
                <input type="checkbox" class="checkbox" id="_clr_workflow_signature_enabled" name="_clr_workflow_signature_enabled" value="yes" <?php checked( $signature ); ?>>
                <span class="description"><?php esc_html_e( 'Im Übergabe-/Rückgabeformular eine optionale handschriftliche Dokumentationsunterschrift anbieten. Dies ist keine qualifizierte elektronische Signatur.', 'patsch9-rental-engine' ); ?></span>
            </p>
        </div>
        <?php
    }

    public function save_product_fields( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['clr_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clr_save_product_' . $post_id ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability checked above; every checklist entry is sanitized in clean_checklist().
        $handover = isset( $_POST['clr_handover_checklist'] ) && is_array( $_POST['clr_handover_checklist'] ) ? wp_unslash( $_POST['clr_handover_checklist'] ) : [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability checked above; every checklist entry is sanitized in clean_checklist().
        $return = isset( $_POST['clr_return_checklist'] ) && is_array( $_POST['clr_return_checklist'] ) ? wp_unslash( $_POST['clr_return_checklist'] ) : [];
        update_post_meta( $post_id, '_clr_handover_checklist', $this->clean_checklist( $handover ) );
        update_post_meta( $post_id, '_clr_return_checklist', $this->clean_checklist( $return ) );
        update_post_meta( $post_id, '_clr_workflow_signature_enabled', isset( $_POST['_clr_workflow_signature_enabled'] ) ? 'yes' : 'no' );
    }

    private function booking( $booking_id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->booking_table(), absint( $booking_id ) ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    private function asset_tracking_enabled( $product_id ) {
        return 'yes' === get_post_meta( absint( $product_id ), '_clr_asset_tracking', true );
    }

    /**
     * Called while the core still holds all relevant product booking locks.
     */
    public function assign_new_bookings( $result, $order, $booking_ids ) {
        if ( is_wp_error( $result ) || ! $order instanceof WC_Order || ! is_array( $booking_ids ) ) {
            return $result;
        }

        $created_assignments = [];
        foreach ( $booking_ids as $booking_id ) {
            $booking = $this->booking( $booking_id );
            if ( ! $booking || ! $this->asset_tracking_enabled( (int) $booking['product_id'] ) ) {
                continue;
            }
            $assigned = $this->ensure_booking_assignments( $booking );
            if ( is_wp_error( $assigned ) ) {
                $this->delete_assignments_by_ids( $created_assignments );
                return $assigned;
            }
            $created_assignments = array_merge( $created_assignments, $assigned );
        }
        return true;
    }

    public function assign_manual_booking( $result, $booking_id ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $booking = $this->booking( $booking_id );
        if ( ! $booking || ! $this->asset_tracking_enabled( (int) $booking['product_id'] ) ) {
            return $result;
        }
        $assigned = $this->ensure_booking_assignments( $booking );
        return is_wp_error( $assigned ) ? $assigned : true;
    }

    /**
     * Ensure one physical asset per booked quantity. Returns only newly created
     * assignment IDs so a failed multi-booking order can be rolled back safely.
     */
    private function ensure_booking_assignments( array $booking ) {
        global $wpdb;
        $booking_id = absint( $booking['id'] ?? 0 );
        $product_id = absint( $booking['product_id'] ?? 0 );
        $quantity   = max( 1, absint( $booking['quantity'] ?? 1 ) );
        if ( ! $booking_id || ! $product_id ) {
            return new WP_Error( 'clr_asset_booking', __( 'Gerätezuordnung konnte nicht vorbereitet werden.', 'patsch9-rental-engine' ) );
        }

        $existing = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, asset_id FROM %i WHERE booking_id = %d AND status IN ('reserved','handed_over') ORDER BY id",
                $this->assignment_table(),
                $booking_id
            ),
            ARRAY_A
        );
        $existing = is_array( $existing ) ? $existing : [];
        if ( count( $existing ) >= $quantity ) {
            return [];
        }

        $need = $quantity - count( $existing );
        $already_asset_ids = array_map( 'absint', wp_list_pluck( $existing, 'asset_id' ) );
        $start_at = sanitize_text_field( $booking['start_at'] ?? '' );
        $end_at   = sanitize_text_field( $booking['end_at'] ?? '' );

        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id
                 FROM %i a
                 WHERE a.product_id = %d
                   AND a.status IN ('available','rented')
                   AND NOT EXISTS (
                       SELECT 1 FROM %i aa
                       WHERE aa.asset_id = a.id
                         AND aa.status IN ('reserved','handed_over')
                         AND aa.booking_id <> %d
                         AND aa.start_at < %s
                         AND aa.end_at > %s
                   )
                 ORDER BY a.id ASC
                 LIMIT %d",
                $this->asset_table(),
                $product_id,
                $this->assignment_table(),
                $booking_id,
                $end_at,
                $start_at,
                $need + count( $already_asset_ids ) + 20
            ),
            ARRAY_A
        );
        $candidates = is_array( $candidates ) ? $candidates : [];

        $available_ids = [];
        foreach ( $candidates as $candidate ) {
            $asset_id = absint( $candidate['id'] ?? 0 );
            if ( $asset_id && ! in_array( $asset_id, $already_asset_ids, true ) ) {
                $available_ids[] = $asset_id;
            }
            if ( count( $available_ids ) >= $need ) {
                break;
            }
        }

        if ( count( $available_ids ) < $need ) {
            return new WP_Error(
                'clr_asset_capacity',
                sprintf(
                    /* translators: 1: required number of physical assets, 2: rental product ID. */
                    __( 'Für diese Buchung werden %1$d konkrete Geräte benötigt, für Mietprodukt #%2$d sind im Zeitraum aber nicht genügend einsatzfähige Geräte hinterlegt.', 'patsch9-rental-engine' ),
                    $quantity,
                    $product_id
                )
            );
        }

        $created = [];
        foreach ( $available_ids as $asset_id ) {
            $ok = $wpdb->insert(
                $this->assignment_table(),
                [
                    'asset_id'   => $asset_id,
                    'booking_id' => $booking_id,
                    'order_id'   => absint( $booking['order_id'] ?? 0 ),
                    'start_at'   => $start_at,
                    'end_at'     => $end_at,
                    'status'     => 'reserved',
                    'created_at' => current_time( 'mysql', true ),
                    'updated_at' => current_time( 'mysql', true ),
                ],
                [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
            if ( false === $ok ) {
                $this->delete_assignments_by_ids( $created );
                return new WP_Error( 'clr_asset_assignment_db', __( 'Die konkrete Gerätezuordnung konnte nicht gespeichert werden.', 'patsch9-rental-engine' ) );
            }
            $created[] = (int) $wpdb->insert_id;
        }
        return $created;
    }

    private function delete_assignments_by_ids( array $ids ) {
        global $wpdb;
        foreach ( array_map( 'absint', $ids ) as $id ) {
            if ( $id ) {
                $wpdb->delete( $this->assignment_table(), [ 'id' => $id ], [ '%d' ] );
            }
        }
    }

    public function reassign_updated_booking( $result, $old_row, $new_row ) {
        if ( is_wp_error( $result ) || false === $result || ! is_array( $old_row ) || ! is_array( $new_row ) ) {
            return $result;
        }
        $product_id = absint( $new_row['product_id'] ?? 0 );
        if ( ! $this->asset_tracking_enabled( $product_id ) ) {
            return true;
        }
        if ( 'reserved' !== sanitize_key( $old_row['workflow_status'] ?? 'reserved' ) ) {
            return new WP_Error( 'clr_asset_edit_state', __( 'Bereits übergebene Geräte können nicht automatisch auf einen anderen Zeitraum umgebucht werden.', 'patsch9-rental-engine' ) );
        }

        global $wpdb;
        $booking_id = absint( $old_row['id'] ?? 0 );
        $old_assignments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE booking_id = %d AND status = 'reserved' ORDER BY id",
                $this->assignment_table(),
                $booking_id
            ),
            ARRAY_A
        );
        $old_assignments = is_array( $old_assignments ) ? $old_assignments : [];

        $wpdb->delete( $this->assignment_table(), [ 'booking_id' => $booking_id, 'status' => 'reserved' ], [ '%d', '%s' ] );
        $created = $this->ensure_booking_assignments( $new_row );
        if ( ! is_wp_error( $created ) ) {
            return true;
        }

        // Roll the physical assignment state back to the exact previous rows.
        $wpdb->delete( $this->assignment_table(), [ 'booking_id' => $booking_id, 'status' => 'reserved' ], [ '%d', '%s' ] );
        foreach ( $old_assignments as $assignment ) {
            $wpdb->insert(
                $this->assignment_table(),
                [
                    'asset_id'   => absint( $assignment['asset_id'] ),
                    'booking_id' => $booking_id,
                    'order_id'   => absint( $assignment['order_id'] ),
                    'start_at'   => sanitize_text_field( $assignment['start_at'] ),
                    'end_at'     => sanitize_text_field( $assignment['end_at'] ),
                    'status'     => 'reserved',
                    'created_at' => sanitize_text_field( $assignment['created_at'] ),
                    'updated_at' => sanitize_text_field( $assignment['updated_at'] ),
                ],
                [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
        return $created;
    }

    public function cancel_booking_assignments( $booking_id, $order_id = 0 ) {
        global $wpdb;
        $where = [ 'booking_id' => absint( $booking_id ) ];
        $where_format = [ '%d' ];
        if ( $order_id ) {
            $where['order_id'] = absint( $order_id );
            $where_format[] = '%d';
        }
        $wpdb->update(
            $this->assignment_table(),
            [ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ],
            $where,
            [ '%s', '%s' ],
            $where_format
        );
    }

    private function assignments_for_booking( $booking_id ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT aa.*, a.inventory_number, a.serial_number, a.known_damage, a.status AS asset_status
                 FROM %i aa INNER JOIN %i a ON a.id = aa.asset_id
                 WHERE aa.booking_id = %d AND aa.status <> 'cancelled'
                 ORDER BY a.inventory_number, a.id",
                $this->assignment_table(),
                $this->asset_table(),
                absint( $booking_id )
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    private function rental_data_for_booking( array $booking, WC_Order $order ) {
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $candidate = $item->get_meta( '_clr_rental_data', true );
            if ( is_array( $candidate ) && (int) ( $candidate['product_id'] ?? 0 ) === (int) ( $booking['product_id'] ?? 0 ) ) {
                return $candidate;
            }
        }
        return [];
    }

    private function fulfilment_label( $mode ) {
        $labels = [
            'pickup'          => __( 'Abholung und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
            'delivery'        => __( 'Lieferung durch Vermieter und Rückgabe durch Kunde', 'patsch9-rental-engine' ),
            'delivery_return' => __( 'Lieferung und Abholung durch Vermieter', 'patsch9-rental-engine' ),
        ];
        $mode = sanitize_key( (string) $mode );
        return $labels[ $mode ] ?? '';
    }

    private function workflow_status_label( $status ) {
        $labels = [
            'reserved'    => __( 'Reserviert', 'patsch9-rental-engine' ),
            'handed_over' => __( 'Übergeben', 'patsch9-rental-engine' ),
            'returned'    => __( 'Zurückgegeben', 'patsch9-rental-engine' ),
        ];
        return $labels[ sanitize_key( $status ) ] ?? sanitize_text_field( $status );
    }

    public function render_admin_tab() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        $notice = isset( $_GET['clr_workflow_notice'] ) ? sanitize_key( wp_unslash( $_GET['clr_workflow_notice'] ) ) : '';
        if ( $notice ) {
            $messages = [
                'handover_saved'   => [ 'success', __( 'Übergabe wurde dokumentiert.', 'patsch9-rental-engine' ) ],
                'return_saved'     => [ 'success', __( 'Rückgabe wurde dokumentiert.', 'patsch9-rental-engine' ) ],
                'checklist'        => [ 'error', __( 'Bitte alle Prüfpunkte vollständig bestätigen bzw. bewerten.', 'patsch9-rental-engine' ) ],
                'checklist_detail' => [ 'error', __( 'Bei „Abweichung / fehlt“ muss eine Beschreibung hinterlegt werden.', 'patsch9-rental-engine' ) ],
                'signature'        => [ 'error', __( 'Die Unterschrift konnte nicht verarbeitet werden.', 'patsch9-rental-engine' ) ],
                'assets'           => [ 'error', __( 'Die Gerätezuordnung konnte nicht abgeschlossen werden.', 'patsch9-rental-engine' ) ],
                'deposit_required' => [ 'error', __( 'Die erforderliche Kaution muss vor Abschluss der Übergabe vollständig erfasst sein.', 'patsch9-rental-engine' ) ],
                'deposit_error'    => [ 'error', __( 'Die Kautionsbuchung konnte nicht abgeschlossen werden. Bitte erneut versuchen oder die Kautionsbox in der Bestellung verwenden.', 'patsch9-rental-engine' ) ],
                'deposit_settlement' => [ 'error', __( 'Bitte die Kautionsabrechnung vollständig ausfüllen. Wird weniger als die hinterlegte Kaution zurückgezahlt, muss angegeben werden, was mit dem Restbetrag geschieht.', 'patsch9-rental-engine' ) ],
                'workflow_state'   => [ 'warning', __( 'Dieser Arbeitsschritt ist für den aktuellen Status nicht verfügbar.', 'patsch9-rental-engine' ) ],
            ];
            if ( isset( $messages[ $notice ] ) ) {
                echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . '"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
            }
        }

        $booking_id = isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;
        $mode       = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
        if ( $booking_id && in_array( $mode, [ 'handover', 'return' ], true ) ) {
            $this->render_workflow_form( $booking_id, $mode );
            return;
        }

        global $wpdb;
        $now = current_time( 'mysql' );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE source = 'order'
                   AND order_id > 0
                   AND status = 'reserved'
                   AND end_at >= %s
                 ORDER BY start_at ASC
                 LIMIT 300",
                $this->booking_table(),
                $now
            ),
            ARRAY_A
        );
        $rows = is_array( $rows ) ? $rows : [];
        ?>
        <div class="clr-v2-admin-card">
            <h2><?php esc_html_e( 'Übergaben & Rückgaben', 'patsch9-rental-engine' ); ?></h2>
            <p><?php esc_html_e( 'Hier wird der operative Mietablauf dokumentiert. Pro Abschluss entsteht ein unveränderliches PDF-Protokoll in der zugehörigen Bestellung.', 'patsch9-rental-engine' ); ?></p>
            <table class="widefat striped clr-workflow-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Termin', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Mietartikel', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Bestellung', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Gerät(e)', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Aktion', 'patsch9-rental-engine' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'Keine anstehenden Vermietungen.', 'patsch9-rental-engine' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $rows as $row ) :
                    $product = wc_get_product( (int) $row['product_id'] );
                    $order   = wc_get_order( (int) $row['order_id'] );
                    $assets  = $this->assignments_for_booking( (int) $row['id'] );
                    $status  = sanitize_key( $row['workflow_status'] ?? 'reserved' );
                    ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $row['start_at'] ) ) . ' – ' . wp_date( 'd.m.Y H:i', strtotime( $row['end_at'] ) ) ); ?></td>
                        <td><?php echo esc_html( $product ? $product->get_name() : '#' . (int) $row['product_id'] ); ?></td>
                        <td><?php if ( $order ) : ?><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a><?php else : ?>–<?php endif; ?></td>
                        <td><?php
                            if ( $assets ) {
                                echo esc_html( implode( ', ', array_map( static fn( $asset ) => (string) $asset['inventory_number'], $assets ) ) );
                            } elseif ( $this->asset_tracking_enabled( (int) $row['product_id'] ) ) {
                                esc_html_e( 'noch nicht zugeordnet', 'patsch9-rental-engine' );
                            } else {
                                esc_html_e( 'keine Einzelgeräteverwaltung', 'patsch9-rental-engine' );
                            }
                        ?></td>
                        <td><?php echo esc_html( $this->workflow_status_label( $status ) ); ?></td>
                        <td>
                            <?php if ( 'reserved' === $status ) : ?>
                                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=workflow&mode=handover&booking_id=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Übergabe', 'patsch9-rental-engine' ); ?></a>
                            <?php elseif ( 'handed_over' === $status ) : ?>
                                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=workflow&mode=return&booking_id=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Rückgabe', 'patsch9-rental-engine' ); ?></a>
                            <?php else : ?>
                                <span aria-hidden="true">✓</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_workflow_form( $booking_id, $mode ) {
        $booking = $this->booking( $booking_id );
        if ( ! $booking || 'order' !== $booking['source'] || ! $booking['order_id'] ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Die Buchung wurde nicht gefunden oder ist für diesen Workflow nicht geeignet.', 'patsch9-rental-engine' ) . '</p></div>';
            return;
        }
        $order = wc_get_order( (int) $booking['order_id'] );
        $product = wc_get_product( (int) $booking['product_id'] );
        if ( ! $order || ! $product ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Bestellung oder Mietprodukt wurde nicht gefunden.', 'patsch9-rental-engine' ) . '</p></div>';
            return;
        }

        $expected = 'handover' === $mode ? 'reserved' : 'handed_over';
        if ( sanitize_key( $booking['workflow_status'] ?? 'reserved' ) !== $expected ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Dieser Arbeitsschritt ist für den aktuellen Status nicht verfügbar.', 'patsch9-rental-engine' ) . '</p></div>';
            return;
        }

        if ( $this->asset_tracking_enabled( (int) $booking['product_id'] ) ) {
            $ensured = $this->ensure_booking_assignments( $booking );
            if ( is_wp_error( $ensured ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $ensured->get_error_message() ) . '</p></div>';
                return;
            }
        }

        $assets = $this->assignments_for_booking( $booking_id );
        $checklist = $this->checklist( (int) $booking['product_id'], $mode );
        $signature_enabled = 'yes' === get_post_meta( (int) $booking['product_id'], '_clr_workflow_signature_enabled', true );
        $action = 'handover' === $mode ? 'rmwc_workflow_handover' : 'rmwc_workflow_return';
        $title  = 'handover' === $mode ? __( 'Übergabe abschließen', 'patsch9-rental-engine' ) : __( 'Rückgabe abschließen', 'patsch9-rental-engine' );
        $deposit_state = RMWC_V2_Deposits::instance()->state( $order );
        $rental_data   = $this->rental_data_for_booking( $booking, $order );
        $fulfilment    = sanitize_key( $rental_data['fulfilment'] ?? '' );
        ?>
        <div class="clr-v2-admin-card clr-workflow-form-card">
            <header class="clr-workflow-form-head">
                <a class="clr-workflow-back" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=workflow' ) ); ?>">← <?php esc_html_e( 'Zur Übersicht', 'patsch9-rental-engine' ); ?></a>
                <div>
                    <h2><?php echo esc_html( $title ); ?></h2>
                    <p><?php echo esc_html( 'handover' === $mode ? __( 'Gerät und Zubehör gemeinsam mit dem Kunden prüfen und den Zustand dokumentieren.', 'patsch9-rental-engine' ) : __( 'Rückgabe prüfen, Zustand dokumentieren und das Gerät anschließend wieder freigeben oder in Prüfung geben.', 'patsch9-rental-engine' ) ); ?></p>
                </div>
            </header>
            <div class="clr-workflow-summary">
                <div class="clr-workflow-summary-item"><span><?php esc_html_e( 'Mietartikel', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( $product->get_name() ); ?></strong></div>
                <div class="clr-workflow-summary-item"><span><?php esc_html_e( 'Bestellung / Kunde', 'patsch9-rental-engine' ); ?></span><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong><small><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></small></div>
                <div class="clr-workflow-summary-item"><span><?php esc_html_e( 'Zeitraum', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $booking['start_at'] ) ) . ' – ' . wp_date( 'd.m.Y H:i', strtotime( $booking['end_at'] ) ) ); ?></strong></div>
                <?php if ( $fulfilment ) : ?>
                    <div class="clr-workflow-summary-item"><span><?php esc_html_e( 'Transport / Übergabe', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( $this->fulfilment_label( $fulfilment ) ); ?></strong><?php if ( in_array( $fulfilment, [ 'delivery', 'delivery_return' ], true ) && ! empty( $rental_data['delivery_address'] ) ) : ?><small><?php echo esc_html( $rental_data['delivery_address'] ); ?></small><?php endif; ?></div>
                <?php endif; ?>
                <?php if ( $assets ) : ?><div class="clr-workflow-summary-item"><span><?php esc_html_e( 'Zugeordnete Geräte', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( implode( ', ', array_map( static fn( $asset ) => (string) $asset['inventory_number'], $assets ) ) ); ?></strong></div><?php endif; ?>
            </div>

            <?php
            $assets_with_damage = array_values(
                array_filter(
                    $assets,
                    static fn( $asset ) => '' !== trim( (string) ( $asset['known_damage'] ?? '' ) )
                )
            );
            ?>
            <?php if ( $assets_with_damage ) : ?>
                <section class="clr-workflow-known-damage" aria-label="<?php esc_attr_e( 'Bekannte Vorschäden', 'patsch9-rental-engine' ); ?>">
                    <div class="clr-workflow-known-damage-title"><?php esc_html_e( 'Bekannte Vorschäden / Gebrauchsspuren', 'patsch9-rental-engine' ); ?></div>
                    <p><?php esc_html_e( 'Diese Angaben stammen aus dem Inventarstamm und werden automatisch in das Protokoll übernommen. Prüfe bei der Übergabe, ob sie weiterhin zutreffen.', 'patsch9-rental-engine' ); ?></p>
                    <ul>
                        <?php foreach ( $assets_with_damage as $asset ) : ?>
                            <li><strong><?php echo esc_html( $asset['inventory_number'] ?? '' ); ?>:</strong> <?php echo esc_html( $asset['known_damage'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clr-workflow-form">
                <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
                <input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking_id ); ?>">
                <?php wp_nonce_field( $action . '_' . $booking_id, 'clr_workflow_nonce' ); ?>

                <?php if ( $checklist ) : ?>
                    <fieldset class="clr-workflow-checklist clr-workflow-form-section <?php echo 'return' === $mode ? 'clr-workflow-return-checklist' : ''; ?>">
                        <legend><?php esc_html_e( 'Prüfpunkte', 'patsch9-rental-engine' ); ?></legend>
                        <?php if ( 'return' === $mode ) : ?>
                            <p class="description clr-workflow-checklist-help"><?php esc_html_e( 'Jeder Prüfpunkt muss bewertet werden. Bei „Abweichung / fehlt“ ist eine kurze Beschreibung verpflichtend und wird im Rückgabeprotokoll gespeichert.', 'patsch9-rental-engine' ); ?></p>
                            <?php foreach ( $checklist as $index => $item ) : ?>
                                <div class="clr-workflow-return-check-row" data-check-index="<?php echo esc_attr( $index ); ?>">
                                    <div class="clr-workflow-return-check-label"><strong><?php echo esc_html( $item ); ?></strong></div>
                                    <div class="clr-workflow-return-check-options" role="radiogroup" aria-label="<?php echo esc_attr( $item ); ?>">
                                        <label><input type="radio" name="checklist_status[<?php echo esc_attr( $index ); ?>]" value="ok" required> <span><?php esc_html_e( 'In Ordnung', 'patsch9-rental-engine' ); ?></span></label>
                                        <label><input type="radio" name="checklist_status[<?php echo esc_attr( $index ); ?>]" value="issue" required> <span><?php esc_html_e( 'Abweichung / fehlt', 'patsch9-rental-engine' ); ?></span></label>
                                        <label><input type="radio" name="checklist_status[<?php echo esc_attr( $index ); ?>]" value="na" required> <span><?php esc_html_e( 'Nicht zutreffend', 'patsch9-rental-engine' ); ?></span></label>
                                    </div>
                                    <label class="clr-workflow-return-check-note">
                                        <span><?php esc_html_e( 'Dokumentation / Bemerkung', 'patsch9-rental-engine' ); ?></span>
                                        <textarea name="checklist_note[<?php echo esc_attr( $index ); ?>]" rows="2" maxlength="700" placeholder="<?php esc_attr_e( 'Bei Abweichung z. B. „Messbecher fehlt“ oder „stark verschmutzt“', 'patsch9-rental-engine' ); ?>"></textarea>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <?php foreach ( $checklist as $index => $item ) : ?>
                                <label class="clr-workflow-check-row"><input type="checkbox" name="checklist[]" value="<?php echo esc_attr( $index ); ?>" required> <span><?php echo esc_html( $item ); ?></span></label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ( (float) ( $deposit_state['required'] ?? 0 ) > 0 ) : ?>
                    <section class="clr-workflow-deposit clr-workflow-form-section">
                        <div class="clr-workflow-section-head">
                            <div>
                                <h3><?php esc_html_e( 'Kaution', 'patsch9-rental-engine' ); ?></h3>
                                <p><?php esc_html_e( 'Der Kautionsstand wird zusammen mit diesem Vorgang im Protokoll dokumentiert.', 'patsch9-rental-engine' ); ?></p>
                            </div>
                        </div>
                        <div class="clr-workflow-deposit-grid">
                            <div><span><?php esc_html_e( 'Erforderlich', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['required'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Erhalten', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['received'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Zurückgezahlt', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['refunded'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Aktuell hinterlegt', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['available'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                        </div>

                        <?php if ( 'handover' === $mode && (float) ( $deposit_state['missing'] ?? 0 ) > 0 ) : ?>
                            <div class="notice notice-warning inline clr-workflow-deposit-warning">
                                <p><?php
                                    printf(
                                        /* translators: %s: still missing refundable deposit. */
                                        esc_html__( 'Vor der Übergabe sind noch %s Kaution offen. Der offene Betrag muss jetzt erfasst werden.', 'patsch9-rental-engine' ),
                                        esc_html( wp_strip_all_tags( wc_price( $deposit_state['missing'], [ 'currency' => $order->get_currency() ] ) ) )
                                    );
                                ?></p>
                            </div>
                            <label class="clr-workflow-check-row clr-workflow-deposit-toggle">
                                <input type="checkbox" name="clr_workflow_deposit_receive" value="1" required>
                                <span><strong><?php esc_html_e( 'Kaution vollständig erhalten', 'patsch9-rental-engine' ); ?></strong><small><?php esc_html_e( 'Mit dieser Bestätigung wird der offene Betrag als erhalten gebucht und eine Kautionsquittung erzeugt.', 'patsch9-rental-engine' ); ?></small></span>
                            </label>
                            <p class="clr-workflow-field">
                                <label><strong><?php esc_html_e( 'Kaution erhalten als', 'patsch9-rental-engine' ); ?></strong><br>
                                    <select name="clr_workflow_deposit_method" required>
                                        <option value="cash"><?php esc_html_e( 'Bar bei Übergabe', 'patsch9-rental-engine' ); ?></option>
                                        <option value="transfer"><?php esc_html_e( 'Separate Überweisung bereits eingegangen', 'patsch9-rental-engine' ); ?></option>
                                        <option value="other"><?php esc_html_e( 'Sonstige Hinterlegung', 'patsch9-rental-engine' ); ?></option>
                                    </select>
                                </label>
                            </p>
                        <?php elseif ( 'handover' === $mode ) : ?>
                            <p class="clr-workflow-deposit-ok">✓ <?php esc_html_e( 'Die erforderliche Kaution ist vollständig erfasst.', 'patsch9-rental-engine' ); ?></p>
                        <?php endif; ?>

                        <?php if ( 'return' === $mode && (float) ( $deposit_state['available'] ?? 0 ) > 0 ) : ?>
                            <?php if ( (float) ( $deposit_state['online_available'] ?? 0 ) > 0 ) : ?>
                                <div class="notice notice-info inline">
                                    <p><?php esc_html_e( 'Ein Teil der Kaution wurde online hinterlegt. Eine automatische Rückzahlung auf die ursprüngliche Zahlungsart wird weiterhin sicher über die Kautionsbox in der Bestellung ausgelöst. Trage hier nur den Betrag ein, der bei dieser Rückgabe tatsächlich bereits zurückgezahlt wurde.', 'patsch9-rental-engine' ); ?></p>
                                    <p><a class="button" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'Kautionsbox in Bestellung öffnen', 'patsch9-rental-engine' ); ?></a></p>
                                </div>
                            <?php endif; ?>
                            <div class="clr-workflow-deposit-settlement" data-available="<?php echo esc_attr( wc_format_decimal( $deposit_state['available'] ) ); ?>">
                                <h4><?php esc_html_e( 'Kautionsabrechnung bei Rückgabe', 'patsch9-rental-engine' ); ?></h4>
                                <p class="description"><?php esc_html_e( 'Gib an, wie viel Kaution dem Kunden bei dieser Rückgabe tatsächlich zurückgezahlt wurde. Ist der Betrag geringer als die aktuell hinterlegte Kaution, muss der Grund für den Restbetrag dokumentiert werden.', 'patsch9-rental-engine' ); ?></p>
                                <p class="clr-workflow-field"><label><strong><?php esc_html_e( 'Bei dieser Rückgabe zurückgezahlt', 'patsch9-rental-engine' ); ?></strong><br>
                                    <input type="number" name="clr_workflow_deposit_refund_amount" step="0.01" min="0" max="<?php echo esc_attr( wc_format_decimal( $deposit_state['available'] ) ); ?>" value="<?php echo esc_attr( wc_format_decimal( $deposit_state['available'] ) ); ?>" required>
                                </label></p>
                                <p class="clr-workflow-deposit-retained-preview"><strong><?php esc_html_e( 'Verbleibender Betrag:', 'patsch9-rental-engine' ); ?></strong> <span><?php echo wp_kses_post( wc_price( 0, [ 'currency' => $order->get_currency() ] ) ); ?></span></p>
                                <div class="clr-workflow-deposit-retention-fields">
                                    <p class="clr-workflow-field"><label><strong><?php esc_html_e( 'Grund für den verbleibenden Betrag', 'patsch9-rental-engine' ); ?></strong><br>
                                        <select name="clr_workflow_deposit_retention_reason">
                                            <option value="none"><?php esc_html_e( 'Kein Restbetrag', 'patsch9-rental-engine' ); ?></option>
                                            <option value="pending"><?php esc_html_e( 'Rückzahlung des Restbetrags erfolgt später / separat', 'patsch9-rental-engine' ); ?></option>
                                            <option value="damage"><?php esc_html_e( 'Einbehalt wegen Beschädigung', 'patsch9-rental-engine' ); ?></option>
                                            <option value="cleaning"><?php esc_html_e( 'Einbehalt wegen Reinigung / Verschmutzung', 'patsch9-rental-engine' ); ?></option>
                                            <option value="loss"><?php esc_html_e( 'Einbehalt wegen Verlust / Nichtrückgabe', 'patsch9-rental-engine' ); ?></option>
                                            <option value="other"><?php esc_html_e( 'Sonstiger Einbehalt', 'patsch9-rental-engine' ); ?></option>
                                        </select>
                                    </label></p>
                                    <p class="clr-workflow-field"><label><strong><?php esc_html_e( 'Begründung / Details', 'patsch9-rental-engine' ); ?></strong><br>
                                        <textarea name="clr_workflow_deposit_retention_note" rows="3" maxlength="700" placeholder="<?php esc_attr_e( 'Optional ergänzen, z. B. „Reinigungspauschale wegen starker Verschmutzung“ oder „fehlender Messbecher“', 'patsch9-rental-engine' ); ?>"></textarea>
                                    </label></p>
                                </div>
                            </div>
                        <?php elseif ( 'return' === $mode ) : ?>
                            <p class="clr-workflow-deposit-ok">✓ <?php esc_html_e( 'Aktuell ist keine Kaution mehr zur Rückzahlung hinterlegt.', 'patsch9-rental-engine' ); ?></p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ( 'return' === $mode && $assets ) : ?>
                    <p class="clr-workflow-field">
                        <label><strong><?php esc_html_e( 'Gerätestatus nach Rückgabe', 'patsch9-rental-engine' ); ?></strong><br>
                            <select name="asset_status">
                                <option value="available"><?php esc_html_e( 'Verfügbar', 'patsch9-rental-engine' ); ?></option>
                                <option value="cleaning"><?php esc_html_e( 'Reinigung / Prüfung', 'patsch9-rental-engine' ); ?></option>
                                <option value="maintenance"><?php esc_html_e( 'Wartung', 'patsch9-rental-engine' ); ?></option>
                                <option value="defect"><?php esc_html_e( 'Defekt', 'patsch9-rental-engine' ); ?></option>
                            </select>
                        </label>
                    </p>
                <?php endif; ?>

                <p class="clr-workflow-field"><label><strong><?php esc_html_e( 'Notiz / neu festgestellte Auffälligkeiten', 'patsch9-rental-engine' ); ?></strong><br><textarea name="notes" rows="5" maxlength="3000" placeholder="<?php esc_attr_e( 'Optional: neue Schäden, fehlendes Zubehör oder sonstige Hinweise dokumentieren …', 'patsch9-rental-engine' ); ?>"></textarea></label></p>

                <?php if ( $signature_enabled ) : ?>
                    <div class="clr-signature-wrap">
                        <p><strong><?php esc_html_e( 'Optionale Dokumentationsunterschrift', 'patsch9-rental-engine' ); ?></strong></p>
                        <canvas class="clr-signature-pad" width="700" height="220" aria-label="<?php esc_attr_e( 'Unterschriftsfeld', 'patsch9-rental-engine' ); ?>"></canvas>
                        <input type="hidden" name="signature_data" class="clr-signature-data" value="">
                        <p><button type="button" class="button clr-signature-clear"><?php esc_html_e( 'Unterschrift löschen', 'patsch9-rental-engine' ); ?></button></p>
                        <p class="description"><?php esc_html_e( 'Die Unterschrift dient der Dokumentation des Übergabe-/Rückgabevorgangs und ist keine qualifizierte elektronische Signatur.', 'patsch9-rental-engine' ); ?></p>
                    </div>
                <?php endif; ?>

                <div class="clr-workflow-submit-row"><?php submit_button( $title, 'primary', 'submit', false ); ?></div>
            </form>
        </div>
        <?php
    }

    private function validate_action_request( $action ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $booking_id = isset( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0;
        $nonce = isset( $_POST['clr_workflow_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_workflow_nonce'] ) ) : '';
        if ( ! $booking_id || ! $nonce || ! wp_verify_nonce( $nonce, $action . '_' . $booking_id ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $booking = $this->booking( $booking_id );
        if ( ! $booking || 'order' !== $booking['source'] || ! $booking['order_id'] ) {
            wp_die( esc_html__( 'Buchung nicht gefunden.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }
        $order = wc_get_order( (int) $booking['order_id'] );
        if ( ! $order ) {
            wp_die( esc_html__( 'Bestellung nicht gefunden.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }
        return [ $booking, $order ];
    }

    private function validated_checklist_snapshot( array $booking, $type ) {
        $configured = $this->checklist( (int) $booking['product_id'], $type );

        if ( 'return' === $type ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are validated against a fixed allowlist and mapped to the server-side checklist below.
            $raw_status = isset( $_POST['checklist_status'] ) && is_array( $_POST['checklist_status'] ) ? wp_unslash( $_POST['checklist_status'] ) : [];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Notes are individually sanitized and length-limited below.
            $raw_notes = isset( $_POST['checklist_note'] ) && is_array( $_POST['checklist_note'] ) ? wp_unslash( $_POST['checklist_note'] ) : [];
            $allowed = [ 'ok', 'issue', 'na' ];
            $snapshot = [];

            foreach ( $configured as $index => $label ) {
                $status = isset( $raw_status[ $index ] ) ? sanitize_key( $raw_status[ $index ] ) : '';
                if ( ! in_array( $status, $allowed, true ) ) {
                    return new WP_Error( 'clr_workflow_checklist', __( 'Bitte jeden Prüfpunkt bewerten.', 'patsch9-rental-engine' ) );
                }
                $note = isset( $raw_notes[ $index ] ) ? sanitize_textarea_field( $raw_notes[ $index ] ) : '';
                $note = trim( function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 700 ) : substr( $note, 0, 700 ) );
                if ( 'issue' === $status && '' === $note ) {
                    return new WP_Error( 'clr_workflow_checklist_detail', __( 'Bei „Abweichung / fehlt“ muss eine Beschreibung hinterlegt werden.', 'patsch9-rental-engine' ) );
                }
                $snapshot[] = [
                    'label'  => sanitize_text_field( $label ),
                    'status' => $status,
                    'note'   => $note,
                ];
            }
            return $snapshot;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only numeric indexes are accepted and mapped to the server-side configured checklist.
        $raw = isset( $_POST['checklist'] ) && is_array( $_POST['checklist'] ) ? wp_unslash( $_POST['checklist'] ) : [];
        $selected = array_values( array_unique( array_map( 'absint', $raw ) ) );
        if ( $configured && count( $selected ) < count( $configured ) ) {
            return new WP_Error( 'clr_workflow_checklist', __( 'Bitte alle Prüfpunkte bestätigen.', 'patsch9-rental-engine' ) );
        }
        foreach ( array_keys( $configured ) as $index ) {
            if ( ! in_array( $index, $selected, true ) ) {
                return new WP_Error( 'clr_workflow_checklist', __( 'Bitte alle Prüfpunkte bestätigen.', 'patsch9-rental-engine' ) );
            }
        }
        return $configured;
    }

    private function signature_from_request() {
        $raw = isset( $_POST['signature_data'] ) ? trim( (string) wp_unslash( $_POST['signature_data'] ) ) : '';
        if ( '' === $raw ) {
            return '';
        }
        if ( ! preg_match( '#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $raw, $matches ) ) {
            return new WP_Error( 'clr_signature_format', __( 'Die Unterschrift konnte nicht verarbeitet werden.', 'patsch9-rental-engine' ) );
        }
        $bytes = base64_decode( $matches[1], true );
        if ( false === $bytes || strlen( $bytes ) > 220000 ) {
            return new WP_Error( 'clr_signature_size', __( 'Die Unterschrift ist zu groß oder ungültig.', 'patsch9-rental-engine' ) );
        }
        $info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data is handled explicitly.
        if ( ! is_array( $info ) || IMAGETYPE_JPEG !== ( $info[2] ?? 0 ) || ( $info[0] ?? 0 ) > 1400 || ( $info[1] ?? 0 ) > 500 ) {
            return new WP_Error( 'clr_signature_image', __( 'Die Unterschrift ist keine gültige Bilddatei.', 'patsch9-rental-engine' ) );
        }
        return 'data:image/jpeg;base64,' . base64_encode( $bytes );
    }

    private function workflow_snapshot( array $booking, WC_Order $order, $type, array $checklist, array $assets, $signature, $notes ) {
        $product = wc_get_product( (int) $booking['product_id'] );
        $rental_data = $this->rental_data_for_booking( $booking, $order );
        $deposit_state = RMWC_V2_Deposits::instance()->state( $order );
        return [
            'booking_id'      => absint( $booking['id'] ),
            'order_id'        => $order->get_id(),
            'order_number'    => sanitize_text_field( $order->get_order_number() ),
            'product_id'      => absint( $booking['product_id'] ),
            'product_name'    => sanitize_text_field( $product ? $product->get_name() : '#' . (int) $booking['product_id'] ),
            'customer_name'   => sanitize_text_field( $order->get_formatted_billing_full_name() ),
            'customer_email'  => sanitize_email( $order->get_billing_email() ),
            'start_at'        => sanitize_text_field( $booking['start_at'] ),
            'end_at'          => sanitize_text_field( $booking['end_at'] ),
            'event_at'        => current_time( 'mysql', true ),
            'event_type'      => sanitize_key( $type ),
            'fulfilment'      => sanitize_key( $rental_data['fulfilment'] ?? '' ),
            'delivery_address'=> sanitize_text_field( $rental_data['delivery_address'] ?? '' ),
            'deposit'         => [
                'required'  => max( 0, (float) ( $deposit_state['required'] ?? 0 ) ),
                'received'  => max( 0, (float) ( $deposit_state['received'] ?? 0 ) ),
                'refunded'  => max( 0, (float) ( $deposit_state['refunded'] ?? 0 ) ),
                'retained'  => max( 0, (float) ( $deposit_state['retained'] ?? 0 ) ),
                'available' => max( 0, (float) ( $deposit_state['available'] ?? 0 ) ),
                'missing'   => max( 0, (float) ( $deposit_state['missing'] ?? 0 ) ),
            ],
            'checklist'       => array_values( array_map( static function( $item ) {
                if ( is_array( $item ) ) {
                    $status = sanitize_key( $item['status'] ?? '' );
                    return [
                        'label'  => sanitize_text_field( $item['label'] ?? '' ),
                        'status' => in_array( $status, [ 'ok', 'issue', 'na' ], true ) ? $status : '',
                        'note'   => sanitize_textarea_field( $item['note'] ?? '' ),
                    ];
                }
                return sanitize_text_field( $item );
            }, $checklist ) ),
            'assets'          => array_map(
                static function( $asset ) {
                    return [
                        'asset_id'          => absint( $asset['asset_id'] ?? 0 ),
                        'inventory_number'  => sanitize_text_field( $asset['inventory_number'] ?? '' ),
                        'serial_number'     => sanitize_text_field( $asset['serial_number'] ?? '' ),
                        'known_damage'      => sanitize_textarea_field( $asset['known_damage'] ?? '' ),
                    ];
                },
                $assets
            ),
            'notes'           => sanitize_textarea_field( $notes ),
            'staff_user_id'   => get_current_user_id(),
            'staff_name'      => sanitize_text_field( wp_get_current_user()->display_name ),
            'signature_jpeg'  => is_string( $signature ) ? $signature : '',
        ];
    }

    public function handle_handover() {
        [ $booking, $order ] = $this->validate_action_request( 'rmwc_workflow_handover' );
        if ( 'reserved' !== sanitize_key( $booking['workflow_status'] ?? 'reserved' ) ) {
            $this->redirect( 'workflow_state' );
        }
        $checklist = $this->validated_checklist_snapshot( $booking, 'handover' );
        if ( is_wp_error( $checklist ) ) {
            $this->redirect( 'checklist', (int) $booking['id'], 'handover' );
        }
        $signature = $this->signature_from_request();
        if ( is_wp_error( $signature ) ) {
            $this->redirect( 'signature', (int) $booking['id'], 'handover' );
        }

        $deposit_module = RMWC_V2_Deposits::instance();
        $deposit_state  = $deposit_module->state( $order );
        if ( (float) ( $deposit_state['missing'] ?? 0 ) > 0 ) {
            if ( empty( $_POST['clr_workflow_deposit_receive'] ) ) {
                $this->redirect( 'deposit_required', (int) $booking['id'], 'handover' );
            }
            $method = isset( $_POST['clr_workflow_deposit_method'] ) ? sanitize_key( wp_unslash( $_POST['clr_workflow_deposit_method'] ) ) : 'cash';
            $receipt = $deposit_module->workflow_receive( $order, (float) $deposit_state['missing'], $method );
            if ( is_wp_error( $receipt ) ) {
                $this->redirect( 'deposit_error', (int) $booking['id'], 'handover' );
            }
            $deposit_state = $deposit_module->state( $order );
            if ( (float) ( $deposit_state['missing'] ?? 0 ) > 0.0001 ) {
                $this->redirect( 'deposit_required', (int) $booking['id'], 'handover' );
            }
        }

        if ( $this->asset_tracking_enabled( (int) $booking['product_id'] ) ) {
            $assigned = $this->ensure_booking_assignments( $booking );
            if ( is_wp_error( $assigned ) ) {
                $this->redirect( 'assets', (int) $booking['id'], 'handover' );
            }
        }
        $assets = $this->assignments_for_booking( (int) $booking['id'] );
        $notes = isset( $_POST['notes'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ), 0, 3000 ) : '';
        $snapshot = $this->workflow_snapshot( $booking, $order, 'handover', $checklist, $assets, $signature, $notes );

        global $wpdb;
        $now = current_time( 'mysql', true );
        $transitioned = $wpdb->update(
            $this->booking_table(),
            [ 'workflow_status' => 'handed_over', 'handover_at' => $now ],
            [ 'id' => (int) $booking['id'], 'workflow_status' => 'reserved' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( 1 !== (int) $transitioned ) {
            // Compare-and-set prevents two simultaneous submissions from
            // creating duplicate protocol documents, emails and order notes.
            $this->redirect( 'workflow_state', (int) $booking['id'], 'handover' );
        }
        $wpdb->update(
            $this->assignment_table(),
            [ 'status' => 'handed_over', 'updated_at' => $now ],
            [ 'booking_id' => (int) $booking['id'], 'status' => 'reserved' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
        foreach ( $assets as $asset ) {
            $wpdb->update( $this->asset_table(), [ 'status' => 'rented', 'updated_at' => $now ], [ 'id' => absint( $asset['asset_id'] ) ], [ '%s', '%s' ], [ '%d' ] );
        }

        $documents = RMWC_V2_Documents::instance();
        $document_id = $documents->create_document( $order, 'handover_protocol', $snapshot, 0, 'handover_b' . (int) $booking['id'] );
        if ( ! is_wp_error( $document_id ) ) {
            $documents->send_document_email(
                $order,
                (int) $document_id,
                __( 'Übergabeprotokoll zu Ihrer Miete', 'patsch9-rental-engine' ),
                __( 'Übergabeprotokoll', 'patsch9-rental-engine' ),
                __( 'Im Anhang erhalten Sie das Protokoll zur Übergabe Ihres Mietartikels.', 'patsch9-rental-engine' )
            );
        }
        $order->add_order_note( sprintf( 'Vermietung: Übergabe für Buchung #%d dokumentiert.', (int) $booking['id'] ) );
        do_action( 'rmwc_workflow_status_changed', $order, (int) $booking['id'], 'handed_over' );
        $this->redirect( 'handover_saved' );
    }

    public function handle_return() {
        [ $booking, $order ] = $this->validate_action_request( 'rmwc_workflow_return' );
        if ( 'handed_over' !== sanitize_key( $booking['workflow_status'] ?? 'reserved' ) ) {
            $this->redirect( 'workflow_state' );
        }
        $checklist = $this->validated_checklist_snapshot( $booking, 'return' );
        if ( is_wp_error( $checklist ) ) {
            $notice = 'clr_workflow_checklist_detail' === $checklist->get_error_code() ? 'checklist_detail' : 'checklist';
            $this->redirect( $notice, (int) $booking['id'], 'return' );
        }
        $signature = $this->signature_from_request();
        if ( is_wp_error( $signature ) ) {
            $this->redirect( 'signature', (int) $booking['id'], 'return' );
        }

        $deposit_module = RMWC_V2_Deposits::instance();
        $deposit_state  = $deposit_module->state( $order );
        if ( (float) ( $deposit_state['available'] ?? 0 ) > 0 ) {
            if ( ! isset( $_POST['clr_workflow_deposit_refund_amount'] ) ) {
                $this->redirect( 'deposit_settlement', (int) $booking['id'], 'return' );
            }
            $amount = max( 0, (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['clr_workflow_deposit_refund_amount'] ) ) ) );
            $amount = min( (float) $deposit_state['available'], $amount );
            $reason = isset( $_POST['clr_workflow_deposit_retention_reason'] ) ? sanitize_key( wp_unslash( $_POST['clr_workflow_deposit_retention_reason'] ) ) : 'none';
            $retention_note = isset( $_POST['clr_workflow_deposit_retention_note'] )
                ? substr( sanitize_textarea_field( wp_unslash( $_POST['clr_workflow_deposit_retention_note'] ) ), 0, 700 )
                : '';
            $remaining = max( 0, (float) $deposit_state['available'] - $amount );
            if ( $remaining > 0.0001 && ! in_array( $reason, [ 'pending', 'damage', 'cleaning', 'loss', 'other' ], true ) ) {
                $this->redirect( 'deposit_settlement', (int) $booking['id'], 'return' );
            }
            $refund = $deposit_module->workflow_manual_refund( $order, $amount, $reason, $retention_note );
            if ( is_wp_error( $refund ) ) {
                $this->redirect( 'deposit_error', (int) $booking['id'], 'return' );
            }
        }

        $assets = $this->assignments_for_booking( (int) $booking['id'] );
        $allowed_statuses = [ 'available', 'cleaning', 'maintenance', 'defect' ];
        $asset_status = isset( $_POST['asset_status'] ) ? sanitize_key( wp_unslash( $_POST['asset_status'] ) ) : 'available';
        if ( ! in_array( $asset_status, $allowed_statuses, true ) ) {
            $asset_status = 'available';
        }
        $notes = isset( $_POST['notes'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ), 0, 3000 ) : '';
        $snapshot = $this->workflow_snapshot( $booking, $order, 'return', $checklist, $assets, $signature, $notes );
        $snapshot['asset_status_after_return'] = $asset_status;

        global $wpdb;
        $now = current_time( 'mysql', true );
        $transitioned = $wpdb->update(
            $this->booking_table(),
            [ 'workflow_status' => 'returned', 'return_at' => $now ],
            [ 'id' => (int) $booking['id'], 'workflow_status' => 'handed_over' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( 1 !== (int) $transitioned ) {
            // Compare-and-set prevents duplicate return documents and side
            // effects when a mobile browser resubmits the same form.
            $this->redirect( 'workflow_state', (int) $booking['id'], 'return' );
        }
        $wpdb->update(
            $this->assignment_table(),
            [ 'status' => 'returned', 'updated_at' => $now ],
            [ 'booking_id' => (int) $booking['id'] ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        foreach ( $assets as $asset ) {
            $wpdb->update( $this->asset_table(), [ 'status' => $asset_status, 'updated_at' => $now ], [ 'id' => absint( $asset['asset_id'] ) ], [ '%s', '%s' ], [ '%d' ] );
        }

        $documents = RMWC_V2_Documents::instance();
        $document_id = $documents->create_document( $order, 'return_protocol', $snapshot, 0, 'return_b' . (int) $booking['id'] );
        if ( ! is_wp_error( $document_id ) ) {
            $documents->send_document_email(
                $order,
                (int) $document_id,
                __( 'Rückgabeprotokoll zu Ihrer Miete', 'patsch9-rental-engine' ),
                __( 'Rückgabeprotokoll', 'patsch9-rental-engine' ),
                __( 'Im Anhang erhalten Sie das Protokoll zur Rückgabe Ihres Mietartikels.', 'patsch9-rental-engine' )
            );
        }
        $order->add_order_note( sprintf( 'Vermietung: Rückgabe für Buchung #%d dokumentiert.', (int) $booking['id'] ) );
        $this->complete_order_when_all_returns_finished( $order );
        do_action( 'rmwc_workflow_status_changed', $order, (int) $booking['id'], 'returned' );
        $this->redirect( 'return_saved' );
    }

    private function complete_order_when_all_returns_finished( WC_Order $order ) {
        global $wpdb;
        $open = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE source = 'order' AND order_id = %d AND status <> 'cancelled' AND workflow_status <> 'returned'",
                $this->booking_table(),
                $order->get_id()
            )
        );
        if ( $open > 0 ) {
            return false;
        }

        if ( ! in_array( $order->get_status(), [ 'completed', 'cancelled', 'refunded', 'failed' ], true ) ) {
            $order->update_status(
                'completed',
                __( 'Vermietung: Alle Mietartikel wurden zurückgegeben. Bestellung automatisch abgeschlossen.', 'patsch9-rental-engine' )
            );
        }
        return 'completed' === $order->get_status();
    }

    private function redirect( $notice, $booking_id = 0, $mode = '' ) {
        $args = [ 'page' => 'clr-rentals', 'tab' => 'workflow', 'clr_workflow_notice' => sanitize_key( $notice ) ];
        if ( $booking_id ) {
            $args['booking_id'] = absint( $booking_id );
        }
        if ( $mode ) {
            $args['mode'] = sanitize_key( $mode );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function add_order_meta_box() {
        $screens = [ 'shop_order' ];
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box( 'rmwc_workflow_box', __( 'Mietablauf', 'patsch9-rental-engine' ), [ $this, 'render_order_meta_box' ], $screen, 'side', 'default' );
        }
    }

    public function render_order_meta_box( $object ) {
        $order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
        if ( ! $order ) {
            return;
        }
        global $wpdb;
        $bookings = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY start_at, id', $this->booking_table(), $order->get_id() ),
            ARRAY_A
        );
        if ( ! $bookings ) {
            echo '<p>' . esc_html__( 'Keine Mietbuchung in dieser Bestellung.', 'patsch9-rental-engine' ) . '</p>';
            return;
        }
        foreach ( $bookings as $booking ) {
            $product = wc_get_product( (int) $booking['product_id'] );
            $assets  = $this->assignments_for_booking( (int) $booking['id'] );
            $status  = sanitize_key( $booking['workflow_status'] ?? 'reserved' );
            echo '<div class="clr-order-workflow-item">';
            echo '<p><strong>' . esc_html( $product ? $product->get_name() : '#' . (int) $booking['product_id'] ) . '</strong><br>';
            echo esc_html( $this->workflow_status_label( $status ) );
            if ( $assets ) {
                echo '<br>' . esc_html( implode( ', ', array_map( static fn( $asset ) => (string) $asset['inventory_number'], $assets ) ) );
            }
            echo '</p>';
            if ( 'reserved' === $status ) {
                echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=clr-rentals&tab=workflow&mode=handover&booking_id=' . (int) $booking['id'] ) ) . '">' . esc_html__( 'Übergabe öffnen', 'patsch9-rental-engine' ) . '</a></p>';
            } elseif ( 'handed_over' === $status ) {
                echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=clr-rentals&tab=workflow&mode=return&booking_id=' . (int) $booking['id'] ) ) . '">' . esc_html__( 'Rückgabe öffnen', 'patsch9-rental-engine' ) . '</a></p>';
            }
            echo '</div>';
        }
    }
}
