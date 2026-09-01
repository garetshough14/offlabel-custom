<?php
/**
 * Focused smoke test for transactional emails and safe affiliate unlinking.
 * Run with: php tests/runtime-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$olr_test_actions = array();
$olr_test_mail    = array();
$olr_test_meta    = array();
$olr_test_users   = array();

class WP_User {
	public $ID;
	public $user_email;
	public $display_name;
	public $user_login;
	public $roles = array();
}

class WP_Post {}

function add_shortcode( $tag, $callback ) {}
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	global $olr_test_actions;
	$olr_test_actions[] = array( $tag, $priority );
}
function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = '' ) { return $text; }
function get_userdata( $user_id ) {
	global $olr_test_users;
	return isset( $olr_test_users[ $user_id ] ) ? $olr_test_users[ $user_id ] : false;
}
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function wp_specialchars_decode( $text, $flags = ENT_QUOTES ) { return html_entity_decode( $text, $flags ); }
function get_bloginfo( $key ) { return 'Off Label Research'; }
function wp_mail( $to, $subject, $message ) {
	global $olr_test_mail;
	$olr_test_mail[] = compact( 'to', 'subject', 'message' );
	return true;
}
function get_page_by_path( $path ) { return false; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function update_user_meta( $user_id, $key, $value ) {
	global $olr_test_meta;
	$olr_test_meta[ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	global $olr_test_meta;
	unset( $olr_test_meta[ $user_id ][ $key ] );
	return true;
}
function current_time( $type ) { return '2026-08-31 12:00:00'; }

final class OLR_Test_UAP_Database {
	public $removed = array();
	public function get_uid_by_affiliate_id( $affiliate_id ) { return 42 === (int) $affiliate_id ? 7 : 0; }
	public function remove_user_from_affiliate( $user_id ) {
		$this->removed[] = (int) $user_id;
		return true;
	}
}

$user               = new WP_User();
$user->ID            = 7;
$user->user_email    = 'member@example.test';
$user->display_name  = 'Research Member';
$user->user_login    = 'member';
$olr_test_users[ 7 ] = $user;
$indeed_db           = new OLR_Test_UAP_Database();

require dirname( __DIR__ ) . '/off-label-account-hub.php';

$plugin     = OLR_Account_Hub::instance();
$reflection = new ReflectionClass( $plugin );

$email_method = $reflection->getMethod( 'send_application_email' );
$email_method->setAccessible( true );
if ( ! $email_method->invoke( $plugin, 7, 'submitted' ) || ! $email_method->invoke( $plugin, 7, 'approved' ) ) {
	fwrite( STDERR, "Application email test failed.\n" );
	exit( 1 );
}
if ( 2 !== count( $olr_test_mail ) || false === strpos( $olr_test_mail[0]['message'], 'pending review' ) || false === strpos( $olr_test_mail[1]['message'], 'approved' ) ) {
	fwrite( STDERR, "Application email content test failed.\n" );
	exit( 1 );
}

$unlink_method = $reflection->getMethod( 'remove_affiliate_access' );
$unlink_method->setAccessible( true );
if ( 1 !== $unlink_method->invoke( $plugin, array( 42 ) ) || array( 7 ) !== $indeed_db->removed || ! isset( $olr_test_users[7] ) ) {
	fwrite( STDERR, "Safe affiliate unlink test failed.\n" );
	exit( 1 );
}
if ( 'rejected' !== $olr_test_meta[7]['_olr_affiliate_application_status'] ) {
	fwrite( STDERR, "Application status after unlink test failed.\n" );
	exit( 1 );
}

$required_hooks = array(
	array( 'template_redirect', 1 ),
	array( 'wp_ajax_uap_ajax_remove_one_affiliate', -100 ),
	array( 'wp_ajax_uap_ajax_remove_many_affiliates', -100 ),
);
foreach ( $required_hooks as $hook ) {
	if ( ! in_array( $hook, $olr_test_actions, true ) ) {
		fwrite( STDERR, "Required request guard was not registered.\n" );
		exit( 1 );
	}
}

echo "Account application and member-preservation smoke tests passed.\n";
