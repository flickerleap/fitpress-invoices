<?php
/**
 * @package FitPress
 */
/*
Plugin Name: FitPress Invoices
Plugin URI: http://fitpress.co.za
Description: FitPress Invoices is a add-on for FitPress that allows the system to add pricing to memberships and send invoices automatically
Version: 1.0
Author: Digital Leap
Author URI: http://digitalleap.co.za/wordpress/
License: GPLv2 or later
Text Domain: fitpress-invoices
*/

if ( ! defined( 'ABSPATH' ) ) :
	exit; // Exit if accessed directly
endif;

function is_fitpress_active(){

	/**
	 * Check if FitPress is active, and if it isn't, disable Invoices.
	 *
	 * @since 1.0
	 */
	if ( !is_plugin_active( 'fitpress/fitpress.php' ) ) {
		add_action( 'admin_notices', 'FP_Invoice::fitpress_inactive_notice' );
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}

}

add_action( 'admin_init', 'is_fitpress_active' );

class FP_Invoice {

	/* We only want a single instance of this class. */
	private static $instance = null;

	/*
	* Creates or returns an instance of this class.
	*
	* @return  FP_Invoice A single instance of this class.
	*/
	public static function get_instance( ) {
		if ( null == self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	} // end get_instance;



	/**
	 * Hook in methods.
	 */
	public function __construct(){

		$this->includes();
		$this->init_hooks();

		do_action( 'fitpress_loaded' );

	}

	public function includes(){

		include_once( 'includes/fp-invoices-utilities.php' );
		include_once( 'includes/class-fp-invoices-post-type.php' );
		include_once( 'includes/class-fp-invoices-admin.php' );
		include_once( 'includes/class-fp-invoice-run.php' );

	}

	public function init_hooks(){

	}

	public static function fitpress_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) :?>
			<div id="message" class="error">
				<p><?php printf( __( '%sFitPress is inactive%s. The FitPress plugin must be active for FitPress Invoices to work. Please install & activate FitPress.', 'fitpress-invoices' ), '<strong>', '</strong>' ); ?></p>
			</div>
		<?php endif;
	}

}

/**
 * Extension main function
 */
function __fp_invoices_main() {
	FP_Invoice::get_instance();
}

// Initialize plugin when plugins are loaded
add_action( 'plugins_loaded', '__fp_invoices_main' );
