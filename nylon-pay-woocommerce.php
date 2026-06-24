<?php

/**
 * Plugin Name: Nylon Pay for WooCommerce
 * Plugin URI:  https://nylonpay.nilesquad.com
 * Description: Accept mobile money payments via Nylon Pay.
 * Version:     1.0.0
 * Author:      Ocen Chris and NileSquad
 * Author URI:  https://ocenchris.com
 * Text Domain: nylon-pay-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (! defined('ABSPATH')) {
	exit;
}

define('NYLON_PAY_VERSION', '1.0.0');
define('NYLON_PAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NYLON_PAY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Initialise the plugin after WooCommerce has loaded.
 */
function nylon_pay_init()
{
	if (! class_exists('WooCommerce')) {
		add_action('admin_notices', 'nylon_pay_missing_wc_notice');
		return;
	}

	require_once NYLON_PAY_PLUGIN_DIR . 'includes/class-nylon-pay-api.php';
	require_once NYLON_PAY_PLUGIN_DIR . 'includes/class-nylon-pay-gateway.php';
	require_once NYLON_PAY_PLUGIN_DIR . 'includes/class-nylon-pay-webhook.php';

	Nylon_Pay_Webhook::register_routes();
}
add_action('plugins_loaded', 'nylon_pay_init', 0);

/**
 * Admin notice shown when WooCommerce is not active.
 */
function nylon_pay_missing_wc_notice()
{
	echo '<div class="notice notice-error"><p>';
	// translators: %s is an HTML link to the WooCommerce install page.
	printf(
		esc_html__('Nylon Pay for WooCommerce requires WooCommerce to be installed and active. %s', 'nylon-pay-woocommerce'),
		'<a href="' . esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search')) . '">' . esc_html__('Install WooCommerce', 'nylon-pay-woocommerce') . '</a>'
	);
	echo '</p></div>';
}

/**
 * Add the Nylon Pay gateway to WooCommerce's list of available gateways.
 *
 * @param array $gateways Existing payment gateways.
 * @return array
 */
function nylon_pay_add_gateway($gateways)
{
	$gateways[] = 'Nylon_Pay_Gateway';
	return $gateways;
}
add_filter('woocommerce_payment_gateways', 'nylon_pay_add_gateway');

/**
 * Enqueue checkout stylesheet on the checkout page only.
 */
function nylon_pay_enqueue_styles()
{
	if (! is_checkout()) {
		return;
	}
	wp_enqueue_style(
		'nylon-pay-checkout',
		NYLON_PAY_PLUGIN_URL . 'assets/css/checkout.css',
		array(),
		NYLON_PAY_VERSION
	);
}
add_action('wp_enqueue_scripts', 'nylon_pay_enqueue_styles');

/**
 * Schedule the cron fallback polling job on plugin activation.
 */
function nylon_pay_activate()
{
	if (! wp_next_scheduled('nylon_pay_check_pending_orders')) {
		wp_schedule_event(time(), 'hourly', 'nylon_pay_check_pending_orders');
	}
}
register_activation_hook(__FILE__, 'nylon_pay_activate');

/**
 * Clear the scheduled cron on plugin deactivation.
 */
function nylon_pay_deactivate()
{
	wp_clear_scheduled_hook('nylon_pay_check_pending_orders');
}
register_deactivation_hook(__FILE__, 'nylon_pay_deactivate');
