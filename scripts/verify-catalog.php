<?php
/** Catalog regression: complete results, sorting and scoped sold-out visibility. No live writes. */
define('ABSPATH', __DIR__);
$hooks=array();$checks=0;$hide_stock='yes';
function add_action(...$args) {}
function add_filter($tag,$fn,$priority=10,...$args) { $GLOBALS['hooks'][$tag][$priority][]=$fn; }
function remove_filter($tag,$fn,$priority=10) { foreach($GLOBALS['hooks'][$tag][$priority]??array() as $i=>$callback) if($callback===$fn) unset($GLOBALS['hooks'][$tag][$priority][$i]); }
function apply_filters($tag,$value,...$args) { $priorities=$GLOBALS['hooks'][$tag]??array();ksort($priorities);foreach($priorities as $callbacks)foreach($callbacks as $fn)$value=$fn($value,...$args);return $value; }
function __( $text, ...$args ) { return $text; }
function esc_html__($text,...$args) { return esc_html($text); }
function esc_html($text) { return htmlspecialchars((string)$text,ENT_QUOTES); }
function esc_url($text) { return esc_html($text); }
function wp_kses_post($text) { return $text; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function sanitize_title($text) { return strtolower($text); }
function sanitize_key($text) { return strtolower($text); }
function wp_unslash($text) { return $text; }
function _n($one,$many,$count,...$args) { return $count===1?$one:$many; }
function number_format_i18n($n) { return (string)$n; }
function absint($n) { return abs((int)$n); }
function is_wp_error($value) { return false; }
function get_the_terms(...$args) { return array(); }
function get_terms($args) { return array((object)array('slug'=>'essentials','name'=>'Essentials','count'=>14)); }
function olr_catalog_url() { return 'https://fixture.invalid/catalog/'; }
function olr_get_research_product_url($p) { return olr_catalog_url().$p->get_id().'/'; }
function olr_get_catalog_product_detail($p) { return ''; }
function remove_query_arg($keys,$url) { return strtok($url,'?'); }
function add_query_arg($args,$value,$url=null) { if(!is_array($args))$args=array($args=>$value);else $url=$value;return $url.'?'.http_build_query($args); }
class WC_Product {
 public $id,$status='publish',$visibility='visible',$stock=true,$allowed=true,$price,$menu=0,$date=100,$category;
 function __construct($id) { $this->id=$id;$this->price=($id%4)*25+25;$this->category=$id%2?'essentials':'compounds'; }
 function get_id(){return $this->id;} function get_status(){return $this->status;} function get_catalog_visibility(){return $this->visibility;}
 function get_price(){return $this->price;} function get_menu_order(){return $this->menu;} function get_date_created(){return new DateTimeImmutable('@'.$this->date);}
 function is_visible(){return $this->allowed && !($this->stock===false && (apply_filters('pre_option_woocommerce_hide_out_of_stock_items',false)?:$GLOBALS['hide_stock'])==='yes');}
 function is_in_stock(){return $this->stock;} function is_featured(){return false;} function get_name(){return 'Bottle '.$this->id;}
 function get_image(...$args){return '<img alt="Bottle" src="bottle.jpg">';} function get_price_html(){return '$'.$this->price;}
}
function wc_get_products($args) {
 $GLOBALS['last_query']=$args;
 $products=array_values(array_filter($GLOBALS['products'],static function($p)use($args){return $p->status===$args['status'] && in_array($p->visibility,array('visible','catalog'),true) && (empty($args['category'])||in_array($p->category,$args['category'],true));}));
 $total=count($products);
 if($args['limit']>0)$products=array_slice($products,(($args['page']??1)-1)*$args['limit'],$args['limit']);
 return !empty($args['paginate'])?(object)array('products'=>$products,'total'=>$total,'max_num_pages'=>ceil($total/$args['limit'])):$products;
}
function check($ok,$label){++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($label);}
require getenv('OLR_CATALOG_BRIDGE') ?: __DIR__.'/../gitpress/woocommerce-bridge.php';
$products=array();foreach(range(1,28) as $id)$products[]=new WC_Product($id);
$products[4]->stock=false;$products[5]->visibility='catalog';
foreach(array('hidden','search') as $visibility){$p=new WC_Product(count($products)+1);$p->visibility=$visibility;$products[]=$p;}
$draft=new WC_Product(31);$draft->status='draft';$products[]=$draft;
$blocked=new WC_Product(32);$blocked->allowed=false;$products[]=$blocked;
foreach(array(1,2,3,999) as $page) {
 $_GET=array('olr_page'=>(string)$page);
 $html=olr_render_research_catalog();
 check(substr_count($html,'<article class="olr-research-card')===28,'all 28 cards shown for old page '.$page);
 preg_match_all('#<h2><a href="([^"]+)"#',$html,$links);
 check(count(array_unique($links[1]))===28,'28 unique products, no duplicate pages');
 check(strpos($html,'28 products')!==false,'displayed count matches cards');
 check(strpos($html,'Sold out')!==false,'sold-out card keeps its badge');
 check(strpos($html,'olr-research-pagination')===false,'pagination removed');
}
check($products[4]->is_visible()===false,'stock visibility outside catalog unchanged');
foreach(array('menu_order','price','price-desc','newest') as $sort) {
 $a=olr_get_catalog_products('',$sort);$ids=array_map(static function($p){return $p->id;},$a);
 shuffle($products);$b=olr_get_catalog_products('',$sort);
 check($ids===array_map(static function($p){return $p->id;},$b),'stable tie-breaks for '.$sort);
 if($sort==='price'||$sort==='price-desc')foreach(array_slice($a,1) as $i=>$p)check($sort==='price'?$a[$i]->price<=$p->price:$a[$i]->price>=$p->price,'correct price direction');
}
$filtered=olr_get_catalog_products('essentials');
check(count($filtered)===14,'category filter retains all matching products');
foreach($filtered as $p)check($p->category==='essentials','no unrelated category');
check($last_query['limit']===-1 && !$last_query['paginate'] && !isset($last_query['page']),'one unpaginated WooCommerce query');
check(!olr_is_catalog_product_visible($draft)&&!olr_is_catalog_product_visible($blocked),'draft and filtered products remain hidden');
class ThrowingProduct extends WC_Product { function is_visible(){throw new RuntimeException('fixture');} }
try { olr_is_catalog_product_visible(new ThrowingProduct(99)); } catch(RuntimeException $e) {}
check(apply_filters('pre_option_woocommerce_hide_out_of_stock_items',false)===false,'visibility override removed even after an exception');
echo "PASS: $checks catalog regression assertions.\n";
