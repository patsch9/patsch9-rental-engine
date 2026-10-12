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
                __( 'To your order <strong>#%s</strong> you will find the corresponding PDF document in the attachment.', 'patsch9-rental-engine' ),
                esc_html( $order->get_order_number() )
            )
        );
        ?>
    </p>
<?php endif; ?>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WooCommerce email template hook; the hook name is defined by WooCommerce and must not be prefixed.
do_action( 'woocommerce_email_footer', $email );
