<?php
/** Live opt-in, switch-off, permission and persisted-allocation fixture checks. */
require __DIR__ . '/verify-best-offer-final.php';
$start_checks=$checks;
class QaAdminExit extends Exception {}
class QaAdminRedirect extends Exception {}
function wp_die($message, ...$args) { throw new QaAdminExit($message); }
function check_admin_referer($action) { if (empty($GLOBALS['qa_nonce_valid'])) throw new QaAdminExit('Invalid nonce'); }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; }
function admin_url($path) { return 'https://fixture.invalid/wp-admin/'.$path; }
function wp_safe_redirect($url) { throw new QaAdminRedirect($url); }
function qa_save_live($expected) {
 try { (new OLR_Offer_Admin())->save_live_settings(); throw new RuntimeException('Expected redirect or denial'); }
 catch(QaAdminRedirect $e) { eq($expected,'saved','live settings authorized save'); }
 catch(QaAdminExit $e) { eq(strpos($e->getMessage(),$expected)!==false,true,'live settings denied: '.$expected); }
}

$environment='production';$test_user_id=0;$test_caps=array();$test_user_meta=array();$test_token='';
unset($options['olr_best_offer_live_enabled']);
$options['olr_best_offer_enabled']='yes';
$wc->cart=new WC_Cart();
eq(OLR_Best_Offer::live_requested(),false,'staging option cannot enable public pricing');
$engine=new OLR_Best_Offer();$engine->boot();
$cart=run_cart(array('a'=>line(901,3)));
eq(total($cart),195,'public default remains original price');
eq(in_array(OLR_Best_Offer::AUTO,$cart->codes,true),false,'default off adds no automatic coupon');

$_POST=array('enabled'=>'yes','acknowledge_live_risk'=>'yes');$qa_nonce_valid=true;
qa_save_live('Administrator access required');
$test_user_id=42;$test_caps=array('manage_woocommerce'=>true);
qa_save_live('Administrator access required');
$test_caps['manage_options']=true;$test_token='session-live-test';$qa_nonce_valid=false;
qa_save_live('Invalid nonce');$qa_nonce_valid=true;
$environment='staging';qa_save_live('Use the staging setting');$environment='production';
unset($_POST['acknowledge_live_risk']);qa_save_live('Confirm the backup');
$_POST['acknowledge_live_risk']='yes';
$test_user_meta[42][OLR_Offer_Live_Preview::META]=array('token'=>hash('sha256',$test_token),'expires'=>time()+1700);
qa_save_live('End private preview');
$test_user_meta=array();
$wc->cart->cart_contents=array('marked'=>line(901,1,array(OLR_Offer_Live_Preview::MARKER=>true)));
qa_save_live('End private preview');
$wc->cart=new WC_Cart();
$coupons[OLR_Best_Offer::AUTO]=array('amount'=>1);
qa_save_live('reserved automatic coupon code conflict');unset($coupons[OLR_Best_Offer::AUTO]);
qa_save_live('saved');eq(OLR_Best_Offer::live_requested(),true,'explicit acknowledged enable saved');

// A new guest request receives pricing, but not preview purchase restrictions.
$test_user_id=0;$test_caps=array();$test_token='';
$engine=new OLR_Best_Offer();$engine->boot();
$cart=run_cart(array('a'=>line(901,3),'b'=>line(902,1)));
eq(total($cart),250.5,'explicit live opt-in enables guest pricing');
eq(isset($cart->cart_contents['a'][OLR_Offer_Live_Preview::MARKER]),false,'live guest items are not marked as preview');
$guard=new OLR_Offer_Live_Preview();
eq($guard->block_order(123,null),123,'ordinary live order is not blocked by preview guard');
$gateways=array('fixture-offline'=>new stdClass());
eq($guard->gateways($gateways),$gateways,'ordinary live gateways left available');
$live_order=new WC_Order();$engine->assert_safe_order($live_order);
eq($live_order->get_meta('_olr_best_offer_version'),'0.3.0','live order receives version metadata');
$live_coupon_items=array();$id=801;
foreach($cart->cart_contents as $key=>$values) {
 $item=new TestOrderLine($id,$values['data'],$values['quantity'],$values['line_subtotal']);
 $engine->order_meta($item,$key,$values,$live_order);$live_order->items[$id++]=$item;
}
foreach($cart->codes as $code) {
 $item=new TestOrderLine($id++,null,0,0);
 $engine->order_coupon_meta($item,$code,new WC_Coupon($code),$live_order);$live_coupon_items[$code]=$item;
}
foreach(array('/wc/store/v1/checkout','/wc/store/v1/order/123','/wc/store/v1/batch') as $route) {
 eq(is_wp_error($engine->guard_store_checkout(null,null,new TestRestRequest('POST',$route))),true,'unsupported Store API writes blocked: '.$route);
}
eq($engine->guard_store_checkout(null,null,new TestRestRequest('GET','/wc/store/v1/cart')),null,'Store API reads untouched');
eq($engine->guard_store_checkout(null,null,new TestRestRequest('POST','/wc/store/v1/cart/add-item')),null,'ordinary Store API cart addition untouched');
eq($engine->guard_store_checkout(null,null,new TestRestRequest('POST','/wc/v3/orders')),null,'admin REST route not changed by Store API guard');

// Old preview markers still protect a cart, even with public pricing enabled.
$wc->cart->cart_contents['a'][OLR_Offer_Live_Preview::MARKER]=true;
eq(is_wp_error($guard->block_order(null,null)),true,'live opt-in cannot make old preview carts purchasable');
$caught=false;try{$engine->assert_safe_order(new WC_Order());}catch(Exception $e){$caught=true;}
eq($caught,true,'final order guard still rejects marked preview cart');

// Disabling must work without acknowledgement, even when compatibility has failed.
$test_user_id=42;$test_caps=array('manage_options'=>true,'manage_woocommerce'=>true);
$coupons[OLR_Best_Offer::AUTO]=array('amount'=>1);$_POST=array();
qa_save_live('saved');unset($coupons[OLR_Best_Offer::AUTO]);
eq(OLR_Best_Offer::live_requested(),false,'switch off saved without backup acknowledgement or compatibility pass');

// Simulate a genuinely fresh request: no enabled instance's hooks can mask an
// adapter missing from the disabled plugin's constructor.
$wp_filter=array();$test_user_id=0;$test_caps=array();$wc->cart=new WC_Cart();
$engine=new OLR_Best_Offer();$engine->boot();
$cart=run_cart(array('a'=>line(901,3)),array(OLR_Best_Offer::AUTO));
eq(total($cart),195,'switch off restores original pricing on new request');
eq($cart->codes,array(),'switch off removes stale automatic cart coupon');
eq(has_filter('woocommerce_order_recalculate_coupons_coupon_object'),true,'saved-order adapter remains registered while live pricing is off');
$replay=new WC_Discounts($live_order);
foreach($live_coupon_items as $code=>$item) {
 $coupon=apply_filters('woocommerce_order_recalculate_coupons_coupon_object',new WC_Coupon($code),$code,$item,$live_order);
 $replay->apply_coupon($coupon,false);
}
eq(array_sum($replay->get_discounts_by_item(true)),1950,'saved allocation replays exactly after live pricing turned off');

// Compatibility failure with an enabled switch pauses checkout instead of
// silently placing an order at a fallback price.
$options['olr_best_offer_live_enabled']='yes';$coupons[OLR_Best_Offer::AUTO]=array('amount'=>1);
$engine=new OLR_Best_Offer();$engine->boot();$notices=array();$engine->check_cart();
eq(count($notices)>0,true,'live compatibility failure displays checkout pause');
$caught=false;try{$engine->assert_safe_order(new WC_Order());}catch(Exception $e){$caught=true;}
eq($caught,true,'live compatibility failure blocks final classic order creation');
unset($coupons[OLR_Best_Offer::AUTO]);
// Render both switch states to verify the instructions and explicit opt-in UI.
function esc_url($url) { return htmlspecialchars($url,ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string)$text,ENT_QUOTES); }
function home_url($path) { return 'https://fixture.invalid'.$path; }
function checked($actual,$expected=true,$echo=true) { return $actual===$expected?'checked="checked"':''; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="fixture-only">'; }
function submit_button($text,$type='primary') { echo '<button type="submit">'.htmlspecialchars($text,ENT_QUOTES).'</button>'; }
$test_user_id=42;$test_caps=array('manage_options'=>true,'manage_woocommerce'=>true);$test_user_meta=array();$wc->cart=new WC_Cart();
foreach(array('no','yes') as $state) {
 $options['olr_best_offer_live_enabled']=$state;
 ob_start();(new OLR_Offer_Admin())->page();$html=ob_get_clean();
 eq(strpos($html,'name="action" value="olr_save_live_best_offer"')!==false,true,'live settings form rendered '.$state);
 eq(strpos($html,'name="acknowledge_live_risk"')!==false,$state==='no','risk acknowledgement required on first enable only '.$state);
 eq(strpos($html,'Live pricing: '.($state==='yes'?'ON for all shoppers':'OFF'))!==false,true,'live status rendered '.$state);
}
$test_caps['manage_options']=false;
ob_start();(new OLR_Offer_Admin())->page();$html=ob_get_clean();
eq(strpos($html,'olr_save_live_best_offer')===false,true,'non-admin cannot see live enable form');
echo 'PASS: '.($checks-$start_checks).' live-control assertions; '.$checks." total. No real database, gateway, email or refund verification.\n";
