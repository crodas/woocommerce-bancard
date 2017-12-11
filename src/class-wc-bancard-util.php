<?php

/**
 * Bancard Util
 *
 * This class is a set of util static methods used for the Bancard API.
 *
 * If you can choose any modern Gateway I urge you to do that, this API has security flaws
 * explained in the code.
 *
 * I wrote this plugin so if you don't have a choise at least you can use a secure implementation.
 *
 */
class WC_Bancard_Util {
	/**
	 * Bancard's API urls.
	 */
	protected static $url = array(
		'live' => 'https://vpos.infonet.com.py',
		'stage' => 'https://vpos.infonet.com.py:8888',
	);

	/**
	 * Reads a configuration out of WooCommerce settings.
	 *
	 * This function will read the WooCommerce bancard settings directly, it is done this way so
	 * we can read even properties even before the gateways are loaded.
	 *
	 * If a given property is not found NULL will be returned instead.
	 *
	 * @param string $key Property name
	 *
	 * @return mixed
	 */
	public static function get( $key ) {
		$data = maybe_unserialize( get_option( 'woocommerce_bancard_settings' ) );

		if ( ! array_key_exists( $key, $data ) ) {
			return null;
		}

		return $data[ $key ];
	}

	/**
	 * "Signs" data
	 *
	 * Signs all the function parameters in the "Bancard way".
	 *
	 * Couple of security notes here:
	 *
	 *   1. They use a pretty weak algorithm, md5.
	 *   2. They choose to sign just a few fields per request. Not all the data.
	 *	  That is just silly.
	 *
	 * Although I would design things differently, this sign must be compatible with Bancard
	 * weak design.
	 *
	 * @return string
	 */
	public static function sign() {
		return md5( self::get( 'private_key' ) . implode( '', func_get_args()) );
	}

	/**
	 * Returns bancard's API url
	 *
	 * It will return the full API url, either stage or production
	 *
	 * @param $path URL path
	 *
	 * @return string Full URL
	 */
	protected static function url($path) {
		return ( self::get( 'stage' ) ? self::$url['stage'] : self::$url['live'] ) . $path;
	}

	/**
	 * Performs an API call to Bancard
	 *
	 * The API is a POST call, the request body is serialized as JSON.
	 *
	 * This is a function does not sign the API requests automatically, it may do so in the future,
	 * but right now it is the caller responsability to sign their requests and make sure the
	 * reply is legic  (by checking the response signatures).
	 *
	 * @param string $url
	 * @param array  $args
	 *
	 * @return mixed
	 */
	protected static function api_exec( $url, array $args ) {
		$json = json_encode( $args );
		$url  = self::url( $url );

		$response = wp_remote_post( $url, array(
			'method'  => 'POST',
			'body'	  => $json,
			'httpversion' => '1.1',
			'compress'	=> false,
			'user-agent'  => 'WP-Bancard API',
			'headers'	 => array(
				'Referer' => '',
				'Content-Type' =>'application/json',
				'Content-Length' => strlen($json),
			),
		) );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$object = json_decode( $response['body'] );
		if ( is_object( $object ) && 'success' === $object->status ) {
			return $object;
		}

		$error = new RuntimeException( 'Invalid response from Bancard' );
		if ( 'error' === $object->status && ! empty( $object->messages ) ) {
			$error->response = $object->messages[0]->key;
		}

		throw $error;
	}

	/**
	 * Creates a Bancard Payments
	 *
	 * This function takes a WC_Order and makes a Bancard Payment request. All the details are handled by this
	 * method. Right now only single_buy is supported.
	 *
	 * This function will return an URL which is for your customer.
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public static function create(WC_Order $order) {
		$shop_process_id = (string)$order->get_id();

		$request = array(
			'public_key' => self::get( 'public_key'),
			'operation' => array(
				'return_url' => $order->get_checkout_order_received_url(),
				'cancel_url' => $order->get_cancel_order_url_raw(),
				'shop_process_id' => $shop_process_id,
				'additional_data' => '',
				'description' => get_bloginfo( 'name') . ' #' . $order->get_id(),
				'amount' => number_format( (float)$order->get_total(), 2, '.', '' ),
				'currency' => get_woocommerce_currency(),
			),
		);

		$request['operation']['token'] = self::sign(
			$request['operation']['shop_process_id'],
			$request['operation']['amount'],
			$request['operation']['currency']
		);

		$order->update_status( 'on-hold', __( 'Awaiting payment confirmation', 'woocommerce-bancard' ) );

		$response = self::api_exec( '/vpos/api/0.3/single_buy', $request );


		if ( empty( $response->process_id ) ) {
			throw new RuntimeException( 'Invalid response from Bancard. Check the logs for a better' );
		}

		wp_schedule_single_event( strtotime( '+10 minutes' ), 'bancard_cancel_transaction', array( $shop_process_id ) );

		return array(
			'result' => 'success',
			'redirect' => self::url( '/payment/single_buy?process_id=' . $response->process_id ),
		);
	}

	/**
	 * Handles the Payment notification
	 *
	 * This method handles a payment notification send by Bancard, if the current request is a payment notification.
	 *
	 * Be sure to setup the notification URL properly in Bancard's dashboard.
	 *
	 * If this is a payment notification it will exit inmmediable and return data the way bancard expects it.
	 *
	 * It is a good idea to let the URL generated automatically.
	 */
	public static function maybe_handle_payment_notification() {
		parse_str( parse_url( self::get( 'url' ), PHP_URL_QUERY ), $args );

		if ( empty( $args ) ) {
			return false;
		}

		foreach ( $args as $key => $value ) {
			if ( empty( $_GET[ $key ] ) || $value !== $_GET[ $key ] ) {
				return false;
			}
		}

		header( 'Content-Type: application/json' );

		$logger = wc_get_logger();

		try {
			$json = file_get_contents( 'php://input' );
			$body = json_decode( $json, true );
			$logger->debug( print_r( $body, true ), array( 'source' => 'bancard-request' ) );

			if ( ! is_array( $body ) || empty( $body['operation'] ) ) {
				throw new Exception( 'Invalid request body' );
			}

			$operation = (array) $body['operation'];

			$expected_signature = self::sign(
				$operation['shop_process_id'],
				'confirm',
				$operation['amount'],
				$operation['currency']
			);

			if ( $operation['token'] !== $expected_signature ) {
				throw new Error( 'Invalid signature' );
			}

			$order = wc_get_order( (int)$operation['shop_process_id'] );
			if ( empty( $order ) ) {
				throw new RuntimeException( 'Invalid shop_process_id (' . $operation['shop_process_id'] . ')' );
			}

		} catch ( Exception $e ) {
			$logger->error( $e->getMessage(), array( 'source' => 'bancard-error' ) );
			header( 'HTTP/1.0 400 Bad Request' );
			echo json_encode( array(
				'status' => 'error',
				'message' => $e->getMessage(),
			) );
			exit;
		}

		if ( ! empty( $operation['authorization_number'] ) && is_numeric( $operation['authorization_number'] ) ) {
			$order->add_order_note( sprintf(
				__( 'Bancard: %s. Autorización %d', 'woocommerce-bancard' ),
				$operation['response_description'],
				$operation['authorization_number']
			) );
			foreach ( $operation as $operation => $value ) {
				update_post_meta( $order->get_id(), '_bancard_' . $operation, $value );
			}
			$order->payment_complete();
		} else {
			$order->add_order_note( sprintf(
				__( 'Bancard: %s', 'woocommerce-bancard' ),
				$operation['response_description']
			) );

			$order->update_status( 'failed' );
		}

		echo json_encode( array( 'status' => 'success' ) ) ;
		exit;
	}

	public static function cancel_transaction( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			$response = self::api_exec( '/vpos/api/0.3/single_buy/rollback', array(
				'public_key' => self::get( 'public_key' ),
				'operation' => array(
					'shop_process_id' => $order_id,
					'token' => self::sign(
						$order_id,
						'rollback',
						'0.00'
					)
				),
			) );
		} catch ( RuntimeException $exception ) {
			if ( empty( $exception->response ) ) {
				return;
			}
			switch ( $exception->response ) {
			case 'PaymentNotFoundError':
				$order->add_order_note( sprintf(
					__( 'Bancard: %s', 'woocommerce-bancard' ),
					'Payment not found after 10 minutes'
				) );
				$order->update_status( 'failed' );
				break;
			case 'TransactionAlreadyConfirmed':
				$order->payment_complete();
				break;
			}
		}
	}

	public static function check_confirmation( $order_id ) {
		$order = wc_get_order( $order_id );

		$response = self::api_exec( '/vpos/api/0.3/single_buy/confirmations', array(
			'public_key' => self::get( 'public_key' ),
			'operation' => array(
				'shop_process_id' => $order_id,
				'token' => self::sign(
					$order_id,
					'get_confirmation'
				)
			),
		) );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$response = (array)$response->confirmation;

		if ( ! empty( $response['authorization_number'] ) && is_numeric( $response['authorization_number'] ) ) {
			$order->add_order_note( sprintf(
				__( 'Bancard: %s. Autorización %d', 'woocommerce-bancard' ),
				$response['response_description'],
				$response['authorization_number']
			) );
			foreach ( $response as $operation => $value ) {
				update_post_meta( $order->get_id(), '_bancard_' . $operation, $value );
			}
			$order->payment_complete();
		} else {
			$order->add_order_note( sprintf(
				__( 'Bancard: %s', 'woocommerce-bancard' ),
				$response['response_description']
			) );

			$order->update_status( 'failed' );
		}
	}


	public static function init() {
		add_action( 'bancard_check_confirmation', __CLASS__ . '::check_confirmation', 10, 1 );
		add_action( 'bancard_cancel_transaction', __CLASS__ . '::cancel_transaction', 10, 1 );
	}
}

add_filter( 'init', 'WC_Bancard_Util::init' );
add_filter( 'init', 'WC_Bancard_Util::maybe_handle_payment_notification', 9999 );
