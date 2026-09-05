<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wordpress-plugins' );
$admin = ( $argv[1] ?? '' ) === 'admin';
$allowed = $admin;
$registered = array();
function is_admin() { return $GLOBALS['admin']; }
function add_action( $hook, $fn ) { $GLOBALS['registered'][] = $hook; }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_option( $key, $default = false ) { return $key === 'um_options' ? array( 'accessible' => 2, 'access_redirect' => '/login/', 'api_secret' => 'NEVER-EXPOSE-THIS' ) : $default; }
function absint( $v ) { return abs( (int) $v ); }
function get_page_by_path( $slug ) { return null; }
function wp_normalize_path( $s ) { return str_replace( '\\', '/', $s ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
require ABSPATH . 'wordpress-plugins/off-label-access-diagnostics/off-label-access-diagnostics.php';
if ( $registered !== ( $admin ? array( 'admin_menu' ) : array() ) ) { throw new Exception( 'Incorrect hook scope' ); }
$report = OLR_Access_Diagnostics::collect();
if ( strpos( json_encode( $report ), 'NEVER-EXPOSE-THIS' ) !== false ) { throw new Exception( 'Unapproved option exposed' ); }
if ( $admin && ( $report['saved_um_options']['accessible'] ?? null ) !== 2 ) { throw new Exception( 'Missing access option' ); }
if ( ! $admin && ! isset( $report['error'] ) ) { throw new Exception( 'Missing permission guard' ); }
$allowed = false;
if ( ! isset( OLR_Access_Diagnostics::collect()['error'] ) ) { throw new Exception( 'Non-admin could collect report' ); }
echo 'PASS: ' . ( $admin ? 'admin report option allowlist and permission checks' : 'no frontend hooks or report access' ) . PHP_EOL;
