<?php
/** Private disposable fixture worker. Excluded from the release ZIP. */
$fixture = getenv( 'OLR_TEST_WP' );
if ( ! $fixture ) { http_response_code( 403 ); exit; }
$root = dirname( $fixture );
if ( PHP_SAPI !== 'cli' && ( ( $_SERVER['REMOTE_ADDR'] ?? '' ) !== '127.0.0.1' || ! hash_equals( trim( file_get_contents( $root . '/http.token' ) ), $_SERVER['HTTP_X_OLR_QA'] ?? '' ) ) ) { http_response_code( 403 ); exit; }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8337';
$_SERVER['REQUEST_URI'] = '/';
require $fixture . '/wp-load.php';
if ( DB_NAME !== 'olr_affiliate_120_test' || DB_HOST !== '127.0.0.1:33317' ) { http_response_code( 403 ); exit; }
add_filter( 'pre_wp_mail', '__return_true', -1000 );
require dirname( __DIR__ ) . '/off-label-account-hub.php';
$context = json_decode( file_get_contents( $root . '/context.json' ), true );
wp_set_current_user( $context['user_id'] );
$mode = PHP_SAPI === 'cli' ? ( $argv[1] ?? '' ) : ( $_GET['mode'] ?? '' );
try {
	if ( 0 === strpos( $mode, 'choose-race-' ) ) {
		$chooser = $context['code_race_users'][ (int) substr( $mode, -1 ) ];
		wp_set_current_user( $chooser );
		echo wp_json_encode( array( 'ok' => true, 'code' => OLR_Affiliate_Coupons::choose( $chooser, $context['code_race_name'] ) ) );
	} elseif ( 'choose-csrf' === $mode ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'olr_aff_action' => 'choose_code', 'olr_aff_nonce' => 'invalid', 'coupon_code' => 'should-not-save' );
		register_shutdown_function( function () { echo wp_json_encode( get_transient( 'olr_aff_notice_' . get_current_user_id() ) ); } );
		OLR_Affiliate_Flows::dispatch();
	} elseif ( 'coupon-admin-denied' === $mode ) {
		add_filter( 'wp_die_handler', function () { return function () { throw new RuntimeException( 'Administrator access required.' ); }; } );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'olr_aff_action' => 'coupon_default', 'discount_percent' => 99, 'olr_aff_nonce' => wp_create_nonce( 'olr_aff_coupon_default' ) );
		OLR_Affiliate_Flows::dispatch();
	} elseif ( 'repair-code' === $mode ) {
		echo wp_json_encode( array( 'ok' => true, 'code' => OLR_Affiliate_Service::prepare_code( $context['activation_uid'] ) ) );
	} elseif ( 'code-csrf' === $mode ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['olr_aff_nonce'] = 'invalid';
		add_filter( 'wp_die_handler', function () { return function ( $message ) { throw new RuntimeException( 'Rejected nonce' ); }; } );
		OLR_Affiliate_Flows::ajax_prepare_code();
	} elseif ( 'activate' === $mode ) {
		wp_set_current_user( $context['activation_uid'] );
		OLR_Affiliate_Service::activate( $context['activation_uid'], true );
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uap_affiliates WHERE uid=%d", $context['activation_uid'] ) );
		echo wp_json_encode( array( 'ok' => true, 'count' => (int) $count, 'id' => OLR_Account_Hub::affiliate_id( $context['activation_uid'] ) ) );
	} elseif ( 0 === strpos( $mode, 'spend-' ) ) {
		OLR_Store_Credit::reserve( $context['spend_ids'][ (int) substr( $mode, 6 ) ] );
		echo wp_json_encode( array( 'ok' => true ) );
	} elseif ( 'payout-race' === $mode ) {
		$id = OLR_Affiliate_Service::request( $context['race_uid'], 'store_credit', wp_generate_uuid4() );
		echo wp_json_encode( array( 'ok' => true, 'id' => $id ) );
	} elseif ( 'concurrent' === $mode ) {
		$id = OLR_Affiliate_Service::request( $context['user_id'], 'store_credit', $context['key'] );
		echo wp_json_encode( array( 'ok' => true, 'id' => $id, 'balance' => OLR_Affiliate_Service::balance( $context['user_id'] ) ) );
	} elseif ( 'upload' === $mode ) {
		OLR_Affiliate_Service::upload( $context['user_id'], $_FILES['w9'] ?? array(), '1' === ( $_POST['signed'] ?? '' ) );
		echo wp_json_encode( array( 'ok' => true, 'document' => OLR_Affiliate_Service::latest_document( $context['user_id'] )['id'] ) );
	} elseif ( 'download' === $mode ) {
		if ( '1' === ( $_GET['admin'] ?? '' ) ) { wp_set_current_user( $context['admin_id'] ); }
		$id = (int) OLR_Affiliate_Service::latest_document( $context['user_id'] )['id'];
		$_GET['olr_w9_download'] = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'olr_w9_download_' . $id );
		OLR_Affiliate_Flows::dispatch();
	} elseif ( 'security' === $mode ) {
		// Turn WordPress's terminal HTTP errors into inspectable exceptions for CLI tests.
		add_filter( 'wp_die_handler', function () { return function ( $message ) { throw new RuntimeException( strip_tags( $message ) ); }; } );
		$cases = array( 'uap_make_wp_user_affiliate_from_public', 'uap_admin_update_payout_status', 'uap_ajax_payments_change_status', 'uap_delete_wallet_item_via_ajax', 'olr_submit_affiliate_application' );
		$count = 0;
		foreach ( $cases as $case ) {
			$_REQUEST = array( 'action' => $case );
			try { OLR_Affiliate_Flows::guard_native_routes(); throw new LogicException( 'Unguarded route: ' . $case ); }
			catch ( RuntimeException $e ) { ++$count; }
		}
		echo wp_json_encode( array( 'ok' => true, 'guarded_routes' => $count ) );
	} elseif ( 'csrf' === $mode ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'olr_aff_action' => 'store_credit', 'olr_aff_nonce' => 'invalid', 'operation_key' => wp_generate_uuid4() );
		register_shutdown_function( function () use ( $context ) { echo wp_json_encode( get_transient( 'olr_aff_notice_' . $context['user_id'] ) ); } );
		OLR_Affiliate_Flows::dispatch();
	}
} catch ( Throwable $e ) {
	http_response_code( 400 );
	echo wp_json_encode( array( 'ok' => false, 'message' => $e->getMessage() ) );
}
