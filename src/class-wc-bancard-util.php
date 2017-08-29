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

	public static function maybe_create_tables() {
		global $wpdb;

		if ( ! is_callable( 'dbDelta' ) ) {
			require( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}

		dbDelta( 'CREATE TABLE `' . $wpdb->prefix . 'bancard` (
			`id` bigint(20) NOT NULL AUTO_INCREMENT,
			`order_id` bigint(20) NOT NULL,
			`authorization_number` bigint(20) NOT NULL DEFAULT "0",
			`response_description` text,
			`ticket_number` bigint(21) NOT NULL DEFAULT "0",
			`confirmed` smallint default "0",
			PRIMARY KEY (`id`)
		)' );
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
			'method'	 => 'POST',
			'body'	   => $json,
			'httpversion' => '1.1',
			'compress'   => false,
			'user-agent' => 'WP-Bancard API',
			'headers'	=> array(
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
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'bancard', array(
			'order_id' => $order->get_id(),
		) );

		$buy_id = $wpdb->insert_id;

		$request = array(
			'public_key' => self::get( 'public_key'),
			'operation' => array(
				'return_url' => self::return_url( $order, $buy_id ),
				'cancel_url' => $order->get_cancel_order_url_raw(),
				'shop_process_id' => (string)$buy_id,
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

		$response = self::do_request( '/vpos/api/0.3/single_buy', $request );

		return array(
			'result' => 'success',
			'redirect' => self::url( '/payment/single_buy?process_id=' . $response->process_id ),
		);
	}

	public static function init() {
		global $wpdb;

		self::maybe_create_tables();

		if ( empty( $_GET['bancard'] ) ) {
			return;
		}

		header( 'Content-Type: application/json' );

		try {
			$body = json_decode( file_get_contents( 'php://input' ), true );
			if ( ! is_array( $body ) ) {
				throw new Exception( 'Invalid request body' );
			}

			$expected_signature = self::sign(
				$body['operation']['shop_process_id'], 
				'confirm',
				$body['operation']['amount'],
				$body['operation']['currency']
			);

			if ( $body['operation']['token'] !== $expected_signature ) {
				throw new Error( 'Invalid signature' );
			}

		} catch ( Exception $e ) {
			header( 'HTTP/1.0 400 Bad Request' );
			echo json_encode( array(
				'status' => 'error',
				'message' => $e->getMessage(),
			) );
			exit;
		}

		file_put_contents( '/tmp/debug.txt', print_r( $body , true ) );

		$wpdb->update( $wpdb->prefix . 'bancard', array(
			'authorization_number' => $body['operation']['authorization_number'],
			'ticket_number' => $body['operation']['ticket_number'],
			'confirmed' => 1,
		), array( 'id' => $body['operation']['shop_process_id'] ) );
	}
}
