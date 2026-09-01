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
function wc_format_decimal( $value ) { return number_format( (float) $value, 6, '.', '' ); }
function wc_price( $value ) { return '$' . number_format( (float) $value, 2 ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
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
}

class WC_Order_Item_Product {
	public $meta = array();
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
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
$one    = new WC_Product( 101, 100 );
$two    = new WC_Product( 102, 50 );
$GLOBALS['olr_test_products'][101] = $one;
$GLOBALS['olr_test_products'][102] = $two;

$cart = new WC_Cart();
$cart->items = array(
	'box-a' => array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 2, 'data' => clone $one, 'olr_box_id' => 'box-12345', 'olr_box_size' => 5, 'olr_box_rate' => .25, 'olr_box_label' => 'The Five' ),
	'box-b' => array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 3, 'data' => clone $two, 'olr_box_id' => 'box-12345', 'olr_box_size' => 5, 'olr_box_rate' => .25, 'olr_box_label' => 'The Five' ),
	'normal'=> array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 1, 'data' => clone $one ),
);
$GLOBALS['olr_test_wc'] = (object) array( 'cart' => $cart );

$plugin->apply_box_prices( $cart );
olr_assert( 75.0 === $cart->items['box-a']['data']->price, 'The Five applies 25% to the first native product line.' );
olr_assert( 37.5 === $cart->items['box-b']['data']->price, 'The Five applies 25% to a duplicate-quantity line.' );
olr_assert( 100.0 === (float) $cart->items['normal']['data']->price, 'Ordinary cart products remain untouched.' );

$incomplete = new WC_Cart();
$incomplete->items = array(
	'bad' => array( 'product_id' => 101, 'variation_id' => 0, 'quantity' => 4, 'data' => clone $one, 'olr_box_id' => 'box-67890', 'olr_box_size' => 5, 'olr_box_rate' => .25 ),
);
$plugin->apply_box_prices( $incomplete );
olr_assert( 100.0 === (float) $incomplete->items['bad']['data']->price, 'Incomplete groups never receive box pricing.' );

$coupon_blocked = false;
try {
	$plugin->prevent_coupon_stacking( true, new WC_Coupon(), new WC_Discounts() );
} catch ( Exception $exception ) {
	$coupon_blocked = false !== strpos( $exception->getMessage(), 'cannot be combined' );
}
olr_assert( $coupon_blocked, 'All coupons are rejected while a box is present.' );

$display = $plugin->cart_item_data( array(), $cart->items['box-a'] );
olr_assert( 2 === count( $display ) && 'The Five' === $display[0]['value'] && '25%' === $display[1]['value'], 'Cart lines expose the public box tier and discount.' );

$order_item = new WC_Order_Item_Product();
$values = $cart->items['box-a'];
$values['olr_box_regular_price'] = 100;
$plugin->save_order_item_metadata( $order_item, 'box-a', $values, null );
olr_assert( 'The Five' === $order_item->meta['Research Box'], 'Order lines preserve the public box label.' );
olr_assert( '25%' === $order_item->meta['Box discount'], 'Order lines preserve the discount rate.' );
olr_assert( '$50.00' === $order_item->meta['Box savings'], 'Order lines preserve line-level savings.' );

$reflector = new ReflectionClass( $plugin );
$rate = $reflector->getMethod( 'tier_rate' );
$rate->setAccessible( true );
olr_assert( .30 === $rate->invoke( $plugin, 10 ), 'The Ten resolves to the fixed 30% rate.' );
olr_assert( 0.0 === $rate->invoke( $plugin, 7 ), 'Unsupported tiers receive no discount.' );

$validate = $reflector->getMethod( 'validate_selection' );
$validate->setAccessible( true );
$selection = $validate->invoke( $plugin, array( array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 2 ), array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 3 ) ), 5, '' );
olr_assert( is_array( $selection ) && 1 === count( $selection ) && 5 === $selection[0]['quantity'], 'Duplicate selections merge and validate against live server product data.' );
$short_selection = $validate->invoke( $plugin, array( array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 4 ) ), 5, '' );
olr_assert( is_wp_error( $short_selection ), 'A four-bottle request is rejected for The Five.' );
$two->sale = true;
$sale_selection = $validate->invoke( $plugin, array( array( 'product_id' => 102, 'variation_id' => 0, 'quantity' => 5 ) ), 5, '' );
olr_assert( is_wp_error( $sale_selection ), 'Sale-priced products are rejected during server validation.' );
$two->sale = false;

echo "Runtime smoke checks completed.\n";
