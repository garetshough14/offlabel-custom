<?php
/**
 * Plugin Name: Off Label Build Your Box
 * Description: Branded mix-and-match research box builder backed by native WooCommerce products, carts, and orders.
 * Version: 1.0.1
 * Author: Off Label Research
 * Text Domain: off-label-build-a-box
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OLR_Build_A_Box {
	const VERSION             = '1.0.1';
	const PAGE_SLUG           = 'build-your-box';
	const SHORTCODE           = 'olr_build_a_box';
	const META_ELIGIBLE       = '_olr_box_eligible';
	const CART_BOX_ID         = 'olr_box_id';
	const CART_BOX_SIZE       = 'olr_box_size';
	const CART_BOX_RATE       = 'olr_box_rate';
	const CART_BOX_LABEL      = 'olr_box_label';
	const CART_REGULAR_PRICE  = 'olr_box_regular_price';
	const HERO_IMAGE          = 'assets/hero-three-vials-transparent-glass.png';

	/** @var self|null */
	private static $instance = null;

	/** @var bool */
	private $late_styles_printed = false;

	/** @var bool */
	private $assets_localized = false;

	/**
	 * Return the shared plugin instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register update-safe WordPress and WooCommerce integrations.
	 */
	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_filter( 'dgs_allowed_inner_shortcodes', array( $this, 'allow_gitpress_shortcode' ) );
		add_filter( 'the_content', array( $this, 'render_nested_shortcode' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ), 40 );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_eligibility_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_eligibility' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'product_columns' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'product_column_value' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'product_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_product_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_action( 'admin_notices', array( $this, 'product_bulk_notice' ) );

		add_action( 'wp_ajax_olr_save_box', array( $this, 'ajax_save_box' ) );
		add_action( 'wp_ajax_nopriv_olr_save_box', array( $this, 'ajax_save_box' ) );
		add_action( 'wp_ajax_olr_remove_box', array( $this, 'ajax_remove_box' ) );
		add_action( 'wp_ajax_nopriv_olr_remove_box', array( $this, 'ajax_remove_box' ) );
		add_action( 'template_redirect', array( $this, 'handle_cart_box_action' ), 1 );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_box_prices' ), 9999 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_boxes' ), 5 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'prevent_coupon_stacking' ), 999, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'locked_cart_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'box_remove_link' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_class', array( $this, 'cart_item_class' ), 10, 3 );
		add_action( 'woocommerce_before_cart', array( $this, 'coupon_policy_notice' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'coupon_policy_notice' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_metadata' ), 10, 4 );
	}

	/**
	 * Create the public fallback page on activation.
	 */
	public static function activate() {
		$page = get_page_by_path( self::PAGE_SLUG );
		if ( ! $page instanceof WP_Post ) {
			wp_insert_post(
				array(
					'post_title'     => 'Build Your Box',
					'post_name'      => self::PAGE_SLUG,
					'post_content'   => '[' . self::SHORTCODE . ']',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}

		flush_rewrite_rules();
	}

	/**
	 * Keep the nested shortcode available to GitPress managed pages.
	 *
	 * @param array $shortcodes Existing allowlist.
	 * @return array
	 */
	public function allow_gitpress_shortcode( $shortcodes ) {
		$shortcodes   = is_array( $shortcodes ) ? $shortcodes : array();
		$shortcodes[] = self::SHORTCODE;
		return array_values( array_unique( $shortcodes ) );
	}

	/**
	 * Provide a late nested-shortcode pass for managed page content.
	 *
	 * @param string $content Page content.
	 * @return string
	 */
	public function render_nested_shortcode( $content ) {
		if ( false === strpos( (string) $content, '[' . self::SHORTCODE ) ) {
			return $content;
		}

		return do_shortcode( (string) $content );
	}

	/**
	 * Add a stable page class without affecting unrelated routes.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( ! is_admin() && function_exists( 'is_page' ) && is_page( self::PAGE_SLUG ) ) {
			$classes[] = 'olr-build-box-page';
		}
		return $classes;
	}

	/**
	 * Load scoped assets on the canonical page or when forced by the shortcode.
	 *
	 * @param bool $force Whether the shortcode has already confirmed it is needed.
	 */
	public function frontend_assets( $force = false ) {
		$commerce_box_context = ! is_admin()
			&& ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) )
			&& $this->cart_has_box();
		if ( ! $force && ( is_admin() || ( ( ! function_exists( 'is_page' ) || ! is_page( self::PAGE_SLUG ) ) && ! $commerce_box_context ) ) ) {
			return;
		}

		wp_enqueue_style(
			'olr-build-a-box',
			plugins_url( 'assets/build-a-box.css', __FILE__ ),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'olr-build-a-box',
			plugins_url( 'assets/build-a-box.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);

		if ( ! $this->assets_localized && function_exists( 'WC' ) ) {
			$price_format = function_exists( 'get_woocommerce_price_format' ) ? get_woocommerce_price_format() : '%1$s%2$s';
			wp_localize_script(
				'olr-build-a-box',
				'olrBuildBox',
				array(
					'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
					'nonce'            => wp_create_nonce( 'olr_box_cart' ),
					'cartUrl'          => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
					'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
					'currencyPosition' => $price_format,
					'decimals'         => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
					'decimalSeparator' => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
					'thousandSeparator'=> function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
					'messages'          => array(
						'genericError'    => __( 'Your box could not be updated. Please try again.', 'off-label-build-a-box' ),
						'tierTooSmall'    => __( 'Remove bottles before switching to the smaller box.', 'off-label-build-a-box' ),
						'variationNeeded' => __( 'Choose an option before adding this bottle.', 'off-label-build-a-box' ),
						'boxFull'         => __( 'Your selected box is already full.', 'off-label-build-a-box' ),
					),
				)
			);
			$this->assets_localized = true;
		}
	}

	/**
	 * Inline styles if GitPress discovers the shortcode after wp_head.
	 *
	 * @return string
	 */
	private function late_style_markup() {
		if ( $this->late_styles_printed || ! did_action( 'wp_head' ) || wp_style_is( 'olr-build-a-box', 'done' ) ) {
			return '';
		}

		$path = plugin_dir_path( __FILE__ ) . 'assets/build-a-box.css';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$css = file_get_contents( $path );
		if ( false === $css ) {
			return '';
		}

		$this->late_styles_printed = true;
		return '<style id="olr-build-a-box-late-css">' . $css . '</style>';
	}

	/**
	 * Render the complete builder from live WooCommerce product data.
	 *
	 * @return string
	 */
	public function shortcode() {
		$this->frontend_assets( true );
		$late_styles = $this->late_style_markup();

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return $late_styles . $this->frontend_unavailable_state();
		}

		$this->ensure_cart();
		$products    = $this->eligible_product_data();
		$edit_box_id = isset( $_GET['edit_box'] ) ? $this->sanitize_box_id( wp_unslash( $_GET['edit_box'] ) ) : '';
		$initial     = $edit_box_id ? $this->builder_state_for_box( $edit_box_id ) : array();
		$edit_box_id = $initial ? $edit_box_id : '';
		$initial_tier= $initial ? absint( $initial['tier'] ) : 5;

		ob_start();
		?>
		<?php echo $late_styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="olr-build-box<?php echo $products ? '' : ' olr-build-box--empty'; ?>" data-olr-build-box data-tier="<?php echo esc_attr( (string) $initial_tier ); ?>" data-edit-box="<?php echo esc_attr( $edit_box_id ); ?>">
			<section class="olr-build-box__hero" aria-labelledby="olr-build-box-title">
				<div class="olr-build-box__hero-copy">
					<p class="olr-build-box__eyebrow">Mix. Match. Save.</p>
					<h1 id="olr-build-box-title">Build your<br>research box.</h1>
					<span class="olr-build-box__rule" aria-hidden="true"></span>
					<p>Any bottles. Any combination.<br>Your box, your research.</p>
				</div>
				<figure class="olr-build-box__hero-media">
					<img src="<?php echo esc_url( plugins_url( self::HERO_IMAGE, __FILE__ ) ); ?>" alt="Off Label Research Sermorelin, KPV, and MOTS-C research vials" width="1554" height="1012">
				</figure>
				<ul class="olr-build-box__proof" aria-label="Product standards">
					<li><?php echo $this->proof_icon( 'purity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>99%+ purity</span></li>
					<li><?php echo $this->proof_icon( 'tested' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Third-party tested</span></li>
					<li><?php echo $this->proof_icon( 'shipping' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Fast shipping</span></li>
					<li><?php echo $this->proof_icon( 'research' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Research use only</span></li>
				</ul>
			</section>

			<section class="olr-build-box__tiers" aria-labelledby="olr-box-tier-title">
				<p id="olr-box-tier-title">Discount is applied automatically when your box is complete.</p>
				<div class="olr-build-box__tier-grid" role="radiogroup" aria-label="Choose a box size">
					<?php echo $this->tier_card( 5, 25, 'The Five', $initial_tier ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->tier_card( 10, 30, 'The Ten', $initial_tier ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</section>

			<section class="olr-build-box__builder" aria-labelledby="olr-builder-title">
				<div class="olr-build-box__catalog">
					<header class="olr-build-box__builder-header">
						<div><p class="olr-build-box__eyebrow">Build your box</p><h2 id="olr-builder-title">Choose your bottles.</h2><p>Add exactly <span data-tier-copy><?php echo esc_html( (string) $initial_tier ); ?></span> bottles to unlock your box price.</p></div>
						<div class="olr-build-box__progress" aria-label="Box progress">
							<p><span>Your box</span><strong><span data-box-count>0</span> / <span data-box-limit><?php echo esc_html( (string) $initial_tier ); ?></span></strong></p>
							<div data-progress-dots aria-hidden="true"></div>
						</div>
					</header>

					<div class="olr-build-box__alert" data-builder-alert role="alert" tabindex="-1" hidden></div>
					<?php if ( $products ) : ?>
						<div class="olr-build-box__product-grid" data-product-grid>
							<?php foreach ( $products as $index => $product_data ) : ?>
								<?php echo $this->product_card( $product_data, $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endforeach; ?>
							<?php if ( count( $products ) > 7 ) : ?>
								<button class="olr-build-box__view-all-tile" type="button" data-view-all><span aria-hidden="true">▢ ▢ ▢</span><strong>View all<br>bottles</strong><b aria-hidden="true">→</b></button>
							<?php endif; ?>
						</div>
						<?php if ( count( $products ) > 7 ) : ?><button class="olr-build-box__browse" type="button" data-view-all>Browse all bottles <span aria-hidden="true">→</span></button><?php endif; ?>
					<?php else : ?>
						<div class="olr-build-box__empty"><p class="olr-build-box__eyebrow">Collection loading</p><h3>Box selection is being prepared.</h3><p><?php echo current_user_can( 'manage_woocommerce' ) ? esc_html__( 'Select bottle products under Products, choose Enable Build Your Box from Bulk actions, then click Apply.', 'off-label-build-a-box' ) : esc_html__( 'Please check back shortly.', 'off-label-build-a-box' ); ?></p></div>
					<?php endif; ?>
				</div>

				<aside class="olr-build-box__summary" aria-labelledby="olr-box-summary-title">
					<p class="olr-build-box__eyebrow" id="olr-box-summary-title">Your research box</p>
					<ol class="olr-build-box__summary-items" data-summary-items></ol>
					<div class="olr-build-box__unlock" data-unlock-message><span aria-hidden="true">○</span><p>Add <strong data-remaining-count><?php echo esc_html( (string) $initial_tier ); ?></strong> more <span data-remaining-label>bottles</span> to<br><b>unlock <span data-discount-copy><?php echo esc_html( '5' === (string) $initial_tier ? '25' : '30' ); ?></span>% off</b></p></div>
					<dl class="olr-build-box__totals">
						<div><dt>Subtotal (<span data-total-items>0</span> items)</dt><dd data-subtotal>$0.00</dd></div>
						<div class="olr-build-box__discount-row"><dt>Box discount (<span data-discount-copy><?php echo esc_html( '5' === (string) $initial_tier ? '25' : '30' ); ?></span>%)</dt><dd data-savings>−$0.00</dd></div>
						<div class="olr-build-box__total-row"><dt>Total</dt><dd data-total>$0.00</dd></div>
					</dl>
					<button class="olr-build-box__submit" type="button" data-submit-box disabled><?php echo esc_html( $edit_box_id ? 'Update box' : 'Add box to cart' ); ?> <span aria-hidden="true">→</span></button>
					<button class="olr-build-box__start-over" type="button" data-start-over>Start over</button>
				</aside>
			</section>

			<footer class="olr-build-box__notice">
				<span aria-hidden="true">i</span>
				<p>Build Your Box discounts cannot be combined with coupon codes, sale pricing, or volume pricing. Discount is applied automatically when the selected box is complete.</p>
				<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Questions? Contact us <b aria-hidden="true">→</b></a>
			</footer>
			<script type="application/json" data-initial-box-state><?php echo wp_json_encode( $initial ? $initial['items'] : array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Frontend dependency state.
	 *
	 * @return string
	 */
	private function frontend_unavailable_state() {
		return '<div class="olr-build-box"><section class="olr-build-box__empty"><p class="olr-build-box__eyebrow">Build Your Box</p><h1>Box builder temporarily unavailable.</h1><p>The product service is not active. Please try again later.</p></section></div>';
	}

	/**
	 * Render one fixed tier card.
	 *
	 * @param int    $size Box size.
	 * @param int    $percent Discount percentage.
	 * @param string $label Public label.
	 * @param int    $selected Selected size.
	 * @return string
	 */
	private function tier_card( $size, $percent, $label, $selected ) {
		$checked = absint( $size ) === absint( $selected );
		ob_start();
		?>
		<label class="olr-build-box__tier<?php echo $checked ? ' is-selected' : ''; ?>">
			<input type="radio" name="olr_box_tier" value="<?php echo esc_attr( (string) $size ); ?>" data-box-tier data-discount="<?php echo esc_attr( (string) $percent ); ?>"<?php checked( $checked ); ?>>
			<span class="olr-build-box__radio" aria-hidden="true"></span>
			<span class="olr-build-box__tier-copy"><strong><?php echo esc_html( $label ); ?></strong><small>Any <?php echo esc_html( (string) $size ); ?> bottles</small><b><?php echo esc_html( (string) $percent ); ?>% off</b></span>
			<span class="olr-build-box__tier-notes"><span>↻ &nbsp; Mix + match</span><span>▣ &nbsp; Same product allowed</span></span>
		</label>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one live product card.
	 *
	 * @param array $data Product data.
	 * @param int   $index Zero-based display index.
	 * @return string
	 */
	private function product_card( $data, $index ) {
		$is_variable = 'variable' === $data['type'];
		$price       = isset( $data['regular_price'] ) ? (float) $data['regular_price'] : 0.0;
		ob_start();
		?>
		<article class="olr-build-box__product<?php echo $index >= 7 ? ' is-extra' : ''; ?>" data-product-card data-product-id="<?php echo esc_attr( (string) $data['id'] ); ?>" data-product-name="<?php echo esc_attr( $data['name'] ); ?>" data-product-image="<?php echo esc_url( $data['image'] ); ?>" data-product-type="<?php echo esc_attr( $data['type'] ); ?>" data-regular-price="<?php echo esc_attr( (string) $price ); ?>">
			<div class="olr-build-box__product-media"><img src="<?php echo esc_url( $data['image'] ); ?>" alt="<?php echo esc_attr( $data['name'] ); ?>" loading="lazy"></div>
			<h3><?php echo esc_html( $data['name'] ); ?></h3>
			<p class="olr-build-box__product-price"><?php echo wp_kses_post( $data['price_html'] ); ?></p>
			<?php if ( $is_variable ) : ?>
				<label class="olr-build-box__variation"><span class="screen-reader-text">Choose an option for <?php echo esc_html( $data['name'] ); ?></span><select data-variation-select><option value="">Choose an option</option><?php foreach ( $data['variations'] as $variation ) : ?><option value="<?php echo esc_attr( (string) $variation['id'] ); ?>" data-price="<?php echo esc_attr( (string) $variation['regular_price'] ); ?>" data-name="<?php echo esc_attr( $variation['name'] ); ?>" data-image="<?php echo esc_url( $variation['image'] ); ?>"><?php echo esc_html( $variation['name'] ); ?> — <?php echo wp_kses_post( $variation['price_text'] ); ?></option><?php endforeach; ?></select></label>
			<?php endif; ?>
			<div class="olr-build-box__quantity" data-quantity-control>
				<button type="button" data-quantity-change="-1" aria-label="Decrease <?php echo esc_attr( $data['name'] ); ?> quantity">−</button>
				<output data-product-quantity aria-live="polite">0</output>
				<button type="button" data-quantity-change="1" aria-label="Increase <?php echo esc_attr( $data['name'] ); ?> quantity">+</button>
			</div>
			<button class="olr-build-box__add" type="button" data-add-product>Add</button>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Return approved product data for the builder.
	 *
	 * @return array
	 */
	private function eligible_product_data() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_ELIGIBLE,
				'meta_value'     => 'yes',
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			)
		);

		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$data    = $this->product_to_builder_data( $product );
			if ( $data ) {
				$products[] = $data;
			}
		}

		return $products;
	}

	/**
	 * Convert one eligible WooCommerce product into safe builder data.
	 *
	 * @param WC_Product|false $product Product object.
	 * @return array
	 */
	private function product_to_builder_data( $product ) {
		if ( ! $this->base_product_is_eligible( $product ) ) {
			return array();
		}

		$image_id = $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : '';
		if ( ! $image ) {
			return array();
		}

		$data = array(
			'id'            => $product->get_id(),
			'name'          => wp_strip_all_tags( $product->get_name() ),
			'image'         => $image,
			'type'          => $product->get_type(),
			'regular_price' => 0,
			'price_html'    => '',
			'variations'    => array(),
		);

		if ( $product->is_type( 'simple' ) ) {
			$regular = $this->regular_price( $product );
			if ( $regular <= 0 ) {
				return array();
			}
			$display_regular       = $this->display_regular_price( $product );
			$data['regular_price'] = $display_regular;
			$data['price_html']    = wc_price( $display_regular );
			return $data;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() || $variation->is_on_sale() ) {
				continue;
			}
			$regular = $this->regular_price( $variation );
			if ( $regular <= 0 ) {
				continue;
			}
			$variation_image_id = $variation->get_image_id() ? $variation->get_image_id() : $image_id;
			$variation_image    = wp_get_attachment_image_url( $variation_image_id, 'woocommerce_single' );
			$variation_name     = function_exists( 'wc_get_formatted_variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $variation, true, true, true ) ) : '';
			$display_regular = $this->display_regular_price( $variation );
			$data['variations'][] = array(
				'id'            => $variation->get_id(),
				'name'          => $variation_name ? $variation_name : sprintf( __( 'Option %d', 'off-label-build-a-box' ), $variation->get_id() ),
				'image'         => $variation_image ? $variation_image : $image,
				'regular_price' => $display_regular,
				'price_text'    => wp_strip_all_tags( wc_price( $display_regular ) ),
			);
		}

		if ( ! $data['variations'] ) {
			return array();
		}

		$prices = wp_list_pluck( $data['variations'], 'regular_price' );
		$min    = min( $prices );
		$max    = max( $prices );
		$data['regular_price'] = $min;
		$data['price_html']    = $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
		return $data;
	}

	/**
	 * Check product-level eligibility and current sale/stock state.
	 *
	 * @param WC_Product|false $product Product object.
	 * @return bool
	 */
	private function base_product_is_eligible( $product ) {
		return $product instanceof WC_Product
			&& $product->is_type( array( 'simple', 'variable' ) )
			&& 'yes' === $product->get_meta( self::META_ELIGIBLE, true )
			&& $product->is_visible()
			&& $product->is_purchasable()
			&& $product->is_in_stock()
			&& ! $product->is_on_sale()
			&& (bool) $product->get_image_id();
	}

	/**
	 * Read a positive raw regular price.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return float
	 */
	private function regular_price( $product ) {
		$price = $product instanceof WC_Product ? $product->get_regular_price( 'edit' ) : '';
		return is_numeric( $price ) ? max( 0, (float) $price ) : 0.0;
	}

	/**
	 * Convert a raw regular price to the store's configured frontend tax display.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return float
	 */
	private function display_regular_price( $product ) {
		$regular = $this->regular_price( $product );
		return $regular > 0 && function_exists( 'wc_get_price_to_display' )
			? (float) wc_get_price_to_display( $product, array( 'price' => $regular ) )
			: $regular;
	}

	/**
	 * Save or update a complete box through a nonced AJAX request.
	 */
	public function ajax_save_box() {
		check_ajax_referer( 'olr_box_cart', 'nonce' );
		$this->ensure_cart();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Your cart is unavailable. Please refresh and try again.', 'off-label-build-a-box' ) ), 503 );
		}

		if ( WC()->cart->get_applied_coupons() ) {
			wp_send_json_error( array( 'message' => __( 'Remove the applied coupon before adding a discounted research box.', 'off-label-build-a-box' ) ), 409 );
		}

		$tier        = isset( $_POST['tier'] ) && is_scalar( $_POST['tier'] ) ? absint( $_POST['tier'] ) : 0;
		$edit_box_id = isset( $_POST['box_id'] ) ? $this->sanitize_box_id( wp_unslash( $_POST['box_id'] ) ) : '';
		$raw_items   = isset( $_POST['items'] ) && is_scalar( $_POST['items'] ) ? json_decode( wp_unslash( (string) $_POST['items'] ), true ) : array();
		$selection   = $this->validate_selection( $raw_items, $tier, $edit_box_id );
		if ( is_wp_error( $selection ) ) {
			wp_send_json_error( array( 'message' => $selection->get_error_message() ), 400 );
		}

		if ( $edit_box_id && ! $this->box_exists_in_cart( $edit_box_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That box is no longer in your cart.', 'off-label-build-a-box' ) ), 404 );
		}

		$box_id  = $edit_box_id ? $edit_box_id : strtolower( wp_generate_uuid4() );
		$snapshot= $edit_box_id ? $this->snapshot_box( $edit_box_id ) : array();
		if ( $edit_box_id ) {
			$this->remove_box_from_cart( $edit_box_id );
		}

		$added_keys = array();
		try {
			foreach ( $selection as $entry ) {
				$cart_data = array(
					self::CART_BOX_ID        => $box_id,
					self::CART_BOX_SIZE      => $tier,
					self::CART_BOX_RATE      => $this->tier_rate( $tier ),
					self::CART_BOX_LABEL     => $this->tier_label( $tier ),
					self::CART_REGULAR_PRICE => $entry['regular_price'],
				);
				$key = WC()->cart->add_to_cart( $entry['product_id'], $entry['quantity'], $entry['variation_id'], $entry['variation'], $cart_data );
				if ( ! $key ) {
					throw new Exception( __( 'One of the selected bottles could not be added.', 'off-label-build-a-box' ) );
				}
				$added_keys[] = $key;
			}
		} catch ( Exception $exception ) {
			foreach ( array_unique( $added_keys ) as $key ) {
				WC()->cart->remove_cart_item( $key );
			}
			$this->restore_box_snapshot( $snapshot );
			WC()->cart->calculate_totals();
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 409 );
		}

		WC()->cart->calculate_totals();
		WC()->cart->set_session();
		wp_send_json_success(
			array(
				'boxId'    => $box_id,
				'cartUrl'  => wc_get_cart_url(),
				'cartCount'=> WC()->cart->get_cart_contents_count(),
				'message'  => $edit_box_id ? __( 'Your box has been updated.', 'off-label-build-a-box' ) : __( 'Your box has been added to the cart.', 'off-label-build-a-box' ),
			)
		);
	}

	/**
	 * Remove a complete box over AJAX.
	 */
	public function ajax_remove_box() {
		check_ajax_referer( 'olr_box_cart', 'nonce' );
		$this->ensure_cart();
		$box_id = isset( $_POST['box_id'] ) ? $this->sanitize_box_id( wp_unslash( $_POST['box_id'] ) ) : '';
		if ( ! $box_id || ! $this->remove_box_from_cart( $box_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That box is no longer in your cart.', 'off-label-build-a-box' ) ), 404 );
		}
		WC()->cart->calculate_totals();
		WC()->cart->set_session();
		wp_send_json_success( array( 'cartUrl' => wc_get_cart_url() ) );
	}

	/**
	 * Validate and normalize a client selection using live server data.
	 *
	 * @param mixed  $raw_items Raw decoded JSON.
	 * @param int    $tier Box size.
	 * @param string $edit_box_id Existing box ID, if any.
	 * @return array|WP_Error
	 */
	private function validate_selection( $raw_items, $tier, $edit_box_id = '' ) {
		if ( ! in_array( absint( $tier ), array( 5, 10 ), true ) ) {
			return new WP_Error( 'invalid_tier', __( 'Choose either The Five or The Ten.', 'off-label-build-a-box' ) );
		}
		if ( ! is_array( $raw_items ) ) {
			return new WP_Error( 'invalid_items', __( 'Choose the bottles for your box.', 'off-label-build-a-box' ) );
		}

		$merged = array();
		$total  = 0;
		foreach ( $raw_items as $raw_item ) {
			if ( ! is_array( $raw_item ) ) {
				continue;
			}
			$product_id  = isset( $raw_item['product_id'] ) && is_scalar( $raw_item['product_id'] ) ? absint( $raw_item['product_id'] ) : 0;
			$variation_id= isset( $raw_item['variation_id'] ) && is_scalar( $raw_item['variation_id'] ) ? absint( $raw_item['variation_id'] ) : 0;
			$quantity    = isset( $raw_item['quantity'] ) && is_scalar( $raw_item['quantity'] ) ? absint( $raw_item['quantity'] ) : 0;
			if ( ! $product_id || ! $quantity ) {
				continue;
			}
			$total += $quantity;
			if ( $total > $tier ) {
				return new WP_Error( 'too_many_items', __( 'The selected box contains too many bottles.', 'off-label-build-a-box' ) );
			}
			$key = $product_id . ':' . $variation_id;
			if ( isset( $merged[ $key ] ) ) {
				$merged[ $key ]['quantity'] += $quantity;
			} else {
				$merged[ $key ] = array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity );
			}
		}

		if ( $total !== $tier ) {
			return new WP_Error( 'incomplete_box', sprintf( __( 'Choose exactly %d bottles before adding this box.', 'off-label-build-a-box' ), $tier ) );
		}

		$validated = array();
		foreach ( $merged as $entry ) {
			$parent = wc_get_product( $entry['product_id'] );
			if ( ! $this->base_product_is_eligible( $parent ) ) {
				return new WP_Error( 'ineligible_product', __( 'One of the selected bottles is no longer eligible for Build Your Box.', 'off-label-build-a-box' ) );
			}

			$purchasable = $parent;
			$variation   = array();
			if ( $parent->is_type( 'variable' ) ) {
				$purchasable = $entry['variation_id'] ? wc_get_product( $entry['variation_id'] ) : false;
				if ( ! $purchasable instanceof WC_Product_Variation || $purchasable->get_parent_id() !== $parent->get_id() ) {
					return new WP_Error( 'invalid_variation', __( 'Choose a valid option for every variable product.', 'off-label-build-a-box' ) );
				}
				$variation = $purchasable->get_variation_attributes();
			} elseif ( $entry['variation_id'] ) {
				return new WP_Error( 'invalid_variation', __( 'The selected product option is invalid.', 'off-label-build-a-box' ) );
			}

			if ( ! $purchasable->is_purchasable() || ! $purchasable->is_in_stock() || $purchasable->is_on_sale() ) {
				return new WP_Error( 'product_unavailable', __( 'One of the selected bottles is unavailable or currently on sale.', 'off-label-build-a-box' ) );
			}
			if ( $purchasable->is_sold_individually() && $entry['quantity'] > 1 ) {
				return new WP_Error( 'sold_individually', sprintf( __( '%s can only be selected once.', 'off-label-build-a-box' ), $parent->get_name() ) );
			}

			$regular = $this->regular_price( $purchasable );
			if ( $regular <= 0 ) {
				return new WP_Error( 'invalid_price', __( 'One of the selected bottles does not have a valid regular price.', 'off-label-build-a-box' ) );
			}

			$existing_quantity = $this->cart_quantity_for_product( $parent->get_id(), $purchasable instanceof WC_Product_Variation ? $purchasable->get_id() : 0, $edit_box_id );
			$required_quantity = $existing_quantity + $entry['quantity'];
			if ( ! $purchasable->backorders_allowed() && ! $purchasable->has_enough_stock( $required_quantity ) ) {
				return new WP_Error( 'insufficient_stock', sprintf( __( 'There is not enough stock available for %s.', 'off-label-build-a-box' ), $parent->get_name() ) );
			}

			$maximum = (int) $purchasable->get_max_purchase_quantity();
			if ( $maximum > 0 && $required_quantity > $maximum ) {
				return new WP_Error( 'maximum_quantity', sprintf( __( 'The available quantity limit for %s has been reached.', 'off-label-build-a-box' ), $parent->get_name() ) );
			}

			$validated[] = array(
				'product_id'    => $parent->get_id(),
				'variation_id'  => $purchasable instanceof WC_Product_Variation ? $purchasable->get_id() : 0,
				'variation'     => $variation,
				'quantity'      => $entry['quantity'],
				'regular_price' => $regular,
			);
		}

		return $validated;
	}

	/**
	 * Apply final non-stacking box prices to valid groups only.
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function apply_box_prices( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$groups = $this->cart_box_groups( $cart );
		foreach ( $groups as $box_id => $group ) {
			$size = absint( $group['size'] );
			$rate = $this->tier_rate( $size );
			if ( $group['quantity'] !== $size || ! $rate ) {
				continue;
			}
			foreach ( $group['keys'] as $cart_item_key ) {
				$cart_item = $cart->get_cart_item( $cart_item_key );
				if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
					continue;
				}
				$current = wc_get_product( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'] );
				$parent  = wc_get_product( $cart_item['product_id'] );
				if ( ! $current instanceof WC_Product || ! $this->base_product_is_eligible( $parent ) || $current->is_on_sale() ) {
					continue;
				}
				$regular = $this->regular_price( $current );
				if ( $regular > 0 ) {
					$cart_item['data']->set_price( wc_format_decimal( $regular * ( 1 - $rate ) ) );
				}
			}
		}
	}

	/**
	 * Block checkout if a persisted box no longer satisfies its rules.
	 */
	public function validate_cart_boxes() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$groups = $this->cart_box_groups( WC()->cart );
		if ( $groups && WC()->cart->get_applied_coupons() ) {
			$this->add_notice_once( __( 'Research box discounts cannot be combined with coupon codes. Remove the coupon or remove the box before checkout.', 'off-label-build-a-box' ) );
		}

		foreach ( $groups as $group ) {
			$size = absint( $group['size'] );
			if ( ! in_array( $size, array( 5, 10 ), true ) || $group['quantity'] !== $size ) {
				$this->add_notice_once( __( 'A research box in your cart is incomplete. Edit or remove it before checkout.', 'off-label-build-a-box' ) );
				continue;
			}
			foreach ( $group['keys'] as $key ) {
				$item    = WC()->cart->get_cart_item( $key );
				$parent  = is_array( $item ) ? wc_get_product( $item['product_id'] ) : false;
				$current = is_array( $item ) ? wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] ) : false;
				if ( ! $this->base_product_is_eligible( $parent ) || ! $current instanceof WC_Product || ! $current->is_purchasable() || ! $current->is_in_stock() || $current->is_on_sale() ) {
					$this->add_notice_once( __( 'A bottle in your research box is no longer available at the box price. Edit the box before checkout.', 'off-label-build-a-box' ) );
					break;
				}
			}
		}
	}

	/**
	 * Reject every coupon while a validated or persisted box is present.
	 *
	 * @param bool         $valid Existing validity.
	 * @param WC_Coupon    $coupon Coupon object.
	 * @param WC_Discounts $discounts Discounts object.
	 * @return bool
	 * @throws Exception When boxes and coupons conflict.
	 */
	public function prevent_coupon_stacking( $valid, $coupon, $discounts = null ) {
		unset( $coupon, $discounts );
		if ( $valid && $this->cart_has_box() ) {
			throw new Exception( __( 'Coupon codes cannot be combined with Build Your Box pricing.', 'off-label-build-a-box' ) );
		}
		return $valid;
	}

	/**
	 * Display public box information beside cart and checkout lines.
	 *
	 * @param array $data Existing display data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function cart_item_data( $data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_BOX_ID ] ) ) {
			return $data;
		}
		$size = absint( $cart_item[ self::CART_BOX_SIZE ] );
		$data[] = array( 'key' => __( 'Research Box', 'off-label-build-a-box' ), 'value' => $this->tier_label( $size ) );
		$data[] = array( 'key' => __( 'Box discount', 'off-label-build-a-box' ), 'value' => absint( $this->tier_rate( $size ) * 100 ) . '%' );
		return $data;
	}

	/**
	 * Lock individual line quantities and provide one group edit action.
	 *
	 * @param string $html Existing quantity input.
	 * @param string $cart_item_key Cart key.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function locked_cart_quantity( $html, $cart_item_key, $cart_item ) {
		if ( empty( $cart_item[ self::CART_BOX_ID ] ) ) {
			return $html;
		}
		$box_id = $this->sanitize_box_id( $cart_item[ self::CART_BOX_ID ] );
		$output = '<span class="olr-box-locked-quantity" aria-label="Quantity">× ' . absint( $cart_item['quantity'] ) . '</span>';
		if ( $this->is_first_box_cart_item( $cart_item_key, $box_id ) ) {
			$output .= '<span class="olr-box-cart-actions"><a href="' . esc_url( add_query_arg( 'edit_box', $box_id, home_url( '/' . self::PAGE_SLUG . '/' ) ) ) . '">' . esc_html__( 'Edit box', 'off-label-build-a-box' ) . '</a></span>';
		}
		return $output;
	}

	/**
	 * Convert each protected line's remove action into whole-box removal.
	 *
	 * @param string $html Existing remove link.
	 * @param string $cart_item_key Cart key.
	 * @return string
	 */
	public function box_remove_link( $html, $cart_item_key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $html;
		}
		$item = WC()->cart->get_cart_item( $cart_item_key );
		if ( ! is_array( $item ) || empty( $item[ self::CART_BOX_ID ] ) ) {
			return $html;
		}
		$box_id = $this->sanitize_box_id( $item[ self::CART_BOX_ID ] );
		$url    = wp_nonce_url(
			add_query_arg( array( 'olr_box_action' => 'remove', 'olr_box_id' => $box_id ), wc_get_cart_url() ),
			'olr_remove_box_' . $box_id
		);
		return '<a href="' . esc_url( $url ) . '" class="remove olr-remove-box" aria-label="' . esc_attr__( 'Remove this entire research box', 'off-label-build-a-box' ) . '" data-product_id="">&times;</a>';
	}

	/**
	 * Mark box lines for scoped cart styling.
	 *
	 * @param string $class Existing class.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart key.
	 * @return string
	 */
	public function cart_item_class( $class, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );
		return empty( $cart_item[ self::CART_BOX_ID ] ) ? $class : trim( $class . ' olr-box-cart-item' );
	}

	/**
	 * Explain the non-stacking policy before customers interact with coupons.
	 */
	public function coupon_policy_notice() {
		if ( ! $this->cart_has_box() ) {
			return;
		}
		wc_print_notice( __( 'Build Your Box pricing is already applied to the selected bottles and cannot be combined with coupon codes.', 'off-label-build-a-box' ), 'notice' );
	}

	/**
	 * Save customer-readable and internal group data to native order lines.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values Cart values.
	 * @param WC_Order              $order Order.
	 */
	public function save_order_item_metadata( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );
		if ( empty( $values[ self::CART_BOX_ID ] ) ) {
			return;
		}
		$size    = absint( $values[ self::CART_BOX_SIZE ] );
		$rate    = $this->tier_rate( $size );
		$regular = isset( $values[ self::CART_REGULAR_PRICE ] ) ? (float) $values[ self::CART_REGULAR_PRICE ] : 0.0;
		$savings = $regular * $rate * absint( $values['quantity'] );
		$item->add_meta_data( 'Research Box', $this->tier_label( $size ), true );
		$item->add_meta_data( 'Box discount', absint( $rate * 100 ) . '%', true );
		if ( $savings > 0 ) {
			$item->add_meta_data( 'Box savings', wp_strip_all_tags( wc_price( $savings ) ), true );
		}
		$item->add_meta_data( '_olr_box_id', $this->sanitize_box_id( $values[ self::CART_BOX_ID ] ), true );
		$item->add_meta_data( '_olr_box_size', $size, true );
		$item->add_meta_data( '_olr_box_rate', $rate, true );
	}

	/**
	 * Handle a nonce-protected whole-box removal link from the cart.
	 */
	public function handle_cart_box_action() {
		$action = isset( $_GET['olr_box_action'] ) && is_scalar( $_GET['olr_box_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['olr_box_action'] ) ) : '';
		$box_id = isset( $_GET['olr_box_id'] ) ? $this->sanitize_box_id( wp_unslash( $_GET['olr_box_id'] ) ) : '';
		if ( 'remove' !== $action || ! $box_id ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'olr_remove_box_' . $box_id ) ) {
			wp_die( esc_html__( 'That box removal link has expired. Return to your cart and try again.', 'off-label-build-a-box' ), '', array( 'response' => 403 ) );
		}
		$this->ensure_cart();
		if ( $this->remove_box_from_cart( $box_id ) ) {
			WC()->cart->calculate_totals();
			WC()->cart->set_session();
			wc_add_notice( __( 'The research box was removed from your cart.', 'off-label-build-a-box' ), 'success' );
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Add a product-level eligibility checkbox.
	 */
	public function product_eligibility_field() {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ELIGIBLE,
				'label'       => __( 'Build Your Box eligible', 'off-label-build-a-box' ),
				'description' => __( 'Show this bottle in the Build Your Box product selector. Sale-priced, unsupported, image-less, and out-of-stock products remain excluded.', 'off-label-build-a-box' ),
			)
		);
	}

	/**
	 * Persist product eligibility through the product CRUD API.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_product_eligibility( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$product->update_meta_data( self::META_ELIGIBLE, isset( $_POST[ self::META_ELIGIBLE ] ) ? 'yes' : 'no' );
	}

	/**
	 * Add a compact product-list status column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function product_columns( $columns ) {
		$columns['olr_box_eligible'] = __( 'Box eligible', 'off-label-build-a-box' );
		return $columns;
	}

	/**
	 * Render the product eligibility column.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Product post ID.
	 */
	public function product_column_value( $column, $post_id ) {
		if ( 'olr_box_eligible' !== $column ) {
			return;
		}
		echo 'yes' === get_post_meta( $post_id, self::META_ELIGIBLE, true ) ? '<span aria-label="Eligible">✓</span>' : '<span aria-label="Not eligible">—</span>';
	}

	/**
	 * Add product-list bulk controls for box eligibility.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function product_bulk_actions( $actions ) {
		$actions['olr_box_enable']  = __( 'Enable Build Your Box', 'off-label-build-a-box' );
		$actions['olr_box_disable'] = __( 'Disable Build Your Box', 'off-label-build-a-box' );
		return $actions;
	}

	/**
	 * Persist the core-nonced product-list bulk action through WooCommerce CRUD.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Selected action.
	 * @param array  $post_ids     Selected product IDs.
	 * @return string
	 */
	public function handle_product_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'olr_box_enable', 'olr_box_disable' ), true ) || ! current_user_can( 'edit_products' ) ) {
			return $redirect_url;
		}

		$value = 'olr_box_enable' === $action ? 'yes' : 'no';
		$count = 0;
		foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
			$product = wc_get_product( $post_id );
			if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$product->update_meta_data( self::META_ELIGIBLE, $value );
			$product->save();
			$count++;
		}

		return add_query_arg(
			array(
				'olr_box_bulk'  => 'yes',
				'olr_box_state' => $value,
				'olr_box_count' => $count,
			),
			$redirect_url
		);
	}

	/**
	 * Confirm a completed product eligibility bulk action.
	 */
	public function product_bulk_notice() {
		if ( ! current_user_can( 'edit_products' ) || empty( $_GET['olr_box_bulk'] ) ) {
			return;
		}
		$count = isset( $_GET['olr_box_count'] ) ? absint( $_GET['olr_box_count'] ) : 0;
		$state = isset( $_GET['olr_box_state'] ) && 'yes' === sanitize_text_field( wp_unslash( $_GET['olr_box_state'] ) );
		$message = sprintf(
			/* translators: 1: product count, 2: eligibility state. */
			_n( '%1$d product was %2$s for Build Your Box.', '%1$d products were %2$s for Build Your Box.', $count, 'off-label-build-a-box' ),
			$count,
			$state ? __( 'enabled', 'off-label-build-a-box' ) : __( 'disabled', 'off-label-build-a-box' )
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Explain a missing WooCommerce dependency without breaking the site.
	 */
	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Off Label Build Your Box requires WooCommerce.', 'off-label-build-a-box' ) . '</p></div>';
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-product', 'product' ), true ) ) {
			return;
		}
		$enabled = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_ELIGIBLE,
				'meta_value'     => 'yes',
			)
		);
		if ( ! $enabled ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Build Your Box has no enabled products. Select bottle products, choose Enable Build Your Box from Bulk actions, and click Apply.', 'off-label-build-a-box' ) . '</p></div>';
		}
	}

	/**
	 * Return grouped box lines from a cart.
	 *
	 * @param WC_Cart|null $cart Cart instance.
	 * @return array
	 */
	private function cart_box_groups( $cart = null ) {
		$cart = $cart instanceof WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart instanceof WC_Cart ) {
			return array();
		}
		$groups = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$box_id = isset( $item[ self::CART_BOX_ID ] ) ? $this->sanitize_box_id( $item[ self::CART_BOX_ID ] ) : '';
			if ( ! $box_id ) {
				continue;
			}
			if ( ! isset( $groups[ $box_id ] ) ) {
				$groups[ $box_id ] = array( 'size' => absint( $item[ self::CART_BOX_SIZE ] ), 'quantity' => 0, 'keys' => array() );
			}
			$groups[ $box_id ]['quantity'] += absint( $item['quantity'] );
			$groups[ $box_id ]['keys'][]    = $key;
		}
		return $groups;
	}

	/**
	 * Return an editable builder state from the current cart session.
	 *
	 * @param string $box_id Box ID.
	 * @return array
	 */
	private function builder_state_for_box( $box_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}
		$items = array();
		$tier  = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_BOX_ID ] ) || $box_id !== $this->sanitize_box_id( $item[ self::CART_BOX_ID ] ) ) {
				continue;
			}
			$tier = absint( $item[ self::CART_BOX_SIZE ] );
			$product = wc_get_product( $item['product_id'] );
			$current = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product instanceof WC_Product || ! $current instanceof WC_Product ) {
				continue;
			}
			$image_id = $current->get_image_id() ? $current->get_image_id() : $product->get_image_id();
			$image    = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );
			$name     = wp_strip_all_tags( $product->get_name() );
			if ( $current instanceof WC_Product_Variation && function_exists( 'wc_get_formatted_variation' ) ) {
				$variation_name = wp_strip_all_tags( wc_get_formatted_variation( $current, true, true, true ) );
				$name          .= $variation_name ? ' — ' . $variation_name : '';
			}
			$items[] = array(
				'product_id'   => $product->get_id(),
				'variation_id' => $current instanceof WC_Product_Variation ? $current->get_id() : 0,
				'quantity'     => absint( $item['quantity'] ),
				'name'         => $name,
				'image'        => $image,
				'regular_price'=> $this->display_regular_price( $current ),
			);
		}
		return $items && in_array( $tier, array( 5, 10 ), true ) ? array( 'tier' => $tier, 'items' => $items ) : array();
	}

	/**
	 * Snapshot a box before an atomic edit.
	 *
	 * @param string $box_id Box ID.
	 * @return array
	 */
	private function snapshot_box( $box_id ) {
		$snapshot = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $snapshot;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_BOX_ID ] ) || $box_id !== $this->sanitize_box_id( $item[ self::CART_BOX_ID ] ) ) {
				continue;
			}
			$data = $item;
			foreach ( array( 'key', 'data', 'product_id', 'variation_id', 'variation', 'quantity', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ) as $reserved ) {
				unset( $data[ $reserved ] );
			}
			$snapshot[] = array(
				'product_id'   => absint( $item['product_id'] ),
				'variation_id' => absint( $item['variation_id'] ),
				'variation'    => isset( $item['variation'] ) ? (array) $item['variation'] : array(),
				'quantity'     => absint( $item['quantity'] ),
				'data'         => $data,
			);
		}
		return $snapshot;
	}

	/**
	 * Restore a previous box when an edit cannot be completed.
	 *
	 * @param array $snapshot Saved lines.
	 */
	private function restore_box_snapshot( $snapshot ) {
		if ( ! $snapshot || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( $snapshot as $entry ) {
			WC()->cart->add_to_cart( $entry['product_id'], $entry['quantity'], $entry['variation_id'], $entry['variation'], $entry['data'] );
		}
	}

	/**
	 * Remove every line assigned to one box.
	 *
	 * @param string $box_id Box ID.
	 * @return bool
	 */
	private function remove_box_from_cart( $box_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		$removed = false;
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $item[ self::CART_BOX_ID ] ) && $box_id === $this->sanitize_box_id( $item[ self::CART_BOX_ID ] ) ) {
				WC()->cart->remove_cart_item( $key );
				$removed = true;
			}
		}
		return $removed;
	}

	/**
	 * Count current cart demand outside the box being edited.
	 *
	 * @param int    $product_id Parent product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $excluded_box_id Box being replaced.
	 * @return int
	 */
	private function cart_quantity_for_product( $product_id, $variation_id, $excluded_box_id = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}
		$quantity = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$item_box = isset( $item[ self::CART_BOX_ID ] ) ? $this->sanitize_box_id( $item[ self::CART_BOX_ID ] ) : '';
			if ( $excluded_box_id && $item_box === $excluded_box_id ) {
				continue;
			}
			if ( absint( $item['product_id'] ) === absint( $product_id ) && absint( $item['variation_id'] ) === absint( $variation_id ) ) {
				$quantity += absint( $item['quantity'] );
			}
		}
		return $quantity;
	}

	/**
	 * Check whether a box ID exists in the active cart.
	 *
	 * @param string $box_id Box ID.
	 * @return bool
	 */
	private function box_exists_in_cart( $box_id ) {
		$groups = $this->cart_box_groups();
		return isset( $groups[ $box_id ] );
	}

	/**
	 * Check whether any protected box line exists.
	 *
	 * @return bool
	 */
	private function cart_has_box() {
		return (bool) $this->cart_box_groups();
	}

	/**
	 * Determine the first cart line for a box.
	 *
	 * @param string $cart_item_key Cart key.
	 * @param string $box_id Box ID.
	 * @return bool
	 */
	private function is_first_box_cart_item( $cart_item_key, $box_id ) {
		$groups = $this->cart_box_groups();
		return isset( $groups[ $box_id ]['keys'][0] ) && $groups[ $box_id ]['keys'][0] === $cart_item_key;
	}

	/**
	 * Ensure the WooCommerce cart is initialized on AJAX and shortcode requests.
	 */
	private function ensure_cart() {
		if ( function_exists( 'WC' ) && ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Add a WooCommerce notice once per request.
	 *
	 * @param string $message Notice text.
	 * @param string $type Notice type.
	 */
	private function add_notice_once( $message, $type = 'error' ) {
		if ( function_exists( 'wc_has_notice' ) && wc_has_notice( $message, $type ) ) {
			return;
		}
		wc_add_notice( $message, $type );
	}

	/**
	 * Restrict box identifiers to UUID-safe characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_box_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( sanitize_text_field( (string) $value ) );
		return preg_match( '/^[a-z0-9-]{8,64}$/', $value ) ? $value : '';
	}

	/**
	 * Fixed discount rate for an approved tier.
	 *
	 * @param int $tier Box size.
	 * @return float
	 */
	private function tier_rate( $tier ) {
		return 5 === absint( $tier ) ? 0.25 : ( 10 === absint( $tier ) ? 0.30 : 0.0 );
	}

	/**
	 * Fixed public label for an approved tier.
	 *
	 * @param int $tier Box size.
	 * @return string
	 */
	private function tier_label( $tier ) {
		return 10 === absint( $tier ) ? __( 'The Ten', 'off-label-build-a-box' ) : __( 'The Five', 'off-label-build-a-box' );
	}

	/**
	 * Small inline proof icons matching the existing line-art system.
	 *
	 * @param string $type Icon type.
	 * @return string
	 */
	private function proof_icon( $type ) {
		$icons = array(
			'purity'   => '<svg viewBox="0 0 40 40" aria-hidden="true"><path d="M16 6h8M18 6v9L10.5 29a3 3 0 0 0 2.7 4.5h13.6a3 3 0 0 0 2.7-4.5L22 15V6" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M13.5 27h13" fill="none" stroke="currentColor" stroke-width="1.2"/></svg>',
			'tested'   => '<svg viewBox="0 0 40 40" aria-hidden="true"><path d="m20 4 13 5v9c0 8-5.2 14.2-13 18-7.8-3.8-13-10-13-18V9l13-5Z" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="m14 20 4 4 8-9" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>',
			'shipping' => '<svg viewBox="0 0 40 40" aria-hidden="true"><path d="M4 10h21v18H4zM25 16h6l5 6v6H25z" fill="none" stroke="currentColor" stroke-width="1.4"/><circle cx="11" cy="30" r="3" fill="none" stroke="currentColor" stroke-width="1.4"/><circle cx="30" cy="30" r="3" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>',
			'research' => '<svg viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="15" fill="none" stroke="currentColor" stroke-width="1.4"/><text x="20" y="23" text-anchor="middle" font-size="9" font-weight="700" fill="currentColor">21+</text></svg>',
		);
		return isset( $icons[ $type ] ) ? $icons[ $type ] : '';
	}
}

register_activation_hook( __FILE__, array( 'OLR_Build_A_Box', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
OLR_Build_A_Box::instance();
