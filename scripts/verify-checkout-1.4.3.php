<?php
// Scoped PHP rendering tests. No WordPress database or live checkout requests.
define('ABSPATH', __DIR__);
$hooks = array();
function add_action($name,$callback,...$args) { $GLOBALS['hooks'][$name][]=$callback; }
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function __($s,...$args) {return $s;}
function esc_html__($s,...$args) {return htmlspecialchars($s, ENT_QUOTES);}
function esc_attr__($s,...$args) {return htmlspecialchars($s, ENT_QUOTES);}
function wp_kses_post($s) {return $s;} // Trusted fixture markup only.
class ShippingSession {public $enabled=true; function get($key){return $this->enabled;}}
class ShippingCart {
 public $needs=true; public $calculated=true; public $formatted='<span class="amount">$8.99</span>'; public $calls=0;
 function needs_shipping(){return $this->needs;}
 function has_calculated_shipping(){return $this->calculated;}
 function get_cart_shipping_total(){$this->calls++;return $this->formatted;}
}
$wc=(object)array('session'=>new ShippingSession(),'cart'=>new ShippingCart());
function WC(){return $GLOBALS['wc'];}
$version = in_array('--1.4.4', $argv, true) ? '1.4.4' : '1.4.3';
require dirname(__DIR__).'/output/checkout-update-'.$version.'/off-label-checkout-test/off-label-checkout-test.php';
function render_shipping(){ob_start();olr_checkout_test_shipping_summary();return ob_get_clean();}
function check($condition,$message){if(!$condition)throw new Exception($message);}
check(in_array('olr_checkout_test_shipping_summary',$hooks['woocommerce_review_order_before_shipping'],true),'Native checkout hook registered');
check(str_contains(render_shipping(),'$8.99'),'Native paid shipping total');
$wc->cart->formatted='Free!';check(str_contains(render_shipping(),'Free!'),'Free shipping from native formatter');
$wc->cart->formatted='$9.73 <small class="tax_label">including tax</small>';check(str_contains(render_shipping(),$wc->cart->formatted),'Preserve native currency/tax formatting');
$wc->cart->calculated=false;$before=$wc->cart->calls;check(str_contains(render_shipping(),'Calculated after address') && $before===$wc->cart->calls,'No invented zero amount before calculation');
$wc->cart->needs=false;check(render_shipping()==='','Virtual-only carts unchanged');
$wc->cart->needs=true;$wc->session->enabled=false;check(render_shipping()==='','Other checkout sessions unchanged');
$wc->session=null;check(render_shipping()==='','Missing session safe');
echo "PASS: native shipping amount, free/tax formatting, pending address, virtual cart, route/session guards.\n";
