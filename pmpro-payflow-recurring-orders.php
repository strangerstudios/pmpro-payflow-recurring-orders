<?php
/*
 * Plugin Name: Paid Memberships Pro - Payflow Recurring Orders Add On
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/payflow-recurring-orders-addon/
 * Description: Check daily for new recurring orders in Payflow and add as PMPro orders.
 * Version: 0.3.1
 * Author: Paid Memberships Pro
 * Author URI: htts://www.paidmembershipspro.com
 * Text Domain: paid-memberships-pro
 * Domain Path: /languages
*/

/**
 * Load the languages folder for translations.
 */
function pmpropfpro_load_plugin_text_domain() {
	load_plugin_textdomain( 'pmpro-payflow-recurring-orders', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pmpropfpro_load_plugin_text_domain' );

/*
	The Plan
	* Find users to check:
		- Active members
		- Subscription Transaction ID
		- Date of last order is older than their pay period
	* For each user:
		- Check their Subscription in Payflow.
		- Look for new orders.
*/

/*
	Schedule Cron on Activation; Deschedule on Deactivation
*/
// activation
function pmpropfpro_activation() {
	wp_schedule_event( current_time( 'timestamp' ), 'hourly', 'pmpro_payflow_recurring_orders' );
}
register_activation_hook( __FILE__, 'pmpropfpro_activation' );

// clear our crons on plugin deactivation
function pmpropfpro_deactivation() {
	wp_clear_scheduled_hook( 'pmpro_payflow_recurring_orders' );
}
register_deactivation_hook( __FILE__, 'pmpropfpro_deactivation' );

// for testing
function pmpropfro_init_test() {
	if ( ! empty( $_REQUEST['payflowtest'] ) && current_user_can( 'manage_options' ) ) {
		pmpro_payflow_recurring_orders();
		exit;
	}
}
// add_action('init', 'pmpropfro_init_test');

/*
	Cron function.
*/
add_action( 'pmpro_payflow_recurring_orders', 'pmpro_payflow_recurring_orders' );
function pmpro_payflow_recurring_orders() {
	// is PMPro even active?
	if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
		return;
	}

	global $wpdb;
	$now = current_time( 'timestamp' );

	// Use this filter to avoid running the cron at certain hours.
	$skip_cron = apply_filters( 'pmpro_payflow_recurring_orders_skip_cron', false );
	if ( $skip_cron ) {
		return;
	}

	// where did we leave off?
	if ( isset( $_REQUEST['start'] ) ) {
		$start = intval( $_REQUEST['start'] );
		delete_option( 'pmpro_pfro_paused' );
	} else {
		$start = get_option( 'payflow_recurring_orders_cron_count', 0 );
	}

	// are we paused? value is timestamp. if set wait until then
	$paused = get_option( 'pmpro_pfro_paused', false );
	if ( ! empty( $paused ) && $paused > $now ) {
		echo 'Paused. Add &force=1 to URL to force start.<br />';
		return;
	}

	// how many subscriptions to run at one time. based on your server speed and timeout limits/etc.
	if ( defined( 'PMPRO_CRON_LIMIT' ) ) {
		$nper = PMPRO_CRON_LIMIT;
	} else {
		$nper = 50;
	}

	// next one
	$end = ( intval( $start ) + intval( $nper ) );

	// get subs
	$sqlQuery = "
		SELECT SQL_CALC_FOUND_ROWS user_id FROM
		(
			SELECT mu.user_id,
				CASE mu.cycle_period
					WHEN 'Day' THEN date_add(mo.timestamp, INTERVAL mu.cycle_number DAY)
					WHEN 'Month' THEN date_add(mo.timestamp, INTERVAL mu.cycle_number MONTH)
					WHEN 'Week' THEN date_add(mo.timestamp, INTERVAL mu.cycle_number WEEK)
					WHEN 'Year' THEN date_add(mo.timestamp, INTERVAL mu.cycle_number YEAR)
				END as next_payment_date
			FROM $wpdb->pmpro_memberships_users mu
				LEFT JOIN $wpdb->pmpro_membership_orders mo
					ON mo.id = (
						SELECT id FROM $wpdb->pmpro_membership_orders WHERE gateway = 'payflowpro' AND status NOT IN('review', 'token', 'pending') AND user_id = mu.user_id ORDER BY id DESC LIMIT 1
					)
			WHERE mu.status = 'active'
				AND mo.subscription_transaction_id <> ''
		) members
		WHERE DATEDIFF('" . current_time( 'mysql' ) . "', next_payment_date) >= 0
		LIMIT $start, $nper
	";

	$sub_user_ids = $wpdb->get_col( $sqlQuery );

	$count = $wpdb->get_var( 'SELECT FOUND_ROWS()' );

	echo 'Processing ' . intval( $start ) . ' to ' . ( intval( $start ) + intval( $nper ) ) . ' of ' . $count . ' subscriptions.<hr />';

	// if no more subs, pause until tomorrow
	if ( empty( $sub_user_ids ) ) {
		echo 'All done. Pausing until tomorrow.<br />';

		$tomorrow = strtotime( date( 'Y-m-d 00:00:00', $now + 3600 * 24 ) );
		update_option( 'pmpro_pfro_paused', $tomorrow );
		update_option( 'payflow_recurring_orders_cron_count', 0 );

		return;
	}

	$failed_payment_emails = array();

	// loop through subs
	foreach ( $sub_user_ids as $user_id ) {
		$user = get_userdata( $user_id );

		if ( empty( $user->ID ) ) {
			echo "Coundn't find user #" . $user_id . '...<br /><hr />';
			continue;
		}

		echo 'Checking for recurring orders for user #' . $user->ID . ' ' . $user->user_login . ' (' . $user->user_email . ')...<br />';

		$last_order = new MemberOrder();
		$last_order->getLastMemberOrder( $user_id );

		if ( ! empty( $last_order->id ) ) {
			echo '- Last order found. #' . $last_order->id . ', Code: ' . $last_order->code . ', SubID: ' . $last_order->subscription_transaction_id . '.<br />';
		} else {
			echo '- No last order. Skipping.';
			echo '<hr />';
			continue;
		}

		// is it even Payflow?
		if ( $last_order->gateway != 'payflowpro' ) {
			echo "- Order is for '" . $last_order->gateway . "' gateway.<br />";
			echo '<hr />';
			continue;
		}

		// check subscription
		if ( ! empty( $last_order->subscription_transaction_id ) ) {
			echo '- Checking subscription #' . $last_order->subscription_transaction_id . '.<br />';

			$status = pmpropfro_getSubscriptionPayments( $last_order );

			// find orders
			$payments = pmpropfro_processPaymentHistory( $status );

			if ( ! empty( $payments ) ) {
				foreach ( $payments as $payment ) {
					if ( $payment['P_TRANSTATE'] == 1 || $payment['P_TRANSTATE'] == 11 ) {
						echo '- Failed payment #' . $payment['P_PNREF'] . '.';

						// check if we have this one already
						$old_order = new MemberOrder();
						$old_order->getMemberOrderByPaymentTransactionID( $payment['P_PNREF'] );

						if ( empty( $old_order->id ) ) {
							$failed_payment_emails[] = $user->user_email;

							// not there yet, add it
							$morder                              = new MemberOrder();
							$morder->user_id                     = $last_order->user_id;
							$morder->membership_id               = $last_order->membership_id;
							$morder->payment_transaction_id      = $payment['P_PNREF'];
							$morder->subscription_transaction_id = $last_order->subscription_transaction_id;

							$morder->InitialPayment = $payment['P_AMT']; // not the initial payment, but the class is expecting that
							$morder->PaymentAmount  = $payment['P_AMT'];

							$morder->status = 'error';

							// save
							$morder->saveOrder();
							$morder->getMemberOrderByID( $morder->id );

							echo ' Saving order.';

							// this will affect the main query, so need to roll back the "end" 1 space
							$end--;

							// unless there is another non-failed payment more recent, cancel their membership
							if ( ! pmpropfro_paymentAfter( $payments, strtotime( $payment['P_TRANSTIME'] ) ) ) {
								// cancel membership
								pmpro_changeMembershipLevel( 0, $user_id );

								echo ' Membership cancelled. Member emailed.';

								// notify them
								$myemail = new PMProEmail();
								$myemail->sendCancelEmail( $user );
							} else {
								echo ' More recent successful order. So not cancelling membership.';
							}
						} else {
							echo ' Already logged.';
						}

						echo '<br />';
					} elseif ( $payment['P_TRANSTATE'] == 8 ) {
						// check if we have this one already
						$old_order = new MemberOrder();
						$old_order->getMemberOrderByPaymentTransactionID( $payment['P_PNREF'] );

						if ( empty( $old_order->id ) ) {
							// not there yet, add it
							$morder                              = new MemberOrder();
							$morder->user_id                     = $last_order->user_id;
							$morder->membership_id               = $last_order->membership_id;
							$morder->payment_transaction_id      = $payment['P_PNREF'];
							$morder->subscription_transaction_id = $last_order->subscription_transaction_id;

							$morder->InitialPayment = $payment['P_AMT']; // not the initial payment, but the class is expecting that
							$morder->PaymentAmount  = $payment['P_AMT'];

							$morder->status = 'success';

							// save
							$morder->saveOrder();
							$morder->getMemberOrderByID( $morder->id );

							// this will affect the main query, so need to roll back the "end" 1 space
							$end--;

							if ( ! empty( $morder->id ) ) {
								// update the timestamp
								$timestamp = date( 'Y-m-d H:i:s', strtotime( $payment['P_TRANSTIME'] ) );
								$wpdb->query( "UPDATE $wpdb->pmpro_membership_orders SET timestamp = '" . $timestamp . "' WHERE id = '" . $morder->id . "' LIMIT 1" );
								echo '<strong>- Order added. #' . $morder->id . '.</strong><br />';

								// email the user their invoice
								$pmproemail = new PMProEmail();
								$pmproemail->sendInvoiceEmail( $user, $morder );

								echo '- Invoice email sent to ' . $user->user_email . '.';
							} else {
								echo '- Error adding order.';
							}
						} else {
							echo '- Order already saved for #' . $payment['P_PNREF'] . '.<br />';
						}
					} else {
						echo '<strong>- Payment ' . $payment['P_PNREF'] . ' has status #' . $payment['P_TRANSTATE'] . ' so not saving.</strong><br />';
					}
				}
			} else {
				echo '- No payments found.<br />';
			}
		}
		echo '<hr />';
	}

	echo 'Going to start with #' . $end . ' next time.';
	update_option( 'payflow_recurring_orders_cron_count', $end );

	echo '<hr />';
	foreach ( $failed_payment_emails as $email ) {
		echo $email . '<br />';
	}
}

/*
	Get Payflow Subscription Payments
*/
function pmpropfro_getSubscriptionPayments( $order ) {
	if ( empty( $order->subscription_transaction_id ) ) {
		return false;
	}

	// paypal profile stuff
	$nvpStr  = '';
	$nvpStr .= '&ORIGPROFILEID=' . urlencode( $order->subscription_transaction_id ) . '&ACTION=I&PAYMENTHISTORY=Y';

	$httpParsedResponseAr = $order->Gateway->PPHttpPost( 'R', $nvpStr );

	if ( '0' == strtoupper( $httpParsedResponseAr['RESULT'] ) ) {
		return $httpParsedResponseAr;
	} else {
		$order->status     = 'error';
		$order->errorcode  = $order->Gateway->httpParsedResponseAr['L_ERRORCODE0'];
		$order->error      = urldecode( $order->Gateway->httpParsedResponseAr['L_LONGMESSAGE0'] );
		$order->shorterror = urldecode( $order->Gateway->httpParsedResponseAr['L_SHORTMESSAGE0'] );

		return false;
	}
}

/*
	Get Payments from Payflow Response
*/
function pmpropfro_processPaymentHistory( $results ) {
	$payments = array();

	// is there at least one?
	if ( ! empty( $results['P_PNREF1'] ) ) {
		$maxpossiblepayments = count( $results ) / 3;
		for ( $i = 1; $i <= $maxpossiblepayments; $i++ ) {
			// if no pnref, we're finished
			if ( empty( $results[ 'P_PNREF' . $i ] ) ) {
				break;
			}

			// if no transtate, this is not a "real" payment, skip it
			if ( empty( $results[ 'P_TRANSTATE' . $i ] ) ) {
				continue;
			}

			$payments[] = array(
				'P_PNREF'     => $results[ 'P_PNREF' . $i ],
				'P_TRANSTIME' => $results[ 'P_TRANSTIME' . $i ],
				'P_RESULT'    => $results[ 'P_RESULT' . $i ],
				'P_TENDER'    => $results[ 'P_TENDER' . $i ],
				'P_AMT'       => $results[ 'P_AMT' . $i ],
				'P_TRANSTATE' => $results[ 'P_TRANSTATE' . $i ],
			);
		}
	}

	return $payments;
}

/*
	Check if there is a successful payment after a certain datetime.

	- $payments = array of payment objects from Payflow.
	- $time = UNIX TIMESTAMP
*/
function pmpropfro_paymentAfter( $payments, $time ) {
	if ( ! empty( $payments ) ) {
		foreach ( $payments as $payment ) {
			if ( $payment['P_TRANSTATE'] == 8 && strtotime( $payment['P_TRANSTIME'] ) > $time ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Mark the plugin as MMPU-incompatible.
 */
function pmpropfro_mmpu_incompatible_add_ons( $incompatible ) {
	$incompatible[] = 'PMPro Payflow Recurring Orders Add On';
	return $incompatible;
}
add_filter( 'pmpro_mmpu_incompatible_add_ons', 'pmpropfro_mmpu_incompatible_add_ons' );

/*
	Function to add links to the plugin row meta
*/
function pmpropfro_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-payflow-recurring-orders.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'https://www.paidmembershipspro.com/add-ons/payflow-recurring-orders-addon/' ) . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-payflow-recurring-orders' ) ) . '">' . __( 'Docs', 'pmpro-payflow-recurring-orders' ) . '</a>',
			'<a href="' . esc_url( 'https://paidmembershipspro.com/support/' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-payflow-recurring-orders' ) ) . '">' . __( 'Support', 'pmpro-payflow-recurring-orders' ) . '</a>',
		);
		$links     = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmpropfro_plugin_row_meta', 10, 2 );
