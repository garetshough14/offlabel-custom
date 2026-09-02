<?php
/**
 * Lightweight runtime checks for pricing, grouping, coupon policy, and order data.
 * Run: C:\xampp\php\php.exe tests\runtime-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['olr_test_hooks']    = array();
$GLOBALS['olr_test_products'] = array();
$GLOBALS['olr_test_wc']       = null;

function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['olr_test_hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['olr_test_hooks'][ $hook ][] = $callback; }
function add_shortcode( $tag, $callback ) { $GLOBALS['olr_test_hooks'][ 'shortcode_' . $tag ][] = $callback; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function flush_rewrite_rules() {}
function __( $text, $domain = null ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }
function plugins_url( $path, $plugin = '' ) { return 'https://example.test/wp-content/plugins/off-label-build-a-box/' . ltrim( $path, '/' ); }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function wc_format_decimal( $value ) { return number_format( (float) $value, 6, '.', '' ); }
function wc_price( $value, $args = array() ) { return '$' . number_format( (float) $value, 2 ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_get_attachment_image_url( $id, $size ) { return 'image-' . absint( $id ) . '.jpg'; }
function get_option( $key, $default = false ) { return 'excl'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function add_query_arg( $key, $value = null, $url = '' ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function wc_add_notice( $message, $type = 'success' ) {}
function wc_get_product( $id ) { return isset( $GLOBALS['olr_test_products'][ $id ] ) ? $GLOBALS['olr_test_products'][ $id ] : false; }
function WC() { return $GLOBALS['olr_test_wc']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

class WC_Product {
	public $id;
	public $regular;
	public $price;
	public $eligible;
	public $sale = false;
	public $stock = true;
	public $visible = true;
	public $purchasable = true;
	public $image_id = 1;
	public $type = 'simple';

	public function __construct( $id, $regular, $eligible = true ) {
		$this->id = $id;
		$this->regular = $regular;
		$this->price = $regular;
		$this->eligible = $eligible;
	}
	public function get_id() { return $this->id; }
	public function get_name() { return 'Bottle ' . $this->id; }
	public function get_type() { return $this->type; }
	public function is_type( $types ) { return in_array( $this->type, (array) $types, true ); }
	public function get_meta( $key, $single = true ) { return '_olr_box_eligible' === $key && $this->eligible ? 'yes' : 'no'; }
	public function is_visible() { return $this->visible; }
	public function is_purchasable() { return $this->purchasable; }
	public function is_in_stock() { return $this->stock; }
	public function is_on_sale() { return $this->sale; }
	public function get_image_id() { return $this->image_id; }
	public function get_regular_price( $context = 'view' ) { return $this->regular; }
	public function set_price( $price ) { $this->price = (float) $price; }
	public function backorders_allowed() { return false; }
	public function has_enough_stock( $quantity ) { return $quantity <= 100; }
	public function get_max_purchase_quantity() { return 100; }
	public function is_sold_individually() { return false; }
}

class WC_Product_Variation extends WC_Product {
	public $type = 'variation';
}

class WC_Cart {
	public $items = array();
	public $coupons = array();
	public function get_cart() { return $this->items; }
	public function get_cart_item( $key ) { return isset( $this->items[ $key ] ) ? $this->items[ $key ] : false; }
	public function get_applied_coupons() { return $this->coupons; }
	public function remove_cart_item( $key ) { if ( ! isset( $this->items[ $key ] ) ) { return false; } unset( $this->items[ $key ] ); return true; }
}

class WC_Order_Item_Product {
	public $meta = array();
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
}

class WC_Coupon {}
class WC_Discounts {}

function olr_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

require dirname( __DIR__ ) . '/off-label-build-a-box.php';

$plugin = OLR_Build_A_Box::instance();
foreach ( $GLOBALS['olr_test_hooks'] as $hook_name => $callbacks ) {
	foreach ( $callbacks as $callback ) {
		olr_assert( is_callable( $callback ), 'Registered callback is callable: ' . $hook_name );
	}
}
$one    = new WC_Product( 101, 100 );
$two    = new WC_Product( 102, 50 );
$GLOBALS['olr_test_products'][101] = $one;
$GLOBALS['olr_test_products'][102] = $two;

$cart = new WC_Cart();
$manifest = array(
	array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 2, 'name' => 'Bottle 101', 'image' => 'image-1.jpg', 'regular_price' => 100 ),
	array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 3, 'name' => 'Bottle 102', 'image' => 'image-1.jpg', 'regular_price' => 50 ),
);
$cart->items = array(
	'box-parent' => array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 1, 'data' => clone $one, 'olr_box_id' => 'box-12345', 'olr_box_size' => 5, 'olr_box_rate' => .25, 'olr_box_label' => 'The Five', 'olr_box_role' => 'parent', 'olr_box_manifest' => $manifest ),
	'box-component-a' => array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 1, 'data' => clone $one, 'olr_box_id' => 'box-12345', 'olr_box_size' => 5, 'olr_box_rate' => .25, 'olr_box_label' => 'The Five', 'olr_box_role' => 'component' ),
	'box-component-b' => array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 3, 'data' => clone $two, 'olr_box_id' => 'box-12345', 'olr_box_size' => 5, 'olr_box_rate' => .25, 'olr_box_label' => 'The Five', 'olr_box_role' => 'component' ),
	'normal'=> array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 1, 'data' => clone $one ),
);
$GLOBALS['olr_test_wc'] = (object) array( 'cart' => $cart );

$plugin->apply_box_prices( $cart );
olr_assert( 75.0 === $cart->items['box-parent']['data']->price, 'The Five applies 25% to the visible parent allocation.' );
olr_assert( 75.0 === $cart->items['box-component-a']['data']->price, 'The remaining first-product allocation keeps native discounted pricing.' );
olr_assert( 37.5 === $cart->items['box-component-b']['data']->price, 'Hidden component allocations keep native discounted pricing.' );
olr_assert( 100.0 === (float) $cart->items['normal']['data']->price, 'Ordinary cart products remain untouched.' );

foreach ( $cart->items as &$line ) {
	$line['line_total'] = (float) $line['data']->price * absint( $line['quantity'] );
	$line['line_tax']   = 0;
}
unset( $line );
olr_assert( true === $plugin->cart_item_visible( true, $cart->items['box-parent'], 'box-parent' ), 'The box parent remains visible.' );
olr_assert( false === $plugin->cart_item_visible( true, $cart->items['box-component-b'], 'box-component-b' ), 'Native component allocations are hidden.' );
olr_assert( 2 === $plugin->cart_contents_count( 7 ), 'The cart counter treats one box plus one normal product as two items.' );
olr_assert( '$262.50' === $plugin->cart_item_group_price( '', $cart->items['box-parent'], 'box-parent' ), 'The visible row shows the combined discounted box total.' );

$incomplete = new WC_Cart();
$incomplete->items = array(
	'bad' => array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 4, 'data' => clone $one, 'olr_box_id' => 'box-67890', 'olr_box_size' => 5, 'olr_box_rate' => .25 ),
);
$plugin->apply_box_prices( $incomplete );
olr_assert( 100.0 === (float) $incomplete->items['bad']['data']->price, 'Incomplete groups never receive box pricing.' );

$coupon = new WC_Coupon();
olr_assert( false === $plugin->coupon_valid_for_product( true, $one, $coupon, $cart->items['box-parent'] ), 'Coupons are invalid for every protected box allocation.' );
olr_assert( true === $plugin->coupon_valid_for_product( true, $one, $coupon, $cart->items['normal'] ), 'Coupons remain available for ordinary cart products.' );
olr_assert( 0.0 === $plugin->coupon_discount_amount( 25.0, 75.0, $cart->items['box-parent'], false, $coupon ), 'Box allocations always receive zero coupon discount.' );
olr_assert( 25.0 === $plugin->coupon_discount_amount( 25.0, 100.0, $cart->items['normal'], false, $coupon ), 'Ordinary product coupon amounts remain untouched.' );

$box_discount_item    = (object) array( 'object' => $cart->items['box-parent'] );
$normal_discount_item = (object) array( 'object' => $cart->items['normal'] );
$discount_items       = array( 'box' => $box_discount_item, 'normal' => $normal_discount_item );
$items_to_validate    = $plugin->coupon_items_to_validate( $discount_items, new WC_Discounts() );
$items_to_apply       = $plugin->coupon_items_to_apply( $discount_items, $coupon, new WC_Discounts() );
olr_assert( array( 'normal' ) === array_keys( $items_to_validate ), 'Box allocations are excluded from coupon product validation.' );
olr_assert( array( 'normal' ) === array_keys( $items_to_apply ), 'Fixed-cart and product coupons distribute value only across ordinary products.' );

$display = $plugin->cart_item_data( array(), $cart->items['box-parent'] );
olr_assert( 2 === count( $display ) && '25% applied' === $display[0]['value'] && false !== strpos( $display[1]['display'], 'Bottle 102' ), 'The single cart line exposes its savings and complete contents without a redundant tier field.' );
olr_assert( array() === $plugin->cart_item_data( array(), $cart->items['box-component-a'] ), 'Hidden component lines expose no duplicate customer metadata.' );
$checkout_actions = $plugin->locked_checkout_quantity( '', $cart->items['box-parent'], 'box-parent' );
olr_assert( false !== strpos( $checkout_actions, 'Remove box' ) && false === strpos( $checkout_actions, '1 box' ), 'The custom checkout receives whole-box actions without a redundant quantity.' );
olr_assert( false !== strpos( $plugin->checkout_box_image( '', $cart->items['box-parent'] ), 'research-box-five.png' ), 'The Five receives its approved five-vial artwork.' );
$ten_image_item = $cart->items['box-parent'];
$ten_image_item['olr_box_size'] = 10;
olr_assert( false !== strpos( $plugin->checkout_box_image( '', $ten_image_item ), 'research-box-ten.png' ), 'The Ten receives its approved ten-vial artwork.' );

$order_item = new WC_Order_Item_Product();
$values = $cart->items['box-parent'];
$values['olr_box_regular_price'] = 100;
$plugin->save_order_item_metadata( $order_item, 'box-parent', $values, null );
olr_assert( 'The Five' === $order_item->meta['Research Box'], 'Order lines preserve the public box label.' );
olr_assert( '25%' === $order_item->meta['Box discount'], 'Order lines preserve the discount rate.' );
olr_assert( '$87.50' === $order_item->meta['Box savings'], 'The visible order line preserves whole-box savings.' );
olr_assert( false !== strpos( $order_item->meta['Contents'], '3 x Bottle 102' ), 'The visible order line preserves the complete manifest.' );

$component_order_item = new WC_Order_Item_Product();
$plugin->save_order_item_metadata( $component_order_item, 'box-component-a', $cart->items['box-component-a'], null );
olr_assert( false === $plugin->order_item_visible( true, $component_order_item ), 'Component order lines are hidden from customer views and emails.' );

$removal_cart = clone $cart;
$GLOBALS['olr_test_wc']->cart = $removal_cart;
$plugin->cascade_box_removal( 'box-parent', $removal_cart );
unset( $removal_cart->items['box-parent'] );
olr_assert( array( 'normal' ) === array_keys( $removal_cart->items ), 'Removing the visible row removes every native allocation in the box.' );
$tamper_cart = clone $cart;
$GLOBALS['olr_test_wc']->cart = $tamper_cart;
$plugin->reject_box_quantity_change( 'box-parent', 2, 1, $tamper_cart );
olr_assert( array( 'normal' ) === array_keys( $tamper_cart->items ), 'A direct quantity change removes the complete protected box.' );
$GLOBALS['olr_test_wc']->cart = $cart;

$reflector = new ReflectionClass( $plugin );
$rate = $reflector->getMethod( 'tier_rate' );
$rate->setAccessible( true );
olr_assert( .30 === $rate->invoke( $plugin, 10 ), 'The Ten resolves to the fixed 30% rate.' );
olr_assert( 0.0 === $rate->invoke( $plugin, 7 ), 'Unsupported tiers receive no discount.' );

$validate = $reflector->getMethod( 'validate_selection' );
$validate->setAccessible( true );
$selection = $validate->invoke( $plugin, array( array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 2 ), array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 3 ) ), 5, '' );
olr_assert( is_array( $selection ) && 1 === count( $selection ) && 5 === $selection[0]['quantity'], 'Duplicate selections merge and validate against live server product data.' );
$manifest_method = $reflector->getMethod( 'selection_manifest' );
$manifest_method->setAccessible( true );
$allocation_method = $reflector->getMethod( 'box_line_allocations' );
$allocation_method->setAccessible( true );
$validated_manifest = $manifest_method->invoke( $plugin, $selection );
$allocations = $allocation_method->invoke( $plugin, $selection, 'box-24680', 5, $validated_manifest );
olr_assert( 2 === count( $allocations ) && 1 === $allocations[0]['quantity'] && 'parent' === $allocations[0]['data']['olr_box_role'], 'A box creates exactly one visible parent allocation.' );
olr_assert( 4 === $allocations[1]['quantity'] && 'component' === $allocations[1]['data']['olr_box_role'], 'The remaining duplicate quantity is retained as a hidden native allocation.' );
$short_selection = $validate->invoke( $plugin, array( array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 4 ) ), 5, '' );
olr_assert( is_wp_error( $short_selection ), 'A four-bottle request is rejected for The Five.' );
$two->sale = true;
$sale_selection = $validate->invoke( $plugin, array( array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 5 ) ), 5, '' );
olr_assert( is_wp_error( $sale_selection ), 'Sale-priced products are rejected during server validation.' );
$two->sale = false;

echo "Runtime smoke checks completed.\n";
