<?php
/**
 * Deposit selection, ledger, receipts and refunds.
 *
 * Deposits are intentionally kept separate from invoice/revenue semantics.
 * The separate Lexware connector can consume the published hooks/meta without
 * being required by this plugin.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Deposits {
    private static $instance = null;
    private $creating_deposit_refund = false;
    private $store_api_callback_registered = false;
    private $admin_footer_forms = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $core = RMWC_Plugin::instance();
        remove_action( 'add_meta_boxes', [ $core, 'add_deposit_meta_box' ] );
        remove_action( 'woocommerce_process_shop_order_meta', [ $core, 'save_deposit_meta_box' ], 20 );

        add_action( 'rmwc_product_panel_after_handover', [ $this, 'product_fields' ], 10 );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields' ], 35 );
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'cart_item_data' ], 40, 3 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'canonicalize_cart_deposit_method' ], 40 );
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'checkout_choice' ], 5 );
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'checkout_update' ], 5 );
        add_action( 'wp_enqueue_scripts', [ $this, 'checkout_script' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'order_deposit_meta' ], 40, 2 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_classic_checkout_choice' ], 20 );
        // WooCommerce documents the Additional Checkout Fields API on woocommerce_init.
        add_action( 'woocommerce_init', [ $this, 'register_block_checkout_field' ], 40 );

        // WooCommerce may have fired woocommerce_blocks_loaded before V2 modules are
        // instantiated. Register immediately in that case; otherwise wait for the hook.
        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            $this->register_store_api_update_callback();
        } else {
            add_action( 'woocommerce_blocks_loaded', [ $this, 'register_store_api_update_callback' ] );
        }
        // Defensive fallback for unusual load orders. The method is idempotent, so
        // running it again after WooCommerce initialization is harmless.
        add_action( 'woocommerce_init', [ $this, 'register_store_api_update_callback' ], 5 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'store_api_checkout_update_order' ], 40, 2 );

        add_action( 'woocommerce_payment_complete', [ $this, 'sync_online_received' ], 40 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'sync_online_received' ], 40 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'sync_online_received' ], 40 );

        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 40 );
        add_action( 'admin_footer', [ $this, 'render_admin_footer_forms' ], 100 );
        add_action( 'admin_post_rmwc_deposit_receive', [ $this, 'admin_receive' ] );
        add_action( 'admin_post_rmwc_deposit_refund', [ $this, 'admin_refund' ] );
        add_action( 'admin_post_rmwc_deposit_clear_uncertain', [ $this, 'admin_clear_uncertain' ] );

        // Keep online deposit refunds visible in WooCommerce's refund ledger while
        // clearly separating them from taxable/invoiced service refunds.
        add_action( 'woocommerce_create_refund', [ $this, 'tag_deposit_refund' ], 1, 2 );
        add_filter( 'woocommerce_order_is_partially_refunded', [ $this, 'keep_deposit_refund_partial' ], 1, 3 );
        add_filter( 'woocommerce_email_enabled_customer_refunded_order', [ $this, 'suppress_core_refund_email' ], 1, 3 );
        add_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', [ $this, 'suppress_core_refund_email' ], 1, 3 );

        add_action( 'rmwc_render_admin_tab_settings_after', [ $this, 'settings_fields' ] );
        add_action( 'rmwc_save_v2_settings', [ $this, 'save_settings' ] );
        add_filter( 'wlc_payment_terms_addition', [ $this, 'filter_lexware_payment_terms_addition' ], 10, 2 );
    }

    public function product_fields( $product_id ) {
        $mode = sanitize_key( get_post_meta( $product_id, '_clr_deposit_mode', true ) ?: 'choice' );
        woocommerce_wp_select(
            [
                'id'          => '_clr_deposit_mode',
                'wrapper_class' => 'clr-rental-field-row',
                'label'       => __( 'Kautionshinterlegung', 'patsch9-rental-engine' ),
                'value'       => $mode,
                'options'     => [
                    'choice' => __( 'Bei Online-Zahlung Kunde wählen lassen', 'patsch9-rental-engine' ),
                    'online' => __( 'Bei Online-Zahlung immer direkt mit einziehen', 'patsch9-rental-engine' ),
                    'cash'   => __( 'Immer separat / bar bei Übergabe', 'patsch9-rental-engine' ),
                ],
                'description' => __( 'Bei als offline eingestuften Zahlungsarten wird die Kaution grundsätzlich nicht in den WooCommerce-Zahlbetrag aufgenommen.', 'patsch9-rental-engine' ),
                'desc_tip'    => true,
            ]
        );
    }

    public function save_product_fields( $post_id ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $nonce = isset( $_POST['clr_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_product_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clr_save_product_' . $post_id ) ) {
            return;
        }
        $mode = isset( $_POST['_clr_deposit_mode'] ) ? sanitize_key( wp_unslash( $_POST['_clr_deposit_mode'] ) ) : 'choice';
        update_post_meta( $post_id, '_clr_deposit_mode', in_array( $mode, [ 'choice', 'online', 'cash' ], true ) ? $mode : 'choice' );
        delete_transient( 'clr_v2_deposit_choice_product_ids' );
    }

    /**
     * Product IDs for which the customer must explicitly choose how to provide
     * the refundable security deposit. The list is used by Checkout Blocks'
     * JSON-Schema conditions, so field registration never depends on a cart
     * session already having been hydrated on the current request.
     *
     * @return int[]
     */
    private function deposit_choice_product_ids() {
        $cached = get_transient( 'clr_v2_deposit_choice_product_ids' );
        if ( is_array( $cached ) ) {
            return array_values( array_unique( array_filter( array_map( 'absint', $cached ) ) ) );
        }

        $ids = get_posts(
            [
                'post_type'              => 'product',
                'post_status'            => [ 'publish', 'private' ],
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small cached configuration lookup, invalidated when rental deposit settings are saved.
                    'relation' => 'AND',
                    [
                        'key'   => '_clr_rental_enabled',
                        'value' => 'yes',
                    ],
                    [
                        'key'   => '_clr_deposit_mode',
                        'value' => 'choice',
                    ],
                    [
                        'key'     => '_clr_deposit',
                        'value'   => 0,
                        'compare' => '>',
                        'type'    => 'NUMERIC',
                    ],
                ],
            ]
        );

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        set_transient( 'clr_v2_deposit_choice_product_ids', $ids, 12 * HOUR_IN_SECONDS );
        return $ids;
    }

    public function cart_item_data( $data, $product_id, $variation_id ) {
        if ( empty( $data['clr_rental'] ) ) {
            return $data;
        }
        $config_id = absint( $data['clr_rental']['product_id'] ?? ( $variation_id ?: $product_id ) );
        $mode = sanitize_key( get_post_meta( $config_id, '_clr_deposit_mode', true ) ?: 'choice' );
        $method = 'cash';
        if ( 'online' === $mode ) {
            $method = 'online';
        } elseif ( 'choice' === $mode && WC()->session ) {
            $session_choice = sanitize_key( (string) WC()->session->get( 'clr_v2_deposit_choice', '' ) );
            $method = in_array( $session_choice, [ 'online', 'cash' ], true ) ? $session_choice : 'cash';
        }
        $data['clr_rental']['deposit_mode']   = $mode;
        $data['clr_rental']['deposit_method'] = $method;
        $data['unique_key'] = md5( wp_json_encode( $data['clr_rental'] ) . microtime( true ) );
        return $data;
    }

    private function offline_gateway_ids() {
        $stored = get_option( 'clr_v2_offline_gateway_ids', 'bacs,cheque,cod' );
        $ids = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,;]+/', (string) $stored ) ?: [] ) ) );
        return apply_filters( 'rmwc_offline_gateway_ids', $ids );
    }

    private function selected_gateway_id() {
        if ( WC()->session ) {
            $id = sanitize_key( (string) WC()->session->get( 'chosen_payment_method', '' ) );
            if ( $id ) {
                return $id;
            }
        }
        return '';
    }

    private function gateway_is_online( $gateway_id ) {
        $gateway_id = sanitize_key( $gateway_id );
        return '' !== $gateway_id && ! in_array( $gateway_id, $this->offline_gateway_ids(), true );
    }

    private function effective_method_for_mode( $mode ) {
        $mode = sanitize_key( $mode );
        if ( 'cash' === $mode ) {
            return 'cash';
        }
        $gateway_id = $this->selected_gateway_id();
        if ( ! $this->gateway_is_online( $gateway_id ) ) {
            return 'cash';
        }
        if ( 'online' === $mode ) {
            return 'online';
        }
        $choice = WC()->session ? sanitize_key( (string) WC()->session->get( 'clr_v2_deposit_choice', '' ) ) : '';
        return 'online' === $choice ? 'online' : 'cash';
    }

    public function canonicalize_cart_deposit_method( $cart ) {
        if ( ! $cart instanceof WC_Cart ) {
            return;
        }
        foreach ( $cart->get_cart() as $cart_item_key => $item ) {
            if ( empty( $item['clr_rental'] ) ) {
                continue;
            }
            $product_id = absint( $item['clr_rental']['product_id'] ?? 0 );
            $mode = sanitize_key( get_post_meta( $product_id, '_clr_deposit_mode', true ) ?: 'choice' );
            $cart->cart_contents[ $cart_item_key ]['clr_rental']['deposit_mode']   = $mode;
            $cart->cart_contents[ $cart_item_key ]['clr_rental']['deposit_method'] = $this->effective_method_for_mode( $mode );
        }
    }

    private function cart_deposit_summary() {
        if ( ! WC()->cart ) {
            return [ 'required' => 0.0, 'choice' => false, 'forced_online' => false ];
        }
        $required = 0.0;
        $choice = false;
        $forced_online = false;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['clr_rental'] ) ) {
                continue;
            }
            $r = $item['clr_rental'];
            $qty = max( 1, absint( $item['quantity'] ?? 1 ) );
            $deposit = max( 0, (float) ( $r['deposit'] ?? 0 ) ) * $qty;
            if ( $deposit <= 0 ) {
                continue;
            }
            $required += $deposit;
            $mode = sanitize_key( $r['deposit_mode'] ?? 'choice' );
            $choice = $choice || 'choice' === $mode;
            $forced_online = $forced_online || 'online' === $mode;
        }
        return compact( 'required', 'choice', 'forced_online' );
    }

    public function checkout_choice() {
        $summary = $this->cart_deposit_summary();
        if ( $summary['required'] <= 0 || ! $summary['choice'] ) {
            return;
        }
        $choice = WC()->session ? sanitize_key( (string) WC()->session->get( 'clr_v2_deposit_choice', '' ) ) : '';
        $offline_ids = implode( ',', $this->offline_gateway_ids() );
        ?>
        <div class="clr-v2-checkout-deposit" data-offline-gateways="<?php echo esc_attr( $offline_ids ); ?>">
            <p><strong>
                <?php
                /* translators: %s: formatted refundable deposit amount. */
                echo esc_html( sprintf( __( 'Kaution: %s', 'patsch9-rental-engine' ), wp_strip_all_tags( wc_price( $summary['required'] ) ) ) );
                ?>
            </strong></p>
            <div class="clr-v2-deposit-choice-options">
                <label><input type="radio" name="clr_v2_deposit_choice" value="online" <?php checked( $choice, 'online' ); ?>> <?php esc_html_e( 'Kaution jetzt zusammen mit der Online-Zahlung hinterlegen', 'patsch9-rental-engine' ); ?></label><br>
                <label><input type="radio" name="clr_v2_deposit_choice" value="cash" <?php checked( $choice, 'cash' ); ?>> <?php esc_html_e( 'Kaution separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' ); ?></label>
            </div>
            <p class="description clr-v2-deposit-offline-note" hidden><?php esc_html_e( 'Für die aktuell gewählte Zahlungsart wird die Kaution nicht online eingezogen. Sie ist gemäß Mietbedingungen separat zu hinterlegen.', 'patsch9-rental-engine' ); ?></p>
            <p class="description clr-v2-deposit-online-note"><?php esc_html_e( 'Online hinterlegte Kautionen können nach der Rückgabe über die ursprüngliche Zahlungsart zurückgezahlt werden, sofern das Zahlungs-Gateway Rückerstattungen unterstützt.', 'patsch9-rental-engine' ); ?></p>
        </div>
        <?php
    }

    /**
     * Whether the current cart contains at least one rental with a customer-selectable deposit.
     */
    private function cart_has_deposit_choice() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['clr_rental'] ) ) {
                continue;
            }
            $product_id = absint( $item['clr_rental']['product_id'] ?? 0 );
            if ( $product_id && (float) get_post_meta( $product_id, '_clr_deposit', true ) > 0 && 'choice' === sanitize_key( get_post_meta( $product_id, '_clr_deposit_mode', true ) ?: 'choice' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Register the customer choice through WooCommerce's official Additional Checkout Fields API.
     * This is used by the Checkout Block; the classic checkout keeps its native radio UI.
     */
    public function register_block_checkout_field() {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return;
        }

        $product_ids = $this->deposit_choice_product_ids();
        if ( $product_ids ) {
            $cart_contains_choice_product = [
                'cart' => [
                    'properties' => [
                        'items' => [
                            'contains' => [
                                'enum' => $product_ids,
                            ],
                        ],
                    ],
                ],
            ];
            $cart_has_no_choice_product = [
                'cart' => [
                    'properties' => [
                        'items' => [
                            'not' => [
                                'contains' => [
                                    'enum' => $product_ids,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
            $required = $cart_contains_choice_product;
            $hidden   = $cart_has_no_choice_product;
        } else {
            // No currently configured product can require this field.
            $required = false;
            $hidden   = true;
        }

        woocommerce_register_additional_checkout_field(
            [
                'id'            => 'patsch9-rental-engine/deposit-choice',
                'label'         => __( 'Kaution hinterlegen', 'patsch9-rental-engine' ),
                'optionalLabel' => __( 'Kaution hinterlegen', 'patsch9-rental-engine' ),
                'location'      => 'order',
                'type'          => 'select',
                'required'      => $required,
                'hidden'        => $hidden,
                'placeholder'   => __( 'Bitte auswählen …', 'patsch9-rental-engine' ),
                'options'       => [
                    [ 'value' => 'cash', 'label' => __( 'Separat überweisen oder bei Abholung bar hinterlegen', 'patsch9-rental-engine' ) ],
                    [ 'value' => 'online', 'label' => __( 'Jetzt zusammen mit der Online-Zahlung hinterlegen', 'patsch9-rental-engine' ) ],
                ],
                'sanitize_callback' => static function( $value ) {
                    $value = sanitize_key( (string) $value );
                    return in_array( $value, [ 'cash', 'online' ], true ) ? $value : '';
                },
                'validate_callback' => static function( $value ) {
                    $value = sanitize_key( (string) $value );
                    if ( '' === $value ) {
                        // Conditional `required` handles the empty value only when
                        // a matching rental product is actually in the cart.
                        return null;
                    }
                    if ( ! in_array( $value, [ 'cash', 'online' ], true ) ) {
                        return new WP_Error( 'rmwc_invalid_deposit_choice', __( 'Bitte wählen Sie eine gültige Art der Kautionshinterlegung.', 'patsch9-rental-engine' ) );
                    }
                    return null;
                },
            ]
        );
    }

    /** Register the Store API cart-update callback used by Checkout Blocks. */
    public function register_store_api_update_callback() {
        if ( $this->store_api_callback_registered || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
            return;
        }

        woocommerce_store_api_register_update_callback(
            [
                'namespace' => 'patsch9-rental-engine',
                'callback'  => function( $data ) {
                    if ( ! is_array( $data ) || ! array_key_exists( 'deposit_choice', $data ) || ! WC()->session ) {
                        return;
                    }
                    $choice = sanitize_key( (string) $data['deposit_choice'] );
                    if ( '' !== $choice && ! in_array( $choice, [ 'cash', 'online' ], true ) ) {
                        return;
                    }
                    $gateway = isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '';
                    WC()->session->set( 'clr_v2_deposit_choice', $choice );
                    if ( $gateway ) {
                        WC()->session->set( 'chosen_payment_method', $gateway );
                    }
                    if ( WC()->cart ) {
                        $this->canonicalize_cart_deposit_method( WC()->cart );
                    }
                },
            ]
        );
        $this->store_api_callback_registered = true;
    }

    /**
     * Persist Block Checkout choice and the same canonical deposit metadata as classic checkout.
     */
    public function store_api_checkout_update_order( $order, $request ) {
        if ( ! $order instanceof WC_Order || ! $request instanceof WP_REST_Request ) {
            return;
        }
        $fields = $request->get_param( 'additional_fields' );
        $choice = is_array( $fields ) && isset( $fields['patsch9-rental-engine/deposit-choice'] )
            ? sanitize_key( (string) $fields['patsch9-rental-engine/deposit-choice'] )
            : '';
        $gateway = sanitize_key( (string) $request->get_param( 'payment_method' ) );
        if ( WC()->session ) {
            if ( in_array( $choice, [ 'cash', 'online' ], true ) ) {
                WC()->session->set( 'clr_v2_deposit_choice', $choice );
            }
            if ( $gateway ) {
                WC()->session->set( 'chosen_payment_method', $gateway );
            }
        }
        if ( WC()->cart ) {
            $this->canonicalize_cart_deposit_method( WC()->cart );
            $this->persist_order_deposit_meta( $order );
        }
    }

    public function checkout_update( $post_data ) {
        if ( ! WC()->session || ! is_string( $post_data ) ) {
            return;
        }
        parse_str( $post_data, $data );
        $gateway = isset( $data['payment_method'] ) ? sanitize_key( $data['payment_method'] ) : '';
        if ( $gateway ) {
            WC()->session->set( 'chosen_payment_method', $gateway );
        }
        $choice = isset( $data['clr_v2_deposit_choice'] ) ? sanitize_key( $data['clr_v2_deposit_choice'] ) : '';
        WC()->session->set( 'clr_v2_deposit_choice', in_array( $choice, [ 'online', 'cash' ], true ) ? $choice : '' );
    }

    /** Enforce an explicit deposit choice in the classic checkout. */
    public function validate_classic_checkout_choice() {
        if ( ! $this->cart_has_deposit_choice() ) {
            return;
        }
        $choice = isset( $_POST['clr_v2_deposit_choice'] ) ? sanitize_key( wp_unslash( $_POST['clr_v2_deposit_choice'] ) ) : '';
        if ( ! in_array( $choice, [ 'online', 'cash' ], true ) ) {
            wc_add_notice( __( 'Bitte wählen Sie, wie die Kaution hinterlegt werden soll.', 'patsch9-rental-engine' ), 'error' );
        }
    }

    public function checkout_script() {
        if ( ! is_checkout() || is_order_received_page() ) {
            return;
        }
        wp_enqueue_script( 'wc-checkout' );
        wp_add_inline_script(
            'wc-checkout',
            'jQuery(function($){function clrDepositGateway(){var $box=$(".clr-v2-checkout-deposit");if(!$box.length)return;var ids=String($box.data("offline-gateways")||"").split(",");var gateway=$("input[name=payment_method]:checked").val()||"";var offline=gateway&&ids.indexOf(gateway)!==-1;var $online=$box.find("input[value=online]");$box.find(".clr-v2-deposit-offline-note").prop("hidden",!offline);$box.find(".clr-v2-deposit-online-note").prop("hidden",offline);$online.prop("disabled",offline);if(offline&&$online.is(":checked")){$online.prop("checked",false);}}$(document.body).on("change","input[name=clr_v2_deposit_choice],input[name=payment_method]",function(){clrDepositGateway();$(document.body).trigger("update_checkout");});$(document.body).on("updated_checkout",clrDepositGateway);clrDepositGateway();});'
        );

        if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) && $this->cart_has_deposit_choice() ) {
            $handle = 'patsch9-rental-engine-checkout-blocks';
            wp_enqueue_script(
                $handle,
                RMWC_URL . 'assets/checkout-blocks.js',
                [ 'wp-data', 'wc-blocks-checkout', 'wc-blocks-data-store' ],
                RMWC_VERSION,
                true
            );
            wp_localize_script(
                $handle,
                'RMWCCheckoutBlocks',
                [
                    'offlineGateways' => $this->offline_gateway_ids(),
                ]
            );
        }
    }

    private function persist_order_deposit_meta( WC_Order $order ) {
        if ( ! WC()->cart ) {
            return;
        }
        $required = 0.0;
        $online   = 0.0;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['clr_rental'] ) ) {
                continue;
            }
            $r      = $item['clr_rental'];
            $qty    = max( 1, absint( $item['quantity'] ?? 1 ) );
            $amount = max( 0, (float) ( $r['deposit'] ?? 0 ) ) * $qty;
            $required += $amount;
            if ( 'online' === sanitize_key( $r['deposit_method'] ?? 'cash' ) ) {
                $online += $amount;
            }
        }
        if ( $required <= 0 ) {
            return;
        }
        $order->update_meta_data( '_clr_deposit_required_total', wc_format_decimal( $required, wc_get_price_decimals() ) );
        $order->update_meta_data( '_clr_deposit_online_expected', wc_format_decimal( $online, wc_get_price_decimals() ) );
        $order->update_meta_data( '_clr_v2_deposit_ledger', 'enabled' );
        $order->update_meta_data( '_clr_deposit_checkout_choice', $online > 0 ? 'online' : 'cash' );

        $order->update_meta_data(
            '_clr_invoice_payment_terms_addition',
            $this->build_lexware_deposit_terms( $order, $required, $online )
        );
    }

    public function order_deposit_meta( $order, $data ) {
        unset( $data );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $this->persist_order_deposit_meta( $order );
    }

    /**
     * Return the total refundable deposit required for an order.
     *
     * New V2 orders persist the total in order meta. For orders created by
     * earlier plugin builds, rebuild it from the immutable rental line-item
     * snapshot so the admin screen remains usable after an update.
     */
    private function required_total( WC_Order $order ) {
        $stored = max( 0, (float) $order->get_meta( '_clr_deposit_required_total', true ) );
        if ( $stored > 0 ) {
            return $stored;
        }

        $required = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $rental = $item->get_meta( '_clr_rental_data', true );
            if ( ! is_array( $rental ) ) {
                continue;
            }
            $qty = max( 1, (int) $item->get_quantity() );
            $required += max( 0, (float) ( $rental['deposit'] ?? 0 ) ) * $qty;
        }

        // Very old online-deposit orders may only expose the deposit as a fee.
        return max( $required, $this->fee_deposit_total( $order ) );
    }

    /**
     * Sum the refundable deposit fees actually included in WooCommerce payment.
     */
    private function fee_deposit_total( WC_Order $order ) {
        $total = 0.0;
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            $is_deposit = 'yes' === $fee->get_meta( '_clr_deposit', true )
                || 'deposit' === sanitize_key( (string) $fee->get_meta( '_clr_fee_type', true ) )
                || 0 === strpos( (string) $fee->get_name(), 'Kaution – ' );
            if ( $is_deposit ) {
                $total += max( 0, (float) $fee->get_total() );
            }
        }
        return $total;
    }

    /**
     * Sum immutable deposit documents for the order, optionally by method.
     */
    private function document_sum( WC_Order $order, $type, $method = '' ) {
        $type   = sanitize_key( $type );
        $method = sanitize_key( $method );
        $sum    = 0.0;

        foreach ( RMWC_V2_Documents::instance()->documents_for_order( $order->get_id() ) as $document ) {
            if ( $type !== sanitize_key( (string) ( $document['type'] ?? '' ) ) ) {
                continue;
            }
            if ( '' !== $method ) {
                $snapshot = json_decode( (string) ( $document['snapshot'] ?? '' ), true );
                if ( ! is_array( $snapshot ) || $method !== sanitize_key( (string) ( $snapshot['method'] ?? '' ) ) ) {
                    continue;
                }
            }
            $sum += max( 0, (float) ( $document['amount'] ?? 0 ) );
        }

        return $sum;
    }

    /**
     * Format a WooCommerce price for plain-text integrations such as Lexware.
     */
    private function plain_price( $amount, WC_Order $order ) {
        $text = wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = str_replace( "\xc2\xa0", ' ', $text );
        return trim( preg_replace( '/[ \t]+/u', ' ', $text ) ?: $text );
    }

    /**
     * Build the optional Lexware payment-terms addition for a rental order.
     */
    private function build_lexware_deposit_terms( WC_Order $order, $required, $online ) {
        $required = max( 0, (float) $required );
        $online   = min( $required, max( 0, (float) $online ) );
        if ( $required <= 0 ) {
            return '';
        }

        $required_text = $this->plain_price( $required, $order );

        if ( $online >= $required - 0.0001 ) {
            return sanitize_textarea_field(
                sprintf(
                    /* translators: %s: formatted refundable deposit amount already collected online. */
                    __( 'Die Kaution von %s wurde bereits mit der Online-Zahlung hinterlegt und gehört nicht zur Rechnungssumme. Der QR-Code dieser Rechnung enthält nur den Rechnungsbetrag. Die Rückerstattung erfolgt gemäß Mietbedingungen.', 'patsch9-rental-engine' ),
                    $required_text
                )
            );
        }

        if ( $online > 0.0001 ) {
            $remaining = max( 0, $required - $online );
            return sanitize_textarea_field(
                sprintf(
                    /* translators: 1: total refundable deposit, 2: amount collected online, 3: amount still to be deposited separately. */
                    __( 'Die Kaution beträgt %1$s. %2$s wurden bereits online hinterlegt; %3$s sind separat zu überweisen oder bei Abholung bar zu hinterlegen. Der QR-Code dieser Rechnung enthält nur den Rechnungsbetrag. Die Rückerstattung erfolgt gemäß Mietbedingungen.', 'patsch9-rental-engine' ),
                    $required_text,
                    $this->plain_price( $online, $order ),
                    $this->plain_price( $remaining, $order )
                )
            );
        }

        return sanitize_textarea_field(
            sprintf(
                /* translators: %s: formatted refundable deposit amount to be deposited separately. */
                __( 'Gemäß Mietbedingungen ist zusätzlich eine Kaution von %s zu hinterlegen. Sie kann separat überwiesen oder bei Abholung bar hinterlegt werden. Der QR-Code dieser Rechnung enthält nur den Rechnungsbetrag; die Kaution ist darin nicht enthalten. Die Rückerstattung erfolgt gemäß Mietbedingungen.', 'patsch9-rental-engine' ),
                $required_text
            )
        );
    }

    /**
     * Let compatible optional invoice integrations request a fresh payment
     * terms addition. This keeps older WooCommerce orders readable without
     * rewriting their historic order metadata.
     */
    public function filter_lexware_payment_terms_addition( $addition, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $addition;
        }

        $required = $this->required_total( $order );
        if ( $required <= 0 ) {
            return $addition;
        }

        $online = max( 0, (float) $order->get_meta( '_clr_deposit_online_expected', true ) );
        return $this->build_lexware_deposit_terms( $order, $required, $online );
    }

    public function received_total( WC_Order $order ) {
        return $this->document_sum( $order, 'deposit_received' );
    }

    public function refunded_total( WC_Order $order ) {
        $manual = $this->document_sum( $order, 'deposit_refund', 'manual' );
        return $manual + $this->online_refunded_total( $order );
    }

    /**
     * Amount finally retained from the refundable deposit. A retention closes
     * that part of the deposit and must no longer be offered for refund.
     */
    public function retained_total( WC_Order $order ) {
        $retained = max( 0, (float) $order->get_meta( '_clr_deposit_retained', true ) );
        foreach ( RMWC_V2_Documents::instance()->documents_for_order( $order->get_id() ) as $document ) {
            if ( 'deposit_refund' !== sanitize_key( (string) ( $document['type'] ?? '' ) ) ) {
                continue;
            }
            $snapshot = json_decode( (string) ( $document['snapshot'] ?? '' ), true );
            if ( is_array( $snapshot ) ) {
                $retained = max( $retained, max( 0, (float) ( $snapshot['retained'] ?? 0 ) ) );
            }
        }
        return $retained;
    }

    /**
     * Current security-deposit state for admin/workflow UIs.
     */
    public function state( WC_Order $order ) {
        $required  = max( 0, $this->required_total( $order ) );
        $received  = max( 0, $this->received_total( $order ) );
        $refunded  = max( 0, $this->refunded_total( $order ) );
        $retained  = max( 0, $this->retained_total( $order ) );
        $available = max( 0, $received - $refunded - $retained );
        $missing   = max( 0, $required - $received );
        $online_available = min( $available, max( 0, $this->online_received_total( $order ) - $this->online_refunded_total( $order ) ) );

        return [
            'required'         => $required,
            'received'         => $received,
            'refunded'         => $refunded,
            'retained'         => $retained,
            'available'        => $available,
            'missing'          => $missing,
            'online_available' => $online_available,
            'uncertain'        => (bool) $order->get_meta( '_clr_deposit_refund_uncertain', true ),
        ];
    }

    /**
     * Record a separately received deposit from the handover workflow.
     *
     * This intentionally supports only receipt methods that do not move money
     * through a payment gateway. Online deposits are synchronized separately
     * from WooCommerce payment completion.
     *
     * @return int|WP_Error Deposit receipt document ID or error.
     */
    public function workflow_receive( WC_Order $order, $amount, $method ) {
        $method = sanitize_key( (string) $method );
        if ( ! in_array( $method, [ 'cash', 'transfer', 'other' ], true ) ) {
            return new WP_Error( 'clr_deposit_method', __( 'Ungültige Art der Kautionshinterlegung.', 'patsch9-rental-engine' ) );
        }

        $lock = $this->acquire_order_lock( $order );
        if ( ! $lock ) {
            return new WP_Error( 'clr_deposit_busy', __( 'Die Kaution wird gerade in einem anderen Vorgang bearbeitet. Bitte erneut versuchen.', 'patsch9-rental-engine' ) );
        }

        try {
            $state   = $this->state( $order );
            $missing = max( 0, (float) $state['missing'] );
            $amount  = min( $missing, max( 0, (float) $amount ) );
            if ( $amount <= 0 ) {
                return new WP_Error( 'clr_deposit_amount', __( 'Es ist keine offene Kaution vorhanden bzw. der Betrag ist ungültig.', 'patsch9-rental-engine' ) );
            }

            $labels = [
                'cash'     => __( 'Bar', 'patsch9-rental-engine' ),
                'transfer' => __( 'Separate Überweisung', 'patsch9-rental-engine' ),
                'other'    => __( 'Sonstige Hinterlegung', 'patsch9-rental-engine' ),
            ];
            $snapshot = [
                'order_number' => $order->get_order_number(),
                'date'          => wp_date( 'd.m.Y H:i' ),
                'customer_name' => $order->get_formatted_billing_full_name(),
                'method'        => $method,
                'method_label'  => $labels[ $method ],
                'remaining'     => max( 0, $missing - $amount ),
                'source'        => 'handover_workflow',
            ];
            $document_id = RMWC_V2_Documents::instance()->create_document(
                $order,
                'deposit_received',
                $snapshot,
                $amount,
                'workflow_received_' . wp_generate_uuid4()
            );
            if ( is_wp_error( $document_id ) ) {
                return $document_id;
            }

            RMWC_V2_Documents::instance()->send_document_email(
                $order,
                (int) $document_id,
                __( 'Kautionsquittung zu Ihrer Vermietung', 'patsch9-rental-engine' ),
                __( 'Kaution erhalten', 'patsch9-rental-engine' ),
                __( 'Wir bestätigen den Erhalt Ihrer Kaution. Die Quittung finden Sie im Anhang.', 'patsch9-rental-engine' )
            );
            $order->add_order_note(
                sprintf(
                    'Kaution im Übergabeprozess erhalten: %s (%s).',
                    wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) ),
                    $labels[ $method ]
                )
            );
            do_action( 'rmwc_deposit_saved', $order, 'open', 'received', 0, 0 );
            return (int) $document_id;
        } finally {
            $this->release_order_lock( $lock );
        }
    }

    /**
     * Record a manual/non-gateway deposit refund from the return workflow.
     *
     * Gateway refunds remain in the order-side deposit box because an
     * ambiguous provider response must be handled with the dedicated lockout
     * workflow there.
     *
     * @return int|WP_Error Deposit refund document ID or error.
     */
    public function workflow_manual_refund( WC_Order $order, $amount, $retention_reason = 'none', $retention_note = '' ) {
        $lock = $this->acquire_order_lock( $order );
        if ( ! $lock ) {
            return new WP_Error( 'clr_deposit_busy', __( 'Die Kaution wird gerade in einem anderen Vorgang bearbeitet. Bitte erneut versuchen.', 'patsch9-rental-engine' ) );
        }

        try {
            $state     = $this->state( $order );
            $available = max( 0, (float) $state['available'] );
            if ( $available <= 0 ) {
                return new WP_Error( 'clr_deposit_refund_amount', __( 'Es ist keine hinterlegte Kaution mehr vorhanden.', 'patsch9-rental-engine' ) );
            }
            $amount    = min( $available, max( 0, (float) $amount ) );
            $remaining = max( 0, $available - $amount );

            $retention_reason = sanitize_key( (string) $retention_reason );
            $allowed_reasons  = [ 'none', 'pending', 'damage', 'cleaning', 'loss', 'other' ];
            if ( ! in_array( $retention_reason, $allowed_reasons, true ) ) {
                return new WP_Error( 'clr_deposit_retention_reason', __( 'Ungültiger Grund für den verbleibenden Kautionsbetrag.', 'patsch9-rental-engine' ) );
            }
            if ( $remaining > 0.0001 && 'none' === $retention_reason ) {
                return new WP_Error( 'clr_deposit_retention_reason', __( 'Bei einer Teilrückzahlung muss angegeben werden, was mit dem verbleibenden Kautionsbetrag geschieht.', 'patsch9-rental-engine' ) );
            }
            if ( $remaining <= 0.0001 ) {
                $retention_reason = 'none';
            }

            $retention_note = substr( sanitize_textarea_field( (string) $retention_note ), 0, 700 );
            $reason_labels = [
                'pending'  => __( 'Rückzahlung des Restbetrags erfolgt später / separat', 'patsch9-rental-engine' ),
                'damage'   => __( 'Einbehalt wegen Beschädigung', 'patsch9-rental-engine' ),
                'cleaning' => __( 'Einbehalt wegen Reinigung / Verschmutzung', 'patsch9-rental-engine' ),
                'loss'     => __( 'Einbehalt wegen Verlust / Nichtrückgabe', 'patsch9-rental-engine' ),
                'other'    => __( 'Sonstiger Einbehalt', 'patsch9-rental-engine' ),
            ];
            $reason_text = $reason_labels[ $retention_reason ] ?? '';
            if ( $reason_text && $retention_note ) {
                $reason_text .= ': ' . $retention_note;
            }

            $is_retention = $remaining > 0.0001 && in_array( $retention_reason, [ 'damage', 'cleaning', 'loss', 'other' ], true );
            $retained     = $is_retention ? $remaining : 0.0;
            if ( $is_retention ) {
                $order->update_meta_data( '_clr_deposit_retained', wc_format_decimal( $retained, wc_get_price_decimals() ) );
                $order->update_meta_data( '_clr_deposit_retention_reason', $retention_reason );
                $order->update_meta_data( '_clr_deposit_note', $retention_note );
                $order->save();
            }

            // If nothing was paid out and the remainder is merely pending, no
            // refund receipt is created. The return protocol still records the
            // open deposit balance and the order remains refundable later.
            if ( $amount <= 0 && 'pending' === $retention_reason ) {
                $order->add_order_note( __( 'Mietkaution bei Rückgabe noch nicht zurückgezahlt; Rückzahlung des Restbetrags erfolgt später / separat.', 'patsch9-rental-engine' ) );
                return 0;
            }

            $snapshot = [
                'order_number' => $order->get_order_number(),
                'date'          => wp_date( 'd.m.Y H:i' ),
                'customer_name' => $order->get_formatted_billing_full_name(),
                'method'        => 'manual',
                'method_label'  => __( 'Bar / manuell / separat', 'patsch9-rental-engine' ),
                'remaining'     => $is_retention ? 0 : $remaining,
                'retained'      => $retained,
                'reason'        => $reason_text,
                'source'        => 'return_workflow',
            ];
            $document_id = RMWC_V2_Documents::instance()->create_document(
                $order,
                'deposit_refund',
                $snapshot,
                $amount,
                'workflow_refund_' . wp_generate_uuid4()
            );
            if ( is_wp_error( $document_id ) ) {
                return $document_id;
            }

            RMWC_V2_Documents::instance()->send_document_email(
                $order,
                (int) $document_id,
                $is_retention ? __( 'Abrechnung Ihrer Mietkaution', 'patsch9-rental-engine' ) : __( 'Rückzahlung Ihrer Mietkaution', 'patsch9-rental-engine' ),
                $is_retention ? __( 'Kautionsabrechnung', 'patsch9-rental-engine' ) : __( 'Kaution zurückgezahlt', 'patsch9-rental-engine' ),
                $is_retention
                    ? __( 'Im Anhang erhalten Sie die Abrechnung Ihrer Mietkaution mit der dokumentierten Rückzahlung und dem Einbehalt.', 'patsch9-rental-engine' )
                    : __( 'Wir bestätigen die Rückzahlung Ihrer Kaution bzw. Teilkaution. Die Rückzahlungsquittung finden Sie im Anhang.', 'patsch9-rental-engine' )
            );
            $note = sprintf(
                'Mietkaution im Rückgabeprozess zurückgezahlt: %s.',
                wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) )
            );
            if ( $is_retention ) {
                $note .= ' Einbehalt: ' . wp_strip_all_tags( wc_price( $retained, [ 'currency' => $order->get_currency() ] ) ) . ( $reason_text ? ' – ' . $reason_text : '' ) . '.';
            } elseif ( 'pending' === $retention_reason && $remaining > 0 ) {
                $note .= ' Noch offen: ' . wp_strip_all_tags( wc_price( $remaining, [ 'currency' => $order->get_currency() ] ) ) . '.';
            }
            $order->add_order_note( $note );
            do_action( 'rmwc_deposit_saved', $order, 'received', 'refunded', $amount, $retained );
            return (int) $document_id;
        } finally {
            $this->release_order_lock( $lock );
        }
    }

    private function online_received_total( WC_Order $order ) {
        return $this->document_sum( $order, 'deposit_received', 'online' );
    }

    /**
     * Sum WooCommerce refund objects that were created specifically for the
     * online security deposit. This remains authoritative even if generating
     * the immutable PDF receipt failed after the gateway refund succeeded.
     */
    private function gateway_deposit_refunded_total( WC_Order $order ) {
        $sum = 0.0;
        foreach ( $order->get_refunds() as $refund ) {
            if ( $refund instanceof WC_Order_Refund && 'yes' === $refund->get_meta( '_clr_deposit_refund', true ) ) {
                $sum += max( 0, (float) $refund->get_amount() );
            }
        }
        return $sum;
    }

    private function online_refunded_total( WC_Order $order ) {
        return max(
            $this->document_sum( $order, 'deposit_refund', 'online' ),
            $this->gateway_deposit_refunded_total( $order )
        );
    }

    /**
     * Mark the WC_Order_Refund before its first save. The extra argument is
     * private to this plugin and survives wp_parse_args() inside wc_create_refund().
     */
    public function tag_deposit_refund( $refund, $args ) {
        if ( ! $refund instanceof WC_Order_Refund || empty( $args['rmwc_deposit_refund'] ) ) {
            return;
        }
        $refund->add_meta_data( '_clr_deposit_refund', 'yes', true );
        $refund->add_meta_data( '_clr_deposit_refund_method', 'online', true );
    }

    /**
     * A security-deposit refund must never change the parent order to the
     * generic "refunded" status, even for an unusual zero-price rental where
     * the deposit represents the complete WooCommerce payment amount.
     */
    public function keep_deposit_refund_partial( $is_partial, $order_id, $refund_id ) {
        unset( $order_id );
        $refund = wc_get_order( absint( $refund_id ) );
        if ( $refund instanceof WC_Order_Refund && 'yes' === $refund->get_meta( '_clr_deposit_refund', true ) ) {
            return true;
        }
        return $is_partial;
    }

    /**
     * The rental plugin sends its own WooCommerce-styled Kautionsquittung.
     * Suppress WooCommerce's generic refund email only while this module is
     * synchronously creating a marked deposit refund.
     */
    public function suppress_core_refund_email( $enabled, $object = null, $email = null ) {
        unset( $object, $email );
        return $this->creating_deposit_refund ? false : $enabled;
    }

    public function sync_online_received( $order_id ) {
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || ! $order->is_paid() ) {
            return;
        }
        $online = $this->fee_deposit_total( $order );
        if ( $online <= 0 ) {
            return;
        }
        $docs = RMWC_V2_Documents::instance();
        $snapshot = [
            'order_number' => $order->get_order_number(),
            'date'          => wp_date( 'd.m.Y H:i' ),
            'customer_name' => $order->get_formatted_billing_full_name(),
            'method'        => 'online',
            'method_label'  => $order->get_payment_method_title() ?: __( 'Online-Zahlung', 'patsch9-rental-engine' ),
            'remaining'     => max( 0, $this->required_total( $order ) - $online ),
        ];
        $created = $docs->create_document( $order, 'deposit_received', $snapshot, $online, 'online_received' );
        if ( is_wp_error( $created ) ) {
            return;
        }
        if ( (int) $created > 0 && 'yes' !== $order->get_meta( '_clr_deposit_online_receipt_mailed', true ) ) {
            $sent = $docs->send_document_email(
                $order,
                (int) $created,
                __( 'Kautionsquittung zu Ihrer Vermietung', 'patsch9-rental-engine' ),
                __( 'Kaution erhalten', 'patsch9-rental-engine' ),
                __( 'Vielen Dank. Die mit der Online-Zahlung hinterlegte Kaution wurde erfasst. Ihre Kautionsquittung finden Sie im Anhang.', 'patsch9-rental-engine' )
            );
            if ( $sent ) {
                $order->update_meta_data( '_clr_deposit_online_receipt_mailed', 'yes' );
                $order->save();
            }
        }
    }

    public function add_meta_box() {
        $screens = [ 'shop_order' ];
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box( 'clr_v2_deposit_box', __( 'Mietkaution', 'patsch9-rental-engine' ), [ $this, 'render_meta_box' ], $screen, 'side', 'high' );
        }
    }

    public function render_meta_box( $object ) {
        $order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
        if ( ! $order ) {
            return;
        }
        $required = $this->required_total( $order );
        if ( $required <= 0 ) {
            echo '<p>' . esc_html__( 'Diese Bestellung enthält keine Mietkaution.', 'patsch9-rental-engine' ) . '</p>';
            return;
        }
        $state = $this->state( $order );
        $received = (float) ( $state['received'] ?? 0 );
        $refunded = (float) ( $state['refunded'] ?? 0 );
        $retained = (float) ( $state['retained'] ?? 0 );
        $available = (float) ( $state['available'] ?? 0 );
        $missing = (float) ( $state['missing'] ?? 0 );
        $uncertain = $order->get_meta( '_clr_deposit_refund_uncertain', true );

        echo '<div class="clr-v2-deposit-summary">';
        echo '<p><strong>' . esc_html__( 'Kaution erforderlich:', 'patsch9-rental-engine' ) . '</strong><br>' . wp_kses_post( wc_price( $required, [ 'currency' => $order->get_currency() ] ) ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Erhalten:', 'patsch9-rental-engine' ) . '</strong> ' . wp_kses_post( wc_price( $received, [ 'currency' => $order->get_currency() ] ) ) . '<br><strong>' . esc_html__( 'Zurückgezahlt:', 'patsch9-rental-engine' ) . '</strong> ' . wp_kses_post( wc_price( $refunded, [ 'currency' => $order->get_currency() ] ) );
        if ( $retained > 0.0001 ) {
            echo '<br><strong>' . esc_html__( 'Einbehalten:', 'patsch9-rental-engine' ) . '</strong> ' . wp_kses_post( wc_price( $retained, [ 'currency' => $order->get_currency() ] ) );
        }
        echo '<br><strong>' . esc_html__( 'Aktuell hinterlegt / offen:', 'patsch9-rental-engine' ) . '</strong> ' . wp_kses_post( wc_price( $available, [ 'currency' => $order->get_currency() ] ) ) . '</p>';

        if ( $uncertain ) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Online-Rückzahlung unklar.', 'patsch9-rental-engine' ) . '</strong><br>' . esc_html__( 'Der Zahlungsanbieter meldete keinen eindeutigen Erfolg. Vor einer erneuten Online-Rückzahlung bitte den Zahlungsanbieter prüfen.', 'patsch9-rental-engine' ) . '</p></div>';
            $this->admin_post_form( 'rmwc_deposit_clear_uncertain', $order, [], __( 'Nach Prüfung entsperren', 'patsch9-rental-engine' ), 'secondary' );
        }

        if ( $missing > 0 ) {
            echo '<hr><p><strong>' . esc_html__( 'Kaution separat erfassen', 'patsch9-rental-engine' ) . '</strong></p>';
            $this->admin_post_form(
                'rmwc_deposit_receive',
                $order,
                [
                    'amount' => [ 'type' => 'number', 'label' => __( 'Betrag', 'patsch9-rental-engine' ), 'value' => $missing, 'step' => '0.01', 'min' => '0.01', 'max' => $missing ],
                    'method' => [ 'type' => 'select', 'label' => __( 'Erhalten als', 'patsch9-rental-engine' ), 'options' => [ 'cash' => __( 'Bar', 'patsch9-rental-engine' ), 'transfer' => __( 'Separate Überweisung', 'patsch9-rental-engine' ), 'other' => __( 'Sonstige Hinterlegung', 'patsch9-rental-engine' ) ] ],
                ],
                __( 'Kaution als erhalten buchen', 'patsch9-rental-engine' )
            );
        }

        if ( $available > 0 ) {
            echo '<hr><p><strong>' . esc_html__( 'Kaution zurückzahlen', 'patsch9-rental-engine' ) . '</strong></p>';
            $online_available = max( 0, (float) ( $state['online_available'] ?? 0 ) );
            $options = [ 'manual' => __( 'Bar / manuell bestätigt', 'patsch9-rental-engine' ) ];
            if ( $online_available > 0 && ! $uncertain ) {
                /* translators: %s: maximum refundable online deposit amount. */
                $options = [ 'online' => sprintf( __( 'Über ursprüngliche Online-Zahlungsart (max. %s)', 'patsch9-rental-engine' ), wp_strip_all_tags( wc_price( $online_available, [ 'currency' => $order->get_currency() ] ) ) ) ] + $options;
            }
            $this->admin_post_form(
                'rmwc_deposit_refund',
                $order,
                [
                    'amount' => [ 'type' => 'number', 'label' => __( 'Betrag', 'patsch9-rental-engine' ), 'value' => $available, 'step' => '0.01', 'min' => '0.01', 'max' => $available ],
                    'method' => [ 'type' => 'select', 'label' => __( 'Rückzahlung', 'patsch9-rental-engine' ), 'options' => $options ],
                    'retention_reason' => [
                        'type' => 'select',
                        'label' => __( 'Grund für verbleibenden Einbehalt (optional)', 'patsch9-rental-engine' ),
                        'options' => [
                            'none'     => __( 'Kein Einbehalt / weitere Teilrückzahlung folgt', 'patsch9-rental-engine' ),
                            'damage'   => __( 'Beschädigung', 'patsch9-rental-engine' ),
                            'cleaning' => __( 'Reinigung / nicht ordnungsgemäß gereinigt', 'patsch9-rental-engine' ),
                            'loss'     => __( 'Verlust / Nichtrückgabe', 'patsch9-rental-engine' ),
                            'other'    => __( 'Sonstiger Grund', 'patsch9-rental-engine' ),
                        ],
                    ],
                    'retention_note' => [ 'type' => 'text', 'label' => __( 'Notiz zum Einbehalt (optional)', 'patsch9-rental-engine' ), 'value' => '' ],
                ],
                __( 'Rückzahlung durchführen / bestätigen', 'patsch9-rental-engine' )
            );
        }
        echo '</div>';
    }

    private function admin_post_form( $action, WC_Order $order, array $fields, $button, $class = 'primary' ) {
        // The HPOS order editor already wraps all meta boxes in WooCommerce's
        // main order form. Nesting another <form> here is invalid HTML: browsers
        // can then submit our hidden "action" field with the normal order save,
        // which prevents both deposit actions and status changes from reaching
        // their intended handlers. Visible controls are therefore associated
        // with a real standalone form rendered in admin_footer, outside the
        // WooCommerce order form.
        $form_id = 'clr-v2-deposit-' . sanitize_html_class( $action ) . '-' . $order->get_id() . '-' . count( $this->admin_footer_forms );
        $this->admin_footer_forms[ $form_id ] = [
            'action'   => $action,
            'order_id' => $order->get_id(),
            'nonce'    => wp_create_nonce( $action . '_' . $order->get_id() ),
        ];

        echo '<div class="clr-v2-deposit-form">';
        foreach ( $fields as $name => $field ) {
            echo '<p><label><strong>' . esc_html( $field['label'] ) . '</strong><br>';
            if ( 'select' === $field['type'] ) {
                echo '<select form="' . esc_attr( $form_id ) . '" name="' . esc_attr( $name ) . '" style="width:100%">';
                foreach ( $field['options'] as $value => $label ) {
                    echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
                }
                echo '</select>';
            } elseif ( 'text' === $field['type'] ) {
                echo '<input form="' . esc_attr( $form_id ) . '" style="width:100%" type="text" maxlength="500" name="' . esc_attr( $name ) . '" value="' . esc_attr( $field['value'] ?? '' ) . '">';
            } else {
                echo '<input form="' . esc_attr( $form_id ) . '" style="width:100%" type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( wc_format_localized_decimal( $field['value'] ) ) . '" step="' . esc_attr( $field['step'] ) . '" min="' . esc_attr( $field['min'] ) . '" max="' . esc_attr( $field['max'] ) . '">';
            }
            echo '</label></p>';
        }
        echo '<p><button form="' . esc_attr( $form_id ) . '" class="button button-' . esc_attr( $class ) . '" type="submit">' . esc_html( $button ) . '</button></p></div>';
    }

    public function render_admin_footer_forms() {
        if ( ! $this->admin_footer_forms ) {
            return;
        }
        foreach ( $this->admin_footer_forms as $form_id => $form ) {
            echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="clr-v2-standalone-admin-form" style="display:none">';
            echo '<input type="hidden" name="action" value="' . esc_attr( $form['action'] ) . '">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr( $form['order_id'] ) . '">';
            echo '<input type="hidden" name="clr_v2_deposit_nonce" value="' . esc_attr( $form['nonce'] ) . '">';
            echo '</form>';
        }
        $this->admin_footer_forms = [];
    }

    private function admin_action_order( $action ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'patsch9-rental-engine' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( $action . '_' . $order_id, 'clr_v2_deposit_nonce' );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Bestellung nicht gefunden.', 'patsch9-rental-engine' ), '', [ 'response' => 404 ] );
        }
        return $order;
    }

    private function acquire_order_lock( WC_Order $order ) {
        global $wpdb;
        $scope = ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . get_current_blog_id();
        $lock = 'clr_dep_' . substr( hash( 'sha256', $scope ), 0, 20 ) . '_' . $order->get_id();
        $got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
        return 1 === $got ? $lock : false;
    }

    private function release_order_lock( $lock ) {
        if ( ! $lock ) {
            return;
        }
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }

    private function redirect_order( WC_Order $order, $notice = '' ) {
        $url = $order->get_edit_order_url();
        if ( $notice ) {
            $url = add_query_arg( 'clr_deposit_notice', sanitize_key( $notice ), $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    public function admin_receive() {
        $order = $this->admin_action_order( 'rmwc_deposit_receive' );
        $lock = $this->acquire_order_lock( $order );
        if ( ! $lock ) {
            $this->redirect_order( $order, 'busy' );
        }

        $notice = 'error';
        try {
            $missing = max( 0, $this->required_total( $order ) - $this->received_total( $order ) );
            $amount = isset( $_POST['amount'] ) ? max( 0, (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) ) : 0;
            $amount = min( $missing, $amount );
            $method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'cash';

            if ( $amount <= 0 || ! in_array( $method, [ 'cash', 'transfer', 'other' ], true ) ) {
                $notice = 'invalid';
            } else {
                $labels = [
                    'cash'     => __( 'Bar', 'patsch9-rental-engine' ),
                    'transfer' => __( 'Separate Überweisung', 'patsch9-rental-engine' ),
                    'other'    => __( 'Sonstige Hinterlegung', 'patsch9-rental-engine' ),
                ];
                $snapshot = [
                    'order_number' => $order->get_order_number(),
                    'date'          => wp_date( 'd.m.Y H:i' ),
                    'customer_name' => $order->get_formatted_billing_full_name(),
                    'method'        => $method,
                    'method_label'  => $labels[ $method ],
                    'remaining'     => max( 0, $missing - $amount ),
                ];
                $event = 'received_' . wp_generate_uuid4();
                $document_id = RMWC_V2_Documents::instance()->create_document( $order, 'deposit_received', $snapshot, $amount, $event );

                if ( is_wp_error( $document_id ) ) {
                    $notice = 'error';
                } else {
                    RMWC_V2_Documents::instance()->send_document_email(
                        $order,
                        (int) $document_id,
                        __( 'Kautionsquittung zu Ihrer Vermietung', 'patsch9-rental-engine' ),
                        __( 'Kaution erhalten', 'patsch9-rental-engine' ),
                        __( 'Wir bestätigen den Erhalt Ihrer Kaution. Die Quittung finden Sie im Anhang.', 'patsch9-rental-engine' )
                    );
                    $order->add_order_note( sprintf( 'Kaution erhalten: %s (%s).', wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) ), $labels[ $method ] ) );
                    $notice = 'received';
                }
            }
        } finally {
            // wp_safe_redirect()/exit must happen only after releasing the named
            // lock. PHP does not execute a finally block after exit/die.
            $this->release_order_lock( $lock );
        }

        $this->redirect_order( $order, $notice );
    }

    public function admin_refund() {
        $order = $this->admin_action_order( 'rmwc_deposit_refund' );
        $lock = $this->acquire_order_lock( $order );
        if ( ! $lock ) {
            $this->redirect_order( $order, 'busy' );
        }

        $notice = 'error';
        try {
            if ( $order->get_meta( '_clr_deposit_refund_uncertain', true ) ) {
                $notice = 'uncertain';
            } else {
                $available = max( 0, (float) ( $this->state( $order )['available'] ?? 0 ) );
                $amount = isset( $_POST['amount'] ) ? max( 0, (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) ) : 0;
                $amount = min( $available, $amount );
                $method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'manual';

                if ( $amount <= 0 || ! in_array( $method, [ 'online', 'manual' ], true ) ) {
                    $notice = 'invalid';
                } else {
                    $method_label     = __( 'Bar / manuell', 'patsch9-rental-engine' );
                    $can_record_refund = true;
                    $gateway_refund_id = 0;

                    if ( 'online' === $method ) {
                        $online_available = max( 0, $this->online_received_total( $order ) - $this->online_refunded_total( $order ) );
                        if ( $amount > $online_available + 0.0001 ) {
                            $notice = 'online_limit';
                            $can_record_refund = false;
                        } else {
                            $this->creating_deposit_refund = true;
                            try {
                                $result = wc_create_refund(
                                    [
                                        'amount'                           => wc_format_decimal( $amount ),
                                        'reason'                           => __( 'Rückzahlung Mietkaution', 'patsch9-rental-engine' ),
                                        'order_id'                         => $order->get_id(),
                                        'refund_payment'                   => true,
                                        'restock_items'                    => false,
                                        'line_items'                       => [],
                                        'rmwc_deposit_refund' => true,
                                    ]
                                );
                            } finally {
                                $this->creating_deposit_refund = false;
                            }

                            if ( is_wp_error( $result ) || ! $result instanceof WC_Order_Refund ) {
                                // A gateway/network error can be ambiguous: the provider
                                // might have accepted the refund although WooCommerce did
                                // not receive a definitive response. Never auto-retry until
                                // an administrator checks the payment provider.
                                $order->update_meta_data(
                                    '_clr_deposit_refund_uncertain',
                                    [
                                        'amount'  => $amount,
                                        'at'      => current_time( 'mysql', true ),
                                        'message' => is_wp_error( $result ) ? sanitize_text_field( $result->get_error_message() ) : 'unknown',
                                    ]
                                );
                                $order->save();
                                $notice = 'refund_uncertain';
                                $can_record_refund = false;
                            } else {
                                $method_label = $order->get_payment_method_title() ?: __( 'Ursprüngliche Online-Zahlungsart', 'patsch9-rental-engine' );
                                $gateway_refund_id = $result->get_id();
                            }
                        }
                    }

                    if ( $can_record_refund ) {
                        $remaining = max( 0, $available - $amount );
                        $retention_reason = isset( $_POST['retention_reason'] ) ? sanitize_key( wp_unslash( $_POST['retention_reason'] ) ) : 'none';
                        $allowed_reasons = [ 'none', 'damage', 'cleaning', 'loss', 'other' ];
                        if ( ! in_array( $retention_reason, $allowed_reasons, true ) || $remaining <= 0 ) {
                            $retention_reason = 'none';
                        }
                        $retention_note = isset( $_POST['retention_note'] )
                            ? substr( sanitize_text_field( wp_unslash( $_POST['retention_note'] ) ), 0, 500 )
                            : '';
                        $reason_labels = [
                            'damage'   => __( 'Beschädigung', 'patsch9-rental-engine' ),
                            'cleaning' => __( 'Reinigung / nicht ordnungsgemäß gereinigt', 'patsch9-rental-engine' ),
                            'loss'     => __( 'Verlust / Nichtrückgabe', 'patsch9-rental-engine' ),
                            'other'    => __( 'Sonstiger Grund', 'patsch9-rental-engine' ),
                        ];
                        $reason_text = isset( $reason_labels[ $retention_reason ] ) ? $reason_labels[ $retention_reason ] : '';
                        if ( $reason_text && $retention_note ) {
                            $reason_text .= ': ' . $retention_note;
                        }
                        $snapshot = [
                            'order_number' => $order->get_order_number(),
                            'date'          => wp_date( 'd.m.Y H:i' ),
                            'customer_name' => $order->get_formatted_billing_full_name(),
                            'method'        => 'online' === $method ? 'online' : 'manual',
                            'method_label'  => $method_label,
                            'remaining'     => $remaining,
                            'wc_refund_id'  => absint( $gateway_refund_id ),
                            'retained'      => 'none' !== $retention_reason ? $remaining : 0,
                            'reason'        => $reason_text,
                        ];
                        if ( 'none' !== $retention_reason ) {
                            $order->update_meta_data( '_clr_deposit_retained', wc_format_decimal( $remaining, wc_get_price_decimals() ) );
                            $order->update_meta_data( '_clr_deposit_retention_reason', $retention_reason );
                            $order->update_meta_data( '_clr_deposit_note', $retention_note );
                            $order->save();
                        }
                        $event = $gateway_refund_id
                            ? 'refund_wc_' . absint( $gateway_refund_id )
                            : 'refund_manual_' . wp_generate_uuid4();
                        $document_id = RMWC_V2_Documents::instance()->create_document( $order, 'deposit_refund', $snapshot, $amount, $event );
                        if ( is_wp_error( $document_id ) ) {
                            // For an already completed online gateway refund this is a
                            // document/ledger failure that needs manual attention. Block
                            // another automated refund to prevent duplicate money movement.
                            if ( 'online' === $method ) {
                                $order->update_meta_data(
                                    '_clr_deposit_refund_uncertain',
                                    [
                                        'amount'  => $amount,
                                        'at'      => current_time( 'mysql', true ),
                                        'message' => 'refund_succeeded_document_failed',
                                    ]
                                );
                                $order->save();
                                $notice = 'refund_uncertain';
                            } else {
                                $notice = 'error';
                            }
                        } else {
                            RMWC_V2_Documents::instance()->send_document_email(
                                $order,
                                (int) $document_id,
                                __( 'Rückzahlung Ihrer Mietkaution', 'patsch9-rental-engine' ),
                                __( 'Kaution zurückgezahlt', 'patsch9-rental-engine' ),
                                __( 'Wir bestätigen die Rückzahlung Ihrer Kaution bzw. Teilkaution. Die Rückzahlungsquittung finden Sie im Anhang.', 'patsch9-rental-engine' )
                            );
                            $order->add_order_note( sprintf( 'Mietkaution zurückgezahlt: %s (%s).', wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) ), $method_label ) );
                            $notice = 'refunded';
                        }
                    }
                }
            }
        } finally {
            $this->release_order_lock( $lock );
        }

        $this->redirect_order( $order, $notice );
    }

    public function admin_clear_uncertain() {
        $order = $this->admin_action_order( 'rmwc_deposit_clear_uncertain' );
        $lock  = $this->acquire_order_lock( $order );
        if ( ! $lock ) {
            $this->redirect_order( $order, 'busy' );
        }
        try {
            $order = wc_get_order( $order->get_id() ) ?: $order;
            $order->delete_meta_data( '_clr_deposit_refund_uncertain' );
            $order->add_order_note( 'Mietkaution: Sperre für unklare Online-Rückzahlung wurde nach manueller Prüfung aufgehoben.' );
            $order->save();
        } finally {
            $this->release_order_lock( $lock );
        }
        $this->redirect_order( $order, 'cleared' );
    }

    public function settings_fields() {
        $ids = get_option( 'clr_v2_offline_gateway_ids', 'bacs,cheque,cod' );
        ?>
        <h2><?php esc_html_e( 'Kaution & Zahlungsarten', 'patsch9-rental-engine' ); ?></h2>
        <table class="form-table"><tbody><tr>
            <th><label for="clr_v2_offline_gateway_ids"><?php esc_html_e( 'Offline-Zahlungsarten', 'patsch9-rental-engine' ); ?></label></th>
            <td><input class="regular-text" type="text" id="clr_v2_offline_gateway_ids" name="clr_v2_offline_gateway_ids" value="<?php echo esc_attr( $ids ); ?>"><p class="description"><?php esc_html_e( 'Kommagetrennte Gateway-IDs, bei denen die Kaution nicht in den WooCommerce-Zahlbetrag aufgenommen wird. Standard: bacs, cheque, cod. Weitere Rechnung-/Vorkasse-Plugins hier ergänzen.', 'patsch9-rental-engine' ); ?></p></td>
        </tr></tbody></table>
        <?php
    }

    public function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $raw = isset( $_POST['clr_v2_offline_gateway_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['clr_v2_offline_gateway_ids'] ) ) : 'bacs,cheque,cod';
        $ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,;]+/', $raw ) ?: [] ) ) ) );
        update_option( 'clr_v2_offline_gateway_ids', implode( ',', array_slice( $ids, 0, 50 ) ), false );
    }
}
