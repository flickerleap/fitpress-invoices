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
	 * Check if WooCommerce is active, and if it isn't, disable Subscriptions.
	 *
	 * @since 1.0
	 */
	if ( !is_plugin_active( 'fitpress/fitpress.php' ) ) {
		add_action( 'admin_notices', 'FP_Invoice::woocommerce_inactive_notice' );
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}

}

add_action( 'admin_init', 'is_fitpress_active' );

class FP_Invoice {

	public function __construct(){

		add_action( 'init', array( $this, 'register_post_types' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_post_status' ), 9 );

		add_action( 'fitpress_after_membership_fields', array( $this, 'add_membership_fields' ) );
		add_filter( 'fitpress_before_membership_save', array( $this, 'save_membership_data' ) );

		add_action( 'fitpress_after_membership_profile_fields', array( $this, 'add_membership_profile_fields' ) );
		add_action( 'fitpress_before_membership_profile_save', array( $this, 'save_membership_profile_data' ) );

		if( !wp_get_schedule('send_monthly_invoices') ):
			$start = strtotime( 'tomorrow' );
			wp_schedule_event( $start, 'daily', 'send_monthly_invoices' );
		endif;

		add_action('send_monthly_invoices', __CLASS__ . '::maybe_send_monthly_invoices' );

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

			    foreach ( $members as $member_id ){

			        $membership_id = get_user_meta( $member_id, 'fitpress_membership_id', true );

			    	FP_Invoice::create_invoice( $member_id, $membership_id );

			    }

			}

		endif;

	}

	/**
	 * Register core post types.
	 */
	public static function register_post_types() {
		if ( post_type_exists('membership') ) {
			return;
		}

		do_action( 'action_register_post_type' );

		register_post_type( 'fp_invoice',
			array(
				'labels'             => array(
					'name'                  => __( 'Invoices', 'fitpress-invoices-invoices' ),
					'singular_name'         => __( 'Invoice', 'fitpress-invoices' ),
					'menu_name'             => _x( 'Invoices', 'Admin menu name', 'fitpress-invoices' ),
					'add_new'               => __( 'Add Invoice', 'fitpress-invoices' ),
					'add_new_item'          => __( 'Add New Invoice', 'fitpress-invoices' ),
					'edit'                  => __( 'Edit', 'fitpress-invoices' ),
					'edit_item'             => __( 'Edit Invoice', 'fitpress-invoices' ),
					'new_item'              => __( 'New Invoice', 'fitpress-invoices' ),
					'view'                  => __( 'View Invoice', 'f§itpress-invoices' ),
					'view_item'             => __( 'View Invoice', 'fitpress-invoices' ),
					'search_items'          => __( 'Search Invoices', 'fitpress-invoices' ),
					'not_found'             => __( 'No Invoices found', 'fitpress-invoices' ),
					'not_found_in_trash'    => __( 'No Invoices found in trash', 'fitpress-invoices' ),
					'parent'                => __( 'Parent Invoice', 'fitpress-invoices' ),
					'featured_image'        => __( 'Invoice Image', 'fitpress-invoices' ),
					'set_featured_image'    => __( 'Set invoice image', 'fitpress-invoices' ),
					'remove_featured_image' => __( 'Remove invoice image', 'fitpress-invoices' ),
					'use_featured_image'    => __( 'Use as invoice image', 'fitpress-invoices' ),
				),
				'description'         => __( 'This is where you can add new memberships to your website.', 'fitpress-invoices' ),
				'public'              => false,
				'show_ui'             => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => false,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
				'show_in_nav_menus'   => true
			)
		);
	}

	/**
	 * Register our custom post statuses, used for order status.
	 */
	public static function register_post_status() {
		register_post_status( 'fp-unpaid', array(
			'label'                     => _x( 'Unpaid', 'Invoice status', 'fitpress-invoice' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Pending payment <span class="count">(%s)</span>', 'Pending payment <span class="count">(%s)</span>', 'fitpress-invoice' )
		) );
		register_post_status( 'fp-paid', array(
			'label'                     => _x( 'Paid', 'Invoice status', 'fitpress-invoice' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Paid <span class="count">(%s)</span>', 'Processing <span class="count">(%s)</span>', 'fitpress-invoice' )
		) );
	}

	public function add_membership_fields( $membership_data ){

    	?>
        <p>
            <label for="price">Price</label>
            R <input name="price" type="text" value="<?php echo isset( $membership_data['price'] ) ? $membership_data['price'] : ''; ?>">
        </p>
   		<?php  

	}

	public function save_membership_data( $membership_data ){

	    if(isset($_POST["price"])){
	        $membership_data['price'] = $_POST["price"];
	    }

	    return $membership_data;

	}

	public function add_membership_profile_fields(){

		?>

		<tr>
		<th><label for="credits">Send Prorated Invoice Now?</label></th>

		<td>
			<input type="hidden" name="send_prorated_invoice" id="send_prorated_invoice"  class="regular-text" value="0" />
			<input type="checkbox" name="send_prorated_invoice" id="send_prorated_invoice"  class="regular-check" value="1" /><br />
			<span class="description">Only applies for upgrades and new memberships</span>
		</td>
		</tr>

		<?php

	}

	public function save_membership_profile_data( $member_data ){

		$send_prorated_invoice = ( isset( $_POST['send_prorated_invoice'] ) ) ? $_POST['send_prorated_invoice'] : 0;
		
		$membership_id = ( isset( $_POST['membership_id'] ) ) ? $_POST['membership_id'] : $_GET['membership_id'];
		$old_membership_id = $member_data['old_membership_id'];
		$member_id = $member_data['member_id'];

		if( $old_membership_id && $old_membership_id != $membership_id && $membership_id != '0' && $send_prorated_invoice ):

			FP_Invoice::create_invoice( $member_id, $membership_id, $old_membership_id, true );

		elseif( $membership_id != '0' && $send_prorated_invoice ):

			FP_Invoice::create_invoice( $member_id, $membership_id, null, true );

		elseif( $membership_id != '0' && date( 'j' ) >= 25 ):

			FP_Invoice::create_invoice( $member_id, $membership_id, null );

		endif;

		// update_user_meta( $user_id, 'fitpress_credits', $credits, $old_credits );

	}

	public static function create_invoice( $member_id, $membership_id, $old_membership_id = null, $prorate = false ){

		if( $old_membership_id && $prorate ):

			$memberships = WC_Membership::get_membership( array( $membership_id, $old_membership_id ) );

			$old_price = $memberships[$old_membership_id]['price'];
			$new_price = $memberships[$membership_id]['price'];

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

	public static function woocommerce_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) :?>
			<div id="message" class="error">
				<p><?php printf( __( '%sFitPress is inactive%s. The FitPress plugin must be active for FitPress to work. Please install & activate FitPress.', 'fitpress-invoices' ), '<strong>', '</strong>' ); ?></p>
			</div>
		<?php endif;
	}

}



/**
 * Extension main function
 */
function __fp_invoices_main() {
	new FP_Invoice();
}

// Initialize plugin when plugins are loaded
add_action( 'plugins_loaded', '__fp_invoices_main' );