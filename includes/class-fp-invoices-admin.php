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

		add_action( 'fitpress_after_membership_profile_fields', array( $this, 'add_membership_profile_fields' ), 10, 2 );
		add_action( 'fitpress_before_membership_profile_save', array( $this, 'save_membership_profile_data' ) );


		//Setting up columns.
		add_filter( 'manage_fp_invoice_posts_columns', array( $this, 'column_header' ), 10, 1);
		add_action( 'manage_posts_custom_column', array( $this, 'column_data' ), 15, 3);
		add_filter( 'manage_edit-fp_invoice_sortable_columns', array( $this, 'column_sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_member') );

		add_action( 'admin_init', array( $this, 'init_settings' ), 0, 40 );

	}

	public function add_membership_profile_fields( $member_id, $membership_id ){

		?>

		<tr>
		<th><label for="credits">Send Prorated Invoice Now?</label></th>
		<td>
			<input type="hidden" name="send_prorated_invoice" id="send_prorated_invoice"  class="regular-text" value="0" />
			<input type="checkbox" name="send_prorated_invoice" id="send_prorated_invoice"  class="regular-check" value="1" /><br />
			<span class="description">Only applies for upgrades and new memberships</span>
		</td>
		</tr>

		<tr>
		<th><label for="credits">Do not invoice?</label></th>
		<td>
			<input type="hidden" name="do_not_invoice" id="do_not_invoice"  class="regular-text" value="0" />
			<input type="checkbox" name="do_not_invoice" id="do_not_invoice"  class="regular-check" value="1" /><br />
			<span class="description">Will not send invoice</span>
		</td>
		</tr>

		<?php if( $membership_id && $membership_id =! '0' ):?>
		<tr>
		<th>Next Invoice Date</th>
		<td>
			<?php $next_invoice_date = get_user_meta( $member_id, 'fitpress_next_invoice_date', true );?>
			<?php
			if( $next_invoice_date && $next_invoice_date == 'Once Off' )
				echo 'Once Off';
			elseif($next_invoice_date)
				echo date( 'j F Y', $next_invoice_date );
			else
				echo date( 'F Y', strtotime( '+1 month' ) );
			?>
		</td>
		</tr>
		<?php endif;?>

		<?php

	}

	public function save_membership_profile_data( $member_data ){

		$send_prorated_invoice = ( isset( $_POST['send_prorated_invoice'] ) ) ? $_POST['send_prorated_invoice'] : 0;
		$do_not_invoice = ( isset( $_POST['do_not_invoice'] ) ) ? $_POST['do_not_invoice'] : 0;

		$membership_id = ( isset( $_POST['membership_id'] ) ) ? $_POST['membership_id'] : $_GET['membership_id'];
		$old_membership_id = $member_data['old_membership_id'];
		$member_id = $member_data['member_id'];

		if( $old_membership_id && $old_membership_id == $membership_id || $do_not_invoice )
			return;

		if( $old_membership_id && $old_membership_id != $membership_id && $membership_id != '0' && $send_prorated_invoice && date( 'j' ) < 25 ):

			FP_Invoice::create_invoice( $member_id, $membership_id, $old_membership_id, true );

		elseif( $membership_id != '0' && $send_prorated_invoice && date( 'j' ) < 25 ):

			FP_Invoice::create_invoice( $member_id, $membership_id, null, true );

		elseif( $membership_id != '0' && date( 'j' ) >= 25 ):

			FP_Invoice::create_invoice( $member_id, $membership_id );

		endif;

		// update_user_meta( $user_id, 'fitpress_credits', $credits, $old_credits );

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

			$member_id = get_post_meta( $invoice_id, 'fp_user_id', true );
			$member = get_user_by( 'id', $member_id );

			if(!$member):

				echo __( 'Could not find member information.', 'fitpress-invoices' );

			else:

				echo '<a href="<?php echo get_edit_user_link( $member->ID ); ?>">' . $member->display_name . '</a>';

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
			'Synchronise Renewal Data',
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
