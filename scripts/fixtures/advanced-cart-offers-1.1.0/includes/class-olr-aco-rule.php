<?php
defined( 'ABSPATH' ) || exit;
final class OLR_ACO_Rule {
	const COUPON_TYPE = 'olr_advanced_offer';
	const META_PREFIX = '_olr_aco_';
	public static function defaults() {
		return array(
			'mode' => 'percent_items', 'target_scope' => 'any', 'target_product_ids' => array(), 'target_category_ids' => array(),
			'target_excluded_product_ids' => array(), 'target_excluded_category_ids' => array(), 'discount_qty' => 1, 'min_target_qty' => 1,
			'min_qualifying_spend' => 0, 'selection' => 'lowest', 'qualifying_scope' => 'products', 'qualifying_product_ids' => array(),
			'qualifying_category_ids' => array(), 'qualifying_excluded_product_ids' => array(), 'qualifying_excluded_category_ids' => array(),
			'buy_qty' => 1, 'get_qty' => 1, 'repeat' => 'no', 'max_sets' => 0, 'allow_reuse' => 'no', 'custom_error' => '',
		);
	}
	public static function from_coupon( $coupon ) {
		$rule = self::defaults();
		if ( ! $coupon instanceof WC_Coupon ) { return $rule; }
		foreach ( array_keys( $rule ) as $key ) {
			$value = $coupon->get_meta( self::META_PREFIX . $key, true );
			if ( '' !== $value && null !== $value ) { $rule[ $key ] = $value; }
		}
		foreach ( array( 'target_product_ids', 'target_category_ids', 'target_excluded_product_ids', 'target_excluded_category_ids', 'qualifying_product_ids', 'qualifying_category_ids', 'qualifying_excluded_product_ids', 'qualifying_excluded_category_ids' ) as $array_key ) {
			$rule[ $array_key ] = array_values( array_filter( array_map( 'absint', (array) $rule[ $array_key ] ) ) );
		}
		foreach ( array( 'discount_qty', 'min_target_qty', 'buy_qty', 'get_qty', 'max_sets' ) as $integer_key ) { $rule[ $integer_key ] = absint( $rule[ $integer_key ] ); }
		$rule['discount_qty'] = max( 1, $rule['discount_qty'] );
		$rule['min_target_qty'] = max( 1, $rule['min_target_qty'] );
		$rule['buy_qty'] = max( 1, $rule['buy_qty'] );
		$rule['get_qty'] = max( 1, $rule['get_qty'] );
		$rule['min_qualifying_spend'] = max( 0, (float) $rule['min_qualifying_spend'] );
		return $rule;
	}
	public static function is_advanced( $coupon ) { return $coupon instanceof WC_Coupon && self::COUPON_TYPE === $coupon->get_discount_type(); }
	public static function scope_is_configured( $scope, $product_ids, $category_ids ) {
		if ( 'any' === $scope ) { return true; }
		if ( 'products' === $scope ) { return ! empty( $product_ids ); }
		if ( 'categories' === $scope ) { return ! empty( $category_ids ); }
		return ! empty( $product_ids ) || ! empty( $category_ids );
	}
	public static function product_matches_scope( $product, $scope, $product_ids, $category_ids, $excluded_product_ids = array(), $excluded_category_ids = array() ) {
		if ( ! $product instanceof WC_Product ) { return false; }
		$product_id = (int) $product->get_id();
		$parent_id = (int) $product->get_parent_id();
		$id_match = in_array( $product_id, $product_ids, true ) || ( $parent_id && in_array( $parent_id, $product_ids, true ) );
		$id_excluded = in_array( $product_id, $excluded_product_ids, true ) || ( $parent_id && in_array( $parent_id, $excluded_product_ids, true ) );
		$category_match = false; $category_excluded = false;
		if ( ! empty( $category_ids ) || ! empty( $excluded_category_ids ) ) {
			$category_product_id = $parent_id ? $parent_id : $product_id;
			$product_categories = function_exists( 'wc_get_product_cat_ids' ) ? wc_get_product_cat_ids( $category_product_id ) : $product->get_category_ids();
			$category_match = (bool) array_intersect( array_map( 'absint', $product_categories ), $category_ids );
			$category_excluded = (bool) array_intersect( array_map( 'absint', $product_categories ), $excluded_category_ids );
		}
		if ( $id_excluded || $category_excluded ) { return false; }
		if ( 'any' === $scope ) { return true; }
		switch ( $scope ) {
			case 'products': return $id_match;
			case 'categories': return $category_match;
			case 'products_or_categories': return $id_match || $category_match;
			default: return false;
		}
	}
}
