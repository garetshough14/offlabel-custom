<?php
/** Affiliate lifecycle and atomic commission settlements. No vendor file changes. */
defined( 'ABSPATH' ) || exit;

final class OLR_Affiliate_Service {
	const SCHEMA = '1.2.0';
	const MINIMUM = 5000;
	const HOLD_DAYS = 30;
	private static $in_transaction = false;
	private static $mail = array();
	private static $creating = false;

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'olr_aff_' . $name;
	}

	public static function install() {
		if ( get_option( 'olr_aff_schema' ) === self::SCHEMA ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$schemas = array(
			'requests' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
				operation_key varchar(64) NOT NULL,
				user_id bigint unsigned NOT NULL,
				affiliate_id bigint unsigned NOT NULL,
				method varchar(20) NOT NULL,
				amount bigint NOT NULL,
				currency varchar(8) NOT NULL,
				status varchar(20) NOT NULL,
				details longtext NOT NULL,
				confirmation varchar(190) NOT NULL DEFAULT '',
				payment_id bigint unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY operation_key (operation_key),
				KEY member_status (user_id,status)",
			'reservations' => "referral_id bigint unsigned NOT NULL,
				request_id bigint unsigned NOT NULL,
				amount bigint NOT NULL,
				PRIMARY KEY  (referral_id),
				KEY request_id (request_id)",
			'balances' => "user_id bigint unsigned NOT NULL,
				currency varchar(8) NOT NULL,
				balance bigint NOT NULL DEFAULT 0,
				reserved bigint NOT NULL DEFAULT 0,
				PRIMARY KEY  (user_id,currency)",
			'ledger' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
				operation_key varchar(100) NOT NULL,
				user_id bigint unsigned NOT NULL,
				currency varchar(8) NOT NULL,
				delta bigint NOT NULL,
				reserved_delta bigint NOT NULL,
				kind varchar(30) NOT NULL,
				order_id bigint unsigned NOT NULL DEFAULT 0,
				request_id bigint unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY operation_key (operation_key),
				KEY member (user_id,id),
				KEY order_id (order_id)",
			'documents' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint unsigned NOT NULL,
				file_key varchar(64) NOT NULL,
				status varchar(24) NOT NULL,
				reviewer bigint unsigned NOT NULL DEFAULT 0,
				note text NOT NULL,
				created_at datetime NOT NULL,
				reviewed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY member (user_id,id)",
		);
		foreach ( $schemas as $name => $fields ) {
			dbDelta( 'CREATE TABLE ' . self::table( $name ) . " ($fields) ENGINE=InnoDB $charset;" );
		}
		foreach ( array_keys( $schemas ) as $name ) {
			if ( ! self::transactional_table( self::table( $name ) ) ) { return; }
		}
		// Legacy decisions remain an immutable reference; a separate flag governs eligibility.
		$wpdb->query( "INSERT INTO {$wpdb->usermeta} (user_id,meta_key,meta_value)
			SELECT old.user_id,'_olr_affiliate_blocked','1' FROM {$wpdb->usermeta} old
			WHERE old.meta_key='_olr_affiliate_application_status' AND old.meta_value='rejected'
			AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} flag WHERE flag.user_id=old.user_id AND flag.meta_key='_olr_affiliate_blocked')" );
		update_option( 'olr_aff_schema', self::SCHEMA, false );
	}

	public static function transactional_table( $table ) {
		global $wpdb;
		return 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}
	/** Explicit admin setup step: storage engine only; no vendor PHP/schema columns change. */
	public static function prepare_tables() {
		global $wpdb;
		if ( get_option( 'olr_aff_payouts_enabled' ) ) { throw new RuntimeException( 'Disable payouts before preparing database storage.' ); }
		foreach ( array( $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'uap_affiliates', $wpdb->prefix . 'uap_coupons_code_affiliates', $wpdb->prefix . 'uap_referrals', $wpdb->prefix . 'uap_payments' ) as $table ) {
			if ( ! self::transactional_table( $table ) ) { self::query( "ALTER TABLE `$table` ENGINE=InnoDB" ); }
		}
		update_option( 'olr_aff_storage_prepared', array( 'at' => current_time( 'mysql' ), 'by' => get_current_user_id() ), false );
	}

	public static function query( $sql ) {
		global $wpdb;
		$result = $wpdb->query( $sql );
		if ( false === $result ) { throw new RuntimeException( 'The change could not be saved. Please try again.' ); }
		return $result;
	}

	public static function insert( $name, $data ) {
		global $wpdb;
		if ( false === $wpdb->insert( self::table( $name ), $data ) ) { throw new RuntimeException( 'The change could not be saved. Please try again.' ); }
		return (int) $wpdb->insert_id;
	}

	public static function defer_mail( $result, $args ) {
		if ( null !== $result ) { return $result; }
		self::$mail[] = $args;
		return true;
	}

	/** All hub writers share one per-site lock, including admin, checkout and retries. */
	public static function atomic( $callback ) {
		global $wpdb;
		if ( self::$in_transaction ) { return call_user_func( $callback ); }
		$lock = 'olr_aff_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,10)', $lock ) ) ) {
			throw new RuntimeException( 'Another account update is in progress. Please try again.' );
		}
		self::$in_transaction = true;
		self::$mail = array();
		add_filter( 'pre_wp_mail', array( __CLASS__, 'defer_mail' ), PHP_INT_MAX, 2 );
		try {
			self::query( 'START TRANSACTION' );
			$result = call_user_func( $callback );
			self::query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			self::$mail = array();
			throw $error;
		} finally {
			self::$in_transaction = false;
			remove_filter( 'pre_wp_mail', array( __CLASS__, 'defer_mail' ), PHP_INT_MAX );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		foreach ( self::$mail as $mail ) {
			wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'], $mail['attachments'] );
		}
		self::$mail = array();
		return $result;
	}

	public static function currency() { return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : ''; }
	public static function cents( $amount ) { return (int) round( (float) $amount * 100, 0, PHP_ROUND_HALF_UP ); }
	public static function money( $cents ) { return function_exists( 'wc_price' ) ? wc_price( $cents / 100 ) : '$' . number_format( $cents / 100, 2 ); }
	public static function blocked( $uid ) {
		$flag = get_user_meta( $uid, '_olr_affiliate_blocked', true );
		return '1' === $flag || ( '' === $flag && 'rejected' === get_user_meta( $uid, '_olr_affiliate_application_status', true ) );
	}
	public static function member_eligible( $uid ) {
		$user = get_userdata( $uid );
		return $user && ! user_can( $uid, 'manage_options' ) && ! self::blocked( $uid )
			&& ! in_array( 'pending_user', (array) $user->roles, true )
			&& ! in_array( get_user_meta( $uid, 'account_status', true ), array( 'awaiting_admin_review', 'awaiting_email_confirmation', 'rejected', 'inactive' ), true );
	}
	public static function active( $uid ) {
		global $indeed_db;
		return self::member_eligible( $uid ) && is_object( $indeed_db )
			&& method_exists( $indeed_db, 'is_user_an_active_affiliate' ) && (bool) $indeed_db->is_user_an_active_affiliate( $uid );
	}
	public static function set_block( $uid, $blocked ) {
		return self::atomic( function () use ( $uid, $blocked ) {
			if ( ! get_userdata( $uid ) ) { throw new RuntimeException( 'Member not found.' ); }
			update_user_meta( $uid, '_olr_affiliate_blocked', $blocked ? '1' : '0' );
			update_user_meta( $uid, '_olr_affiliate_access_changed', array( 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ), 'blocked' => $blocked ) );
			// A UAP suspension also needs an explicit administrator restore.
			global $wpdb;
			// UAP's doApproveAffiliate changes WordPress roles. Change only affiliate status.
			self::query( $wpdb->prepare( "UPDATE {$wpdb->prefix}uap_affiliates SET status=%d WHERE uid=%d", $blocked ? 0 : 1, $uid ) );
		} );
	}
	public static function insert_affiliate_guard( $query ) { return self::$creating ? $query : 'SELECT 0'; }

	public static function activate( $uid, $accepted ) {
		global $indeed_db;
		if ( ! $accepted || ! get_option( OLR_Account_Hub::OPTION_TERMS_URL ) ) { throw new RuntimeException( 'Please accept the published affiliate terms.' ); }
		if ( ! self::member_eligible( $uid ) ) { throw new RuntimeException( 'Affiliate activation is unavailable for this account. Contact support.' ); }
		if ( ! is_object( $indeed_db ) || ! method_exists( $indeed_db, 'save_affiliate' ) || ! class_exists( 'WC_Coupon' ) ) { throw new RuntimeException( 'Affiliate activation is temporarily unavailable.' ); }
		global $wpdb;
		foreach ( array( $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'uap_affiliates', $wpdb->prefix . 'uap_coupons_code_affiliates' ) as $table ) {
			if ( ! self::transactional_table( $table ) ) { throw new RuntimeException( 'Affiliate activation requires transactional database tables. Contact support.' ); }
		}
		self::atomic( function () use ( $uid, $indeed_db ) {
			if ( ! self::member_eligible( $uid ) ) { throw new RuntimeException( 'Affiliate activation is unavailable for this account.' ); }
			$id = OLR_Account_Hub::affiliate_id( $uid );
			if ( $id && ! self::active( $uid ) ) { throw new RuntimeException( 'Your affiliate access is suspended. Contact support.' ); }
			$new = ! $id;
			if ( $new ) {
				$rank = (int) get_option( 'uap_register_new_user_rank' );
				if ( ! $rank ) { $settings = $indeed_db->return_settings_from_wp_option( 'register' ); $rank = isset( $settings['uap_register_new_user_rank'] ) ? (int) $settings['uap_register_new_user_rank'] : 0; }
				$rank_data = $rank ? $indeed_db->get_rank( $rank ) : array();
				if ( ! $rank_data || empty( $rank_data['status'] ) ) { throw new RuntimeException( 'The affiliate commission rank is not configured. Contact support.' ); }
				self::$creating = true;
				try { $id = $indeed_db->save_affiliate( $uid ); }
				finally { self::$creating = false; }
				if ( ! $id ) { throw new RuntimeException( 'Affiliate account could not be created.' ); }
				if ( $rank ) { $indeed_db->update_affiliate_rank_by_uid( $uid, $rank ); }
			}
			self::ensure_coupon( $uid, $id );
			update_user_meta( $uid, '_olr_affiliate_terms_url_accepted', get_option( OLR_Account_Hub::OPTION_TERMS_URL ) );
			update_user_meta( $uid, '_olr_affiliate_terms_accepted_at', current_time( 'mysql' ) );
			if ( $new ) {
				update_user_meta( $uid, '_olr_affiliate_activated_at', current_time( 'mysql' ) );
				$user = get_userdata( $uid );
				wp_mail( $user->user_email, 'Your Off Label affiliate account is active', 'Your affiliate code and referral link are ready: ' . home_url( '/account/?um_tab=affiliate' ) );
			}
		} );
		clean_user_cache( $uid );
	}

	public static function coupon_code( $uid ) {
		global $indeed_db;
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! OLR_Account_Hub::affiliate_id( $uid ) ) { return ''; }
		// Resolve the live vendor assignment; legacy display metadata may be stale.
		if ( is_object( $indeed_db ) && method_exists( $indeed_db, 'get_coupons_for_affiliate' ) ) {
			$coupons = (array) $indeed_db->get_coupons_for_affiliate( OLR_Account_Hub::affiliate_id( $uid ) );
			$primary = strtolower( (string) get_user_meta( $uid, OLR_Affiliate_Coupons::PRIMARY_META, true ) );
			usort( $coupons, static function ( $a, $b ) use ( $primary ) { return (int) ( strtolower( $b['code'] ) === $primary ) - (int) ( strtolower( $a['code'] ) === $primary ); } );
			foreach ( $coupons as $coupon ) {
				if ( 'woo' === $coupon['type'] && ! empty( $coupon['code'] ) && wc_get_coupon_id_by_code( $coupon['code'] ) ) { return $coupon['code']; }
			}
		}
		return '';
	}
	/** Repair existing active affiliates without reenrolling them or changing their rank/roles. */
	public static function prepare_code( $uid ) {
		global $wpdb, $indeed_db;
		if ( ! self::active( $uid ) ) { throw new RuntimeException( 'Active affiliate access is required.' ); }
		if ( ! class_exists( 'WC_Coupon' ) || ! method_exists( $indeed_db, 'save_coupon_affiliate_pair' ) ) { throw new RuntimeException( 'Referral codes are temporarily unavailable. Please contact support.' ); }
		foreach ( array( $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'uap_coupons_code_affiliates' ) as $table ) {
			if ( ! self::transactional_table( $table ) ) { throw new RuntimeException( 'Referral code setup needs administrator attention. Please contact support.' ); }
		}
		return self::atomic( function () use ( $uid ) {
			if ( ! self::active( $uid ) ) { throw new RuntimeException( 'Active affiliate access is required.' ); }
			self::ensure_coupon( $uid, OLR_Account_Hub::affiliate_id( $uid ) );
			return self::coupon_code( $uid );
		} );
	}
	private static function ensure_coupon( $uid, $id ) {
		global $indeed_db;
		if ( self::coupon_code( $uid ) ) { return; }
		$code = 'OLR-' . $id;
		while ( OLR_Affiliate_Coupons::taken( $code ) ) { $code = 'OLR-' . $id . '-' . strtoupper( wp_generate_password( 5, false, false ) ); }
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( OLR_Affiliate_Coupons::discount( $uid ) );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->update_meta_data( '_olr_affiliate_owner', $uid );
		$coupon->set_description( 'Off Label affiliate first-order offer' );
		if ( ! $coupon->save() || ! $indeed_db->save_coupon_affiliate_pair( array( 'code' => $code, 'affiliate_id' => $id, 'type' => 'woo', 'status' => 1, 'amount_type' => '', 'amount_value' => '' ) ) ) {
			throw new RuntimeException( 'Your referral code could not be prepared. Please retry activation.' );
		}
		update_user_meta( $uid, 'olr_affiliate_coupon_code', $code );
	}

	public static function readiness() {
		global $wpdb, $indeed_db;
		$errors = array();
		if ( get_option( 'olr_aff_schema' ) !== self::SCHEMA ) { $errors[] = 'Account Hub database setup is incomplete.'; }
		if ( ! defined( 'UAP_PLUGIN_VER' ) || '9.7.7' !== UAP_PLUGIN_VER ) { $errors[] = 'Settlement requires the reviewed UAP 9.7.7 adapter.'; }
		foreach ( array( 'get_referral', 'add_payment', 'change_referrals_status', 'is_user_an_active_affiliate' ) as $method ) {
			if ( ! is_object( $indeed_db ) || ! method_exists( $indeed_db, $method ) ) { $errors[] = 'The UAP settlement adapter is unavailable.'; break; }
		}
		if ( ! self::currency() || self::currency() !== get_option( 'uap_currency' ) || 2 !== (int) wc_get_price_decimals() ) { $errors[] = 'UAP and WooCommerce must use the same currency with two decimal places.'; }
		foreach ( array( self::table( 'requests' ), self::table( 'reservations' ), self::table( 'balances' ), self::table( 'ledger' ), self::table( 'documents' ), $wpdb->prefix . 'uap_referrals', $wpdb->prefix . 'uap_payments' ) as $table ) {
			if ( ! self::transactional_table( $table ) ) { $errors[] = 'All settlement tables must use InnoDB.'; break; }
		}
		// Do not overlap pre-upgrade native payouts awaiting external settlement.
		if ( ! $errors && $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}uap_payments WHERE status=1 LIMIT 1" ) ) { $errors[] = 'Reconcile existing native UAP pending payments before enabling new payouts.'; }
		try { self::storage(); } catch ( Exception $e ) { $errors[] = $e->getMessage(); }
		return array_unique( $errors );
	}
	public static function require_ready() {
		if ( ! get_option( 'olr_aff_payouts_enabled', false ) || self::readiness() ) { throw new RuntimeException( 'Payout setup is not complete. Please contact support.' ); }
	}

	/** Decimal referral amounts are converted once, before any arithmetic. */
	public static function referral_eligible( $row, $affiliate_id, $now = null ) {
		$now = null === $now ? current_time( 'timestamp' ) : $now;
		$date = isset( $row['date'] ) ? strtotime( $row['date'] ) : false;
		return isset( $row['affiliate_id'], $row['status'], $row['payment'], $row['currency'], $row['amount'] )
			&& (int) $row['affiliate_id'] === (int) $affiliate_id && 2 === (int) $row['status'] && 0 === (int) $row['payment']
			&& self::currency() === $row['currency'] && self::cents( $row['amount'] ) > 0
			&& false !== $date && $date <= $now - self::HOLD_DAYS * DAY_IN_SECONDS;
	}
	public static function available_referrals( $uid, $lock = false ) {
		global $wpdb;
		$id = OLR_Account_Hub::affiliate_id( $uid );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.* FROM {$wpdb->prefix}uap_referrals r LEFT JOIN " . self::table( 'reservations' ) . " h ON h.referral_id=r.id WHERE r.affiliate_id=%d AND r.status=2 AND r.payment=0 AND h.referral_id IS NULL ORDER BY r.id" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Commission information is temporarily unavailable.' ); }
		return array_values( array_filter( (array) $rows, function ( $row ) use ( $id ) { return self::referral_eligible( $row, $id ); } ) );
	}
	public static function latest_document( $uid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'documents' ) . ' WHERE user_id=%d ORDER BY id DESC LIMIT 1', $uid ), ARRAY_A );
	}
	public static function tax_approved( $uid ) {
		$doc = self::latest_document( $uid );
		return $doc && 'approved' === $doc['status'];
	}
	public static function zelle_details( $name, $destination ) {
		$name = trim( sanitize_text_field( $name ) );
		$destination = trim( $destination );
		if ( ! is_email( $destination ) ) {
			$digits = preg_replace( '/[^0-9]/', '', $destination );
			if ( 11 === strlen( $digits ) && '1' === $digits[0] ) { $digits = substr( $digits, 1 ); }
			if ( ! preg_match( '/^[2-9][0-9]{2}[2-9][0-9]{6}$/', $digits ) ) { throw new RuntimeException( 'Enter an enrolled Zelle email or US mobile number.' ); }
			$destination = '+1' . $digits;
		}
		if ( '' === $name || strlen( $name ) > 150 || strlen( $destination ) > 190 ) { throw new RuntimeException( 'Enter the Zelle recipient name and destination.' ); }
		return array( 'name' => $name, 'destination' => $destination );
	}

	public static function request( $uid, $method, $key, $details = array() ) {
		self::require_ready();
		if ( ! in_array( $method, array( 'store_credit', 'zelle' ), true ) || ! preg_match( '/^[a-f0-9-]{36}$/i', $key ) ) { throw new RuntimeException( 'Invalid payout request.' ); }
		return self::atomic( function () use ( $uid, $method, $key, $details ) {
			global $wpdb;
			$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'requests' ) . ' WHERE operation_key=%s', $key ), ARRAY_A );
			if ( $old ) {
				if ( (int) $old['user_id'] !== $uid || $old['method'] !== $method ) { throw new RuntimeException( 'Invalid payout request.' ); }
				return (int) $old['id'];
			}
			if ( ! self::active( $uid ) ) { throw new RuntimeException( 'Active affiliate access is required.' ); }
			if ( 'zelle' === $method && ! self::tax_approved( $uid ) ) { throw new RuntimeException( 'An approved W-9 is required for Zelle.' ); }
			if ( 'zelle' === $method ) { $details = self::zelle_details( $details['name'] ?? '', $details['destination'] ?? '' ); }
			$rows = self::available_referrals( $uid, true );
			$sum = array_sum( array_map( function ( $row ) { return self::cents( $row['amount'] ); }, $rows ) );
			if ( $sum < self::MINIMUM ) { throw new RuntimeException( 'At least $50 in commissions cleared through the 30-day hold is required.' ); }
			$now = current_time( 'mysql' );
			$id = self::insert( 'requests', array( 'operation_key' => $key, 'user_id' => $uid, 'affiliate_id' => OLR_Account_Hub::affiliate_id( $uid ), 'method' => $method, 'amount' => $sum, 'currency' => self::currency(), 'status' => 'pending', 'details' => wp_json_encode( $details ), 'created_at' => $now, 'updated_at' => $now ) );
			foreach ( $rows as $row ) {
				self::insert( 'reservations', array( 'referral_id' => $row['id'], 'request_id' => $id, 'amount' => self::cents( $row['amount'] ) ) );
				if ( 1 !== self::query( $wpdb->prepare( "UPDATE {$wpdb->prefix}uap_referrals SET payment=1 WHERE id=%d AND payment=0 AND status=2", $row['id'] ) ) ) { throw new RuntimeException( 'A commission changed. Refresh and try again.' ); }
			}
			if ( 'store_credit' === $method ) { self::complete( $id, 'Automatic store-credit conversion' ); }
			if ( 'zelle' === $method ) { self::notify_manager( 'New Zelle payout request #' . $id, 'A member requested a Zelle payout. Review eligibility and recipient details in Affiliate Management.' ); }
			return $id;
		} );
	}

	/** Shared pre-send review and locked completion validation. */
	public static function validate_pending( $request, $lock = false ) {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		if ( 'pending' !== $request['status'] || ! self::active( $request['user_id'] ) || $request['currency'] !== self::currency() ) { throw new RuntimeException( 'This payout is not eligible for completion.' ); }
		if ( 'zelle' === $request['method'] && ( ! self::tax_approved( $request['user_id'] ) ) ) { throw new RuntimeException( 'Zelle requires a currently approved W-9.' ); }
		$holds = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'reservations' ) . ' WHERE request_id=%d ORDER BY referral_id' . $suffix, $request['id'] ), ARRAY_A );
		$sum = 0;
		foreach ( $holds as $hold ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uap_referrals WHERE id=%d" . $suffix, $hold['referral_id'] ), ARRAY_A );
			if ( ! $row || 1 !== (int) $row['payment'] ) { throw new RuntimeException( 'Reserved commissions changed. Review this payout before sending funds.' ); }
			$row['payment'] = 0;
			if ( ! self::referral_eligible( $row, $request['affiliate_id'] ) || self::cents( $row['amount'] ) !== (int) $hold['amount'] ) { throw new RuntimeException( 'A reserved commission was refunded or changed. Reject this request and ask the affiliate to resubmit.' ); }
			$sum += (int) $hold['amount'];
		}
		if ( $sum !== (int) $request['amount'] || $sum < self::MINIMUM ) { throw new RuntimeException( 'Payout reservation totals do not match.' ); }
		return $holds;
	}

	public static function complete( $id, $confirmation ) {
		self::require_ready();
		return self::atomic( function () use ( $id, $confirmation ) {
			global $wpdb, $indeed_db;
			$request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'requests' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $request ) { throw new RuntimeException( 'Payout not found.' ); }
			if ( 'paid' === $request['status'] ) { return; }
			$holds = self::validate_pending( $request, true );
			$sum = (int) $request['amount'];
			if ( 'zelle' === $request['method'] && '' === trim( $confirmation ) ) { throw new RuntimeException( 'Enter the Zelle payment confirmation.' ); }
			$ids = array_map( 'intval', array_column( $holds, 'referral_id' ) );
			$payment = $indeed_db->add_payment( array( 'payment_type' => 'zelle' === $request['method'] ? 'bt' : 'wallet', 'transaction_id' => 'olr-' . $request['operation_key'], 'referral_ids' => implode( ',', $ids ), 'affiliate_id' => $request['affiliate_id'], 'amount' => number_format( $sum / 100, 2, '.', '' ), 'currency' => $request['currency'], 'create_date' => current_time( 'mysql' ), 'update_date' => current_time( 'mysql' ), 'status' => 2 ) );
			if ( ! $payment || $wpdb->last_error ) { throw new RuntimeException( 'UAP could not record this payment. No credit was issued.' ); }
			// UAP otherwise snapshots the member's old native payment preference.
			$snapshot = array( 'olr_method' => $request['method'], 'olr_request_id' => $id, 'recipient' => json_decode( $request['details'], true ) );
			if ( false === $wpdb->update( $wpdb->prefix . 'uap_payments', array( 'payment_details' => maybe_serialize( $snapshot ) ), array( 'id' => $payment ) ) ) { throw new RuntimeException( 'The payout destination could not be recorded.' ); }
			$indeed_db->change_referrals_status( $ids, 2 );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'UAP could not settle the commissions.' ); }
			if ( 'store_credit' === $request['method'] ) { self::ledger( $request['user_id'], $sum, 0, 'payout-' . $id, 'affiliate_credit', 0, $id ); }
			self::query( $wpdb->prepare( 'UPDATE ' . self::table( 'requests' ) . " SET status='paid',payment_id=%d,confirmation=%s,updated_at=%s WHERE id=%d", $payment, sanitize_text_field( $confirmation ), current_time( 'mysql' ), $id ) );
		} );
	}
	public static function reject( $id, $reason ) {
		return self::atomic( function () use ( $id, $reason ) {
			global $wpdb;
			$request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'requests' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $request || 'pending' !== $request['status'] ) { throw new RuntimeException( 'Only pending requests can be rejected.' ); }
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT referral_id FROM ' . self::table( 'reservations' ) . ' WHERE request_id=%d', $id ) );
			foreach ( $ids as $referral_id ) { self::query( $wpdb->prepare( "UPDATE {$wpdb->prefix}uap_referrals SET payment=0 WHERE id=%d AND payment=1", $referral_id ) ); }
			self::query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'reservations' ) . ' WHERE request_id=%d', $id ) );
			self::query( $wpdb->prepare( 'UPDATE ' . self::table( 'requests' ) . " SET status='rejected',confirmation=%s,updated_at=%s WHERE id=%d", sanitize_text_field( $reason ), current_time( 'mysql' ), $id ) );
		} );
	}

	public static function balance( $uid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT balance,reserved FROM ' . self::table( 'balances' ) . ' WHERE user_id=%d AND currency=%s', $uid, self::currency() ), ARRAY_A );
		return $row ? array_map( 'intval', $row ) : array( 'balance' => 0, 'reserved' => 0 );
	}
	public static function notify_manager( $subject, $message ) {
		$to = get_option( OLR_Account_Hub::OPTION_NOTIFICATION_EMAIL ) ?: get_option( 'admin_email' );
		if ( is_email( $to ) ) { wp_mail( $to, '[Off Label] ' . $subject, $message . "\n\n" . admin_url( 'admin.php?page=olr-affiliate-management' ) ); }
	}
	/** Must execute inside atomic(). Deltas and reservations share the same ledger. */
	public static function ledger( $uid, $delta, $reserved_delta, $key, $kind, $order_id = 0, $request_id = 0 ) {
		global $wpdb;
		if ( ! self::$in_transaction ) { throw new LogicException( 'Ledger writes require a transaction.' ); }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'ledger' ) . ' WHERE operation_key=%s', $key ), ARRAY_A );
		if ( $existing ) {
			if ( (int) $existing['user_id'] !== (int) $uid || (int) $existing['delta'] !== $delta || (int) $existing['reserved_delta'] !== $reserved_delta ) { throw new RuntimeException( 'A conflicting credit operation needs review.' ); }
			return;
		}
		self::query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'balances' ) . ' (user_id,currency,balance,reserved) VALUES (%d,%s,0,0)', $uid, self::currency() ) );
		$current = self::balance( $uid );
		$balance = $current['balance'] + $delta;
		$reserved = $current['reserved'] + $reserved_delta;
		if ( $balance < 0 || $reserved < 0 || $reserved > $balance ) { throw new RuntimeException( 'Your available store credit changed. Please refresh checkout.' ); }
		self::query( $wpdb->prepare( 'UPDATE ' . self::table( 'balances' ) . ' SET balance=%d,reserved=%d WHERE user_id=%d AND currency=%s', $balance, $reserved, $uid, self::currency() ) );
		self::insert( 'ledger', array( 'operation_key' => $key, 'user_id' => $uid, 'currency' => self::currency(), 'delta' => $delta, 'reserved_delta' => $reserved_delta, 'kind' => $kind, 'order_id' => $order_id, 'request_id' => $request_id, 'created_at' => current_time( 'mysql' ) ) );
	}

	/** Explicit deployment configuration; never infer a publicly reachable uploads directory. */
	public static function storage() {
		$missing = array();
		if ( ! defined( 'OLR_AFFILIATE_PRIVATE_DIR' ) ) { $missing[] = 'OLR_AFFILIATE_PRIVATE_DIR'; }
		if ( ! defined( 'OLR_AFFILIATE_W9_KEY_FILE' ) ) { $missing[] = 'OLR_AFFILIATE_W9_KEY_FILE'; }
		if ( $missing ) { throw new RuntimeException( 'Private W-9 setup is incomplete: define ' . implode( ' and ', $missing ) . ' in wp-config.php, pointing to host-provided locations outside the public web root.' ); }
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) { throw new RuntimeException( 'Private W-9 uploads require the PHP Sodium extension. Ask the host to enable it.' ); }
		if ( ! class_exists( 'finfo' ) || wp_max_upload_size() < 10 * 1024 * 1024 ) { throw new RuntimeException( 'Enable PHP Fileinfo and a server upload limit of at least 10 MB (post limit at least 12 MB).' ); }
		$dir = realpath( OLR_AFFILIATE_PRIVATE_DIR );
		$keyfile = realpath( OLR_AFFILIATE_W9_KEY_FILE );
		$root = realpath( ABSPATH );
		$webroot = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : $root;
		if ( ! $dir || ! $keyfile || ! is_dir( $dir ) || ! is_writable( $dir ) || ! is_file( $keyfile ) || ! is_readable( $keyfile ) ) { throw new RuntimeException( 'Private W-9 storage or key file is unavailable.' ); }
		foreach ( array_filter( array( $root, $webroot ) ) as $public ) {
			$public = strtolower( str_replace( '\\', '/', $public ) );
			foreach ( array( $dir, $keyfile ) as $path ) {
				$path = strtolower( str_replace( '\\', '/', $path ) );
				if ( $path === $public || 0 === strpos( $path, rtrim( $public, '/' ) . '/' ) ) { throw new RuntimeException( 'W-9 storage and key file must be outside the public web root.' ); }
			}
		}
		$key = base64_decode( trim( file_get_contents( $keyfile ) ), true );
		if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) { throw new RuntimeException( 'The W-9 encryption key file is invalid.' ); }
		return array( $dir, $key );
	}
	public static function upload( $uid, $file, $attested ) {
		if ( ! self::active( $uid ) || ! $attested ) { throw new RuntimeException( 'Confirm this is your completed, signed W-9.' ); }
		list( $dir, $key ) = self::storage();
		if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) { throw new RuntimeException( 'Upload a signed PDF, up to 10 MB.' ); }
		$size = filesize( $file['tmp_name'] );
		$mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
		if ( ! $size || $size > 10 * 1024 * 1024 || 'pdf' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) || 'application/pdf' !== $mime ) { throw new RuntimeException( 'Only PDF files up to 10 MB are accepted.' ); }
		$plain = file_get_contents( $file['tmp_name'] );
		if ( 0 !== strpos( $plain, '%PDF-' ) || false === strpos( substr( $plain, -2048 ), '%%EOF' ) || preg_match( '~/JavaScript|/JS\b|/Launch|/EmbeddedFile~i', $plain ) ) { throw new RuntimeException( 'Upload a standard PDF without scripts or embedded files.' ); }
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$encrypted = $nonce . sodium_crypto_secretbox( $plain, $nonce, $key );
		sodium_memzero( $plain );
		$file_key = bin2hex( random_bytes( 24 ) );
		$path = $dir . DIRECTORY_SEPARATOR . $file_key . '.bin';
		if ( strlen( $encrypted ) !== file_put_contents( $path, $encrypted, LOCK_EX ) ) { throw new RuntimeException( 'The document could not be stored.' ); }
		chmod( $path, 0600 );
		try {
			self::atomic( function () use ( $uid, $file_key ) {
				self::insert( 'documents', array( 'user_id' => $uid, 'file_key' => $file_key, 'status' => 'pending', 'note' => '', 'created_at' => current_time( 'mysql' ) ) );
			} );
		} catch ( Throwable $e ) { unlink( $path ); throw $e; }
		self::notify_manager( 'W-9 ready for review', 'A member uploaded a W-9. Review it using the protected administrator download. The document is not attached to this message.' );
	}
	public static function review_document( $id, $approved, $note ) {
		self::atomic( function () use ( $id, $approved, $note ) {
			global $wpdb;
			$doc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'documents' ) . ' WHERE id=%d', $id ), ARRAY_A );
			if ( ! $doc || (int) self::latest_document( $doc['user_id'] )['id'] !== $id ) { throw new RuntimeException( 'This document was replaced. Review the latest upload.' ); }
			self::query( $wpdb->prepare( 'UPDATE ' . self::table( 'documents' ) . ' SET status=%s,reviewer=%d,note=%s,reviewed_at=%s WHERE id=%d', $approved ? 'approved' : 'needs_replacement', get_current_user_id(), sanitize_textarea_field( $note ), current_time( 'mysql' ), $id ) );
		} );
	}
}
