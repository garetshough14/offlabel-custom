<?php
defined( 'ABSPATH' ) || exit;
final class OLR_ACO_Rule_Engine {
	private $validation_errors = array();
	public function register_hooks() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'add_discount_type' ) );
		add_filter( 'woocommerce_product_coupon_types', array( $this, 'add_product_coupon_type' ) );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'coupon_error_message' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'select_discount_items' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'calculate_unit_discount' ), 10, 5 );
	}
	public function add_discount_type( $types ) { $types[ OLR_ACO_Rule::COUPON_TYPE ] = __( 'Advanced cart offer', 'advanced-cart-offers-for-woocommerce' ); return $types; }
	public function add_product_coupon_type( $types ) { $types[] = OLR_ACO_Rule::COUPON_TYPE; return array_values( array_unique( $types ) ); }
	public function validate_coupon( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! OLR_ACO_Rule::is_advanced( $coupon ) ) { return $valid; }
		$rule = OLR_ACO_Rule::from_coupon( $coupon );
		$items = is_object( $discounts ) && is_callable( array( $discounts, 'get_items' ) ) ? $discounts->get_items() : array();
		$eligible_target_items = array_filter( $items, static function ( $item ) use ( $coupon ) {
			if ( ! isset( $item->product, $item->object, $item->price ) || (float) $item->price <= 0 ) { return false; }
			return $coupon->is_valid_for_product( $item->product, $item->object ) || $coupon->is_valid_for_cart();
		} );
		$plan = $this->build_plan( $rule, $items, $eligible_target_items );
		if ( ! $plan['valid'] ) {
			$message = $rule['custom_error'] ? $rule['custom_error'] : $plan['message'];
			$this->validation_errors[ wc_format_coupon_code( $coupon->get_code() ) ] = $message;
			return false;
		}
		unset( $this->validation_errors[ wc_format_coupon_code( $coupon->get_code() ) ] );
		return true;
	}
	public function coupon_error_message( $message, $error_code, $coupon ) {
		if ( ! $coupon instanceof WC_Coupon ) { return $message; }
		if ( defined( 'WC_Coupon::E_WC_COUPON_INVALID_FILTERED' ) && WC_Coupon::E_WC_COUPON_INVALID_FILTERED !== (int) $error_code ) { return $message; }
		$code = wc_format_coupon_code( $coupon->get_code() );
		return isset( $this->validation_errors[ $code ] ) ? $this->validation_errors[ $code ] : $message;
	}
	public function select_discount_items( $items, $coupon, $discounts ) {
		if ( ! OLR_ACO_Rule::is_advanced( $coupon ) ) { return $items; }
		$rule = OLR_ACO_Rule::from_coupon( $coupon );
		$all = is_object( $discounts ) && is_callable( array( $discounts, 'get_items' ) ) ? $discounts->get_items() : $items;
		$plan = $this->build_plan( $rule, $all, $items );
		if ( ! $plan['valid'] || $plan['discount_qty'] < 1 ) { return array(); }
		$target_items = array_filter( $items, static function ( $item ) use ( $rule ) {
			return isset( $item->product ) && OLR_ACO_Rule::product_matches_scope( $item->product, $rule['target_scope'], $rule['target_product_ids'], $rule['target_category_ids'], $rule['target_excluded_product_ids'], $rule['target_excluded_category_ids'] );
		} );
		$direction = 'highest' === $rule['selection'] ? -1 : 1;
		uasort( $target_items, static function ( $first, $second ) use ( $direction ) {
			$first_price = $first->quantity > 0 ? (float) $first->price / $first->quantity : 0;
			$second_price = $second->quantity > 0 ? (float) $second->price / $second->quantity : 0;
			if ( $first_price === $second_price ) { return strcmp( (string) $first->key, (string) $second->key ); }
			return $first_price < $second_price ? -1 * $direction : $direction;
		} );
		$coupon->set_limit_usage_to_x_items( $plan['discount_qty'] );
		return array_values( $target_items );
	}
	public function calculate_unit_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! OLR_ACO_Rule::is_advanced( $coupon ) ) { return $discount; }
		$percentage = min( 100, max( 0, (float) $coupon->get_amount() ) );
		return min( (float) $discounting_amount, (float) $discounting_amount * ( $percentage / 100 ) );
	}
	private function build_plan( $rule, $items, $eligible_target_items = null ) {
		if ( ! OLR_ACO_Rule::scope_is_configured( $rule['target_scope'], $rule['target_product_ids'], $rule['target_category_ids'] ) ) {
			return $this->invalid_plan( __( 'This offer is not fully configured. Please contact the store owner.', 'advanced-cart-offers-for-woocommerce' ) );
		}
		$target_items = is_array( $eligible_target_items ) ? $eligible_target_items : $items;
		$target = $this->scope_totals( $target_items, $rule['target_scope'], $rule['target_product_ids'], $rule['target_category_ids'], $rule['target_excluded_product_ids'], $rule['target_excluded_category_ids'] );
		if ( 'bogo' !== $rule['mode'] ) {
			$required_quantity = max( $rule['discount_qty'], $rule['min_target_qty'] );
			if ( $target['quantity'] < $required_quantity ) {
				return $this->invalid_plan( sprintf( _n( 'Add at least %d eligible item to use this offer.', 'Add at least %d eligible items to use this offer.', $required_quantity, 'advanced-cart-offers-for-woocommerce' ), $required_quantity ) );
			}
			if ( $target['subtotal'] < $rule['min_qualifying_spend'] ) { return $this->invalid_plan( $this->minimum_spend_message( $rule['min_qualifying_spend'] ) ); }
			return array( 'valid' => true, 'discount_qty' => $rule['discount_qty'], 'message' => '', 'sets' => 1 );
		}
		if ( ! OLR_ACO_Rule::scope_is_configured( $rule['qualifying_scope'], $rule['qualifying_product_ids'], $rule['qualifying_category_ids'] ) ) {
			return $this->invalid_plan( __( 'This offer is not fully configured. Please contact the store owner.', 'advanced-cart-offers-for-woocommerce' ) );
		}
		$qualifying = $this->scope_totals( $items, $rule['qualifying_scope'], $rule['qualifying_product_ids'], $rule['qualifying_category_ids'], $rule['qualifying_excluded_product_ids'], $rule['qualifying_excluded_category_ids'] );
		if ( $qualifying['subtotal'] < $rule['min_qualifying_spend'] ) { return $this->invalid_plan( $this->minimum_spend_message( $rule['min_qualifying_spend'] ) ); }
		$sets = 'yes' === $rule['repeat'] ? intdiv( $qualifying['quantity'], $rule['buy_qty'] ) : ( $qualifying['quantity'] >= $rule['buy_qty'] ? 1 : 0 );
		if ( $rule['max_sets'] > 0 ) { $sets = min( $sets, $rule['max_sets'] ); }
		if ( $sets < 1 ) {
			return $this->invalid_plan( sprintf( _n( 'Buy at least %d qualifying item to use this offer.', 'Buy at least %d qualifying items to use this offer.', $rule['buy_qty'], 'advanced-cart-offers-for-woocommerce' ), $rule['buy_qty'] ) );
		}
		$overlap_quantity = $this->overlap_quantity( $target_items, $rule );
		$qualifier_only = max( 0, $qualifying['quantity'] - $overlap_quantity );
		while ( $sets > 0 ) {
			$reserved_overlap = 'yes' === $rule['allow_reuse'] ? 0 : max( 0, ( $rule['buy_qty'] * $sets ) - $qualifier_only );
			$available_target = max( 0, $target['quantity'] - $reserved_overlap );
			if ( $available_target >= $rule['get_qty'] * $sets ) { break; }
			--$sets;
		}
		if ( $sets < 1 ) {
			return $this->invalid_plan( sprintf( _n( 'Add at least %d eligible discounted item to use this offer.', 'Add at least %d eligible discounted items to use this offer.', $rule['get_qty'], 'advanced-cart-offers-for-woocommerce' ), $rule['get_qty'] ) );
		}
		return array( 'valid' => true, 'discount_qty' => $rule['get_qty'] * $sets, 'message' => '', 'sets' => $sets );
	}
	private function scope_totals( $items, $scope, $product_ids, $category_ids, $excluded_product_ids = array(), $excluded_category_ids = array() ) {
		$quantity = 0; $subtotal = 0.0;
		foreach ( $items as $item ) {
			if ( ! isset( $item->product, $item->quantity, $item->price ) || ! OLR_ACO_Rule::product_matches_scope( $item->product, $scope, $product_ids, $category_ids, $excluded_product_ids, $excluded_category_ids ) ) { continue; }
			$quantity += max( 0, (int) $item->quantity );
			$subtotal += function_exists( 'wc_remove_number_precision' ) ? wc_remove_number_precision( (float) $item->price ) : (float) $item->price;
		}
		return array( 'quantity' => $quantity, 'subtotal' => $subtotal );
	}
	private function overlap_quantity( $items, $rule ) {
		$quantity = 0;
		foreach ( $items as $item ) {
			if ( ! isset( $item->product, $item->quantity ) ) { continue; }
			$is_target = OLR_ACO_Rule::product_matches_scope( $item->product, $rule['target_scope'], $rule['target_product_ids'], $rule['target_category_ids'], $rule['target_excluded_product_ids'], $rule['target_excluded_category_ids'] );
			$is_trigger = OLR_ACO_Rule::product_matches_scope( $item->product, $rule['qualifying_scope'], $rule['qualifying_product_ids'], $rule['qualifying_category_ids'], $rule['qualifying_excluded_product_ids'], $rule['qualifying_excluded_category_ids'] );
			if ( $is_target && $is_trigger ) { $quantity += max( 0, (int) $item->quantity ); }
		}
		return $quantity;
	}
	private function minimum_spend_message( $amount ) {
		$formatted = function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : (string) $amount;
		return sprintf( __( 'Spend at least %s on qualifying items to use this offer.', 'advanced-cart-offers-for-woocommerce' ), $formatted );
	}
	private function invalid_plan( $message ) { return array( 'valid' => false, 'discount_qty' => 0, 'message' => $message, 'sets' => 0 ); }
}
