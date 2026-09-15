<?php
/** Disposable WP/HPOS fixture; no production data or outgoing email. */
$fixture = getenv( 'OLR_TEST_WP' );
if ( ! $fixture ) { throw new RuntimeException( 'Set OLR_TEST_WP.' ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8337'; $_SERVER['REQUEST_URI'] = '/account/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $fixture . '/wp-load.php';
if ( DB_NAME !== 'olr_affiliate_120_test' || DB_HOST !== '127.0.0.1:33317' ) { throw new RuntimeException( 'Disposable fixture only.' ); }
add_filter( 'pre_wp_mail', '__return_true', -1000 );
require dirname( __DIR__ ) . '/off-label-account-hub.php';
$checks = 0;
function check_tracking( $pass, $message ) { if ( ! $pass ) { throw new RuntimeException( 'FAIL: ' . $message ); } ++$GLOBALS['checks']; echo "PASS: $message\n"; }
if ( class_exists( '\WooCommerce\Shipping\ShipStation\API\REST\Orders_Controller' ) ) {
	$probe_uid = wp_create_user( 'ship_' . bin2hex( random_bytes( 4 ) ), wp_generate_password( 32 ) );
	wp_set_current_user( $probe_uid );
	$probe = wc_create_order( array( 'customer_id' => $probe_uid ) );
	$controller = new \WooCommerce\Shipping\ShipStation\API\REST\Orders_Controller();
	$controller->process_items( array(), $probe, array( 'ship_date' => '2026-09-08', 'tracking_number' => 'REAL-HOOK-123', 'carrier_code' => 'custom_carrier', 'tracking_url' => 'https://example.invalid/tracking/REAL-HOOK-123', 'notification_id' => wp_generate_uuid4() ) );
	$recorded = OLR_Order_Tracking::items( wc_get_order( $probe->get_id() ) );
	check_tracking( count( $recorded ) === 1 && $recorded[0]['url'] === 'https://example.invalid/tracking/REAL-HOOK-123', 'actual ShipStation 5.3.5 REST notification processor captures carrier URL and tracking through the hub hook' );
}
$name = 'dashboard_' . bin2hex( random_bytes( 4 ) );
$uid = wp_create_user( $name, wp_generate_password( 32 ), $name . '@example.invalid' );
wp_update_user( array( 'ID' => $uid, 'first_name' => 'Alexandra', 'display_name' => 'Alexandra' ) );
wp_set_current_user( $uid ); update_user_meta( $uid, 'account_status', 'approved' );
$order = wc_create_order( array( 'customer_id' => $uid ) ); $order->set_total( 286 ); $order->set_status( 'completed' );
$item = new WC_Order_Item_Product(); $item->set_name( 'Synthetic order item' ); $item->set_quantity( 4 ); $item->set_total( 286 ); $order->add_item( $item ); $order->save();
$data = array( 'tracking_number' => '9400111899560000000000', 'carrier' => 'USPS', 'ship_date' => time() );
do_action( 'woocommerce_shipstation_shipnotify', $order, $data );
do_action( 'woocommerce_shipstation_shipnotify', $order, $data );
$items = OLR_Order_Tracking::items( wc_get_order( $order->get_id() ) );
check_tracking( count( $items ) === 1 && $items[0]['number'] === $data['tracking_number'], 'ShipStation notification stores tracking through HPOS CRUD and deduplicates retries' );
check_tracking( strpos( $items[0]['url'], 'https://tools.usps.com/' ) === 0, 'USPS parcel receives a carrier tracking link' );
$order->add_order_note( 'INTERNAL: private fulfillment instructions never shown', false );
$order->add_order_note( 'Items shipped via USPS on September 8, 2026 with tracking number 9400111899560000000000 (Shipstation).', true );
check_tracking( count( OLR_Order_Tracking::items( $order ) ) === 1, 'structured shipment and matching legacy note are shown once' );
$order->add_order_note( 'Items shipped via UPS on September 8, 2026 with tracking number 1Z999AA10123456784 (Shipstation).', false );
$items = OLR_Order_Tracking::items( $order );
check_tracking( count( $items ) === 2 && $items[1]['carrier'] === 'UPS', 'split shipment in historical private ShipStation note remains visible as tracking fields only' );
$html = OLR_Order_Tracking::render( $order );
check_tracking( strpos( $html, 'INTERNAL' ) === false && strpos( $html, '1Z999AA10123456784' ) !== false, 'tracking view does not disclose unrelated note bodies' );
$order->update_meta_data( '_wc_shipment_tracking_items', array( array( 'tracking_number' => 'CUSTOM-123456', 'custom_tracking_provider' => 'Local Courier', 'custom_tracking_link' => 'https://example.invalid/track/CUSTOM-123456' ) ) ); $order->save();
check_tracking( count( OLR_Order_Tracking::items( $order ) ) === 3, 'WooCommerce Shipment Tracking metadata supports custom carriers and multiple packages' );
$unsafe = OLR_Order_Tracking::normalize( array( 'tracking_number' => '123<script>alert(1)</script>', 'carrier' => 'Custom', 'url' => 'javascript:alert(1)' ) );
check_tracking( $unsafe['url'] === '' && strpos( $unsafe['number'], '<' ) === false, 'tracking data strips markup and unsafe URL schemes' );
check_tracking( OLR_Order_Tracking::from_note( 'Private admin note: secret' ) === null, 'unrecognized note format is ignored' );
$hub = OLR_Account_Hub::instance();
$method = new ReflectionMethod( $hub, 'render_tab' );
$dashboard = $method->invoke( $hub, 'overview' );
check_tracking( strpos( $dashboard, 'Alexandra' ) !== false && strpos( $dashboard, 'Samantha' ) === false, 'dashboard greets the actual signed-in member' );
check_tracking( strpos( $dashboard, '286.00' ) !== false && strpos( $dashboard, '4 items' ) !== false && strpos( $dashboard, 'Arriving' ) === false, 'latest real order shows amount and quantity without an invented delivery estimate' );
$orders = $method->invoke( $hub, 'orders' );
check_tracking( strpos( $orders, '9400111899560000000000' ) !== false && strpos( $orders, 'Track package' ) !== false, 'order list exposes tracking numbers and carrier links' );
$_GET['order_id'] = $order->get_id();
$detail = $method->invoke( $hub, 'orders' );
check_tracking( strpos( $detail, 'Shipment tracking' ) !== false && strpos( $detail, '1Z999AA10123456784' ) !== false, 'owned order detail shows split shipment tracking' );
$other = wp_create_user( $name . '_other', wp_generate_password( 32 ), $name . '_other@example.invalid' ); wp_set_current_user( $other );
check_tracking( ! OLR_Order_Tracking::items( $order ), 'another member cannot read tracking for this order' );
check_tracking( strpos( $method->invoke( $hub, 'orders' ), '1Z999AA10123456784' ) === false, 'direct order ID request cannot disclose another customer shipment' );
unset( $_GET['order_id'] );
check_tracking( strpos( $method->invoke( $hub, 'overview' ), 'Your first order starts here' ) !== false, 'new customer sees the no-orders state' );
wp_set_current_user( 0 );
check_tracking( ! OLR_Order_Tracking::items( $order ), 'guests cannot read customer tracking' );
wp_set_current_user( $uid );
$tabs = $hub->ultimate_member_tabs( array( 100 => array( 'general' => array( 'title' => 'Account' ) ) ) );
check_tracking( $tabs[5]['overview']['title'] === 'Dashboard' && isset( $tabs[12]['tracking'], $tabs[18]['addresses'] ), 'navigation exposes dashboard, tracking and addresses' );
OLR_Affiliate_Service::activate( $uid, true );
$affiliate_tabs = $hub->ultimate_member_tabs( array() );
check_tracking( isset( $affiliate_tabs[19]['affiliate'] ) && $affiliate_tabs[19]['affiliate']['title'] === 'Affiliate dashboard', 'active affiliates keep a dedicated dashboard navigation entry' );
check_tracking( strpos( $method->invoke( $hub, 'overview' ), 'Recent order' ) !== false, 'active affiliates land on the customer order dashboard' );
// Render the actual dashboard and orders in the native UM shell for responsive QA.
$css = file_get_contents( dirname( __DIR__ ) . '/assets/account-hub.css' );
$css = str_replace( 'url("fonts/', 'url("/fonts/', $css );
$js = file_get_contents( dirname( __DIR__ ) . '/assets/account-hub.js' );
$nav = '';
foreach ( array( 'overview' => 'Dashboard', 'orders' => 'Orders', 'tracking' => 'Tracking', 'general' => 'Account details', 'addresses' => 'Addresses', 'affiliate' => 'Affiliate program', 'olr_logout' => 'Log out' ) as $slug => $label ) { $nav .= '<li><a href="#' . $slug . '" data-tab="' . $slug . '" class="um-account-link ' . ( $slug === 'overview' ? 'current' : '' ) . '"><span class="um-account-title">' . $label . '</span></a></li>'; }
$prefix = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account Hub local layout QA</title><style>body{margin:0}' . $css . '</style></head><body><div class="olr-account-hub" data-olr-account-hub><p class="olr-account-brand">My account</p><div class="um um-account"><div class="um-form"><div class="olr-um-account-form-shell"><div class="um-account-side"><ul>' . $nav . '</ul></div><div class="um-account-main">';
$suffix = '</div></div></div></div></div><script>' . $js . '</script></body></html>';
$root = dirname( $fixture ) . '/public';
file_put_contents( $root . '/dashboard-121.html', $prefix . $dashboard . $suffix );
file_put_contents( $root . '/orders-121.html', $prefix . $orders . $suffix );
if ( shortcode_exists( 'ultimatemember_account' ) ) {
	$_GET['um_tab'] = 'overview';
	$native = $hub->shortcode();
	$native_css = file_get_contents( $fixture . '/wp-content/plugins/ultimate-member/assets/css/um-account.css' );
	check_tracking( strpos( $native, 'um-account-side' ) !== false && strpos( $native, 'olr-customer-title' ) !== false, 'actual Ultimate Member shortcode renders the customer dashboard and native navigation' );
	file_put_contents( $root . '/native-dashboard-121.html', '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Native UM dashboard QA</title><style>' . $native_css . 'body{margin:0}' . $css . '</style></head><body>' . $native . '<script>' . $js . '</script></body></html>' );
}
echo "Completed $checks dashboard/tracking checks.\n";
