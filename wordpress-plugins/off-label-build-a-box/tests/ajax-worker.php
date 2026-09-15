<?php
/** Disposable request seam: real builder endpoint + WordPress nonce validation.
 * Products/cart/storage are test doubles; this never loads a site or database.
 * Invoked by submission-smoke.js over stdin/stdout in separate PHP processes.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ob_start();
require __DIR__ . '/runtime-smoke.php';
ob_end_clean();
require __DIR__ . '/fixtures/wp-nonces.php';
define( 'DAY_IN_SECONDS', 86400 );

function wp_get_current_user() { return (object) array( 'ID' => 123 ); }
function wp_get_session_token() { return 'disposable-test-session'; }
function wp_hash( $data, $scheme = '' ) { return hash_hmac( 'md5', $data, 'disposable-test-salt' ); }
function apply_filters( $name, $value, ...$args ) { return $value; }
function do_action( $name, ...$args ) {}
function wp_doing_ajax() { return true; }
function wp_unslash( $value ) { return $value; }
function wp_generate_uuid4() { return 'box-' . bin2hex( random_bytes( 16 ) ); }
function wc_get_cart_url() { return 'https://example.test/cart/'; }
function nocache_headers() {}

class AjaxResponse extends RuntimeException {
	public function __construct( public $body, public $status = 200 ) { parent::__construct(); }
}
function wp_die( $body, $status = 500 ) { throw new AjaxResponse( $body, $status ); }
function wp_send_json_success( $data ) { throw new AjaxResponse( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data, $status = 200 ) { throw new AjaxResponse( array( 'success' => false, 'data' => $data ), $status ); }

class AjaxCart extends WC_Cart {
	public $fail_after = 0;
	private $additions = 0;
	public function add_to_cart( $product_id, $quantity, $variation_id = 0, $variation = array(), $data = array() ) {
		if ( $this->fail_after && ++$this->additions === $this->fail_after ) { return false; }
		$key = md5( json_encode( array( $product_id, $variation_id, $data ) ) );
		$this->items[ $key ] = array_merge( $data, array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'variation' => $variation, 'quantity' => $quantity, 'data' => clone wc_get_product( $product_id ) ) );
		return $key;
	}
	public function calculate_totals() {
		OLR_Build_A_Box::instance()->apply_box_prices( $this );
		// WooCommerce's cart-session hook runs after totals, including rollbacks.
		$this->set_session();
	}
	public function set_session() { WC()->session->cart = $this->items; }
	public function get_cart_contents_count() { return array_sum( array_column( $this->items, 'quantity' ) ); }
}

class AjaxSession {
	public $cart = array();
	public function set_customer_session_cookie( $set ) {}
	public function save_data() {
		$cart = $this->cart;
		foreach ( $cart as &$item ) { unset( $item['data'] ); }
		unset( $item );
		file_put_contents( $GLOBALS['storage'], json_encode( $cart ) );
	}
}

$request = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$storage = $request['storage'];
if ( ! is_file( $storage ) || strpos( realpath( $storage ), realpath( sys_get_temp_dir() ) . DIRECTORY_SEPARATOR ) !== 0 ) {
	throw new RuntimeException( 'Only an existing temporary test storage file is allowed.' );
}
$cart = new AjaxCart();
$cart->items = json_decode( file_get_contents( $storage ), true );
$cart->fail_after = $request['failAfter'] ?? 0;
foreach ( $cart->items as &$item ) { $item['data'] = clone wc_get_product( $item['product_id'] ); }
unset( $item );
$GLOBALS['olr_test_wc'] = (object) array( 'cart' => $cart, 'session' => new AjaxSession() );
WC()->session->cart = $cart->items;
$_POST = $_REQUEST = $request['post'] ?? array();
try {
	if ( 'nonce' === $request['mode'] ) {
		$tick = wp_nonce_tick( 'olr_box_cart' ) - ( ! empty( $request['expired'] ) ? 2 : 0 );
		wp_send_json_success( array( 'nonce' => substr( wp_hash( $tick . '|olr_box_cart|123|' . wp_get_session_token(), 'nonce' ), -12, 10 ) ) );
	}
	$callbacks = $GLOBALS['olr_test_hooks'][ 'wp_ajax_' . ( $_POST['action'] ?? '' ) ] ?? array();
	if ( ! $callbacks ) { wp_die( 0, 400 ); }
	call_user_func( $callbacks[0] );
} catch ( AjaxResponse $response ) {
	// Model normal WooCommerce shutdown persistence after the AJAX handler.
	WC()->session->save_data();
	echo json_encode( array( 'status' => $response->status, 'body' => $response->body ) );
}
