<?php
/** Populated, actual UM/UAP tab markup for local responsive verification. Never production. */
require __DIR__ . '/dashboard-tracking.php';
global $wpdb, $indeed_db;
$affiliate_id = OLR_Account_Hub::affiliate_id( $uid );
$original_rank = $indeed_db->get_affiliate_rank( 0, $uid );
$original_roles = get_userdata( $uid )->roles;
$old_code = OLR_Affiliate_Service::coupon_code( $uid );
$wpdb->delete( $wpdb->prefix . 'uap_coupons_code_affiliates', array( 'affiliate_id' => $affiliate_id ) );
check_tracking( ! OLR_Affiliate_Service::coupon_code( $uid ), 'legacy affiliate without live assignment has no fabricated code' );
$new_code = OLR_Affiliate_Service::prepare_code( $uid );
check_tracking( $new_code && $new_code !== $old_code && $indeed_db->get_affiliate_for_coupon_code( strtolower( $new_code ) ) == $affiliate_id, 'existing active affiliate receives a unique live UAP referral coupon' );
check_tracking( $new_code === OLR_Affiliate_Service::prepare_code( $uid ), 'code repair is idempotent' );
check_tracking( $affiliate_id === OLR_Account_Hub::affiliate_id( $uid ) && $original_rank == $indeed_db->get_affiliate_rank( 0, $uid ) && $original_roles === get_userdata( $uid )->roles, 'code repair preserves affiliate ID, rank and roles' );
OLR_Affiliate_Service::set_block( $uid, true );
try { OLR_Affiliate_Service::prepare_code( $uid ); throw new LogicException( 'Suspended affiliate generated a code.' ); }
catch ( RuntimeException $e ) { check_tracking( true, 'code repair refuses suspended affiliates' ); }
OLR_Affiliate_Service::set_block( $uid, false );
foreach ( array( array( '68.58', 0 ), array( '12.50', 0 ), array( '2.00', 2 ), array( '500.00', 1 ) ) as $ref ) {
 $wpdb->insert( $wpdb->prefix . 'uap_referrals', array( 'affiliate_id' => $affiliate_id, 'source' => 'woo', 'reference' => $order->get_id(), 'amount' => $ref[0], 'currency' => 'USD', 'status' => $ref[1], 'payment' => 0, 'date' => date( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ) );
}
OLR_Affiliate_Service::atomic( function () use ( $uid ) { OLR_Affiliate_Service::ledger( $uid, 2500, 0, 'qa-' . wp_generate_uuid4(), 'conversion' ); } );
OLR_Affiliate_Service::insert( 'requests', array( 'operation_key' => wp_generate_uuid4(), 'user_id' => $uid, 'affiliate_id' => $affiliate_id, 'method' => 'store_credit', 'currency' => 'USD', 'amount' => 2500, 'status' => 'completed', 'details' => '{}', 'created_at' => current_time( 'mysql' ) ) );
$vendor_css = '';
foreach ( array( 'ultimate-member/assets/css/um-styles.css', 'ultimate-member/assets/css/um-account.css', 'ultimate-member/assets/css/um-responsive.css', 'ultimate-affiliate-pro/assets/css/main_public.css', 'ultimate-affiliate-pro/assets/css/templates.css' ) as $file ) {
 if ( is_file( $fixture . '/wp-content/plugins/' . $file ) ) { $vendor_css .= file_get_contents( $fixture . '/wp-content/plugins/' . $file ); }
}
$css = str_replace( 'url("fonts/', 'url("/fonts/', file_get_contents( dirname( __DIR__ ) . '/assets/account-hub.css' ) );
$js = file_get_contents( dirname( __DIR__ ) . '/assets/account-hub.js' );
$tabs = array( 'overview', 'orders', 'tracking', 'general', 'password', 'privacy', 'delete', 'addresses', 'affiliate', 'performance', 'commissions', 'payouts', 'creative', 'guidelines' );
$manifest = array();
UM()->builtin()->set_core_fields();
UM()->builtin()->set_predefined_fields();
UM()->options()->update( 'account_name', true );
UM()->options()->update( 'account_email', true );
foreach ( array( 'password', 'privacy', 'delete' ) as $native_tab ) { UM()->options()->update( 'account_tab_' . $native_tab, true ); }
$GLOBALS['shortcode_tags']['uap-account-page'][0]->set_user();
foreach ( $tabs as $tab ) {
 $_GET['um_tab'] = $tab;
 UM()->account()->tab_output = array(); // Simulate the fresh request made by each real navigation.
 $native = $hub->shortcode();
 // Local-only navigation. Forms retain real handlers in markup but QA never submits them.
 $native = preg_replace_callback( '~href="[^"]*(?:um_tab=|/account/)([a-z_]+)[^"]*"~', static function ( $m ) use ( $tabs ) { return in_array( $m[1], $tabs, true ) ? 'href="/tab-' . $m[1] . '.html"' : $m[0]; }, $native );
 file_put_contents( $root . '/tab-' . $tab . '.html', '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account tab QA: ' . $tab . '</title><style>' . $vendor_css . 'body{margin:0} ' . $css . '</style></head><body>' . $native . '<script>' . $js . '</script></body></html>' );
 $manifest[] = $tab;
}
file_put_contents( $root . '/tabs.json', wp_json_encode( $manifest ) );
$wpdb->delete( $wpdb->prefix . 'uap_coupons_code_affiliates', array( 'affiliate_id' => $affiliate_id ) );
$_GET['um_tab'] = 'affiliate';
UM()->account()->tab_output = array();
$missing = $hub->shortcode();
check_tracking( strpos( $missing, 'data-olr-code-setup' ) !== false && strpos( $missing, 'olr_aff_nonce' ) !== false && strpos( $missing, 'NOT CONFIGURED' ) === false, 'missing-code view contains protected repair form instead of dead-end status' );
file_put_contents( $root . '/code-setup.html', '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $vendor_css . $css . '</style></head><body>' . $missing . '<script>' . $js . '</script></body></html>' );
OLR_Affiliate_Service::prepare_code( $uid );
echo "Generated all account tabs with native UM/UAP styles and populated commissions, tracking and payout history.\n";
