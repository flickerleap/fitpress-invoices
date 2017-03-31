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

    }

    public function init_hooks(){

        if( !wp_get_schedule('send_monthly_invoices') ):
            $start = strtotime( 'tomorrow' );
            wp_schedule_event( $start, 'daily', 'send_monthly_invoices' );
        endif;

        add_action( 'send_monthly_invoices' , __CLASS__ . '::maybe_send_monthly_invoices' );

    }

	/**
	* Adds credits to user if subscription is successfully activated
	*
	* @param int $user_id A user ID for the user that the subscription was activated for.
	* @param mixed $subscription_key The key referring to the activated subscription
	* @version 1.0
	* @since 0.1
	*/
	public static function maybe_send_monthly_invoices( $force = false ) {

		if( date('j') == 25 || $force ):

			$members = FP_Membership::get_members( );

			$memberships = FP_Membership::get_memberships( );

			// Check for results
			if (!empty($members)) {

                $next_day = strtotime( 'tomorrow', current_time( 'timestamp' ) );

			    foreach ( $members as $member_id ){

			        $next_invoice_date = get_user_meta( $member_id, 'fitpress_next_invoice_date', true );

			        if( !$next_invoice_date || $next_invoice_date != 'Once Off' && $next_day >= $next_invoice_date ):

				        $membership_id = get_user_meta( $member_id, 'fitpress_membership_id', true );

				    	FP_Invoice::create_invoice( $member_id, $membership_id );

				    endif;

			    }

			}

		endif;

	}

	public static function create_invoice( $member_id, $membership_id, $old_membership_id = null, $prorate = false ){

		if( $old_membership_id && $prorate ):

			$memberships = FP_Membership::get_membership( array( $membership_id, $old_membership_id ) );

			$old_price = $memberships[$old_membership_id]['price'];
			$new_price = $memberships[$membership_id]['price'];

			if( !isset( $memberships[$membership_id]['term'] ) || $memberships[$membership_id]['term'] == '+1 Month' ):

				update_user_meta( $member_id, 'fitpress_next_invoice_date', strtotime('25th of this month') );

			elseif( $memberships[$membership_id]['term'] == 'Once Off' ):

				update_user_meta( $member_id, 'fitpress_next_invoice_date', $memberships[$membership_id]['term'] );

			else:

				update_user_meta( $member_id, 'fitpress_next_invoice_date', strtotime( '25 ' . date( 'F Y', strtotime( $memberships[$membership_id]['term'] ) ) ) );

			endif;

			if( $new_price > $old_price ):

				$memberships[$membership_id]['price'] = self::prorate_price( $new_price - $old_price );

			else:

				return;

			endif;

			$line_items[] = $memberships[$membership_id];

			$invoice_number = get_option( 'fitpress_invoice_number', 0 );

			$new_invoice_number = $invoice_number++;

			update_option( 'fitpress_invoice_number', $new_invoice_number );

			$invoice_number = str_pad( $new_invoice_number, 9, 'INV000000', STR_PAD_LEFT);

			$post = array(
				'post_title' => date( 'F', strtotime( '+1 month') ) . '\'s Invoice #' . $invoice_number,
				'post_type' => 'fp_invoice',
				'post_status' => 'fp-unpaid'
			);

			$new_post_id = wp_insert_post( $post );

			update_post_meta( $new_post_id, 'fp_invoice_line_items', $line_items );
			update_post_meta( $new_post_id, 'fp_invoice_number', $invoice_number );
			update_post_meta( $new_post_id, 'fp_invoice_date', date( 'Y/m/d') );
			update_post_meta( $new_post_id, 'fp_invoice_due_date', date( 'Y/m/d' ) );
			update_post_meta( $new_post_id, 'fp_user_id', $member_id );

			self::send_invoice( $new_post_id );

		elseif( $prorate ):

			$memberships = FP_Membership::get_membership( array( $membership_id ) );

			$memberships[$membership_id]['price'] = self::prorate_price( $memberships[$membership_id]['price'] );

			if( !isset( $memberships[$membership_id]['term'] ) || $memberships[$membership_id]['term'] == '+1 Month' ):

				update_user_meta( $member_id, 'fitpress_next_invoice_date', strtotime('25th of this month') );

			elseif( $memberships[$membership_id]['term'] == 'Once Off' ):

				update_user_meta( $member_id, 'fitpress_next_invoice_date', $memberships[$membership_id]['term'] );

			else:

				update_user_meta( $member_id, 'fitpress_next_invoice_date', strtotime( '25 ' . date( 'F Y', strtotime( $memberships[$membership_id]['term'] ) ) ) );

			endif;

			$line_items[] = $memberships[$membership_id];

			$invoice_number = get_option( 'fitpress_invoice_number', 0 );

			$new_invoice_number = $invoice_number++;

			update_option( 'fitpress_invoice_number', $new_invoice_number );

			$invoice_number = str_pad( $new_invoice_number, 9, 'INV000000', STR_PAD_LEFT);

			$post = array(
				'post_title' => date( 'F' ) . '\'s Prorated Invoice #' . $invoice_number,
				'post_type' => 'fp_invoice',
				'post_status' => 'fp-unpaid'
			);

			$new_post_id = wp_insert_post( $post );

			update_post_meta( $new_post_id, 'fp_invoice_line_items', $line_items );
			update_post_meta( $new_post_id, 'fp_invoice_number', $invoice_number );
			update_post_meta( $new_post_id, 'fp_invoice_date', date( 'Y/m/d' ) );
			update_post_meta( $new_post_id, 'fp_invoice_due_date', date( 'Y/m/d' ) );
			update_post_meta( $new_post_id, 'fp_user_id', $member_id );

			self::send_invoice( $new_post_id );

		else:

			$memberships = FP_Membership::get_membership( array( $membership_id ) );

			if( !isset( $memberships[$membership_id]['term'] ) || $memberships[$membership_id]['term'] == '+1 Month' ):

				update_user_meta( $member_id, 'fitpress_next_invoice_date', strtotime( '25 ' . date( 'F Y', strtotime( '+1 Month' ) ) ) );

			elseif( $memberships[$membership_id]['term'] == 'Once Off' ):

				update_user_meta( $member_id, 'fitpress_next_invoice_date', $memberships[$membership_id]['term'] );

			else:

				update_user_meta( $member_id, 'fitpress_next_invoice_date', strtotime( '25 ' . date( 'F Y', strtotime( $memberships[$membership_id]['term'] ) ) ) );

			endif;

			$line_items[] = $memberships[$membership_id];

			$invoice_number = get_option( 'fitpress_invoice_number', 0 );

			$new_invoice_number = $invoice_number + 1;

			update_option( 'fitpress_invoice_number', $new_invoice_number );

			$invoice_number = str_pad( $new_invoice_number, 9, 'INV000000', STR_PAD_LEFT);

			$post = array(
				'post_title' => date( 'F', strtotime( '+1 month') ) . '\'s Invoice #' . $invoice_number,
				'post_type' => 'fp_invoice',
				'post_status' => 'fp-unpaid'
			);

			$new_post_id = wp_insert_post( $post );

			update_post_meta( $new_post_id, 'fp_invoice_line_items', $line_items );
			update_post_meta( $new_post_id, 'fp_invoice_number', $invoice_number );
			update_post_meta( $new_post_id, 'fp_invoice_date', date('Y/m/d') );
			update_post_meta( $new_post_id, 'fp_invoice_due_date', date('Y/m/d',  strtotime( '1' . date( 'F Y', strtotime('+1 month') ) ) ) );
			update_post_meta( $new_post_id, 'fp_user_id', $member_id );

			self::send_invoice( $new_post_id );

		endif;

		return;

	}

	public static function send_invoice( $invoice_id, $prorate = false ){

		$invoice = get_post( $invoice_id );
		$line_items = get_post_meta( $invoice_id, 'fp_invoice_line_items', true );

		$invoice_data = array(
			'number' => get_post_meta( $invoice_id, 'fp_invoice_number', true ),
			'date' => get_post_meta( $invoice_id, 'fp_invoice_date', true ),
			'due_date' => get_post_meta( $invoice_id, 'fp_invoice_due_date',  true ),
		);
		$member_id = get_post_meta( $invoice_id, 'fp_user_id', true );
		$member = get_user_by('id', $member_id);

		$FP_Email = new FP_Email( array( 'template' => 'email/invoice.php' ) );

		$FP_Email->send_email( $member->user_email, $invoice->post_title, array( 'header' => 'Tax Invoice', 'line_items' => $line_items, 'invoice' => $invoice_data, 'member' => $member ) );

	}

	public static function prorate_price( $price ){

		$days_in_month = date( 't' );
		$days_left = $days_in_month - date( 'j' );

		$prorated_price = $price / $days_in_month * $days_left;

		return round( $prorated_price, -1 );

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
