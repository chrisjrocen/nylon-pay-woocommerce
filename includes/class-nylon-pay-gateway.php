<?php
/**
 * Nylon Pay WooCommerce Payment Gateway.
 *
 * @package NylonPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends WC_Payment_Gateway to add Nylon Pay (Mobile Money / Bank Transfer)
 * as a payment option at checkout.
 */
class Nylon_Pay_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor — sets gateway properties, loads settings, registers hooks.
	 */
	public function __construct() {
		$this->id                 = 'nylon_pay';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'Nylon Pay', 'nylon-pay-woocommerce' );
		$this->method_description = __( 'Accept Mobile Money and Bank Transfer payments via Nylon Pay.', 'nylon-pay-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		add_action( 'nylon_pay_check_pending_orders', array( $this, 'check_pending_orders' ) );
	}

	// -------------------------------------------------------------------------
	// Admin settings
	// -------------------------------------------------------------------------

	/**
	 * Define all admin settings fields shown on the gateway settings page.
	 */
	public function init_form_fields() {
		$webhook_url = rest_url( 'nylon-pay/v1/webhook' );

		$this->form_fields = array(

			'enabled' => array(
				'title'   => __( 'Enable / Disable', 'nylon-pay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Nylon Pay', 'nylon-pay-woocommerce' ),
				'default' => 'no',
			),

			'title' => array(
				'title'       => __( 'Payment Method Title', 'nylon-pay-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Mobile Money (Nylon Pay)', 'nylon-pay-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Title shown to customers on the checkout page.', 'nylon-pay-woocommerce' ),
			),

			'description' => array(
				'title'   => __( 'Description', 'nylon-pay-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with MTN MoMo, Airtel Money, or Bank Transfer via Nylon Pay.', 'nylon-pay-woocommerce' ),
			),

			'mode' => array(
				'title'       => __( 'Mode', 'nylon-pay-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'sandbox' => __( 'Sandbox (Testing)', 'nylon-pay-woocommerce' ),
					'live'    => __( 'Live (Production)', 'nylon-pay-woocommerce' ),
				),
				'default'     => 'sandbox',
				'desc_tip'    => true,
				'description' => __( 'Use Sandbox for testing. Switch to Live only after completing KYC on the Nylon Pay dashboard.', 'nylon-pay-woocommerce' ),
			),

			'sandbox_api_key' => array(
				'title'       => __( 'Sandbox API Key', 'nylon-pay-woocommerce' ),
				'type'        => 'password',
				'desc_tip'    => true,
				'description' => __( 'Starts with npk_sandbox_... — from Dashboard > Settings > API Keys.', 'nylon-pay-woocommerce' ),
				'default'     => '',
			),

			'sandbox_api_secret' => array(
				'title'       => __( 'Sandbox API Secret', 'nylon-pay-woocommerce' ),
				'type'        => 'password',
				'desc_tip'    => true,
				'description' => __( 'Starts with nps_sandbox_... — shown only once on creation.', 'nylon-pay-woocommerce' ),
				'default'     => '',
			),

			'live_api_key' => array(
				'title'       => __( 'Live API Key', 'nylon-pay-woocommerce' ),
				'type'        => 'password',
				'desc_tip'    => true,
				'description' => __( 'Starts with npk_live_... — from Dashboard > Settings > API Keys.', 'nylon-pay-woocommerce' ),
				'default'     => '',
			),

			'live_api_secret' => array(
				'title'       => __( 'Live API Secret', 'nylon-pay-woocommerce' ),
				'type'        => 'password',
				'desc_tip'    => true,
				'description' => __( 'Starts with nps_live_... — shown only once on creation, store it securely.', 'nylon-pay-woocommerce' ),
				'default'     => '',
			),

			'webhook_secret' => array(
				'title'       => __( 'Webhook Secret', 'nylon-pay-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					// translators: %s is the webhook URL for this site.
					__( 'Found in Nylon Pay Dashboard > Settings > Webhooks. Your webhook URL: %s', 'nylon-pay-woocommerce' ),
					'<br><code>' . esc_url( $webhook_url ) . '</code>'
				),
				'default'     => '',
			),

			'enabled_mobile_money' => array(
				'title'   => __( 'Mobile Money', 'nylon-pay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable MTN MoMo / Airtel Money', 'nylon-pay-woocommerce' ),
				'default' => 'yes',
			),

			'enabled_bank_transfer' => array(
				'title'   => __( 'Bank Transfer', 'nylon-pay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Bank Transfer', 'nylon-pay-woocommerce' ),
				'default' => 'no',
			),

			'debug' => array(
				'title'   => __( 'Debug Log', 'nylon-pay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging (WooCommerce > Status > Logs)', 'nylon-pay-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Checkout UI
	// -------------------------------------------------------------------------

	/**
	 * Output the payment fields shown at checkout (phone number + method selector).
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}

		echo '<div class="nylon-pay-phone-field">';

		echo '<p class="form-row form-row-wide">';
		echo '<label for="nylon_pay_phone">';
		echo esc_html__( 'Mobile Money Phone Number', 'nylon-pay-woocommerce' );
		echo ' <span class="required">*</span></label>';
		echo '<input type="tel" id="nylon_pay_phone" name="nylon_pay_phone" class="input-text" placeholder="256700000000" required />';
		echo '<span class="nylon-pay-field-hint">' . esc_html__( 'Enter your number without a leading + sign, e.g. 256700000000', 'nylon-pay-woocommerce' ) . '</span>';
		echo '</p>';

		$methods = $this->get_enabled_methods();
		if ( count( $methods ) > 1 ) {
			echo '<p class="form-row form-row-wide">';
			echo '<label for="nylon_pay_method">' . esc_html__( 'Payment Method', 'nylon-pay-woocommerce' ) . ' <span class="required">*</span></label>';
			echo '<select id="nylon_pay_method" name="nylon_pay_method" class="select">';
			foreach ( $methods as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</p>';
		} elseif ( ! empty( $methods ) ) {
			$default_method = array_key_first( $methods );
			echo '<input type="hidden" name="nylon_pay_method" value="' . esc_attr( $default_method ) . '" />';
		}

		echo '</div>';
	}

	/**
	 * Build the list of payment methods enabled in settings.
	 *
	 * @return array Associative array of method_key => label.
	 */
	private function get_enabled_methods(): array {
		$methods = array();
		if ( 'yes' === $this->get_option( 'enabled_mobile_money', 'yes' ) ) {
			$methods['mobileMoney'] = __( 'MTN MoMo / Airtel Money', 'nylon-pay-woocommerce' );
		}
		if ( 'yes' === $this->get_option( 'enabled_bank_transfer', 'no' ) ) {
			$methods['bank'] = __( 'Bank Transfer', 'nylon-pay-woocommerce' );
		}
		return $methods;
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate checkout fields before process_payment() is called.
	 *
	 * @return bool True when all fields are valid.
	 */
	public function validate_fields() {
		$phone  = isset( $_POST['nylon_pay_phone'] )
			? sanitize_text_field( wp_unslash( $_POST['nylon_pay_phone'] ) )
			: '';
		$method = isset( $_POST['nylon_pay_method'] )
			? sanitize_text_field( wp_unslash( $_POST['nylon_pay_method'] ) )
			: '';

		if ( empty( $phone ) ) {
			wc_add_notice( __( 'Please enter your phone number.', 'nylon-pay-woocommerce' ), 'error' );
			return false;
		}

		// Strip leading + and whitespace, then check for valid digit count.
		$clean_phone = preg_replace( '/[\s+]/', '', $phone );
		if ( ! preg_match( '/^\d{9,15}$/', $clean_phone ) ) {
			wc_add_notice( __( 'Please enter a valid phone number (digits only, no + sign, e.g. 256700000000).', 'nylon-pay-woocommerce' ), 'error' );
			return false;
		}

		$allowed_methods = array( 'mobileMoney', 'bank' );
		if ( ! empty( $method ) && ! in_array( $method, $allowed_methods, true ) ) {
			wc_add_notice( __( 'Please select a valid payment method.', 'nylon-pay-woocommerce' ), 'error' );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Payment processing
	// -------------------------------------------------------------------------

	/**
	 * Initiate the Nylon Pay collection when the customer clicks "Place Order".
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array|null Result array on success; null on failure (WC stops checkout).
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$phone  = isset( $_POST['nylon_pay_phone'] )
			? preg_replace( '/[\s+]/', '', sanitize_text_field( wp_unslash( $_POST['nylon_pay_phone'] ) ) )
			: '';
		$method = isset( $_POST['nylon_pay_method'] )
			? sanitize_text_field( wp_unslash( $_POST['nylon_pay_method'] ) )
			: 'mobileMoney';

		// Build and persist the reference before the API call so it can be
		// looked up by the webhook even if process_payment() never returns.
		$reference = $this->generate_reference( $order_id );
		$order->update_meta_data( '_nylon_pay_reference', $reference );
		$order->update_meta_data( '_nylon_pay_method', $method );
		$order->save();

		$api = new Nylon_Pay_API( $this->get_api_key(), $this->get_api_secret() );

		$result = $api->collect_payment( array(
			'amount'        => $this->get_amount_in_smallest_unit( $order ),
			'currency'      => get_woocommerce_currency(),
			'description'   => sprintf(
				// translators: %s is the WooCommerce order number.
				__( 'Order #%s', 'nylon-pay-woocommerce' ),
				$order->get_order_number()
			),
			'customer_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone_number'  => $phone,
			'email'         => $order->get_billing_email(),
			'reference'     => $reference,
			'method'        => $method,
			'metadata'      => array(
				'order_id'  => (string) $order_id,
				'order_key' => $order->get_order_key(),
				'site_url'  => get_site_url(),
			),
		) );

		if ( ! $result['success'] ) {
			$error_message = isset( $result['message'] ) ? $result['message'] : __( 'Unknown error', 'nylon-pay-woocommerce' );
			wc_add_notice( __( 'Payment could not be initiated. Please try again.', 'nylon-pay-woocommerce' ), 'error' );
			$this->log( 'collect_payment failed for order #' . $order_id . ': ' . $error_message, 'error' );
			return null;
		}

		$order->update_status(
			'pending',
			__( 'Nylon Pay payment initiated. Awaiting confirmation.', 'nylon-pay-woocommerce' )
		);
		$order->add_order_note(
			// translators: %s is the Nylon Pay reference string.
			sprintf( __( 'Nylon Pay reference: %s', 'nylon-pay-woocommerce' ), $reference )
		);

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	// -------------------------------------------------------------------------
	// Cron fallback — poll pending orders
	// -------------------------------------------------------------------------

	/**
	 * Poll Nylon Pay for any pending orders that never received a webhook.
	 *
	 * Runs on the 'nylon_pay_check_pending_orders' cron action (hourly).
	 */
	public function check_pending_orders() {
		$orders = wc_get_orders( array(
			'status'         => array( 'pending', 'on-hold' ),
			'payment_method' => 'nylon_pay',
			'date_before'    => gmdate( 'Y-m-d H:i:s', time() - 600 ),
			'limit'          => 20,
		) );

		if ( empty( $orders ) ) {
			return;
		}

		$api = new Nylon_Pay_API( $this->get_api_key(), $this->get_api_secret() );

		foreach ( $orders as $order ) {
			$reference = $order->get_meta( '_nylon_pay_reference' );
			if ( ! $reference ) {
				continue;
			}

			$result = $api->get_status( $reference );
			if ( ! $result['success'] || empty( $result['data']['status'] ) ) {
				continue;
			}

			$status = sanitize_text_field( $result['data']['status'] );

			if ( 'successful' === $status && ! $order->is_paid() ) {
				$order->payment_complete( isset( $result['data']['id'] ) ? sanitize_text_field( $result['data']['id'] ) : '' );
				$order->add_order_note( __( 'Nylon Pay: Payment confirmed via status poll.', 'nylon-pay-woocommerce' ) );
			} elseif ( 'failed' === $status ) {
				$order->update_status( 'failed', __( 'Nylon Pay: Payment failed (status poll).', 'nylon-pay-woocommerce' ) );
			} elseif ( 'cancelled' === $status ) {
				$order->update_status( 'cancelled', __( 'Nylon Pay: Payment cancelled (status poll).', 'nylon-pay-woocommerce' ) );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the active API key based on the configured mode.
	 *
	 * @return string
	 */
	public function get_api_key(): string {
		return 'live' === $this->get_option( 'mode', 'sandbox' )
			? (string) $this->get_option( 'live_api_key', '' )
			: (string) $this->get_option( 'sandbox_api_key', '' );
	}

	/**
	 * Return the active API secret based on the configured mode.
	 *
	 * @return string
	 */
	public function get_api_secret(): string {
		return 'live' === $this->get_option( 'mode', 'sandbox' )
			? (string) $this->get_option( 'live_api_secret', '' )
			: (string) $this->get_option( 'sandbox_api_secret', '' );
	}

	/**
	 * Generate a unique 15-character payment reference for the given order.
	 *
	 * Format: "NP" + 6-digit zero-padded order ID + 7 random hex chars = 15 chars.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	private function generate_reference( int $order_id ): string {
		$prefix = 'NP' . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT );
		$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		return substr( $prefix . $suffix, 0, 15 );
	}

	/**
	 * Convert the order total to the smallest currency unit accepted by Nylon Pay.
	 *
	 * UGX, KES, TZS, and RWF have no subunits — pass as whole integers.
	 * USD, EUR, and GBP are expressed in cents (× 100).
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return int
	 */
	private function get_amount_in_smallest_unit( WC_Order $order ): int {
		$currency          = get_woocommerce_currency();
		$no_subunit_currencies = array( 'UGX', 'KES', 'TZS', 'RWF' );

		if ( in_array( $currency, $no_subunit_currencies, true ) ) {
			return (int) round( $order->get_total() );
		}

		return (int) round( $order->get_total() * 100 );
	}

	/**
	 * Write a log entry when debug mode is enabled (errors always log).
	 *
	 * @param string $message Log message.
	 * @param string $level   WC_Logger level: 'debug'|'info'|'notice'|'warning'|'error'.
	 */
	private function log( string $message, string $level = 'debug' ) {
		$is_debug = 'yes' === $this->get_option( 'debug', 'no' );
		if ( ! $is_debug && 'error' !== $level ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'nylon-pay' ) );
	}
}
