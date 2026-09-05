<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OLR_Cart_Preview {
	const SESSION_KEY = 'olr_cart_preview_event';
	public static function init() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'added' ), 99, 1 );
		add_action( 'wc_ajax_olr_cart_preview', array( __CLASS__, 'respond' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 31 );
	}
	public static function added( $key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { return; }
		// Record a successful WooCommerce addition, never a button click. No cart mutation.
		$previous = WC()->session->get( self::SESSION_KEY, array() );
		$keys = isset( $previous['time'], $previous['keys'] ) && time() - $previous['time'] < 10 ? $previous['keys'] : array();
		$keys[] = $key;
		WC()->session->set( self::SESSION_KEY, array( 'token' => wp_generate_uuid4(), 'time' => time(), 'keys' => array_unique( $keys ) ) );
	}
	public static function payload() {
		$empty = array( 'pending' => false );
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) { return $empty; }
		$event = WC()->session->get( self::SESSION_KEY, array() );
		$cart = WC()->cart;
		$items = $cart->get_cart();
		if ( empty( $event['token'] ) || empty( $event['keys'] ) || time() - (int) $event['time'] > 300 || ! array_intersect( $event['keys'], array_keys( $items ) ) ) { return $empty; }
		$html = '<ul class="olr-cart-preview__items">';
		foreach ( $items as $key => $item ) {
			$product = $item['data'];
			if ( ! $product || ! $product->exists() || $item['quantity'] <= 0 || ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $item, $key ) ) { continue; }
			$name = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $item, $key );
			// Checkout decorates this filter with its own thumbnail. Keep the filtered
			// product/box label, but not checkout-specific layout inside our name column.
			$name = wp_strip_all_tags( $name );
			$price = apply_filters( 'woocommerce_cart_item_subtotal', $cart->get_product_subtotal( $product, $item['quantity'] ), $item, $key );
			$quantity = 'Qty ' . (int) $item['quantity'];
			$image = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image( 'woocommerce_thumbnail' ), $item, $key );
			$html .= '<li class="olr-cart-preview__item"><div class="olr-cart-preview__media">' . wp_kses_post( $image ) . '</div><div class="olr-cart-preview__product"><strong class="olr-cart-preview__name">' . esc_html( $name ) . '</strong><span class="olr-cart-preview__quantity">' . $quantity . '</span><div class="olr-cart-preview__details">' . wp_kses_post( wc_get_formatted_cart_item_data( $item ) ) . '</div></div><div class="olr-cart-preview__price">' . wp_kses_post( $price ) . '</div></li>';
		}
		$html .= '</ul>';
		return array( 'pending' => true, 'token' => $event['token'], 'html' => $html, 'count' => $cart->get_cart_contents_count() );
	}
	public static function respond() {
		// Session-only, read-only data. No identifiers from the request and no shared caching.
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		wp_send_json_success( self::payload() );
	}
	public static function assets() {
		if ( ! class_exists( 'WC_AJAX' ) || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) ) { return; }
		wp_enqueue_script( 'olr-cart-preview', plugins_url( 'assets/cart-preview.js', __FILE__ ), array( 'jquery' ), OLR_Site_Controls::VERSION, true );
		// This store's custom checkout lives at /cart/; preserve the separate payment endpoints.
		$checkout = function_exists( 'olr_checkout_test_is_page' ) ? home_url( '/cart/' ) : wc_get_checkout_url();
		$config = array( 'endpoint' => WC_AJAX::get_endpoint( 'olr_cart_preview' ), 'checkout' => $checkout, 'shop' => home_url( '/catalog/' ), 'cart' => wc_get_cart_url() );
		wp_add_inline_script( 'olr-cart-preview', 'window.olrCartPreview=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
