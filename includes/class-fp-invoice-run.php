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

		add_action( 'fitpress_daily_cron', array( $this, 'maybe_send_invoices' ) );

		add_action( 'fitpress_member_signup', array( $this, 'create_invoice' ), 10, 2 );

		add_filter( 'fitpress_credit_reset_date', array( $this, 'sync_reset_credit' ) );

		add_action( 'fitpress_daily_cron', array( $this, 'maybe_expire_renewals' ), 2 );

	}

	public function maybe_expire_renewals(){

		$args = array(
			'post_type' => 'fp_member',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => '_fp_membership_status',
					'value' => 'active',
					'compare' => '=',
				),
				array(
					'relation' => 'OR',
					array(
						'key' => '_fp_renewal_date',
						'value' => array( strtotime( 'yesterday midnight' ), strtotime( 'today midnight' ) - 1 ),
						'compare' => 'BETWEEN',
					),
					array(
						'key' => '_fp_renewal_date',
						'value' => strtotime( 'yesterday midnight' ),
						'compare' => '=',
					),
				),
			),
			'posts_per_page' => '-1',
		);

		$memberships = new WP_Query( $args );

		if ( $memberships->found_posts ) :
			foreach ( $memberships->posts as $membership ) :
				the_post();
				$membership_id = $membership->ID;
				update_post_meta( $membership_id, '_fp_membership_status', 'suspended' );
				update_post_meta( $membership_id, '_fp_credits', '0' );
			endforeach;
		endif;

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
	public function maybe_send_invoices() {

		$memberships = $this->get_renewals( );

		// Check for results.
		if ( ! empty( $memberships ) ) :

			foreach ( $memberships as $membership ) :

				$membership_id = $membership->ID;

				$package_id = get_post_meta( $membership_id, '_fp_package_id', true );

				$this->create_invoice( $membership_id, $package_id );

			endforeach;

		endif;

	}


	/**
	 * Get a list of active or non-active members. Can also search.
	 */
	public static function get_renewals() {

		$args = array(
			'post_type' => 'fp_member',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => '_fp_membership_status',
					'value' => 'active',
					'compare' => '=',
				),
				array(
					'relation' => 'OR',
					array(
						'key' => '_fp_renewal_date',
						'value' => array( strtotime( 'today midnight' ), strtotime( 'tomorrow midnight' ) - 1 ),
						'compare' => 'BETWEEN',
					),
					array(
						'key' => '_fp_renewal_date',
						'value' => strtotime( 'today midnight' ),
						'compare' => '=',
					),
				),
			),
			'posts_per_page' => '-1',
		);

		$member_query = new WP_Query( $args );

		return $member_query->posts;

	}

	public function create_invoice( $membership_id, $package_id, $old_package_id = null, $prorate = false ) {

		if ( $old_package_id && $prorate ) :

			$memberships = FP_Membership::get_membership( array( $package_id, $old_package_id ) );

			$old_price = $memberships[ $old_package_id ]['price'];
			$new_price = $memberships[ $package_id ]['price'];

			$this->set_dates( $membership_id, $memberships[ $package_id ] );

			if ( $new_price > $old_price ) :

				$memberships[ $package_id ]['price'] = $this->prorate_price( $new_price - $old_price );

			else :

				return;

			endif;

			$line_items[] = $memberships[ $package_id ];

			$invoice_id = $this->create_invoice_post( $line_items, $membership_id, $prorate );

			$this->send_invoice( $invoice_id );

		elseif ( $prorate ) :

			$memberships = FP_Membership::get_membership( array( $package_id ) );

			$memberships[ $package_id ]['price'] = $this->prorate_price( $memberships[ $package_id ]['price'] );

			$this->set_dates( $membership_id, $memberships[ $package_id ] );

			$line_items[] = $memberships[ $package_id ];

			$invoice_id = $this->create_invoice_post( $line_items, $membership_id, $prorate );

			$this->send_invoice( $invoice_id );

		else :

			$memberships = FP_Membership::get_membership( array( $package_id ) );

			$this->set_dates( $membership_id, $memberships[ $package_id ] );

			$line_items[] = $memberships[ $package_id ];

			$invoice_id = $this->create_invoice_post( $line_items, $membership_id, $prorate );

			$this->send_invoice( $invoice_id );

		endif;

	}

	public function send_invoice( $invoice_id, $prorate = false ) {

		$invoice = get_post( $invoice_id );
		$line_items = get_post_meta( $invoice_id, 'fp_invoice_line_items', true );

		$invoice_data = array(
			'number' => get_post_meta( $invoice_id, 'fp_invoice_number', true ),
			'date' => get_post_meta( $invoice_id, 'fp_invoice_date', true ),
			'due_date' => get_post_meta( $invoice_id, 'fp_invoice_due_date',  true ),
		);
		$membership_id = get_post_meta( $invoice_id, 'fp_membership_id', true );
		$member_id = get_post_meta( $membership_id, '_fp_user_id', true );
		$member = get_user_by( 'id', $member_id );

		$FP_Email = new FP_Email( array( 'template' => 'email/invoice.php' ) );

		$FP_Email->send_email( $member->user_email, $invoice->post_title, array( 'header' => 'Tax Invoice', 'line_items' => $line_items, 'invoice' => $invoice_data, 'member' => $member ) );

	}

	public function prorate_price( $price ){

		$days_in_month = date( 't' );
		$days_left = $days_in_month - date( 'j' );

		$prorated_price = $price / $days_in_month * $days_left;

		return round( $prorated_price, -1 );

	}

	public function create_invoice_post( $line_items, $membership_id, $prorate ) {

		$invoice_number = get_option( 'fitpress_invoice_number', 0 );

		$new_invoice_number = intval($invoice_number) + 1;

		update_option( 'fitpress_invoice_number', $new_invoice_number );

		$invoice_number = str_pad( $new_invoice_number, 9, 'INV000000', STR_PAD_LEFT );

		$due_date = date( 'Y/m/d' );

		if ( $this->is_synchronise_date() && $prorate ) :
			$title = date( 'F' ) . '\'s Prorated Invoice #' . $invoice_number;
		elseif ( $this->is_synchronise_date() ) :
			$title = date( 'F', strtotime( '+1 month' ) ) . '\'s Invoice #' . $invoice_number;
			$due_date = date( 'Y/m/d',  strtotime( '1' . date( 'F Y', strtotime( '+1 month' ) ) ) );
		else :
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
		update_post_meta( $new_post_id, 'fp_membership_id', $membership_id );

		return $new_post_id;

	}

	public function set_dates( $membership_id, $package = null ) {

		if ( $package && 'Once Off' == $package['term'] ) :

			update_post_meta( $membership_id, '_fp_renewal_date', 'N/A' );
			update_post_meta( $membership_id, '_fp_expiration_date', strtotime( $package['expiration_date'] ) );

			return;

		endif;

		$month = date( 'n', strtotime( $package['term'] ) );
		$year = date( 'Y', strtotime( $package['term'] ) );

		if ( $this->is_synchronise_date() ) :

			if ( date( 'j' ) < $this->get_synchronise_date() && '+1 month' == $package['term'] ) :

				$day = $this->get_synchronise_date();
				$month = date( 'n' );
				$year = date( 'Y' );

			else :

				$day = date( 'j', strtotime( $package['term'], strtotime( date( $this->get_synchronise_date() . ' F Y' ) ) ) );

				if ( $day < $this->get_synchronise_date() ) :
					$day = $this->get_synchronise_date() - $day;
					$month--;
				endif;

			endif;

		else :

			$membership_date = get_post_meta( $membership_id, '_fp_membership_start_date', true );

			if ( ! $membership_date ) :
				$membership_date = strtotime( 'today midnight' );
				update_post_meta( $membership_id, '_fp_membership_start_date', $membership_date );
			endif;

			$day = date( 'j', strtotime( $package['term'], $membership_date ) );

			if ( $day < date( 'j', $membership_date ) ) :
				$day = date( 'j', $membership_date ) - $day;
				$month--;
			endif;

		endif;

		$renewal_date = strtotime( $year . '-' . $month . '-' . $day );

		update_post_meta( $membership_id, '_fp_renewal_date', $renewal_date );
		update_post_meta( $membership_id, '_fp_expiration_date', 'N/A' );

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
