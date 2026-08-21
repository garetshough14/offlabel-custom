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
				'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/styles.css',
				array(),
				'20260820.3'
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
			'olr_journal',
			'olr_document_archive',
			'olr_cart_count',
		);

		return array_values( array_unique( array_merge( $shortcodes, $woocommerce_shortcodes ) ) );
	},
	10,
	1
);

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
			<figure class="olr-product-view__media">
				<?php echo wp_kses_post( $image_html ); ?>
			</figure>

			<div class="olr-product-view__summary">
				<p class="olr-label">Off Label Research / Product record</p>
				<h1 id="olr-product-title-<?php echo esc_attr( (string) $product_id ); ?>"><?php echo esc_html( $product->get_name() ); ?></h1>
				<?php if ( '' !== $price_html ) : ?>
					<div class="olr-product-view__price"><?php echo wp_kses_post( $price_html ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $short_description ) : ?>
					<div class="olr-product-view__intro"><?php echo wp_kses_post( wc_format_content( $short_description ) ); ?></div>
				<?php endif; ?>
				<div class="olr-product-view__purchase"><?php echo $cart_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<dl class="olr-product-view__meta">
					<div><dt>SKU</dt><dd><?php echo esc_html( '' !== $sku ? $sku : 'N/A' ); ?></dd></div>
					<div><dt>Category</dt><dd><?php echo '' !== $categories ? wp_kses_post( $categories ) : esc_html__( 'Uncategorized', 'offlabel-research' ); ?></dd></div>
				</dl>
			</div>
		</article>

		<div class="olr-product-information">
			<?php if ( '' !== $description ) : ?>
				<section class="olr-product-information__section" aria-labelledby="olr-product-description-<?php echo esc_attr( (string) $product_id ); ?>">
					<h2 id="olr-product-description-<?php echo esc_attr( (string) $product_id ); ?>">Description</h2>
					<div class="olr-product-information__copy"><?php echo wp_kses_post( wc_format_content( $description ) ); ?></div>
				</section>
			<?php endif; ?>

			<?php if ( '' !== trim( $attributes_html ) ) : ?>
				<section class="olr-product-information__section" aria-labelledby="olr-product-specifications-<?php echo esc_attr( (string) $product_id ); ?>">
					<h2 id="olr-product-specifications-<?php echo esc_attr( (string) $product_id ); ?>">Specifications</h2>
					<div class="olr-product-information__attributes"><?php echo wp_kses_post( $attributes_html ); ?></div>
				</section>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $related_products ) ) : ?>
			<section class="olr-product-related" aria-labelledby="olr-related-products-<?php echo esc_attr( (string) $product_id ); ?>">
				<h2 id="olr-related-products-<?php echo esc_attr( (string) $product_id ); ?>">Related research</h2>
				<div class="olr-product-related__grid">
					<?php foreach ( $related_products as $related_product ) : ?>
						<a class="olr-product-related__item" href="<?php echo esc_url( $related_product->get_permalink() ); ?>">
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
 * pass so the nested [olr_product_page] token cannot leak into the browser.
 */
add_filter(
	'the_content',
	static function ( $content ) {
		if ( ! is_page( 'research-item' ) || false === strpos( (string) $content, '[olr_product_page' ) ) {
			return $content;
		}

		return do_shortcode( (string) $content );
	},
	99
);
