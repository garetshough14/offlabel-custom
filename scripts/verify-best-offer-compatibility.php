<?php
/** Exercise each WooCommerce version in a fresh process because WC_VERSION is a constant. */
if ( ( $argv[1] ?? '' ) !== '--worker' ) {
	foreach ( array( '11.1.0', '11.1.1', '11.1.2', '11.2.0', '11.0.0', '11.1.1-rc.1', 'missing' ) as $version ) {
		$process = proc_open( array( PHP_BINARY, __FILE__, '--worker', $version ), array( 1 => STDOUT, 2 => STDERR ), $pipes );
		if ( ! is_resource( $process ) || proc_close( $process ) !== 0 ) { exit( 1 ); }
	}
	exit( 0 );
}

define( 'ABSPATH', __DIR__ );
$version = $argv[2];
if ( 'missing' !== $version ) { define( 'WC_VERSION', $version ); }
$hooks = array();
$notices = array();
function add_action( $hook, $callback, ...$args ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function add_filter( ...$args ) { add_action( ...$args ); }
function wp_get_environment_type() { return 'production'; }
function get_option( $key, $default = false ) { return 'olr_best_offer_live_enabled' === $key ? 'yes' : $default; }
function is_user_logged_in() { return false; }
function wc_get_coupon_id_by_code( $code ) { return 0; }
function wc_has_notice( $message, $type ) { return in_array( $message, $GLOBALS['notices'], true ); }
function wc_add_notice( $message, $type ) { $GLOBALS['notices'][] = $message; }
function WC() { return (object) array( 'session' => null, 'cart' => null ); }
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

require dirname( __DIR__ ) . '/wordpress-plugins/off-label-best-offer/off-label-best-offer.php';
$accepted = in_array( $version, array( '11.1.0', '11.1.1' ), true );
$errors = OLR_Best_Offer::compatibility_errors();
check( empty( $errors ) === $accepted, 'Unexpected compatibility decision for ' . $version );
$engine = new OLR_Best_Offer();
$engine->boot();
check( ! empty( $GLOBALS['olr_best_offer_live_ready'] ) === $accepted, 'Incorrect live engine status for ' . $version );
$engine->check_cart();
check( empty( $notices ) === $accepted, 'Incorrect checkout pause for ' . $version );
if ( ! $accepted ) {
	check( count( $errors ) === 1 && strpos( $errors[0], 'WooCommerce' ) !== false, 'Expected an actionable WooCommerce version error' );
	$blocked = false;
	try { $engine->assert_safe_order( null ); } catch ( Exception $error ) { $blocked = true; }
	check( $blocked, 'Unverified version must still block order creation' );
}
echo 'PASS: WooCommerce ' . $version . ( $accepted ? ' starts live pricing without blocking checkout.' : ' remains blocked.' ) . PHP_EOL;
