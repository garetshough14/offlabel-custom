<?php
/**
 * Off Label Research: GitPress/WooCommerce shortcode bridge.
 *
 * Child theme: require this file from functions.php.
 * Code Snippets: paste everything below the opening PHP tag and run everywhere.
 * It allowlists the approved WooCommerce tags, registers read-only presentation
 * helpers and loads page-specific frontend interactions. It never
 * modifies gateways, orders, prices, carts, checkout data, products, or post
 * content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	static function () {
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

		if ( is_page( 'coas' ) || is_page( 'testing' ) ) {
			wp_enqueue_script(
				'olr-coa',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/scripts/olr-coa.js',
				array(),
				'1.0.0',
				true
			);
		}

		if ( is_page( array( 'research', 'shop', 'research-catalog-test' ) ) || ( function_exists( 'is_shop' ) && is_shop() ) ) {
			wp_enqueue_style(
				'olr-research-catalog',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/styles.css',
				array(),
				'20260821.2'
			);
		}

		/*
		 * Keep the dynamic product record styled even when the WordPress page is
		 * accidentally left in the theme's default render mode. GitPress Managed
		 * mode is still recommended because it also supplies the shared header and
		 * footer, but the product itself must never fall back to raw browser styles.
		 */
		if ( is_page( 'research-item' ) ) {
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
				'20260827.2'
			);

			wp_enqueue_script(
				'olr-product-detail',
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/scripts/olr-product-detail.js',
				array( 'jquery' ),
				'1.0.2',
				true
			);
		}
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
			'olr_journal',
			'olr_document_archive',
			'olr_cart_count',
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
			return home_url( '/shop/' );
		}

		return add_query_arg( 'product_id', $product->get_id(), home_url( '/research-item/' ) );
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
		$terms        = get_the_terms( $product_id, 'product_cat' );
		$category     = __( 'Research product', 'offlabel-research' );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( 'uncategorized' !== $term->slug ) {
					$category = $term->name;
					break;
				}
			}
		}

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
				<p><?php echo esc_html( strtoupper( $category ) ); ?></p>
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

		$query_args = array(
			'status'     => 'publish',
			'limit'      => 12,
			'page'       => $current_page,
			'paginate'   => true,
			'visibility' => 'catalog',
			'orderby'    => $sort_options[ $sort ]['orderby'],
			'order'      => $sort_options[ $sort ]['order'],
		);

		if ( '' !== $category_slug ) {
			$query_args['category'] = array( $category_slug );
		}

		$results     = wc_get_products( $query_args );
		$products    = isset( $results->products ) && is_array( $results->products ) ? $results->products : array();
		$total       = isset( $results->total ) ? absint( $results->total ) : count( $products );
		$total_pages = isset( $results->max_num_pages ) ? absint( $results->max_num_pages ) : 1;
		$terms       = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
			)
		);
		$terms       = is_wp_error( $terms ) ? array() : $terms;
		$base_url    = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : home_url( '/shop/' );
		$base_url    = remove_query_arg( array( 'olr_category', 'olr_sort', 'olr_page' ), $base_url );
		$preferred   = array( 'glp' => 10, 'peptide' => 20, 'stack' => 30, 'essential' => 40, 'bundle' => 50 );
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
		<div class="olr-research-catalog" id="catalog">
			<nav class="olr-research-tabs" aria-label="Research categories">
				<div class="olr-research-shell olr-research-tabs__track">
					<a class="<?php echo '' === $category_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'olr_sort', $sort, $base_url ) ); ?>">All</a>
					<?php foreach ( array_slice( $terms, 0, 5 ) as $term ) : ?>
						<a class="<?php echo $category_slug === $term->slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'olr_category' => $term->slug, 'olr_sort' => $sort ), $base_url ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
					<?php endforeach; ?>
				</div>
			</nav>

			<div class="olr-research-shell olr-research-toolbar">
				<details class="olr-research-menu">
					<summary>Filter <span aria-hidden="true">+</span></summary>
					<div class="olr-research-menu__panel">
						<a class="<?php echo '' === $category_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'olr_sort', $sort, $base_url ) ); ?>">All research</a>
						<?php foreach ( $terms as $term ) : ?>
							<a class="<?php echo $category_slug === $term->slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'olr_category' => $term->slug, 'olr_sort' => $sort ), $base_url ) ); ?>"><?php echo esc_html( $term->name ); ?> <span><?php echo esc_html( (string) $term->count ); ?></span></a>
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
					<?php foreach ( array_slice( $products, 0, 8 ) as $product ) : ?>
						<?php echo olr_render_catalog_product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>

				<section class="olr-research-feature" aria-labelledby="olr-research-feature-title">
					<div class="olr-research-feature__copy">
						<p>Featured research</p>
						<h2 id="olr-research-feature-title">Research<br>without<br>the noise.</h2>
						<span>We focus on quality, evidence,<br>and doing things differently.</span>
						<a href="<?php echo esc_url( olr_get_research_product_url( $products[0] ) ); ?>">Explore <b aria-hidden="true">→</b></a>
					</div>
				</section>

				<?php if ( count( $products ) > 8 ) : ?>
					<div class="olr-research-shell olr-research-card-grid olr-research-card-grid--after-feature">
						<?php foreach ( array_slice( $products, 8, 4 ) as $product ) : ?>
							<?php echo olr_render_catalog_product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

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

		$record_page_id  = get_queried_object_id();
		$record_page_url = $record_page_id ? get_permalink( $record_page_id ) : home_url( '/research-item/' );
		$record_url      = add_query_arg( 'product_id', $product_id, $record_page_url );
		$previous_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$previous_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;

		$GLOBALS['post']    = $product_post;
		$GLOBALS['product'] = $product;
		setup_postdata( $product_post );

		$image_html = $product->get_image(
			'woocommerce_single',
			array(
				'class'    => 'olr-product-view__image',
				'loading'  => 'eager',
				'decoding' => 'async',
			)
		);
		$gallery_ids = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );

		$short_description = trim( (string) $product->get_short_description() );
		$description       = trim( (string) $product->get_description() );
		$sku               = trim( (string) $product->get_sku() );
		$categories        = wc_get_product_category_list( $product_id, ', ' );
		$price_html        = $product->get_price_html();

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
				<div class="olr-product-view__purchase"><?php echo $cart_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
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
				<a class="olr-product-view__receipts" href="/coas/">View the receipts <span aria-hidden="true">→</span></a>
				<?php if ( '' !== $short_description ) : ?>
					<div class="olr-product-view__intro"><?php echo wp_kses_post( wc_format_content( $short_description ) ); ?></div>
				<?php endif; ?>
				<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
					<div class="olr-volume-pricing" data-olr-volume-pricing data-unit-price="<?php echo esc_attr( wc_get_price_to_display( $product ) ); ?>">
						<p><strong>Volume quantities</strong><span>Choose a bottle count.</span></p>
						<div><?php foreach ( array( 1, 3, 5, 10 ) as $quantity ) : ?><button type="button" data-olr-quantity="<?php echo esc_attr( (string) $quantity ); ?>"><span><?php echo esc_html( 1 === $quantity ? '1 bottle' : $quantity . ' bottles' ); ?></span><strong><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $product ) * $quantity ) ); ?></strong></button><?php endforeach; ?></div>
					</div>
				<?php endif; ?>
				<div class="olr-product-view__notice"><span aria-hidden="true">i</span><p><strong>Research use only</strong>This product is intended strictly for laboratory and analytical research purposes. Not intended for human or animal consumption or use.</p></div>
			</div>
		</article>

		<div class="olr-product-information olr-product-information--accordion">
			<?php if ( '' !== $description ) : ?>
				<details class="olr-product-information__section">
					<summary id="olr-product-description-<?php echo esc_attr( (string) $product_id ); ?>">Product information</summary>
					<div class="olr-product-information__copy"><?php echo wp_kses_post( wc_format_content( $description ) ); ?></div>
				</details>
			<?php endif; ?>

			<?php if ( '' !== trim( $attributes_html ) ) : ?>
				<details class="olr-product-information__section">
					<summary id="olr-product-specifications-<?php echo esc_attr( (string) $product_id ); ?>">Testing + quality</summary>
					<div class="olr-product-information__attributes"><?php echo wp_kses_post( $attributes_html ); ?></div>
				</details>
			<?php endif; ?>
			<details class="olr-product-information__section"><summary>Storage + handling</summary><div class="olr-product-information__copy"><p>Store and handle according to the product label and supplied research documentation.</p></div></details>
			<details class="olr-product-information__section"><summary>Shipping</summary><div class="olr-product-information__copy"><p>Shipping options and timing are calculated at checkout.</p></div></details>
			<details class="olr-product-information__section"><summary>Research use</summary><div class="olr-product-information__copy"><p>For laboratory and analytical research only. Not for human or animal consumption.</p></div></details>
		</div>

		<?php if ( ! empty( $related_products ) ) : ?>
			<section class="olr-product-related" aria-labelledby="olr-related-products-<?php echo esc_attr( (string) $product_id ); ?>">
				<div class="olr-product-related__head"><h2 id="olr-related-products-<?php echo esc_attr( (string) $product_id ); ?>">Recently researched</h2><a href="/shop/">View all →</a></div>
				<div class="olr-product-related__grid">
					<?php foreach ( $related_products as $related_product ) : ?>
						<a class="olr-product-related__item" href="<?php echo esc_url( olr_get_research_product_url( $related_product ) ); ?>">
							<span class="olr-product-related__media"><?php echo wp_kses_post( $related_product->get_image( 'woocommerce_thumbnail' ) ); ?></span>
							<strong><?php echo esc_html( $related_product->get_name() ); ?></strong>
							<span><?php echo wp_kses_post( $related_product->get_price_html() ); ?></span>
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

					$product = $product_id ? wc_get_product( $product_id ) : false;
					if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
						return '<div class="olr-product-state"><p class="olr-label">Product record</p><h1>' . esc_html__( 'Select a research product.', 'offlabel-research' ) . '</h1><p>' . esc_html__( 'Return to the catalog and choose a product to review its current record.', 'offlabel-research' ) . '</p><a class="olr-button" href="' . esc_url( home_url( '/shop/' ) ) . '">' . esc_html__( 'View all research', 'offlabel-research' ) . '</a></div>';
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

		if ( ! shortcode_exists( 'olr_journal' ) ) {
			add_shortcode(
				'olr_journal',
				static function ( $attributes ) {
					$attributes = shortcode_atts(
						array( 'limit' => 9 ),
						is_array( $attributes ) ? $attributes : array(),
						'olr_journal'
					);
					$limit      = min( 12, max( 1, absint( $attributes['limit'] ) ) );
					$query      = new WP_Query(
						array(
							'post_type'           => 'post',
							'post_status'         => 'publish',
							'posts_per_page'      => $limit,
							'ignore_sticky_posts' => true,
							'no_found_rows'       => true,
						)
					);

					if ( ! $query->have_posts() ) {
						return '<p class="olr-empty-state">' . esc_html__( 'No journal entries are available.', 'offlabel-research' ) . '</p>';
					}

					$output = '<div class="olr-journal-grid" role="list">';
					foreach ( $query->posts as $post ) {
						$post_id    = (int) $post->ID;
						$permalink  = get_permalink( $post_id );
						$title      = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
						$excerpt    = wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 24, '...' );
						$categories = get_the_category( $post_id );
						$category   = is_array( $categories ) && isset( $categories[0]->name ) ? (string) $categories[0]->name : '';
						$title      = '' !== $title ? $title : __( 'Read article', 'offlabel-research' );

						$output .= '<article class="olr-journal-card" role="listitem">';
						if ( has_post_thumbnail( $post_id ) ) {
							$image = get_the_post_thumbnail(
								$post_id,
								'large',
								array(
									'class'    => 'olr-journal-card__image',
									'loading'  => 'lazy',
									'decoding' => 'async',
								)
							);
							if ( '' !== $image ) {
								$output .= '<a class="olr-journal-card__media" href="' . esc_url( $permalink ) . '" tabindex="-1" aria-hidden="true">' . wp_kses_post( $image ) . '</a>';
							}
						}

						$output .= '<div class="olr-journal-card__content"><div class="olr-journal-card__meta">';
						if ( '' !== $category ) {
							$output .= '<span>' . esc_html( $category ) . '</span>';
						}
						$output .= '<time datetime="' . esc_attr( get_the_date( 'c', $post_id ) ) . '">' . esc_html( get_the_date( '', $post_id ) ) . '</time></div>';
						$output .= '<h3 class="olr-journal-card__title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';
						if ( '' !== $excerpt ) {
							$output .= '<p class="olr-journal-card__excerpt">' . esc_html( $excerpt ) . '</p>';
						}
						$output .= '<a class="olr-text-link" href="' . esc_url( $permalink ) . '">' . esc_html__( 'Read journal entry', 'offlabel-research' ) . '</a></div></article>';
					}

					return $output . '</div>';
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

						if ( ! in_array( $category, array( 'peptides', 'glp', 'other' ), true ) ) {
							$category = false !== strpos( $search_name, 'tz-2' ) ? 'glp' : ( false !== strpos( $search_name, 'ipamorelin' ) || false !== strpos( $search_name, 'sermorelin' ) ? 'peptides' : 'other' );
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
						$output .= '<a class="olr-coa-row__link" href="' . esc_url( $row['document_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link_label ) . '</a></article>';
					}

					return $output . '</div>';
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

		if ( ! $has_product_token && ! $has_catalog_token ) {
			return $content;
		}

		return do_shortcode( (string) $content );
	},
	99
);
