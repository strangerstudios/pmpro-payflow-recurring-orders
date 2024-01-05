=== Paid Memberships Pro - Payflow Recurring Orders ===
Contributors: strangerstudios
Tags: paid memberships pro, pmpro, paypal, payflow
Requires at least: 4
Tested up to: 6.4
Stable tag: 0.3.1

Synchronize Payflow recurring subscriptions with Paid Memberships Pro.

== Description ==

We have developed this Add On to synchronize Payflow recurring orders and cancellations with Paid Memberships Pro. This addon should definitely be installed if you are using the Payflow Pro gateway option in Paid Memberships Pro and have recurring subscriptions.

== Installation ==

= Prerequisites =
1. You must have Paid Memberships Pro installed and activated on your site.

= Download, Install and Activate! =
1. Download the latest version of the plugin.
1. Unzip the downloaded file to your computer.
1. Upload the /pmpro-payflow-recurring-orders/ directory to the /wp-content/plugins/ directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. That’s it. No settings.

View full documentation at: https://www.paidmembershipspro.com/add-ons/payflow-recurring-orders-addon/

== Changelog ==
= 0.3.1 - 2024-01-05 =
* BUG FIX/ENHANCEMENT: Marking plugin as incompatible with Multiple Memberships Per User for the PMPro v3.0 update. #6 (@dparker1005)

= .3 =
* BUG FIX/ENHANCEMENT: Removed the code to only run the cron between 1AM and 6AM. Instead, it will run every hour and you can use the new pmpro_payflow_recurring_orders_skip_cron filter to return true during those times if you'd like to skip the cron during heavy times.

= .2 =
* BUG FIX: Fixed setting of $end value.
* BUG/ENHANCEMENT: Added force parameter.
* FEATURE: Added check for PMPRO_CRON_LIMIT to override the 50 per run limit.

= .1.1 =
* BUG FIX: Fixed use of pmpropfro_paymentAfter to pass payments along.

= .1 =
* Initial version.