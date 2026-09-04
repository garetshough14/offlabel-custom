<?php
/**
 * Plugin Name: Off Label Test Page Seeder
 * Description: Creates isolated GitPress-managed test pages for the Off Label production rollout.
 * Version: 1.1.2
 * Author: Off Label Research
 * Text Domain: off-label-test-page-seeder
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OLR_Test_Page_Seeder {
	const MARKER_META = '_olr_test_page_seeder';
	const VERSION     = '1.1.2';
	const ASSET_BASE  = 'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/';

	/**
	 * Return the complete isolated test-page map.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function pages() {
		return array(
			'home-test' => array(
				'title'    => 'Home Test',
				'fragment' => 'home.html',
			),
			'about-test' => array(
				'title'    => 'About Test',
				'fragment' => 'about.html',
			),
			'research-catalog-test' => array(
				'title'    => 'Research Catalog Test',
				'fragment' => 'research.html',
			),
			'research-item' => array(
				'title'    => 'Research Item Test',
				'fragment' => 'product.html',
			),
			'testing-test' => array(
				'title'    => 'Testing and COAs Test',
				'fragment' => 'testing.html',
			),
			'receipt-test' => array(
				'title'    => 'COA Receipt Test',
				'fragment' => 'receipt.html',
			),
			'faq-test' => array(
				'title'    => 'FAQ Test',
				'fragment' => 'faq.html',
			),
			'cart-test' => array(
				'title'    => 'Cart Test',
				'fragment' => 'cart.html',
			),
			'build-your-box-test' => array(
				'title'    => 'Build Your Box Test',
				'fragment' => 'build-your-box.html',
			),
			'checkout-test' => array(
				'title'    => 'Checkout Test',
				'fragment' => 'checkout-test.html',
			),
			'account-test' => array(
				'title'    => 'Account Test',
				'fragment' => 'account.html',
			),
			'affiliate-test' => array(
				'title'    => 'Affiliate Program Test',
				'fragment' => 'affiliate.html',
			),
			'affiliate-guidelines-test' => array(
				'title'    => 'Affiliate Guidelines Test',
				'fragment' => 'affiliate-guidelines.html',
			),
		);
	}

	/**
	 * Build the GitPress source shortcode for a fragment.
	 *
	 * @param string $fragment Fragment filename.
	 * @return string
	 */
	private static function source_shortcode( $fragment ) {
		return sprintf(
			'[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/%s" branch="main" format="html" updated_meta="false"]',
			$fragment
		);
	}

	/**
	 * Create missing test pages and repair their GitPress settings.
	 * Existing production routes are not part of this map and are never touched.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function seed() {
		$results = array();

		foreach ( self::pages() as $slug => $definition ) {
			$page    = get_page_by_path( $slug, OBJECT, 'page' );
			$created = false;

			if ( ! $page || 'trash' === $page->post_status ) {
				$page_id = wp_insert_post(
					array(
						'post_title'     => $definition['title'],
						'post_name'      => $slug,
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'post_content'   => '',
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					),
					true
				);

				if ( is_wp_error( $page_id ) ) {
					$results[ $slug ] = array(
						'ok'      => false,
						'message' => $page_id->get_error_message(),
					);
					continue;
				}

				$created = true;
			} else {
				$page_id = (int) $page->ID;

				if ( 'publish' !== $page->post_status ) {
					$published = wp_update_post(
						array(
							'ID'          => $page_id,
							'post_status' => 'publish',
						),
						true
					);

					if ( is_wp_error( $published ) ) {
						$results[ $slug ] = array(
							'ok'      => false,
							'message' => $published->get_error_message(),
						);
						continue;
					}
				}
			}

			$shortcode = self::source_shortcode( $definition['fragment'] );

			update_post_meta( $page_id, 'dgs_page_shortcode', $shortcode );
			update_post_meta( $page_id, 'dgs_page_shortcode_render_mode', 'gitpress_managed' );
			update_post_meta( $page_id, 'dgs_page_shortcode_placement', 'after' );
			update_post_meta( $page_id, 'dgs_page_shortcode_full_width', '1' );
			update_post_meta( $page_id, self::MARKER_META, self::VERSION );

			$results[ $slug ] = array(
				'ok'      => true,
				'id'      => $page_id,
				'created' => $created,
				'url'     => get_permalink( $page_id ),
			);
		}

		update_option( 'olr_test_page_seeder_last_run', time(), false );
		return $results;
	}

	/** Seed immediately when the plugin is activated. */
	public static function activate() {
		self::seed();
		flush_rewrite_rules( false );
	}

	/** Register runtime and administration hooks. */
	public static function init() {
		add_filter( 'wp_robots', array( __CLASS__, 'noindex_test_pages' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_classes' ), 999 );
		add_filter( 'woocommerce_is_cart', array( __CLASS__, 'is_cart_test' ) );
		add_filter( 'shortcode_atts_olr_product_page', array( __CLASS__, 'product_preview_attributes' ), 10, 3 );
		add_filter( 'shortcode_atts_olr_coa_page', array( __CLASS__, 'coa_preview_attributes' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_dynamic_test_context' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 100 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_olr_seed_test_pages', array( __CLASS__, 'handle_seed_request' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_action_links' ) );
	}

	/** Determine whether the current request is one of the isolated test pages. */
	private static function is_test_page() {
		return ! is_admin() && is_page( array_keys( self::pages() ) );
	}

	/** Return the current mapped test-page slug. */
	private static function current_test_slug() {
		if ( ! self::is_test_page() ) {
			return '';
		}

		$post = get_queried_object();
		return $post instanceof WP_Post ? sanitize_key( $post->post_name ) : '';
	}

	/** Let WooCommerce initialize its native cart behavior on the cart test route. */
	public static function is_cart_test( $is_cart ) {
		return $is_cart || ( ! is_admin() && is_page( 'cart-test' ) );
	}

	/** Give the direct product test URL a real published product. */
	public static function product_preview_attributes( $attributes, $pairs, $input ) {
		if ( ! is_admin() && is_page( 'research-item' ) && empty( $attributes['id'] ) && empty( $attributes['sku'] ) && empty( $_GET['product_id'] ) ) {
			$attributes['id'] = self::preview_product_id( false );
		}
		return $attributes;
	}

	/** Give the direct receipt test URL a real report-backed product. */
	public static function coa_preview_attributes( $attributes, $pairs, $input ) {
		if ( ! is_admin() && is_page( 'receipt-test' ) && empty( $attributes['product_id'] ) && empty( $_GET['product_id'] ) ) {
			$attributes['product_id'] = self::preview_product_id( true );
		}
		return $attributes;
	}

	/** Find a real published product suitable for a dynamic-page preview. */
	private static function preview_product_id( $require_report = false ) {
		if ( $require_report && post_type_exists( 'olr_coa' ) ) {
			$reports = get_posts(
				array(
					'post_type'      => 'olr_coa',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'orderby'        => 'meta_value date',
					'meta_key'       => '_olr_test_date',
					'order'          => 'DESC',
					'meta_query'     => array(
						array(
							'key'     => '_olr_pdf_id',
							'compare' => 'EXISTS',
						),
					),
				)
			);

			foreach ( $reports as $report ) {
				$product_id = absint( get_post_meta( $report->ID, '_olr_product_id', true ) );
				if ( $product_id && 'publish' === get_post_status( $product_id ) ) {
					return $product_id;
				}
			}
		}

		$preferred = get_page_by_path( 'bpc-157', OBJECT, 'product' );
		if ( $preferred instanceof WP_Post && 'publish' === $preferred->post_status ) {
			return absint( $preferred->ID );
		}

		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => 1,
					'orderby' => 'menu_order',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			if ( ! empty( $products ) ) {
				return absint( reset( $products ) );
			}
		}

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		if ( ! empty( $product_ids ) ) {
			return absint( reset( $product_ids ) );
		}

		return 0;
	}

	/** Supply a real product/report when a tester opens a dynamic route directly. */
	public static function redirect_dynamic_test_context() {
		if ( is_admin() || ! empty( $_GET['product_id'] ) ) {
			return;
		}

		$require_report = is_page( 'receipt-test' );
		if ( ! $require_report && ! is_page( 'research-item' ) ) {
			return;
		}

		$product_id = self::preview_product_id( $require_report );
		if ( ! $product_id ) {
			return;
		}

		$page_id = get_queried_object_id();
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' . self::current_test_slug() . '/' );
		wp_safe_redirect( add_query_arg( 'product_id', $product_id, $url ), 302 );
		exit;
	}

	/** Load the same presentation and interaction assets used by the production routes. */
	public static function frontend_assets() {
		$slug = self::current_test_slug();
		if ( ! $slug ) {
			return;
		}

		wp_enqueue_style( 'olr-test-responsive', self::ASSET_BASE . 'styles.css', array(), self::VERSION );

		if ( 'home-test' === $slug ) {
			wp_enqueue_script( 'olr-test-compliance-gate', self::ASSET_BASE . 'scripts/olr-compliance-gate.js', array(), self::VERSION, true );
		}

		if ( 'faq-test' === $slug ) {
			wp_enqueue_script( 'olr-test-faq', self::ASSET_BASE . 'scripts/olr-faq.js', array(), self::VERSION, true );
		}

		if ( 'testing-test' === $slug ) {
			wp_enqueue_script( 'olr-test-coa-archive', self::ASSET_BASE . 'scripts/olr-coa.js', array(), self::VERSION, true );
		}

		if ( 'research-item' === $slug ) {
			foreach ( array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'photoswipe-default-skin' ) as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
			foreach ( array( 'flexslider', 'zoom', 'photoswipe', 'photoswipe-ui-default', 'wc-add-to-cart', 'wc-add-to-cart-variation', 'wc-single-product' ) as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
			wp_enqueue_style( 'olr-test-product-record', self::ASSET_BASE . 'styles-product.css', array(), self::VERSION );
			wp_enqueue_script( 'olr-test-product-record', self::ASSET_BASE . 'scripts/olr-product-detail.js', array( 'jquery' ), self::VERSION, true );
		}

		if ( 'receipt-test' === $slug ) {
			wp_enqueue_style( 'olr-test-coa-detail', plugins_url( 'off-label-coa-manager/assets/coa-detail.css' ), array(), self::VERSION );
			wp_enqueue_script( 'olr-test-coa-detail', plugins_url( 'off-label-coa-manager/assets/coa-detail.js' ), array(), self::VERSION, true );
			wp_localize_script(
				'olr-test-coa-detail',
				'olrCoaViewer',
				array(
					'libraryUrl' => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.mjs',
					'workerUrl'  => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs',
				)
			);
		}

		if ( in_array( $slug, array( 'account-test', 'affiliate-test', 'affiliate-guidelines-test' ), true ) ) {
			if ( 'account-test' === $slug ) {
				foreach ( array( 'uap_public_style', 'uap_templates' ) as $uap_style ) {
					wp_enqueue_style( $uap_style );
				}
				foreach ( array( 'uap-public-functions', 'uap-account_page-functions' ) as $uap_script ) {
					wp_enqueue_script( $uap_script );
				}
			}

			wp_enqueue_style( 'olr-test-account-hub', plugins_url( 'off-label-account-hub/assets/account-hub.css' ), array(), self::VERSION );
			wp_enqueue_script( 'olr-test-account-hub', plugins_url( 'off-label-account-hub/assets/account-hub.js' ), array(), self::VERSION, true );
			wp_localize_script(
				'olr-test-account-hub',
				'olrAccountHub',
				array(
					'logoutUrl'    => wp_logout_url( home_url( '/' ) ),
					'guidelinesUrl' => home_url( '/affiliate-guidelines-test/' ),
					'menuLabel'    => __( 'Account menu', 'off-label-test-page-seeder' ),
					'copyLabel'    => __( 'Copy', 'off-label-test-page-seeder' ),
					'copied'       => __( 'Copied', 'off-label-test-page-seeder' ),
				)
			);
		}

		if ( 'build-your-box-test' === $slug ) {
			wp_enqueue_style( 'olr-test-build-box', plugins_url( 'off-label-build-a-box/assets/build-a-box.css' ), array(), self::VERSION );
			wp_enqueue_script( 'olr-test-build-box', plugins_url( 'off-label-build-a-box/assets/build-a-box.js' ), array(), self::VERSION, true );
		}

		if ( 'cart-test' === $slug ) {
			foreach ( array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' ) as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
			foreach ( array( 'wc-cart', 'wc-cart-fragments' ) as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}
	}

	/**
	 * Keep test routes out of search engines.
	 *
	 * @param array<string,bool> $robots Existing directives.
	 * @return array<string,bool>
	 */
	public static function noindex_test_pages( $robots ) {
		if ( self::is_test_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Add stable test-page classes without affecting production pages.
	 *
	 * @param string[] $classes Existing body classes.
	 * @return string[]
	 */
	public static function body_classes( $classes ) {
		if ( self::is_test_page() ) {
			$classes = array_diff( (array) $classes, array( 'et_right_sidebar' ) );
			$classes[] = 'olr-isolated-test-page';
			$classes[] = 'olr-site-responsive';
			$classes[] = 'et_pb_pagebuilder_layout';
			$classes[] = 'et_no_sidebar';

			switch ( self::current_test_slug() ) {
				case 'account-test':
					$classes[] = 'olr-account-hub-page';
					break;
				case 'affiliate-test':
					$classes[] = 'olr-affiliate-landing-page';
					break;
				case 'affiliate-guidelines-test':
					$classes[] = 'olr-affiliate-guidelines-page';
					break;
				case 'build-your-box-test':
					$classes[] = 'olr-build-box-page';
					break;
				case 'receipt-test':
					$classes[] = 'olr-coa-detail-page';
					break;
				case 'cart-test':
					$classes[] = 'woocommerce';
					$classes[] = 'woocommerce-cart';
					$classes[] = 'woocommerce-page';
					break;
			}
		}
		return array_values( array_unique( $classes ) );
	}

	/** Return a context-complete public preview URL for dynamic pages. */
	private static function preview_url( $slug, $page ) {
		$url = get_permalink( $page );
		if ( 'research-item' === $slug ) {
			$product_id = self::preview_product_id( false );
			return $product_id ? add_query_arg( 'product_id', $product_id, $url ) : $url;
		}
		if ( 'receipt-test' === $slug ) {
			$product_id = self::preview_product_id( true );
			return $product_id ? add_query_arg( 'product_id', $product_id, $url ) : $url;
		}
		return $url;
	}

	/** Add the seeder status page under Tools. */
	public static function admin_menu() {
		add_management_page(
			__( 'Off Label Test Pages', 'off-label-test-page-seeder' ),
			__( 'Off Label Test Pages', 'off-label-test-page-seeder' ),
			'manage_options',
			'olr-test-pages',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Add a direct link from the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'tools.php?page=olr-test-pages' ) ) . '">' . esc_html__( 'Test Pages', 'off-label-test-page-seeder' ) . '</a>'
		);
		return $links;
	}

	/** Handle the nonce-protected repair action. */
	public static function handle_seed_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage test pages.', 'off-label-test-page-seeder' ) );
		}
		check_admin_referer( 'olr_seed_test_pages' );
		self::seed();
		wp_safe_redirect( add_query_arg( 'seeded', '1', admin_url( 'tools.php?page=olr-test-pages' ) ) );
		exit;
	}

	/** Render the page inventory and repair control. */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Off Label Test Pages', 'off-label-test-page-seeder' ); ?></h1>
			<p><?php esc_html_e( 'These published, noindex routes mirror the GitPress fragments planned for production without changing any live page.', 'off-label-test-page-seeder' ); ?></p>
			<?php if ( isset( $_GET['seeded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The test-page set was created or repaired.', 'off-label-test-page-seeder' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Page', 'off-label-test-page-seeder' ); ?></th><th><?php esc_html_e( 'Slug', 'off-label-test-page-seeder' ); ?></th><th><?php esc_html_e( 'Fragment', 'off-label-test-page-seeder' ); ?></th><th><?php esc_html_e( 'Status', 'off-label-test-page-seeder' ); ?></th><th><?php esc_html_e( 'Actions', 'off-label-test-page-seeder' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::pages() as $slug => $definition ) : ?>
					<?php $page = get_page_by_path( $slug, OBJECT, 'page' ); ?>
					<tr>
						<td><?php echo esc_html( $definition['title'] ); ?></td>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
						<td><code><?php echo esc_html( $definition['fragment'] ); ?></code></td>
						<td><?php echo $page ? esc_html( ucfirst( $page->post_status ) ) : esc_html__( 'Missing', 'off-label-test-page-seeder' ); ?></td>
						<td>
							<?php if ( $page ) : ?>
								<a href="<?php echo esc_url( self::preview_url( $slug, $page ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'off-label-test-page-seeder' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>"><?php esc_html_e( 'Edit', 'off-label-test-page-seeder' ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 20px;">
				<input type="hidden" name="action" value="olr_seed_test_pages">
				<?php wp_nonce_field( 'olr_seed_test_pages' ); ?>
				<?php submit_button( __( 'Create or repair test pages', 'off-label-test-page-seeder' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'OLR_Test_Page_Seeder', 'activate' ) );
add_action( 'plugins_loaded', array( 'OLR_Test_Page_Seeder', 'init' ) );
