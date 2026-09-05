<?php
/** Isolated tests: real upstream WP hook dispatcher and UM Access class; stubbed site data. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
$fixture = getenv( 'OLR_GITPRESS_QA' );
$scenario = $argv[1] ?? 'guest';
$legacy = ( $argv[2] ?? '' ) === 'legacy';
$mode = $argv[3] ?? 'gitpress_managed';
define( 'DGS_PLUGIN_DIR', $legacy ? $fixture . '/Gitpress-main/' : dirname( __DIR__ ) . '/wordpress-plugins/Gitpress-main/' );
foreach ( array( 'rest' => 'REST_REQUEST', 'xmlrpc' => 'XMLRPC_REQUEST', 'wc-api' => 'WC_API_REQUEST' ) as $name => $constant ) {
	if ( $scenario === $name ) { define( $constant, true ); }
}
require $fixture . '/plugin.php';
require $fixture . '/um-class-access.php';
require DGS_PLUGIN_DIR . 'includes/class-page-shortcode-manager.php';
class WP_Post { public $ID = 123; }
class WP_Query {
	public $is_home = false;
	public $is_posts_page = false;
	public function is_singular() { return true; }
}
class RedirectObserved extends Exception {}
$post = new WP_Post();
$wp_query = new WP_Query();
$events = array();
$status = null;
$no_cache = false;
$routes = array( 'guest' => '/', 'catalog' => '/catalog/', 'product' => '/catalog/epitalon/', 'coas' => '/coas/epitalon/', 'cart' => '/cart/', 'login' => '/login/', 'register' => '/register/', 'reset' => '/password-reset/', 'terms' => '/terms-and-conditions/', 'privacy' => '/privacy-policy/', 'confirmation' => '/checkout/order-received/123/', 'expired-confirmation' => '/checkout/order-received/123/', 'pay' => '/checkout/order-pay/123/' );
$route = $routes[$scenario] ?? '/catalog/epitalon/';
$_SERVER['REQUEST_URI'] = $route;
$signed_in = in_array( $scenario, array( 'member', 'admin-member', 'confirmation', 'pay' ), true );
function is_admin() { return $GLOBALS['scenario'] === 'admin-request'; }
function is_singular() { return $GLOBALS['scenario'] !== 'archive'; }
function is_page( $slug = null ) { return $slug === null || in_array( trim( $GLOBALS['route'], '/' ), (array) $slug, true ); }
function is_product() { return false; } // Clean product routes are WordPress pages.
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $s ) { return $s; }
function is_feed() { return $GLOBALS['scenario'] === 'feed'; }
function is_404() { return $GLOBALS['scenario'] === '404'; }
function wp_doing_ajax() { return $GLOBALS['scenario'] === 'ajax'; }
function wp_doing_cron() { return $GLOBALS['scenario'] === 'cron'; }
function is_wc_endpoint_url() { return in_array( $GLOBALS['scenario'], array( 'confirmation', 'expired-confirmation', 'pay' ), true ); }
function get_queried_object() { return $GLOBALS['scenario'] === 'no-post' ? null : $GLOBALS['post']; }
function get_post_meta( $id, $key, $single = false ) {
	if ( $key === '_dgs_page_shortcode_render_mode' ) { return $GLOBALS['mode']; }
	if ( $key === '_dgs_page_shortcode' ) { return $GLOBALS['scenario'] === 'empty' ? '' : '[fixture_body]'; }
	return '';
}
function sanitize_key( $s ) { return strtolower( $s ); }
function is_user_logged_in() { return $GLOBALS['signed_in']; }
function current_user_can( $cap ) { return $GLOBALS['scenario'] === 'admin-member'; }
function is_multisite() { return false; }
function is_front_page() { return $GLOBALS['route'] === '/'; }
function is_category() { return false; }
function is_tag() { return false; }
function is_tax() { return false; }
function um_is_core_post( $post, $type ) { return $GLOBALS['route'] === '/' . $type . '/'; }
function um_is_core_page( $type ) { return um_is_core_post( null, $type ); }
function um_user( $key ) { return $key === 'default_homepage' ? true : 123; }
function um_get_core_page( $type ) { return 'https://offlabelresearch.com/' . $type . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function urlencode_deep( $s ) { return urlencode( $s ); }
function esc_url_raw( $s ) { return $s; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function um_safe_redirect( $url ) { $GLOBALS['events'][] = 'redirect:' . $url; throw new RedirectObserved( $url ); }
function send_frame_options_header() { $GLOBALS['events'][] = 'frame-header'; }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
class FixtureUM {
	public $is_permalinks = true;
	public function options() { return $this; }
	public function permalinks() { return $this; }
	public function get_current_url( $pretty = true ) { return 'https://offlabelresearch.com' . $GLOBALS['route']; }
	public function get( $key ) {
		$options = array( 'accessible' => $GLOBALS['scenario'] === 'public-site' ? 1 : 2, 'access_redirect' => 'https://offlabelresearch.com/login', 'home_page_accessible' => false, 'access_exclude_uris' => array( 'https://offlabelresearch.com/register/', 'https://offlabelresearch.com/password-reset/', 'https://offlabelresearch.com/terms-and-conditions/', 'https://offlabelresearch.com/privacy-policy/' ) );
		return $options[$key] ?? false;
	}
}
function UM() { static $um; return $um ?? ( $um = new FixtureUM() ); }
function get_option( $key, $default = '' ) { return $key === 'dgs_managed_header_shortcode' ? '[fixture_header]' : ( $key === 'dgs_managed_footer_shortcode' ? '[fixture_footer]' : $default ); }
function do_shortcode( $s ) { $GLOBALS['events'][] = 'render:' . $s; return '<section>' . $s . '</section>'; }
function status_header( $code ) { $GLOBALS['status'] = $code; }
function language_attributes() { echo 'lang="en-US"'; }
function bloginfo( $key ) { echo 'UTF-8'; }
function body_class( $classes ) { echo 'class="' . implode( ' ', $classes ) . '"'; }
function wp_head() { echo '<!-- head -->'; }
function wp_body_open() { echo '<!-- body -->'; }
function wp_footer() { echo '<!-- footer -->'; }

register_shutdown_function( static function () {
	fwrite( STDERR, json_encode( array( 'events' => $GLOBALS['events'], 'status' => $GLOBALS['status'], 'nocache' => $GLOBALS['no_cache'], 'cache_flag' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) );
} );
DGS_Page_Shortcode_Manager::init();
if ( ( $argv[4] ?? '' ) === 'bridge' ) {
	require dirname( __DIR__ ) . '/gitpress/woocommerce-bridge.php';
}
$access = new \um\core\Access();
// Actual UM callback registration and global restriction implementation.
add_action( 'template_redirect', static function () { $GLOBALS['events'][] = 'before-um'; }, 999 );
add_action( 'template_redirect', static function () { $GLOBALS['events'][] = 'after-um'; }, 1011 );
try {
	do_action( 'wp' );
	if ( in_array( $scenario, array( 'rest', 'xmlrpc', 'wc-api', 'ajax', 'cron', 'admin-request' ), true ) ) {
		// Service requests do not use the front-end template_redirect pipeline.
		echo apply_filters( 'template_include', 'SERVICE_TEMPLATE' );
	} else {
		do_action( 'template_redirect' );
		$template = apply_filters( 'template_include', 'THEME_TEMPLATE' );
		if ( $template === 'THEME_TEMPLATE' ) { echo $template; } else { include $template; }
	}
} catch ( RedirectObserved $e ) {
	echo 'REDIRECT';
}
