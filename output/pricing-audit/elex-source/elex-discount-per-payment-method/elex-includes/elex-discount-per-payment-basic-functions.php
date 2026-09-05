<?php
// to check whether accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

//check class dependencies exist or not
if ( ! class_exists( 'ELEX_DPP_BASIC_Dependencies' ) ) {
	require_once  'elex-discount-per-payment-basic-dependencies.php' ;
}

//check woocommerce is active function exist
if ( ! function_exists( 'elex_dpp_basic_is_woocommerce_active' ) ) {

	function elex_dpp_basic_is_woocommerce_active() {
		return ELEX_DPP_BASIC_Dependencies::woocommerce_active_check();
	}
}
