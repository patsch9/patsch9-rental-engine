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
        return $wpdb->prefix . 'rmwc_bookings';
    }

    private function asset_table() {
        global $wpdb;
        return $wpdb->prefix . 'rmwc_assets';
    }

    private function assignment_table() {
        global $wpdb;
        return $wpdb->prefix . 'rmwc_asset_assignments';
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
        $key = 'handover' === $type ? '_rmwc_handover_checklist' : '_rmwc_return_checklist';
        return $this->clean_checklist( get_post_meta( absint( $product_id ), $key, true ) );
    }

    public function product_fields( $product_id ) {
        $handover = $this->checklist( $product_id, 'handover' );
        $return   = $this->checklist( $product_id, 'return' );
        $signature = 'yes' === get_post_meta( $product_id, '_rmwc_workflow_signature_enabled', true );
        ?>
        <div class="options_group rmwc-workflow-config">
            <div class="rmwc-product-section-head">
                <h4><?php esc_html_e( 'Transfer & Return', 'patsch9-rental-engine' ); ?></h4>
                <p class="description"><?php esc_html_e( 'Define product-specific checkpoints. When a transfer or return is completed, the confirmed points are stored in the respective PDF protocol invariably.', 'patsch9-rental-engine' ); ?></p>
            </div>
            <div class="rmwc-checklist-config-grid">
                <section class="rmwc-checklist-config-card">
                    <div class="rmwc-checklist-config-head">
                        <h5><?php esc_html_e( 'Transfer checklist', 'patsch9-rental-engine' ); ?></h5>
                        <button type="button" class="button rmwc-add-checklist-item" data-target="handover"><?php esc_html_e( '+ Punkt', 'patsch9-rental-engine' ); ?></button>
                    </div>
                    <div class="rmwc-checklist-items" data-checklist="handover">
                        <?php foreach ( $handover as $item ) : ?>
                            <div class="rmwc-checklist-item"><input type="text" maxlength="220" name="rmwc_handover_checklist[]" value="<?php echo esc_attr( $item ); ?>"><button type="button" class="button-link-delete rmwc-remove-checklist-item" aria-label="<?php esc_attr_e( 'Remove checkpoint', 'patsch9-rental-engine' ); ?>"><?php esc_html_e( '×', 'patsch9-rental-engine' ); ?></button></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Example: Device clean, accessories complete, instruction done.', 'patsch9-rental-engine' ); ?></p>
                </section>
                <section class="rmwc-checklist-config-card">
                    <div class="rmwc-checklist-config-head">
                        <h5><?php esc_html_e( 'Return checklist', 'patsch9-rental-engine' ); ?></h5>
                        <button type="button" class="button rmwc-add-checklist-item" data-target="return"><?php esc_html_e( '+ Punkt', 'patsch9-rental-engine' ); ?></button>
                    </div>
                    <div class="rmwc-checklist-items" data-checklist="return">
                        <?php foreach ( $return as $item ) : ?>
                            <div class="rmwc-checklist-item"><input type="text" maxlength="220" name="rmwc_return_checklist[]" value="<?php echo esc_attr( $item ); ?>"><button type="button" class="button-link-delete rmwc-remove-checklist-item" aria-label="<?php esc_attr_e( 'Remove checkpoint', 'patsch9-rental-engine' ); ?>"><?php esc_html_e( '×', 'patsch9-rental-engine' ); ?></button></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Example: boiler cleaned, accessories complete, no visible damage.', 'patsch9-rental-engine' ); ?></p>
                </section>
            </div>
            <p class="form-field">
                <label for="_rmwc_workflow_signature_enabled"><?php esc_html_e( 'Unterschrift', 'patsch9-rental-engine' ); ?></label>
                <input type="checkbox" class="checkbox" id="_rmwc_workflow_signature_enabled" name="_rmwc_workflow_signature_enabled" value="yes" <?php checked( $signature ); ?>>
                <span class="description"><?php esc_html_e( 'Offer an optional handwritten documentation signature in the handover/return form. This is not a qualified electronic signature.', 'patsch9-rental-engine' ); ?></span>
            </p>
        </div>
        <?php
    }

    public function save_product_fields( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['rmwc_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_save_product_' . $post_id ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability checked above; every checklist entry is sanitized in clean_checklist().
        $handover = isset( $_POST['rmwc_handover_checklist'] ) && is_array( $_POST['rmwc_handover_checklist'] ) ? wp_unslash( $_POST['rmwc_handover_checklist'] ) : [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability checked above; every checklist entry is sanitized in clean_checklist().
        $return = isset( $_POST['rmwc_return_checklist'] ) && is_array( $_POST['rmwc_return_checklist'] ) ? wp_unslash( $_POST['rmwc_return_checklist'] ) : [];
        update_post_meta( $post_id, '_rmwc_handover_checklist', $this->clean_checklist( $handover ) );
        update_post_meta( $post_id, '_rmwc_return_checklist', $this->clean_checklist( $return ) );
        update_post_meta( $post_id, '_rmwc_workflow_signature_enabled', isset( $_POST['_rmwc_workflow_signature_enabled'] ) ? 'yes' : 'no' );
    }

    private function booking( $booking_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->booking_table(), absint( $booking_id ) ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    private function asset_tracking_enabled( $product_id ) {
        return 'yes' === get_post_meta( absint( $product_id ), '_rmwc_asset_tracking', true );
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
            return new WP_Error( 'rmwc_asset_booking', __( 'Device assignment could not be prepared.', 'patsch9-rental-engine' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
                'rmwc_asset_capacity',
                sprintf(
                    /* translators: 1: required number of physical assets, 2: rental product ID. */
                    __( 'For this booking, %1$d specific devices are required, for rental product #%2$d however, not enough usable devices are stored during the period.', 'patsch9-rental-engine' ),
                    $quantity,
                    $product_id
                )
            );
        }

        $created = [];
        foreach ( $available_ids as $asset_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
                return new WP_Error( 'rmwc_asset_assignment_db', __( 'The specific equipment assignment could not be saved.', 'patsch9-rental-engine' ) );
            }
            $created[] = (int) $wpdb->insert_id;
        }
        return $created;
    }

    private function delete_assignments_by_ids( array $ids ) {
        global $wpdb;
        foreach ( array_map( 'absint', $ids ) as $id ) {
            if ( $id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
            return new WP_Error( 'rmwc_asset_edit_state', __( 'Devices already handed over cannot be automatically rebooked to another period.', 'patsch9-rental-engine' ) );
        }

        global $wpdb;
        $booking_id = absint( $old_row['id'] ?? 0 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $old_assignments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE booking_id = %d AND status = 'reserved' ORDER BY id",
                $this->assignment_table(),
                $booking_id
            ),
            ARRAY_A
        );
        $old_assignments = is_array( $old_assignments ) ? $old_assignments : [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $wpdb->delete( $this->assignment_table(), [ 'booking_id' => $booking_id, 'status' => 'reserved' ], [ '%d', '%s' ] );
        $created = $this->ensure_booking_assignments( $new_row );
        if ( ! is_wp_error( $created ) ) {
            return true;
        }

        // Roll the physical assignment state back to the exact previous rows.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $wpdb->delete( $this->assignment_table(), [ 'booking_id' => $booking_id, 'status' => 'reserved' ], [ '%d', '%s' ] );
        foreach ( $old_assignments as $assignment ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
            $candidate = $item->get_meta( '_rmwc_rental_data', true );
            if ( is_array( $candidate ) && (int) ( $candidate['product_id'] ?? 0 ) === (int) ( $booking['product_id'] ?? 0 ) ) {
                return $candidate;
            }
        }
        return [];
    }

    private function fulfilment_label( $mode ) {
        $labels = [
            'pickup'          => __( 'Pickup and return by customer', 'patsch9-rental-engine' ),
            'delivery'        => __( 'Delivery by rental operator and return by customer', 'patsch9-rental-engine' ),
            'delivery_return' => __( 'Delivery and collection by rental operator', 'patsch9-rental-engine' ),
        ];
        $mode = sanitize_key( (string) $mode );
        return $labels[ $mode ] ?? '';
    }

    private function workflow_status_label( $status ) {
        $labels = [
            'reserved'    => __( 'Reserviert', 'patsch9-rental-engine' ),
            'handed_over' => __( 'Surrender', 'patsch9-rental-engine' ),
            'returned'    => __( 'Returned', 'patsch9-rental-engine' ),
        ];
        return $labels[ sanitize_key( $status ) ] ?? sanitize_text_field( $status );
    }

    public function render_admin_tab() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No permission.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WooCommerce admin navigation/filter parameter; no state change occurs here.
        $notice = isset( $_GET['rmwc_workflow_notice'] ) ? sanitize_key( wp_unslash( $_GET['rmwc_workflow_notice'] ) ) : '';
        if ( $notice ) {
            $messages = [
                'handover_saved'   => [ 'success', __( 'Handover has been documented.', 'patsch9-rental-engine' ) ],
                'return_saved'     => [ 'success', __( 'Return has been documented.', 'patsch9-rental-engine' ) ],
                'checklist'        => [ 'error', __( 'Please confirm or evaluate all test points in full.', 'patsch9-rental-engine' ) ],
                'checklist_detail' => [ 'error', __( 'For "Difference / missing" a description must be stored.', 'patsch9-rental-engine' ) ],
                'signature'        => [ 'error', __( 'The signature could not be processed.', 'patsch9-rental-engine' ) ],
                'assets'           => [ 'error', __( 'The equipment assignment could not be completed.', 'patsch9-rental-engine' ) ],
                'deposit_required' => [ 'error', __( 'The required deposit must be fully recorded before the handover is completed.', 'patsch9-rental-engine' ) ],
                'deposit_error'    => [ 'error', __( 'The deposit booking could not be completed. Please try again or use the deposit box in the order.', 'patsch9-rental-engine' ) ],
                'deposit_settlement' => [ 'error', __( 'Please complete the deposit statement in full. If less than the deposit deposited is refunded, it is necessary to specify what happens to the balance.', 'patsch9-rental-engine' ) ],
                'workflow_state'   => [ 'warning', __( 'This step is not available for the current status.', 'patsch9-rental-engine' ) ],
            ];
            if ( isset( $messages[ $notice ] ) ) {
                echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . '"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WooCommerce admin navigation/filter parameter; no state change occurs here.
        $booking_id = isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WooCommerce admin navigation/filter parameter; no state change occurs here.
        $mode       = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
        if ( $booking_id && in_array( $mode, [ 'handover', 'return' ], true ) ) {
            $this->render_workflow_form( $booking_id, $mode );
            return;
        }

        global $wpdb;
        $now = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
        <div class="rmwc-v2-admin-card">
            <h2><?php esc_html_e( 'Transfers & Returns', 'patsch9-rental-engine' ); ?></h2>
            <p><?php esc_html_e( 'The operational rental process is documented here. An invariable PDF protocol is created per conclusion in the corresponding order.', 'patsch9-rental-engine' ); ?></p>
            <table class="widefat striped rmwc-workflow-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Termin', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Rental goods', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Order', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Device(s)', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Aktion', 'patsch9-rental-engine' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No outstanding rentals.', 'patsch9-rental-engine' ); ?></td></tr>
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
                                esc_html_e( 'Not yet allocated', 'patsch9-rental-engine' );
                            } else {
                                esc_html_e( 'No single device management', 'patsch9-rental-engine' );
                            }
                        ?></td>
                        <td><?php echo esc_html( $this->workflow_status_label( $status ) ); ?></td>
                        <td>
                            <?php if ( 'reserved' === $status ) : ?>
                                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=workflow&mode=handover&booking_id=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Transfer', 'patsch9-rental-engine' ); ?></a>
                            <?php elseif ( 'handed_over' === $status ) : ?>
                                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=workflow&mode=return&booking_id=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Return', 'patsch9-rental-engine' ); ?></a>
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
            echo '<div class="notice notice-error"><p>' . esc_html__( 'The booking was not found or is not suitable for this workflow.', 'patsch9-rental-engine' ) . '</p></div>';
            return;
        }
        $order = wc_get_order( (int) $booking['order_id'] );
        $product = wc_get_product( (int) $booking['product_id'] );
        if ( ! $order || ! $product ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Order or rental product was not found.', 'patsch9-rental-engine' ) . '</p></div>';
            return;
        }

        $expected = 'handover' === $mode ? 'reserved' : 'handed_over';
        if ( sanitize_key( $booking['workflow_status'] ?? 'reserved' ) !== $expected ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'This step is not available for the current status.', 'patsch9-rental-engine' ) . '</p></div>';
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
        $signature_enabled = 'yes' === get_post_meta( (int) $booking['product_id'], '_rmwc_workflow_signature_enabled', true );
        $action = 'handover' === $mode ? 'rmwc_workflow_handover' : 'rmwc_workflow_return';
        $title  = 'handover' === $mode ? __( 'Complete handover', 'patsch9-rental-engine' ) : __( 'Complete return', 'patsch9-rental-engine' );
        $deposit_state = RMWC_V2_Deposits::instance()->state( $order );
        $rental_data   = $this->rental_data_for_booking( $booking, $order );
        $fulfilment    = sanitize_key( $rental_data['fulfilment'] ?? '' );
        ?>
        <div class="rmwc-v2-admin-card rmwc-workflow-form-card">
            <header class="rmwc-workflow-form-head">
                <a class="rmwc-workflow-back" href="<?php echo esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=workflow' ) ); ?>">← <?php esc_html_e( 'Overview', 'patsch9-rental-engine' ); ?></a>
                <div>
                    <h2><?php echo esc_html( $title ); ?></h2>
                    <p><?php echo esc_html( 'handover' === $mode ? __( 'Check equipment and accessories together with the customer and document the condition.', 'patsch9-rental-engine' ) : __( 'Check the return, document the condition and then release or test the device.', 'patsch9-rental-engine' ) ); ?></p>
                </div>
            </header>
            <div class="rmwc-workflow-summary">
                <div class="rmwc-workflow-summary-item"><span><?php esc_html_e( 'Rental goods', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( $product->get_name() ); ?></strong></div>
                <div class="rmwc-workflow-summary-item"><span><?php esc_html_e( 'Order / customer', 'patsch9-rental-engine' ); ?></span><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong><small><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></small></div>
                <div class="rmwc-workflow-summary-item"><span><?php esc_html_e( 'Period', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $booking['start_at'] ) ) . ' – ' . wp_date( 'd.m.Y H:i', strtotime( $booking['end_at'] ) ) ); ?></strong></div>
                <?php if ( $fulfilment ) : ?>
                    <div class="rmwc-workflow-summary-item"><span><?php esc_html_e( 'Transport / handover', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( $this->fulfilment_label( $fulfilment ) ); ?></strong><?php if ( in_array( $fulfilment, [ 'delivery', 'delivery_return' ], true ) && ! empty( $rental_data['delivery_address'] ) ) : ?><small><?php echo esc_html( $rental_data['delivery_address'] ); ?></small><?php endif; ?></div>
                <?php endif; ?>
                <?php if ( $assets ) : ?><div class="rmwc-workflow-summary-item"><span><?php esc_html_e( 'Related devices', 'patsch9-rental-engine' ); ?></span><strong><?php echo esc_html( implode( ', ', array_map( static fn( $asset ) => (string) $asset['inventory_number'], $assets ) ) ); ?></strong></div><?php endif; ?>
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
                <section class="rmwc-workflow-known-damage" aria-label="<?php esc_attr_e( 'Known pre-damages', 'patsch9-rental-engine' ); ?>">
                    <div class="rmwc-workflow-known-damage-title"><?php esc_html_e( 'Known pre-damage / signs of wear', 'patsch9-rental-engine' ); ?></div>
                    <p><?php esc_html_e( 'This information comes from the inventory log and is automatically transferred to the log. Check if it continues to apply when it is handed over.', 'patsch9-rental-engine' ); ?></p>
                    <ul>
                        <?php foreach ( $assets_with_damage as $asset ) : ?>
                            <li><strong><?php echo esc_html( $asset['inventory_number'] ?? '' ); ?>:</strong> <?php echo esc_html( $asset['known_damage'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmwc-workflow-form">
                <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
                <input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking_id ); ?>">
                <?php wp_nonce_field( $action . '_' . $booking_id, 'rmwc_workflow_nonce' ); ?>

                <?php if ( $checklist ) : ?>
                    <fieldset class="rmwc-workflow-checklist rmwc-workflow-form-section <?php echo 'return' === $mode ? 'rmwc-workflow-return-checklist' : ''; ?>">
                        <legend><?php esc_html_e( 'Test points', 'patsch9-rental-engine' ); ?></legend>
                        <?php if ( 'return' === $mode ) : ?>
                            <p class="description rmwc-workflow-checklist-help"><?php esc_html_e( 'Each check point has to be evaluated. In case of "Difference / missing", a short description is mandatory and is stored in the return log.', 'patsch9-rental-engine' ); ?></p>
                            <?php foreach ( $checklist as $index => $item ) : ?>
                                <div class="rmwc-workflow-return-check-row" data-check-index="<?php echo esc_attr( $index ); ?>">
                                    <div class="rmwc-workflow-return-check-label"><strong><?php echo esc_html( $item ); ?></strong></div>
                                    <div class="rmwc-workflow-return-check-options" role="radiogroup" aria-label="<?php echo esc_attr( $item ); ?>">
                                        <label><input type="radio" name="checklist_status[<?php echo esc_attr( $index ); ?>]" value="ok" required> <span><?php esc_html_e( 'In Ordnung', 'patsch9-rental-engine' ); ?></span></label>
                                        <label><input type="radio" name="checklist_status[<?php echo esc_attr( $index ); ?>]" value="issue" required> <span><?php esc_html_e( 'Abweichung / fehlt', 'patsch9-rental-engine' ); ?></span></label>
                                        <label><input type="radio" name="checklist_status[<?php echo esc_attr( $index ); ?>]" value="na" required> <span><?php esc_html_e( 'Not applicable', 'patsch9-rental-engine' ); ?></span></label>
                                    </div>
                                    <label class="rmwc-workflow-return-check-note">
                                        <span><?php esc_html_e( 'Dokumentation / Bemerkung', 'patsch9-rental-engine' ); ?></span>
                                        <textarea name="checklist_note[<?php echo esc_attr( $index ); ?>]" rows="2" maxlength="700" placeholder="<?php esc_attr_e( 'In case of deviation e.g. "Measurement cup is missing" or "very dirty"', 'patsch9-rental-engine' ); ?>"></textarea>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <?php foreach ( $checklist as $index => $item ) : ?>
                                <label class="rmwc-workflow-check-row"><input type="checkbox" name="checklist[]" value="<?php echo esc_attr( $index ); ?>" required> <span><?php echo esc_html( $item ); ?></span></label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ( (float) ( $deposit_state['required'] ?? 0 ) > 0 ) : ?>
                    <section class="rmwc-workflow-deposit rmwc-workflow-form-section">
                        <div class="rmwc-workflow-section-head">
                            <div>
                                <h3><?php esc_html_e( 'Security deposit', 'patsch9-rental-engine' ); ?></h3>
                                <p><?php esc_html_e( 'The deposit stand is documented in the log together with this process.', 'patsch9-rental-engine' ); ?></p>
                            </div>
                        </div>
                        <div class="rmwc-workflow-deposit-grid">
                            <div><span><?php esc_html_e( 'Erforderlich', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['required'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Received', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['received'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Repaid', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['refunded'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Aktuell hinterlegt', 'patsch9-rental-engine' ); ?></span><strong><?php echo wp_kses_post( wc_price( $deposit_state['available'], [ 'currency' => $order->get_currency() ] ) ); ?></strong></div>
                        </div>

                        <?php if ( 'handover' === $mode && (float) ( $deposit_state['missing'] ?? 0 ) > 0 ) : ?>
                            <div class="notice notice-warning inline rmwc-workflow-deposit-warning">
                                <p><?php
                                    printf(
                                        /* translators: %s: still missing refundable deposit. */
                                        esc_html__( 'Before handing over %s deposit is still open. The open amount must now be recorded.', 'patsch9-rental-engine' ),
                                        esc_html( wp_strip_all_tags( wc_price( $deposit_state['missing'], [ 'currency' => $order->get_currency() ] ) ) )
                                    );
                                ?></p>
                            </div>
                            <label class="rmwc-workflow-check-row rmwc-workflow-deposit-toggle">
                                <input type="checkbox" name="rmwc_workflow_deposit_receive" value="1" required>
                                <span><strong><?php esc_html_e( 'Deposit received in full', 'patsch9-rental-engine' ); ?></strong><small><?php esc_html_e( 'With this confirmation, the open amount is booked as received and a deposit receipt is generated.', 'patsch9-rental-engine' ); ?></small></span>
                            </label>
                            <p class="rmwc-workflow-field">
                                <label><strong><?php esc_html_e( 'Deposit received as', 'patsch9-rental-engine' ); ?></strong><br>
                                    <select name="rmwc_workflow_deposit_method" required>
                                        <option value="cash"><?php esc_html_e( 'Cash at handover', 'patsch9-rental-engine' ); ?></option>
                                        <option value="transfer"><?php esc_html_e( 'Separate transfer already received', 'patsch9-rental-engine' ); ?></option>
                                        <option value="other"><?php esc_html_e( 'Sonstige Hinterlegung', 'patsch9-rental-engine' ); ?></option>
                                    </select>
                                </label>
                            </p>
                        <?php elseif ( 'handover' === $mode ) : ?>
                            <p class="rmwc-workflow-deposit-ok">✓ <?php esc_html_e( 'The required security deposit is fully recorded.', 'patsch9-rental-engine' ); ?></p>
                        <?php endif; ?>

                        <?php if ( 'return' === $mode && (float) ( $deposit_state['available'] ?? 0 ) > 0 ) : ?>
                            <?php if ( (float) ( $deposit_state['online_available'] ?? 0 ) > 0 ) : ?>
                                <div class="notice notice-info inline">
                                    <p><?php esc_html_e( 'Part of the deposit has been deposited online. An automatic refund on the original payment method is still safely triggered via the deposit box in the order. Only enter here the amount that has actually been refunded at this return.', 'patsch9-rental-engine' ); ?></p>
                                    <p><a class="button" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'Open deposit box in order', 'patsch9-rental-engine' ); ?></a></p>
                                </div>
                            <?php endif; ?>
                            <div class="rmwc-workflow-deposit-settlement" data-available="<?php echo esc_attr( wc_format_decimal( $deposit_state['available'] ) ); ?>">
                                <h4><?php esc_html_e( 'Deposit statement on return', 'patsch9-rental-engine' ); ?></h4>
                                <p class="description"><?php esc_html_e( 'Specify how much deposit has actually been paid back to the customer during this return. If the amount is lower than the deposit currently deposited, the reason for the remaining amount must be documented.', 'patsch9-rental-engine' ); ?></p>
                                <p class="rmwc-workflow-field"><label><strong><?php esc_html_e( 'Refunded at this return', 'patsch9-rental-engine' ); ?></strong><br>
                                    <input type="number" name="rmwc_workflow_deposit_refund_amount" step="0.01" min="0" max="<?php echo esc_attr( wc_format_decimal( $deposit_state['available'] ) ); ?>" value="<?php echo esc_attr( wc_format_decimal( $deposit_state['available'] ) ); ?>" required>
                                </label></p>
                                <p class="rmwc-workflow-deposit-retained-preview"><strong><?php esc_html_e( 'Verbleibender Betrag:', 'patsch9-rental-engine' ); ?></strong> <span><?php echo wp_kses_post( wc_price( 0, [ 'currency' => $order->get_currency() ] ) ); ?></span></p>
                                <div class="rmwc-workflow-deposit-retention-fields">
                                    <p class="rmwc-workflow-field"><label><strong><?php esc_html_e( 'Reason for the remaining amount', 'patsch9-rental-engine' ); ?></strong><br>
                                        <select name="rmwc_workflow_deposit_retention_reason">
                                            <option value="none"><?php esc_html_e( 'No balance', 'patsch9-rental-engine' ); ?></option>
                                            <option value="pending"><?php esc_html_e( 'Repayment of the balance is made later / separately', 'patsch9-rental-engine' ); ?></option>
                                            <option value="damage"><?php esc_html_e( 'Retention due to damage', 'patsch9-rental-engine' ); ?></option>
                                            <option value="cleaning"><?php esc_html_e( 'Retention due to cleaning / pollution', 'patsch9-rental-engine' ); ?></option>
                                            <option value="loss"><?php esc_html_e( 'Retention due to loss / non-return', 'patsch9-rental-engine' ); ?></option>
                                            <option value="other"><?php esc_html_e( 'Other withholding', 'patsch9-rental-engine' ); ?></option>
                                        </select>
                                    </label></p>
                                    <p class="rmwc-workflow-field"><label><strong><?php esc_html_e( 'Reason/details', 'patsch9-rental-engine' ); ?></strong><br>
                                        <textarea name="rmwc_workflow_deposit_retention_note" rows="3" maxlength="700" placeholder="<?php esc_attr_e( 'Optional supplement, e.g. "cleaning allowance due to heavy pollution" or "missing measuring cup"', 'patsch9-rental-engine' ); ?>"></textarea>
                                    </label></p>
                                </div>
                            </div>
                        <?php elseif ( 'return' === $mode ) : ?>
                            <p class="rmwc-workflow-deposit-ok">✓ <?php esc_html_e( 'There is currently no deposit left for repayment.', 'patsch9-rental-engine' ); ?></p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ( 'return' === $mode && $assets ) : ?>
                    <p class="rmwc-workflow-field">
                        <label><strong><?php esc_html_e( 'Device status after return', 'patsch9-rental-engine' ); ?></strong><br>
                            <select name="asset_status">
                                <option value="available"><?php esc_html_e( 'Available', 'patsch9-rental-engine' ); ?></option>
                                <option value="cleaning"><?php esc_html_e( 'Cleaning / testing', 'patsch9-rental-engine' ); ?></option>
                                <option value="maintenance"><?php esc_html_e( 'Wartung', 'patsch9-rental-engine' ); ?></option>
                                <option value="defect"><?php esc_html_e( 'Defekt', 'patsch9-rental-engine' ); ?></option>
                            </select>
                        </label>
                    </p>
                <?php endif; ?>

                <p class="rmwc-workflow-field"><label><strong><?php esc_html_e( 'Note / newly identified abnormalities', 'patsch9-rental-engine' ); ?></strong><br><textarea name="notes" rows="5" maxlength="3000" placeholder="<?php esc_attr_e( 'Optional: document new damage, missing accessories or other information ...', 'patsch9-rental-engine' ); ?>"></textarea></label></p>

                <?php if ( $signature_enabled ) : ?>
                    <div class="rmwc-signature-wrap">
                        <p><strong><?php esc_html_e( 'Optionale Dokumentationsunterschrift', 'patsch9-rental-engine' ); ?></strong></p>
                        <canvas class="rmwc-signature-pad" width="700" height="220" aria-label="<?php esc_attr_e( 'Unterschriftsfeld', 'patsch9-rental-engine' ); ?>"></canvas>
                        <input type="hidden" name="signature_data" class="rmwc-signature-data" value="">
                        <p><button type="button" class="button rmwc-signature-clear"><?php esc_html_e( 'Delete signature', 'patsch9-rental-engine' ); ?></button></p>
                        <p class="description"><?php esc_html_e( 'The signature serves the documentation of the transfer/return process and is not a qualified electronic signature.', 'patsch9-rental-engine' ); ?></p>
                    </div>
                <?php endif; ?>

                <div class="rmwc-workflow-submit-row"><?php submit_button( $title, 'primary', 'submit', false ); ?></div>
            </form>
        </div>
        <?php
    }

    private function validate_action_request( $action ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No permission.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $booking_id = isset( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0;
        $nonce = isset( $_POST['rmwc_workflow_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_workflow_nonce'] ) ) : '';
        if ( ! $booking_id || ! $nonce || ! wp_verify_nonce( $nonce, $action . '_' . $booking_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $booking = $this->booking( $booking_id );
        if ( ! $booking || 'order' !== $booking['source'] || ! $booking['order_id'] ) {
            wp_die( esc_html__( 'Booking not found.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }
        $order = wc_get_order( (int) $booking['order_id'] );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }
        return [ $booking, $order ];
    }

    private function validated_checklist_snapshot( array $booking, $type ) {
        $configured = $this->checklist( (int) $booking['product_id'], $type );

        if ( 'return' === $type ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- validate_action_request() verifies the action-specific nonce before this private helper is called; values are then allowlisted below.
            $raw_status = isset( $_POST['checklist_status'] ) && is_array( $_POST['checklist_status'] ) ? wp_unslash( $_POST['checklist_status'] ) : [];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- validate_action_request() verifies the action-specific nonce before this private helper is called; notes are sanitized and length-limited below.
            $raw_notes = isset( $_POST['checklist_note'] ) && is_array( $_POST['checklist_note'] ) ? wp_unslash( $_POST['checklist_note'] ) : [];
            $allowed = [ 'ok', 'issue', 'na' ];
            $snapshot = [];

            foreach ( $configured as $index => $label ) {
                $status = isset( $raw_status[ $index ] ) ? sanitize_key( $raw_status[ $index ] ) : '';
                if ( ! in_array( $status, $allowed, true ) ) {
                    return new WP_Error( 'rmwc_workflow_checklist', __( 'Please evaluate each checkpoint.', 'patsch9-rental-engine' ) );
                }
                $note = isset( $raw_notes[ $index ] ) ? sanitize_textarea_field( $raw_notes[ $index ] ) : '';
                $note = trim( function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 700 ) : substr( $note, 0, 700 ) );
                if ( 'issue' === $status && '' === $note ) {
                    return new WP_Error( 'rmwc_workflow_checklist_detail', __( 'For "Difference / missing" a description must be stored.', 'patsch9-rental-engine' ) );
                }
                $snapshot[] = [
                    'label'  => sanitize_text_field( $label ),
                    'status' => $status,
                    'note'   => $note,
                ];
            }
            return $snapshot;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- validate_action_request() verifies the action-specific nonce before this private helper is called; only numeric configured indexes are accepted.
        $raw = isset( $_POST['checklist'] ) && is_array( $_POST['checklist'] ) ? wp_unslash( $_POST['checklist'] ) : [];
        $selected = array_values( array_unique( array_map( 'absint', $raw ) ) );
        if ( $configured && count( $selected ) < count( $configured ) ) {
            return new WP_Error( 'rmwc_workflow_checklist', __( 'Please confirm all checkpoints.', 'patsch9-rental-engine' ) );
        }
        foreach ( array_keys( $configured ) as $index ) {
            if ( ! in_array( $index, $selected, true ) ) {
                return new WP_Error( 'rmwc_workflow_checklist', __( 'Please confirm all checkpoints.', 'patsch9-rental-engine' ) );
            }
        }
        return $configured;
    }

    private function signature_from_request() {
        $raw = isset( $_POST['signature_data'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['signature_data'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verifies the action-specific nonce before this private helper is called; the data URI is strictly validated below.
        if ( '' === $raw ) {
            return '';
        }
        if ( ! preg_match( '#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $raw, $matches ) ) {
            return new WP_Error( 'rmwc_signature_format', __( 'The signature could not be processed.', 'patsch9-rental-engine' ) );
        }
        $bytes = base64_decode( $matches[1], true );
        if ( false === $bytes || strlen( $bytes ) > 220000 ) {
            return new WP_Error( 'rmwc_signature_size', __( 'The signature is too large or invalid.', 'patsch9-rental-engine' ) );
        }
        $info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data is handled explicitly.
        if ( ! is_array( $info ) || IMAGETYPE_JPEG !== ( $info[2] ?? 0 ) || ( $info[0] ?? 0 ) > 1400 || ( $info[1] ?? 0 ) > 500 ) {
            return new WP_Error( 'rmwc_signature_image', __( 'The signature is not a valid image file.', 'patsch9-rental-engine' ) );
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
            if ( empty( $_POST['rmwc_workflow_deposit_receive'] ) ) {
                $this->redirect( 'deposit_required', (int) $booking['id'], 'handover' );
            }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
            $method = isset( $_POST['rmwc_workflow_deposit_method'] ) ? sanitize_key( wp_unslash( $_POST['rmwc_workflow_deposit_method'] ) ) : 'cash';
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
        $notes = isset( $_POST['notes'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ), 0, 3000 ) : '';
        $snapshot = $this->workflow_snapshot( $booking, $order, 'handover', $checklist, $assets, $signature, $notes );

        global $wpdb;
        $now = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $wpdb->update(
            $this->assignment_table(),
            [ 'status' => 'handed_over', 'updated_at' => $now ],
            [ 'booking_id' => (int) $booking['id'], 'status' => 'reserved' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
        foreach ( $assets as $asset ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $wpdb->update( $this->asset_table(), [ 'status' => 'rented', 'updated_at' => $now ], [ 'id' => absint( $asset['asset_id'] ) ], [ '%s', '%s' ], [ '%d' ] );
        }

        $documents = RMWC_V2_Documents::instance();
        $document_id = $documents->create_document( $order, 'handover_protocol', $snapshot, 0, 'handover_b' . (int) $booking['id'] );
        if ( ! is_wp_error( $document_id ) ) {
            $documents->send_document_email(
                $order,
                (int) $document_id,
                __( 'Transfer log to your rent', 'patsch9-rental-engine' ),
                __( 'Transfer Log', 'patsch9-rental-engine' ),
                __( 'In the appendix you will receive the minutes of the delivery of your rental item.', 'patsch9-rental-engine' )
            );
        }
        $order->add_order_note( sprintf( __( 'Rental: Transfer for booking #%d documented.', 'patsch9-rental-engine' ), (int) $booking['id'] ) );
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
            $notice = 'rmwc_workflow_checklist_detail' === $checklist->get_error_code() ? 'checklist_detail' : 'checklist';
            $this->redirect( $notice, (int) $booking['id'], 'return' );
        }
        $signature = $this->signature_from_request();
        if ( is_wp_error( $signature ) ) {
            $this->redirect( 'signature', (int) $booking['id'], 'return' );
        }

        $deposit_module = RMWC_V2_Deposits::instance();
        $deposit_state  = $deposit_module->state( $order );
        if ( (float) ( $deposit_state['available'] ?? 0 ) > 0 ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
            if ( ! isset( $_POST['rmwc_workflow_deposit_refund_amount'] ) ) {
                $this->redirect( 'deposit_settlement', (int) $booking['id'], 'return' );
            }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
            $amount = max( 0, (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['rmwc_workflow_deposit_refund_amount'] ) ) ) );
            $amount = min( (float) $deposit_state['available'], $amount );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
            $reason = isset( $_POST['rmwc_workflow_deposit_retention_reason'] ) ? sanitize_key( wp_unslash( $_POST['rmwc_workflow_deposit_retention_reason'] ) ) : 'none';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
            $retention_note = isset( $_POST['rmwc_workflow_deposit_retention_note'] )
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
                ? substr( sanitize_textarea_field( wp_unslash( $_POST['rmwc_workflow_deposit_retention_note'] ) ), 0, 700 )
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
        $asset_status = isset( $_POST['asset_status'] ) ? sanitize_key( wp_unslash( $_POST['asset_status'] ) ) : 'available';
        if ( ! in_array( $asset_status, $allowed_statuses, true ) ) {
            $asset_status = 'available';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validate_action_request() verified the action-specific nonce at the start of this handler.
        $notes = isset( $_POST['notes'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ), 0, 3000 ) : '';
        $snapshot = $this->workflow_snapshot( $booking, $order, 'return', $checklist, $assets, $signature, $notes );
        $snapshot['asset_status_after_return'] = $asset_status;

        global $wpdb;
        $now = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $wpdb->update(
            $this->assignment_table(),
            [ 'status' => 'returned', 'updated_at' => $now ],
            [ 'booking_id' => (int) $booking['id'] ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        foreach ( $assets as $asset ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $wpdb->update( $this->asset_table(), [ 'status' => $asset_status, 'updated_at' => $now ], [ 'id' => absint( $asset['asset_id'] ) ], [ '%s', '%s' ], [ '%d' ] );
        }

        $documents = RMWC_V2_Documents::instance();
        $document_id = $documents->create_document( $order, 'return_protocol', $snapshot, 0, 'return_b' . (int) $booking['id'] );
        if ( ! is_wp_error( $document_id ) ) {
            $documents->send_document_email(
                $order,
                (int) $document_id,
                __( 'Return log to your rent', 'patsch9-rental-engine' ),
                __( 'Return Log', 'patsch9-rental-engine' ),
                __( 'In the Appendix you will receive the log for the return of your rental item.', 'patsch9-rental-engine' )
            );
        }
        $order->add_order_note( sprintf( __( 'Rental: Return for booking #%d documented.', 'patsch9-rental-engine' ), (int) $booking['id'] ) );
        $this->complete_order_when_all_returns_finished( $order );
        do_action( 'rmwc_workflow_status_changed', $order, (int) $booking['id'], 'returned' );
        $this->redirect( 'return_saved' );
    }

    private function complete_order_when_all_returns_finished( WC_Order $order ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
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
                __( 'Rental: All rental items have been returned. Order completed automatically.', 'patsch9-rental-engine' )
            );
        }
        return 'completed' === $order->get_status();
    }

    private function redirect( $notice, $booking_id = 0, $mode = '' ) {
        $args = [ 'page' => 'rmwc-rentals', 'tab' => 'workflow', 'rmwc_workflow_notice' => sanitize_key( $notice ) ];
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
            add_meta_box( 'rmwc_workflow_box', __( 'Rental procedure', 'patsch9-rental-engine' ), [ $this, 'render_order_meta_box' ], $screen, 'side', 'default' );
        }
    }

    public function render_order_meta_box( $object ) {
        $order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
        if ( ! $order ) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $bookings = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY start_at, id', $this->booking_table(), $order->get_id() ),
            ARRAY_A
        );
        if ( ! $bookings ) {
            echo '<p>' . esc_html__( 'No rental booking in this order.', 'patsch9-rental-engine' ) . '</p>';
            return;
        }
        foreach ( $bookings as $booking ) {
            $product = wc_get_product( (int) $booking['product_id'] );
            $assets  = $this->assignments_for_booking( (int) $booking['id'] );
            $status  = sanitize_key( $booking['workflow_status'] ?? 'reserved' );
            echo '<div class="rmwc-order-workflow-item">';
            echo '<p><strong>' . esc_html( $product ? $product->get_name() : '#' . (int) $booking['product_id'] ) . '</strong><br>';
            echo esc_html( $this->workflow_status_label( $status ) );
            if ( $assets ) {
                echo '<br>' . esc_html( implode( ', ', array_map( static fn( $asset ) => (string) $asset['inventory_number'], $assets ) ) );
            }
            echo '</p>';
            if ( 'reserved' === $status ) {
                echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=workflow&mode=handover&booking_id=' . (int) $booking['id'] ) ) . '">' . esc_html__( 'Open commit', 'patsch9-rental-engine' ) . '</a></p>';
            } elseif ( 'handed_over' === $status ) {
                echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=workflow&mode=return&booking_id=' . (int) $booking['id'] ) ) . '">' . esc_html__( 'Open Return', 'patsch9-rental-engine' ) . '</a></p>';
            }
            echo '</div>';
        }
    }
}
