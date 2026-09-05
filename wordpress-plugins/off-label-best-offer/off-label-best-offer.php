<?php
/**
 * Plugin Name: Off Label Best Offer
 * Description: Non-stacking product/group pricing with an explicit live enable switch and a separate administrator-only, non-purchasable preview. Disabled by default.
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Off Label Research
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes/class-olr-offer-planner.php';
require_once __DIR__ . '/includes/class-olr-offer-admin.php';
require_once __DIR__ . '/includes/class-olr-offer-live-preview.php';

final class OLR_Best_Offer {
	const VERSION = '0.3.0';
	const AUTO = 'olr-automatic-best-offer';
	const RUNTIME = 'olr_allocated_offer';
	private $calculating = false;
	private $quoting = false;
	private $plan = null;
	private $cart;
	private $base = array();
	private $errors = array();
	private $enabled = false;
	private $replays;
	private $private_preview = false;

	public function __construct() {
		$this->replays = new SplObjectStorage();
		new OLR_Offer_Admin();
		new OLR_Offer_Live_Preview();
		// Resolve the mode before WooCommerce restores/recalculates cart sessions at wp_loaded:10.
		add_action( 'wp_loaded', array( $this, 'boot' ), 1 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'prepare' ), PHP_INT_MAX );
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'virtual_coupon' ), 10, 2 );
		// Persisted orders must keep their allocation adapter even when NEW cart
		// pricing is switched off. Leave this plugin installed after taking orders.
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'allocate_coupon' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'discount_map' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'replay_order_coupon' ), 100, 4 );
		add_filter( 'woocommerce_coupon_discount_types', static function ( $types ) { $types[ self::RUNTIME ] = 'Internal automatic allocation (not for manual coupons)'; return $types; } );
		add_action( 'before_woocommerce_init', static function () {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		} );
	}

	public function boot() {
		// Production requires its own explicit administrator opt-in. A staging
		// option or an old private-preview grant must never enable public pricing.
		$this->private_preview = OLR_Offer_Live_Preview::active();
		$staging = 'yes' === get_option( 'olr_best_offer_enabled', 'no' ) && in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true );
		$live = self::live_requested();
		$this->enabled = ( $staging || $live || $this->private_preview ) && ! self::compatibility_errors();
		if ( $live && ! $this->enabled ) {
			$this->errors = array( 'Off Label pricing is paused because a required compatibility check failed. Please contact the store.' );
			add_action( 'woocommerce_checkout_process', array( $this, 'check_cart' ), 100 );
			add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ), 100 );
			add_action( 'woocommerce_checkout_create_order', array( $this, 'assert_safe_order' ), 100 );
			add_filter( 'rest_pre_dispatch', array( $this, 'guard_store_checkout' ), -999, 3 );
		}
		if ( ! $this->enabled ) { return; }
		if ( class_exists( 'OLR_Build_A_Box' ) ) {
			$box = OLR_Build_A_Box::instance();
			remove_action( 'woocommerce_before_calculate_totals', array( $box, 'apply_box_prices' ), 9999 );
			foreach ( array( 'woocommerce_coupon_is_valid_for_product' => 'coupon_valid_for_product', 'woocommerce_coupon_get_discount_amount' => 'coupon_discount_amount', 'woocommerce_coupon_get_items_to_validate' => 'coupon_items_to_validate', 'woocommerce_coupon_get_items_to_apply' => 'coupon_items_to_apply' ) as $hook => $method ) {
				remove_filter( $hook, array( $box, $method ), 999 );
			}
			remove_action( 'woocommerce_before_cart', array( $box, 'coupon_policy_notice' ), 5 );
			remove_action( 'woocommerce_before_checkout_form', array( $box, 'coupon_policy_notice' ), 20 );
		}
		// Remove only the audited ELEX callbacks, never other fees, gateway logic or admin settings.
		global $wp_filter;
		foreach ( array( 'woocommerce_before_calculate_totals' => 'Elex_Woo_Discount_Per_Payment_Method_Add_Discount', 'woocommerce_cart_calculate_fees' => 'Elex_Woo_Discount_Per_Payment_Method_Add_html' ) as $hook => $method ) {
			foreach ( ( $wp_filter[ $hook ]->callbacks ?? array() ) as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && is_object( $fn[0] ) && 'Elex_Woo_Discount_Per_Payment_Method' === get_class( $fn[0] ) && $method === $fn[1] ) { remove_action( $hook, $fn, $priority ); }
				}
			}
		}
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_box_exclusions' ), 1000, 3 );
		add_filter( 'woocommerce_product_coupon_types', static function ( $types ) { $types[] = self::RUNTIME; return $types; } );
		add_filter( 'woocommerce_apply_individual_use_coupon', static function ( $keep, $coupon, $applied ) {
			if ( in_array( self::AUTO, $applied, true ) ) { $keep[] = self::AUTO; }
			return array_unique( $keep );
		}, 20, 3 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'finish' ), PHP_INT_MAX );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ), 100 );
		add_action( 'woocommerce_checkout_process', array( $this, 'check_cart' ), 100 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 100, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'line_subtotal' ), 100, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_meta' ), 100, 4 );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'order_coupon_meta' ), 100, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'assert_safe_order' ), 100 );
		// Only the classic checkout has the persisted allocation integration.
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_store_checkout' ), -999, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_html' ), 10, 3 );
		// Private quotes are fetched after an authenticated, no-cache request, never inserted into shared HTML.
		if ( ! $this->private_preview ) { add_filter( 'do_shortcode_tag', array( $this, 'selector_data' ), 100, 4 ); }
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 100, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 100 );
		if ( $this->private_preview ) { $GLOBALS['olr_best_offer_preview_ready'] = true; }
		if ( $live ) { $GLOBALS['olr_best_offer_live_ready'] = true; }
	}

	public static function live_requested() {
		return 'production' === wp_get_environment_type() && 'yes' === get_option( 'olr_best_offer_live_enabled', 'no' );
	}

	public static function compatibility_errors() {
		$errors = array();
		if ( ! defined( 'WC_VERSION' ) || '11.1.0' !== WC_VERSION ) { $errors[] = 'WooCommerce 11.1.0 is required.'; }
		if ( defined( 'OLR_ACO_VERSION' ) && '1.1.0' !== OLR_ACO_VERSION ) { $errors[] = 'Advanced Cart Offers 1.1.0 is required.'; }
		if ( class_exists( 'OLR_Build_A_Box' ) && '1.3.3' !== OLR_Build_A_Box::VERSION ) { $errors[] = 'Build Your Box 1.3.3 is required.'; }
		if ( class_exists( 'Elex_Woo_Discount_Per_Payment_Method' ) ) {
			$reflection = new ReflectionClass( 'Elex_Woo_Discount_Per_Payment_Method' );
			$header = get_file_data( $reflection->getFileName(), array( 'Version' => 'Version' ) );
			if ( '1.3.2' !== $header['Version'] ) { $errors[] = 'ELEX 1.3.2 is required.'; }
		}
		if ( wc_get_coupon_id_by_code( self::AUTO ) ) { $errors[] = 'Remove the reserved automatic coupon code conflict before enabling pricing.'; }
		return $errors;
	}

	public function guard_store_checkout( $result, $server, $request ) {
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) { return $result; }
		if ( preg_match( '#^/wc/store/v[0-9]+/(checkout|order|batch)(/|$)#', $request->get_route() ) ) {
			return new WP_Error( 'olr_classic_checkout_required', 'Please use the store checkout page. This pricing release does not support Blocks/Store API order submission.', array( 'status' => 409 ) );
		}
		return $result;
	}

	public static function settings( $product ) {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$tiers = array();
		foreach ( array( array( 3, 10 ), array( 5, 15 ), array( 10, 20 ) ) as $index => $default ) {
			$n = $index + 1;
			$q = $parent->get_meta( '_olr_volume_q' . $n );
			$p = $parent->get_meta( '_olr_volume_p' . $n );
			$tiers[] = array( 'quantity' => '' === $q ? $default[0] : max( 1, (int) $q ), 'percent' => '' === $p ? $default[1] : max( 0, min( 100, (float) $p ) ) );
		}
		return array( 'enabled' => 'no' !== $parent->get_meta( '_olr_volume_enabled' ), 'override' => 'no' !== $parent->get_meta( '_olr_volume_override' ), 'tiers' => $tiers );
	}

	public function virtual_coupon( $data, $code ) {
		if ( self::AUTO !== $code || ! $this->enabled ) { return $data; }
		return array( 'code' => self::AUTO, 'discount_type' => self::RUNTIME, 'amount' => 0, 'individual_use' => false, 'free_shipping' => false, 'virtual' => true );
	}

	public function prepare( $cart ) {
		if ( ! $this->enabled ) {
			if ( in_array( self::AUTO, $cart->get_applied_coupons(), true ) ) { $cart->set_applied_coupons( array_values( array_diff( $cart->get_applied_coupons(), array( self::AUTO ) ) ) ); }
			return;
		}
		$this->cart = $cart;
		$this->plan = null;
		$this->errors = array();
		$this->base = array();
		$this->calculating = true;
		foreach ( $cart->get_cart() as $key => $line ) {
			if ( $this->private_preview ) { $cart->cart_contents[ $key ][ OLR_Offer_Live_Preview::MARKER ] = true; }
			$product = wc_get_product( $line['variation_id'] ?: $line['product_id'] );
			if ( ! $product ) { $this->errors[] = 'A cart product could not be loaded.'; continue; }
			$regular = (float) $product->get_regular_price();
			$current = (float) $product->get_price();
			if ( '' === $product->get_regular_price() ) { $regular = $current; }
			$observed = (float) $line['data']->get_price();
			if ( abs( $observed - $regular ) > 0.00001 && abs( $observed - $current ) > 0.00001 ) {
				$this->errors[] = 'Another extension is changing a product price. Its discount needs a compatibility review.';
			}
			$this->base[ $key ] = array( 'regular' => $regular, 'sale' => min( $regular, $current ) );
			// Each line gets its own object; two lines of the same SKU cannot mutate each other.
			$cart->cart_contents[ $key ]['data'] = clone $product;
			$cart->cart_contents[ $key ]['data']->set_price( $regular );
			unset( $cart->cart_contents[ $key ]['_olr_best_offer'] );
		}
		$codes = array_values( array_diff( $cart->get_applied_coupons(), array( self::AUTO ) ) );
		if ( ! $cart->is_empty() ) { $codes[] = self::AUTO; }
		$cart->set_applied_coupons( $codes );
	}

	private function group_id( $line ) {
		return ! empty( $line['olr_box_id'] ) ? 'box:' . $line['olr_box_id'] : 'product:' . ( $line['variation_id'] ?: $line['product_id'] );
	}

	private function groups( $items ) {
		$groups = array();
		foreach ( $items as $key => $item ) {
			$line = $item->object;
			$id = $this->group_id( $line );
			if ( ! isset( $groups[ $id ] ) ) { $groups[ $id ] = array( 'prices' => array(), 'automatic' => array(), 'allow_promos' => true, 'quantity' => 0, 'items' => array() ); }
			$groups[ $id ]['prices'][ $key ] = max( 0, (int) round( $item->price ) );
			$groups[ $id ]['quantity'] += $item->quantity;
			$groups[ $id ]['items'][ $key ] = $item;
		}
		$payment = ! $this->private_preview && WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
		foreach ( $groups as $id => &$group ) {
			$first = reset( $group['items'] );
			$is_box = 0 === strpos( $id, 'box:' );
			$settings = self::settings( $first->product );
			$rate = 0;
			if ( $is_box ) {
				$size = (int) ( $first->object['olr_box_size'] ?? 0 );
				if ( $group['quantity'] !== (float) $size && $group['quantity'] !== $size ) { $this->errors[] = 'A Research Box is incomplete.'; }
				else { $rate = 5 === $size ? 25 : ( 10 === $size ? 30 : 0 ); }
			} elseif ( $settings['enabled'] ) { $rate = OLR_Offer_Planner::tier( $group['quantity'], $settings['tiers'] ); }
			$total = array_sum( $group['prices'] );
			$group['automatic'][ $is_box ? 'Build Your Box' : 'Volume savings' ] = OLR_Offer_Planner::allocate( $total * $rate / 100, $group['prices'] );
			// A product opting out of promotional overrides keeps its qualifying volume offer.
			if ( ! $is_box && $rate > 0 && ! $settings['override'] ) { $group['allow_promos'] = false; }
			$sale = array();
			foreach ( $group['prices'] as $key => $price ) {
				$base = $this->base[ $key ];
				$sale[ $key ] = $base['regular'] > 0 ? (int) round( $price * ( 1 - $base['sale'] / $base['regular'] ) ) : 0;
			}
			$group['automatic']['Sale price'] = $sale;
			foreach ( (array) get_option( 'elex_discount_per_payment_method_options', array() ) as $index => $rule ) {
				if ( ! is_array( $rule ) || ! $payment || $payment !== ( $rule['id'] ?? '' ) || 'yes' !== ( $rule['checkbox_value'] ?? '' ) ) { continue; }
				if ( 'percentage' !== ( $rule['discount_type'] ?? 'percentage' ) ) {
					$this->errors[] = 'Fixed ELEX payment discounts need a tax-aware adapter before checkout can proceed.';
					continue;
				}
				$group['automatic']['Payment method savings'] = OLR_Offer_Planner::allocate( $total * min( 100, max( 0, (float) $rule['value'] ) ) / 100, $group['prices'] );
			}
		}
		unset( $group );
		return $groups;
	}

	/** Remove explicitly excluded entire boxes BEFORE native coupon/BOGO qualification and allocation. */
	private function eligible_items( $items, $coupon ) {
		$blocked = array();
		$bundle_term = get_term_by( 'slug', 'bundles', 'product_cat' );
		$exclude_boxes = 'yes' === $coupon->get_meta( '_olr_exclude_boxes' ) || ( $bundle_term && in_array( (int) $bundle_term->term_id, array_map( 'intval', $coupon->get_excluded_product_categories() ), true ) );
		foreach ( $items as $item ) {
			if ( empty( $item->object['olr_box_id'] ) ) { continue; }
			$ids = array( $item->product->get_id(), $item->product->get_parent_id() );
			$cats = wc_get_product_cat_ids( $item->product->get_parent_id() ?: $item->product->get_id() );
			$excluded = $exclude_boxes || array_intersect( $ids, $coupon->get_excluded_product_ids() ) || array_intersect( $cats, $coupon->get_excluded_product_categories() );
			if ( $coupon->get_exclude_sale_items() && $item->product->is_on_sale() ) { $excluded = true; }
			// Target inclusion must cover the full box; a partial-target coupon cannot override part of it.
			if ( ( $coupon->get_product_ids() || $coupon->get_product_categories() ) && ! array_intersect( $ids, $coupon->get_product_ids() ) && ! array_intersect( $cats, $coupon->get_product_categories() ) ) { $excluded = true; }
			if ( class_exists( 'OLR_ACO_Rule' ) && OLR_ACO_Rule::is_advanced( $coupon ) ) {
				$rule = OLR_ACO_Rule::from_coupon( $coupon );
				if ( ! OLR_ACO_Rule::product_matches_scope( $item->product, $rule['target_scope'], $rule['target_product_ids'], $rule['target_category_ids'], $rule['target_excluded_product_ids'], $rule['target_excluded_category_ids'] ) ) { $excluded = true; }
			}
			if ( $excluded ) { $blocked[ $item->object['olr_box_id'] ] = true; }
		}
		return array_filter( $items, static function ( $item ) use ( $blocked ) { return empty( $item->object['olr_box_id'] ) || ! isset( $blocked[ $item->object['olr_box_id'] ] ); } );
	}

	private function build_plan( $items ) {
		$groups = $this->groups( $items );
		$offers = array();
		$this->quoting = true;
		try {
			foreach ( $this->cart->get_applied_coupons() as $code ) {
				if ( self::AUTO === $code ) { continue; }
				$coupon = new WC_Coupon( $code );
				if ( ! in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_cart', 'fixed_product', 'olr_advanced_offer' ), true ) ) {
					$this->errors[] = 'An unsupported coupon type needs a compatibility review.';
					$offers[ $code ] = array();
					continue;
				}
				$quote = new WC_Discounts( $this->cart );
				$eligible = $this->eligible_items( $items, $coupon );
				$quote->set_items( array_map( static function ( $item ) { return clone $item; }, $eligible ) );
				$result = $quote->apply_coupon( $coupon );
				$offers[ $code ] = is_wp_error( $result ) ? array() : $quote->get_discounts_by_item( true );
			}
		} finally { $this->quoting = false; }
		return OLR_Offer_Planner::choose( $groups, $offers );
	}

	public function validate_box_exclusions( $valid, $coupon, $discounts ) {
		if ( ! $valid || $this->quoting || self::AUTO === $coupon->get_code() || ! ( $discounts->get_object() instanceof WC_Cart ) ) { return $valid; }
		$items = $discounts->get_items();
		$eligible = $this->eligible_items( $items, $coupon );
		if ( count( $items ) === count( $eligible ) ) { return $valid; }
		if ( ! $eligible ) { return false; }
		$this->quoting = true;
		try {
			$quote = new WC_Discounts( $discounts->get_object() );
			$quote->set_items( $eligible );
			return ! is_wp_error( $quote->is_coupon_valid( clone $coupon ) );
		} finally { $this->quoting = false; }
	}

	public function allocate_coupon( $items, $coupon, $discounts ) {
		if ( isset( $this->replays[ $coupon ] ) ) { return array(); }
		if ( ! $this->calculating || $this->quoting || $discounts->get_object() !== $this->cart ) { return $items; }
		if ( null === $this->plan ) { $this->plan = $this->build_plan( $discounts->get_items() ); }
		// Only this disposable coupon instance changes, AFTER native validation. No DB coupon is edited.
		$coupon->set_discount_type( self::RUNTIME );
		return array(); // Apply the exact allocation through WooCommerce's custom-discount map hook.
	}

	public function discount_map( $map, $coupon ) {
		if ( isset( $this->replays[ $coupon ] ) ) { return array_replace( array_fill_keys( array_keys( $map ), 0 ), $this->replays[ $coupon ] ); }
		if ( ! $this->calculating || $this->quoting || ! $this->plan || self::RUNTIME !== $coupon->get_discount_type() ) { return $map; }
		$allocation = self::AUTO === $coupon->get_code() ? $this->plan['automatic'] : ( $this->plan['coupons'][ $coupon->get_code() ] ?? array() );
		return array_replace( array_fill_keys( array_keys( $map ), 0 ), $allocation );
	}

	public function finish( $cart ) {
		$this->calculating = false;
		foreach ( $cart->get_fees() as $fee ) {
			if ( $fee->amount < 0 ) { $this->errors[] = 'Another extension added a discount fee. Checkout is paused to prevent stacking.'; }
		}
		foreach ( $cart->get_cart() as $key => $line ) {
			$group = $this->plan['groups'][ $this->group_id( $line ) ] ?? null;
			if ( $group ) { $cart->cart_contents[ $key ]['_olr_best_offer'] = $group; }
		}
		if ( WC()->session ) { WC()->session->set( 'olr_best_offer_errors', array_values( array_unique( $this->errors ) ) ); }
	}

	public function check_cart() {
		$errors = $this->errors ?: ( WC()->session ? (array) WC()->session->get( 'olr_best_offer_errors', array() ) : array() );
		foreach ( array_unique( $errors ) as $error ) {
			if ( ! wc_has_notice( $error, 'error' ) ) { wc_add_notice( $error, 'error' ); }
		}
	}

	public function coupon_label( $label, $coupon ) { return self::AUTO === $coupon->get_code() ? 'Automatic best offer' : $label; }
	public function coupon_html( $html, $coupon, $discount_html ) {
		if ( self::AUTO === $coupon->get_code() ) { return $discount_html; }
		if ( WC()->cart && WC()->cart->get_coupon_discount_amount( $coupon->get_code() ) <= 0 && $this->plan && array_sum( $this->plan['automatic'] ) > 0 ) {
			$html .= '<small class="olr-offer-message">Your existing offer provides a greater discount.</small>';
		}
		return $html;
	}

	public function item_data( $data, $line ) {
		if ( empty( $line['_olr_best_offer'] ) ) { return $data; }
		$data = array_values( array_filter( $data, static function ( $entry ) { return 'Box savings' !== ( $entry['key'] ?? '' ); } ) );
		$offer = $line['_olr_best_offer'];
		if ( $offer['savings'] > 0 ) { $data[] = array( 'key' => 'Applied offer', 'value' => $offer['offer'] ); }
		return $data;
	}

	public function line_subtotal( $html, $line, $key ) {
		if ( ! WC()->cart || empty( $line['_olr_best_offer'] ) ) { return $html; }
		$before = 0; $after = 0;
		$include_tax = WC()->cart->display_prices_including_tax();
		foreach ( WC()->cart->get_cart() as $other_key => $other ) {
			if ( empty( $line['olr_box_id'] ) ? $other_key !== $key : ( $other['olr_box_id'] ?? '' ) !== $line['olr_box_id'] ) { continue; }
			$before += $other['line_subtotal'] + ( $include_tax ? $other['line_subtotal_tax'] : 0 );
			$after += $other['line_total'] + ( $include_tax ? $other['line_tax'] : 0 );
		}
		return $after < $before ? '<del>' . wc_price( $before ) . '</del> <ins>' . wc_price( $after ) . '</ins><small class="olr-offer-message">Offer included</small>' : wc_price( $after );
	}

	public function order_meta( $item, $key, $values, $order ) {
		if ( empty( $values['_olr_best_offer'] ) ) { return; }
		$group = $values['_olr_best_offer'];
		$item->add_meta_data( '_olr_best_offer_source', $group['source'], true );
		$item->add_meta_data( '_olr_best_offer_name', $group['offer'], true );
		$item->add_meta_data( '_olr_best_offer_version', self::VERSION, true );
		$item->add_meta_data( '_olr_best_offer_cart_key', $key, true );
		$item->add_meta_data( '_olr_best_offer_quantity', $item->get_quantity(), true );
		$item->add_meta_data( '_olr_best_offer_subtotal', $item->get_subtotal(), true );
		if ( 'parent' === ( $values['olr_box_role'] ?? '' ) ) {
			$item->delete_meta_data( 'Box discount' );
			$item->delete_meta_data( 'Box savings' );
			$item->add_meta_data( 'Applied offer', $group['offer'], true );
			// Native order line totals, not metadata, remain the source of truth for refunds and tax.
			$item->update_meta_data( '_olr_box_rate', 0 );
		}
	}

	public function assert_safe_order( $order ) {
		if ( $this->private_preview || OLR_Offer_Live_Preview::protected_cart() ) { throw new Exception( OLR_Offer_Live_Preview::MESSAGE ); }
		if ( $this->errors ) { throw new Exception( implode( ' ', array_unique( $this->errors ) ) ); }
		$order->update_meta_data( '_olr_best_offer_version', self::VERSION );
	}

	public function order_coupon_meta( $item, $code, $coupon, $order ) {
		if ( ! $this->plan ) { return; }
		$map = self::AUTO === $code ? $this->plan['automatic'] : ( $this->plan['coupons'][ $code ] ?? array() );
		$item->add_meta_data( '_olr_best_offer_allocation', $map, true );
	}

	/** Ordinary order recalculation replays the paid allocation, never today's coupon percentages. */
	public function replay_order_coupon( $coupon, $code, $coupon_item, $order ) {
		if ( ! $order->get_meta( '_olr_best_offer_version' ) ) { return $coupon; }
		$recorded = $coupon_item->get_meta( '_olr_best_offer_allocation', true );
		if ( ! is_array( $recorded ) ) { throw new Exception( 'This order needs an Off Label pricing review before its coupons can be changed.' ); }
		$map = array();
		foreach ( $order->get_items( 'line_item' ) as $id => $line ) {
			if ( (float) $line->get_quantity() !== (float) $line->get_meta( '_olr_best_offer_quantity' ) || abs( (float) $line->get_subtotal() - (float) $line->get_meta( '_olr_best_offer_subtotal' ) ) > 0.00001 ) {
				throw new Exception( 'Order quantities/prices changed. This staging candidate cannot reprice edited orders; review the order before saving.' );
			}
			$map[ $id ] = (int) ( $recorded[ $line->get_meta( '_olr_best_offer_cart_key' ) ] ?? 0 );
		}
		$coupon->set_discount_type( self::RUNTIME );
		$this->replays[ $coupon ] = $map;
		return $coupon;
	}

	public static function quote_data( $product ) {
		$settings = self::settings( $product );
		$regular = '' === $product->get_regular_price() ? (float) $product->get_price() : (float) $product->get_regular_price();
		return array( 'enabled' => $settings['enabled'], 'tiers' => $settings['tiers'], 'regular' => (float) wc_get_price_to_display( $product, array( 'price' => $regular ) ), 'current' => (float) wc_get_price_to_display( $product ), 'variable' => $product->is_type( 'variable' ) );
	}

	public function selector_data( $output, $tag, $attr, $match ) {
		if ( 'olr_product_page' !== $tag || ! preg_match( '/id="olr-product-title-(\d+)"/', $output, $ids ) ) { return $output; }
		$product = wc_get_product( (int) $ids[1] );
		if ( ! $product ) { return $output; }
		return str_replace( 'data-olr-volume-pricing ', 'data-olr-offer="' . esc_attr( wp_json_encode( self::quote_data( $product ) ) ) . '" data-olr-volume-pricing ', $output );
	}
	public function variation_data( $data, $parent, $variation ) { $data['olr_offer'] = self::quote_data( $variation ); return $data; }
	public function assets() {
		wp_enqueue_style( 'olr-best-offer', plugins_url( 'assets/volume.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'olr-best-offer', plugins_url( 'assets/volume.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
		wp_localize_script( 'olr-best-offer', 'olrOfferMoney', array( 'symbol' => get_woocommerce_currency_symbol(), 'decimals' => wc_get_price_decimals(), 'decimal' => wc_get_price_decimal_separator(), 'thousand' => wc_get_price_thousand_separator(), 'format' => get_woocommerce_price_format() ) );
		if ( $this->private_preview ) {
			wp_localize_script( 'olr-best-offer', 'olrOfferPreview', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'olr_private_pricing_quotes' ) ) );
		}
	}
}

add_action( 'plugins_loaded', static function () { if ( class_exists( 'WooCommerce' ) ) { new OLR_Best_Offer(); } }, 30 );
