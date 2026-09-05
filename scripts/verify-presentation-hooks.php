<?php
// Isolated callback checks. Never connects to a WordPress install.
define( 'ABSPATH', __DIR__ );
$admin = false;
$ajax = false;
$product_page = true;
$hooks = array();
function add_action( ...$args ) { global $hooks; $hooks[] = $args[0]; }
function add_filter( ...$args ) { global $hooks; $hooks[] = $args[0]; }
function add_shortcode( ...$args ) {}
function is_admin() { global $admin; return $admin; }
function wp_doing_ajax() { global $ajax; return $ajax; }
function is_page( $slugs ) { global $product_page; return $product_page; }
function get_query_var( $name ) { return ''; }
function wp_get_attachment_image_src( $id, $size, $icon ) { return array( 'original.jpg', 1150, 1600, false ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function absint( $value ) { return abs( (int) $value ); }
require dirname( __DIR__ ) . '/wordpress-plugins/off-label-production-rollout/off-label-production-rollout.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } }
$cropped = array( 'cropped.jpg', 300, 300, true );
$result = OLR_Production_Rollout::uncropped_product_images( $cropped, 10, 'woocommerce_thumbnail', false );
check( $result[2] === 1600, 'Product thumbnail must use original portrait dimensions.' );
check( OLR_Production_Rollout::uncropped_product_images( $cropped, 10, 'woocommerce_single', false ) === $cropped, 'Other sizes must remain unchanged.' );
$product_page = false;
check( OLR_Production_Rollout::uncropped_product_images( $cropped, 10, 'woocommerce_thumbnail', false ) === $cropped, 'Other pages must remain unchanged.' );
$product_page = true;
foreach ( array( 'admin', 'ajax' ) as $context ) {
    $$context = true;
    check( OLR_Production_Rollout::uncropped_product_images( $cropped, 10, 'woocommerce_thumbnail', false ) === $cropped, 'Admin/AJAX must remain unchanged.' );
    $$context = false;
}
define( 'REST_REQUEST', true );
check( OLR_Production_Rollout::uncropped_product_images( $cropped, 10, 'woocommerce_thumbnail', false ) === $cropped, 'REST must remain unchanged.' );
$header = OLR_Production_Rollout::site_header();
check( strpos( $header, '[olr_cart_count]' ) === false && strpos( $header, '(0)' ) !== false, 'Header must render without a cart session.' );
check( strpos( OLR_Production_Rollout::site_footer(), '/terms-and-conditions/' ) !== false, 'Bundled footer must have the canonical Terms link.' );
check( ! in_array( 'rest_api_init', $hooks, true ), 'No REST changes.' );
echo "PASS: original image dimensions; other pages, sizes, admin, AJAX and REST unchanged; shortcode fallbacks.\n";
