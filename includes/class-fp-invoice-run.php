<?php

if ( ! defined( 'ABSPATH' ) ) :
	exit; // Exit if accessed directly
endif;

class FP_Invoice_Run {

	/* We only want a single instance of this class. */
	private static $instance = null;

	protected $is_synchronised = null;

	protected $synchronise_date = null;

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

		if( !wp_get_schedule('send_monthly_invoices') ):
			$start = strtotime( 'tomorrow' );
			wp_schedule_event( $start, 'daily', 'send_monthly_invoices' );
		endif;

		add_action( 'send_monthly_invoices', array( $this, 'maybe_send_monthly_invoices' ) );

		add_action( 'fitpress_member_signup', array( $this, 'create_invoice' ), 10, 2 );

		add_filter( 'fitpress_credit_reset_date', array( $this, 'sync_reset_credit' ) );

	}

	/**
	 * Returns false if the credit sync should be on invoice.
	 *
	 * @param  Int $date The date the sync will run on.
	 * @return Mixed     Returns the day if synchronised or false if not.
	 */
	public function sync_reset_credit( $date ) {

		if ( ! $this->is_synchronise_date() ) :
			return false;
		endif;

		return $date;


	}

	/**
	 * Returns if the system must sync invoices or not.
	 *
	 * @return Bool Returns true or fale.
	 */
	public function is_synchronise_date() {
		if ( empty( $this->is_synchronised ) ) :
			$fp_settings = get_option( 'fitpress_settings' );
			$this->is_synchronised = isset( $fp_settings['synchronise_renewal'] ) && boolval( $fp_settings['synchronise_renewal'] );
		endif;
		return $this->is_synchronised;
	}

	public function get_synchronise_date(){
		if ( empty( $this->synchronise_date ) ) :
			$fp_settings = get_option( 'fitpress_settings' );
			$this->synchronise_date = $fp_settings['billing_date'];
		endif;
		return $this->synchronise_date;
	}

	/**
	* Adds credits to user if subscription is successfully activated
	*
	* @param int $user_id A user ID for the user that the subscription was activated for.
	* @param mixed $subscription_key The key referring to the activated subscription
	* @version 1.0
	* @since 0.1
	*/
	public function maybe_send_monthly_invoices( ) {

		$members = $this->get_renewals( );

		$memberships = FP_Membership::get_memberships( );

		// Check for results.
		if ( ! empty( $members ) ) :

			$next_day = strtotime( 'tomorrow', current_time( 'timestamp' ) );

			foreach ( $members as $member_id ) :

				$membership_id = get_user_meta( $member_id, 'fitpress_membership_id', true );

				$this->create_invoice( $member_id, $membership_id );

			endforeach;

		endif;

	}


	/**
	 * Get a list of active or non-active members. Can also search.
	 *
	 * @param Mixed $fields Fields to return. Defaults to ID.
	 * @param Bool  $none_members Set to true to return none members.
	 * @param Mixed $search Set to string to search for a specific member.
	 */
	public static function get_renewals( ) {

		$args = array(
			'meta_query' => array(
				array(
					'key' => 'fitpress_membership_status',
					'value' => 'active',
					'compare' => '=',
				),
				array(
					'key' => 'fitpress_next_invoice_date',
					'value' => strtotime( date( 'j F Y' ) ),
					'compare' => '=',
				),
			),
			'fields' => $fields,
		);

		$member_query = new WP_User_Query( $args );

		return $member_query->get_results();

	}

	public function create_invoice( $member_id, $membership_id, $old_membership_id = null, $prorate = false ){

		if( intval( $old_membership_id ) != 0 && $prorate ):

			$memberships = FP_Membership::get_membership( array( $membership_id, $old_membership_id ) );

			$old_price = $memberships[$old_membership_id]['price'];
			$new_price = $memberships[$membership_id]['price'];

			$this->set_next_invoice_date( $member_id, $memberships[$membership_id]['term'] );

			if( $new_price > $old_price ):

				$memberships[$membership_id]['price'] = $this->prorate_price( $new_price - $old_price );

			else:

				return;

			endif;

			$line_items[] = $memberships[ $membership_id ];

			$invoice_id = $this->create_invoice_post( $line_items, $member_id, $prorate );

			$this->send_invoice( $invoice_id );

		elseif ( $prorate ) :

			$memberships = FP_Membership::get_membership( array( $membership_id ) );

			$memberships[$membership_id]['price'] = $this->prorate_price( $memberships[$membership_id]['price'] );

			$this->set_next_invoice_date( $member_id, $memberships[$membership_id]['term'] );

			$line_items[] = $memberships[$membership_id];

			$invoice_id = $this->create_invoice_post( $line_items, $member_id, $prorate );

			$this->send_invoice( $invoice_id );

		else :

			$memberships = FP_Membership::get_membership( array( $membership_id ) );

			$this->set_next_invoice_date( $member_id, $memberships[$membership_id]['term'] );

			$line_items[] = $memberships[$membership_id];

			$invoice_id = $this->create_invoice_post( $line_items, $member_id, $prorate );

			$this->send_invoice( $invoice_id );

		endif;

	}

	public function send_invoice( $invoice_id, $prorate = false ){

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

	public function prorate_price( $price ){

		$days_in_month = date( 't' );
		$days_left = $days_in_month - date( 'j' );

		$prorated_price = $price / $days_in_month * $days_left;

		return round( $prorated_price, -1 );

	}

	public function create_invoice_post( $line_items, $member_id, $prorate ){

		$invoice_number = get_option( 'fitpress_invoice_number', 0 );

		$new_invoice_number = intval($invoice_number) + 1;

		update_option( 'fitpress_invoice_number', $new_invoice_number );

		$invoice_number = str_pad( $new_invoice_number, 9, 'INV000000', STR_PAD_LEFT);

		$due_date = date( 'Y/m/d' );

		if ( $this->is_synchronise_date() && $prorate ) :
			$title = date( 'F' ) . '\'s Prorated Invoice #' . $invoice_number;
		elseif ( $this->is_synchronise_date() ):
			$title = date( 'F', strtotime( '+1 month') ) . '\'s Invoice #' . $invoice_number;
			$due_date = date('Y/m/d',  strtotime( '1' . date( 'F Y', strtotime('+1 month') ) ) );
		else:
			$title = 'Invoice #' . $invoice_number;
		endif;

		$post = array(
			'post_title' => $title,
			'post_type' => 'fp_invoice',
			'post_status' => 'fp-unpaid'
		);

		$new_post_id = wp_insert_post( $post );

		update_post_meta( $new_post_id, 'fp_invoice_line_items', $line_items );
		update_post_meta( $new_post_id, 'fp_invoice_number', $invoice_number );
		update_post_meta( $new_post_id, 'fp_invoice_date', date( 'Y/m/d' ) );
		update_post_meta( $new_post_id, 'fp_invoice_due_date', $due_date );
		update_post_meta( $new_post_id, 'fp_user_id', $member_id );

		return $new_post_id;

	}

	public function set_next_invoice_date( $member_id, $term = null ) {

		if ( $term == 'Once Off' ) :

			update_user_meta( $member_id, 'fitpress_next_invoice_date', $term );

			return;

		endif;		

		$month = date( 'n', strtotime( $term ) );
		$year = date( 'Y', strtotime( $term ) );

		if ( $this->is_synchronise_date() ) :

			if( date( 'j' ) < $this->get_synchronise_date() && $term == '+1 month' ) :

				$day = $this->get_synchronise_date();
				$month = date( 'n' );
				$year = date( 'Y' );

			else :

				$day = date( 'j', strtotime( $term, strtotime( date( $this->get_synchronise_date() . ' F Y' ) ) ) );

				if ( $day < $this->get_synchronise_date() ) :
					$day = $this->get_synchronise_date() - $day;
					$month--;
				endif;

			endif;

		else :

			$membership_date = get_user_meta( $member_id, 'fitpress_membership_date', true );

			if ( ! $membership_date ) :
				$membership_date = date( 'j F Y' );
				update_user_meta( $member_id, 'fitpress_membership_date', strtotime( $membership_date ) );
			else :
				date( 'j F Y', $membership_date );
			endif;

			$day = date( 'j', strtotime( $term, strtotime( $membership_date ) ) );

			if ( $day < date( 'j', strtotime( $membership_date ) ) ) :
				$day = date( 'j', strtotime( $membership_date ) ) - $day;
				$month--;
			endif;

		endif;

		$next_invoice_date = strtotime( $day . '-' . $month . '-' . $year );

		update_user_meta( $member_id, 'fitpress_next_invoice_date', $next_invoice_date );

	}

}

/**
 * Extension main function
 */
function __fp_invoice_run() {
	FP_Invoice_Run::get_instance();
}

// Initialize plugin when plugins are loaded
add_action( 'fitpress_loaded', '__fp_invoice_run' );
