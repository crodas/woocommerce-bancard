<?php

class WC_Gateway_Bancard extends WC_Payment_Gateway {

	public function __construct() {
		$this->id				 = 'bancard';
		$this->has_fields		 = false;
		$this->order_button_text  = __( 'Pagar con Bancard', 'woocommerce-bancard' );
		$this->method_title	   = __( 'Bancard', 'woocommerce-bancard' );
		$this->method_description = '';
		$this->supports		   = array( 'products' );

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

	protected function return_url( $order, $buy_id, $bancard_reply = 'success' ) {
		$order_id = (string) $order->get_id();
		$buy_id   = (string) $buy_id;
		$args = compact( 'order_id', 'buy_id', 'bancard_reply' );
		$args['token'] = wp_create_nonce( serialize( $args ) );

		$base_url = $order->get_checkout_order_received_url();

		return add_query_arg( $args, $base_url );
	}

	public function process_payment( $order_id ) {
		return WC_Bancard_Util::create( wc_get_order( $order_id ) );
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
				'type'	=> 'checkbox',
				'label'   => __( 'Activar Barncard', 'woocommerce' ),
				'default' => 'yes',
			),
			'stage' => array(
				'title'   => __( 'Activar/Desactivar', 'woocommerce' ),
				'type'	=> 'checkbox',
				'label'   => __( 'Activar modo de prueba', 'woocommerce' ),
				'default' => 'yes',
			),
			'public_key' => array(
				'title'	   => __( 'Cláve pública', 'woocommerce' ),
				'type'		=> 'text',
				'description' => __( 'Clave pública provista por Bancard', 'woocommerce' ),
				'desc_tip'	=> true,
			),
			'private_key' => array(
				'title'	   => __( 'Cláve privada', 'woocommerce' ),
				'type'		=> 'password',
				'description' => __( 'Clave privada provista por Bancard', 'woocommerce' ),
				'desc_tip'	=> true,
			),
		);
	}

}
