<?php
/**
 * Post Types
 *
 * Registers post types and taxonomies.
 *
 * @class     FP_Post_Types
 * @version   2.5.0
 * @package   FitPress/Classes/Products
 * @category  Class
 * @author    Digital Leap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FP_Post_Types Class.
 */
class FP_Payment {

	public $default_methods = array();

	/**
	 * Hook in methods.
	 */
    public function __construct(){

	}

	protected function get_defaults(){
		$defaults = array(
			//'debit-order' => 'Debit Order',
			//'eft' => 'Electronic Funds Transfer',
		);
		return $defaults;
	}

	public function get_methods(){

		return apply_filters( 'fitpress_payment_methods', $this->get_defaults() );

	}

}

/**
 * Extension main function
 */
function __fp_payment_main() {
    new FP_Payment();
}

// Initialize plugin when plugins are loaded
add_action( 'plugins_loaded', '__fp_payment_main' );
