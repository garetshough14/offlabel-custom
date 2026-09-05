<?php
define( 'ABSPATH', __DIR__ );
$admin = false;
$saved = null;
$settings = array();
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function is_admin() { return $GLOBALS['admin']; }
function home_url( $p ) { return 'https://offlabelresearch.com' . $p; }
function plugins_url( $p, $file ) { return 'https://offlabelresearch.com/wp-content/plugins/off-label-site-controls/' . $p; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function wp_html_excerpt( $s, $n, $append ) { return substr( $s, 0, $n ); }
function esc_url_raw( $s, $protocols = null ) { return preg_match( '~^https?://~', $s ) ? $s : ''; }
function esc_url( $s ) { return htmlspecialchars( esc_url_raw( $s ), ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function get_option( $k, $default ) { return $GLOBALS['saved'] ?? $default; }
function register_setting( $group, $key, $args ) { $GLOBALS['settings'] = $args; }
function check( $yes, $message ) { if ( ! $yes ) { throw new Exception( $message ); } }
require dirname( __DIR__ ) . '/wordpress-plugins/off-label-site-controls/off-label-site-controls.php';
$header = file_get_contents( dirname( __DIR__ ) . '/gitpress/partials/header.template.html' );
$out = OLR_Site_Controls::replace_banner( $header, 'divi_github_content' );
check( strpos( $out, 'Store announcements' ) !== false, 'Header banner not replaced' );
check( substr_count( $out, '<aside' ) === 1, 'Duplicate banner' );
check( substr( $out, strpos( $out, '<div class="site-header"' ) ) === substr( $header, strpos( $header, '<div class="site-header"' ) ), 'Navigation changed' );
$saved = array( array( 'text' => 'Monthly offer', 'url' => 'https://offlabelresearch.com/catalog/' ), array( 'text' => '', 'url' => '' ), array( 'text' => '<b>Join us</b>', 'url' => 'javascript:alert(1)' ) );
$out = OLR_Site_Controls::replace_banner( $header, 'olr_site_header' );
check( strpos( $out, 'Monthly offer' ) !== false && strpos( $out, 'Research use only' ) === false, 'Saved banner ignored' );
check( strpos( $out, 'javascript:' ) === false && strpos( $out, '<b>Join' ) === false, 'Unsafe data rendered' );
check( OLR_Site_Controls::replace_banner( $header, 'unrelated_shortcode' ) === $header, 'Unrelated shortcode changed' );
$admin = true;
check( OLR_Site_Controls::replace_banner( $header, 'divi_github_content' ) === $header, 'Admin output changed' );
$admin = false;
$saved = array();
check( strpos( OLR_Site_Controls::replace_banner( $header, 'divi_github_content' ), '<aside' ) === false, 'Blank banner not omitted' );
OLR_Site_Controls::settings();
check( $settings['show_in_rest'] === false && is_callable( $settings['sanitize_callback'] ), 'Settings registration missing safety flags' );
echo "PASS: server-rendered banner, preserved navigation, saved values, escaping, blanks, scoped filtering and Settings API registration.\n";
$about = file_get_contents( dirname( __DIR__ ) . '/gitpress/pages/about.html' );
$updated = OLR_Site_Controls::replace_about_image( $about, 'divi_github_content' );
check( strpos( $updated, 'about-philosophy-approved-v1.png' ) !== false, 'About artwork not replaced' );
check( strpos( $updated, 'about-philosophy-record-no-research.png' ) === false, 'Legacy artwork remains' );
check( strpos( $updated, 'about-philosophy-wide-v2.png' ) !== false && strpos( $updated, '<source media="(max-width: 48.875rem)"' ) !== false, 'Responsive artwork sources missing' );
check( preg_replace( '~</?(?:img|picture|source)\b[^>]*>~', '', $about ) === preg_replace( '~</?(?:img|picture|source)\b[^>]*>~', '', $updated ), 'About content changed beyond image' );
check( OLR_Site_Controls::replace_about_image( $updated, 'divi_github_content' ) === $updated, 'Replacement not idempotent' );
check( OLR_Site_Controls::replace_about_image( $about, 'other' ) === $about, 'Unrelated shortcode changed' );
$admin = true;
check( OLR_Site_Controls::replace_about_image( $about, 'divi_github_content' ) === $about, 'Admin content changed' );
$admin = false;
if ( isset( $argv[1] ) && '--about-fixture' === $argv[1] ) { echo "ABOUT_FIXTURE_START\n" . $updated; }
echo "\nPASS: About image replacement, preserved copy, idempotence and admin/shortcode boundaries.\n";
$box = file_get_contents( dirname( __DIR__ ) . '/wordpress-plugins/off-label-build-a-box/tests/visual-fixture.html' );
$box_updated = OLR_Site_Controls::replace_box_image( $box, 'olr_build_a_box' );
check( strpos( $box_updated, 'build-box-transparent-v1.png' ) !== false, 'Box artwork not replaced' );
check( OLR_Site_Controls::replace_box_image( $box_updated, 'divi_github_content' ) === $box_updated, 'Nested box replacement not idempotent' );
check( preg_replace( '~<figure class="olr-build-box__hero-media">.*?</figure>~s', '', $box ) === preg_replace( '~<figure class="olr-build-box__hero-media">.*?</figure>~s', '', $box_updated ), 'Builder changed outside hero figure' );
check( OLR_Site_Controls::replace_box_image( $box, 'unrelated' ) === $box, 'Unrelated shortcode changed' );
$admin = true;
check( OLR_Site_Controls::replace_box_image( $box, 'olr_build_a_box' ) === $box, 'Admin content changed' );
$admin = false;
if ( isset( $argv[1] ) && '--box-fixture' === $argv[1] ) { echo "BOX_FIXTURE_START\n" . $box_updated; }
echo "\nPASS: box hero-only replacement; builder markup unchanged; idempotence and shortcode/admin boundaries.\n";
