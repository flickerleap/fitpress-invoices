<?php

if ( ! defined( 'ABSPATH' ) ) :
	exit; // Exit if accessed directly
endif;

class FP_Invoices_Admin {

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

		add_action( 'fitpress_after_membership_fields', array( $this, 'add_membership_fields' ), 10, 2 );
		add_action( 'fitpress_after_membership_save', array( $this, 'save_membership_data' ) );

		add_action( 'fitpress_after_membership_actions', array( $this, 'add_action_fields' ) );

		//Setting up post columns.
		add_filter( 'manage_fp_invoice_posts_columns', array( $this, 'column_header' ), 10, 1);
		add_action( 'manage_posts_custom_column', array( $this, 'column_data' ), 15, 3);
		add_filter( 'manage_edit-fp_invoice_sortable_columns', array( $this, 'column_sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_member') );

		//Setting up user columns.
		add_filter( 'manage_users_columns', array( $this, 'user_column_header' ), 10, 1 );
		add_action( 'manage_users_custom_column', array( $this, 'user_column_data' ), 15, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'user_column_sortable' ) );
		add_action( 'pre_get_users', array( $this, 'sort_by_user_data' ) );

		add_action( 'admin_init', array( $this, 'init_settings' ), 0, 40 );

	}

	public function add_membership_fields( $package_id, $member_id ){

		?>

		<?php if ( $package_id ) :?>
			<p>
				<label for="renewal_date">Renewal Date</label>
				<?php $renewal_date = get_post_meta( $membership_id, '_fp_renewal_date', true );?>
				<?php if ( ! $renewal_date || empty( $renewal_date ) || 'N/A' == $renewal_date ) :?>
					<input type="text" name="renewal_date" id="renewal_date" value="N/A" />
				<?php else :?>
					<input type="text" name="renewal_date" id="renewal_date" value="<?php echo date( 'j F Y', $renewal_date );?>" />
				<?php endif;?>
			</p>
		<?php endif;?>

		<?php

	}

	public function save_membership_data( $membership_data ){

		$send_prorated_invoice = ( isset( $_POST['send_prorated_invoice'] ) ) ? $_POST['send_prorated_invoice'] : 0;
		$do_invoice = ( isset( $_POST['do_invoice'] ) ) ? $_POST['do_invoice'] : 0;

		$membership_status = ( isset( $_POST['membership_status'] ) ) ? $_POST['membership_status'] : 'on-hold';
		$old_package_id = ( $membership_data['old_package_id'] ) ? $membership_data['old_package_id'] : 0;
		$package_id = ( $membership_data['package_id'] ) ? $membership_data['package_id'] : 0;
		$membership_id = $membership_data['membership_id'];
		$renewal_date = isset( $_POST['renewal_date'] ) ? $_POST['renewal_date'] : '';
		$membership_start_date = get_post_meta( $membership_id, '_fp_membership_start_date', true );
		$membership_start_date = ( $membership_start_date ) ? $membership_start_date : strtotime( 'midnight today' );

		if ( $renewal_date && $old_package_id == $package_id ) :
			if ( empty( $renewal_date ) || $renewal_date == 'N/A' ) :
				$renewal_date = 'N/A';
			else :
				$renewal_date = strtotime( $renewal_date );
			endif;
		elseif (  $old_package_id != $membership_id ) :
			$package_data = FP_Membership::get_membership( $package_id );
			if ( 'Once Off' == $package_data[ $package_id ]['term'] ) :
				$renewal_date = 'N/A';
			else :
				$renewal_date = strtotime( $package_data[ $package_id ]['term'], $membership_start_date );
			endif;
		endif;

		update_post_meta( $membership_id, '_fp_renewal_date', $renewal_date );

		$invoice = new FP_Invoice_Run();

		if ( $do_invoice ) :

			$invoice->create_invoice( $membership_id, $package_id, $old_package_id, $send_prorated_invoice );
			return;

		endif;

		$package = FP_Membership::get_membership( $package_id );
		$invoice->set_dates( $membership_id, $package[ $package_id ] );

	}

	public function add_action_fields( ){

		?>
		
		<p>
			<label for="do_invoice">Send Invoice?</label>
			<input type="checkbox" name="do_invoice" value="1" />
		</p>

		<?php

	}

	public function set_membership_date( $member_id ) {

		update_post_meta( $membership_id, '_fp_membership_start_date', date( 'j F Y' ) );

	}

	/*
	* Setup Column and data for users page with sortable
	*/
	public static function column_header( $column ){

		$column['member'] = __( 'Member', 'fitpress-invoices' );
		$column['status'] = __( 'Status', 'fitpress-invoices' );

		return $column;

	}

	public static function column_data( $column_name, $invoice_id ){

		if ( 'member' == $column_name ):

			$membership_id = get_post_meta( $invoice_id, 'fp_membership_id', true );
			$user_id = get_post_meta( $membership_id, '_fp_user_id', true );
			$user = get_user_by( 'id', $user_id );

			if(!$user):

				echo __( 'Could not find member information.', 'fitpress-invoices' );

			else:

				echo '<a href="' . get_admin_url( null, 'post.php?post=' . $membership_id . '&action=edit' ) . '">' . $user->display_name . '</a>';

			endif;

		endif;

		if ( 'status' == $column_name ):

			if( 'fp-unpaid' == get_post_status( $invoice_id ) ):

				echo 'Unpaid';

			else:

				echo 'Paid';

			endif;

		endif;

	}

	public static function column_sortable( $columns ){

		$columns['member'] = 'member';
		$columns['status'] = 'status';

		return $columns;

	}

	public static function sort_by_member( $query ) {

		if ( 'member' == $query->get( 'orderby' ) ) {

			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'meta_key', 'fp_user_id');

		}

		 if ( 'status' == $query->get( 'orderby' ) ) {

			$query->set( 'orderby', 'status' );

		}

	}

	public function user_column_header( $column ){
		$column['renewal_date'] = __( 'Renewal date', 'fitpress-invoices' );

		return $column;
	}

	public function user_column_data( $value, $column_name, $user_id ) {

		if ( 'renewal_date' == $column_name ) :
			
			$membership = FP_Membership::get_user_membership( $user_id );

			$renewal_date = get_post_meta( $membership['membership_id'], '_fp_renewal_date', true );

			if ( $renewal_date && 'Once Off' != $renewal_date && 'N/A' != $renewal_date ) :

				return date( 'j F Y', $renewal_date );

			else :

				return '';

			endif;

		endif;

		return $value;

	}

	public function sort_by_user_data( $query ) {

		if ( 'renewal_date' == $query->get( 'orderby' ) ) {

			$query->set( 'orderby', 'meta_value' );
			$query->set( 'meta_key', 'fitpress_next_invoice_date' );

		}

	}

	public function user_column_sortable( $columns ) {

		return $columns;

	}

	public function init_settings() {

		$this->settings = get_option( 'fitpress_settings' );

		add_settings_section(
			'invoice_settings',
			'Invoice Settings',
			array( $this, 'invoice_settings_callback_function' ),
			'fp_settings'
		);

		add_settings_field(
			'synchronise_renewal',
			'Synchronise Renewal Date',
			array( $this, 'synchronise_renewal_callback_function' ),
			'fp_settings',
			'invoice_settings'
		);
		add_settings_field(
			'billing_date',
			'Billing Date',
			array( $this, 'billing_date_callback_function' ),
			'fp_settings',
			'invoice_settings'
		);

		register_setting( 'fp_settings', 'fitpress_settings' );

	}

	public function invoice_settings_callback_function() {
	}

	public function synchronise_renewal_callback_function() {
		$value = (! empty( $this->settings['synchronise_renewal'] ) ) ? $this->settings['synchronise_renewal'] : '';
		echo '<input name="fitpress_settings[synchronise_renewal]" id="synchronise_renewal" class="small" type="checkbox" value="1" ' . checked( 1, $value, false ) . ' /> (aligns all renewals to happen on the same day)';
	}

	public function billing_date_callback_function() {
		$value = (! empty( $this->settings['billing_date'] ) ) ? $this->settings['billing_date'] : '25';
		if( ! empty( $this->settings['synchronise_renewal'] ) && $this->settings['synchronise_renewal'] ):
			echo '<input name="fitpress_settings[billing_date]" id="billing_date" class="small" type="number"  value="' . $value . '" />';
		else:
			echo '<input name="fitpress_settings[billing_date]" id="billing_date" class="small" type="number"  value="' . $value . '" style="display:none;" />';
		endif;
		?>
		<script>
		jQuery(document).ready(function($){
			$('#billing_date:not(:visible)').parents('tr').hide();
			$("#synchronise_renewal").on("change", function(){
				$("#billing_date").toggle();
				$('#billing_date').parents('tr').toggle();
			});
		});
		</script>
		<?php
	}

}

/**
 * Extension main function
 */
function __fp_invoices_admin_main() {
	FP_Invoices_Admin::get_instance();
}

// Initialize plugin when plugins are loaded
add_action( 'fitpress_loaded', '__fp_invoices_admin_main' );
