<?php
/**
 * Nylon Pay API wrapper.
 *
 * Implements the Nile.js envelope protocol for making raw HTTP calls to the
 * Nylon Pay backend. No WooCommerce dependencies — this class is pure PHP.
 *
 * @package NylonPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for the Nylon Pay REST API.
 *
 * Every request is a single POST to the /api/services endpoint wrapped in a
 * signed Nile.js envelope. See implementation.MD for the full protocol spec.
 */
class Nylon_Pay_API {

	/**
	 * @var string Merchant API key (npk_sandbox_... or npk_live_...).
	 */
	private $api_key;

	/**
	 * @var string Merchant API secret (nps_sandbox_... or nps_live_...).
	 */
	private $api_secret;

	/**
	 * @var string Base URL for every API request.
	 */
	private $base_url = 'https://api.nylonpay.nilesquad.com/api/services';

	/**
	 * Constructor.
	 *
	 * @param string $api_key    Merchant API key.
	 * @param string $api_secret Merchant API secret.
	 */
	public function __construct( string $api_key, string $api_secret ) {
		$this->api_key    = $api_key;
		$this->api_secret = $api_secret;
	}

	// -------------------------------------------------------------------------
	// Public API methods
	// -------------------------------------------------------------------------

	/**
	 * Initiate a mobile money or bank transfer collection.
	 *
	 * @param array $args {
	 *   @type int    $amount        Amount in smallest currency unit.
	 *   @type string $currency      ISO currency code e.g. 'UGX'.
	 *   @type string $description   Human-readable payment description.
	 *   @type string $customer_name Customer full name.
	 *   @type string $phone_number  Phone number without leading +.
	 *   @type string $email         (optional) Customer email.
	 *   @type string $reference     13–15 character unique reference.
	 *   @type string $method        (optional) 'mobileMoney' or 'bank'.
	 *   @type array  $metadata      (optional) Arbitrary key/value pairs.
	 * }
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public function collect_payment( array $args ): array {
		$payload = array(
			'amount'      => absint( $args['amount'] ),
			'currency'    => sanitize_text_field( $args['currency'] ),
			'description' => sanitize_text_field( $args['description'] ),
			'reference'   => sanitize_text_field( $args['reference'] ),
			'customer'    => array(
				'name'        => sanitize_text_field( $args['customer_name'] ),
				'phoneNumber' => sanitize_text_field( $args['phone_number'] ),
			),
		);

		if ( ! empty( $args['email'] ) ) {
			$payload['customer']['email'] = sanitize_email( $args['email'] );
		}

		if ( ! empty( $args['method'] ) ) {
			$payload['method'] = sanitize_text_field( $args['method'] );
		}

		if ( ! empty( $args['metadata'] ) && is_array( $args['metadata'] ) ) {
			$payload['metadata'] = $args['metadata'];
		}

		return $this->make_request( 'sdk-collect-payment-and-resolve', $payload );
	}

	/**
	 * Get the current status of a transaction by reference.
	 *
	 * @param string $reference The unique payment reference (13–15 chars).
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public function get_status( string $reference ): array {
		$payload = array(
			'reference' => sanitize_text_field( $reference ),
		);

		return $this->make_request( 'sdk-get-status', $payload );
	}

	/**
	 * Verify a webhook signature from the Nylon Pay platform.
	 *
	 * The signature lives inside the JSON body (not an HTTP header) and is
	 * computed as HMAC-SHA256 over the raw request body bytes.
	 *
	 * @param string $raw_body  Raw POST body string received from Nylon Pay.
	 * @param string $signature The 'signature' value extracted from the payload.
	 * @param string $secret    Webhook secret from the Nylon Pay dashboard.
	 * @return bool True only when signature matches and payload is fresh (<= 300 s).
	 */
	public static function verify_webhook_signature( string $raw_body, string $signature, string $secret ): bool {
		if ( empty( $secret ) || empty( $signature ) || empty( $raw_body ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $raw_body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		// Freshness check — guard against replayed webhooks.
		$body = json_decode( $raw_body, true );
		if ( ! is_array( $body ) || empty( $body['timestamp'] ) ) {
			return false;
		}

		$ts_raw = $body['timestamp'];

		if ( is_numeric( $ts_raw ) ) {
			$ts_ms = (float) $ts_raw;
			// Distinguish seconds from milliseconds.
			$ts_ms = $ts_ms < 1.0e12 ? $ts_ms * 1000.0 : $ts_ms;
		} else {
			$parsed = strtotime( (string) $ts_raw );
			if ( false === $parsed ) {
				return false;
			}
			$ts_ms = (float) $parsed * 1000.0;
		}

		$age_seconds = abs( ( microtime( true ) * 1000 - $ts_ms ) / 1000 );

		return $age_seconds <= 300;
	}

	// -------------------------------------------------------------------------
	// Private helpers — Nile.js envelope + HMAC-SHA256 signing
	// -------------------------------------------------------------------------

	/**
	 * Build the signed Nile.js envelope and POST it; retry on transient failures.
	 *
	 * @param string $action  SDK action name e.g. 'sdk-collect-payment-and-resolve'.
	 * @param array  $payload Action-specific fields (without _fingerprint).
	 * @return array { success: bool, data?: array, message?: string }
	 */
	private function make_request( string $action, array $payload ): array {
		$fingerprint         = $this->generate_fingerprint();
		$payload['_fingerprint'] = $fingerprint;

		$nonce        = $this->generate_nonce();
		$timestamp_ms = (string) round( microtime( true ) * 1000 );
		$signature    = $this->create_signature( $fingerprint, $nonce, $timestamp_ms, $payload );

		$envelope = array(
			'intent'  => 'execute',
			'service' => 'sdk',
			'action'  => $action,
			'payload' => $payload,
		);

		$headers = array(
			'Content-Type'       => 'application/json',
			'Accept'             => 'application/json',
			'x-nylon-key'        => $this->api_key,
			'x-nylon-nonce'      => $nonce,
			'x-nylon-signature'  => $signature,
			'x-nylon-timestamp'  => $timestamp_ms,
		);

		$retryable_codes = array( 408, 429, 500, 502, 503, 504 );
		$max_retries     = 2;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				// Exponential backoff: 2^attempt seconds + up to 0.5 s jitter.
				$delay_ms = (int) ( pow( 2, $attempt ) * 1000 + wp_rand( 0, 500 ) );
				usleep( $delay_ms * 1000 );
			}

			$response = wp_remote_post(
				$this->base_url,
				array(
					'timeout' => 30,
					'headers' => $headers,
					'body'    => wp_json_encode( $envelope ),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt === $max_retries ) {
					return array(
						'success' => false,
						'message' => $response->get_error_message(),
					);
				}
				continue;
			}

			$status_code   = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			// Nylon Pay returns HTTP 200 for both success and application errors.
			if ( in_array( (int) $status_code, $retryable_codes, true ) && $attempt < $max_retries ) {
				continue;
			}

			if ( $status_code >= 200 && $status_code < 300 ) {
				return array(
					'success' => true,
					'data'    => is_array( $decoded ) ? $decoded : array(),
				);
			}

			return array(
				'success' => false,
				// translators: %d is an HTTP status code number.
				'message' => sprintf( __( 'HTTP %d', 'nylon-pay-woocommerce' ), $status_code ),
				'data'    => is_array( $decoded ) ? $decoded : array(),
			);
		}

		// Should be unreachable, but satisfy the type system.
		return array( 'success' => false, 'message' => __( 'Request failed after retries.', 'nylon-pay-woocommerce' ) );
	}

	/**
	 * Build the server fingerprint — a SHA-256 hex of PHP environment info.
	 *
	 * Mirrors the Node.js fingerprint constructed in the TypeScript SDK using
	 * PHP equivalents of os/process values. Confirmed open question with
	 * NileSquad (see implementation.MD §10.1).
	 *
	 * @return string Lowercase hex SHA-256.
	 */
	private function generate_fingerprint(): string {
		$info = sprintf(
			'type:%s|platform:%s|arch:%s|release:%s|hostname:%s|node:%s|v8:%s',
			php_uname( 's' ),
			strtolower( php_uname( 's' ) ),
			php_uname( 'm' ),
			php_uname( 'r' ),
			gethostname(),
			phpversion(),
			phpversion()
		);

		return hash( 'sha256', $info );
	}

	/**
	 * Generate a cryptographically random nonce (16 hex bytes).
	 *
	 * @return string 32-character lowercase hex string.
	 */
	private function generate_nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Compute the HMAC-SHA256 request signature.
	 *
	 * Signature message: fingerprint + "." + nonce + "." + timestamp_ms + "." + canonicalJSON(payload)
	 *
	 * @param string $fingerprint  Server fingerprint hex.
	 * @param string $nonce        Random nonce hex.
	 * @param string $timestamp_ms Unix timestamp in milliseconds as a string.
	 * @param array  $payload      Request payload (will be canonical-JSON encoded).
	 * @return string Lowercase hex HMAC-SHA256.
	 */
	private function create_signature( string $fingerprint, string $nonce, string $timestamp_ms, array $payload ): string {
		$canonical = $this->canonical_json( $payload );
		$message   = $fingerprint . '.' . $nonce . '.' . $timestamp_ms . '.' . $canonical;

		return hash_hmac( 'sha256', $message, $this->api_secret );
	}

	/**
	 * Encode an array to canonical JSON (RFC 8785 JCS).
	 *
	 * Object keys are recursively sorted by UTF-16 code unit order. For the
	 * purely ASCII keys used by the Nylon Pay API this is equivalent to byte
	 * order, so strcmp() is the correct comparator.
	 *
	 * @param mixed $value The value to encode.
	 * @return string JSON string with no extra whitespace.
	 */
	private function canonical_json( $value ): string {
		if ( is_array( $value ) && $this->is_assoc( $value ) ) {
			uksort( $value, 'strcmp' );
			$pairs = array();
			foreach ( $value as $k => $v ) {
				$pairs[] = wp_json_encode( $k ) . ':' . $this->canonical_json( $v );
			}
			return '{' . implode( ',', $pairs ) . '}';
		}

		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $item ) {
				$items[] = $this->canonical_json( $item );
			}
			return '[' . implode( ',', $items ) . ']';
		}

		return wp_json_encode( $value );
	}

	/**
	 * Return true when an array is associative (has at least one string key).
	 *
	 * @param array $array Array to test.
	 * @return bool
	 */
	private function is_assoc( array $array ): bool {
		if ( empty( $array ) ) {
			return false;
		}
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
