<?php
/**
 * End-to-end request smoke test for the public application POST handler.
 * The handler exits after redirect, so assertions run during shutdown.
 */

define( 'ABSPATH', __DIR__ . '/' );

$olr_submit_meta     = array();
$olr_submit_mail     = array();
$olr_submit_redirect = '';

class WP_User {
	public $ID = 7;
	public $user_email = 'member@example.test';
	public $display_name = 'Research Member';
	public $user_login = 'member';
	public $roles = array( 'subscriber' );
}
class WP_Post {}

function add_shortcode( $tag, $callback ) {}
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
function absint( $value ) { return abs( (int) $value ); }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 7; }
function get_userdata( $user_id ) { return 7 === (int) $user_id ? new WP_User() : false; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $nonce, $action ) { return 'valid-test-nonce' === $nonce; }
function get_user_meta( $user_id, $key, $single = false ) {
	global $olr_submit_meta;
	return isset( $olr_submit_meta[ $user_id ][ $key ] ) ? $olr_submit_meta[ $user_id ][ $key ] : '';
}
function get_option( $key, $default = false ) {
	if ( 'olr_affiliate_terms_url' === $key ) {
		return 'https://example.test/affiliate-terms/';
	}
	if ( 'admin_email' === $key ) {
		return 'manager@example.test';
	}
	return $default;
}
function sanitize_email( $email ) { return filter_var( $email, FILTER_SANITIZE_EMAIL ); }
function esc_url_raw( $url, $protocols = null ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }
function wp_http_validate_url( $url ) { return (bool) filter_var( $url, FILTER_VALIDATE_URL ); }
function current_time( $type ) { return '2026-08-31 12:00:00'; }
function update_user_meta( $user_id, $key, $value ) {
	global $olr_submit_meta;
	$olr_submit_meta[ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	global $olr_submit_meta;
	unset( $olr_submit_meta[ $user_id ][ $key ] );
	return true;
}
function do_action( $tag, ...$args ) {}
function __( $text, $domain = '' ) { return $text; }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function wp_specialchars_decode( $text, $flags = ENT_QUOTES ) { return html_entity_decode( $text, $flags ); }
function get_bloginfo( $key ) { return 'Off Label Research'; }
function wp_mail( $to, $subject, $message ) {
	global $olr_submit_mail;
	$olr_submit_mail[] = compact( 'to', 'subject', 'message' );
	return true;
}
function get_page_by_path( $path ) { return false; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( $args, $url = '' ) {
	return rtrim( $url, '?' ) . '?' . http_build_query( $args );
}
function wp_safe_redirect( $url, $status = 302 ) {
	global $olr_submit_redirect;
	$olr_submit_redirect = $url;
	return true;
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	'olr_account_action'                => 'submit_affiliate_application',
	'olr_affiliate_application_nonce'   => 'valid-test-nonce',
	'olr_affiliate_url'                 => 'example.com/profile',
	'olr_affiliate_plan'                => 'Share current research releases with my audience.',
	'olr_affiliate_terms'               => '1',
);

register_shutdown_function(
	function () {
		global $olr_submit_meta, $olr_submit_mail, $olr_submit_redirect;
		$application = isset( $olr_submit_meta[7] ) ? $olr_submit_meta[7] : array();
		$passed      = isset( $application['_olr_affiliate_application_status'] )
			&& 'pending' === $application['_olr_affiliate_application_status']
			&& 'https://example.com/profile' === $application['_olr_affiliate_application_url']
			&& 2 === count( $olr_submit_mail )
			&& 'member@example.test' === $olr_submit_mail[0]['to']
			&& 'manager@example.test' === $olr_submit_mail[1]['to']
			&& false !== strpos( $olr_submit_redirect, 'um_tab=affiliate' )
			&& false !== strpos( $olr_submit_redirect, 'olr_notice=application_submitted' );
		if ( ! $passed ) {
			fwrite( STDERR, "Public application request test failed.\n" );
			exit( 1 );
		}
		echo "Public application request test passed.\n";
	}
);

require dirname( __DIR__ ) . '/off-label-account-hub.php';
OLR_Account_Hub::instance()->maybe_submit_frontend_application();

fwrite( STDERR, "The application handler returned without redirecting.\n" );
exit( 1 );
