<?php
/**
 * Nylon Pay Webhook Handler.
 *
 * Registers a WP REST API endpoint and processes incoming webhook POSTs
 * from the Nylon Pay platform.
 *
 * @package NylonPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles webhook delivery from Nylon Pay.
 *
 * All methods are static — this class has no state of its own.
 */
class Nylon_Pay_Webhook {

	/**
	 * Register the REST API route.
	 *
	 * Called inside rest_api_init via Nylon_Pay_Webhook::register_routes()
	 * which is hooked in the main bootstrap file.
	 */
	public static function register_routes() {
		add_action( 'rest_api_init', function () {
			register_rest_route(
				'nylon-pay/v1',
				'/webhook',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( 'Nylon_Pay_Webhook', 'handle' ),
					// Nylon Pay does not use OAuth — signature verification is the auth mechanism.
					'permission_callback' => '__return_true',
				)
			);
		} );
	}

	/**
	 * Main webhook callback — verify, find order, dispatch event.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = $request->get_body();
		$payload  = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
		}

		foreach ( array( 'event', 'data', 'signature' ) as $required_key ) {
			if ( ! isset( $payload[ $required_key ] ) ) {
				return new WP_REST_Response( array( 'error' => 'Missing field: ' . $required_key ), 400 );
			}
		}

		// Load the webhook secret from WooCommerce gateway settings.
		$gateway_settings = get_option( 'woocommerce_nylon_pay_settings', array() );
		$webhook_secret   = isset( $gateway_settings['webhook_secret'] ) ? $gateway_settings['webhook_secret'] : '';

		if ( empty( $webhook_secret ) ) {
			self::log( 'Webhook received but webhook_secret is not configured.', 'warning' );
			return new WP_REST_Response( array( 'error' => 'Gateway misconfigured' ), 400 );
		}

		// Signature verification runs BEFORE any order mutation.
		$signature = (string) $payload['signature'];
		if ( ! Nylon_Pay_API::verify_webhook_signature( $raw_body, $signature, $webhook_secret ) ) {
			self::log( 'Webhook signature verification failed.', 'error' );
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
		}

		$event     = sanitize_text_field( $payload['event'] );
		$data      = is_array( $payload['data'] ) ? $payload['data'] : array();
		$reference = isset( $data['reference'] ) ? sanitize_text_field( $data['reference'] ) : '';

		if ( empty( $reference ) ) {
			return new WP_REST_Response( array( 'error' => 'Missing reference' ), 400 );
		}

		// Idempotency — use a transient keyed on event + reference to prevent
		// duplicate order mutations if Nylon Pay re-delivers the same webhook.
		$transient_key = 'nylon_pay_wh_' . md5( $event . $reference );
		if ( get_transient( $transient_key ) ) {
			self::log( 'Duplicate webhook skipped: ' . $event . ' / ' . $reference, 'info' );
			return new WP_REST_Response( array( 'status' => 'already processed' ), 200 );
		}

		// Look up the WooCommerce order by the reference stored at checkout.
		$orders = wc_get_orders( array(
			'meta_key'   => '_nylon_pay_reference',
			'meta_value' => $reference,
			'limit'      => 1,
		) );

		if ( empty( $orders ) ) {
			self::log( 'No order found for reference: ' . $reference, 'warning' );
			return new WP_REST_Response( array( 'error' => 'Order not found' ), 404 );
		}

		$order = $orders[0];

		// Mark as processed before updating the order to prevent race conditions
		// on concurrent webhook deliveries.
		set_transient( $transient_key, 1, DAY_IN_SECONDS );

		self::process_event( $event, $data, $order );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Update the WooCommerce order based on the Nylon Pay event.
	 *
	 * @param string   $event Webhook event name e.g. 'collection.completed'.
	 * @param array    $data  Transaction data object from the payload.
	 * @param WC_Order $order The matching WooCommerce order.
	 */
	private static function process_event( string $event, array $data, WC_Order $order ): void {
		$txn_id         = isset( $data['id'] )            ? sanitize_text_field( $data['id'] )            : '';
		$operator_tid   = isset( $data['operatorTid'] )   ? sanitize_text_field( $data['operatorTid'] )   : '';
		$failure_reason = isset( $data['failureReason'] ) ? sanitize_text_field( $data['failureReason'] ) : '';

		switch ( $event ) {

			case 'collection.completed':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $txn_id );
					$order->update_meta_data( '_nylon_pay_transaction_id', $txn_id );
					if ( $operator_tid ) {
						$order->update_meta_data( '_nylon_pay_operator_tid', $operator_tid );
					}
					$order->add_order_note(
						sprintf(
							// translators: 1: Nylon Pay transaction ID, 2: operator transaction ID.
							__( 'Payment confirmed by Nylon Pay. Txn ID: %1$s | Operator TID: %2$s', 'nylon-pay-woocommerce' ),
							$txn_id,
							$operator_tid ? $operator_tid : __( 'N/A', 'nylon-pay-woocommerce' )
						)
					);
					$order->save();
				}
				break;

			case 'collection.failed':
				if ( ! in_array( $order->get_status(), array( 'failed', 'cancelled', 'completed', 'processing' ), true ) ) {
					$order->update_status(
						'failed',
						sprintf(
							// translators: %s is the failure reason from Nylon Pay.
							__( 'Payment failed via Nylon Pay. Reason: %s', 'nylon-pay-woocommerce' ),
							$failure_reason ? $failure_reason : __( 'Unknown', 'nylon-pay-woocommerce' )
						)
					);
					$order->update_meta_data( '_nylon_pay_processed', 'yes' );
					$order->save();
				}
				break;

			case 'refund.completed':
				$order->add_order_note(
					sprintf(
						// translators: %s is the Nylon Pay transaction ID for the refund.
						__( 'Nylon Pay refund processed. Txn ID: %s', 'nylon-pay-woocommerce' ),
						$txn_id
					)
				);
				break;

			case 'chargeback.received':
				$order->update_status(
					'on-hold',
					__( 'Nylon Pay: Chargeback received. Please review via your dashboard.', 'nylon-pay-woocommerce' )
				);
				break;

			default:
				self::log( 'Unhandled webhook event: ' . $event, 'info' );
				break;
		}
	}

	/**
	 * Write to the WooCommerce log under the 'nylon-pay' source channel.
	 *
	 * @param string $message Log message.
	 * @param string $level   'debug'|'info'|'warning'|'error'.
	 */
	private static function log( string $message, string $level = 'info' ) {
		wc_get_logger()->log( $level, $message, array( 'source' => 'nylon-pay' ) );
	}
}
