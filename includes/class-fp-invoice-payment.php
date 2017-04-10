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

		add_action( 'template_redirect', array( $this, 'check_payment' ) );

	}

	public function check_payment() {
		global $wp;

		$member_id = get_current_user_id();

		$membership_status = new FP_Membership_Status( $member_id );
		$status = $membership_status->get_status();

		$membership = FP_Membership::get_user_membership( $member_id );

		if ( 'active' != $membership_status && 'Once Off' != $membership['term'] && ! isset( $wp->query_vars['checkout'] ) ) :
			fp_add_flash_message( __( 'Please, make payment to activate your membership again.', 'fitpress' ), 'error' );
			wp_redirect( fp_checkout_url() );
			exit;
		elseif ( 'active' == $membership_status && ! apply_filters( 'fitpress_payment_token', true ) ) :
			fp_add_flash_message( __( 'Please, <a href="' . fp_checkout_url() . '">add your payment method</a>. You will not be billed now.', 'fitpress' ), 'error' );
		endif;
	}

	protected function get_defaults() {
		$defaults = array(
			//'debit-order' => 'Debit Order',
			//'eft' => 'Electronic Funds Transfer',
		);
		return $defaults;
	}

	public function get_methods() {

		return apply_filters( 'fitpress_payment_methods', $this->get_defaults() );

	}

	public function has_payment( $reference ) {

		$args = array(
			'post_type' => 'fp_payment',
			'meta_key' => 'fp_payment_reference',
			'meta_value' => $reference,
		);

		$payment = new WP_Query( $args );

		if ( 0 === $payment->found_posts ) :
			return false;
		else :
			return true;
		endif;

	}

	public function set_payment( $invoice_id, $status, $amount, $reference ) {

		$payment_details = array(
			'post_title' => 'Payment for invoice id ' . $invoice_id,
			'post_type' => 'fp_payment',
			'post_status' => 'publish',
		);

		$payment_id = wp_insert_post( $payment_details );

		update_post_meta( $payment_id, 'fp_payment_invoice_id', $invoice_id );
		update_post_meta( $payment_id, 'fp_payment_status', $status );
		update_post_meta( $payment_id, 'fp_payment_amount', $amount );
		update_post_meta( $payment_id, 'fp_payment_reference', $reference );

	}

	public function find_invoice( $member_id ) {

		$args = array(
			'post_type' => 'fp_invoice',
			'meta_key' => 'fp_user_id',
			'meta_value' => $member_id,
			'post_status' => 'fp-unpaid',
		);

		$invoice = new WP_Query( $args );

		if ( 0 === $invoice->found_posts ) :
			return false;
		else :
			return $invoice->posts[0]->ID;
		endif;

	}

	public function process_payment( $status, $payment_data ) {

		$invoice_id = $this->find_invoice( $payment_data['member_id'] );

		if ( ! $invoice_id ) :
			die( 'No unpaid invoice.' );
		endif;

		$this->set_payment( $invoice_id, $status, $payment_data['amount'], $payment_data['reference'] );

		switch ( $status ) :
			case 'complete':
				$this->update_invoice( $invoice_id );
				$this->activate_membership( $payment_data['member_id'] );
				$this->update_credits( $payment_data['member_id'] );
				break;
			case 'failed':
			case 'pending':
				$this->suspend_membership( $payment_data['member_id'] );
				break;
			case 'cancelled':
				$this->cancel_membership( $payment_data['member_id'] );
				break;
		endswitch;

	}

	public function update_credits( $member_id ) {

		$old_credits = get_user_meta( $member_id, 'fitpress_credits', true );

		$membership = FP_Membership::get_user_membership( $member_id );

		update_user_meta( $member_id, 'fitpress_credits', $credits['credits'], $old_credits);

	}

	public function update_invoice( $invoice_id ) {

		$invoice = array(
			'ID' => $invoice_id,
			'post_status' => 'fp-paid',
		);

		wp_update_post( $invoice );

	}

	public function cancel_membership( $member_id ) {

		$membership_status = new FP_Membership_Status( $member_id );

		$membership_status->set_status( 'cancelled' );

	}

	public function suspend_membership( $member_id ) {

		$membership_status = new FP_Membership_Status( $member_id );

		$membership_status->set_status( 'suspended' );

	}

	public function activate_membership( $member_id ) {

		$membership_status = new FP_Membership_Status( $member_id );

		$membership_status->set_status( 'active' );

	}


}

new FP_Payment();
