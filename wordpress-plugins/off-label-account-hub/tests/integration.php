<?php
/** Run only against the disposable local fixture, never a customer database.
 * OLR_TEST_WP=/path/to/fixture/wordpress php tests/integration.php
 */
$fixture = getenv( 'OLR_TEST_WP' );
if ( ! $fixture || ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Set OLR_TEST_WP to the disposable test installation.' ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8337';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $fixture . '/wp-load.php';
if ( DB_NAME !== 'olr_affiliate_120_test' || DB_HOST !== '127.0.0.1:33317' ) { throw new RuntimeException( 'Refusing to modify a non-test database.' ); }
add_filter( 'pre_wp_mail', '__return_true', -1000 );
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
WC_Install::install();
global $wpdb, $indeed_db;
if ( ! is_object( $indeed_db ) ) { $indeed_db = new Uap_Database(); }
$indeed_db->create_tables();
require dirname( __DIR__ ) . '/off-label-account-hub.php';
OLR_Affiliate_Service::install();
update_option( 'olr_aff_payouts_enabled', false );
OLR_Affiliate_Service::prepare_tables();
update_option( 'olr_affiliate_terms_url', 'https://example.test/affiliate-terms/' );
update_option( 'uap_currency', 'USD' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'olr_affiliate_customer_discount', '20.00' );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
$rank_data = $indeed_db->get_rank( 0 );
$rank_data['label'] = 'QA default commission';
$rank_data['slug'] = 'qa-default-' . bin2hex( random_bytes( 4 ) );
$rank_data['status'] = 1;
$rank_id = (int) $indeed_db->rank_save_update( $rank_data );
update_option( 'uap_register_new_user_rank', $rank_id );

$checks = 0;
function verify( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$GLOBALS['checks']; echo 'PASS: ' . $message . "\n";
}
function refuses( $fn, $message ) {
	try { $fn(); } catch ( RuntimeException $e ) { verify( true, $message ); return; }
	throw new RuntimeException( 'FAIL: expected refusal: ' . $message );
}
function member() {
	$name = 'qa_' . bin2hex( random_bytes( 5 ) );
	$id = wp_create_user( $name, wp_generate_password( 32 ), $name . '@example.invalid' );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	wp_set_current_user( $id );
	update_user_meta( $id, 'account_status', 'approved' );
	return $id;
}
function referral( $uid, $amount = '50.00', $age = 31, $status = 2, $payment = 0, $currency = 'USD' ) {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'uap_referrals', array( 'affiliate_id' => OLR_Account_Hub::affiliate_id( $uid ), 'source' => 'woo', 'reference' => 'qa-' . wp_generate_uuid4(), 'amount' => $amount, 'currency' => $currency, 'status' => $status, 'payment' => $payment, 'date' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $age * DAY_IN_SECONDS ) ) );
	if ( $wpdb->last_error ) { throw new RuntimeException( $wpdb->last_error ); }
	return (int) $wpdb->insert_id;
}
function document( $uid, $status ) {
	return OLR_Affiliate_Service::insert( 'documents', array( 'user_id' => $uid, 'file_key' => bin2hex( random_bytes( 24 ) ), 'status' => $status, 'note' => '', 'created_at' => current_time( 'mysql' ) ) );
}
function credit_order( $uid, $credit, $cash ) {
	$order = wc_create_order( array( 'customer_id' => $uid ) );
	$order->set_currency( 'USD' ); $order->set_total( $cash / 100 );
	$order->update_meta_data( '_olr_credit_amount', $credit );
	$order->update_meta_data( '_olr_credit_gross_total', $cash + $credit );
	$order->update_meta_data( '_olr_credit_currency', 'USD' );
	$order->save(); return $order;
}

verify( ! OLR_Affiliate_Service::readiness(), 'UAP 9.7.7 adapter, private key/storage and transactional tables are ready' );
update_option( 'olr_aff_payouts_enabled', true );
$uid = member();
$roles = get_userdata( $uid )->roles;
update_user_meta( $uid, '_olr_affiliate_application_status', 'pending' );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::activate( $uid, false ); }, 'terms acceptance required' );
OLR_Affiliate_Service::activate( $uid, true );
verify( OLR_Affiliate_Service::active( $uid ), 'pending applicant activates immediately' );
verify( get_userdata( $uid )->roles === $roles, 'activation preserves WordPress member roles' );
verify( (int) $indeed_db->get_affiliate_rank( 0, $uid ) === $rank_id, 'configured default rank is assigned' );
$affiliate = OLR_Account_Hub::affiliate_id( $uid ); $code = OLR_Affiliate_Service::coupon_code( $uid );
verify( $code && $indeed_db->get_affiliate_for_coupon_code( strtolower( $code ) ) == $affiliate, 'real WooCommerce coupon mapped to UAP affiliate' );
update_user_meta( $uid, 'olr_affiliate_coupon_code', 'stale-legacy-code' );
verify( $code === OLR_Affiliate_Service::coupon_code( $uid ), 'live UAP coupon assignment takes precedence over stale display metadata' );
OLR_Affiliate_Service::activate( $uid, true );
verify( $affiliate === OLR_Account_Hub::affiliate_id( $uid ) && $code === OLR_Affiliate_Service::coupon_code( $uid ), 'repeated activation preserves affiliate and code' );
verify( 'pending' === get_user_meta( $uid, '_olr_affiliate_application_status', true ), 'legacy application history is preserved' );
$coupon = new WC_Coupon( $code );
verify( 'percent' === $coupon->get_discount_type() && 20.0 === (float) $coupon->get_amount(), 'new code gives advertised 20% offer' );
OLR_Affiliate_Service::set_block( $uid, true );
verify( ! OLR_Affiliate_Service::active( $uid ) && ! $indeed_db->is_user_an_active_affiliate( $uid ), 'block disables hub and native UAP affiliate status' );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::activate( $uid, true ); }, 'blocked affiliate cannot reactivate' );
OLR_Affiliate_Service::set_block( $uid, false );
verify( OLR_Affiliate_Service::active( $uid ) && get_userdata( $uid )->roles === $roles, 'explicit restore preserves roles and affiliate history' );
$rejected = member(); update_user_meta( $rejected, '_olr_affiliate_application_status', 'rejected' );
refuses( function () use ( $rejected ) { OLR_Affiliate_Service::activate( $rejected, true ); }, 'legacy rejected applicant stays blocked' );
$admin = get_user_by( 'login', 'fixture_admin' );
refuses( function () use ( $admin ) { OLR_Affiliate_Service::activate( $admin->ID, true ); }, 'administrators cannot enroll' );
wp_set_current_user( $uid );
$saved_db = $indeed_db; $indeed_db = null;
refuses( function () use ( $uid ) { OLR_Affiliate_Service::activate( $uid, true ); }, 'missing UAP fails without modifying affiliate state' );
$indeed_db = $saved_db;

$boundary = array( 'affiliate_id' => $affiliate, 'amount' => '50.00', 'status' => 2, 'payment' => 0, 'currency' => 'USD', 'date' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) );
verify( OLR_Affiliate_Service::referral_eligible( $boundary, $affiliate ), 'commission exactly 30 days old is eligible' );
$boundary['date'] = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS + 60 );
verify( ! OLR_Affiliate_Service::referral_eligible( $boundary, $affiliate ), 'commission inside hold is excluded' );
referral( $uid, '49.99' );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::request( $uid, 'store_credit', wp_generate_uuid4() ); }, '$49.99 cannot convert' );
referral( $uid, '0.01' ); referral( $uid, '90.00', 1 ); referral( $uid, '90.00', 31, 0 ); referral( $uid, '90.00', 31, 2, 2 ); referral( $uid, '90.00', 31, 2, 0, 'EUR' );
$key = wp_generate_uuid4();
$request = OLR_Affiliate_Service::request( $uid, 'store_credit', $key );
verify( OLR_Affiliate_Service::balance( $uid )['balance'] === 5000, 'exactly $50 converts instantly without a W-9; invalid referrals excluded' );
verify( OLR_Affiliate_Service::request( $uid, 'store_credit', $key ) === $request && OLR_Affiliate_Service::balance( $uid )['balance'] === 5000, 'request replay cannot duplicate credit' );
verify( ! OLR_Affiliate_Service::available_referrals( $uid ), 'settled or ineligible commissions cannot be requested again' );
$other = member();
refuses( function () use ( $other, $key ) { OLR_Affiliate_Service::request( $other, 'store_credit', $key ); }, 'another user cannot replay a payout key' );
wp_set_current_user( $uid );
referral( $uid );
$zelle = array( 'name' => 'Fixture Member', 'destination' => 'fixture@example.invalid' );
refuses( function () use ( $uid, $zelle ) { OLR_Affiliate_Service::request( $uid, 'zelle', wp_generate_uuid4(), $zelle ); }, 'Zelle refused without W-9' );
$doc = document( $uid, 'pending' );
refuses( function () use ( $uid, $zelle ) { OLR_Affiliate_Service::request( $uid, 'zelle', wp_generate_uuid4(), $zelle ); }, 'pending W-9 cannot unlock Zelle' );
OLR_Affiliate_Service::review_document( $doc, true, 'Complete' );
$zid = OLR_Affiliate_Service::request( $uid, 'zelle', wp_generate_uuid4(), $zelle );
verify( ! OLR_Affiliate_Service::available_referrals( $uid ), 'Zelle request reserves commissions against store-credit conversion' );
document( $uid, 'pending' );
$queued = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OLR_Affiliate_Service::table( 'requests' ) . ' WHERE id=%d', $zid ), ARRAY_A );
refuses( function () use ( $queued ) { OLR_Affiliate_Service::validate_pending( $queued ); }, 'pre-send queue review flags replacement W-9 before external payment' );
refuses( function () use ( $zid ) { OLR_Affiliate_Service::complete( $zid, 'fixture-confirmation' ); }, 'replacement W-9 blocks pending Zelle completion' );
refuses( function () use ( $doc ) { OLR_Affiliate_Service::review_document( $doc, true, '' ); }, 'superseded W-9 cannot be approved' );
OLR_Affiliate_Service::reject( $zid, 'Fixture cancellation' );
verify( count( OLR_Affiliate_Service::available_referrals( $uid ) ) === 1, 'rejected request releases reserved commissions' );
$latest = OLR_Affiliate_Service::latest_document( $uid );
OLR_Affiliate_Service::review_document( (int) $latest['id'], true, '' );
$zid = OLR_Affiliate_Service::request( $uid, 'zelle', wp_generate_uuid4(), $zelle );
$held = $wpdb->get_var( $wpdb->prepare( 'SELECT referral_id FROM ' . OLR_Affiliate_Service::table( 'reservations' ) . ' WHERE request_id=%d', $zid ) );
$wpdb->update( $wpdb->prefix . 'uap_referrals', array( 'status' => 0 ), array( 'id' => $held ) );
refuses( function () use ( $zid ) { OLR_Affiliate_Service::complete( $zid, 'fixture-confirmation' ); }, 'refunded reserved referral prevents payout' );
OLR_Affiliate_Service::reject( $zid, 'Refunded' );
referral( $uid );
$zid = OLR_Affiliate_Service::request( $uid, 'zelle', wp_generate_uuid4(), $zelle );
OLR_Affiliate_Service::complete( $zid, 'fixture-confirmation' );
OLR_Affiliate_Service::complete( $zid, 'fixture-confirmation' );
$paid_id = $wpdb->get_var( $wpdb->prepare( 'SELECT payment_id FROM ' . OLR_Affiliate_Service::table( 'requests' ) . ' WHERE id=%d', $zid ) );
$snapshot = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT payment_details FROM {$wpdb->prefix}uap_payments WHERE id=%d", $paid_id ) ) );
verify( $snapshot['recipient'] === $zelle && $snapshot['olr_method'] === 'zelle', 'UAP settlement preserves the frozen Zelle recipient snapshot' );
verify( OLR_Affiliate_Service::balance( $uid )['balance'] === 5000, 'Zelle completion and replay do not issue store credit' );

$before = OLR_Affiliate_Service::balance( $uid );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::atomic( function () use ( $uid ) { OLR_Affiliate_Service::ledger( $uid, 5000, 0, wp_generate_uuid4(), 'test'); throw new RuntimeException( 'Injected failure' ); } ); }, 'injected failure rolls transaction back' );
verify( OLR_Affiliate_Service::balance( $uid ) === $before, 'rollback preserves exact balance' );
verify( OLR_Store_Credit::credit_to_apply( 8000, 5000, 1000 ) === 4000 && OLR_Store_Credit::credit_to_apply( 3000, 5000, 0 ) === 3000, 'partial and full tender capped at spendable balance and gross total' );
$order = credit_order( $uid, 3000, 2000 );
OLR_Store_Credit::reserve( $order ); OLR_Store_Credit::reserve( $order );
verify( OLR_Affiliate_Service::balance( $uid )['reserved'] === 3000, 'HPOS order reserves credit once' );
$competing = credit_order( $uid, 3000, 2000 );
refuses( function () use ( $competing ) { OLR_Store_Credit::reserve( $competing ); }, 'competing checkout cannot overspend reserved credit' );
OLR_Store_Credit::status_changed( $order->get_id(), 'pending', 'failed', $order );
verify( OLR_Affiliate_Service::balance( $uid )['reserved'] === 0, 'failed payment releases credit' );
OLR_Store_Credit::reserve( $order ); OLR_Store_Credit::capture( $order ); OLR_Store_Credit::capture( $order );
verify( OLR_Affiliate_Service::balance( $uid ) === array( 'balance' => 2000, 'reserved' => 0 ), 'payment retry recaptures once and preserves unused balance' );
$refund_key = wp_generate_uuid4();
OLR_Store_Credit::admin_refund( $order->get_id(), '10.00', $refund_key );
OLR_Store_Credit::admin_refund( $order->get_id(), '10.00', $refund_key );
verify( OLR_Affiliate_Service::balance( $uid )['balance'] === 3000, 'partial credit refund is retry-safe' );
refuses( function () use ( $order ) { OLR_Store_Credit::admin_refund( $order->get_id(), '20.01', wp_generate_uuid4() ); }, 'credit refund cannot exceed original credit used' );
OLR_Store_Credit::status_changed( $order->get_id(), 'completed', 'refunded', $order );
verify( OLR_Affiliate_Service::balance( $uid )['balance'] === 5000, 'full refund restores only remaining credit portion' );
verify( OLR_Store_Credit::proportional_refund( 3000, 2000, 500 ) === 750, 'partial cash refund returns proportional credit tender' );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::upload( $uid, array(), false ); }, 'unsigned/missing upload rejected' );
refuses( function () { OLR_Affiliate_Service::zelle_details( 'Fixture', '123' ); }, 'invalid Zelle destination rejected' );
verify( OLR_Affiliate_Service::zelle_details( 'Fixture', '(415) 555-0123' )['destination'] === '+14155550123', 'US Zelle mobile normalized' );
$rest = new WP_REST_Request( 'POST', '/ultimate-affiliates-pro/v1/make-user-affiliate/123' );
verify( is_wp_error( OLR_Affiliate_Flows::guard_rest( null, null, $rest ) ), 'native REST enrollment cannot bypass eligibility' );
verify( 'SELECT 0' === OLR_Affiliate_Service::insert_affiliate_guard( 'INSERT...' ), 'native enrollment SQL seam rejects unapproved origins' );
update_option( 'uap_currency', 'EUR' );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::request( $uid, 'store_credit', wp_generate_uuid4() ); }, 'currency mismatch blocks conversion' );
update_option( 'uap_currency', 'USD' );
update_option( 'olr_aff_payouts_enabled', false );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::request( $uid, 'store_credit', wp_generate_uuid4() ); }, 'disabled payouts cannot be invoked directly' );
update_option( 'olr_aff_payouts_enabled', true );
verify( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS order exercised through WooCommerce CRUD' );

// Actual cart, coupon, tax and HPOS checkout order integration.
$buyer = member();
WC()->session = new WC_Session_Handler();
WC()->customer = new WC_Customer( $buyer );
WC()->customer->set_billing_country( 'US' ); WC()->customer->set_billing_state( 'CA' );
WC()->customer->set_billing_email( get_userdata( $buyer )->user_email );
WC()->customer->set_is_vat_exempt( false );
WC()->cart = new WC_Cart();
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_prices_include_tax', 'no' ); update_option( 'woocommerce_tax_based_on', 'billing' );
$taxclass = 'qa-' . bin2hex( random_bytes( 4 ) );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10.0000', 'tax_rate_name' => 'QA tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_class' => $taxclass ) );
$product = new WC_Product_Simple(); $product->set_name( 'Synthetic QA product' ); $product->set_regular_price( '100' ); $product->set_virtual( true ); $product->set_tax_status( 'taxable' ); $product->set_tax_class( $taxclass ); $product->save();
WC()->cart->add_to_cart( $product->get_id() );
verify( WC()->cart->apply_coupon( $code ), 'new customer can apply the real referral coupon' );
WC()->cart->calculate_totals();
$gross = OLR_Affiliate_Service::cents( WC()->cart->get_total( 'edit' ) );
$tax = WC()->cart->get_total_tax();
verify( $gross === 8800 && OLR_Affiliate_Service::cents( $tax ) === 800, 'coupon calculates 20% off before 10% tax' );
OLR_Affiliate_Service::atomic( function () use ( $buyer ) { OLR_Affiliate_Service::ledger( $buyer, 5000, 0, wp_generate_uuid4(), 'test_credit' ); } );
WC()->session->set( 'olr_credit_use', true ); WC()->cart->calculate_totals();
verify( OLR_Affiliate_Service::cents( WC()->cart->get_total( 'edit' ) ) === 3800 && WC()->cart->get_total_tax() === $tax, 'real cart applies credit after tax and retains $38 cash due' );
$checkout_id = WC()->checkout()->create_order( array( 'billing_email' => get_userdata( $buyer )->user_email, 'billing_country' => 'US', 'billing_state' => 'CA', 'payment_method' => 'bacs' ) );
verify( ! is_wp_error( $checkout_id ), 'real WooCommerce checkout creates credit-funded HPOS order' );
$attributed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uap_referrals WHERE source='woo' AND reference=%s AND affiliate_id=%d", (string) $checkout_id, $affiliate ), ARRAY_A );
verify( $attributed && (float) $attributed['amount'] > 0, 'UAP attributes the real coupon order and calculates commission using configured rules' );
$checkout_order = wc_get_order( $checkout_id );
verify( (int) $checkout_order->get_meta( '_olr_credit_amount' ) === 5000 && OLR_Affiliate_Service::cents( $checkout_order->get_total_tax() ) === 800, 'HPOS order preserves credit amount and original taxes' );
verify( OLR_Affiliate_Service::balance( $buyer )['reserved'] === 5000, 'checkout hook reserves the exact applied credit' );
$checkout_order->payment_complete( 'fixture-no-money-transfer' );
verify( OLR_Affiliate_Service::balance( $buyer )['balance'] === 0, 'real payment-complete hook captures credit once' );
verify( OLR_Affiliate_Service::balance( $buyer )['reserved'] === 0, 'payment completion clears reservation' );
$refund = wc_create_refund( array( 'order_id' => $checkout_id, 'amount' => 19, 'refund_payment' => false, 'reason' => 'Synthetic partial refund' ) );
verify( ! is_wp_error( $refund ) && OLR_Affiliate_Service::balance( $buyer )['balance'] === 2500, 'WooCommerce partial refund returns proportional store credit' );
$refund = wc_create_refund( array( 'order_id' => $checkout_id, 'amount' => 19, 'refund_payment' => false, 'reason' => 'Synthetic remaining refund' ) );
verify( ! is_wp_error( $refund ) && OLR_Affiliate_Service::balance( $buyer )['balance'] === 5000, 'WooCommerce full refund restores original credit exactly' );
WC()->cart->remove_coupons();
OLR_Affiliate_Service::atomic( function () use ( $buyer ) { OLR_Affiliate_Service::ledger( $buyer, 6000, 0, wp_generate_uuid4(), 'test_credit' ); } );
WC()->cart->calculate_totals();
verify( OLR_Affiliate_Service::cents( WC()->cart->get_total( 'edit' ) ) === 0, 'real cart can be funded entirely with credit including tax' );
$full_id = WC()->checkout()->create_order( array( 'billing_email' => get_userdata( $buyer )->user_email, 'billing_country' => 'US', 'billing_state' => 'CA' ) );
$full_order = wc_get_order( $full_id );
verify( $full_order && 'olr_store_credit' === $full_order->get_payment_method(), 'fully funded order records store credit as its payment method' );
$full_order->payment_complete();
verify( OLR_Affiliate_Service::balance( $buyer )['balance'] === 0 && $full_order->is_paid(), 'credit-only payment completes without an external gateway' );
OLR_Store_Credit::admin_refund( $full_id, '110.00', wp_generate_uuid4() );
verify( OLR_Affiliate_Service::balance( $buyer )['balance'] === 11000, 'credit-only order can be fully refunded to credit' );
wp_set_current_user( $uid );

// A database failure in UAP settlement must roll back the whole conversion.
$fault_ref = referral( $uid ); $before = OLR_Affiliate_Service::balance( $uid );
$fault = function ( $sql ) use ( $wpdb ) { return false !== stripos( $sql, 'INSERT INTO ' . $wpdb->prefix . 'uap_payments' ) ? 'SELECT 0' : $sql; };
add_filter( 'query', $fault );
refuses( function () use ( $uid ) { OLR_Affiliate_Service::request( $uid, 'store_credit', wp_generate_uuid4() ); }, 'injected UAP payment failure aborts settlement' );
remove_filter( 'query', $fault );
verify( OLR_Affiliate_Service::balance( $uid ) === $before && (int) $indeed_db->get_referral( $fault_ref )['payment'] === 0, 'failed settlement leaves credit and referral unpaid state unchanged' );

$activation_uid = member();
$spend_uid = member();
OLR_Affiliate_Service::atomic( function () use ( $spend_uid ) { OLR_Affiliate_Service::ledger( $spend_uid, 5000, 0, wp_generate_uuid4(), 'test_credit' ); } );
$spend_ids = array( credit_order( $spend_uid, 5000, 0 )->get_id(), credit_order( $spend_uid, 5000, 0 )->get_id() );
$race_uid = member(); OLR_Affiliate_Service::activate( $race_uid, true ); referral( $race_uid );
wp_set_current_user( $uid );

file_put_contents( dirname( $fixture ) . '/context.json', wp_json_encode( array( 'user_id' => $uid, 'admin_id' => $admin->ID, 'baseline' => $before['balance'], 'plugin' => dirname( __DIR__ ), 'activation_uid' => $activation_uid, 'spend_ids' => $spend_ids, 'race_uid' => $race_uid, 'key' => wp_generate_uuid4() ) ) );
// Render production PHP output for layout inspection, with only fixture identities.
$html = OLR_Affiliate_Flows::payout_panel();
$css = file_get_contents( dirname( __DIR__ ) . '/assets/account-hub.css' );
file_put_contents( dirname( $fixture ) . '/payout-preview.html', '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;padding:24px;font-family:Arial;background:#f4f2eb}.olr-account-hub{max-width:1100px;margin:auto}input{padding:12px;font:inherit}h2{font-size:28px}' . $css . '</style></head><body><main class="olr-account-hub">' . $html . '</main></body></html>' );
echo "Completed $checks integration checks.\n";
