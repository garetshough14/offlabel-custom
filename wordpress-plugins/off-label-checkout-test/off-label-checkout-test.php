<?php
/**
 * Plugin Name: Off Label Checkout Test
 * Description: Isolated, branded WooCommerce checkout preview at /checkout-test/ with active store gateways and a non-charging test option.
 * Version: 1.2.5
 * Author: Off Label Research
 * Text Domain: off-label-checkout-test
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLR_CHECKOUT_TEST_VERSION', '1.2.5' );
define( 'OLR_CHECKOUT_TEST_FILE', __FILE__ );
define( 'OLR_CHECKOUT_TEST_LOGO_URL', 'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/branding/off-label-logo-cropped-black.webp' );

register_activation_hook(
	OLR_CHECKOUT_TEST_FILE,
	static function () {
		$page = get_page_by_path( 'checkout-test' );
		if ( ! $page instanceof WP_Post ) {
			wp_insert_post(
				array(
					'post_title'   => 'Checkout Test',
					'post_name'    => 'checkout-test',
					'post_content' => '[olr_checkout_test]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'comment_status' => 'closed',
				)
			);
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook( OLR_CHECKOUT_TEST_FILE, 'flush_rewrite_rules' );

/**
 * Determine whether this request is the isolated checkout preview.
 *
 * @return bool
 */
function olr_checkout_test_is_page() {
	return ! is_admin() && is_page( 'checkout-test' );
}

/* Let gateway extensions enqueue the same assets they use on the real checkout. */
add_filter(
	'woocommerce_is_checkout',
	static function ( $is_checkout ) {
		return $is_checkout || olr_checkout_test_is_page();
	}
);

add_filter(
	'dgs_allowed_inner_shortcodes',
	static function ( $shortcodes ) {
		$shortcodes   = is_array( $shortcodes ) ? $shortcodes : array();
		$shortcodes[] = 'olr_checkout_test';
		return array_values( array_unique( $shortcodes ) );
	}
);

add_filter(
	'the_content',
	static function ( $content ) {
		return false !== strpos( (string) $content, '[olr_checkout_test' ) ? do_shortcode( (string) $content ) : $content;
	},
	100
);

add_action(
	'template_redirect',
	static function () {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( olr_checkout_test_is_page() ) {
			WC()->session->set( 'olr_checkout_test', true );
			return;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() ) {
			WC()->session->__unset( 'olr_checkout_test' );
		}
	},
	1
);

add_filter(
	'body_class',
	static function ( $classes ) {
		if ( olr_checkout_test_is_page() ) {
			$classes[] = 'woocommerce-checkout';
			$classes[] = 'olr-checkout-test-page';
		}
		return $classes;
	}
);

add_filter(
	'wp_robots',
	static function ( $robots ) {
		if ( olr_checkout_test_is_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

add_filter(
	'wp_sitemaps_posts_query_args',
	static function ( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}

		$page = get_page_by_path( 'checkout-test' );
		if ( $page instanceof WP_Post ) {
			$args['post__not_in']   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'][] = $page->ID;
		}
		return $args;
	},
	10,
	2
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! olr_checkout_test_is_page() ) {
			return;
		}

		foreach ( array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' ) as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( array( 'selectWoo', 'wc-country-select', 'wc-address-i18n', 'wc-checkout' ) as $handle ) {
			wp_enqueue_script( $handle );
		}

		wp_enqueue_style(
			'olr-checkout-test',
			plugins_url( 'assets/checkout-test.css', OLR_CHECKOUT_TEST_FILE ),
			array( 'woocommerce-general' ),
			OLR_CHECKOUT_TEST_VERSION
		);
		wp_enqueue_script(
			'olr-checkout-test',
			plugins_url( 'assets/checkout-test.js', OLR_CHECKOUT_TEST_FILE ),
			array( 'jquery', 'wc-checkout' ),
			OLR_CHECKOUT_TEST_VERSION,
			true
		);
		wp_localize_script(
			'olr-checkout-test',
			'olrCheckoutTest',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'cartUrl'       => wc_get_cart_url(),
				'cartNonce'     => wp_create_nonce( 'olr_checkout_test_cart' ),
				'stageLabels'   => array(
					'information' => __( 'Information', 'off-label-checkout-test' ),
					'shipping'    => __( 'Shipping', 'off-label-checkout-test' ),
					'payment'     => __( 'Payment', 'off-label-checkout-test' ),
				),
				'requiredError'   => __( 'Complete the required fields before continuing.', 'off-label-checkout-test' ),
				'cartUpdateError' => __( 'Your bag could not be updated. Please try again.', 'off-label-checkout-test' ),
			)
		);
	},
	30
);

add_filter(
	'woocommerce_checkout_fields',
	static function ( $fields ) {
		if ( ! olr_checkout_test_is_page() ) {
			return $fields;
		}

		/* Keep one intentional consent field on this isolated checkout. */
		if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
			$marketing_phrases = array(
				'newsletter',
				'exclusive email',
				'discounts and product information',
				'marketing email',
				'promotional email',
				'offers by email',
			);

			foreach ( $fields['billing'] as $field_key => $field ) {
				$label = isset( $field['label'] ) ? strtolower( wp_strip_all_tags( (string) $field['label'] ) ) : '';
				foreach ( $marketing_phrases as $phrase ) {
					if ( false !== strpos( $label, $phrase ) ) {
						unset( $fields['billing'][ $field_key ] );
						break;
					}
				}
			}
		}

		$fields['billing']['olr_research_updates'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'Email me research updates and new releases', 'off-label-checkout-test' ),
			'required' => false,
			'class'    => array( 'form-row-wide', 'olr-research-updates-field' ),
			'priority' => 120,
		);
		return $fields;
	},
	999
);

/**
 * Get a compact set of visible, in-stock products for an empty checkout.
 *
 * WooCommerce's own best-selling shortcode query orders by total sales. Newest
 * products fill any remaining spaces so the state does not depend on sales
 * history alone.
 *
 * @param int $limit Maximum recommendation count.
 * @return WC_Product[]
 */
function olr_checkout_test_get_recommendations( $limit = 4 ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$limit    = max( 1, absint( $limit ) );
	$products = array();

	if ( class_exists( 'WC_Shortcode_Products' ) ) {
		$best_sellers = new WC_Shortcode_Products(
			array(
				'limit'      => max( 12, $limit * 3 ),
				'columns'    => $limit,
				'orderby'    => 'popularity',
				'order'      => 'DESC',
				'visibility' => 'visible',
			),
			'best_selling_products'
		);
		$query        = new WP_Query( $best_sellers->get_query_args() );
		$product_ids  = wp_parse_id_list( $query->posts );

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product || ! $product->is_visible() || ! $product->is_in_stock() ) {
				continue;
			}
			$products[] = $product;
			if ( count( $products ) >= $limit ) {
				break;
			}
		}

		if ( function_exists( 'WC' ) && WC()->query ) {
			WC()->query->remove_ordering_args();
		}
	}

	if ( count( $products ) < $limit ) {
		$fallbacks = wc_get_products(
			array(
				'status'       => 'publish',
				'limit'        => $limit,
				'visibility'   => 'visible',
				'stock_status' => 'instock',
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		$seen = array();
		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product ) {
				$seen[ $product->get_id() ] = true;
			}
		}
		foreach ( $fallbacks as $product ) {
			if ( ! $product instanceof WC_Product || isset( $seen[ $product->get_id() ] ) ) {
				continue;
			}
			$products[]                 = $product;
			$seen[ $product->get_id() ] = true;
			if ( count( $products ) >= $limit ) {
				break;
			}
		}
	}

	return array_slice( $products, 0, $limit );
}

add_filter(
	'woocommerce_cart_item_name',
	static function ( $name, $cart_item ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->session->get( 'olr_checkout_test' ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
			return $name;
		}
		$image = $cart_item['data']->get_image( 'woocommerce_thumbnail', array( 'class' => 'olr-checkout-test__product-image', 'loading' => 'eager' ) );
		return '<span class="olr-checkout-test__product">' . wp_kses_post( $image ) . '<span class="olr-checkout-test__product-copy">' . $name . '</span></span>';
	},
	10,
	2
);

add_filter(
	'woocommerce_checkout_cart_item_quantity',
	static function ( $quantity_html, $cart_item, $cart_item_key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->session->get( 'olr_checkout_test' ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
			return $quantity_html;
		}

		$product           = $cart_item['data'];
		$quantity          = isset( $cart_item['quantity'] ) ? max( 1, absint( $cart_item['quantity'] ) ) : 1;
		$maximum_quantity  = (int) $product->get_max_purchase_quantity();
		$increase_disabled = 0 < $maximum_quantity && $quantity >= $maximum_quantity;
		$product_name      = wp_strip_all_tags( $product->get_name() );

		ob_start();
		?>
		<span class="olr-checkout-quantity" data-olr-cart-item="<?php echo esc_attr( $cart_item_key ); ?>" data-quantity="<?php echo esc_attr( (string) $quantity ); ?>">
			<button type="button" data-olr-quantity-change="-1" aria-label="<?php echo esc_attr( 1 === $quantity ? sprintf( __( 'Remove %s', 'off-label-checkout-test' ), $product_name ) : sprintf( __( 'Decrease %s quantity', 'off-label-checkout-test' ), $product_name ) ); ?>"><span aria-hidden="true">−</span></button>
			<output aria-label="<?php esc_attr_e( 'Quantity', 'off-label-checkout-test' ); ?>"><?php echo esc_html( (string) $quantity ); ?></output>
			<button type="button" data-olr-quantity-change="1" aria-label="<?php echo esc_attr( sprintf( __( 'Increase %s quantity', 'off-label-checkout-test' ), $product_name ) ); ?>"<?php disabled( $increase_disabled ); ?>><span aria-hidden="true">+</span></button>
		</span>
		<button class="olr-checkout-remove" type="button" data-olr-remove-item="<?php echo esc_attr( $cart_item_key ); ?>"><?php esc_html_e( 'Remove', 'off-label-checkout-test' ); ?></button>
		<?php
		return (string) ob_get_clean();
	},
	10,
	3
);

/**
 * Update a checkout-test cart line without sending the customer back to Bag.
 */
function olr_checkout_test_update_cart_quantity() {
	check_ajax_referer( 'olr_checkout_test_cart', 'nonce' );

	if ( ! function_exists( 'WC' ) ) {
		wp_send_json_error( array( 'message' => __( 'WooCommerce is unavailable.', 'off-label-checkout-test' ) ), 503 );
	}

	if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}

	if ( ! WC()->session || ! WC()->cart || ! WC()->session->get( 'olr_checkout_test' ) ) {
		wp_send_json_error( array( 'message' => __( 'Your checkout session has expired. Refresh and try again.', 'off-label-checkout-test' ) ), 403 );
	}

	$cart_item_key = isset( $_POST['cart_item_key'] ) ? wc_clean( wp_unslash( $_POST['cart_item_key'] ) ) : '';
	$quantity      = isset( $_POST['quantity'] ) ? max( 0, absint( wp_unslash( $_POST['quantity'] ) ) ) : 0;
	$cart_item     = '' !== $cart_item_key ? WC()->cart->get_cart_item( $cart_item_key ) : false;

	if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
		wp_send_json_error( array( 'message' => __( 'That product is no longer in your bag.', 'off-label-checkout-test' ) ), 404 );
	}

	$maximum_quantity = (int) $cart_item['data']->get_max_purchase_quantity();
	if ( 0 < $maximum_quantity ) {
		$quantity = min( $quantity, $maximum_quantity );
	}

	if ( 0 === $quantity ) {
		$updated = WC()->cart->remove_cart_item( $cart_item_key );
	} else {
		$updated = WC()->cart->set_quantity( $cart_item_key, $quantity, true );
	}

	if ( false === $updated ) {
		wp_send_json_error( array( 'message' => __( 'Your bag could not be updated.', 'off-label-checkout-test' ) ), 400 );
	}

	WC()->cart->calculate_totals();
	wp_send_json_success(
		array(
			'cartEmpty' => WC()->cart->is_empty(),
			'cartCount' => WC()->cart->get_cart_contents_count(),
			'quantity'  => $quantity,
		)
	);
}

add_action( 'wp_ajax_olr_checkout_test_update_cart', 'olr_checkout_test_update_cart_quantity' );
add_action( 'wp_ajax_nopriv_olr_checkout_test_update_cart', 'olr_checkout_test_update_cart_quantity' );

add_action(
	'woocommerce_checkout_create_order',
	static function ( $order, $data ) {
		$is_test = isset( $data['payment_method'] ) && 'olr_checkout_test' === $data['payment_method'];
		if ( ! $is_test || ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( '_olr_checkout_test', 'yes' );
		$order->update_meta_data( '_olr_research_updates', ! empty( $data['olr_research_updates'] ) ? 'yes' : 'no' );
		$order->add_order_note( __( 'Created through the isolated OLR checkout test. No payment was collected.', 'off-label-checkout-test' ) );
	},
	10,
	2
);

add_filter(
	'woocommerce_can_reduce_order_stock',
	static function ( $can_reduce, $order ) {
		return $order instanceof WC_Order && 'yes' === $order->get_meta( '_olr_checkout_test' ) ? false : $can_reduce;
	},
	10,
	2
);

foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ) as $email_id ) {
	add_filter(
		'woocommerce_email_enabled_' . $email_id,
		static function ( $enabled, $order ) {
			return $order instanceof WC_Order && 'yes' === $order->get_meta( '_olr_checkout_test' ) ? false : $enabled;
		},
		10,
		2
	);
}

add_action(
	'woocommerce_review_order_before_payment',
	static function () {
		if ( function_exists( 'WC' ) && WC()->session && WC()->session->get( 'olr_checkout_test' ) ) {
			echo '<input type="hidden" name="olr_checkout_test" value="1">';
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		class OLR_Checkout_Test_Gateway extends WC_Payment_Gateway {
			/** Initialize the non-charging test gateway. */
			public function __construct() {
				$this->id                 = 'olr_checkout_test';
				$this->method_title       = __( 'OLR Test Payment', 'off-label-checkout-test' );
				$this->method_description = __( 'Creates a labeled test order without collecting or transmitting payment details.', 'off-label-checkout-test' );
				$this->title              = __( 'OLR Test Payment — no charge', 'off-label-checkout-test' );
				$this->description        = __( 'Testing only. No card or bank information is requested, transmitted, or charged.', 'off-label-checkout-test' );
				$this->has_fields         = false;
				$this->enabled            = 'yes';
			}

			/** @return bool */
			public function is_available() {
				$is_pay_endpoint = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
				return ! $is_pay_endpoint && function_exists( 'WC' ) && WC()->session && (bool) WC()->session->get( 'olr_checkout_test' );
			}

			/**
			 * Complete a test checkout without contacting a processor.
			 *
			 * @param int $order_id Order ID.
			 * @return array
			 */
			public function process_payment( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					wc_add_notice( __( 'The test order could not be created. Please try again.', 'off-label-checkout-test' ), 'error' );
					return array( 'result' => 'failure' );
				}

				$order->update_meta_data( '_olr_checkout_test', 'yes' );
				$order->save();
				$order->update_status( 'on-hold', __( 'OLR test checkout completed; no payment collected.', 'off-label-checkout-test' ) );
				WC()->cart->empty_cart();

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'OLR_Checkout_Test_Gateway';
				return $gateways;
			}
		);
	}
);

add_filter(
	'woocommerce_available_payment_gateways',
	static function ( $gateways ) {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			unset( $gateways['olr_checkout_test'] );
			return $gateways;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->session->get( 'olr_checkout_test' ) ) {
			unset( $gateways['olr_checkout_test'] );
			return $gateways;
		}

		/* Keep every gateway WooCommerce considers available on this checkout. */
		return $gateways;
	},
	999
);

add_action(
	'init',
	static function () {
		add_shortcode(
			'olr_checkout_test',
			static function () {
				if ( ! class_exists( 'WooCommerce' ) ) {
					return '<div class="olr-checkout-test-state"><h1>' . esc_html__( 'Checkout unavailable', 'off-label-checkout-test' ) . '</h1><p>' . esc_html__( 'WooCommerce must be active to use the checkout test.', 'off-label-checkout-test' ) . '</p></div>';
				}

				if ( function_exists( 'WC' ) && WC()->session ) {
					WC()->session->set( 'olr_checkout_test', true );
				}

				$cart_is_empty   = ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty();
				$recommendations = $cart_is_empty ? olr_checkout_test_get_recommendations( 4 ) : array();
				$shop_url        = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
				$shop_url        = $shop_url ? $shop_url : home_url( '/shop/' );

				ob_start();
				?>
				<div class="olr-checkout-test woocommerce<?php echo $cart_is_empty ? ' olr-checkout-test--empty' : ''; ?>" data-olr-page="checkout-test" data-olr-cart-state="<?php echo $cart_is_empty ? 'empty' : 'active'; ?>"<?php echo $cart_is_empty ? '' : ' data-olr-checkout-stage="information"'; ?>>
					<?php if ( ! $cart_is_empty ) : ?>
						<nav class="olr-checkout-test__steps" aria-label="Checkout progress">
							<button type="button" data-olr-stage-target="information" aria-current="step"><b>1.</b> Information</button><span aria-hidden="true"></span>
							<button type="button" data-olr-stage-target="shipping" disabled><b>2.</b> Shipping</button><span aria-hidden="true"></span>
							<button type="button" data-olr-stage-target="payment" disabled><b>3.</b> Payment</button>
						</nav>
						<div class="olr-checkout-test__status" role="status" aria-live="polite" tabindex="-1"></div>
					<?php endif; ?>
					<main class="olr-checkout-test__main">
						<?php if ( $cart_is_empty ) : ?>
							<section class="olr-checkout-empty" aria-labelledby="olr-empty-title">
								<div class="olr-checkout-empty__intro">
									<span class="olr-checkout-empty__eyebrow">Your bag</span>
									<h1 id="olr-empty-title">Your bag is empty.</h1>
									<p>Add a research product before continuing to checkout.</p>
									<a class="olr-checkout-empty__shop" href="<?php echo esc_url( $shop_url ); ?>">Shop all products <span aria-hidden="true">→</span></a>
								</div>
								<?php if ( $recommendations ) : ?>
									<div class="olr-checkout-empty__recommendations">
										<div class="olr-checkout-empty__heading"><div><span>Most researched</span><h2>Best-selling products</h2></div><a href="<?php echo esc_url( $shop_url ); ?>">View all <span aria-hidden="true">→</span></a></div>
										<div class="olr-checkout-empty__grid">
											<?php foreach ( $recommendations as $product ) : ?>
												<article class="olr-checkout-empty__product">
													<a class="olr-checkout-empty__image" href="<?php echo esc_url( $product->get_permalink() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View %s', 'off-label-checkout-test' ), $product->get_name() ) ); ?>">
														<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) ); ?>
													</a>
													<div class="olr-checkout-empty__product-meta"><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a><span><?php echo wp_kses_post( $product->get_price_html() ); ?></span></div>
												</article>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
							</section>
						<?php else : ?>
							<?php echo do_shortcode( '[woocommerce_checkout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</main>
					<footer class="olr-checkout-test__footer">
						<div class="olr-checkout-test__footer-brand"><img src="<?php echo esc_url( OLR_CHECKOUT_TEST_LOGO_URL ); ?>" alt="Off Label Research" width="1199" height="169" loading="lazy"><p>Where science<br>goes off-script.</p></div>
						<div><b>Research</b><a href="/shop/">All products</a><a href="/research/">Research</a></div>
						<div><b>Company</b><a href="/about/">About</a><a href="/testing/">Testing</a><a href="/faq/">FAQ</a><a href="/contact/">Contact</a></div>
						<div><b>Account</b><a href="/my-account/">Account</a><a href="/my-account/orders/">Orders</a><a href="/cart/">Bag</a></div>
						<div><b>Support</b><a href="/shipping/">Shipping</a><a href="/returns/">Returns</a><a href="/terms/">Terms</a><a href="/privacy-policy/">Privacy</a></div>
					</footer>
					<div class="olr-checkout-test__legal"><span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Off Label Research. All rights reserved.</span><span>Research differently.</span></div>
				</div>
				<?php
				return (string) ob_get_clean();
			}
		);
	}
);
