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
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function get_icon() {
		return 'Bancard';
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
				'title'   => __( 'Activar/Desactivar', 'woocommerce-bancard' ),
				'type'	=> 'checkbox',
				'label'   => __( 'Activar Barncard', 'woocommerce-bancard' ),
				'default' => 'yes',
			),
			'stage' => array(
				'title'   => __( 'Activar/Desactivar', 'woocommerce-bancard' ),
				'type'	=> 'checkbox',
				'label'   => __( 'Activar modo de prueba', 'woocommerce-bancard' ),
				'default' => 'yes',
			),
			'public_key' => array(
				'title'	   => __( 'Cláve pública', 'woocommerce-bancard' ),
				'type'		=> 'text',
				'description' => __( 'Clave pública provista por Bancard', 'woocommerce-bancard' ),
				'desc_tip'	=> true,
			),
			'private_key' => array(
				'title'	   => __( 'Cláve privada', 'woocommerce-bancard' ),
				'type'		=> 'password',
				'description' => __( 'Clave privada provista por Bancard', 'woocommerce-bancard' ),
				'desc_tip'	=> true,
			),
			'url' => array(
				'title' => __( 'URL de Confirmación del pago', 'woocommerce-bancard' ),
				'type'  => 'text',
				'default' => add_query_arg( 'bancard', wp_create_nonce( 'bancard' ),  home_url( '/' ) ),
			),
		);
	}

}
