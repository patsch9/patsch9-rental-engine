<?php
/**
 * Physical rental asset registry.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Inventory {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rmwc_render_admin_tab_inventory', [ $this, 'render_admin_tab' ] );
        add_action( 'admin_post_rmwc_asset_save', [ $this, 'save_asset' ] );
        add_action( 'admin_post_rmwc_asset_delete', [ $this, 'delete_asset' ] );
        add_action( 'rmwc_product_panel_after_handover', [ $this, 'product_field' ], 30 );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_field' ], 50 );
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'rmwc_assets';
    }

    private function assignment_table() {
        global $wpdb;
        return $wpdb->prefix . 'rmwc_asset_assignments';
    }

    private function statuses() {
        return [
            'available'      => __( 'Available', 'patsch9-rental-engine' ),
            'rented'         => __( 'Rented', 'patsch9-rental-engine' ),
            'cleaning'       => __( 'Cleaning / testing', 'patsch9-rental-engine' ),
            'maintenance'    => __( 'Maintenance', 'patsch9-rental-engine' ),
            'defect'         => __( 'Defective', 'patsch9-rental-engine' ),
            'out_of_service' => __( 'Out of service', 'patsch9-rental-engine' ),
        ];
    }

    public function product_field( $product_id ) {
        woocommerce_wp_checkbox(
            [
                'id'          => '_rmwc_asset_tracking',
                'wrapper_class' => 'rmwc-rental-field-row',
                'label'       => __( 'Manage individual equipment', 'patsch9-rental-engine' ),
                'value'       => get_post_meta( $product_id, '_rmwc_asset_tracking', true ),
                'description' => __( 'Reservations are automatically assigned to specific devices with inventory/serial number. The master data is maintained under WooCommerce → Rentals → Devices.', 'patsch9-rental-engine' ),
                'desc_tip'    => true,
            ]
        );
    }

    public function save_product_field( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['rmwc_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_save_product_' . $post_id ) ) {
            return;
        }
        update_post_meta( $post_id, '_rmwc_asset_tracking', isset( $_POST['_rmwc_asset_tracking'] ) ? 'yes' : 'no' );
    }

    private function get_asset( $asset_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table(), absint( $asset_id ) ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function render_admin_tab() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No permission.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inventory admin navigation/filter parameter; no state change occurs here.
        $product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inventory admin navigation/filter parameter; no state change occurs here.
        $edit_id    = isset( $_GET['asset_id'] ) ? absint( wp_unslash( $_GET['asset_id'] ) ) : 0;
        $editing    = $edit_id ? $this->get_asset( $edit_id ) : null;

        if ( $product_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d ORDER BY inventory_number ASC, id ASC LIMIT 1000', $this->table(), $product_id ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i ORDER BY product_id ASC, inventory_number ASC, id ASC LIMIT 1000', $this->table() ),
                ARRAY_A
            );
        }
        $rows = is_array( $rows ) ? $rows : [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inventory admin navigation/filter parameter; no state change occurs here.
        $notice = isset( $_GET['rmwc_asset_notice'] ) ? sanitize_key( wp_unslash( $_GET['rmwc_asset_notice'] ) ) : '';
        if ( $notice ) {
            $messages = [
                'saved'              => [ 'success', __( 'Device saved.', 'patsch9-rental-engine' ) ],
                'deleted'            => [ 'success', __( 'Unused device has been deleted.', 'patsch9-rental-engine' ) ],
                'archived'           => [ 'success', __( 'The device already has history and was therefore set to "outside operation" instead of disappearing from old processes.', 'patsch9-rental-engine' ) ],
                'active_assignment'  => [ 'error', __( 'The device is assigned to an active or ongoing rental and cannot currently be deleted/archived.', 'patsch9-rental-engine' ) ],
                'history_product'    => [ 'error', __( 'The rental product of an already used device cannot be changed afterwards. To do so, create a new device.', 'patsch9-rental-engine' ) ],
                'duplicate'          => [ 'error', __( 'This inventory number is already assigned.', 'patsch9-rental-engine' ) ],
                'invalid_product'    => [ 'error', __( 'Please select a valid rental product.', 'patsch9-rental-engine' ) ],
                'invalid_inventory'  => [ 'error', __( 'Please specify an inventory number.', 'patsch9-rental-engine' ) ],
                'db_error'           => [ 'error', __( 'The device change could not be safely stored in the database. Please try again.', 'patsch9-rental-engine' ) ],
            ];
            if ( isset( $messages[ $notice ] ) ) {
                echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . ' inline"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
            }
        }

        ?>
        <div class="rmwc-v2-admin-card">
            <div class="rmwc-v2-card-heading">
                <div>
                    <h2><?php echo esc_html( $editing ? __( 'Edit equipment', 'patsch9-rental-engine' ) : __( 'Add equipment', 'patsch9-rental-engine' ) ); ?></h2>
                    <p><?php esc_html_e( 'Inventory numbers remain permanently linked to the rental history. Already used devices are therefore archived instead of removed from existing protocols when deleted.', 'patsch9-rental-engine' ); ?></p>
                </div>
                <?php if ( $editing ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=inventory' ) ); ?>"><?php esc_html_e( 'Cancel editing', 'patsch9-rental-engine' ); ?></a>
                <?php endif; ?>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmwc-v2-asset-form">
                <input type="hidden" name="action" value="rmwc_asset_save">
                <input type="hidden" name="asset_id" value="<?php echo esc_attr( $editing['id'] ?? 0 ); ?>">
                <?php wp_nonce_field( 'rmwc_asset_save', 'rmwc_v2_asset_nonce' ); ?>
                <div class="rmwc-v2-form-grid">
                    <p>
                        <label><strong><?php esc_html_e( 'Rental product', 'patsch9-rental-engine' ); ?></strong><br>
                            <select class="wc-product-search" style="width:100%" name="product_id" data-placeholder="<?php esc_attr_e( 'Search rental product...', 'patsch9-rental-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" required>
                                <?php
                                if ( $editing ) {
                                    $product = wc_get_product( (int) $editing['product_id'] );
                                    if ( $product ) {
                                        echo '<option value="' . esc_attr( $product->get_id() ) . '" selected>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </label>
                    </p>
                    <p><label><strong><?php esc_html_e( 'Inventory number', 'patsch9-rental-engine' ); ?></strong><br><input type="text" name="inventory_number" maxlength="100" value="<?php echo esc_attr( $editing['inventory_number'] ?? '' ); ?>" required></label></p>
                    <p><label><strong><?php esc_html_e( 'Serial number', 'patsch9-rental-engine' ); ?></strong><br><input type="text" name="serial_number" maxlength="190" value="<?php echo esc_attr( $editing['serial_number'] ?? '' ); ?>"></label></p>
                    <p>
                        <label><strong><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></strong><br>
                            <select name="status">
                                <?php foreach ( $this->statuses() as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $editing['status'] ?? 'available', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </p>
                    <p><label><strong><?php esc_html_e( 'Purchase date', 'patsch9-rental-engine' ); ?></strong><br><input type="date" name="purchase_date" value="<?php echo esc_attr( $editing['purchase_date'] ?? '' ); ?>"></label></p>
                    <p><label><strong><?php esc_html_e( 'Last service', 'patsch9-rental-engine' ); ?></strong><br><input type="date" name="last_service_date" value="<?php echo esc_attr( $editing['last_service_date'] ?? '' ); ?>"></label></p>
                </div>
                <div class="rmwc-v2-asset-textareas">
                    <p>
                        <label><strong><?php esc_html_e( 'Known damage / signs of wear', 'patsch9-rental-engine' ); ?></strong><br>
                            <textarea name="known_damage" rows="4" maxlength="4000" style="width:100%"><?php echo esc_textarea( $editing['known_damage'] ?? '' ); ?></textarea>
                        </label>
                        <span class="description"><?php esc_html_e( 'Only enter known pre-damages. They are displayed at the handover and are documented in transfer and return logs.', 'patsch9-rental-engine' ); ?></span>
                    </p>
                    <p><label><strong><?php esc_html_e( 'Internal note', 'patsch9-rental-engine' ); ?></strong><br><textarea name="notes" rows="4" maxlength="2000" style="width:100%"><?php echo esc_textarea( $editing['notes'] ?? '' ); ?></textarea></label></p>
                </div>
                <?php submit_button( $editing ? __( 'Save Changes', 'patsch9-rental-engine' ) : __( 'Add equipment', 'patsch9-rental-engine' ), 'primary', 'submit', false ); ?>
            </form>
        </div>

        <div class="rmwc-v2-admin-card">
            <h2><?php esc_html_e( 'Inventory', 'patsch9-rental-engine' ); ?></h2>
            <table class="widefat striped rmwc-assets-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Product', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Inventory', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Serial number', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Known damage', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Maintenance', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'patsch9-rental-engine' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No equipment recorded yet.', 'patsch9-rental-engine' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $rows as $row ) :
                    $product = wc_get_product( (int) $row['product_id'] );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $product ? $product->get_name() : '#' . (int) $row['product_id'] ); ?></td>
                        <td><strong><?php echo esc_html( $row['inventory_number'] ); ?></strong></td>
                        <td><?php echo esc_html( $row['serial_number'] ?: '–' ); ?></td>
                        <td><?php echo esc_html( $this->statuses()[ $row['status'] ] ?? $row['status'] ); ?></td>
                        <td><?php echo esc_html( ! empty( $row['known_damage'] ) ? wp_trim_words( $row['known_damage'], 12, '…' ) : '–' ); ?></td>
                        <td><?php echo esc_html( $row['last_service_date'] ?: '–' ); ?></td>
                        <td class="rmwc-row-actions">
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=rmwc-rentals&tab=inventory&asset_id=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'patsch9-rental-engine' ); ?></a>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Really delete device? Already used devices are only archived for security reasons.', 'patsch9-rental-engine' ) ); ?>');">
                                <input type="hidden" name="action" value="rmwc_asset_delete">
                                <input type="hidden" name="asset_id" value="<?php echo esc_attr( $row['id'] ); ?>">
                                <?php wp_nonce_field( 'rmwc_asset_delete_' . (int) $row['id'], 'rmwc_v2_asset_delete_nonce' ); ?>
                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'patsch9-rental-engine' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function save_asset() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No permission.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $nonce = isset( $_POST['rmwc_v2_asset_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_v2_asset_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_asset_save' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        $asset_id   = isset( $_POST['asset_id'] ) ? absint( wp_unslash( $_POST['asset_id'] ) ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        if ( 'yes' !== get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            $this->redirect( 'invalid_product' );
        }

        $inventory = isset( $_POST['inventory_number'] ) ? sanitize_text_field( wp_unslash( $_POST['inventory_number'] ) ) : '';
        $inventory = function_exists( 'mb_substr' ) ? mb_substr( trim( $inventory ), 0, 100 ) : substr( trim( $inventory ), 0, 100 );
        if ( '' === $inventory ) {
            $this->redirect( 'invalid_inventory' );
        }

        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'available';
        if ( ! isset( $this->statuses()[ $status ] ) ) {
            $status = 'available';
        }

        $purchase = $this->valid_date_or_null( isset( $_POST['purchase_date'] ) ? sanitize_text_field( wp_unslash( $_POST['purchase_date'] ) ) : '' );
        $service  = $this->valid_date_or_null( isset( $_POST['last_service_date'] ) ? sanitize_text_field( wp_unslash( $_POST['last_service_date'] ) ) : '' );
        $serial       = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
        $notes        = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        $known_damage = isset( $_POST['known_damage'] ) ? sanitize_textarea_field( wp_unslash( $_POST['known_damage'] ) ) : '';
        $serial       = function_exists( 'mb_substr' ) ? mb_substr( $serial, 0, 190 ) : substr( $serial, 0, 190 );
        $notes        = function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, 2000 ) : substr( $notes, 0, 2000 );
        $known_damage = function_exists( 'mb_substr' ) ? mb_substr( $known_damage, 0, 4000 ) : substr( $known_damage, 0, 4000 );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $duplicate = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE inventory_number = %s AND id <> %d LIMIT 1',
                $this->table(),
                $inventory,
                $asset_id
            )
        );
        if ( $duplicate ) {
            $this->redirect( 'duplicate', $asset_id );
        }

        if ( $asset_id ) {
            $existing_asset = $this->get_asset( $asset_id );
            if ( ! $existing_asset ) {
                wp_die( esc_html__( 'Device not found.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $history = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE asset_id = %d', $this->assignment_table(), $asset_id )
            );
            if ( $history > 0 && (int) $existing_asset['product_id'] !== $product_id ) {
                $this->redirect( 'history_product', $asset_id );
            }
        }

        $data = [
            'product_id'        => $product_id,
            'inventory_number'  => $inventory,
            'serial_number'     => $serial,
            'status'            => $status,
            'purchase_date'     => $purchase,
            'last_service_date' => $service,
            'notes'             => $notes,
            'known_damage'      => $known_damage,
            'updated_at'        => current_time( 'mysql', true ),
        ];
        $format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

        if ( $asset_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $result = $wpdb->update( $this->table(), $data, [ 'id' => $asset_id ], $format, [ '%d' ] );
        } else {
            $data['created_at'] = current_time( 'mysql', true );
            $format[] = '%s';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $result = $wpdb->insert( $this->table(), $data, $format );
        }
        if ( false === $result ) {
            $this->redirect( 'db_error', $asset_id );
        }
        $this->redirect( 'saved' );
    }

    public function delete_asset() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No permission.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $asset_id = isset( $_POST['asset_id'] ) ? absint( wp_unslash( $_POST['asset_id'] ) ) : 0;
        $nonce = isset( $_POST['rmwc_v2_asset_delete_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_v2_asset_delete_nonce'] ) ) : '';
        if ( ! $asset_id || ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_asset_delete_' . $asset_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $asset = $this->get_asset( $asset_id );
        if ( ! $asset ) {
            wp_die( esc_html__( 'Device not found.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $active = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE asset_id = %d AND status IN ('reserved','handed_over')",
                $this->assignment_table(),
                $asset_id
            )
        );
        if ( $active > 0 ) {
            $this->redirect( 'active_assignment', $asset_id );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $history = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE asset_id = %d', $this->assignment_table(), $asset_id )
        );
        if ( $history > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
            $result = $wpdb->update(
                $this->table(),
                [ 'status' => 'out_of_service', 'updated_at' => current_time( 'mysql', true ) ],
                [ 'id' => $asset_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            if ( false === $result ) {
                $this->redirect( 'db_error', $asset_id );
            }
            $this->redirect( 'archived' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned operational tables require current state; WordPress provides no CRUD API for these tables.
        $result = $wpdb->delete( $this->table(), [ 'id' => $asset_id ], [ '%d' ] );
        if ( false === $result ) {
            $this->redirect( 'db_error', $asset_id );
        }
        $this->redirect( 'deleted' );
    }

    private function redirect( $notice, $asset_id = 0 ) {
        $args = [ 'page' => 'rmwc-rentals', 'tab' => 'inventory', 'rmwc_asset_notice' => sanitize_key( $notice ) ];
        if ( $asset_id ) {
            $args['asset_id'] = absint( $asset_id );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function valid_date_or_null( $raw ) {
        $value = sanitize_text_field( (string) $raw );
        if ( '' === $value ) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
        return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
    }
}
