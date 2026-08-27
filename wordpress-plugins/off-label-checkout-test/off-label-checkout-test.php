<?php
/**
 * Plugin Name: Off Label Checkout Test
 * Description: Isolated, branded WooCommerce checkout preview at /checkout-test/ with a non-charging test gateway.
 * Version: 1.0.1
 * Author: Off Label Research
 * Text Domain: off-label-checkout-test
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLR_CHECKOUT_TEST_VERSION', '1.0.1' );
define( 'OLR_CHECKOUT_TEST_FILE', __FILE__ );

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
				'cartUrl'       => wc_get_cart_url(),
				'stageLabels'   => array(
					'information' => __( 'Information', 'off-label-checkout-test' ),
					'shipping'    => __( 'Shipping', 'off-label-checkout-test' ),
					'payment'     => __( 'Payment', 'off-label-checkout-test' ),
				),
				'requiredError' => __( 'Complete the required fields before continuing.', 'off-label-checkout-test' ),
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

		$fields['billing']['olr_research_updates'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'Email me research updates and new releases', 'off-label-checkout-test' ),
			'required' => false,
			'class'    => array( 'form-row-wide', 'olr-research-updates-field' ),
			'priority' => 120,
		);
		return $fields;
	}
);

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

add_action(
	'woocommerce_checkout_create_order',
	static function ( $order, $data ) {
		$is_test = ! empty( $_POST['olr_checkout_test'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates the checkout nonce.
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

		return isset( $gateways['olr_checkout_test'] ) ? array( 'olr_checkout_test' => $gateways['olr_checkout_test'] ) : array();
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

				ob_start();
				?>
				<div class="olr-checkout-test woocommerce" data-olr-page="checkout-test" data-olr-checkout-stage="information">
					<div class="olr-checkout-test__trust" aria-label="Research and shipping standards"><span>99%+ purity</span><i aria-hidden="true">•</i><span>Third-party tested</span><i aria-hidden="true">•</i><span>Fast shipping</span></div>
					<header class="olr-checkout-test__header">
						<a class="olr-checkout-test__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Off Label Research home"><strong>OFF LABEL</strong><span>RESEARCH</span></a>
						<p>Secure checkout <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="1"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></p>
					</header>
					<nav class="olr-checkout-test__steps" aria-label="Checkout progress">
						<button type="button" data-olr-stage-target="information" aria-current="step"><b>1.</b> Information</button><span aria-hidden="true"></span>
						<button type="button" data-olr-stage-target="shipping" disabled><b>2.</b> Shipping</button><span aria-hidden="true"></span>
						<button type="button" data-olr-stage-target="payment" disabled><b>3.</b> Payment</button>
					</nav>
					<div class="olr-checkout-test__status" role="status" aria-live="polite" tabindex="-1"></div>
					<main class="olr-checkout-test__main">
						<?php echo do_shortcode( '[woocommerce_checkout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</main>
					<footer class="olr-checkout-test__footer">
						<div class="olr-checkout-test__footer-brand"><strong>OFF LABEL</strong><span>RESEARCH</span><p>Where science<br>goes off-script.</p></div>
						<div><b>Research</b><a href="/shop/">All products</a><a href="/research/">Research</a><a href="/journal/">The journal</a></div>
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
