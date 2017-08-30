<?php
/*
Plugin Name: Bancard Payment Gateway
Plugin URI: https://github.com/crodas/woocommerce-bancard
Description: WooCommerce custom payment gateway integration on Bancard
Version: 1.0
*/

include_once( dirname( __FILE__ ) . '/src/class-wc-bancard-util.php' );

function woocommerce_bancard_register( $methods ) {
	include_once( dirname( __FILE__ ) . '/src/class-wc-gateway-bancard.php' );
	$methods[] = 'WC_Gateway_Bancard';

	return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'woocommerce_bancard_register' );
add_filter( 'init', 'WC_Bancard_Util::init', 9999 );
