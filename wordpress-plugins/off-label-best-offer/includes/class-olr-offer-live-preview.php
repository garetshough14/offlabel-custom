<?php
defined( 'ABSPATH' ) || exit;

/** Opt-in, expiring production preview for one authenticated administrator browser session. */
final class OLR_Offer_Live_Preview {
	const META = '_olr_private_pricing_preview';
	const MARKER = '_olr_private_pricing_preview_cart';
	const MESSAGE = 'Pricing preview only: order submission is blocked. End the preview and remove its test items before placing a real order.';

	public function __construct() {
		add_action( 'admin_post_olr_start_pricing_preview', array( $this, 'start' ) );
		add_action( 'admin_post_olr_stop_pricing_preview', array( $this, 'stop' ) );
		add_action( 'init', array( $this, 'no_cache' ), 1 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'mark_item' ), 100, 4 );
		add_filter( 'woocommerce_create_order', array( $this, 'block_order' ), -1000, 2 );
		add_action( 'woocommerce_checkout_process', array( $this, 'checkout_notice' ), -1000 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'gateways' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( $this, 'block_store_checkout' ), -1000, 3 );
		add_action( 'wp_ajax_olr_private_pricing_quotes', array( $this, 'quotes' ) );
		add_action( 'wp_ajax_nopriv_olr_private_pricing_quotes', array( $this, 'denied' ) );
	}

	public static function can_preview() {
		return is_user_logged_in() && current_user_can( 'manage_options' ) && current_user_can( 'manage_woocommerce' );
	}
	public static function active() {
		if ( 'production' !== wp_get_environment_type() || ! self::can_preview() ) { return false; }
		$token = wp_get_session_token();
		$grant = get_user_meta( get_current_user_id(), self::META, true );
		return $token && is_array( $grant ) && isset( $grant['token'], $grant['expires'] )
			&& is_string( $grant['token'] ) && $grant['expires'] > time()
			&& $grant['expires'] <= time() + 1800
			&& hash_equals( $grant['token'], hash( 'sha256', $token ) );
	}
	public static function marked_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return false; }
		foreach ( WC()->cart->cart_contents as $line ) { if ( ! empty( $line[ self::MARKER ] ) ) { return true; } }
		return false;
	}
	public static function protected_cart() { return self::active() || self::marked_cart(); }

	public function no_cache() {
		if ( ! self::active() ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
	}
	private function authorize( $action ) {
		if ( ! self::can_preview() ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
		if ( ! WC()->cart ) { wc_load_cart(); }
	}
	public function start() {
		$this->authorize( 'olr_start_pricing_preview' );
		if ( 'production' !== wp_get_environment_type() ) { wp_die( 'Use the staging setting on this environment.' ); }
		if ( ! WC()->cart->is_empty() ) { wp_die( 'Start with an empty cart. Your existing items have not been removed.' ); }
		$token = wp_get_session_token();
		if ( ! $token ) { wp_die( 'Sign in again before starting a preview.' ); }
		update_user_meta( get_current_user_id(), self::META, array( 'token' => hash( 'sha256', $token ), 'expires' => time() + 1800 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=olr-best-offer' ) );
		exit;
	}
	public function stop() {
		$this->authorize( 'olr_stop_pricing_preview' );
		delete_user_meta( get_current_user_id(), self::META );
		// Explicit button says it removes preview items; never empties an unrelated cart.
		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( ! empty( $line[ self::MARKER ] ) ) { WC()->cart->remove_cart_item( $key ); }
		}
		WC()->cart->set_applied_coupons( array_values( array_diff( WC()->cart->get_applied_coupons(), array( OLR_Best_Offer::AUTO ) ) ) );
		if ( WC()->session ) { WC()->session->set( 'olr_best_offer_errors', array() ); }
		wp_safe_redirect( admin_url( 'admin.php?page=olr-best-offer' ) );
		exit;
	}
	public function mark_item( $data, $product_id, $variation_id, $quantity ) {
		if ( self::active() ) { $data[ self::MARKER ] = true; }
		return $data;
	}
	public function block_order( $result, $checkout ) {
		return self::protected_cart() ? new WP_Error( 'olr_preview_no_orders', self::MESSAGE ) : $result;
	}
	public function checkout_notice() {
		if ( self::protected_cart() && ! wc_has_notice( self::MESSAGE, 'error' ) ) { wc_add_notice( self::MESSAGE, 'error' ); }
	}
	public function gateways( $gateways ) { return self::protected_cart() ? array() : $gateways; }
	public function block_store_checkout( $result, $server, $request ) {
		if ( 'GET' === $request->get_method() || 'HEAD' === $request->get_method() || ! self::protected_cart() ) { return $result; }
		if ( preg_match( '#^/wc/store/v[0-9]+/(checkout|order|batch)(/|$)#', $request->get_route() ) ) {
			return new WP_Error( 'olr_preview_no_orders', self::MESSAGE, array( 'status' => 403 ) );
		}
		return $result;
	}
	public function denied() { nocache_headers(); wp_send_json_error( array( 'message' => 'Private administrator preview only.' ), 403 ); }
	public function quotes() {
		nocache_headers();
		if ( ! self::active() ) { $this->denied(); return; }
		check_ajax_referer( 'olr_private_pricing_quotes', 'nonce' );
		if ( empty( $GLOBALS['olr_best_offer_preview_ready'] ) ) {
			wp_send_json_error( array( 'message' => 'Preview cannot run: a required plugin version or pricing compatibility check did not pass.' ), 409 );
		}
		$ids = array_slice( array_unique( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ), 0, 20 );
		$quotes = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$quotes[ $id ] = OLR_Best_Offer::quote_data( $product );
				if ( $product->is_type( 'variable' ) ) {
					foreach ( array_slice( $product->get_children(), 0, 100 ) as $child_id ) {
						$child = wc_get_product( $child_id );
						if ( $child ) { $quotes[ $child_id ] = OLR_Best_Offer::quote_data( $child ); }
					}
				}
			}
		}
		wp_send_json_success( array( 'quotes' => $quotes, 'message' => 'PRIVATE PRICING PREVIEW — your administrator session only. Orders and payments are disabled.', 'endUrl' => admin_url( 'admin.php?page=olr-best-offer' ) ) );
	}
}
