<?php
/**
 * Get other templates passing attributes and including the file.
 *
 * @access public
 * @param string $template_name
 * @param array $args (default: array())
 * @param string $template_path (default: '')
 * @param string $default_path (default: '')
 * @return void
 */

function fp_invoice_maybe_manual_run(){
    if( isset( $_GET['force_send_invoices'] ) ):
    	$invoice_run = new FP_Invoice_Run();
        $invoice_run->maybe_send_invoices( true );
        $url = remove_query_arg( array( 'force_send_invoices' ) );
        wp_redirect( $url );
	elseif ( isset( $_GET['force_send_renewal_reminder'] ) ) :
		include_once( FPI_PLUGIN_DIR . 'includes/notifications/class-fp-notifications-membership-renewal.php' );
		$notification = new FP_Notification();
		$notification->send_daily_notifications();
    endif;
}
add_action( 'template_redirect', 'fp_invoice_maybe_manual_run');
?>
