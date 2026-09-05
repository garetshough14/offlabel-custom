<?php
/** Additional release-gate fixtures. No database, live requests, mail or payments. */
require __DIR__ . '/verify-best-offer.php';
$previous_checks = $checks;
$environment = 'staging';
$wc->session->set('chosen_payment_method', 'card');
function wp_strip_all_tags($text) { return strip_tags($text); }

$products[901] = new WC_Product(901, 65, 0, array(15));
$products[902] = new WC_Product(902, 75, 0, array(16));
$products[903] = new WC_Product(903, 30, 0, array(15));
$products[904] = new WC_Product(904, 60, 901);
$coupons['qa-percent'] = array('discount_type'=>'percent', 'amount'=>30);
$mixed_qa = array('a'=>line(901,2), 'b'=>line(902,1));

// Native product/category eligibility, including variation parent restrictions.
$coupons['qa-percent']['product_ids'] = array(901);
eq(total(run_cart($mixed_qa,array('qa-percent'))),166,'coupon inclusion only discounts matching product');
$coupons['qa-percent']['excluded_product_ids'] = array(901);
eq(total(run_cart($mixed_qa,array('qa-percent'))),205,'explicit exclusion beats inclusion');
unset($coupons['qa-percent']['product_ids'], $coupons['qa-percent']['excluded_product_ids']);
$coupons['qa-percent']['product_categories'] = array(15);
eq(total(run_cart($mixed_qa,array('qa-percent'))),166,'category inclusion only discounts matching product');
$coupons['qa-percent']['excluded_product_categories'] = array(15);
eq(total(run_cart($mixed_qa,array('qa-percent'))),205,'category exclusion beats inclusion');
unset($coupons['qa-percent']['product_categories'], $coupons['qa-percent']['excluded_product_categories']);
$coupons['qa-percent']['excluded_product_ids'] = array(901);
eq(total(run_cart(array('v'=>line(904,1)),array('qa-percent'))),60,'parent exclusion covers variation');
unset($coupons['qa-percent']['excluded_product_ids']);
$coupons['qa-percent']['product_ids'] = array(901);
eq(total(run_cart(array('v'=>line(904,1)),array('qa-percent'))),42,'parent inclusion covers variation');
unset($coupons['qa-percent']['product_ids']);
$coupons['qa-percent']['minimum_amount'] = 205;
eq(total(run_cart($mixed_qa,array('qa-percent'))),143.5,'minimum spend exact boundary accepted');
$coupons['qa-percent']['minimum_amount'] = 205.01;
eq(total(run_cart($mixed_qa,array('qa-percent'))),205,'minimum spend below boundary rejected');
unset($coupons['qa-percent']['minimum_amount']);
$coupons['qa-percent']['maximum_amount'] = 204.99;
eq(total(run_cart($mixed_qa,array('qa-percent'))),205,'maximum spend above boundary rejected');
$coupons['qa-percent']['maximum_amount'] = 205;
eq(total(run_cart($mixed_qa,array('qa-percent'))),143.5,'maximum spend exact boundary accepted');
unset($coupons['qa-percent']['maximum_amount']);

$qa_box = array('olr_box_id'=>'qa-five','olr_box_size'=>5,'olr_box_role'=>'parent');
$qa_box_lines = array('a'=>line(901,1,$qa_box),'b'=>line(902,4,array_merge($qa_box,array('olr_box_role'=>'component'))));
$coupons['qa-percent']['product_ids'] = array(901);
eq(total(run_cart($qa_box_lines,array('qa-percent'))),273.75,'partial product inclusion cannot discount part of a box');
$coupons['qa-percent']['product_ids'] = array(901,902);
eq(total(run_cart($qa_box_lines,array('qa-percent'))),255.5,'whole-box inclusion permits better coupon');
unset($coupons['qa-percent']['product_ids']);
$coupons['qa-percent']['meta'] = array('_olr_exclude_boxes'=>'yes');
$box_and_loose=$qa_box_lines;$box_and_loose['loose']=line(901,1);
eq(total(run_cart($box_and_loose,array('qa-percent'))),319.25,'excluded box retains offer while loose same SKU gets coupon');
unset($coupons['qa-percent']['meta']);
$coupons['qa-fixed'] = array('discount_type'=>'fixed_cart','amount'=>10000);
eq(total(run_cart($mixed_qa,array('qa-fixed'))),0,'oversized fixed-cart coupon cannot make products negative');
$coupons['qa-fixed']['discount_type']='fixed_product';
eq(total(run_cart($mixed_qa,array('qa-fixed'))),0,'oversized per-product coupon capped');

// Explicitly controlled BOGO settings (not assumptions about the live test coupon).
$qa_bogo = array('discount_type'=>'olr_advanced_offer','amount'=>100,'meta'=>array(
 '_olr_aco_mode'=>'bogo','_olr_aco_qualifying_scope'=>'any','_olr_aco_target_scope'=>'any',
 '_olr_aco_buy_qty'=>2,'_olr_aco_get_qty'=>1,'_olr_aco_repeat'=>'no','_olr_aco_allow_reuse'=>'no',
 '_olr_aco_min_qualifying_spend'=>9));
$coupons['qa-bogo']=$qa_bogo;
eq(total(run_cart(array('a'=>line(901,2)),array('qa-bogo'))),130,'buy-two-get-one needs third unit without overlap');
eq(total(run_cart(array('a'=>line(901,3)),array('qa-bogo'))),130,'buy-two-get-one third unit free');
eq(total(run_cart($mixed_qa,array('qa-bogo'))),140,'mixed-price BOGO chooses cheapest eligible unit');
$coupons['qa-bogo']['meta']['_olr_aco_selection']='highest';
eq(total(run_cart($mixed_qa,array('qa-bogo'))),130,'highest-price setting selects correct unit');
$coupons['qa-bogo']=$qa_bogo;
$coupons['qa-bogo']['meta']['_olr_aco_repeat']='yes';
eq(total(run_cart(array('a'=>line(901,7)),array('qa-bogo'))),325,'repeated buy-two-get-one gives two free of seven');
$coupons['qa-bogo']['meta']['_olr_aco_max_sets']=1;
$cart=run_cart(array('a'=>line(901,10)),array('qa-bogo'));
eq(total($cart),520,'capped one-free offer loses to ten-bottle volume offer');
eq($cart->get_coupon_discount_amount('qa-bogo'),0,'losing capped BOGO does not stack');
$coupons['qa-bogo']=$qa_bogo;
$coupons['qa-bogo']['meta']['_olr_aco_allow_reuse']='yes';
eq(total(run_cart(array('a'=>line(901,2)),array('qa-bogo'))),65,'explicit overlap permits qualifying unit discount');
$coupons['qa-bogo']=$qa_bogo;
$coupons['qa-bogo']['meta']['_olr_aco_min_qualifying_spend']=1000;
eq(total(run_cart(array('a'=>line(901,3)),array('qa-bogo'))),175.5,'unmet BOGO spend leaves volume available');
$coupons['qa-bogo']=$qa_bogo;
$coupons['qa-bogo']['meta']['_olr_aco_qualifying_scope']='products';
$coupons['qa-bogo']['meta']['_olr_aco_qualifying_product_ids']=array(901);
$coupons['qa-bogo']['meta']['_olr_aco_target_scope']='products';
$coupons['qa-bogo']['meta']['_olr_aco_target_product_ids']=array(902);
eq(total(run_cart($mixed_qa,array('qa-bogo'))),130,'separate qualifying and target products');
eq(total(run_cart(array('a'=>line(901,2)),array('qa-bogo'))),130,'missing required target cannot receive BOGO');
$coupons['qa-bogo']['meta']['_olr_aco_qualifying_excluded_product_ids']=array(901);
eq(total(run_cart($mixed_qa,array('qa-bogo'))),205,'excluded qualifier cannot unlock reward');
$coupons['qa-bogo']=$qa_bogo;
$coupons['qa-bogo']['meta']['_olr_aco_target_excluded_product_ids']=array(901);
eq(total(run_cart($mixed_qa,array('qa-bogo'))),130,'target exclusion respected independently of qualifying scope');
$coupons['qa-bogo']=$qa_bogo;
$coupons['qa-bogo']['meta']['_olr_exclude_boxes']='yes';
$box_and_loose=$qa_box_lines;$box_and_loose['loose']=line(903,2);
eq(total(run_cart($box_and_loose,array('qa-bogo'))),333.75,'excluded box units cannot unlock standalone BOGO');
$box_and_loose['loose']=line(903,3);
eq(total(run_cart($box_and_loose,array('qa-bogo'))),333.75,'three loose units unlock BOGO without changing excluded box');

// Payment choice competition is tested as allocation only, not a real gateway.
$wc->session->set('chosen_payment_method','zelle');
$cart=run_cart(array('a'=>line(901,3),'b'=>line(902,1)));
eq(total($cart),246.75,'volume winner and payment winner can differ by SKU');
$wc->session->set('chosen_payment_method','card');
eq(total(run_cart($cart->cart_contents,array(),$cart)),250.5,'switching away removes only payment savings');

// Saved allocation snapshots: this deliberately does NOT claim DB/email/refund coverage.
$cart=run_cart(array('a'=>line(901,3),'b'=>line(902,1)),array('qa-percent'));
$qa_order=new WC_Order();$engine->assert_safe_order($qa_order);
$qa_coupon_items=array();$id=501;
foreach($cart->cart_contents as $key=>$values) {
 $line_item=new TestOrderLine($id,$values['data'],$values['quantity'],$values['line_subtotal']);
 $engine->order_meta($line_item,$key,$values,$qa_order);$qa_order->items[$id++]=$line_item;
}
foreach($cart->codes as $code) {
 $coupon_item=new TestOrderLine($id++,null,0,0);
 $engine->order_coupon_meta($coupon_item,$code,new WC_Coupon($code),$qa_order);
 $qa_coupon_items[$code]=$coupon_item;
}
$coupons['qa-percent']['amount']=90;
$replay=new WC_Discounts($qa_order);
foreach($qa_coupon_items as $code=>$coupon_item) $replay->apply_coupon($engine->replay_order_coupon(new WC_Coupon($code),$code,$coupon_item,$qa_order),false);
eq(array_sum($replay->get_discounts_by_item(true)),8100,'existing allocation unchanged after coupon percentage changes');
$qa_order->items[501]->subtotal += 1;
$caught=false;try{$engine->replay_order_coupon(new WC_Coupon('qa-percent'),'qa-percent',$qa_coupon_items['qa-percent'],$qa_order);}catch(Exception $e){$caught=true;}
eq($caught,true,'changed order price requires review');
$qa_order->items[501]->subtotal -= 1;
$qa_coupon_items['qa-percent']->meta=array();
$caught=false;try{$engine->replay_order_coupon(new WC_Coupon('qa-percent'),'qa-percent',$qa_coupon_items['qa-percent'],$qa_order);}catch(Exception $e){$caught=true;}
eq($caught,true,'missing allocation snapshot fails closed');
$old_coupon=new WC_Coupon('qa-percent');
eq($engine->replay_order_coupon($old_coupon,'qa-percent',new TestOrderLine(1,null,0,0),new WC_Order()),$old_coupon,'legacy order without engine metadata unchanged');

echo 'PASS: '.($checks-$previous_checks).' additional release-gate assertions; '.$checks." total. Full staging order/email/refund/payment tests NOT performed.\n";
