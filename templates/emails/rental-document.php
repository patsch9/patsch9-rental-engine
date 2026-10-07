<?php
/**
 * Rental document customer email.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WooCommerce email template hook; the hook name is defined by WooCommerce and must not be prefixed.
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo wp_kses_post( wpautop( $message_body ) ); ?>

<?php if ( $order instanceof WC_Order ) : ?>
    <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s order number. */
                __( 'Zu Ihrer Bestellung <strong>#%s</strong> finden Sie den zugehörigen PDF-Beleg im Anhang.', 'patsch9-rental-engine' ),
                esc_html( $order->get_order_number() )
            )
        );
        ?>
    </p>
<?php endif; ?>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WooCommerce email template hook; the hook name is defined by WooCommerce and must not be prefixed.
do_action( 'woocommerce_email_footer', $email );
