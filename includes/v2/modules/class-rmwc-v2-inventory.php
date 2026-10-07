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
        return $wpdb->prefix . 'clr_assets';
    }

    private function assignment_table() {
        global $wpdb;
        return $wpdb->prefix . 'clr_asset_assignments';
    }

    private function statuses() {
        return [
            'available'      => __( 'Verfügbar', 'patsch9-rental-engine' ),
            'rented'         => __( 'Vermietet', 'patsch9-rental-engine' ),
            'cleaning'       => __( 'Reinigung / Prüfung', 'patsch9-rental-engine' ),
            'maintenance'    => __( 'Wartung', 'patsch9-rental-engine' ),
            'defect'         => __( 'Defekt', 'patsch9-rental-engine' ),
            'out_of_service' => __( 'Außer Betrieb', 'patsch9-rental-engine' ),
        ];
    }

    public function product_field( $product_id ) {
        woocommerce_wp_checkbox(
            [
                'id'          => '_clr_asset_tracking',
                'wrapper_class' => 'clr-rental-field-row',
                'label'       => __( 'Einzelgeräte verwalten', 'patsch9-rental-engine' ),
                'value'       => get_post_meta( $product_id, '_clr_asset_tracking', true ),
                'description' => __( 'Reservierungen werden automatisch konkreten Geräten mit Inventar-/Seriennummer zugeordnet. Die Stammdaten werden unter WooCommerce → Vermietungen → Geräte gepflegt.', 'patsch9-rental-engine' ),
                'desc_tip'    => true,
            ]
        );
    }

    public function save_product_field( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['clr_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clr_save_product_' . $post_id ) ) {
            return;
        }
        update_post_meta( $post_id, '_clr_asset_tracking', isset( $_POST['_clr_asset_tracking'] ) ? 'yes' : 'no' );
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
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
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
        $notice = isset( $_GET['clr_asset_notice'] ) ? sanitize_key( wp_unslash( $_GET['clr_asset_notice'] ) ) : '';
        if ( $notice ) {
            $messages = [
                'saved'              => [ 'success', __( 'Gerät gespeichert.', 'patsch9-rental-engine' ) ],
                'deleted'            => [ 'success', __( 'Unbenutztes Gerät wurde gelöscht.', 'patsch9-rental-engine' ) ],
                'archived'           => [ 'success', __( 'Das Gerät besitzt bereits Historie und wurde deshalb sicher auf „Außer Betrieb“ gesetzt statt aus alten Vorgängen zu verschwinden.', 'patsch9-rental-engine' ) ],
                'active_assignment'  => [ 'error', __( 'Das Gerät ist einer aktiven oder laufenden Vermietung zugeordnet und kann derzeit nicht gelöscht/archiviert werden.', 'patsch9-rental-engine' ) ],
                'history_product'    => [ 'error', __( 'Das Mietprodukt eines bereits verwendeten Geräts kann nicht nachträglich geändert werden. Lege dafür ein neues Gerät an.', 'patsch9-rental-engine' ) ],
                'duplicate'          => [ 'error', __( 'Diese Inventarnummer ist bereits vergeben.', 'patsch9-rental-engine' ) ],
                'invalid_product'    => [ 'error', __( 'Bitte ein gültiges Mietprodukt auswählen.', 'patsch9-rental-engine' ) ],
                'invalid_inventory'  => [ 'error', __( 'Bitte eine Inventarnummer angeben.', 'patsch9-rental-engine' ) ],
                'db_error'           => [ 'error', __( 'Die Geräteänderung konnte nicht sicher in der Datenbank gespeichert werden. Bitte erneut versuchen.', 'patsch9-rental-engine' ) ],
            ];
            if ( isset( $messages[ $notice ] ) ) {
                echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . ' inline"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
            }
        }

        ?>
        <div class="clr-v2-admin-card">
            <div class="clr-v2-card-heading">
                <div>
                    <h2><?php echo esc_html( $editing ? __( 'Gerät bearbeiten', 'patsch9-rental-engine' ) : __( 'Gerät anlegen', 'patsch9-rental-engine' ) ); ?></h2>
                    <p><?php esc_html_e( 'Inventarnummern bleiben dauerhaft mit der Miet-Historie verknüpft. Bereits verwendete Geräte werden beim Löschen deshalb archiviert statt aus vorhandenen Protokollen entfernt.', 'patsch9-rental-engine' ); ?></p>
                </div>
                <?php if ( $editing ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=inventory' ) ); ?>"><?php esc_html_e( 'Bearbeitung abbrechen', 'patsch9-rental-engine' ); ?></a>
                <?php endif; ?>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clr-v2-asset-form">
                <input type="hidden" name="action" value="rmwc_asset_save">
                <input type="hidden" name="asset_id" value="<?php echo esc_attr( $editing['id'] ?? 0 ); ?>">
                <?php wp_nonce_field( 'rmwc_asset_save', 'clr_v2_asset_nonce' ); ?>
                <div class="clr-v2-form-grid">
                    <p>
                        <label><strong><?php esc_html_e( 'Mietprodukt', 'patsch9-rental-engine' ); ?></strong><br>
                            <select class="wc-product-search" style="width:100%" name="product_id" data-placeholder="<?php esc_attr_e( 'Mietprodukt suchen…', 'patsch9-rental-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" required>
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
                    <p><label><strong><?php esc_html_e( 'Inventarnummer', 'patsch9-rental-engine' ); ?></strong><br><input type="text" name="inventory_number" maxlength="100" value="<?php echo esc_attr( $editing['inventory_number'] ?? '' ); ?>" required></label></p>
                    <p><label><strong><?php esc_html_e( 'Seriennummer', 'patsch9-rental-engine' ); ?></strong><br><input type="text" name="serial_number" maxlength="190" value="<?php echo esc_attr( $editing['serial_number'] ?? '' ); ?>"></label></p>
                    <p>
                        <label><strong><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></strong><br>
                            <select name="status">
                                <?php foreach ( $this->statuses() as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $editing['status'] ?? 'available', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </p>
                    <p><label><strong><?php esc_html_e( 'Kaufdatum', 'patsch9-rental-engine' ); ?></strong><br><input type="date" name="purchase_date" value="<?php echo esc_attr( $editing['purchase_date'] ?? '' ); ?>"></label></p>
                    <p><label><strong><?php esc_html_e( 'Letzte Wartung', 'patsch9-rental-engine' ); ?></strong><br><input type="date" name="last_service_date" value="<?php echo esc_attr( $editing['last_service_date'] ?? '' ); ?>"></label></p>
                </div>
                <div class="clr-v2-asset-textareas">
                    <p>
                        <label><strong><?php esc_html_e( 'Bekannte Beschädigungen / Gebrauchsspuren', 'patsch9-rental-engine' ); ?></strong><br>
                            <textarea name="known_damage" rows="4" maxlength="4000" style="width:100%"><?php echo esc_textarea( $editing['known_damage'] ?? '' ); ?></textarea>
                        </label>
                        <span class="description"><?php esc_html_e( 'Nur bereits bekannte Vorschäden eintragen. Sie werden bei der Übergabe angezeigt und unveränderlich in Übergabe- und Rückgabeprotokollen dokumentiert.', 'patsch9-rental-engine' ); ?></span>
                    </p>
                    <p><label><strong><?php esc_html_e( 'Interne Notiz', 'patsch9-rental-engine' ); ?></strong><br><textarea name="notes" rows="4" maxlength="2000" style="width:100%"><?php echo esc_textarea( $editing['notes'] ?? '' ); ?></textarea></label></p>
                </div>
                <?php submit_button( $editing ? __( 'Änderungen speichern', 'patsch9-rental-engine' ) : __( 'Gerät anlegen', 'patsch9-rental-engine' ), 'primary', 'submit', false ); ?>
            </form>
        </div>

        <div class="clr-v2-admin-card">
            <h2><?php esc_html_e( 'Inventar', 'patsch9-rental-engine' ); ?></h2>
            <table class="widefat striped clr-assets-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Produkt', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Inventar', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Seriennummer', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Bekannte Schäden', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Wartung', 'patsch9-rental-engine' ); ?></th>
                    <th><?php esc_html_e( 'Aktionen', 'patsch9-rental-engine' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'Noch keine Geräte erfasst.', 'patsch9-rental-engine' ); ?></td></tr>
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
                        <td class="clr-row-actions">
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=clr-rentals&tab=inventory&asset_id=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'patsch9-rental-engine' ); ?></a>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Gerät wirklich löschen? Bereits verwendete Geräte werden aus Sicherheitsgründen nur archiviert.', 'patsch9-rental-engine' ) ); ?>');">
                                <input type="hidden" name="action" value="rmwc_asset_delete">
                                <input type="hidden" name="asset_id" value="<?php echo esc_attr( $row['id'] ); ?>">
                                <?php wp_nonce_field( 'rmwc_asset_delete_' . (int) $row['id'], 'clr_v2_asset_delete_nonce' ); ?>
                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'Löschen', 'patsch9-rental-engine' ); ?></button>
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
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $nonce = isset( $_POST['clr_v2_asset_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_v2_asset_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_asset_save' ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }

        $asset_id   = isset( $_POST['asset_id'] ) ? absint( wp_unslash( $_POST['asset_id'] ) ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        if ( 'yes' !== get_post_meta( $product_id, '_clr_rental_enabled', true ) ) {
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
                wp_die( esc_html__( 'Gerät nicht gefunden.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
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
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $asset_id = isset( $_POST['asset_id'] ) ? absint( wp_unslash( $_POST['asset_id'] ) ) : 0;
        $nonce = isset( $_POST['clr_v2_asset_delete_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_v2_asset_delete_nonce'] ) ) : '';
        if ( ! $asset_id || ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_asset_delete_' . $asset_id ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $asset = $this->get_asset( $asset_id );
        if ( ! $asset ) {
            wp_die( esc_html__( 'Gerät nicht gefunden.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
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
        $args = [ 'page' => 'clr-rentals', 'tab' => 'inventory', 'clr_asset_notice' => sanitize_key( $notice ) ];
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
