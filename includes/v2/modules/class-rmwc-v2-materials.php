<?php
/**
 * Rental-only accessory packages based on hidden WooCommerce products.
 *
 * Each size (for example "für 10 Personen", "für 20 Personen", "5 kg") is a
 * normal WooCommerce product marked as rental-only accessory. This preserves
 * stock, tax class, price and invoice semantics while keeping the product out
 * of the public catalog and blocking direct purchases.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2_Materials {
    private static $instance = null;
    private $syncing_cart = false;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rmwc_product_panel_accessories', [ $this, 'product_panel' ], 10 );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields' ], 70 );
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'frontend_fields' ], 14 );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_selection' ], 40, 5 );
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'cart_item_data' ], 50, 3 );
        add_action( 'woocommerce_add_to_cart', [ $this, 'add_linked_accessories' ], 30, 6 );
        add_action( 'woocommerce_cart_item_removed', [ $this, 'remove_linked_accessories' ], 30, 2 );
        add_action( 'woocommerce_after_cart_item_quantity_update', [ $this, 'sync_linked_quantities' ], 30, 4 );
        add_action( 'woocommerce_check_cart_items', [ $this, 'validate_linked_accessories' ], 40 );
        add_filter( 'woocommerce_cart_item_permalink', [ $this, 'remove_accessory_permalink' ], 30, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'accessory_cart_item_label' ], 40, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'accessory_order_meta' ], 40, 4 );
    }

    /**
     * Truncate sanitized admin labels without requiring the optional mbstring extension.
     */
    private function limit_text( $value, $length ) {
        $value  = (string) $value;
        $length = max( 1, absint( $length ) );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $length );
        }
        return substr( $value, 0, $length );
    }


    /**
     * A configured accessory can be a simple rental-only product or one
     * concrete variation of a rental-only variable product.
     */
    private function accessory_is_allowed( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }
        if ( $product instanceof WC_Product_Variation ) {
            return 'yes' === get_post_meta( $product->get_parent_id(), '_rmwc_accessory_only', true );
        }
        return $product->is_type( 'simple' ) && 'yes' === get_post_meta( $product->get_id(), '_rmwc_accessory_only', true );
    }

    private function accessory_is_publicly_usable( $product ) {
        if ( ! $this->accessory_is_allowed( $product ) ) {
            return false;
        }
        $status_id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
        return 'publish' === get_post_status( $status_id ) && $product->is_in_stock();
    }

    private function config( $product_id ) {
        $rows = get_post_meta( absint( $product_id ), '_rmwc_v2_accessory_options', true );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $clean = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $accessory_id      = absint( $row['product_id'] ?? 0 );
            $group             = sanitize_text_field( $row['group'] ?? '' );
            $accessory_product = $accessory_id ? wc_get_product( $accessory_id ) : false;
            if ( ! $accessory_id || '' === $group || ! $accessory_product || ! $this->accessory_is_allowed( $accessory_product ) ) {
                continue;
            }
            $mode = sanitize_key( $row['mode'] ?? 'once' );
            $selection = sanitize_key( $row['selection'] ?? 'single' );
            $clean[] = [
                'group'      => $this->limit_text( $group, 100 ),
                'product_id' => $accessory_id,
                'label'      => $this->limit_text( sanitize_text_field( $row['label'] ?? '' ), 150 ),
                'required'   => ! empty( $row['required'] ),
                'selection'  => 'multiple' === $selection ? 'multiple' : 'single',
                'mode'       => 'per_device' === $mode ? 'per_device' : 'once',
            ];
        }
        return $clean;
    }

    private function grouped_config( $product_id ) {
        $groups = [];
        foreach ( $this->config( $product_id ) as $row ) {
            $key = sanitize_title( $row['group'] );
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = [
                    'label'     => $row['group'],
                    'required'  => false,
                    'selection' => 'single',
                    'options'   => [],
                ];
            }
            $groups[ $key ]['required'] = $groups[ $key ]['required'] || $row['required'];
            if ( 'multiple' === ( $row['selection'] ?? 'single' ) ) {
                $groups[ $key ]['selection'] = 'multiple';
            }
            $groups[ $key ]['options'][ (int) $row['product_id'] ] = $row;
        }
        return $groups;
    }

    public function product_panel( $product_id ) {
        $groups = $this->grouped_config( $product_id );
        ?>
        <section class="rmwc-v2-materials-wrap" aria-labelledby="rmwc-v2-materials-title">
            <div class="rmwc-v2-section-heading">
                <div>
                    <h5 id="rmwc-v2-materials-title"><?php esc_html_e( 'Accessories & Consumables', 'patsch9-rental-engine' ); ?></h5>
                    <p class="description"><?php esc_html_e( 'First create a group such as "Material" or "Accessories" and add the appropriate rental accessories. Mandatory selection and single/multiple selection are defined once per group.', 'patsch9-rental-engine' ); ?></p>
                </div>
                <button type="button" class="button rmwc-v2-add-material-group"><?php esc_html_e( '+ Add group', 'patsch9-rental-engine' ); ?></button>
            </div>
            <div class="rmwc-v2-material-group-list">
                <?php
                $group_index = 0;
                foreach ( $groups as $group ) {
                    $this->admin_group( $group_index, $group );
                    $group_index++;
                }
                ?>
            </div>
            <?php if ( ! $groups ) : ?>
                <p class="rmwc-v2-material-empty"><?php esc_html_e( 'No accessory set yet.', 'patsch9-rental-engine' ); ?></p>
            <?php endif; ?>
        </section>
        <?php
    }

    private function admin_group( $group_index, array $group ) {
        $selection = 'multiple' === ( $group['selection'] ?? 'single' ) ? 'multiple' : 'single';
        $options   = array_values( $group['options'] ?? [] );
        ?>
        <div class="rmwc-v2-material-group" data-group-index="<?php echo esc_attr( $group_index ); ?>" data-next-option="<?php echo esc_attr( count( $options ) ); ?>">
            <div class="rmwc-v2-material-group-head">
                <div class="rmwc-v2-material-group-settings">
                    <div class="rmwc-v2-field rmwc-v2-group-name">
                        <label><?php esc_html_e( 'Gruppenname', 'patsch9-rental-engine' ); ?></label>
                        <input type="text" name="rmwc_v2_accessory_groups[<?php echo esc_attr( $group_index ); ?>][label]" value="<?php echo esc_attr( $group['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Material', 'patsch9-rental-engine' ); ?>" maxlength="100" required>
                    </div>
                    <div class="rmwc-v2-field rmwc-v2-group-selection">
                        <label><?php esc_html_e( 'Auswahlart', 'patsch9-rental-engine' ); ?></label>
                        <select name="rmwc_v2_accessory_groups[<?php echo esc_attr( $group_index ); ?>][selection]">
                            <option value="single" <?php selected( $selection, 'single' ); ?>><?php esc_html_e( 'Einfachauswahl – maximal eine Option', 'patsch9-rental-engine' ); ?></option>
                            <option value="multiple" <?php selected( $selection, 'multiple' ); ?>><?php esc_html_e( 'Multiple selection – multiple options possible', 'patsch9-rental-engine' ); ?></option>
                        </select>
                    </div>
                    <div class="rmwc-v2-field rmwc-v2-group-required">
                        <span class="rmwc-v2-label"><?php esc_html_e( 'Pflichtauswahl', 'patsch9-rental-engine' ); ?></span>
                        <label class="rmwc-v2-checkbox">
                            <input type="checkbox" name="rmwc_v2_accessory_groups[<?php echo esc_attr( $group_index ); ?>][required]" value="1" <?php checked( ! empty( $group['required'] ) ); ?>>
                            <span><?php esc_html_e( 'At least one option must be selected', 'patsch9-rental-engine' ); ?></span>
                        </label>
                    </div>
                </div>
                <div class="rmwc-v2-material-group-actions">
                    <button type="button" class="button rmwc-v2-add-material-option"><?php esc_html_e( '+ Add accessories', 'patsch9-rental-engine' ); ?></button>
                    <button type="button" class="button-link-delete rmwc-remove-material-group" aria-label="<?php esc_attr_e( 'Remove accessory group', 'patsch9-rental-engine' ); ?>"><?php esc_html_e( '×', 'patsch9-rental-engine' ); ?></button>
                </div>
            </div>
            <div class="rmwc-v2-material-options-list">
                <?php foreach ( $options as $option_index => $row ) : $this->admin_option( $group_index, $option_index, $row ); endforeach; ?>
            </div>
            <?php if ( ! $options ) : ?>
                <p class="rmwc-v2-material-group-empty"><?php esc_html_e( 'No accessories in this group yet. Add at least one article.', 'patsch9-rental-engine' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function admin_option( $group_index, $option_index, array $row ) {
        $product = wc_get_product( $row['product_id'] ?? 0 );
        ?>
        <div class="rmwc-v2-material-option" data-option-index="<?php echo esc_attr( $option_index ); ?>">
            <div class="rmwc-v2-field rmwc-v2-field-product">
                <label><?php esc_html_e( 'Rental accessory product', 'patsch9-rental-engine' ); ?></label>
                <select class="wc-product-search" name="rmwc_v2_accessory_groups[<?php echo esc_attr( $group_index ); ?>][options][<?php echo esc_attr( $option_index ); ?>][product_id]" data-placeholder="<?php esc_attr_e( 'Find rental accessories...', 'patsch9-rental-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
                    <?php if ( $product ) : ?><option value="<?php echo esc_attr( $product->get_id() ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option><?php endif; ?>
                </select>
                <p class="description"><?php esc_html_e( 'Price, tax, inventory and "Sell separately only" come directly from this WooCommerce article.', 'patsch9-rental-engine' ); ?></p>
            </div>
            <div class="rmwc-v2-field rmwc-v2-field-label">
                <label><?php esc_html_e( 'Display for customers', 'patsch9-rental-engine' ); ?></label>
                <input type="text" name="rmwc_v2_accessory_groups[<?php echo esc_attr( $group_index ); ?>][options][<?php echo esc_attr( $option_index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. for about 1 kg sweet popcorn', 'patsch9-rental-engine' ); ?>" maxlength="150">
                <p class="description"><?php esc_html_e( 'Optional. Leer = Produktname verwenden.', 'patsch9-rental-engine' ); ?></p>
            </div>
            <div class="rmwc-v2-field rmwc-v2-field-mode">
                <label><?php esc_html_e( 'Berechnung', 'patsch9-rental-engine' ); ?></label>
                <select name="rmwc_v2_accessory_groups[<?php echo esc_attr( $group_index ); ?>][options][<?php echo esc_attr( $option_index ); ?>][mode]">
                    <option value="once" <?php selected( $row['mode'] ?? 'once', 'once' ); ?>><?php esc_html_e( '1× per booking', 'patsch9-rental-engine' ); ?></option>
                    <option value="per_device" <?php selected( $row['mode'] ?? 'once', 'per_device' ); ?>><?php esc_html_e( 'Per rented unit', 'patsch9-rental-engine' ); ?></option>
                </select>
            </div>
            <button type="button" class="button-link-delete rmwc-remove-material-option" aria-label="<?php esc_attr_e( 'Remove rental accessories from group', 'patsch9-rental-engine' ); ?>"><?php esc_html_e( '×', 'patsch9-rental-engine' ); ?></button>
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

        $rows = [];
        // New group-centric editor.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested product form is nonce/capability protected and every value is validated below.
        $raw_groups = isset( $_POST['rmwc_v2_accessory_groups'] ) && is_array( $_POST['rmwc_v2_accessory_groups'] ) ? array_slice( wp_unslash( $_POST['rmwc_v2_accessory_groups'] ), 0, 30 ) : null;
        if ( is_array( $raw_groups ) ) {
            $total_options = 0;
            foreach ( $raw_groups as $group ) {
                if ( ! is_array( $group ) ) {
                    continue;
                }
                $group_label = $this->limit_text( sanitize_text_field( $group['label'] ?? '' ), 100 );
                if ( '' === $group_label ) {
                    continue;
                }
                $required  = isset( $group['required'] ) ? 1 : 0;
                $selection = 'multiple' === sanitize_key( $group['selection'] ?? 'single' ) ? 'multiple' : 'single';
                $options   = isset( $group['options'] ) && is_array( $group['options'] ) ? $group['options'] : [];
                foreach ( $options as $option ) {
                    if ( ++$total_options > 100 || ! is_array( $option ) ) {
                        break;
                    }
                    $product_id       = absint( $option['product_id'] ?? 0 );
                    $accessory_product = $product_id ? wc_get_product( $product_id ) : false;
                    if ( ! $product_id || ! $accessory_product || ! $this->accessory_is_allowed( $accessory_product ) ) {
                        continue;
                    }
                    $mode   = sanitize_key( $option['mode'] ?? 'once' );
                    $rows[] = [
                        'group'      => $group_label,
                        'product_id' => $product_id,
                        'label'      => $this->limit_text( sanitize_text_field( $option['label'] ?? '' ), 150 ),
                        'required'   => $required,
                        'selection'  => $selection,
                        'mode'       => 'per_device' === $mode ? 'per_device' : 'once',
                    ];
                }
            }
        } else {
            // Backward compatibility for a stale admin page still posting the former flat editor.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are validated individually below.
            $raw = isset( $_POST['rmwc_v2_accessory_options'] ) && is_array( $_POST['rmwc_v2_accessory_options'] ) ? array_slice( wp_unslash( $_POST['rmwc_v2_accessory_options'] ), 0, 100 ) : [];
            foreach ( $raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $product_id       = absint( $row['product_id'] ?? 0 );
                $group            = $this->limit_text( sanitize_text_field( $row['group'] ?? '' ), 100 );
                $accessory_product = $product_id ? wc_get_product( $product_id ) : false;
                if ( ! $product_id || '' === $group || ! $accessory_product || ! $this->accessory_is_allowed( $accessory_product ) ) {
                    continue;
                }
                $mode   = sanitize_key( $row['mode'] ?? 'once' );
                $rows[] = [
                    'group'      => $group,
                    'product_id' => $product_id,
                    'label'      => $this->limit_text( sanitize_text_field( $row['label'] ?? '' ), 150 ),
                    'required'   => isset( $row['required'] ) ? 1 : 0,
                    'selection'  => 'single',
                    'mode'       => 'per_device' === $mode ? 'per_device' : 'once',
                ];
            }
        }
        update_post_meta( $post_id, '_rmwc_v2_accessory_options', $rows );
    }

    private function accessory_max_quantity( WC_Product $product ) {
        if ( $product->is_sold_individually() ) {
            return 1;
        }
        $max = (int) $product->get_max_purchase_quantity();
        if ( $max < 1 ) {
            $max = 10000;
        }
        return max( 1, min( 10000, $max ) );
    }

    private function accessory_card_description( WC_Product $product ) {
        $description = $product->get_short_description();
        if ( '' === trim( wp_strip_all_tags( $description ) ) && $product instanceof WC_Product_Variation ) {
            $description = $product->get_description();
        }
        return wp_trim_words( wp_strip_all_tags( $description ), 22, '…' );
    }

    public function frontend_fields() {
        global $product;
        if ( ! $product instanceof WC_Product || 'yes' !== get_post_meta( $product->get_id(), '_rmwc_rental_enabled', true ) ) {
            return;
        }
        $groups = $this->grouped_config( $product->get_id() );
        if ( ! $groups ) {
            return;
        }
        ?>
        <div class="rmwc-v2-material-options">
            <?php foreach ( $groups as $key => $group ) : ?>
                <?php
                $usable_options = [];
                foreach ( $group['options'] as $accessory_id => $row ) {
                    $accessory = wc_get_product( $accessory_id );
                    if ( $accessory && $this->accessory_is_publicly_usable( $accessory ) ) {
                        $usable_options[ $accessory_id ] = [ 'row' => $row, 'product' => $accessory ];
                    }
                }
                if ( ! $usable_options ) {
                    continue;
                }
                $selection = 'multiple' === ( $group['selection'] ?? 'single' ) ? 'multiple' : 'single';
                $multiple  = 'multiple' === $selection;
                $group_id  = 'rmwc_v2_material_group_' . sanitize_html_class( $key );
                if ( $multiple ) {
                    $helper = $group['required']
                        ? __( 'Please select at least one option. Multiple selection is possible.', 'patsch9-rental-engine' )
                        : __( 'Optional – You can select one or more options.', 'patsch9-rental-engine' );
                } else {
                    $helper = $group['required']
                        ? __( 'Please select an option.', 'patsch9-rental-engine' )
                        : __( 'Optional – select an option if required.', 'patsch9-rental-engine' );
                }
                ?>
                <section class="rmwc-accessory-group" data-required="<?php echo $group['required'] ? 'yes' : 'no'; ?>" data-selection="<?php echo esc_attr( $selection ); ?>" aria-labelledby="<?php echo esc_attr( $group_id ); ?>">
                    <div class="rmwc-accessory-group-head">
                        <div>
                            <h4 id="<?php echo esc_attr( $group_id ); ?>"><?php echo esc_html( $group['label'] ); ?></h4>
                            <p><?php echo esc_html( $helper ); ?></p>
                        </div>
                        <?php if ( count( $usable_options ) + ( ! $multiple && ! $group['required'] ? 1 : 0 ) > 2 ) : ?>
                            <div class="rmwc-accessory-slider-nav" aria-label="<?php esc_attr_e( 'Roll through accessories', 'patsch9-rental-engine' ); ?>">
                                <button type="button" class="rmwc-accessory-slider-prev" aria-label="<?php esc_attr_e( 'Vorherige Produkte', 'patsch9-rental-engine' ); ?>">&#8249;</button>
                                <button type="button" class="rmwc-accessory-slider-next" aria-label="<?php esc_attr_e( 'Next Products', 'patsch9-rental-engine' ); ?>">&#8250;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="rmwc-accessory-slider" tabindex="0">
                        <?php if ( ! $multiple && ! $group['required'] ) : ?>
                            <div class="rmwc-accessory-card rmwc-accessory-none is-selected" data-accessory-id="0">
                                <input class="rmwc-accessory-choice" type="radio" id="rmwc_v2_material_<?php echo esc_attr( $key ); ?>_none" name="rmwc_v2_material[<?php echo esc_attr( $key ); ?>][product_id]" value="0" checked>
                                <label class="rmwc-accessory-card-select" for="rmwc_v2_material_<?php echo esc_attr( $key ); ?>_none">
                                    <span class="rmwc-accessory-placeholder" aria-hidden="true">+</span>
                                    <span class="rmwc-accessory-card-body">
                                        <strong><?php esc_html_e( 'Not elsewhere specified', 'patsch9-rental-engine' ); ?></strong>
                                        <span><?php esc_html_e( 'No accessories from this group add.', 'patsch9-rental-engine' ); ?></span>
                                    </span>
                                    <span class="rmwc-accessory-select-label"><?php esc_html_e( 'Selected without addition', 'patsch9-rental-engine' ); ?></span>
                                </label>
                            </div>
                        <?php endif; ?>
                        <?php foreach ( $usable_options as $accessory_id => $option ) : ?>
                            <?php
                            $row       = $option['row'];
                            $accessory = $option['product'];
                            $label     = $row['label'] ?: $accessory->get_name();
                            $desc      = $this->accessory_card_description( $accessory );
                            $price     = (float) wc_get_price_to_display( $accessory );
                            $max_qty   = $this->accessory_max_quantity( $accessory );
                            $fixed_qty = $accessory->is_sold_individually();
                            $input_id  = 'rmwc_v2_material_' . sanitize_html_class( $key ) . '_' . absint( $accessory_id );
                            $input_type = $multiple ? 'checkbox' : 'radio';
                            $input_name = $multiple
                                ? 'rmwc_v2_material[' . $key . '][product_ids][]'
                                : 'rmwc_v2_material[' . $key . '][product_id]';
                            ?>
                            <div class="rmwc-accessory-card" data-accessory-id="<?php echo esc_attr( $accessory_id ); ?>" data-price="<?php echo esc_attr( wc_format_decimal( $price ) ); ?>" data-mode="<?php echo esc_attr( $row['mode'] ); ?>">
                                <input class="rmwc-accessory-choice" type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $accessory_id ); ?>" <?php echo ( ! $multiple && $group['required'] ) ? 'required' : ''; ?>>
                                <label class="rmwc-accessory-card-select" for="<?php echo esc_attr( $input_id ); ?>">
                                    <span class="rmwc-accessory-image"><?php echo wp_kses_post( $accessory->get_image( 'woocommerce_thumbnail', [ 'loading' => 'lazy' ] ) ); ?></span>
                                    <span class="rmwc-accessory-card-body">
                                        <strong><?php echo esc_html( $label ); ?></strong>
                                        <?php if ( '' !== $desc ) : ?><span class="rmwc-accessory-description"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
                                        <span class="rmwc-accessory-price"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
                                        <small><?php echo esc_html( 'per_device' === $row['mode'] ? __( 'Calculation per rental unit', 'patsch9-rental-engine' ) : __( 'Calculation per booking', 'patsch9-rental-engine' ) ); ?></small>
                                    </span>
                                    <span class="rmwc-accessory-select-label"><?php esc_html_e( 'Select', 'patsch9-rental-engine' ); ?></span>
                                </label>
                                <div class="rmwc-accessory-quantity" <?php echo $fixed_qty ? 'data-fixed="yes"' : ''; ?>>
                                    <span class="rmwc-accessory-qty-title"><?php esc_html_e( 'Menge', 'patsch9-rental-engine' ); ?></span>
                                    <?php if ( $fixed_qty ) : ?>
                                        <span class="rmwc-accessory-fixed-qty">1</span>
                                        <input type="hidden" name="rmwc_v2_material[<?php echo esc_attr( $key ); ?>][qty][<?php echo esc_attr( $accessory_id ); ?>]" value="1">
                                    <?php else : ?>
                                        <div class="rmwc-accessory-qty-control">
                                            <button type="button" class="rmwc-accessory-qty-minus" aria-label="<?php esc_attr_e( 'Menge verringern', 'patsch9-rental-engine' ); ?>">−</button>
                                            <input class="rmwc-accessory-qty-input" type="number" name="rmwc_v2_material[<?php echo esc_attr( $key ); ?>][qty][<?php echo esc_attr( $accessory_id ); ?>]" value="1" min="1" max="<?php echo esc_attr( $max_qty ); ?>" step="1" inputmode="numeric" disabled>
                                            <button type="button" class="rmwc-accessory-qty-plus" aria-label="<?php esc_attr_e( 'Increase quantity', 'patsch9-rental-engine' ); ?>">+</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function requested_selections( $product_id ) {
        $nonce = isset( $_POST['rmwc_rental_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rmwc_rental_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rmwc_add_rental_' . absint( $product_id ) ) ) {
            return new WP_Error( 'rmwc_material_nonce', __( 'The rental data could not be verified. Please reload page.', 'patsch9-rental-engine' ) );
        }
        $groups = $this->grouped_config( $product_id );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Signed rental form verified above; only configured IDs and bounded integer quantities are accepted below.
        $posted   = isset( $_POST['rmwc_v2_material'] ) && is_array( $_POST['rmwc_v2_material'] ) ? wp_unslash( $_POST['rmwc_v2_material'] ) : [];
        $selected = [];

        foreach ( $groups as $key => $group ) {
            $group_post = $posted[ $key ] ?? [];
            $multiple   = 'multiple' === ( $group['selection'] ?? 'single' );
            $ids        = [];

            if ( $multiple ) {
                if ( is_array( $group_post ) && isset( $group_post['product_ids'] ) && is_array( $group_post['product_ids'] ) ) {
                    $ids = array_values( array_unique( array_filter( array_map( 'absint', array_slice( $group_post['product_ids'], 0, 50 ) ) ) ) );
                }
            } else {
                // Backward compatibility with the old dropdown / single-card payload.
                $id = is_array( $group_post ) ? absint( $group_post['product_id'] ?? 0 ) : absint( $group_post );
                if ( $id ) {
                    $ids[] = $id;
                }
            }

            if ( ! $ids ) {
                if ( $group['required'] ) {
                    /* translators: %s: accessory option group label. */
                    return new WP_Error( 'rmwc_material_required', sprintf( __( 'Please select at least one option for "%s".', 'patsch9-rental-engine' ), $group['label'] ) );
                }
                continue;
            }

            if ( ! $multiple && count( $ids ) > 1 ) {
                return new WP_Error( 'rmwc_material_single', __( 'In this accessory group, only one option may be selected.', 'patsch9-rental-engine' ) );
            }

            foreach ( $ids as $id ) {
                if ( ! isset( $group['options'][ $id ] ) ) {
                    return new WP_Error( 'rmwc_material_invalid', __( 'A selected material option is not valid.', 'patsch9-rental-engine' ) );
                }
                $accessory = wc_get_product( $id );
                if ( ! $accessory || ! $this->accessory_is_publicly_usable( $accessory ) ) {
                    /* translators: %s: accessory option label. */
                    return new WP_Error( 'rmwc_material_stock', sprintf( __( 'The option "%s" is currently not available.', 'patsch9-rental-engine' ), $group['options'][ $id ]['label'] ?: get_the_title( $id ) ) );
                }

                $requested_qty = 1;
                if ( is_array( $group_post ) && isset( $group_post['qty'] ) && is_array( $group_post['qty'] ) && isset( $group_post['qty'][ $id ] ) ) {
                    $requested_qty = absint( $group_post['qty'][ $id ] );
                }
                if ( $requested_qty < 1 ) {
                    return new WP_Error( 'rmwc_material_quantity', __( 'The amount of selected rental accessories must be at least 1.', 'patsch9-rental-engine' ) );
                }
                $max_qty = $this->accessory_max_quantity( $accessory );
                if ( $accessory->is_sold_individually() ) {
                    $requested_qty = 1;
                } elseif ( $requested_qty > $max_qty ) {
                    /* translators: 1: accessory option label, 2: maximum selectable quantity. */
                    return new WP_Error( 'rmwc_material_quantity', sprintf( __( 'For "%1$s" only %2$d pieces can be selected.', 'patsch9-rental-engine' ), $group['options'][ $id ]['label'] ?: $accessory->get_name(), $max_qty ) );
                }

                $row                 = $group['options'][ $id ];
                $row['customer_qty'] = $requested_qty;
                $selected[]          = $row;
            }
        }
        return $selected;
    }

    public function validate_selection( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        unset( $variation_id, $variations );
        if ( 'yes' !== get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            return $passed;
        }
        $selected = $this->requested_selections( $product_id );
        if ( is_wp_error( $selected ) ) {
            wc_add_notice( $selected->get_error_message(), 'error' );
            return false;
        }
        foreach ( $selected as $row ) {
            $accessory = wc_get_product( $row['product_id'] );
            if ( ! $accessory ) {
                continue;
            }
            $customer_qty = max( 1, absint( $row['customer_qty'] ?? 1 ) );
            $total_qty    = 'per_device' === $row['mode'] ? $customer_qty * max( 1, absint( $quantity ) ) : $customer_qty;
            if ( $accessory->is_sold_individually() && $total_qty > 1 ) {
                /* translators: %s: accessory option label. */
                wc_add_notice( sprintf( __( '"%s is configured as an item that can only be sold individually and can therefore only be added once.', 'patsch9-rental-engine' ), $row['label'] ?: $accessory->get_name() ), 'error' );
                return false;
            }
            if ( $accessory->managing_stock() && ! $accessory->has_enough_stock( $total_qty ) ) {
                /* translators: %s: accessory option label. */
                wc_add_notice( sprintf( __( 'There is not enough stock for %s.', 'patsch9-rental-engine' ), $row['label'] ?: $accessory->get_name() ), 'error' );
                return false;
            }
        }
        return $passed;
    }

    public function cart_item_data( $data, $product_id, $variation_id ) {
        unset( $variation_id );
        if ( empty( $data['rmwc_rental'] ) ) {
            return $data;
        }
        $selected = $this->requested_selections( $product_id );
        if ( is_wp_error( $selected ) ) {
            return $data;
        }
        $data['rmwc_rental']['v2_accessories'] = array_map(
            static function( $row ) {
                return [
                    'group'      => sanitize_text_field( $row['group'] ),
                    'product_id' => absint( $row['product_id'] ),
                    'label'      => sanitize_text_field( $row['label'] ),
                    'mode'         => sanitize_key( $row['mode'] ),
                    'customer_qty' => max( 1, absint( $row['customer_qty'] ?? 1 ) ),
                ];
            },
            $selected
        );
        return $data;
    }

    public function add_linked_accessories( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        unset( $variation_id, $variation );
        if ( $this->syncing_cart || empty( $cart_item_data['rmwc_rental']['v2_accessories'] ) ) {
            return;
        }
        $this->syncing_cart = true;
        $added_children = [];
        try {
            foreach ( $cart_item_data['rmwc_rental']['v2_accessories'] as $row ) {
                $accessory_id = absint( $row['product_id'] ?? 0 );
                $customer_qty = max( 1, absint( $row['customer_qty'] ?? 1 ) );
                $qty = 'per_device' === sanitize_key( $row['mode'] ?? 'once' ) ? $customer_qty * max( 1, absint( $quantity ) ) : $customer_qty;
                $added = RMWC_V2_Accessories::instance()->add_to_cart_for_rental(
                    $accessory_id,
                    $qty,
                    [
                        'rmwc_rental_accessory_parent' => $cart_item_key,
                        'rmwc_rental_accessory_parent_product' => absint( $product_id ),
                        'rmwc_rental_accessory_group'  => sanitize_text_field( $row['group'] ?? '' ),
                        'rmwc_rental_accessory_label'  => sanitize_text_field( $row['label'] ?? '' ),
                        'rmwc_rental_accessory_mode'   => sanitize_key( $row['mode'] ?? 'once' ),
                        'rmwc_rental_accessory_customer_qty' => $customer_qty,
                    ]
                );
                if ( ! $added ) {
                    // Roll back the whole logical bundle. The cart must never contain
                    // already-added accessories after a later accessory failed.
                    foreach ( $added_children as $child_key ) {
                        WC()->cart->remove_cart_item( $child_key );
                    }
                    WC()->cart->remove_cart_item( $cart_item_key );
                    wc_add_notice( __( 'A selected rental accessory could not be added. The booking position was completely reset. Please try again.', 'patsch9-rental-engine' ), 'error' );
                    break;
                }
                $added_children[] = (string) $added;
            }
        } finally {
            $this->syncing_cart = false;
        }
    }

    public function remove_linked_accessories( $removed_key, $cart ) {
        if ( $this->syncing_cart || ! $cart instanceof WC_Cart ) {
            return;
        }
        $this->syncing_cart = true;
        try {
            foreach ( $cart->get_cart() as $key => $item ) {
                if ( $removed_key === ( $item['rmwc_rental_accessory_parent'] ?? '' ) ) {
                    $cart->remove_cart_item( $key );
                }
            }
        } finally {
            $this->syncing_cart = false;
        }
    }

    public function sync_linked_quantities( $cart_item_key, $quantity, $old_quantity, $cart ) {
        unset( $old_quantity );
        if ( $this->syncing_cart || ! $cart instanceof WC_Cart ) {
            return;
        }
        $this->syncing_cart = true;
        try {
            $updated_item = $cart->get_cart_item( $cart_item_key );
            if ( is_array( $updated_item ) && 'yes' === ( $updated_item['rmwc_rental_accessory'] ?? '' ) ) {
                $parent_key = (string) ( $updated_item['rmwc_rental_accessory_parent'] ?? '' );
                $mode       = sanitize_key( $updated_item['rmwc_rental_accessory_mode'] ?? 'once' );
                $base_qty   = max( 1, absint( $quantity ) );
                if ( 'per_device' === $mode && '' !== $parent_key ) {
                    $parent_item = $cart->get_cart_item( $parent_key );
                    $parent_qty  = is_array( $parent_item ) ? max( 1, absint( $parent_item['quantity'] ?? 1 ) ) : 1;
                    $base_qty    = max( 1, (int) round( max( 1, absint( $quantity ) ) / $parent_qty ) );
                    $normalized  = $base_qty * $parent_qty;
                    if ( $normalized !== absint( $quantity ) ) {
                        $cart->set_quantity( $cart_item_key, $normalized, false );
                    }
                }
                $cart->cart_contents[ $cart_item_key ]['rmwc_rental_accessory_customer_qty'] = $base_qty;
                return;
            }

            foreach ( $cart->get_cart() as $key => $item ) {
                if ( $cart_item_key !== ( $item['rmwc_rental_accessory_parent'] ?? '' ) ) {
                    continue;
                }
                if ( 'per_device' === sanitize_key( $item['rmwc_rental_accessory_mode'] ?? 'once' ) ) {
                    $base_qty = max( 1, absint( $item['rmwc_rental_accessory_customer_qty'] ?? 1 ) );
                    $cart->set_quantity( $key, $base_qty * max( 1, absint( $quantity ) ), false );
                }
            }
        } finally {
            $this->syncing_cart = false;
        }
    }

    public function validate_linked_accessories() {
        if ( ! WC()->cart ) {
            return;
        }
        $keys = array_keys( WC()->cart->get_cart() );
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( 'yes' !== ( $item['rmwc_rental_accessory'] ?? '' ) ) {
                continue;
            }
            $parent = (string) ( $item['rmwc_rental_accessory_parent'] ?? '' );
            if ( '' === $parent || ! in_array( $parent, $keys, true ) ) {
                wc_add_notice( __( 'A rental accessory is in the shopping cart without any related rental items. Please remove the item and choose the accessory again via the rental item.', 'patsch9-rental-engine' ), 'error' );
            }
        }
    }

    public function remove_accessory_permalink( $permalink, $cart_item, $cart_item_key ) {
        unset( $cart_item_key );
        return 'yes' === ( $cart_item['rmwc_rental_accessory'] ?? '' ) ? false : $permalink;
    }

    public function accessory_cart_item_label( $item_data, $cart_item ) {
        if ( 'yes' !== ( $cart_item['rmwc_rental_accessory'] ?? '' ) ) {
            return $item_data;
        }
        if ( ! empty( $cart_item['rmwc_rental_accessory_group'] ) ) {
            $item_data[] = [ 'key' => __( 'Rental accessories', 'patsch9-rental-engine' ), 'value' => sanitize_text_field( $cart_item['rmwc_rental_accessory_group'] ) ];
        }
        return $item_data;
    }

    public function accessory_order_meta( $item, $cart_item_key, $values, $order ) {
        unset( $cart_item_key, $order );
        if ( ! $item instanceof WC_Order_Item_Product || 'yes' !== ( $values['rmwc_rental_accessory'] ?? '' ) ) {
            return;
        }
        $item->add_meta_data( '_rmwc_rental_accessory', 'yes', true );
        $item->add_meta_data( '_rmwc_rental_parent_product', absint( $values['rmwc_rental_accessory_parent_product'] ?? 0 ), true );
        $item->add_meta_data( '_rmwc_rental_accessory_group', sanitize_text_field( $values['rmwc_rental_accessory_group'] ?? '' ), true );
        $item->add_meta_data( '_rmwc_rental_accessory_label', sanitize_text_field( $values['rmwc_rental_accessory_label'] ?? '' ), true );
        $item->add_meta_data( '_rmwc_rental_accessory_mode', sanitize_key( $values['rmwc_rental_accessory_mode'] ?? 'once' ), true );
        $item->add_meta_data( '_rmwc_rental_accessory_customer_qty', max( 1, absint( $values['rmwc_rental_accessory_customer_qty'] ?? 1 ) ), true );
        if ( ! empty( $values['rmwc_rental_accessory_group'] ) ) {
            $item->add_meta_data( __( 'Rental accessories', 'patsch9-rental-engine' ), sanitize_text_field( $values['rmwc_rental_accessory_group'] ), true );
        }
    }
}
