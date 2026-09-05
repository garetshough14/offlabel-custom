<?php
/** Real WC_Discounts + real Advanced Cart Offers, with in-memory WP/product/session fixtures (no live site/DB). */
namespace Automattic\WooCommerce\Utilities {
	class NumberUtil { public static function round( $n, $p = 0 ) { return round( $n, $p ); } public static function ceil( $n ) { return ceil( $n ); } }
}
namespace {
set_error_handler(static function($severity,$message,$file,$line){throw new \ErrorException($message,0,$severity,$file,$line);});
define( 'ABSPATH', __DIR__ . '/' );
define( 'WC_VERSION', '11.1.0' );
define( 'OLR_ACO_VERSION', '1.1.0' );
$wp_filter = array(); $products = array(); $coupons = array(); $options = array( 'olr_best_offer_enabled' => 'yes' ); $notices = array();
function add_filter( $name, $fn, $priority = 10, $argc = 1 ) { global $wp_filter; if ( ! isset( $wp_filter[$name] ) ) $wp_filter[$name] = (object) array( 'callbacks' => array() ); $wp_filter[$name]->callbacks[$priority][] = array( 'function' => $fn, 'accepted_args' => $argc ); }
function add_action( ...$args ) { add_filter( ...$args ); }
function apply_filters( $name, $value, ...$args ) { global $wp_filter; $callbacks = $wp_filter[$name]->callbacks ?? array(); ksort($callbacks); foreach ($callbacks as $rows) foreach ($rows as $row) $value = call_user_func_array($row['function'], array_slice(array_merge(array($value), $args), 0, $row['accepted_args'])); return $value; }
function has_filter($name) { return !empty($GLOBALS['wp_filter'][$name]); }
function remove_action($name,$fn,$priority=10) { global $wp_filter; foreach (($wp_filter[$name]->callbacks[$priority] ?? array()) as $key=>$row) if($row['function']===$fn) unset($wp_filter[$name]->callbacks[$priority][$key]); }
function remove_filter(...$args) { remove_action(...$args); }
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_get_environment_type() { return $GLOBALS['environment'] ?? 'staging'; }
function wc_get_coupon_id_by_code($code) { return isset($GLOBALS['coupons'][$code]) ? 1 : 0; }
function wc_get_product($id) { return isset($GLOBALS['products'][$id]) ? clone $GLOBALS['products'][$id] : false; }
function wc_get_product_cat_ids($id) { return $GLOBALS['products'][$id]->cats ?? array(); }
function get_term_by($field,$slug,$taxonomy) { return $slug==='bundles' ? (object)array('term_id'=>99) : false; }
function wc_get_product_coupon_types() { return apply_filters('woocommerce_product_coupon_types',array('percent','fixed_product')); }
function wc_get_coupon_types() { return apply_filters('woocommerce_coupon_discount_types',array('percent'=>'Percent','fixed_product'=>'Fixed product','fixed_cart'=>'Fixed cart')); }
function wc_add_number_precision($n) { return round($n*100, 4); }
function wc_add_number_precision_deep($data) { return is_array($data) ? array_map('wc_add_number_precision_deep',$data) : wc_add_number_precision($data); }
function wc_remove_number_precision($n) { return $n/100; }
function wc_remove_number_precision_deep($data) { return is_array($data) ? array_map('wc_remove_number_precision_deep',$data) : $data/100; }
function wc_round_discount($n,$p) { return round($n,$p); }
function wc_get_price_decimals() { return 2; }
function wp_list_pluck($items,$key) { return array_map(static function($item) use($key) { return is_object($item)?$item->$key:$item[$key]; },$items); }
function absint($n) { return abs((int)$n); }
function __($text,$domain='') { return $text; }
function _n($one,$many,$number,$domain='') { return $number===1?$one:$many; }
function esc_html__($text,$domain='') { return $text; }
function esc_html($text) { return $text; }
function wp_kses($text,$tags) { return $text; }
function wc_price($n) { return '$'.number_format($n,2); }
function get_current_user_id() { return $GLOBALS['test_user_id'] ?? 0; }
function is_user_logged_in() { return get_current_user_id()>0; }
function current_user_can($capability,...$args) { return !empty($GLOBALS['test_caps'][$capability]); }
function wp_get_session_token() { return $GLOBALS['test_token']??''; }
function get_user_meta($id,$key,$single=true) { return $GLOBALS['test_user_meta'][$id][$key]??''; }
function wc_format_coupon_code($code) { return strtolower($code); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wc_add_notice($message,$type) { $GLOBALS['notices'][]=$message; }
function wc_has_notice($message,$type) { return in_array($message,$GLOBALS['notices'],true); }
function wc_get_price_to_display($product,$args=array()) { return $args['price'] ?? $product->get_price(); }
function WC() { return $GLOBALS['wc']; }
class WP_Error { public $message; public function __construct($code,$message,$data=array()) { $this->message=$message; } }
class WC_Product {
	public $id,$parent,$price,$regular,$sale,$meta,$cats;
	public function __construct($id,$price=65,$parent=0,$cats=array()) { $this->id=$id;$this->regular=$this->price=$price;$this->sale=null;$this->parent=$parent;$this->meta=array();$this->cats=$cats; }
	public function get_id(){return $this->id;} public function get_parent_id(){return $this->parent;} public function get_price(){return $this->price;} public function get_regular_price(){return $this->regular;} public function set_price($price){$this->price=$price;} public function get_meta($key,$single=true){return $this->meta[$key]??'';} public function is_on_sale(){return $this->sale!==null && $this->sale<$this->regular;} public function get_category_ids(){return $this->cats;} public function is_type($type){return $type===($this->parent?'variation':'simple');} public function get_name(){return 'Product '.$this->id;}
}
class WC_Coupon {
	const E_WC_COUPON_EXCLUDED_CATEGORIES=114,E_WC_COUPON_EXCLUDED_PRODUCTS=113,E_WC_COUPON_INVALID_FILTERED=100,E_WC_COUPON_NOT_YOURS_REMOVED=102,E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK=115,E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST=116,E_WC_COUPON_USAGE_LIMIT_REACHED=106;
	public $props;
	public function __construct($code) { $virtual=apply_filters('woocommerce_get_shop_coupon_data',false,$code); $this->props=array_merge(array('code'=>$code,'discount_type'=>'percent','amount'=>0,'meta'=>array(),'id'=>isset($GLOBALS['coupons'][$code])?1:0,'status'=>'publish','limit_usage_to_x_items'=>null),$virtual?:($GLOBALS['coupons'][$code]??array())); }
	public function __call($method,$args) { $key=substr($method,4); if(strpos($method,'set_')===0){ if($key==='discount_type'&&!isset(wc_get_coupon_types()[$args[0]])) throw new \Exception('Invalid coupon type'); $this->props[$key]=$args[0];return;} if(array_key_exists($key,$this->props))return $this->props[$key]; if(in_array($key,array('product_ids','product_categories','excluded_product_ids','excluded_product_categories','email_restrictions'),true))return array(); return 0; }
	public function get_meta($key,$single=true){return $this->props['meta'][$key]??'';}
	public function is_type($type){return in_array($this->props['discount_type'],(array)$type,true);}
	public function get_coupon_error($code){return 'Coupon error '.$code;}
	public function get_context_based_coupon_errors($code){return array();}
	public function is_valid_for_cart(){return $this->is_type('fixed_cart');}
	public function is_valid_for_product($p,$values=array()) {
		if(!$this->is_type(wc_get_product_coupon_types()))return false;
		$ids=array($p->get_id(),$p->get_parent_id()); $cats=wc_get_product_cat_ids($p->get_parent_id()?:$p->get_id());
		$valid=(!$this->get_product_ids()&&!$this->get_product_categories())||array_intersect($ids,$this->get_product_ids())||array_intersect($cats,$this->get_product_categories());
		if(array_intersect($ids,$this->get_excluded_product_ids())||array_intersect($cats,$this->get_excluded_product_categories())||($this->get_exclude_sale_items()&&$p->is_on_sale()))$valid=false;
		return apply_filters('woocommerce_coupon_is_valid_for_product',(bool)$valid,$p,$this,$values);
	}
	public function get_discount_amount($amount,$item=null,$single=false) { $discount=$this->is_type('percent')?$amount*$this->get_amount()/100:($this->is_type('fixed_product')?min($amount,$this->get_amount()):0);return apply_filters('woocommerce_coupon_get_discount_amount',$discount,$amount,$item,$single,$this); }
}
class WC_Cart {
	public $cart_contents=array(),$codes=array(),$fees=array(),$discounts=array();
	public function get_cart(){return $this->cart_contents;} public function get_applied_coupons(){return $this->codes;} public function set_applied_coupons($codes){$this->codes=$codes;} public function is_empty(){return !$this->cart_contents;} public function get_fees(){return $this->fees;} public function get_displayed_subtotal(){return array_sum(array_map(static function($line){return $line['data']->get_price()*$line['quantity'];},$this->cart_contents));} public function display_prices_including_tax(){return false;} public function get_coupon_discount_amount($code){return array_sum($this->discounts[$code]??array())/100;}
}
class TestSession { public $data=array(); public function get($key,$default=null){return $this->data[$key]??$default;}public function set($key,$value){$this->data[$key]=$value;} }
class TestOrderLine {
	public $id,$product,$qty,$subtotal,$meta=array();
	public function __construct($id,$product,$qty,$subtotal){$this->id=$id;$this->product=$product;$this->qty=$qty;$this->subtotal=$subtotal;}
	public function get_id(){return $this->id;}public function get_product(){return $this->product;}public function get_quantity(){return $this->qty;}public function get_subtotal(){return $this->subtotal;}public function get_subtotal_tax(){return 0;}
	public function get_meta($key,$single=true){return $this->meta[$key]??'';}public function add_meta_data($key,$value,$unique=true){$this->meta[$key]=$value;}public function update_meta_data($key,$value){$this->meta[$key]=$value;}public function delete_meta_data($key){unset($this->meta[$key]);}
}
class WC_Order {public $items=array(),$meta=array();public function get_items($type='line_item'){return $this->items;}public function get_prices_include_tax(){return false;}public function get_meta($key){return $this->meta[$key]??'';}public function update_meta_data($key,$value){$this->meta[$key]=$value;}}
require __DIR__.'/../output/pricing-audit/class-wc-discounts.php';
require 'C:/Users/User/Downloads/advanced-cart-offers-for-woocommerce/includes/class-olr-aco-rule.php';
require 'C:/Users/User/Downloads/advanced-cart-offers-for-woocommerce/includes/class-olr-aco-rule-engine.php';
(new OLR_ACO_Rule_Engine())->register_hooks();
require __DIR__.'/../wordpress-plugins/off-label-best-offer/off-label-best-offer.php';
$wc=(object)array('session'=>new TestSession(),'cart'=>null);
$engine=new OLR_Best_Offer();$engine->boot();
$checks=0;
function eq($actual,$expected,$label){++$GLOBALS['checks'];if(is_numeric($actual)&&is_numeric($expected)?abs($actual-$expected)>0.00001:$actual!==$expected)throw new \RuntimeException($label.' expected '.json_encode($expected).' got '.json_encode($actual));}
function line($id,$qty,$extra=array()){ $product=wc_get_product($id);return array_merge(array('product_id'=>$product->get_parent_id()?:$id,'variation_id'=>$product->get_parent_id()?$id:0,'quantity'=>$qty,'data'=>$product),$extra); }
function run_cart($lines,$codes=array(),$existing=null){
	global $wc,$engine;$cart=$existing?:new WC_Cart();$wc->cart=$cart;$cart->cart_contents=$lines;$cart->codes=$codes;$engine->prepare($cart);$discounts=new WC_Discounts($cart);
	foreach($cart->codes as $code){$result=$discounts->apply_coupon(new WC_Coupon($code));if(is_wp_error($result)&&$code===OLR_Best_Offer::AUTO)throw new \RuntimeException('Automatic coupon invalid: '.$result->message);}
	$cart->discounts=$discounts->get_discounts(true);$per_line=$discounts->get_discounts_by_item(true);
	foreach($cart->cart_contents as $key=>&$item){$item['line_subtotal']=$item['quantity']*$item['data']->get_price();$item['line_total']=$item['line_subtotal']-($per_line[$key]??0)/100;$item['line_subtotal_tax']=$item['line_tax']=0;}unset($item);$engine->finish($cart);return $cart;
}
function total($cart){return array_sum(array_column($cart->cart_contents,'line_total'));}
$products[1]=new WC_Product(1);$products[2]=new WC_Product(2);$products[338]=new WC_Product(338);$products[3]=new WC_Product(3,65,0,array(99));
$coupons['offduty']=array('discount_type'=>'percent','amount'=>30,'minimum_amount'=>50,'individual_use'=>true,'free_shipping'=>true,'excluded_product_ids'=>array(338),'excluded_product_categories'=>array(99));
$coupons['fifteen']=array('discount_type'=>'percent','amount'=>15);
$coupons['thirty']=array('discount_type'=>'percent','amount'=>30);
foreach(array(1=>65,2=>130,3=>175.50,4=>234,5=>276.25,8=>442,9=>497.25,10=>520,12=>624) as $qty=>$expected){eq(total(run_cart(array('a'=>line(1,$qty)))),$expected,'quantity '.$qty);}
eq(total(run_cart(array('a'=>line(1,10)),array('offduty'))),455,'30% replaces volume, not 364');
$cart=run_cart(array('a'=>line(1,10)),array('fifteen'));eq(total($cart),520,'lower coupon suppressed');eq($cart->get_coupon_discount_amount('fifteen'),0,'losing coupon zero');eq(in_array('fifteen',$cart->codes,true),true,'losing coupon retained');
eq(total(run_cart(array('a'=>line(1,5),'b'=>line(338,10)),array('offduty'))),747.50,'excluded SKU keeps its volume');
eq(total(run_cart(array('a'=>line(3,10)),array('offduty'))),520,'excluded category');
eq(total(run_cart(array('a'=>line(1,2),'b'=>line(1,1)))),175.50,'same SKU split lines');
eq(total(run_cart(array('a'=>line(1,2),'b'=>line(2,2)))),260,'different SKU no aggregation');
$products[11]=new WC_Product(11,65,1);$products[12]=new WC_Product(12,65,1);
eq(total(run_cart(array('a'=>line(11,2),'b'=>line(12,2)))),260,'variations not combined');
$products[1]->meta['_olr_volume_enabled']='no';eq(total(run_cart(array('a'=>line(1,10)))),650,'disabled product');unset($products[1]->meta['_olr_volume_enabled']);
$products[1]->meta['_olr_volume_override']='no';eq(total(run_cart(array('a'=>line(1,10)),array('offduty'))),520,'promotional override disabled');eq(total(run_cart(array('a'=>line(1,1)),array('offduty'))),45.50,'override no without a tier');unset($products[1]->meta['_olr_volume_override']);
$box=array('olr_box_id'=>'test','olr_box_size'=>5,'olr_box_role'=>'parent');
$boxlines=array('a'=>line(1,1,$box),'b'=>line(2,4,array_merge($box,array('olr_box_role'=>'component'))));
eq(total(run_cart($boxlines)),243.75,'five box');eq(total(run_cart($boxlines,array('offduty'))),243.75,'offduty excludes Bundles and whole box');eq(total(run_cart($boxlines,array('thirty'))),227.50,'eligible coupon replaces box');
$coupons['thirty']['meta']['_olr_exclude_boxes']='yes';eq(total(run_cart($boxlines,array('thirty'))),243.75,'explicit box exclusion');unset($coupons['thirty']['meta']);
$coupons['thirty']['excluded_product_ids']=array(2);eq(total(run_cart($boxlines,array('thirty'))),243.75,'one excluded member protects whole box');unset($coupons['thirty']['excluded_product_ids']);
$mixed=$boxlines;$mixed['c']=line(1,3);eq(total(run_cart($mixed,array('offduty'))),380.25,'box plus standalone same SKU, separate groups');
$cart=run_cart(array('a'=>line(1,10)));foreach(array(8=>442,4=>234,2=>130) as $q=>$expected){$cart->cart_contents['a']['quantity']=$q;$cart=run_cart($cart->cart_contents,array(),$cart);eq(total($cart),$expected,'same session recalculation '.$q);}
$options['elex_discount_per_payment_method_options']=array(array('id'=>'zelle','checkbox_value'=>'yes','discount_type'=>'percentage','value'=>5));$wc->session->set('chosen_payment_method','zelle');
eq(total(run_cart(array('a'=>line(1,1)))),61.75,'payment wins alone');eq(total(run_cart(array('a'=>line(1,10)),array('offduty'))),455,'payment cannot stack');$wc->session->set('chosen_payment_method','card');eq(total(run_cart(array('a'=>line(1,1)))),65,'payment switch removes discount');
$products[1]->sale=$products[1]->price=52;eq(total(run_cart(array('a'=>line(1,5)))),260,'sale competes with volume');eq(total(run_cart(array('a'=>line(1,10)),array('offduty'))),455,'coupon against regular not sale');$products[1]->sale=null;$products[1]->price=65;
$coupons['fixed']=array('discount_type'=>'fixed_cart','amount'=>100);eq(total(run_cart(array('a'=>line(1,10)),array('fixed'))),520,'fixed-cart lower');$coupons['fixed']['amount']=200;eq(total(run_cart(array('a'=>line(1,10)),array('fixed'))),450,'fixed-cart higher');
$coupons['peritem']=array('discount_type'=>'fixed_product','amount'=>20);eq(total(run_cart(array('a'=>line(1,10)),array('peritem'))),450,'fixed-product higher');
$coupons['limited']=array('discount_type'=>'percent','amount'=>50,'limit_usage_to_x_items'=>1);eq(total(run_cart(array('a'=>line(1,10)),array('limited'))),520,'50% one item loses to 20% ten items');
$coupons['bogo']=array('discount_type'=>'olr_advanced_offer','amount'=>100,'meta'=>array('_olr_aco_mode'=>'bogo','_olr_aco_qualifying_scope'=>'any','_olr_aco_target_scope'=>'any','_olr_aco_buy_qty'=>1,'_olr_aco_get_qty'=>1,'_olr_aco_repeat'=>'yes','_olr_aco_allow_reuse'=>'no'));
eq(total(run_cart(array('a'=>line(1,10)),array('bogo'))),325,'real ACO buy one get one');
$coupons['bogo']['meta']['_olr_aco_repeat']='no';eq(total(run_cart(array('a'=>line(1,10)),array('bogo'))),520,'one free bottle loses to volume by dollars');
eq(total(run_cart(array('a'=>line(1,1)),array('bogo'))),65,'unqualified BOGO rejected');
$coupons['bogo']['excluded_product_categories']=array(99);eq(total(run_cart($boxlines,array('bogo'))),243.75,'BOGO cannot bypass excluded box');
$coupons['minimum']=array('discount_type'=>'percent','amount'=>30,'minimum_amount'=>1000);eq(total(run_cart(array('a'=>line(1,10)),array('minimum'))),520,'minimum spend native validation');
eq((new WC_Coupon('offduty'))->get_free_shipping(),true,'free shipping unchanged');eq((new WC_Coupon('offduty'))->get_individual_use(),true,'individual use unchanged');eq((new WC_Coupon('offduty'))->get_discount_type(),'percent','runtime type never persisted');
$bad=line(1,3);$bad['data']->set_price(50);run_cart(array('a'=>$bad));$engine->check_cart();eq(count($notices)>0,true,'unknown price modifier pauses checkout');
$cart=run_cart(array('a'=>line(1,3)));$cart->fees[]= (object)array('amount'=>-5);$engine->finish($cart);eq(count($wc->session->get('olr_best_offer_errors'))>0,true,'negative fee caught');
$cart=run_cart(array('a'=>line(1,10),'b'=>line(338,10)),array('offduty'));
$order=new WC_Order();$engine->assert_safe_order($order);
foreach($cart->cart_contents as $key=>$values){$id=$key==='a'?101:102;$item=new TestOrderLine($id,$values['data'],$values['quantity'],$values['line_subtotal']);$engine->order_meta($item,$key,$values,$order);$order->items[$id]=$item;}
$order_discount=new WC_Discounts($order);
foreach($cart->codes as $code){$item=new TestOrderLine(201,null,0,0);$coupon=new WC_Coupon($code);$engine->order_coupon_meta($item,$code,$coupon,$order);$replay=$engine->replay_order_coupon($coupon,$code,$item,$order);$order_discount->apply_coupon($replay,false);}
eq(array_sum($order_discount->get_discounts_by_item(true)),32500,'order replay preserves mixed coupon and volume allocation');
eq($order->items[101]->get_meta('_olr_best_offer_name'),'offduty','order records winning coupon');eq($order->items[102]->get_meta('_olr_best_offer_name'),'Volume savings','order records excluded product offer');
$order->items[101]->qty=8;$caught=false;try{$engine->replay_order_coupon(new WC_Coupon(OLR_Best_Offer::AUTO),OLR_Best_Offer::AUTO,$item,$order);}catch(\Exception $e){$caught=true;}eq($caught,true,'edited-order repricing requires review, not silent stacking');
$probe=new WC_Discounts(run_cart($boxlines));eq(is_wp_error($probe->is_coupon_valid(new WC_Coupon('offduty'))),true,'excluded-only box cannot unlock offduty free shipping');
$coupons['thirty']['excluded_product_ids']=array(1);eq(total(run_cart(array('a'=>line(1,10),'b'=>line(2,1)),array('thirty'))),565.50,'independent winning offers across mixed SKUs');unset($coupons['thirty']['excluded_product_ids']);
$environment='production';$disabled=new OLR_Best_Offer();$disabled->boot();$untouched=new WC_Cart();$untouched->cart_contents=array('a'=>line(1,10));$untouched->codes=array(OLR_Best_Offer::AUTO,'offduty');$disabled->prepare($untouched);eq($untouched->codes,array('offduty'),'production gate removes only its own automatic coupon');eq($untouched->cart_contents['a']['data']->get_price(),65,'production gate does not reprice');$environment='staging';
$environment='production';$test_user_id=42;$test_caps=array('manage_options'=>true,'manage_woocommerce'=>true);$test_token='browser-session-one';$test_user_meta=array();
eq(OLR_Offer_Live_Preview::active(),false,'administrator not opted in');
$test_user_meta[42][OLR_Offer_Live_Preview::META]=array('token'=>hash('sha256',$test_token),'expires'=>time()+1700);
eq(OLR_Offer_Live_Preview::active(),true,'explicit administrator session granted');
$test_token='another-browser';eq(OLR_Offer_Live_Preview::active(),false,'second browser excluded');$test_token='browser-session-one';
$test_user_id=43;eq(OLR_Offer_Live_Preview::active(),false,'different administrator excluded');$test_user_id=42;
$test_caps['manage_options']=false;eq(OLR_Offer_Live_Preview::active(),false,'shop manager excluded');$test_caps['manage_options']=true;
$test_user_meta[42][OLR_Offer_Live_Preview::META]['expires']=time()-1;eq(OLR_Offer_Live_Preview::active(),false,'expired session excluded');
$test_user_meta[42][OLR_Offer_Live_Preview::META]['expires']=time()+1700;
$preview_guard=new OLR_Offer_Live_Preview();$wc->cart=new WC_Cart();
eq(is_wp_error($preview_guard->block_order(null,null)),true,'classic order creation blocked in preview');eq($preview_guard->gateways(array('card'=>new \stdClass())),array(),'gateways disabled in preview');
$marked=$preview_guard->mark_item(array(),1,0,3);eq($marked[OLR_Offer_Live_Preview::MARKER],true,'new test items marked');
$preview_engine=new OLR_Best_Offer();$preview_engine->boot();$wc->cart->cart_contents=array('a'=>line(1,3));$preview_engine->prepare($wc->cart);eq($wc->cart->cart_contents['a'][OLR_Offer_Live_Preview::MARKER],true,'all preview lines protected');
$caught=false;try{$preview_engine->assert_safe_order(new WC_Order());}catch(\Exception $e){$caught=true;}eq($caught,true,'final order guard blocks preview');
$test_user_id=0;eq(OLR_Offer_Live_Preview::active(),false,'logout immediately stops preview');eq(is_wp_error($preview_guard->block_order(null,null)),true,'preview items cannot be ordered after logout');
class TestRestRequest {public $method,$route;public function __construct($method,$route){$this->method=$method;$this->route=$route;}public function get_method(){return $this->method;}public function get_route(){return $this->route;}}
foreach(array('/wc/store/v1/checkout','/wc/store/v1/checkout/123','/wc/store/v1/order/123','/wc/store/v1/batch') as $route){eq(is_wp_error($preview_guard->block_store_checkout(null,null,new TestRestRequest('POST',$route))),true,'Store API blocked: '.$route);}
eq($preview_guard->block_store_checkout(null,null,new TestRestRequest('GET','/wc/store/v1/cart')),null,'read-only cart remains accessible');
$wc->cart=new WC_Cart();$wc->cart->cart_contents=array('normal'=>line(1,2));eq($preview_guard->block_order(123,null),123,'normal customer order untouched');$gateways=array('card'=>new \stdClass());eq($preview_guard->gateways($gateways),$gateways,'normal customer gateways untouched');eq($preview_guard->mark_item(array(),1,0,1),array(),'normal customer cart data untouched');
$environment='staging';
mt_srand(7);for($i=0;$i<1000;$i++){ $weights=array('a'=>mt_rand(0,999999),'b'=>mt_rand(0,999999),'c'=>mt_rand(0,999999));$amount=mt_rand(0,array_sum($weights)+100);$map=OLR_Offer_Planner::allocate($amount,$weights);eq(array_sum($map),min($amount,array_sum($weights)),'allocation preserves sum');foreach($map as $key=>$value)eq($value>=0&&$value<=$weights[$key],true,'allocation capped'); }
echo "PASS: $checks assertions; real WooCommerce 11.1.0 discount calculator and Advanced Cart Offers 1.1.0. WP persistence, real checkout, taxes and payments still require staging.\n";
}
