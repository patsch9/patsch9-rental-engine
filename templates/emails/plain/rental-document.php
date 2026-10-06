<?php
/**
 * Plain text rental document customer email.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( "==========\n" . wp_strip_all_tags( (string) $email_heading ) . "\n==========\n\n" );
echo esc_html( wp_strip_all_tags( (string) $message_body ) . "\n\n" );
if ( $order instanceof WC_Order ) {
    /* translators: %s: WooCommerce order number. */
    $rmwc_plain_line = sprintf( __( 'Bestellung #%s – der PDF-Beleg befindet sich im Anhang.', 'patsch9-rental-engine' ), sanitize_text_field( $order->get_order_number() ) );
    echo esc_html( $rmwc_plain_line . "\n" );
}
