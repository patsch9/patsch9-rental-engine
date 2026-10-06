<?php
/**
 * Version 2 module bootstrap.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_V2 {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        require_once RMWC_DIR . 'includes/v2/class-rmwc-pdf.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-shipping.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-terms.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-documents.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-deposits.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-inventory.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-accessories.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-materials.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-workflow.php';
        require_once RMWC_DIR . 'includes/v2/modules/class-rmwc-v2-integrations.php';

        RMWC_V2_Shipping::instance();
        RMWC_V2_Terms::instance();
        RMWC_V2_Documents::instance();
        RMWC_V2_Deposits::instance();
        RMWC_V2_Inventory::instance();
        RMWC_V2_Accessories::instance();
        RMWC_V2_Materials::instance();
        RMWC_V2_Workflow::instance();
        RMWC_V2_Integrations::instance();

        /**
         * Fires after all rental V2 modules are loaded.
         *
         * External integrations (including the separately installable Lexware
         * connector) may hook here without becoming a hard dependency.
         */
        do_action( 'rmwc_v2_loaded', $this );
    }
}
