from pathlib import Path
import re


def read(path):
    return Path(path).read_text(encoding="utf-8")


def write(path, text):
    Path(path).write_text(text, encoding="utf-8")


def replace_once(path, old, new):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{path}: expected one literal match, got {count}")
    write(path, text.replace(old, new, 1))


def sub_once(path, pattern, replacement, flags=0):
    text = read(path)
    updated, count = re.subn(pattern, replacement, text, count=1, flags=flags)
    if count != 1:
        raise RuntimeError(f"{path}: expected one regex match, got {count}: {pattern}")
    write(path, updated)


helper = '''<?php
/**
 * Resolve canonical WooCommerce product IDs for rental configuration.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_Product_Context {
    private static function variation_parent_id( $product_id ) {
        $product = $product_id ? wc_get_product( absint( $product_id ) ) : false;
        if ( ! $product instanceof WC_Product || ! $product->is_type( 'variation' ) ) {
            return 0;
        }
        return absint( $product->get_parent_id() );
    }

    public static function rental_config_id( $product_id, $variation_id = 0 ) {
        $product_id   = absint( $product_id );
        $variation_id = absint( $variation_id );

        if ( $variation_id && 'yes' === get_post_meta( $variation_id, '_rmwc_rental_enabled', true ) ) {
            return $variation_id;
        }
        if ( $product_id && 'yes' === get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            return $product_id;
        }

        $candidate_id = $variation_id ?: $product_id;
        $parent_id    = self::variation_parent_id( $candidate_id );
        if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_rmwc_rental_enabled', true ) ) {
            return $parent_id;
        }

        return 0;
    }

    public static function accessory_config_id( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return 0;
        }
        if ( 'yes' === get_post_meta( $product_id, '_rmwc_accessory_only', true ) ) {
            return $product_id;
        }

        $parent_id = self::variation_parent_id( $product_id );
        if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_rmwc_accessory_only', true ) ) {
            return $parent_id;
        }

        return 0;
    }
}
'''
write('includes/class-rmwc-product-context.php', helper)

replace_once(
    'patsch9-rental-engine.php',
    "require_once plugin_dir_path( __FILE__ ) . 'includes/class-rmwc-required-accessories.php';\n",
    "require_once plugin_dir_path( __FILE__ ) . 'includes/class-rmwc-required-accessories.php';\nrequire_once plugin_dir_path( __FILE__ ) . 'includes/class-rmwc-product-context.php';\n",
)

sub_once(
    'includes/class-rmwc-plugin.php',
    r"    private function config_product_id\( \$product_id, \$variation_id = 0 \) \{.*?\n    \}\n\n    private function pickup_time",
    "    private function config_product_id( $product_id, $variation_id = 0 ) {\n        return RMWC_Product_Context::rental_config_id( $product_id, $variation_id );\n    }\n\n    private function pickup_time",
    re.S,
)

replace_once(
    'includes/class-rmwc-plugin.php',
    """                    $old_rental = $item->get_meta( '_rmwc_rental_data', true );
                    $old_pickup = $item->get_meta( 'Abholung', true );
                    $old_return = $item->get_meta( 'Rückgabe', true );
""",
    """                    $old_rental = $item->get_meta( '_rmwc_rental_data', true );
                    $pickup_key = __( 'Rental start', 'patsch9-rental-engine' );
                    $return_key = __( 'Return', 'patsch9-rental-engine' );
                    $pickup_meta_keys = array_values( array_unique( [
                        $pickup_key,
                        'Rental start',
                        __( 'Pickup / rental start', 'patsch9-rental-engine' ),
                        'Pickup / rental start',
                        'Abholung',
                    ] ) );
                    $return_meta_keys = array_values( array_unique( [ $return_key, 'Return', 'Rückgabe' ] ) );
                    $old_pickup_meta = [];
                    $old_return_meta = [];
                    foreach ( $pickup_meta_keys as $meta_key ) {
                        if ( $item->meta_exists( $meta_key ) ) {
                            $old_pickup_meta[ $meta_key ] = $item->get_meta( $meta_key, true );
                        }
                    }
                    foreach ( $return_meta_keys as $meta_key ) {
                        if ( $item->meta_exists( $meta_key ) ) {
                            $old_return_meta[ $meta_key ] = $item->get_meta( $meta_key, true );
                        }
                    }
""",
)

replace_once(
    'includes/class-rmwc-plugin.php',
    """                        $item->update_meta_data( '_rmwc_rental_data', $rental );
                        $item->update_meta_data( __( 'Pickup / rental start', 'patsch9-rental-engine' ), $this->format_datetime_display( $interval['start_at'] ) );
                        $item->update_meta_data( __( 'Return', 'patsch9-rental-engine' ), $this->format_datetime_display( $interval['end_at'] ) );
""",
    """                        $item->update_meta_data( '_rmwc_rental_data', $rental );
                        foreach ( $pickup_meta_keys as $meta_key ) {
                            if ( $pickup_key !== $meta_key ) {
                                $item->delete_meta_data( $meta_key );
                            }
                        }
                        foreach ( $return_meta_keys as $meta_key ) {
                            if ( $return_key !== $meta_key ) {
                                $item->delete_meta_data( $meta_key );
                            }
                        }
                        $item->update_meta_data( $pickup_key, $this->format_datetime_display( $interval['start_at'] ) );
                        $item->update_meta_data( $return_key, $this->format_datetime_display( $interval['end_at'] ) );
""",
)

replace_once(
    'includes/class-rmwc-plugin.php',
    """                            $item->update_meta_data( '_rmwc_rental_data', $old_rental );
                            $item->update_meta_data( __( 'Pickup / rental start', 'patsch9-rental-engine' ), $old_pickup );
                            $item->update_meta_data( __( 'Return', 'patsch9-rental-engine' ), $old_return );
                            $item->save();
""",
    """                            $item->update_meta_data( '_rmwc_rental_data', $old_rental );
                            foreach ( array_unique( array_merge( $pickup_meta_keys, $return_meta_keys ) ) as $meta_key ) {
                                $item->delete_meta_data( $meta_key );
                            }
                            foreach ( $old_pickup_meta as $meta_key => $meta_value ) {
                                $item->update_meta_data( $meta_key, $meta_value );
                            }
                            foreach ( $old_return_meta as $meta_key => $meta_value ) {
                                $item->update_meta_data( $meta_key, $meta_value );
                            }
                            $item->save();
""",
)

text = read('includes/class-rmwc-plugin.php')
manual_start = text.index("        if ( 'add_manual' === $action ) {")
manual_end = text.index("        if ( 'add_block' === $action ) {", manual_start)
manual = text[manual_start:manual_end]
old = "$interval = $this->build_interval( $product_id, $start_date, $end_date );"
if manual.count(old) != 1:
    raise RuntimeError('add_manual: build_interval call not found exactly once')
manual = manual.replace(old, "$interval = $this->validate_interval_for_product( $product_id, $start_date, $end_date, $qty );", 1)
write('includes/class-rmwc-plugin.php', text[:manual_start] + manual + text[manual_end:])

sub_once(
    'includes/class-rmwc-plugin.php',
    r"            <h2><\?php esc_html_e\( 'Data on uninstall', 'patsch9-rental-engine' \); \?></h2>\s*            <h2><\?php esc_html_e\( 'Reservations', 'patsch9-rental-engine' \); \?></h2>\s*(            <table class=\"form-table\"><tbody><tr><th><label for=\"rmwc_unpaid_hold_minutes\".*?</table>)\s*(            <table class=\"form-table\"><tbody><tr><th><\?php esc_html_e\( 'Cleanup'.*?</table>)",
    "            <h2><?php esc_html_e( 'Reservations', 'patsch9-rental-engine' ); ?></h2>\n\\1\n            <h2><?php esc_html_e( 'Data on uninstall', 'patsch9-rental-engine' ); ?></h2>\n\\2",
    re.S,
)

# The general admin script also contains workflow behavior, so it must run on the rental admin page.
replace_once(
    'includes/class-rmwc-plugin.php',
    """        if ( $this->admin_page_hook && $hook === $this->admin_page_hook ) {
            wp_enqueue_style( 'patsch9-rental-engine-admin', RMWC_URL . 'assets/admin.css', [], RMWC_VERSION );
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }
""",
    """        if ( $this->admin_page_hook && $hook === $this->admin_page_hook ) {
            wp_enqueue_style( 'patsch9-rental-engine-admin', RMWC_URL . 'assets/admin.css', [], RMWC_VERSION );
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
            wp_enqueue_script( 'patsch9-rental-engine-admin-product', RMWC_URL . 'assets/admin.js', [ 'jquery', 'wc-enhanced-select' ], RMWC_VERSION, true );
        }
""",
)

sub_once(
    'includes/v2/modules/class-rmwc-v2-workflow.php',
    r"    private function rental_data_for_booking\( array \$booking, WC_Order \$order \) \{.*?\n    \}\n\n    private function fulfilment_label",
    """    private function rental_data_for_booking( array $booking, WC_Order $order ) {
        $order_item_id = absint( $booking['order_item_id'] ?? 0 );
        $product_id    = absint( $booking['product_id'] ?? 0 );
        if ( $order_item_id ) {
            $item = $order->get_item( $order_item_id );
            if ( ! $item instanceof WC_Order_Item_Product ) {
                return [];
            }
            $candidate = $item->get_meta( '_rmwc_rental_data', true );
            return is_array( $candidate ) && absint( $candidate['product_id'] ?? 0 ) === $product_id ? $candidate : [];
        }

        // Legacy rows may predate order_item_id. Only those rows use the
        // product-ID fallback because identical products can carry different data.
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $candidate = $item->get_meta( '_rmwc_rental_data', true );
            if ( is_array( $candidate ) && absint( $candidate['product_id'] ?? 0 ) === $product_id ) {
                return $candidate;
            }
        }
        return [];
    }

    private function fulfilment_label""",
    re.S,
)

replace_once(
    'includes/v2/modules/class-rmwc-v2-workflow.php',
    '<div class="rmwc-workflow-deposit-settlement" data-available="<?php echo esc_attr( wc_format_decimal( $deposit_state[\'available\'] ) ); ?>">',
    '<div class="rmwc-workflow-deposit-settlement" data-available="<?php echo esc_attr( wc_format_decimal( $deposit_state[\'available\'] ) ); ?>" data-currency="<?php echo esc_attr( $order->get_currency() ); ?>" data-locale="<?php echo esc_attr( str_replace( \'_\', \'-\', get_locale() ) ); ?>">',
)

sub_once(
    'includes/v2/modules/class-rmwc-v2-shipping.php',
    r"        \$product_id = \$product->get_id\(\);.*?\n        return \$needs_shipping;",
    """        $product_id   = $product->get_id();
        $variation_id = $product->is_type( 'variation' ) ? $product_id : 0;
        if ( RMWC_Product_Context::rental_config_id( $product_id, $variation_id ) ) {
            return false;
        }
        if ( RMWC_Product_Context::accessory_config_id( $product_id ) ) {
            return false;
        }

        return $needs_shipping;""",
    re.S,
)

replace_once(
    'includes/v2/modules/class-rmwc-v2-materials.php',
    """    public function validate_selection( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        unset( $variation_id, $variations );
        if ( 'yes' !== get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            return $passed;
        }
        $selected = $this->requested_selections( $product_id );
""",
    """    public function validate_selection( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        unset( $variations );
        $config_product_id = RMWC_Product_Context::rental_config_id( $product_id, $variation_id );
        if ( ! $config_product_id ) {
            return $passed;
        }
        $selected = $this->requested_selections( $config_product_id );
""",
)

replace_once(
    'includes/v2/modules/class-rmwc-v2-inventory.php',
    """        $asset_id   = isset( $_POST['asset_id'] ) ? absint( wp_unslash( $_POST['asset_id'] ) ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        if ( 'yes' !== get_post_meta( $product_id, '_rmwc_rental_enabled', true ) ) {
            $this->redirect( 'invalid_product' );
        }
""",
    """        $asset_id   = isset( $_POST['asset_id'] ) ? absint( wp_unslash( $_POST['asset_id'] ) ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        $product_id = RMWC_Product_Context::rental_config_id( $product_id, $product_id );
        if ( ! $product_id ) {
            $this->redirect( 'invalid_product' );
        }
""",
)

path = 'includes/v2/modules/class-rmwc-v2-documents.php'
text = read(path)
start = text.index('    private function render_deposit_pdf')
end = text.index('    private function render_workflow_pdf', start)
section = text[start:end]
section, count = re.subn(r'return trim\( str_replace\( ".*?", \' \', \$value \) \);', 'return trim( str_replace( "\\\\xc2\\\\xa0", \' \', $value ) );', section, count=1)
if count != 1:
    raise RuntimeError(f'{path}: deposit NBSP normalization not found')
write(path, text[:start] + section + text[end:])

replace_once(
    'assets/admin.js',
    "  function money(v){return (Math.max(0,v)||0).toFixed(2).replace('.',',')+' €';}\n",
    """  function money(v,$box){
    const amount=Math.max(0,Number(v)||0);
    const currency=String($box.data('currency')||'EUR').toUpperCase();
    const locale=String($box.data('locale')||document.documentElement.lang||'en-US').replace('_','-');
    try{return new Intl.NumberFormat(locale,{style:'currency',currency}).format(amount);}catch(e){return amount.toFixed(2)+' '+currency;}
  }
""",
)
replace_once('assets/admin.js', '.text(money(remaining));', '.text(money(remaining,$box));')

replace_once(
    'assets/rental.js',
    """  let start = null, end = null;
  let deliveryFee = 0;
""",
    """  let start = null, end = null;
  let deliveryFee = 0;
  let feedback = box.find('.rmwc-rental-feedback');
  if(!feedback.length){
    feedback=$('<p class="rmwc-rental-feedback" role="alert" aria-live="polite"></p>').insertAfter(cal);
  }
  const showFeedback = message => feedback.addClass('is-error').text(message);
  const clearFeedback = () => feedback.removeClass('is-error').text('');
""",
)
replace_once(
    'assets/rental.js',
    """  cal.on('click','.rmwc-cal-day',function(){
    const d=parseDate($(this).data('date')); if(!d) return;
""",
    """  cal.on('click','.rmwc-cal-day',function(){
    const d=parseDate($(this).data('date')); if(!d) return;
    clearFeedback();
""",
)
replace_once('assets/rental.js', "alert(t('returnNotPossible','Returns are not possible on this weekday.'));", "showFeedback(t('returnNotPossible','Returns are not possible on this weekday.'));")
replace_once('assets/rental.js', "alert(t('periodNotBookable','This period cannot be booked, does not meet the permitted rental duration, or there is not enough stock available.'));", "showFeedback(t('periodNotBookable','This period cannot be booked, does not meet the permitted rental duration, or there is not enough stock available.'));")
replace_once(
    'assets/rental.js',
    "if(!address){ alert(t('enterDeliveryAddress','Please enter the delivery address first.')); return; }",
    "if(!address){ $('#rmwc_delivery_result').addClass('is-error').text(t('enterDeliveryAddress','Please enter the delivery address first.')); $('#rmwc_delivery_address').trigger('focus'); return; }",
)

replace_once(
    'assets/rental.css',
    '.rmwc-calendar{margin:14px 0}\n',
    '.rmwc-calendar{margin:14px 0}\n.rmwc-rental-feedback{margin:8px 0 12px;font-weight:600;line-height:1.45}\n.rmwc-rental-feedback:empty{display:none}\n.rmwc-rental-feedback.is-error{color:#b32d2e}\n',
)

replace_once(
    'uninstall.php',
    "    '_rmwc_product_terms_snapshots',\n];\n",
    "    '_rmwc_product_terms_snapshots',\n    '_rmwc_document_integrity_warning',\n    '_rmwc_contract_email_document_id',\n    '_rmwc_contract_email_last_failed_at',\n    '_rmwc_mobile_action_note_signature',\n];\n",
)
replace_once(
    'uninstall.php',
    "'_rmwc_rental_accessory_group', '_rmwc_rental_accessory_label', '_rmwc_rental_accessory_mode' ]",
    "'_rmwc_rental_accessory_group', '_rmwc_rental_accessory_label', '_rmwc_rental_accessory_mode', '_rmwc_rental_accessory_customer_qty' ]",
)
replace_once(
    'uninstall.php',
    "    '_transient_rmwc_rate_%',\n    '_transient_timeout_rmwc_rate_%',\n",
    "    '_transient_rmwc_rate_%',\n    '_transient_timeout_rmwc_rate_%',\n    '_transient_rmwc_global_rate_%',\n    '_transient_timeout_rmwc_global_rate_%',\n",
)

regression = '''<?php
/** Regression coverage for the 2026-11 Google review findings. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RMWC_RUN_UPGRADE_TESTS' ) ) {
    throw new RuntimeException( 'Requires disposable database and RMWC_RUN_UPGRADE_TESTS=1.' );
}

$checks = 0;
$check = static function( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};

$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] );
if ( ! $admin_ids ) {
    throw new RuntimeException( 'Administrator fixture is missing.' );
}
wp_set_current_user( (int) $admin_ids[0] );

$parent = new WC_Product_Variable();
$parent->set_name( 'Review variable rental' );
$parent->set_status( 'publish' );
$parent->save();
$parent_id = $parent->get_id();
update_post_meta( $parent_id, '_rmwc_rental_enabled', 'yes' );

$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_regular_price( '10' );
$variation->set_status( 'publish' );
$variation->save();
$variation_id = $variation->get_id();

$check( $parent_id === RMWC_Product_Context::rental_config_id( $parent_id, $variation_id ), 'Variation inherits rental configuration from parent' );
$check( false === RMWC_V2_Shipping::instance()->product_needs_shipping( true, $variation ), 'Inherited rental variation does not trigger normal shipping' );
update_post_meta( $parent_id, '_rmwc_rental_enabled', 'no' );
update_post_meta( $parent_id, '_rmwc_accessory_only', 'yes' );
$check( $parent_id === RMWC_Product_Context::accessory_config_id( $variation_id ), 'Accessory variation inherits parent configuration' );
$check( false === RMWC_V2_Shipping::instance()->product_needs_shipping( true, $variation ), 'Inherited accessory variation does not trigger normal shipping' );

$product = new WC_Product_Simple();
$product->set_name( 'Review rental fixture' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->save();
$product_id = $product->get_id();
update_post_meta( $product_id, '_rmwc_rental_enabled', 'yes' );
update_post_meta( $product_id, '_rmwc_capacity', 3 );
update_post_meta( $product_id, '_rmwc_pickup_time', '12:00' );
update_post_meta( $product_id, '_rmwc_return_time', '10:00' );
update_post_meta( $product_id, '_rmwc_min_days', 1 );
update_post_meta( $product_id, '_rmwc_max_days', 0 );
update_post_meta( $product_id, '_rmwc_advance_days', 0 );

$order = wc_create_order();
foreach ( [ 'first', 'second' ] as $marker ) {
    $item = new WC_Order_Item_Product();
    $item->set_product( $product );
    $item->set_quantity( 1 );
    $item->add_meta_data( '_rmwc_rental_data', [ 'product_id' => $product_id, 'marker' => $marker ], true );
    $order->add_item( $item );
}
$order->save();
$items = array_values( $order->get_items( 'line_item' ) );
$workflow_method = new ReflectionMethod( RMWC_V2_Workflow::instance(), 'rental_data_for_booking' );
$workflow_method->setAccessible( true );
$matched = $workflow_method->invoke( RMWC_V2_Workflow::instance(), [ 'order_item_id' => $items[1]->get_id(), 'product_id' => $product_id ], $order );
$check( 'second' === ( $matched['marker'] ?? '' ), 'Workflow resolves duplicate products by order item ID' );

global $wpdb;
$plugin = RMWC_Plugin::instance();
$admin_actions = new ReflectionMethod( $plugin, 'admin_handle_actions' );
$admin_actions->setAccessible( true );
$old_request_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';

$blocked_start = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+30 days' );
$blocked_end = $blocked_start->modify( '+1 day' );
update_post_meta( $product_id, '_rmwc_blocked_pickup_days', [ (int) $blocked_start->format( 'N' ) ] );
$before_manual = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $wpdb->prefix . 'rmwc_bookings', $product_id ) );
$_POST = [
    'rmwc_action' => 'add_manual',
    'rmwc_admin_nonce' => wp_create_nonce( 'rmwc_admin_action' ),
    'product_id' => $product_id,
    'start_date' => $blocked_start->format( 'Y-m-d' ),
    'end_date' => $blocked_end->format( 'Y-m-d' ),
    'quantity' => 1,
];
$admin_actions->invoke( $plugin );
$after_manual = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $wpdb->prefix . 'rmwc_bookings', $product_id ) );
$check( $before_manual === $after_manual, 'Manual booking obeys product interval rules' );
update_post_meta( $product_id, '_rmwc_blocked_pickup_days', [] );

$period_order = wc_create_order();
$period_item = new WC_Order_Item_Product();
$period_item->set_product( $product );
$period_item->set_quantity( 1 );
$old_start = $blocked_start->modify( '+5 days' );
$old_end = $old_start->modify( '+2 days' );
$new_start = $old_start->modify( '+3 days' );
$new_end = $new_start->modify( '+2 days' );
$period_item->add_meta_data( '_rmwc_rental_data', [
    'product_id' => $product_id,
    'start' => $old_start->format( 'Y-m-d' ),
    'end' => $old_end->format( 'Y-m-d' ),
    'start_at' => $old_start->format( 'Y-m-d' ) . ' 12:00:00',
    'end_at' => $old_end->format( 'Y-m-d' ) . ' 10:00:00',
    'days' => 2,
], true );
$period_item->add_meta_data( __( 'Rental start', 'patsch9-rental-engine' ), 'old canonical', true );
$period_item->add_meta_data( __( 'Pickup / rental start', 'patsch9-rental-engine' ), 'stale duplicate', true );
$period_item->add_meta_data( 'Abholung', 'legacy duplicate', true );
$period_item->add_meta_data( __( 'Return', 'patsch9-rental-engine' ), 'old return', true );
$period_order->add_item( $period_item );
$period_order->save();
$period_item_id = $period_item->get_id();

$wpdb->insert( $wpdb->prefix . 'rmwc_bookings', [
    'product_id' => $product_id,
    'order_id' => $period_order->get_id(),
    'order_item_id' => $period_item_id,
    'source' => 'order',
    'start_date' => $old_start->format( 'Y-m-d' ),
    'end_date' => $old_end->format( 'Y-m-d' ),
    'start_at' => $old_start->format( 'Y-m-d' ) . ' 12:00:00',
    'end_at' => $old_end->format( 'Y-m-d' ) . ' 10:00:00',
    'quantity' => 1,
    'customer_name' => '',
    'customer_email' => '',
    'note' => '',
    'status' => 'reserved',
    'workflow_status' => 'reserved',
    'created_at' => current_time( 'mysql' ),
] );
$booking_id = (int) $wpdb->insert_id;
if ( ! $booking_id ) {
    throw new RuntimeException( 'Could not create booking fixture.' );
}
$_POST = [
    'rmwc_action' => 'update_booking',
    'rmwc_admin_nonce' => wp_create_nonce( 'rmwc_admin_action' ),
    'booking_id' => $booking_id,
    'start_date' => $new_start->format( 'Y-m-d' ),
    'end_date' => $new_end->format( 'Y-m-d' ),
    'note' => '',
];
$admin_actions->invoke( $plugin );
$period_order = wc_get_order( $period_order->get_id() );
$period_item = $period_order->get_item( $period_item_id );
$rental_data = $period_item->get_meta( '_rmwc_rental_data', true );
$check( $new_start->format( 'Y-m-d' ) === ( $rental_data['start'] ?? '' ), 'Admin booking update synchronizes rental data' );
$check( $period_item->meta_exists( __( 'Rental start', 'patsch9-rental-engine' ) ), 'Canonical rental-start metadata remains' );
$check( ! $period_item->meta_exists( __( 'Pickup / rental start', 'patsch9-rental-engine' ) ), 'Obsolete pickup metadata is removed' );
$check( ! $period_item->meta_exists( 'Abholung' ), 'Historic pickup metadata is removed' );

$documents_source = file_get_contents( RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-documents.php' );
$admin_source = file_get_contents( RMWC_DIR . 'assets/admin.js' );
$frontend_source = file_get_contents( RMWC_DIR . 'assets/rental.js' );
$uninstall_source = file_get_contents( RMWC_DIR . 'uninstall.php' );
$inventory_source = file_get_contents( RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-inventory.php' );
$materials_source = file_get_contents( RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-materials.php' );
$check( false !== strpos( $documents_source, 'str_replace( "\\xc2\\xa0"' ), 'Deposit PDFs normalize non-breaking spaces explicitly' );
$check( false === strpos( $admin_source, "+' €'" ), 'Admin deposit preview does not hard-code euro formatting' );
$check( false === strpos( $frontend_source, 'alert(' ), 'Rental frontend uses inline feedback instead of alerts' );
foreach ( [ '_rmwc_document_integrity_warning', '_rmwc_contract_email_document_id', '_rmwc_contract_email_last_failed_at', '_rmwc_mobile_action_note_signature', '_rmwc_rental_accessory_customer_qty', '_transient_rmwc_global_rate_%' ] as $cleanup_key ) {
    $check( false !== strpos( $uninstall_source, $cleanup_key ), 'Uninstall cleanup contains ' . $cleanup_key );
}
$check( false !== strpos( $inventory_source, 'RMWC_Product_Context::rental_config_id' ), 'Inventory uses shared variation resolver' );
$check( false !== strpos( $materials_source, 'RMWC_Product_Context::rental_config_id' ), 'Materials use shared variation resolver' );

$_POST = [];
if ( null === $old_request_method ) {
    unset( $_SERVER['REQUEST_METHOD'] );
} else {
    $_SERVER['REQUEST_METHOD'] = $old_request_method;
}
$wpdb->delete( $wpdb->prefix . 'rmwc_bookings', [ 'id' => $booking_id ], [ '%d' ] );
$period_order->delete( true );
$order->delete( true );
$product->delete( true );
$variation->delete( true );
$parent->delete( true );
WP_CLI::success( $checks . ' Google review regression checks passed.' );
'''
write('tests/test-google-review-regressions.php', regression)

replace_once(
    '.github/workflows/static-checks.yml',
    """      - name: Review authorization and notice regressions
        run: wp-env run cli env RMWC_RUN_UPGRADE_TESTS=1 wp eval-file wp-content/plugins/patsch9-rental-engine/tests/test-review-security.php
      - name: WordPress Plugin Check (strict)
""",
    """      - name: Review authorization and notice regressions
        run: wp-env run cli env RMWC_RUN_UPGRADE_TESTS=1 wp eval-file wp-content/plugins/patsch9-rental-engine/tests/test-review-security.php
      - name: Google review regressions
        run: wp-env run cli env RMWC_RUN_UPGRADE_TESTS=1 wp eval-file wp-content/plugins/patsch9-rental-engine/tests/test-google-review-regressions.php
      - name: WordPress Plugin Check (strict)
""",
)
