<?php
/**
 * WooCommerce customer email for immutable rental documents.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

final class RMWC_Email_Document extends WC_Email {
    /** @var WC_Order|null */
    public $object;

    /** @var string */
    private $message_body = '';

    /** @var string[] */
    private $attachment_paths = [];

    /** @var string */
    private $runtime_subject = '';

    /** @var string */
    private $runtime_heading = '';

    public function __construct() {
        $this->id             = 'rmwc_document';
        $this->title          = __( 'Vermietung – Dokument', 'patsch9-rental-engine' );
        $this->description    = __( 'Wird für Mietverträge, Kautionsbelege sowie Übergabe- und Rückgabeprotokolle verwendet.', 'patsch9-rental-engine' );
        $this->customer_email = true;
        $this->template_html  = 'emails/rental-document.php';
        $this->template_plain = 'emails/plain/rental-document.php';
        $this->template_base  = RMWC_DIR . 'templates/';

        parent::__construct();
    }

    public function get_default_subject() {
        return __( 'Dokument zu Ihrer Vermietung #{order_number}', 'patsch9-rental-engine' );
    }

    public function get_default_heading() {
        return __( 'Dokument zu Ihrer Vermietung', 'patsch9-rental-engine' );
    }

    public function get_subject() {
        if ( '' !== $this->runtime_subject ) {
            return $this->format_string( $this->runtime_subject );
        }
        return parent::get_subject();
    }

    public function get_heading() {
        if ( '' !== $this->runtime_heading ) {
            return $this->format_string( $this->runtime_heading );
        }
        return parent::get_heading();
    }

    /**
     * Send a rental document email.
     *
     * @return bool Whether WooCommerce attempted to send the email successfully.
     */
    public function trigger_document( WC_Order $order, $subject, $heading, $body, $attachment_path ) {
        $this->object          = $order;
        $this->recipient       = sanitize_email( $order->get_billing_email() );
        $this->setup_locale();
        try {
            $this->message_body     = wp_kses_post( (string) $body );
            $paths                  = is_array( $attachment_path ) ? $attachment_path : [ $attachment_path ];
            $this->attachment_paths = array_values(
                array_filter(
                    array_map( static fn( $path ) => is_string( $path ) ? $path : '', $paths ),
                    static fn( $path ) => '' !== $path && is_file( $path )
                )
            );
            $this->runtime_subject = sanitize_text_field( (string) $subject );
            $this->runtime_heading = sanitize_text_field( (string) $heading );
            $this->placeholders['{order_number}'] = $order->get_order_number();

            if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
                return false;
            }

            return (bool) $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        } finally {
            $this->restore_locale();
            // Do not carry customer/document state into the next Woo mail send.
            $this->object          = null;
            $this->message_body    = '';
            $this->attachment_paths = [];
            $this->runtime_subject = '';
            $this->runtime_heading = '';
        }
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'message_body'  => $this->message_body,
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'message_body'  => $this->message_body,
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_attachments() {
        $attachments = parent::get_attachments();
        foreach ( $this->attachment_paths as $attachment_path ) {
            if ( is_file( $attachment_path ) ) {
                $attachments[] = $attachment_path;
            }
        }
        return array_values( array_unique( $attachments ) );
    }
}
