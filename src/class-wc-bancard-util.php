<?php

class WC_Bancard_Util {
	protected static $url = array(
		'live' => 'https://vpos.infonet.com.py',
		'stage' => 'https://vpos.infonet.com.py:8888',
	);

	public static function get( $key ) {
		$data = maybe_unserialize( get_option( 'woocommerce_bancard_settings' ) );
		return $data[ $key ];
	}

	public static function sign() {
		return md5( self::get( 'private_key' ) . implode( '', func_get_args()) );
	}

	protected static function url($url) {
		return ( self::get( 'stage' ) ? self::$url['stage'] : self::$url['live'] ) . $url;
	}

	protected static function return_url( $order, $buy_id ) {
		$order_id = (string) $order->get_id();
		$buy_id   = (string) $buy_id;
		$args = compact( 'order_id', 'buy_id' );
		$args['token'] = wp_create_nonce( serialize( $args ) );

		$base_url = $order->get_checkout_order_received_url();

		return add_query_arg( $args, $base_url );
	}

	protected static function do_request( $url, array $args ) {
		$json = json_encode( $args );

		$response = wp_remote_post( self::url( $url ), array(
			'method'  => 'POST',
			'body'	  => $json,
			'httpversion' => '1.1',
			'compress'    => false,
			'user-agent'  => 'WP-Bancard API',
			'headers'     => array(
				'Referer' => '',
				'Content-Type' =>'application/json',
				'Content-Length' => strlen($json),
			),
		) );

		$object = json_decode( $response['body'] );
		if ( is_object( $object ) && 'success' === $object->status ) {
			return $object;
		}

		throw new RuntimeException( 'Invalid response from Bancard API: ' . $response['body'] );
	}

	public static function create(WC_Order $order) {

		$request = array(
			'public_key' => self::get( 'public_key'),
			'operation' => array(
				'return_url' => self::return_url( $order, $buy_id ),
				'cancel_url' => $order->get_cancel_order_url_raw(),
				'shop_process_id' => (string)$order->get_id(),
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

		$response = self::do_request( '/vpos/api/0.3/single_buy', $request );

		return array(
			'result' => 'success',
			'redirect' => self::url( '/payment/single_buy?process_id=' . $response->process_id ),
		);
	}

	public static function init() {
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
	}
}
