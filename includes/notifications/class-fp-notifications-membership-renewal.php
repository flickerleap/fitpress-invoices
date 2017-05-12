<?php
/**
 * Renewal notifications
 *
 * Renewal notifications .
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
 * FP_Membership_Renewal_Notification Class.
 */
class FP_Membership_Renewal_Notification {

	/**
	 * Hook in methods.
	 */
	public function __construct() {

		add_filter( 'fitpress_daily_notifications', array( $this, 'membership_renewal_reminder' ) );

	}

	public function membership_renewal_reminder( $notifications ) {

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
						'value' => array( strtotime( '+3 days midnight' ), strtotime( '+4 days midnight' ) - 1 ),
						'compare' => 'BETWEEN',
					),
					array(
						'key' => '_fp_renewal_date',
						'value' => strtotime( '+3 days midnight' ),
						'compare' => '=',
					),
				),
			),
			'posts_per_page' => '-1',
		);

		$memberships = new WP_Query( $args );

		if ( $memberships->found_posts ) :
			foreach ( $memberships->posts as $membership ) :

				$membership_id = $membership->ID;

				$user_id = get_post_meta( $membership_id, '_fp_user_id', true );
				$user = get_user_by( 'ID', $user_id );

				$renewal_date = get_post_meta( $membership_id, '_fp_renewal_date', true );
				$package_id = get_post_meta( $membership_id, '_fp_package_id', true );
				$package_name = get_the_title( $package_id );

				$message = '';

				$message .= '<p>Hi ' . $user->first_name . ',</p>';
				$message .= '<p>This is just a reminder that your ' . $package_name . ' membership will renew on ' . date( 'j F Y', $renewal_date ) . '. Please make any changes to your account before that date.</p>';

				$notifications[] = array(
					'template' => 'email/notification.php',
					'email' => $user->user_email,
					'subject' => 'Membership Renewal Reminder',
					'header' => 'Membership Renewal Reminder',
					'message' => $message,
				);

			endforeach;
		endif;

		return $notifications;

	}
}

new FP_Membership_Renewal_Notification();
