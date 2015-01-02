<?php
/*
Plugin Name: PMPro Payflow Recurring Orders
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-payflow-recurring-orders/
Description: Check daily for new recurring orders in Payflow and add as PMPro orders.
Version: .1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/
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

//for testing
function init_test_1()
{
	if(!empty($_REQUEST['test']) && current_user_can('manage_options'))
	{
		pmpro_payflow_recurring_orders();	
		exit;
	}
}
add_action('init', 'init_test_1');

/*
	Cron function.
*/
add_action('pmpro_payflow_recurring_orders', 'pmpro_payflow_recurring_orders');
function pmpro_payflow_recurring_orders()
{
	//is PMPro even active?
	if(!function_exists('pmpro_hasMembershipLevel'))
		return;
	
	global $wpdb;
	$now = current_time('timestamp');
	
	//between what hours should the cron run?
	$cron_start = 1;	//1 AM
	$cron_end = 24;		//6 AM
	$current_hour = date('G', $now);
	if($current_hour > $cron_end || $current_hour < $cron_start)
		return;
	
	//are we paused? value is timestamp. if set wait until then
	$paused = get_option('pmpro_pfro_paused', false);
	if(!empty($paused) && $paused > $now)
		return;
		
	//how many subscriptions to run at one time. based on your server speed and timeout limits/etc.
	$nper = 20;
	
	//where did we leave off?	
	if(!empty($_REQUEST['start']))
		$start = get_option('payflow_recurring_orders_cron_count', 0);
	else
		$start = intval($_REQUEST['start']);
	
	//next one
	update_option('payflow_recurring_orders_cron_count', intval($start) + intval($nper));
	
	//get subs
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
		WHERE DATEDIFF('" . current_time('mysql') . "', next_payment_date) <= 0
		LIMIT $start, $nper
	";
		
	$sub_user_ids = $wpdb->get_col($sqlQuery);		
	$count = $wpdb->get_var('SELECT FOUND_ROWS()');
	
	echo "Processing " . intval($start) . " to " . (intval($start)+intval($nper)) . " of " . $count . " subscriptions.<hr />";
	
	//if no more subs, pause until tomorrow
	if(empty($sub_user_ids))
	{
		$tomorrow = strtotime(date("Y-m-d 00:00:00", $now+3600*24));
		//update_option('pmpro_pfro_paused', $tomorrow);
		//update_option('payflow_recurring_orders_cron_count', 0);
		
		return;
	}
	
	//loop through subs
	foreach($sub_user_ids as $user_id)
	{	
		$user = get_userdata($user_id);
		
		if(empty($user->ID))
		{
			echo "Coundn't find user #" . $user_id . "...<br /><hr />";
			continue;
		}
		
		echo "Checking for recurring orders for user #" . $user->ID . " " . $user->user_login . " (" . $user->user_email . ")...<br />";
		
		$last_order = new MemberOrder();
		$last_order->getLastMemberOrder($user_id);
		
		if(!empty($last_order->id))
			echo "- Last order found. #" . $last_order->id . ", Code: " . $last_order->code . ", SubID: " . $last_order->subscription_transaction_id . ".<br />";		
		else
		{
			echo "- No last order. Skipping.";
			echo "<hr />";
			continue;
		}
				
		//is it even Payflow?
		if($last_order->gateway != "payflowpro")
		{
			echo "- Order is for '" . $last_order->gateway . "' gateway.<br />";
			echo "<hr />";
			continue;
		}
				
		//check subscription
		if(!empty($last_order->subscription_transaction_id))
		{					
			echo "- Checking subscription #" . $last_order->subscription_transaction_id . ".<br />";
						
			$status = pmpropfro_getSubscriptionPayments($last_order);
			
			//find orders
			$payments = pmpropfro_processPaymentHistory($status);
						
			if(!empty($payments))
			{
				foreach($payments as $payment)
				{					
					//success?
					if($payment['P_TRANSTATE'] == 8)
					{
						//check if we have this one already
						$old_order = new MemberOrder();
						$old_order->getMemberOrderByPaymentTransactionID($payment['P_PNREF']);
						
						if(empty($old_order->id))
						{
							//not there yet, add it
							$morder = new MemberOrder();
							$morder->user_id = $last_order->user_id;
							$morder->membership_id = $last_order->membership_id;
							$morder->payment_transaction_id = $payment['P_PNREF'];
							$morder->subscription_transaction_id = $last_order->subscription_transaction_id;
							
							$morder->InitialPayment = $payment['P_AMT']; //not the initial payment, but the class is expecting that
							$morder->PaymentAmount = $payment['P_AMT'];														
														
							//save
							//$morder->saveOrder();
							//$morder->getMemberOrderByID($morder->id);
														
							if(!empty($morder->id))
							{
								//update the timestamp							
								$timestamp = date("Y-m-d H:i:s", strtotime($payment['P_TRANSTIME']));							
								$wpdb->query("UPDATE $wpdb->pmpro_membership_orders SET timestamp = '" . $timestamp . "' WHERE id = '" . $morder->id . "' LIMIT 1");
								echo "- Order added. #" . $morder->id . ".<br />";
								
								//email the user their invoice				
								$pmproemail = new PMProEmail();				
								//$pmproemail->sendInvoiceEmail($user, $morder);						
								
								//echo "- Invoice email sent to " . $user->user_email . ".";
							}
							else
								echo "- Error adding order.";														
							
							
						}
					}
					else
					{
						echo "- Payment " . $payment['P_PNREF'] . " has status #" . $payment['P_TRANSTATE'] . " so not saving.<br />";
					}
				}
			}
			else
			{				
				echo "- No payments found.<br />";
			}
		}

		echo "<hr />";
	}
}

/*
	Get Payflow Subscription Payments
*/
function pmpropfro_getSubscriptionPayments($order)
{			
	if(empty($order->subscription_transaction_id))
		return false;
	
	//paypal profile stuff
	$nvpStr = "";			
	$nvpStr .= "&ORIGPROFILEID=" . urlencode($order->subscription_transaction_id) . "&ACTION=I&PAYMENTHISTORY=Y";						
				
	$httpParsedResponseAr = $order->Gateway->PPHttpPost('R', $nvpStr);						
				
	if("0" == strtoupper($httpParsedResponseAr["RESULT"])) 
	{				
		return $httpParsedResponseAr;				
	} 
	else  
	{
		$order->status = "error";
		$order->errorcode = $this->httpParsedResponseAr['L_ERRORCODE0'];
		$order->error = urldecode($this->httpParsedResponseAr['L_LONGMESSAGE0']);
		$order->shorterror = urldecode($this->httpParsedResponseAr['L_SHORTMESSAGE0']);
		
		return false;				
	}
}

/*
	Get Payments from Payflow Response
*/
function pmpropfro_processPaymentHistory($results)
{	
	$payments = array();
	
	//is there at least one?
	if(!empty($results['P_PNREF1']))
	{
		$npayments = ceil((count($results) - 3)/6);
		for($i = 1; $i <= $npayments; $i++)
		{
			$payments[] = array(
				"P_PNREF" => $results['P_PNREF'.$i],
				"P_TRANSTIME" => $results['P_TRANSTIME'.$i],
				"P_RESULT" => $results['P_RESULT'.$i],
				"P_TENDER" => $results['P_TENDER'.$i],
				"P_AMT" => $results['P_AMT'.$i],
				"P_TRANSTATE" => $results['P_TRANSTATE'.$i]				
			);
		}
	}
	
	return $payments;
}