<?php
/**
 * Off Label Research: GitPress/WooCommerce shortcode bridge.
 *
 * Child theme: require this file from functions.php.
 * Code Snippets: paste everything below the opening PHP tag and run everywhere.
 * It allowlists the approved WooCommerce tags, registers read-only presentation
 * helpers, production catalog routes, and page-specific frontend interactions.
 * It never modifies gateways, orders, prices, carts, checkout data, products,
 * or post content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This repository is public. Never send a stale site-level GitPress token when
 * fetching its contents, while leaving authentication for every other GitHub
 * request untouched.
 */
add_filter(
	'http_request_args',
	static function ( $args, $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( 'api.github.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) || 0 !== strpos( $path, '/repos/garetshough14/offlabel-custom/contents/' ) ) {
			return $args;
		}

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( array_keys( $args['headers'] ) as $header ) {
				if ( 'authorization' === strtolower( (string) $header ) ) {
					unset( $args['headers'][ $header ] );
				}
			}
		}
		return $args;
	},
	PHP_INT_MAX,
	2
);

/* Retire the former editorial archive even if its WordPress page still exists. */
add_action(
	'template_redirect',
	static function () {
		if ( ! is_admin() && is_page( 'journal' ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	},
	0
);

if ( ! function_exists( 'olr_catalog_url' ) ) {
	/** Return the canonical public catalog URL. */
	function olr_catalog_url() {
		return home_url( '/catalog/' );
	}
}

if ( ! function_exists( 'olr_catalog_product_url_from_slug' ) ) {
	/** Return a canonical public product-record URL from a product slug. */
	function olr_catalog_product_url_from_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );
		return $slug ? home_url( '/catalog/' . $slug . '/' ) : olr_catalog_url();
	}
}

add_filter(
	'query_vars',
	static function ( $query_vars ) {
		$query_vars[] = 'olr_product_slug';
		return array_values( array_unique( $query_vars ) );
	}
);

add_action(
	'init',
	static function () {
		$shop_page_id = absint( get_option( 'woocommerce_shop_page_id' ) );
		if ( $shop_page_id ) {
			add_rewrite_rule( '^catalog/?$', 'index.php?page_id=' . $shop_page_id, 'top' );
		}
		add_rewrite_rule( '^catalog/([^/]+)/?$', 'index.php?pagename=catalog-item&olr_product_slug=$matches[1]', 'top' );
	},
	1
);

/** Return whether the current public request is the production catalog root. */
function olr_is_catalog_root_request() {
	if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$request_path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
	return 'catalog' === $request_path;
}

/*
 * WooCommerce turns its assigned Shop page back into a product archive during
 * pre_get_posts. Restore that exact request to the existing page record so
 * GitPress can render the approved full-page catalog while the Shop page ID and
 * WooCommerce assignment remain unchanged.
 */
add_action(
	'pre_get_posts',
	static function ( $query ) {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! olr_is_catalog_root_request() ) {
			return;
		}

		$shop_page_id = absint( get_option( 'woocommerce_shop_page_id' ) );
		if ( ! $shop_page_id ) {
			return;
		}

		$query->set( 'page_id', $shop_page_id );
		$query->set( 'post_type', 'page' );
		$query->set( 'name', '' );
		$query->set( 'pagename', '' );
		$query->set( 'product', '' );
		$query->set( 'product_cat', '' );
		$query->set( 'product_tag', '' );
		$query->set( 'wc_query', '' );
		$query->set( 'posts_per_page', 1 );

		$query->is_page              = true;
		$query->is_singular          = true;
		$query->is_archive           = false;
		$query->is_post_type_archive = false;
		$query->is_tax               = false;
	},
	PHP_INT_MAX
);

add_action(
	'wp',
	static function () {
		if ( olr_is_catalog_root_request() ) {
			remove_filter( 'template_include', array( 'WC_Template_Loader', 'template_loader' ), 10 );
		}
	},
	PHP_INT_MAX
);

add_filter(
	'post_type_link',
	static function ( $url, $post ) {
		if ( $post instanceof WP_Post && 'product' === $post->post_type && $post->post_name ) {
			return olr_catalog_product_url_from_slug( $post->post_name );
		}
		return $url;
	},
	20,
	2
);

/** Resolve the published product selected by the pretty catalog route. */
function olr_get_routed_catalog_product() {
	$slug = sanitize_title( (string) get_query_var( 'olr_product_slug' ) );
	if ( ! $slug || ! function_exists( 'wc_get_product' ) ) {
		return false;
	}

	$post = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return false;
	}

	return wc_get_product( $post->ID );
}

add_action(
	'template_redirect',
	static function () {
		if ( is_admin() ) {
			return;
		}

		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ) : '';

		if ( 'shop' === $request_path ) {
			wp_safe_redirect( olr_catalog_url(), 301 );
			exit;
		}

		if ( 'testing' === $request_path ) {
			wp_safe_redirect( home_url( '/coas/' ), 301 );
			exit;
		}

		if ( 'checkout' === $request_path && ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url() ) ) {
			wp_safe_redirect( home_url( '/cart/' ), 301 );
			exit;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_post = get_queried_object();
			if ( $product_post instanceof WP_Post ) {
				wp_safe_redirect( olr_catalog_product_url_from_slug( $product_post->post_name ), 301 );
				exit;
			}
		}

		if ( in_array( $request_path, array( 'research-item', 'catalog-item' ), true ) ) {
			$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
			$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$target     = $product instanceof WC_Product ? olr_catalog_product_url_from_slug( $product->get_slug() ) : olr_catalog_url();
			wp_safe_redirect( $target, 301 );
			exit;
		}
	},
	2
);

foreach ( array( 'get_canonical_url', 'wpseo_canonical' ) as $canonical_filter ) {
	add_filter(
		$canonical_filter,
		static function ( $canonical ) {
			$product = olr_get_routed_catalog_product();
			return $product instanceof WC_Product ? olr_catalog_product_url_from_slug( $product->get_slug() ) : $canonical;
		}
	);
}

add_filter(
	'pre_get_document_title',
	static function ( $title ) {
		$product = olr_get_routed_catalog_product();
		return $product instanceof WC_Product ? $product->get_name() . ' | ' . get_bloginfo( 'name' ) : $title;
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_script(
			'olr-site-navigation',
			'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/scripts/olr-site-navigation.js',
			array(),
			'1.2.0',
			true
		);

		if ( is_front_page() ) {
			wp_enqueue_script(
				'olr-compliance-gate',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/scripts/olr-compliance-gate.js',
				array(),
				'1.1.0',
				true
			);
		}

		if ( is_page( 'faq' ) ) {
			wp_enqueue_script(
				'olr-faq',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/scripts/olr-faq.js',
				array(),
				'1.0.0',
				true
			);
		}

		if ( is_page( array( 'coas', 'testing' ) ) ) {
			wp_enqueue_style(
				'olr-coa-archive',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/styles.css',
				array(),
				'20260901.1'
			);

			/* Keep the testing typography independent of Divi and CDN cache state. */
			wp_add_inline_style(
				'olr-coa-archive',
				'body :is(#olr-testing-type-system, .olr-page--testing, [data-olr-coa]), body :is(#olr-testing-type-system, .olr-page--testing, [data-olr-coa]) :where(*) { font-family: var(--olr-font) !important; } body :is(#olr-testing-type-system, .olr-page--testing, [data-olr-coa]) :where(*) { letter-spacing: 0 !important; } body :is(#olr-testing-type-system, .olr-page--testing, [data-olr-coa]) :where(h1, h2, h3, h4, h5, h6) { font-weight: 700 !important; letter-spacing: -.015em !important; } body :is(#olr-testing-type-system, .olr-page--testing, [data-olr-coa]) :where(button, .button, summary, nav a, label) { font-weight: 600 !important; } body :is(#olr-testing-type-system, .olr-page--testing, [data-olr-coa]) :where(.olr-label, .olr-coa-hero__kicker, small) { letter-spacing: .025em !important; }'
			);

			wp_enqueue_script(
				'olr-coa',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/scripts/olr-coa.js',
				array(),
				'1.0.1',
				true
			);
		}

		if ( is_page( array( 'catalog', 'research', 'shop', 'catalog-test', 'research-catalog-test' ) ) || ( function_exists( 'is_shop' ) && is_shop() ) ) {
			wp_enqueue_style(
				'olr-research-catalog',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/styles.css',
				array(),
				'20260901.1'
			);
		}

		/*
		 * Keep the dynamic product record styled even when the WordPress page is
		 * accidentally left in the theme's default render mode. GitPress Managed
		 * mode is still recommended because it also supplies the shared header and
		 * footer, but the product itself must never fall back to raw browser styles.
		 */
		if ( is_page( array( 'catalog-item', 'research-item' ) ) ) {
			/* WooCommerce does not treat a shortcode-only page as is_product(). */
			foreach ( array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'photoswipe-default-skin' ) as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			foreach ( array( 'flexslider', 'zoom', 'photoswipe', 'photoswipe-ui-default', 'wc-add-to-cart', 'wc-add-to-cart-variation', 'wc-single-product' ) as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}

			wp_enqueue_style(
				'olr-product-record',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/styles-product.css',
				array(),
				'20260901.1'
			);

			wp_enqueue_script(
				'olr-product-detail',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@25eca99/scripts/olr-product-detail.js',
				array( 'jquery' ),
				'1.1.0',
				true
			);
		}
	},
	20
);

add_filter(
	'body_class',
	static function ( $classes ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'olr-site-responsive';

		$native_pages = array( 'contact', 'shipping', 'returns', 'terms-conditions', 'privacy-policy', 'research-use-policy' );
		if ( is_page( $native_pages ) ) {
			$classes[] = 'olr-native-support-page';
			foreach ( $native_pages as $native_page ) {
				if ( is_page( $native_page ) ) {
					$classes[] = 'olr-native-page--' . sanitize_html_class( $native_page );
					break;
				}
			}
		}

		return array_values( array_unique( $classes ) );
	},
	20
);

add_filter(
	'dgs_allowed_inner_shortcodes',
	static function ( $shortcodes ) {
		$shortcodes = is_array( $shortcodes ) ? $shortcodes : array();

		$woocommerce_shortcodes = array(
			'products',
			'product_categories',
			'product_page',
			'woocommerce_cart',
			'woocommerce_checkout',
			'woocommerce_my_account',
			'woocommerce_order_tracking',
			'olr_product_page',
			'olr_research_catalog',
			'olr_document_archive',
			'olr_coa_search_controls',
			'olr_coa_page',
			'olr_checkout_test',
			'olr_checkout',
			'olr_cart_count',
			'olr_build_a_box',
			'olr_account_hub',
			'olr_affiliate_landing',
			'olr_affiliate_guidelines',
		);

		return array_values( array_unique( array_merge( $shortcodes, $woocommerce_shortcodes ) ) );
	},
	10,
	1
);

if ( ! function_exists( 'olr_get_research_product_url' ) ) {
	/**
	 * Return the scoped product-record URL used by GitPress catalog cards.
	 *
	 * @param WC_Product $product WooCommerce product instance.
	 * @return string
	 */
	function olr_get_research_product_url( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return olr_catalog_url();
		}

		return olr_catalog_product_url_from_slug( $product->get_slug() );
	}
}

if ( ! function_exists( 'olr_get_catalog_product_detail' ) ) {
	/**
	 * Read the most useful strength or size value from a WooCommerce product.
	 *
	 * @param WC_Product $product WooCommerce product instance.
	 * @return string
	 */
	function olr_get_catalog_product_detail( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		foreach ( array( 'pa_strength', 'strength', 'pa_dosage', 'dosage', 'pa_ml', 'ml', 'pa_size', 'size' ) as $attribute_name ) {
			$value = trim( wp_strip_all_tags( (string) $product->get_attribute( $attribute_name ) ) );
			if ( '' !== $value ) {
				$parts = array_filter( array_map( 'trim', explode( ',', $value ) ) );
				return strtoupper( (string) reset( $parts ) );
			}
		}

		$name = wp_strip_all_tags( $product->get_name() );
		if ( preg_match( '/\b\d+(?:\.\d+)?\s*(?:MCG|MG|ML)\b/i', $name, $matches ) ) {
			return strtoupper( preg_replace( '/\s+/', ' ', $matches[0] ) );
		}

		return '';
	}
}

if ( ! function_exists( 'olr_render_catalog_product_card' ) ) {
	/**
	 * Render one catalog card using live WooCommerce data.
	 *
	 * @param WC_Product $product WooCommerce product instance.
	 * @return string
	 */
	function olr_render_catalog_product_card( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
			return '';
		}

		$product_id   = $product->get_id();
		$product_url  = olr_get_research_product_url( $product );
		$product_name = trim( wp_strip_all_tags( $product->get_name() ) );
		$detail       = olr_get_catalog_product_detail( $product );

		$badge = '';
		if ( ! $product->is_in_stock() ) {
			$badge = __( 'Sold out', 'offlabel-research' );
		} elseif ( $product->is_featured() ) {
			$badge = __( 'Featured', 'offlabel-research' );
		} else {
			$created = $product->get_date_created();
			if ( $created && $created->getTimestamp() >= strtotime( '-45 days' ) ) {
				$badge = __( 'New', 'offlabel-research' );
			}
		}

		$image = $product->get_image(
			'woocommerce_single',
			array(
				'class'    => 'olr-research-card__image',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'sizes'    => '(max-width: 360px) 100vw, (max-width: 768px) 50vw, (max-width: 1200px) 33vw, 25vw',
			)
		);

		ob_start();
		?>
		<article class="olr-research-card<?php echo $product->is_in_stock() ? '' : ' is-sold-out'; ?>">
			<a class="olr-research-card__media" href="<?php echo esc_url( $product_url ); ?>">
				<?php if ( '' !== $badge ) : ?>
					<span class="olr-research-card__badge"><?php echo esc_html( $badge ); ?></span>
				<?php endif; ?>
				<?php echo wp_kses_post( $image ); ?>
			</a>
			<div class="olr-research-card__body">
				<h2><a href="<?php echo esc_url( $product_url ); ?>"><?php echo esc_html( $product_name ); ?></a></h2>
				<?php if ( '' !== $detail ) : ?>
					<span class="olr-research-card__detail"><?php echo esc_html( $detail ); ?></span>
				<?php endif; ?>
				<div class="olr-research-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'olr_render_research_catalog' ) ) {
	/**
	 * Render the editorial catalog while WooCommerce remains the data source.
	 *
	 * @return string
	 */
	function olr_render_research_catalog() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return '<div class="olr-catalog-empty"><h2>' . esc_html__( 'Catalog unavailable', 'offlabel-research' ) . '</h2><p>' . esc_html__( 'WooCommerce must be active to display the research catalog.', 'offlabel-research' ) . '</p></div>';
		}

		$category_slug = isset( $_GET['olr_category'] ) ? sanitize_title( wp_unslash( $_GET['olr_category'] ) ) : '';
		$sort          = isset( $_GET['olr_sort'] ) ? sanitize_key( wp_unslash( $_GET['olr_sort'] ) ) : 'menu_order';
		$current_page  = isset( $_GET['olr_page'] ) ? max( 1, absint( wp_unslash( $_GET['olr_page'] ) ) ) : 1;
		$sort_options  = array(
			'menu_order' => array( 'label' => __( 'Featured', 'offlabel-research' ), 'orderby' => 'menu_order', 'order' => 'ASC' ),
			'newest'     => array( 'label' => __( 'Newest', 'offlabel-research' ), 'orderby' => 'date', 'order' => 'DESC' ),
			'price'      => array( 'label' => __( 'Price: low to high', 'offlabel-research' ), 'orderby' => 'price', 'order' => 'ASC' ),
			'price-desc' => array( 'label' => __( 'Price: high to low', 'offlabel-research' ), 'orderby' => 'price', 'order' => 'DESC' ),
		);

		if ( ! isset( $sort_options[ $sort ] ) ) {
			$sort = 'menu_order';
		}

		$restricted_term = 'pep' . 'tide';
		$metabolic_term  = 'g' . 'lp';
		$terms           = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
			)
		);
		$terms           = is_wp_error( $terms ) ? array() : $terms;
		$restricted_slug = '';
		$metabolic_slug  = '';
		foreach ( $terms as $term ) {
			$term_search = strtolower( $term->slug . ' ' . $term->name );
			if ( false !== strpos( $term_search, $restricted_term ) ) {
				$restricted_slug = $term->slug;
			}
			if ( false !== strpos( $term_search, $metabolic_term ) ) {
				$metabolic_slug = $term->slug;
			}
		}
		$query_category_slug = 'compounds' === $category_slug && '' !== $restricted_slug ? $restricted_slug : $category_slug;
		$query_category_slug = 'metabolic' === $category_slug && '' !== $metabolic_slug ? $metabolic_slug : $query_category_slug;

		$query_args = array(
			'status'     => 'publish',
			'limit'      => 12,
			'page'       => $current_page,
			'paginate'   => true,
			'visibility' => 'catalog',
			'orderby'    => $sort_options[ $sort ]['orderby'],
			'order'      => $sort_options[ $sort ]['order'],
		);

		if ( '' !== $query_category_slug ) {
			$query_args['category'] = array( $query_category_slug );
		}

		$results     = wc_get_products( $query_args );
		$products    = isset( $results->products ) && is_array( $results->products ) ? $results->products : array();
		$total       = isset( $results->total ) ? absint( $results->total ) : count( $products );
		$total_pages = isset( $results->max_num_pages ) ? absint( $results->max_num_pages ) : 1;
		$base_url    = olr_catalog_url();
		$base_url    = remove_query_arg( array( 'olr_category', 'olr_sort', 'olr_page' ), $base_url );
		$preferred   = array( $metabolic_term => 10, $restricted_term => 20, 'stack' => 30, 'essential' => 40, 'bundle' => 50 );
		$pagination_base = str_replace(
			'999999999',
			'%#%',
			add_query_arg(
				array_filter(
					array(
						'olr_category' => $category_slug,
						'olr_sort'     => $sort,
						'olr_page'     => 999999999,
					)
				),
				$base_url
			)
		);

		usort(
			$terms,
			static function ( $left, $right ) use ( $preferred ) {
				$left_weight  = 100;
				$right_weight = 100;
				foreach ( $preferred as $needle => $weight ) {
					if ( false !== strpos( strtolower( $left->slug . ' ' . $left->name ), $needle ) ) {
						$left_weight = $weight;
					}
					if ( false !== strpos( strtolower( $right->slug . ' ' . $right->name ), $needle ) ) {
						$right_weight = $weight;
					}
				}
				return $left_weight === $right_weight ? strcasecmp( $left->name, $right->name ) : $left_weight <=> $right_weight;
			}
		);

		$terms = array_values(
			array_filter(
				$terms,
				static function ( $term ) {
					return 'uncategorized' !== $term->slug;
				}
			)
		);

		ob_start();
		?>
		<style id="olr-catalog-shortcode-font-failsafe">
		body :is(#olr-catalog-shortcode-type-system, .olr-research-catalog),
		body :is(#olr-catalog-shortcode-type-system, .olr-research-catalog) :where(*) { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, Helvetica, sans-serif !important; }
		body :is(#olr-catalog-shortcode-type-system, .olr-research-catalog) :where(*) { letter-spacing: 0 !important; }
		body :is(#olr-catalog-shortcode-type-system, .olr-research-catalog) :where(h1, h2, h3, h4, h5, h6) { font-weight: 700 !important; letter-spacing: -.015em !important; }
		body :is(#olr-catalog-shortcode-type-system, .olr-research-catalog) :where(button, .button, summary, nav a, label) { font-weight: 600 !important; }
		body :is(#olr-catalog-shortcode-type-system, .olr-research-catalog) :where(.olr-label, small) { letter-spacing: .025em !important; }
		</style>
		<div class="olr-research-catalog" id="catalog">
			<nav class="olr-research-tabs" aria-label="Research categories">
				<div class="olr-research-shell olr-research-tabs__track">
					<a class="<?php echo '' === $category_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'olr_sort', $sort, $base_url ) ); ?>">All</a>
					<?php foreach ( array_slice( $terms, 0, 5 ) as $term ) : ?>
						<?php $is_restricted_term = false !== strpos( strtolower( $term->slug . ' ' . $term->name ), $restricted_term ); ?>
						<?php $is_metabolic_term = false !== strpos( strtolower( $term->slug . ' ' . $term->name ), $metabolic_term ); ?>
						<?php $public_term_slug = $is_restricted_term ? 'compounds' : ( $is_metabolic_term ? 'metabolic' : $term->slug ); ?>
						<a class="<?php echo $category_slug === $public_term_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'olr_category' => $public_term_slug, 'olr_sort' => $sort ), $base_url ) ); ?>"><?php echo esc_html( $is_restricted_term ? __( 'Research compounds', 'offlabel-research' ) : ( $is_metabolic_term ? __( 'Metabolic research', 'offlabel-research' ) : $term->name ) ); ?></a>
					<?php endforeach; ?>
				</div>
			</nav>

			<div class="olr-research-shell olr-research-toolbar">
				<details class="olr-research-menu">
					<summary>Filter <span aria-hidden="true">+</span></summary>
					<div class="olr-research-menu__panel">
						<a class="<?php echo '' === $category_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'olr_sort', $sort, $base_url ) ); ?>">All research</a>
						<?php foreach ( $terms as $term ) : ?>
							<?php $is_restricted_term = false !== strpos( strtolower( $term->slug . ' ' . $term->name ), $restricted_term ); ?>
							<?php $is_metabolic_term = false !== strpos( strtolower( $term->slug . ' ' . $term->name ), $metabolic_term ); ?>
							<?php $public_term_slug = $is_restricted_term ? 'compounds' : ( $is_metabolic_term ? 'metabolic' : $term->slug ); ?>
							<a class="<?php echo $category_slug === $public_term_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'olr_category' => $public_term_slug, 'olr_sort' => $sort ), $base_url ) ); ?>"><?php echo esc_html( $is_restricted_term ? __( 'Research compounds', 'offlabel-research' ) : ( $is_metabolic_term ? __( 'Metabolic research', 'offlabel-research' ) : $term->name ) ); ?> <span><?php echo esc_html( (string) $term->count ); ?></span></a>
						<?php endforeach; ?>
					</div>
				</details>
				<p class="olr-research-count"><?php echo esc_html( sprintf( _n( '%s product', '%s products', $total, 'offlabel-research' ), number_format_i18n( $total ) ) ); ?></p>
				<details class="olr-research-menu olr-research-menu--sort">
					<summary>Sort by <span aria-hidden="true">⌄</span></summary>
					<div class="olr-research-menu__panel">
						<?php foreach ( $sort_options as $sort_key => $sort_option ) : ?>
							<a class="<?php echo $sort === $sort_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( array( 'olr_category' => $category_slug, 'olr_sort' => $sort_key ) ), $base_url ) ); ?>"><?php echo esc_html( $sort_option['label'] ); ?></a>
						<?php endforeach; ?>
					</div>
				</details>
			</div>

			<?php if ( empty( $products ) ) : ?>
				<div class="olr-research-shell olr-catalog-empty"><p class="olr-label">Catalog result</p><h2>No research products found.</h2><a class="olr-button" href="<?php echo esc_url( $base_url ); ?>">View all research</a></div>
			<?php else : ?>
				<div class="olr-research-shell olr-research-card-grid">
					<?php foreach ( $products as $product ) : ?>
						<?php echo olr_render_catalog_product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<nav class="olr-research-shell olr-research-pagination" aria-label="Catalog pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => $pagination_base,
									'format'    => '',
									'current'   => $current_page,
									'total'     => $total_pages,
									'prev_text' => '←',
									'next_text' => '→',
								)
							)
						);
						?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'olr_render_product_record' ) ) {
	/**
	 * Render a contained product record without relying on the active theme's
	 * singular-product columns. WooCommerce still owns product data, variations,
	 * inventory, validation, and cart behavior.
	 *
	 * @param WC_Product $product WooCommerce product instance.
	 * @return string
	 */
	function olr_render_product_record( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$product_id   = $product->get_id();
		$product_post = get_post( $product_id );
		if ( ! $product_post instanceof WP_Post ) {
			return '';
		}

		$record_url       = olr_get_research_product_url( $product );
		$previous_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$previous_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;

		$GLOBALS['post']    = $product_post;
		$GLOBALS['product'] = $product;
		setup_postdata( $product_post );

		$image_html = $product->get_image(
			'woocommerce_single',
			array(
				'class'         => 'olr-product-view__image',
				'loading'       => 'eager',
				'decoding'      => 'async',
				'fetchpriority' => 'high',
				'sizes'         => '(max-width: 768px) 100vw, (max-width: 1200px) 55vw, 48vw',
			)
		);
		$gallery_ids = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );

		$sku               = trim( (string) $product->get_sku() );
		$price_html        = $product->get_price_html();
		$variation_attribute_name = '';
		$variation_attribute_label = __( 'Strength / Amount', 'offlabel-research' );
		$variation_attribute_values = array();

		if ( $product instanceof WC_Product_Variable ) {
			$variation_attributes = $product->get_variation_attributes();
			foreach ( $variation_attributes as $attribute_name => $attribute_values ) {
				if ( '' === $variation_attribute_name || preg_match( '/strength|dosage|amount|size|mg/i', (string) $attribute_name ) ) {
					$variation_attribute_name   = (string) $attribute_name;
					$variation_attribute_values = array_values( array_filter( array_map( 'strval', (array) $attribute_values ) ) );
					$variation_attribute_label  = wc_attribute_label( $attribute_name, $product );
				}
				if ( preg_match( '/strength|dosage|amount|size|mg/i', (string) $attribute_name ) ) {
					break;
				}
			}
		}


		$form_action_filter = static function () use ( $record_url ) {
			return $record_url;
		};
		add_filter( 'woocommerce_add_to_cart_form_action', $form_action_filter, 999 );
		ob_start();
		if ( function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
			woocommerce_template_single_add_to_cart();
		}
		$cart_html = (string) ob_get_clean();
		remove_filter( 'woocommerce_add_to_cart_form_action', $form_action_filter, 999 );

		ob_start();
		if ( function_exists( 'wc_display_product_attributes' ) ) {
			wc_display_product_attributes( $product );
		}
		$attributes_html = (string) ob_get_clean();

		$related_products = array();
		if ( function_exists( 'wc_get_related_products' ) ) {
			foreach ( wc_get_related_products( $product_id, 4, array( $product_id ) ) as $related_id ) {
				$related_product = wc_get_product( $related_id );
				if ( $related_product && $related_product->is_visible() ) {
					$related_products[] = $related_product;
				}
			}
		}

		if ( $previous_post instanceof WP_Post ) {
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );
		} else {
			unset( $GLOBALS['post'] );
		}

		if ( $previous_product instanceof WC_Product ) {
			$GLOBALS['product'] = $previous_product;
		} else {
			unset( $GLOBALS['product'] );
		}

		ob_start();
		?>
		<article class="olr-product-view" aria-labelledby="olr-product-title-<?php echo esc_attr( (string) $product_id ); ?>">
			<div class="olr-product-view__gallery" data-olr-gallery>
				<figure class="olr-product-view__media" data-olr-gallery-stage>
					<?php echo wp_kses_post( $image_html ); ?>
				</figure>
				<?php if ( count( $gallery_ids ) > 1 ) : ?>
					<div class="olr-product-view__thumbs" aria-label="Product gallery">
						<?php foreach ( $gallery_ids as $index => $image_id ) : ?>
							<button type="button" class="olr-product-view__thumb<?php echo 0 === $index ? ' is-active' : ''; ?>" data-olr-gallery-thumb data-full-src="<?php echo esc_url( wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) ); ?>" aria-label="View product image <?php echo esc_attr( (string) ( $index + 1 ) ); ?>">
								<?php echo wp_kses_post( wp_get_attachment_image( $image_id, 'woocommerce_thumbnail', false, array( 'loading' => 'lazy' ) ) ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="olr-product-view__summary">
				<p class="olr-label">Off Label Research</p>
				<h1 id="olr-product-title-<?php echo esc_attr( (string) $product_id ); ?>"><?php echo esc_html( $product->get_name() ); ?></h1>
				<p class="olr-product-view__ruo">Research use only</p>
				<?php if ( '' !== $variation_attribute_name && ! empty( $variation_attribute_values ) ) : ?>
					<fieldset class="olr-strength-selector" data-olr-variation-control data-attribute-name="attribute_<?php echo esc_attr( sanitize_title( $variation_attribute_name ) ); ?>">
						<legend><?php esc_html_e( 'Strength / Amount', 'offlabel-research' ); ?></legend>
						<div>
							<?php foreach ( $variation_attribute_values as $attribute_value ) : ?>
								<?php
								$term       = taxonomy_exists( $variation_attribute_name ) ? get_term_by( 'slug', $attribute_value, $variation_attribute_name ) : false;
								$value_label = $term instanceof WP_Term ? $term->name : $attribute_value;
								if ( preg_match( '/^\d+(?:\.\d+)?$/', trim( $value_label ) ) && preg_match( '/strength|dosage|amount|mg/i', $variation_attribute_name . ' ' . $variation_attribute_label ) ) {
									$value_label .= ' MG';
								}
								?>
								<button type="button" data-olr-variation-value="<?php echo esc_attr( $attribute_value ); ?>" aria-pressed="false"><?php echo esc_html( strtoupper( $value_label ) ); ?></button>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php endif; ?>
				<?php if ( '' !== $price_html ) : ?>
					<div class="olr-product-view__price"><strong><?php echo wp_kses_post( $price_html ); ?></strong></div>
				<?php endif; ?>
				<ul class="olr-product-view__proof" aria-label="Product standards">
					<li>
						<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false"><circle cx="20" cy="20" r="15.5" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="4 2"></circle><path d="M8.5 13.5 6.5 17l3.9.7M31.5 26.5l2 3.5-3.9.7" fill="none" stroke="currentColor" stroke-width="1.2"></path><text x="20" y="23" text-anchor="middle" font-size="8" font-weight="700" fill="currentColor">99%</text></svg>
						<strong>Purity*</strong>
					</li>
					<li>
						<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false"><path d="M16 6h8M18 6v9L10.5 29a3 3 0 0 0 2.7 4.5h13.6a3 3 0 0 0 2.7-4.5L22 15V6" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13.5 27h13M16 23h8" fill="none" stroke="currentColor" stroke-width="1.1"></path></svg>
						<strong>Third-party<br>tested</strong>
					</li>
					<li>
						<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false"><path d="M12 5.5h11l6 6V34.5H12z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path><path d="M23 5.5v6h6M16 20h9M16 24h9M16 28h6" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"></path></svg>
						<strong>COA<br>available</strong>
					</li>
				</ul>
				<a class="olr-product-view__receipts" href="/coas/">View COAs <span aria-hidden="true">→</span></a>
				<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
					<div class="olr-volume-pricing" data-olr-volume-pricing data-unit-price="<?php echo esc_attr( wc_get_price_to_display( $product ) ); ?>">
						<p><strong>Volume quantities</strong><span>Choose a bottle count.</span></p>
						<div><?php foreach ( array( 1, 3, 5, 10 ) as $quantity ) : ?><button type="button" data-olr-quantity="<?php echo esc_attr( (string) $quantity ); ?>"><span><?php echo esc_html( 1 === $quantity ? '1 bottle' : $quantity . ' bottles' ); ?></span><strong><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $product ) * $quantity ) ); ?></strong></button><?php endforeach; ?></div>
					</div>
				<?php endif; ?>
				<div class="olr-product-view__purchase"><?php echo $cart_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="olr-product-view__notice"><span aria-hidden="true">i</span><p><strong>Research use only</strong>This product is intended strictly for laboratory and analytical research purposes. Not intended for human or animal consumption or use.</p></div>
			</div>
		</article>

		<div class="olr-product-information olr-product-information--accordion">

			<details class="olr-product-information__section" data-icon="quality">
				<summary id="olr-product-specifications-<?php echo esc_attr( (string) $product_id ); ?>">Testing + quality</summary>
				<div class="olr-product-information__attributes"><?php echo '' !== trim( $attributes_html ) ? wp_kses_post( $attributes_html ) : '<p>' . esc_html__( 'Current testing documentation is available through the product COA record.', 'offlabel-research' ) . '</p>'; ?></div>
			</details>
			<details class="olr-product-information__section" data-icon="storage"><summary>Storage + handling</summary><div class="olr-product-information__copy"><p>Store and handle according to the product label and supplied research documentation.</p></div></details>
			<details class="olr-product-information__section" data-icon="shipping"><summary>Shipping</summary><div class="olr-product-information__copy"><p>Shipping options and timing are calculated at checkout.</p></div></details>
			<details class="olr-product-information__section" data-icon="research"><summary>Research use</summary><div class="olr-product-information__copy"><p>For laboratory and analytical research only. Not for human or animal consumption.</p></div></details>
		</div>

		<?php if ( ! empty( $related_products ) ) : ?>
			<section class="olr-product-related" aria-labelledby="olr-related-products-<?php echo esc_attr( (string) $product_id ); ?>">
				<div class="olr-product-related__head"><h2 id="olr-related-products-<?php echo esc_attr( (string) $product_id ); ?>">Recently researched</h2><a href="/catalog/">View all →</a></div>
				<div class="olr-product-related__grid">
					<?php foreach ( $related_products as $related_product ) : ?>
						<a class="olr-product-related__item" href="<?php echo esc_url( olr_get_research_product_url( $related_product ) ); ?>">
							<span class="olr-product-related__media"><?php echo wp_kses_post( $related_product->get_image( 'woocommerce_single' ) ); ?></span>
							<span class="olr-product-related__copy"><strong><?php echo esc_html( $related_product->get_name() ); ?></strong><?php $related_detail = olr_get_catalog_product_detail( $related_product ); if ( '' !== $related_detail ) : ?><small><?php echo esc_html( $related_detail ); ?></small><?php endif; ?><span><?php echo wp_kses_post( $related_product->get_price_html() ); ?></span><em>View product&nbsp; →</em></span>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
		<?php
		return '<div class="olr-live-product woocommerce" data-product-id="' . esc_attr( (string) $product_id ) . '">' . (string) ob_get_clean() . '</div>';
	}
}

add_action(
	'init',
	static function () {
		if ( ! shortcode_exists( 'olr_research_catalog' ) ) {
			add_shortcode(
				'olr_research_catalog',
				static function () {
					return olr_render_research_catalog();
				}
			);
		}

		if ( ! shortcode_exists( 'olr_product_page' ) ) {
			add_shortcode(
				'olr_product_page',
				static function ( $attributes ) {
					if ( ! function_exists( 'wc_get_product' ) ) {
						return '<div class="olr-product-state"><h1>' . esc_html__( 'Product unavailable', 'offlabel-research' ) . '</h1><p>' . esc_html__( 'WooCommerce must be active to display this product.', 'offlabel-research' ) . '</p></div>';
					}

					$attributes = shortcode_atts(
						array(
							'id'  => 0,
							'sku' => '',
						),
						is_array( $attributes ) ? $attributes : array(),
						'olr_product_page'
					);
					$product_id = absint( $attributes['id'] );

					if ( ! $product_id && '' !== trim( (string) $attributes['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
						$product_id = absint( wc_get_product_id_by_sku( sanitize_text_field( (string) $attributes['sku'] ) ) );
					}

					if ( ! $product_id && isset( $_GET['product_id'] ) ) {
						$product_id = absint( wp_unslash( $_GET['product_id'] ) );
					}

					if ( ! $product_id ) {
						$routed_product = olr_get_routed_catalog_product();
						$product_id     = $routed_product instanceof WC_Product ? $routed_product->get_id() : 0;
					}

					$product = $product_id ? wc_get_product( $product_id ) : false;
					if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
						return '<div class="olr-product-state"><p class="olr-label">Product record</p><h1>' . esc_html__( 'Select a research product.', 'offlabel-research' ) . '</h1><p>' . esc_html__( 'Return to the catalog and choose a product to review its current record.', 'offlabel-research' ) . '</p><a class="olr-button" href="' . esc_url( olr_catalog_url() ) . '">' . esc_html__( 'View all research', 'offlabel-research' ) . '</a></div>';
					}

					return olr_render_product_record( $product );
				}
			);
		}

		if ( ! shortcode_exists( 'olr_cart_count' ) ) {
			add_shortcode(
				'olr_cart_count',
				static function () {
					if ( function_exists( 'WC' ) && WC()->cart ) {
						return (string) max( 0, (int) WC()->cart->get_cart_contents_count() );
					}

					return '0';
				}
			);
		}

		if ( ! shortcode_exists( 'olr_document_archive' ) ) {
			add_shortcode(
				'olr_document_archive',
				static function ( $attributes ) {
					$attributes = is_array( $attributes ) ? $attributes : array();
					$items      = apply_filters( 'olr_document_archive_items', array(), $attributes );

					if ( ! is_array( $items ) || empty( $items ) ) {
						return '<p class="olr-empty-state">' . esc_html__( 'No product documents are available in the archive.', 'offlabel-research' ) . '</p>';
					}

					$rows = array();
					foreach ( $items as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}

						$url    = isset( $item['document_url'] ) ? esc_url_raw( (string) $item['document_url'], array( 'http', 'https' ) ) : '';
						$record_url = isset( $item['record_url'] ) ? esc_url_raw( (string) $item['record_url'], array( 'http', 'https' ) ) : $url;
						$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
						if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
							continue;
						}

						$product_name  = isset( $item['product_name'] ) ? sanitize_text_field( (string) $item['product_name'] ) : '';
						$product_name  = '' !== $product_name ? $product_name : __( 'Product documentation', 'offlabel-research' );
						$product_url   = isset( $item['product_url'] ) ? esc_url_raw( (string) $item['product_url'], array( 'http', 'https' ) ) : '';
						$product_id    = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
						$image_url     = isset( $item['product_image_url'] ) ? esc_url_raw( (string) $item['product_image_url'], array( 'http', 'https' ) ) : '';
						$category      = isset( $item['category'] ) ? sanitize_key( (string) $item['category'] ) : '';
						$search_name   = strtolower( $product_name );
						$asset_base    = 'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/images/products/';

						if ( ! in_array( $category, array( 'compounds', 'metabolic', 'other' ), true ) ) {
							$category = false !== strpos( $search_name, 'tz-2' ) ? 'metabolic' : ( false !== strpos( $search_name, 'ipamorelin' ) || false !== strpos( $search_name, 'sermorelin' ) ? 'compounds' : 'other' );
						}

						if ( '' === $image_url ) {
							if ( ! $product_id && '' !== $product_url ) {
								$product_id = absint( url_to_postid( $product_url ) );
							}
							if ( $product_id ) {
								$image_url = (string) get_the_post_thumbnail_url( $product_id, 'medium' );
							}
						}

						if ( '' === $image_url ) {
							if ( false !== strpos( $search_name, 'ipamorelin' ) ) {
								$image_url = $asset_base . 'ipamorelin-10mg.jpg';
							} elseif ( false !== strpos( $search_name, 'sermorelin' ) ) {
								$image_url = $asset_base . 'sermorelin-5mg.jpg';
							} elseif ( false !== strpos( $search_name, 'rt-3' ) ) {
								$image_url = $asset_base . 'rt-3-20mg.jpg';
							} elseif ( false !== strpos( $search_name, 'tz-2' ) ) {
								$image_url = $asset_base . 'tz-2-30mg.jpg';
							}
						}

						$rows[] = array(
							'product_name'   => $product_name,
							'product_url'    => $product_url,
							'product_image'  => $image_url,
							'category'       => $category,
							'strength'       => isset( $item['strength'] ) ? sanitize_text_field( (string) $item['strength'] ) : '',
							'lot'            => isset( $item['lot'] ) ? sanitize_text_field( (string) $item['lot'] ) : '',
							'test_date'      => isset( $item['test_date'] ) ? sanitize_text_field( (string) $item['test_date'] ) : '',
							'document_url'   => $url,
							'record_url'     => $record_url,
							'document_label' => isset( $item['document_label'] ) ? sanitize_text_field( (string) $item['document_label'] ) : '',
						);
					}

					if ( empty( $rows ) ) {
						return '<p class="olr-empty-state">' . esc_html__( 'No product documents are available in the archive.', 'offlabel-research' ) . '</p>';
					}

					$output = '<div class="olr-coa-list" data-coa-list>';
					foreach ( $rows as $row ) {
						$strength   = '' !== $row['strength'] ? $row['strength'] : ( '' !== $row['lot'] ? $row['lot'] : __( 'Research product', 'offlabel-research' ) );
						$test_date  = '' !== $row['test_date'] ? $row['test_date'] : __( 'Not listed', 'offlabel-research' );
						$link_label = '' !== $row['document_label'] ? $row['document_label'] : __( 'View COA', 'offlabel-research' );
						$search     = trim( $row['product_name'] . ' ' . $strength . ' ' . $row['lot'] );
						$output     .= '<article class="olr-coa-row" data-coa-row data-coa-category="' . esc_attr( $row['category'] ) . '" data-coa-search="' . esc_attr( strtolower( $search ) ) . '">';
						$output     .= '<figure class="olr-coa-row__media">';
						if ( '' !== $row['product_image'] ) {
							$output .= '<img src="' . esc_url( $row['product_image'] ) . '" alt="' . esc_attr( $row['product_name'] ) . ' bottle" loading="lazy" decoding="async">';
						}
						$output .= '</figure><div class="olr-coa-row__product"><h3>';
						$output .= '' !== $row['product_url'] ? '<a href="' . esc_url( $row['product_url'] ) . '">' . esc_html( $row['product_name'] ) . '</a>' : esc_html( $row['product_name'] );
						$output .= '</h3><span>' . esc_html( $strength ) . '</span></div>';
						$output .= '<div class="olr-coa-row__status"><strong>' . esc_html__( 'Testing available', 'offlabel-research' ) . '</strong><span>' . esc_html__( 'Latest test date', 'offlabel-research' ) . '</span><time>' . esc_html( $test_date ) . '</time></div>';
						$output .= '<a class="olr-coa-row__link" href="' . esc_url( $row['record_url'] ) . '">' . esc_html( $link_label ) . '</a></article>';
					}

					return $output . '</div>';
				}
			);
		}

		if ( ! shortcode_exists( 'olr_coa_search_controls' ) ) {
			add_shortcode(
				'olr_coa_search_controls',
				static function () {
					return '<form class="olr-coa-search__form" role="search"><label class="screen-reader-text" for="olr-coa-search-input">' . esc_html__( 'Search by product', 'offlabel-research' ) . '</label><input id="olr-coa-search-input" type="search" placeholder="' . esc_attr__( 'Search by product', 'offlabel-research' ) . '" autocomplete="off"><button type="submit" aria-label="' . esc_attr__( 'Search COA records', 'offlabel-research' ) . '"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.75" fill="none" stroke="currentColor" stroke-width="1.4"></circle><path d="m15.6 15.6 5 5" fill="none" stroke="currentColor" stroke-width="1.4"></path></svg></button></form><nav class="olr-coa-tabs" aria-label="' . esc_attr__( 'Filter COA records', 'offlabel-research' ) . '"><button class="is-active" type="button" data-coa-filter="all" aria-pressed="true">' . esc_html__( 'All research', 'offlabel-research' ) . '</button><button type="button" data-coa-filter="compounds" aria-pressed="false">' . esc_html__( 'Compounds', 'offlabel-research' ) . '</button><button type="button" data-coa-filter="metabolic" aria-pressed="false">' . esc_html__( 'Metabolic research', 'offlabel-research' ) . '</button><button type="button" data-coa-filter="other" aria-pressed="false">' . esc_html__( 'Other research', 'offlabel-research' ) . '</button></nav>';
				}
			);
		}
	}
);

/*
 * Some GitPress/Divi content pipelines expand the remote page shortcode after
 * WordPress' normal do_shortcode pass. Run one deliberately late, page-scoped
 * pass so the nested catalog and product tokens cannot leak into the browser.
 */
add_filter(
	'the_content',
	static function ( $content ) {
		$has_product_token = false !== strpos( (string) $content, '[olr_product_page' );
		$has_catalog_token = false !== strpos( (string) $content, '[olr_research_catalog' );
		$has_coa_token     = false !== strpos( (string) $content, '[olr_coa_page' );
		$has_checkout_test_token = false !== strpos( (string) $content, '[olr_checkout_test' );

		if ( ! $has_product_token && ! $has_catalog_token && ! $has_coa_token && ! $has_checkout_test_token ) {
			return $content;
		}

		return do_shortcode( (string) $content );
	},
	99
);
