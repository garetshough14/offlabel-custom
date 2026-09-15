<?php
/** Hub-owned coupon naming and customer discounts. UAP still owns attribution/rates. */
defined( 'ABSPATH' ) || exit;

final class OLR_Affiliate_Coupons {
	const DEFAULT_OPTION = 'olr_affiliate_customer_discount';
	const OVERRIDE_META = '_olr_affiliate_customer_discount';
	const PRIMARY_META = '_olr_affiliate_primary_code';
	private static $touched_posts = array();
	private static $touched_users = array();
	private static function write( $callback ) {
		self::$touched_posts = self::$touched_users = array();
		try { return OLR_Affiliate_Service::atomic( $callback ); }
		finally {
			// WordPress object-cache writes are not transactional; evict after commit or rollback.
			foreach ( array_unique( self::$touched_posts ) as $id ) { if ( $id ) { clean_post_cache( $id ); } }
			foreach ( array_unique( self::$touched_users ) as $uid ) { wp_cache_delete( $uid, 'user_meta' ); }
			foreach ( array( self::DEFAULT_OPTION, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
			WC_Cache_Helper::invalidate_cache_group( 'coupons' );
		}
	}

	public static function percent( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{1,3}(?:\.\d{1,2})?$/', $value ) || (float) $value <= 0 || (float) $value > 100 ) {
			throw new RuntimeException( 'Enter a customer discount greater than 0 and no more than 100, with up to two decimal places.' );
		}
		return wc_format_decimal( $value, 2 );
	}
	public static function default_percent() { return get_option( self::DEFAULT_OPTION, '20.00' ); }
	public static function discount( $uid ) {
		$override = get_user_meta( $uid, self::OVERRIDE_META, true );
		return '' === $override ? self::default_percent() : $override;
	}
	public static function label( $value ) { return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' ) . '%'; }
	public static function offer( $uid ) {
		$code = OLR_Affiliate_Service::coupon_code( $uid );
		if ( $code ) {
			$coupon = new WC_Coupon( $code );
			return $coupon->is_type( 'percent' ) ? self::label( $coupon->get_amount() ) : wp_strip_all_tags( wc_price( $coupon->get_amount() ) );
		}
		return self::label( self::discount( $uid ) );
	}
	private static function require_tables() {
		global $wpdb;
		if ( ! class_exists( 'WC_Coupon' ) ) { throw new RuntimeException( 'WooCommerce coupons are unavailable.' ); }
		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->usermeta, $wpdb->options, $wpdb->prefix . 'uap_coupons_code_affiliates' ) as $table ) {
			if ( ! OLR_Affiliate_Service::transactional_table( $table ) ) { throw new RuntimeException( 'Coupon setup requires transactional database storage. Contact support.' ); }
		}
	}
	/** Read the database directly: a cached negative coupon lookup is unsafe during a claim. */
	public static function taken( $code ) {
		global $wpdb;
		$code = wc_format_coupon_code( $code );
		$woo = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='shop_coupon' AND (LOWER(post_title)=LOWER(%s) OR LOWER(post_title)=LOWER(%s)) LIMIT 1", $code, $code . '__trashed' ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Coupon availability could not be checked. Please retry.' ); }
		$uap = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}uap_coupons_code_affiliates WHERE LOWER(code)=LOWER(%s) LIMIT 1", $code ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Coupon availability could not be checked. Please retry.' ); }
		return (bool) ( $woo || $uap );
	}
	public static function choose( $uid, $requested ) {
		global $indeed_db;
		$requested = trim( (string) $requested );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{3,32}$/D', $requested ) ) { throw new RuntimeException( 'Use 3–32 letters, numbers, hyphens or underscores for your code.' ); }
		if ( ! OLR_Affiliate_Service::active( $uid ) ) { throw new RuntimeException( 'Active affiliate access is required.' ); }
		self::require_tables();
		return self::write( function () use ( $uid, $requested, $indeed_db ) {
			self::$touched_users[] = $uid;
			if ( ! OLR_Affiliate_Service::active( $uid ) ) { throw new RuntimeException( 'Active affiliate access is required.' ); }
			$id = OLR_Account_Hub::affiliate_id( $uid );
			$code = wc_format_coupon_code( strtolower( $requested ) );
			// Selecting one of your own existing live assignments is an idempotent operation.
			foreach ( (array) $indeed_db->get_coupons_for_affiliate( $id ) as $assignment ) {
				if ( 'woo' === $assignment['type'] && strtolower( $assignment['code'] ) === $code && wc_get_coupon_id_by_code( $code ) ) {
					update_user_meta( $uid, self::PRIMARY_META, $code );
					return $code;
				}
			}
			if ( self::taken( $code ) ) { throw new RuntimeException( 'That code is already taken. Please choose another code.' ); }
			$current = OLR_Affiliate_Service::coupon_code( $uid );
			$coupon = new WC_Coupon();
			$settings = array( 'amount_type' => '', 'amount_value' => '' );
			if ( $current ) {
				$source = new WC_Coupon( $current );
				// Carry forward the actual offer and product restrictions, never commission rates from member input.
				foreach ( array( 'discount_type', 'amount', 'individual_use', 'product_ids', 'excluded_product_ids', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'free_shipping', 'product_categories', 'excluded_product_categories', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'email_restrictions', 'date_expires' ) as $prop ) {
					$coupon->{ 'set_' . $prop }( $source->{ 'get_' . $prop }( 'edit' ) );
				}
				$settings = $indeed_db->get_coupon_data( $current );
			} else {
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( self::discount( $uid ) );
			}
			$coupon->set_code( $code );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->update_meta_data( '_olr_affiliate_owner', $uid );
			$coupon->set_description( 'Off Label affiliate first-order offer' );
			$coupon_id = $coupon->save();
			self::$touched_posts[] = $coupon_id;
			if ( ! $coupon_id || ! $indeed_db->save_coupon_affiliate_pair( array( 'code' => $code, 'affiliate_id' => $id, 'type' => 'woo', 'status' => 1, 'amount_type' => $settings['amount_type'] ?? '', 'amount_value' => $settings['amount_value'] ?? '' ) ) ) {
				throw new RuntimeException( 'Your code could not be saved. Please retry.' );
			}
			update_user_meta( $uid, self::PRIMARY_META, $code );
			update_user_meta( $uid, 'olr_affiliate_coupon_code', $code );
			return $code;
		} );
	}
	public static function save_default( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Administrator access required.' ); }
		$value = self::percent( $value );
		self::require_tables();
		self::write( function () use ( $value ) {
			global $wpdb;
			update_option( self::DEFAULT_OPTION, $value, false );
			$rows = $wpdb->get_results( "SELECT DISTINCT p.ID,m.meta_value AS uid FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_type='shop_coupon' AND m.meta_key IN ('_olr_affiliate_owner','_olr_affiliate_discount_owner')", ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Affiliate coupons could not be loaded.' ); }
			foreach ( $rows as $row ) {
				if ( '' === get_user_meta( (int) $row['uid'], self::OVERRIDE_META, true ) ) { self::set_coupon_discount( (int) $row['ID'], $value ); }
			}
		} );
	}
	public static function save_override( $uid, $value ) {
		if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Administrator access required.' ); }
		$value = trim( (string) $value );
		if ( '' !== $value ) { $value = self::percent( $value ); }
		if ( ! OLR_Account_Hub::affiliate_id( $uid ) ) { throw new RuntimeException( 'This member has no affiliate account.' ); }
		self::require_tables();
		self::write( function () use ( $uid, $value ) {
			self::$touched_users[] = $uid;
			global $indeed_db;
			if ( '' === $value ) { delete_user_meta( $uid, self::OVERRIDE_META ); }
			else { update_user_meta( $uid, self::OVERRIDE_META, $value ); }
			foreach ( (array) $indeed_db->get_coupons_for_affiliate( OLR_Account_Hub::affiliate_id( $uid ) ) as $assignment ) {
				if ( 'woo' !== $assignment['type'] ) { continue; }
				$coupon_id = wc_get_coupon_id_by_code( $assignment['code'] );
				if ( ! $coupon_id ) { continue; }
				self::set_coupon_discount( $coupon_id, '' === $value ? self::default_percent() : $value, $uid );
			}
		} );
	}
	private static function set_coupon_discount( $id, $value, $uid = 0 ) {
		self::$touched_posts[] = $id;
		$coupon = new WC_Coupon( $id );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $value );
		if ( $uid ) { $coupon->update_meta_data( '_olr_affiliate_discount_owner', $uid ); }
		if ( ! $coupon->save() ) { throw new RuntimeException( 'A coupon discount could not be saved.' ); }
	}
}
