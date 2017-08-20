<?php

class WC_Gateway_Bancard extends WC_Payment_Gateway {

	protected $url = array(
		'live' => 'https://vpos.infonet.com.py',
		'stage' => 'https://vpos.infonet.com.py:8888',
	);

	public function __construct() {
		$this->id                 = 'bancard';
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Pagar con Bancard', 'woocommerce-bancard' );
		$this->method_title       = __( 'Bancard', 'woocommerce-bancard' );
		$this->method_description = '';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = 'no';
		} else {
			$this->maybe_catch_reply();
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	protected function maybe_catch_reply() {
		$params = array( 'order_id', 'buy_id', 'bancard_reply', 'token' );
		$to_sign   = array();

		foreach ( $params as $name ) {
			if ( empty( $_GET[ $name ] ) ) {
				return false;
			}
			$to_sign[ $name ] = $_GET[ $name ];
		}

		unset( $to_sign['token'] );

		if ( $_GET['token'] !== wp_create_nonce( serialize( $to_sign ) ) ) {
			return false;
		}


		var_dump( $params );exit;
	}

	public function get_icon() {
		return 'Bancard';
	}

	protected function sign() {
		return md5( $this->get_option( 'private_key' ) . implode( '', func_get_args()) );
	}

	protected function sign_request( & $array ) {
		$array['operation']['token'] = $this->sign(
			$array['operation']['shop_process_id'],
			$array['operation']['amount'],
			$array['operation']['currency']
		);

	}

	protected function url($url) {
		return ( $this->get_option( 'stage' ) ? $this->url['stage'] : $this->url['live'] ) . $url;
	}

	protected function _do_request( $url, array $args ) {
		$json = json_encode( $args );

		$response = wp_remote_post( $this->url( $url ), array(
			'method'     => 'POST',
			'body'       => $json,
			'httpversion' => '1.1',
			'compress'   => false,
			'user-agent' => 'WP-Bancard API',
			'headers'    => array(
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

	protected function return_url( $order, $buy_id, $bancard_reply = 'success' ) {
		$order_id = (string) $order->get_id();
		$buy_id   = (string) $buy_id;
		$args = compact( 'order_id', 'buy_id', 'bancard_reply' );
		$args['token'] = wp_create_nonce( serialize( $args ) );

		$base_url = $order->get_checkout_order_received_url();

		return add_query_arg( $args, $base_url );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$buy_id = rand(1, 1000000);

		die($this->return_url( $order, $buy_id ));

		$request = array(
			'public_key' => $this->get_option( 'public_key'),
			'operation' => array(
				'return_url' => $this->return_url( $order, $buy_id ),
				'cancel_url' => $order->get_cancel_order_url_raw(),
				'shop_process_id' => (string)$buy_id,
				'additional_data' => '',
				'description' => 'WooCommerce Order #' . $order_id,
				'amount' => number_format( (float)$order->get_total(), 2, '.', '' ),
				'currency' => get_woocommerce_currency(),
			),
		);

		$this->sign_request( $request );

		$data = $this->_do_request('/vpos/api/0.3/single_buy', $request);
		return array(
			'result' => 'success',
			'redirect' => $this->url('/payment/single_buy?process_id=' . $data->process_id),
		);
	}

	/**
	 * Check if this gateway is enabled and available in the user's country.
	 * @return bool
	 */
	public function is_valid_for_use() {
		return in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_paypal_supported_currencies', array( 'USD', 'PYG' ) ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Activar/Desactivar', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar Barncard', 'woocommerce' ),
				'default' => 'yes',
			),
			'stage' => array(
				'title'   => __( 'Activar/Desactivar', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar modo de prueba', 'woocommerce' ),
				'default' => 'yes',
			),
			'public_key' => array(
				'title'       => __( 'Cláve pública', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Clave pública provista por Bancard', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'private_key' => array(
				'title'       => __( 'Cláve privada', 'woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Clave privada provista por Bancard', 'woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

}
