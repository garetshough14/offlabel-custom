<?php
/**
 * Request smoke test for the account sidebar logout route.
 */

define( 'ABSPATH', __DIR__ . '/' );

$olr_logout_redirect = '';

class WP_User {}
class WP_Post {}

function add_shortcode( $tag, $callback ) {}
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
function absint( $value ) { return abs( (int) $value ); }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_preview() { return false; }
function is_page( $page ) { return 'account' === $page; }
function is_user_logged_in() { return true; }
function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_logout_url( $redirect = '' ) { return 'https://example.test/wp-login.php?action=logout&_wpnonce=test&redirect_to=' . rawurlencode( $redirect ); }
function wp_safe_redirect( $url, $status = 302 ) {
	global $olr_logout_redirect;
	$olr_logout_redirect = $url;
	return true;
}

$_SERVER['REQUEST_URI'] = '/account/?um_tab=olr_logout';
$_GET['um_tab']         = 'olr_logout';

register_shutdown_function(
	function () {
		global $olr_logout_redirect;
		if ( false === strpos( $olr_logout_redirect, 'action=logout' ) || false === strpos( $olr_logout_redirect, '_wpnonce=test' ) ) {
			fwrite( STDERR, "Account logout route test failed.\n" );
			exit( 1 );
		}
		echo "Account logout route test passed.\n";
	}
);

require dirname( __DIR__ ) . '/off-label-account-hub.php';
OLR_Account_Hub::instance()->route_account_requests();

fwrite( STDERR, "The logout route returned without redirecting.\n" );
exit( 1 );
