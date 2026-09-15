<?php
/** Post-tax credit tender for the site's classic WooCommerce checkout. */
defined( 'ABSPATH' ) || exit;

final class OLR_Store_Credit {
	public static function boot() {
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_choice' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'posted_choice' ), 1 );
		add_filter( 'woocommerce_calculated_total', array( __CLASS__, 'total' ), PHP_INT_MAX, 2 );
		// Stay inside #payment, so WooCommerce fragment refreshes replace the control.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'choice' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'total_row' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 100, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'reserve' ), 1 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'reserve' ), 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'payment_complete' ), 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 1, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'refunded' ), 20, 2 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_totals' ), 20, 3 );
	}
	public static function update_choice( $serialized ) {
		parse_str( $serialized, $data );
		if ( WC()->session ) { WC()->session->set( 'olr_credit_use', is_user_logged_in() && isset( $data['olr_use_credit'] ) && '1' === $data['olr_use_credit'] ); }
	}
	public static function posted_choice() {
		if ( WC()->session ) {
			WC()->session->set( 'olr_credit_use', is_user_logged_in() && '1' === OLR_Affiliate_Flows::input( 'olr_use_credit' ) );
			WC()->cart->calculate_totals();
		}
	}
	public static function credit_to_apply( $gross, $balance, $reserved ) {
		return max( 0, min( $gross, $balance - $reserved ) );
	}
	public static function total( $total, $cart ) {
		if ( ! WC()->session ) { return $total; }
		WC()->session->set( 'olr_credit_applied', 0 );
		if ( ! is_user_logged_in() || ! WC()->session->get( 'olr_credit_use' ) || OLR_Affiliate_Service::SCHEMA !== get_option( 'olr_aff_schema' ) || 2 !== (int) wc_get_price_decimals() ) { return $total; }
		$balance = OLR_Affiliate_Service::balance( get_current_user_id() );
		$applied = self::credit_to_apply( OLR_Affiliate_Service::cents( $total ), $balance['balance'], $balance['reserved'] );
		WC()->session->set( 'olr_credit_applied', $applied );
		return max( 0, $total - $applied / 100 );
	}
	public static function choice() {
		if ( ! is_user_logged_in() || ! WC()->session || OLR_Affiliate_Service::SCHEMA !== get_option( 'olr_aff_schema' ) ) { return; }
		$balance = OLR_Affiliate_Service::balance( get_current_user_id() );
		$available = $balance['balance'] - $balance['reserved'];
		if ( $available <= 0 ) { return; }
		echo '<div class="olr-credit-checkout"><label><input type="checkbox" id="olr-use-credit" name="olr_use_credit" value="1" ' . checked( (bool) WC()->session->get( 'olr_credit_use' ), true, false ) . '> Use store credit (' . wp_kses_post( OLR_Affiliate_Service::money( $available ) ) . ' available)</label><p>Credit is applied after discounts, shipping and taxes. Pay any remaining amount using the options below.</p></div>';
	}
	public static function total_row() {
		$applied = WC()->session ? (int) WC()->session->get( 'olr_credit_applied' ) : 0;
		if ( $applied ) {
			$gross = OLR_Affiliate_Service::cents( WC()->cart->get_total( 'edit' ) ) + $applied;
			echo '<tr class="olr-credit-gross"><th>Order total before credit</th><td>' . wp_kses_post( OLR_Affiliate_Service::money( $gross ) ) . '</td></tr><tr class="olr-credit-applied"><th>Store credit payment</th><td>−' . wp_kses_post( OLR_Affiliate_Service::money( $applied ) ) . '</td></tr>';
		}
	}
	public static function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_cart() || is_page( 'cart' ) ) || ! is_user_logged_in() ) { return; }
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', 'jQuery(function($){$(document.body).on("change","#olr-use-credit",function(){$(document.body).trigger("update_checkout");});});' );
		wp_enqueue_style( 'olr-store-credit', plugins_url( 'assets/store-credit.css', dirname( __DIR__ ) . '/off-label-account-hub.php' ), array(), OLR_Account_Hub::VERSION );
	}
	public static function order_meta( $order, $data ) {
		$credit = WC()->session ? (int) WC()->session->get( 'olr_credit_applied' ) : 0;
		if ( ! $credit || ! is_user_logged_in() ) { return; }
		if ( (int) $order->get_customer_id() !== get_current_user_id() ) { throw new RuntimeException( 'Store credit belongs to the signed-in account.' ); }
		$order->update_meta_data( '_olr_credit_amount', $credit );
		$order->update_meta_data( '_olr_credit_gross_total', OLR_Affiliate_Service::cents( $order->get_total() ) + $credit );
		$order->update_meta_data( '_olr_credit_currency', $order->get_currency() );
		if ( OLR_Affiliate_Service::cents( $order->get_total() ) === 0 ) { $order->set_payment_method( 'olr_store_credit' ); $order->set_payment_method_title( 'Store credit' ); }
	}
	private static function order( $order ) { return is_numeric( $order ) ? wc_get_order( $order ) : $order; }
	public static function order_state( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(reserved_delta),0) AS held, COALESCE(SUM(CASE WHEN kind=\'order_capture\' THEN -delta ELSE 0 END),0) AS spent, COALESCE(SUM(CASE WHEN kind=\'order_refund\' THEN delta ELSE 0 END),0) AS refunded,COUNT(*) AS entries FROM ' . OLR_Affiliate_Service::table( 'ledger' ) . ' WHERE order_id=%d', $id ), ARRAY_A );
		return array_map( 'intval', $row ?: array( 'held' => 0, 'spent' => 0, 'refunded' => 0, 'entries' => 0 ) );
	}
	private static function check_order( $order ) {
		if ( ! $order || ! $order->get_customer_id() || $order->get_currency() !== OLR_Affiliate_Service::currency() || $order->get_meta( '_olr_credit_currency' ) !== $order->get_currency() ) { throw new RuntimeException( 'Store-credit order ownership or currency needs review.' ); }
	}
	public static function reserve( $order ) {
		$order = self::order( $order );
		if ( ! $order || ! (int) $order->get_meta( '_olr_credit_amount' ) ) { return; }
		self::check_order( $order );
		OLR_Affiliate_Service::atomic( function () use ( $order ) {
			$state = self::order_state( $order->get_id() );
			$amount = (int) $order->get_meta( '_olr_credit_amount' );
			if ( $state['spent'] === $amount || $state['held'] === $amount ) { return; }
			if ( $state['held'] || $state['spent'] ) { throw new RuntimeException( 'The order credit reservation needs review.' ); }
			OLR_Affiliate_Service::ledger( $order->get_customer_id(), 0, $amount, 'reserve-' . $order->get_id() . '-' . $state['entries'], 'order_reserve', $order->get_id() );
		} );
	}
	public static function payment_complete( $id ) {
		$order = wc_get_order( $id );
		if ( ! $order || ! (int) $order->get_meta( '_olr_credit_amount' ) ) { return; }
		try { self::capture( $order ); }
		catch ( Throwable $e ) {
			$order->update_status( 'on-hold', 'Store credit could not be finalized. Do not fulfill until the credit ledger is reconciled.' );
			$order->add_order_note( 'OLR credit capture requires administrator review. No additional cash payment was attempted.' );
		}
	}
	public static function capture( $order ) {
		self::check_order( $order );
		OLR_Affiliate_Service::atomic( function () use ( $order ) {
			$state = self::order_state( $order->get_id() );
			$amount = (int) $order->get_meta( '_olr_credit_amount' );
			if ( $state['spent'] === $amount ) { return; }
			self::reserve( $order );
			OLR_Affiliate_Service::ledger( $order->get_customer_id(), -$amount, -$amount, 'capture-' . $order->get_id(), 'order_capture', $order->get_id() );
		} );
	}
	public static function status_changed( $id, $from, $to, $order ) {
		if ( ! (int) $order->get_meta( '_olr_credit_amount' ) ) { return; }
		if ( in_array( $to, array( 'processing', 'completed' ), true ) ) { self::payment_complete( $id ); return; }
		try {
			if ( in_array( $to, array( 'failed', 'cancelled' ), true ) ) {
				OLR_Affiliate_Service::atomic( function () use ( $order ) {
					$state = self::order_state( $order->get_id() );
					if ( $state['held'] ) { OLR_Affiliate_Service::ledger( $order->get_customer_id(), 0, -$state['held'], 'release-' . $order->get_id() . '-' . $state['entries'], 'order_release', $order->get_id() ); }
				} );
			}
			if ( 'refunded' === $to ) { self::return_credit( $order, (int) $order->get_meta( '_olr_credit_amount' ), 'full-refund-' . $id, true ); }
		} catch ( Throwable $e ) { $order->add_order_note( 'OLR store-credit reconciliation requires administrator review. Check Affiliate Management before adjusting this order.' ); }
	}
	/** Partial cash refunds return the same proportion of the original credit tender. */
	public static function proportional_refund( $spent, $cash_total, $cash_refunded ) {
		return $cash_total > 0 ? min( $spent, (int) round( $spent * min( $cash_total, $cash_refunded ) / $cash_total ) ) : 0;
	}
	public static function refunded( $id, $refund_id ) {
		$order = wc_get_order( $id );
		if ( ! $order || ! (int) $order->get_meta( '_olr_credit_amount' ) ) { return; }
		$target = self::proportional_refund( (int) $order->get_meta( '_olr_credit_amount' ), OLR_Affiliate_Service::cents( $order->get_total() ), OLR_Affiliate_Service::cents( $order->get_total_refunded() ) );
		try { self::return_credit( $order, $target, 'wc-refund-' . $refund_id, true ); }
		catch ( Throwable $e ) { $order->add_order_note( 'Cash refund recorded; OLR store-credit refund needs review in Affiliate Management.' ); }
	}
	private static function return_credit( $order, $amount, $key, $cumulative = false ) {
		self::check_order( $order );
		return OLR_Affiliate_Service::atomic( function () use ( $order, $amount, $key, $cumulative ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . OLR_Affiliate_Service::table( 'ledger' ) . ' WHERE operation_key=%s', $key ) ) ) { return; }
			$state = self::order_state( $order->get_id() );
			$delta = $cumulative ? max( 0, $amount - $state['refunded'] ) : $amount;
			if ( $delta < 0 || $delta > $state['spent'] - $state['refunded'] ) { throw new RuntimeException( 'Refund exceeds the remaining credit spent on this order.' ); }
			if ( $delta ) { OLR_Affiliate_Service::ledger( $order->get_customer_id(), $delta, 0, $key, 'order_refund', $order->get_id() ); }
		} );
	}
	public static function admin_refund( $id, $amount, $key ) {
		if ( ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $amount ) || OLR_Affiliate_Service::cents( $amount ) <= 0 || ! preg_match( '/^[a-f0-9-]{36}$/i', $key ) ) { throw new RuntimeException( 'Enter a positive credit amount with up to two decimal places.' ); }
		$order = wc_get_order( $id );
		self::return_credit( $order, OLR_Affiliate_Service::cents( $amount ), 'admin-refund-' . $key );
		$order->add_order_note( 'Store-credit refund recorded by administrator #' . get_current_user_id() . ': ' . strip_tags( OLR_Affiliate_Service::money( OLR_Affiliate_Service::cents( $amount ) ) ) );
	}
	public static function order_totals( $rows, $order, $tax_display ) {
		$amount = (int) $order->get_meta( '_olr_credit_amount' );
		if ( ! $amount ) { return $rows; }
		$output = array();
		foreach ( $rows as $key => $row ) {
			if ( 'order_total' === $key ) {
				$output['olr_gross'] = array( 'label' => 'Order total before credit:', 'value' => wc_price( (int) $order->get_meta( '_olr_credit_gross_total' ) / 100, array( 'currency' => $order->get_currency() ) ) );
				$output['olr_credit'] = array( 'label' => 'Store credit payment:', 'value' => '−' . wc_price( $amount / 100, array( 'currency' => $order->get_currency() ) ) );
				$row['label'] = 'Remaining payment:';
			}
			$output[ $key ] = $row;
		}
		return $output;
	}
}
