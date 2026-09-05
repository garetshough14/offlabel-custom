<?php
require __DIR__ . '/verify-site-controls.php';
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
$header = file_get_contents( dirname( __DIR__ ) . '/wordpress-plugins/off-label-production-rollout/assets/site-header.html' );
$refined = OLR_Site_Controls::refine_layout( $header, 'olr_site_header' );
check( substr_count( $refined, 'data-nav="build-your-box"' ) === 2, 'Desktop/mobile box links missing' );
check( substr_count( $refined, 'class="olr-cart-label"' ) === 2, 'Cart labels missing' );
check( OLR_Site_Controls::refine_layout( $refined, 'olr_site_header' ) === $refined, 'Header not idempotent' );
check( OLR_Site_Controls::refine_layout( $header, 'other' ) === $header, 'Other shortcode changed' );
$admin = true; check( OLR_Site_Controls::refine_layout( $header, 'olr_site_header' ) === $header, 'Admin changed' ); $admin = false;
$sections = '<nav class="olr-research-tabs" aria-label="Research categories"><div>ALL BUNDLES</div></nav><nav>Keep navigation</nav><details class="olr-product-information__section" data-icon="quality"><summary>Testing + quality</summary><div>Test report</div></details><details data-icon="storage">Storage</details>';
$out = OLR_Site_Controls::refine_layout( $sections, 'divi_github_content' );
check( $out === '<nav>Keep navigation</nav><details data-icon="storage">Storage</details>', 'Section removal too broad or incomplete' );
class PreviewSession { public $data = array(); function get( $key, $default = null ) { return $this->data[$key] ?? $default; } function set( $key, $value ) { $this->data[$key] = $value; } }
class PreviewProduct { public $name; function __construct( $name ) { $this->name = $name; } function exists() { return true; } function get_name() { return $this->name; } function get_image( $size ) { return '<img alt="Bottle" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">'; } }
class PreviewCart { public $items = array(); function get_cart() { return $this->items; } function get_product_subtotal( $p, $q ) { return '$' . (40 * $q) . '.00'; } function get_cart_contents_count() { return array_sum( array_column( $this->items, 'quantity' ) ); } }
$wc = (object) array( 'session' => new PreviewSession(), 'cart' => new PreviewCart() );
function WC() { return $GLOBALS['wc']; }
function wp_generate_uuid4() { static $i = 0; return 'event-' . ++$i; }
function wp_kses_post( $s ) { return $s; } // Trusted fixture HTML only.
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wc_get_formatted_cart_item_data( $item ) { return $item['details'] ?? ''; }
function apply_filters( $tag, $value, ...$args ) {
	$item = $args[0] ?? array();
	if ( 'woocommerce_widget_cart_item_visible' === $tag && ! empty( $item['hidden'] ) ) return false;
	if ( 'woocommerce_cart_item_subtotal' === $tag && ! empty( $item['box'] ) ) return '$150.00';
	// Reproduce the live checkout's thumbnail injected into the cart-item name.
	if ( 'woocommerce_cart_item_name' === $tag ) return '<span class="olr-checkout-test__product">' . $item['data']->get_image( 'woocommerce_thumbnail' ) . '<span class="olr-checkout-test__product-copy">' . $value . '</span></span>';
	return $value;
}
check( OLR_Cart_Preview::payload() === array( 'pending' => false ), 'Popup without addition' );
$wc->cart->items['simple'] = array( 'data' => new PreviewProduct( 'Epitalon' ), 'quantity' => 2 );
$wc->cart->items['variable'] = array( 'data' => new PreviewProduct( 'Sermorelin - 5 MG' ), 'quantity' => 1, 'details' => '<dl><dt>Strength</dt><dd>5 MG</dd></dl>' );
$wc->cart->items['box'] = array( 'data' => new PreviewProduct( 'The Five Research Box' ), 'quantity' => 1, 'box' => true, 'details' => '<dl><dt>Contents</dt><dd>3 x KPV, 2 x Semax</dd></dl>' );
$wc->cart->items['component'] = array( 'data' => new PreviewProduct( 'HIDDEN COMPONENT' ), 'quantity' => 4, 'hidden' => true );
OLR_Cart_Preview::added( 'simple' );
$payload = OLR_Cart_Preview::payload();
check( $payload['pending'] && strpos( $payload['html'], 'Epitalon' ) !== false && strpos( $payload['html'], 'Qty 2' ) !== false, 'Product/quantity missing' );
check( substr_count( $payload['html'], '<img' ) === 3 && strpos( $payload['html'], 'olr-checkout-test__product' ) === false, 'Checkout injected duplicate image/layout' );
check( strpos( $payload['html'], '5 MG' ) !== false && strpos( $payload['html'], '3 x KPV' ) !== false && strpos( $payload['html'], '$150.00' ) !== false, 'Variations/box contents/group price missing' );
check( strpos( $payload['html'], 'HIDDEN COMPONENT' ) === false, 'Box component duplicated' );
check( strpos( $payload['html'], '<button' ) === false, 'Unexpected cart mutation controls' );
check( OLR_Cart_Preview::payload()['token'] === $payload['token'], 'Reading consumed event before successful display' );
$wc->session->data[OLR_Cart_Preview::SESSION_KEY]['time'] = time() - 301;
check( ! OLR_Cart_Preview::payload()['pending'], 'Expired popup displayed' );
OLR_Cart_Preview::added( 'rolled-back-key' );
check( ! OLR_Cart_Preview::payload()['pending'], 'Rolled back item triggered popup' );
$wc->session = null; check( ! OLR_Cart_Preview::payload()['pending'], 'Missing session unsafe' );
echo "PASS: layout/header boundaries, current-session cart, quantities, variations, box grouping, event expiry and rollback checks.\n";
if ( in_array( '--fixture', $argv, true ) ) echo "CART_FIXTURE_START\n" . json_encode( array( 'header' => $refined, 'payload' => $payload, 'sections' => $out ) );
