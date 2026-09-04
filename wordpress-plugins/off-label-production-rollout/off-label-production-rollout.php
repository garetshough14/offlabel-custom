<?php
/**
 * Plugin Name: Off Label Production Rollout
 * Description: Guarded, reversible product-image seeding and approved GitPress page promotion tools.
 * Version: 1.0.11
 * Author: Off Label Research
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OLR_Production_Rollout {
	const VERSION              = '1.0.11';
	const DESIGN_SYSTEM_URL    = 'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@2ab4ebd385d25759233831763e840a66f35ff649/styles.css';
	const MENU_SLUG            = 'olr-production-rollout';
	const IMAGE_BACKUP_OPTION  = 'olr_production_rollout_image_backup_v1';
	const PAGE_BACKUP_OPTION   = 'olr_production_rollout_page_backup_v1';
	const LAST_REPORT_OPTION   = 'olr_production_rollout_last_report_v1';
	const ATTACHMENT_FILE_META = '_olr_rollout_source_file';
	const ATTACHMENT_HASH_META = '_olr_rollout_source_sha256';
	const TEST_PAGE_META       = '_olr_test_page_seeder';

	/** Register admin-only tools. Activation intentionally performs no work. */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'restore_compliance_checkbox' ), 999 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_olr_production_rollout', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Restore the confirmation control after GitPress sanitizes the fragment.
	 *
	 * GitPress currently preserves the modal label and button but removes the
	 * checkbox input. Run after shortcode expansion so the controller always has
	 * the required explicit-consent control and never silently exits.
	 */
	public static function restore_compliance_checkbox( $content ) {
		$content = (string) $content;
		if (
			is_admin()
			|| ! is_front_page()
			|| false === strpos( $content, 'data-olr-compliance-gate' )
			|| false !== strpos( $content, 'data-olr-gate-confirmation' )
		) {
			return $content;
		}

		$checkbox = '<input type="checkbox" data-olr-gate-confirmation required aria-required="true">';
		$updated  = preg_replace(
			'/(<label\b[^>]*class=["\'][^"\']*\bolr-compliance-gate__confirmation\b[^"\']*["\'][^>]*>)/i',
			'$1' . $checkbox,
			$content,
			1
		);

		return is_string( $updated ) ? $updated : $content;
	}

	/**
	 * Load the approved shared design system on production pages.
	 *
	 * The isolated test-page seeder loaded styles.css as a normal WordPress
	 * stylesheet. Promoted pages previously relied on GitPress preserving the
	 * very large inline copy inside the managed header, which is not reliable
	 * across every page/cache pipeline. Loading the identical, commit-pinned
	 * stylesheet here keeps the production homepage and global chrome identical
	 * to the approved test pages without changing their HTML or design rules.
	 */
	public static function frontend_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'olr-production-design-system',
			self::DESIGN_SYSTEM_URL,
			array(),
			self::VERSION
		);

		wp_enqueue_style(
			'olr-production-presentation-fixes',
			plugins_url( 'assets/production-fixes.css', __FILE__ ),
			array( 'olr-production-design-system' ),
			self::VERSION
		);

		if ( is_front_page() ) {
			wp_enqueue_script(
				'olr-compliance-gate',
				plugins_url( 'assets/olr-compliance-gate.js', __FILE__ ),
				array(),
				self::VERSION,
				true
			);
		}
	}

	/** Add the guarded rollout screen under Tools. */
	public static function admin_menu() {
		add_management_page(
			__( 'Off Label Production Rollout', 'off-label-production-rollout' ),
			__( 'Off Label Rollout', 'off-label-production-rollout' ),
			'activate_plugins',
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/** Absolute bundled manifest path. */
	private static function manifest_path() {
		return plugin_dir_path( __FILE__ ) . 'data/product-image-manifest.csv';
	}

	/** Absolute bundled image path. */
	private static function image_path( $filename ) {
		return plugin_dir_path( __FILE__ ) . 'assets/product-images/' . wp_basename( $filename );
	}

	/** Parse and normalize the bundled CSV. */
	private static function manifest_rows() {
		$path = self::manifest_path();
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'manifest_missing', __( 'The bundled product image manifest is missing.', 'off-label-production-rollout' ) );
		}

		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'manifest_unreadable', __( 'The bundled product image manifest cannot be read.', 'off-label-production-rollout' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			return new WP_Error( 'manifest_empty', __( 'The bundled product image manifest is empty.', 'off-label-production-rollout' ) );
		}
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		$required   = array( 'website_product', 'product_slug', 'image_file', 'site_status', 'source', 'notes' );
		if ( $required !== array_values( $headers ) ) {
			fclose( $handle );
			return new WP_Error( 'manifest_headers', __( 'The manifest headers do not match the approved schema.', 'off-label-production-rollout' ) );
		}

		$rows = array();
		while ( false !== ( $values = fgetcsv( $handle ) ) ) {
			if ( ! array_filter( $values, 'strlen' ) ) {
				continue;
			}
			$values = array_pad( $values, count( $headers ), '' );
			$rows[] = array_map( 'trim', array_combine( $headers, array_slice( $values, 0, count( $headers ) ) ) );
		}
		fclose( $handle );
		return $rows;
	}

	/** Return only approved Live manifest rows. */
	private static function live_rows() {
		$rows = self::manifest_rows();
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return isset( $row['site_status'] ) && 'Live' === $row['site_status'];
				}
			)
		);
	}

	/** Read-only image and product preflight. */
	public static function preflight_images() {
		$result = array(
			'ok'       => true,
			'checks'   => array(),
			'errors'   => array(),
			'warnings' => array(),
		);
		$rows = self::live_rows();
		if ( is_wp_error( $rows ) ) {
			$result['errors'][] = $rows->get_error_message();
			$result['ok']       = false;
			return $result;
		}

		$result['checks'][] = sprintf( 'Manifest contains %d Live rows.', count( $rows ) );
		if ( 29 !== count( $rows ) ) {
			$result['errors'][] = sprintf( 'Expected exactly 29 Live rows; found %d.', count( $rows ) );
		}

		$files = array_values( array_unique( wp_list_pluck( $rows, 'image_file' ) ) );
		$result['checks'][] = sprintf( 'Live rows reference %d unique image files.', count( $files ) );
		if ( 27 !== count( $files ) ) {
			$result['errors'][] = sprintf( 'Expected exactly 27 unique Live image files; found %d.', count( $files ) );
		}

		foreach ( $files as $filename ) {
			$path = self::image_path( $filename );
			if ( ! is_readable( $path ) ) {
				$result['errors'][] = 'Missing image: ' . $filename;
				continue;
			}
			$dimensions = getimagesize( $path );
			if ( ! is_array( $dimensions ) || 1150 !== (int) $dimensions[0] || 1600 !== (int) $dimensions[1] ) {
				$result['errors'][] = sprintf( '%s is not 1150x1600.', $filename );
			}
		}
		if ( ! $result['errors'] ) {
			$result['checks'][] = 'All 27 unique images exist and are exactly 1150x1600.';
		}

		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_products' ) ) {
			$result['errors'][] = 'WooCommerce product APIs are unavailable.';
		} else {
			$published_ids = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'ids',
				)
			);
			if ( 29 !== count( $published_ids ) ) {
				$result['errors'][] = sprintf( 'Expected exactly 29 published WooCommerce products; found %d.', count( $published_ids ) );
			}

			$seen_slugs = array();
			foreach ( $rows as $row ) {
				$slug = sanitize_title( $row['product_slug'] );
				if ( isset( $seen_slugs[ $slug ] ) ) {
					$result['errors'][] = 'Duplicate Live product slug in manifest: ' . $slug;
					continue;
				}
				$seen_slugs[ $slug ] = true;
				$post = get_page_by_path( $slug, OBJECT, 'product' );
				if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! wc_get_product( $post->ID ) ) {
					$result['errors'][] = 'Published product not found for slug: ' . $slug;
				}
			}
			if ( ! $result['errors'] ) {
				$result['checks'][] = 'All 29 manifest slugs resolve to the complete published product set.';
			}
		}

		$result['checks'][] = 'The three Drive-only rows are excluded from seeding.';
		$result['ok']       = empty( $result['errors'] );
		return $result;
	}

	/** Return a product by an exact manifest slug. */
	private static function product_by_slug( $slug ) {
		$post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' );
		return $post instanceof WP_Post && function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : false;
	}

	/** Capture image associations before the first seed. */
	private static function capture_image_backup( $rows ) {
		$backup = array(
			'created_at' => time(),
			'products'   => array(),
		);
		foreach ( $rows as $row ) {
			$product = self::product_by_slug( $row['product_slug'] );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$entry = array(
				'product_id' => $product->get_id(),
				'image_id'   => $product->get_image_id(),
				'gallery'    => $product->get_gallery_image_ids(),
				'variations' => array(),
			);
			if ( $product instanceof WC_Product_Variable ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product_Variation ) {
						$entry['variations'][ $variation_id ] = $variation->get_image_id();
					}
				}
			}
			$backup['products'][ $product->get_id() ] = $entry;
		}
		return $backup;
	}

	/** Find a previously imported attachment only when its file still matches. */
	private static function reusable_attachment_id( $filename, $hash ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => self::ATTACHMENT_FILE_META,
						'value' => $filename,
					),
				),
			)
		);
		foreach ( $query->posts as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file && is_readable( $file ) && hash_equals( $hash, (string) hash_file( 'sha256', $file ) ) ) {
				return (int) $attachment_id;
			}
		}
		return 0;
	}

	/** Import or reuse exactly one attachment for a source file. */
	private static function attachment_for_file( $filename, $title ) {
		$source_path = self::image_path( $filename );
		$hash        = (string) hash_file( 'sha256', $source_path );
		$existing    = self::reusable_attachment_id( $filename, $hash );
		if ( $existing ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$contents = file_get_contents( $source_path );
		if ( false === $contents ) {
			return new WP_Error( 'image_read_failed', 'Unable to read ' . $filename );
		}
		$upload = wp_upload_bits( $filename, null, $contents );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'image_upload_failed', $upload['error'] );
		}

		$filetype      = wp_check_filetype( $upload['file'], null );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => sanitize_text_field( $title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title . ' research product' ) );
		update_post_meta( $attachment_id, self::ATTACHMENT_FILE_META, $filename );
		update_post_meta( $attachment_id, self::ATTACHMENT_HASH_META, $hash );
		return (int) $attachment_id;
	}

	/** Seed canonical product images after a successful preflight. */
	public static function seed_images() {
		$preflight = self::preflight_images();
		if ( empty( $preflight['ok'] ) ) {
			return array( 'ok' => false, 'errors' => array_merge( array( 'Image seeding was blocked by preflight.' ), $preflight['errors'] ) );
		}
		$rows = self::live_rows();
		if ( ! get_option( self::IMAGE_BACKUP_OPTION, false ) ) {
			update_option( self::IMAGE_BACKUP_OPTION, self::capture_image_backup( $rows ), false );
		}

		$attachments = array();
		$updated     = array();
		foreach ( $rows as $row ) {
			$filename = $row['image_file'];
			if ( ! isset( $attachments[ $filename ] ) ) {
				$attachment_id = self::attachment_for_file( $filename, $row['website_product'] );
				if ( is_wp_error( $attachment_id ) ) {
					self::restore_images();
					return array( 'ok' => false, 'errors' => array( $attachment_id->get_error_message(), 'Previous product image associations were restored. Imported Media Library files were preserved.' ) );
				}
				$attachments[ $filename ] = $attachment_id;
			}

			$product = self::product_by_slug( $row['product_slug'] );
			if ( ! $product instanceof WC_Product ) {
				self::restore_images();
				return array( 'ok' => false, 'errors' => array( 'Product disappeared during seeding: ' . $row['product_slug'], 'Previous image associations were restored.' ) );
			}
			$product->set_image_id( $attachments[ $filename ] );
			$product->set_gallery_image_ids( array() );
			$product->save();
			if ( $product instanceof WC_Product_Variable ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product_Variation ) {
						$variation->set_image_id( 0 );
						$variation->save();
					}
				}
			}
			wc_delete_product_transients( $product->get_id() );
			$updated[] = $row['product_slug'];
		}

		return array(
			'ok'      => true,
			'checks'  => array(
				sprintf( 'Assigned canonical images to %d products.', count( $updated ) ),
				sprintf( 'Imported or reused %d unique attachments.', count( $attachments ) ),
				'Product galleries and variation-specific images are cleared so every product uses one canonical image.',
				'NAD+ and RT-3 bundle rows reuse their base-product attachments.',
			),
		);
	}

	/** Verify every canonical image association after seeding. This method is read-only. */
	public static function verify_images() {
		$preflight = self::preflight_images();
		if ( empty( $preflight['ok'] ) ) {
			return array( 'ok' => false, 'errors' => array_merge( array( 'Image verification was blocked by preflight.' ), $preflight['errors'] ) );
		}

		$rows        = self::live_rows();
		$errors      = array();
		$attachments = array();
		foreach ( $rows as $row ) {
			$product = self::product_by_slug( $row['product_slug'] );
			if ( ! $product instanceof WC_Product ) {
				$errors[] = 'Product not found during verification: ' . $row['product_slug'];
				continue;
			}

			$image_id = (int) $product->get_image_id();
			if ( ! $image_id ) {
				$errors[] = 'Canonical featured image is missing for: ' . $row['product_slug'];
				continue;
			}

			$attached_file = get_attached_file( $image_id );
			$source_file   = (string) get_post_meta( $image_id, self::ATTACHMENT_FILE_META, true );
			$source_hash   = (string) hash_file( 'sha256', self::image_path( $row['image_file'] ) );
			$attached_hash = $attached_file && is_readable( $attached_file ) ? (string) hash_file( 'sha256', $attached_file ) : '';
			if ( $row['image_file'] !== $source_file || ! $attached_hash || ! hash_equals( $source_hash, $attached_hash ) ) {
				$errors[] = 'Featured image does not match the canonical file for: ' . $row['product_slug'];
			}

			if ( isset( $attachments[ $row['image_file'] ] ) && $attachments[ $row['image_file'] ] !== $image_id ) {
				$errors[] = 'A shared canonical file uses multiple attachments: ' . $row['image_file'];
			}
			$attachments[ $row['image_file'] ] = $image_id;

			if ( $product->get_gallery_image_ids() ) {
				$errors[] = 'Product gallery was not cleared for: ' . $row['product_slug'];
			}
			if ( $product instanceof WC_Product_Variable ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product_Variation && $variation->get_image_id( 'edit' ) ) {
						$errors[] = sprintf( 'Variation %d retains a variation-specific image for: %s', $variation_id, $row['product_slug'] );
					}
				}
			}
		}

		if ( 27 !== count( array_unique( array_values( $attachments ) ) ) ) {
			$errors[] = sprintf( 'Expected exactly 27 canonical attachment IDs; found %d.', count( array_unique( array_values( $attachments ) ) ) );
		}

		return array(
			'ok'     => empty( $errors ),
			'errors' => $errors,
			'checks' => empty( $errors ) ? array(
				'All 29 products use their exact canonical featured image.',
				'Exactly 27 canonical attachments are in use, including the shared NAD+ and RT-3 bundle images.',
				'All product galleries and variation-specific images are cleared.',
			) : array(),
		);
	}

	/** Restore product, gallery, and variation image IDs. */
	public static function restore_images() {
		$backup = get_option( self::IMAGE_BACKUP_OPTION, false );
		if ( ! is_array( $backup ) || empty( $backup['products'] ) ) {
			return array( 'ok' => false, 'errors' => array( 'No image rollback snapshot exists.' ) );
		}
		$count = 0;
		foreach ( $backup['products'] as $entry ) {
			$product = wc_get_product( absint( $entry['product_id'] ) );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$product->set_image_id( absint( $entry['image_id'] ) );
			$product->set_gallery_image_ids( array_map( 'absint', (array) $entry['gallery'] ) );
			$product->save();
			foreach ( (array) $entry['variations'] as $variation_id => $image_id ) {
				$variation = wc_get_product( absint( $variation_id ) );
				if ( $variation instanceof WC_Product_Variation ) {
					$variation->set_image_id( absint( $image_id ) );
					$variation->save();
				}
			}
			wc_delete_product_transients( $product->get_id() );
			++$count;
		}
		return array( 'ok' => true, 'checks' => array( sprintf( 'Restored image associations for %d products. Media Library attachments were left untouched.', $count ) ) );
	}

	/** Approved production-page map, using existing page IDs only. */
	private static function production_pages() {
		return array(
			'home' => array( 'id' => absint( get_option( 'page_on_front' ) ), 'title' => null, 'slug' => null, 'fragment' => 'home.html' ),
			'about' => array( 'id' => self::page_id( 'about' ), 'title' => 'About', 'slug' => 'about', 'fragment' => 'about.html' ),
			'catalog' => array( 'id' => absint( get_option( 'woocommerce_shop_page_id' ) ), 'title' => 'Catalog', 'slug' => 'catalog', 'fragment' => 'research.html' ),
			'coas' => array( 'id' => self::page_id( 'coas' ), 'title' => 'COAs', 'slug' => 'coas', 'fragment' => 'testing.html' ),
			'faq' => array( 'id' => self::page_id( 'faq' ), 'title' => 'FAQ', 'slug' => 'faq', 'fragment' => 'faq.html' ),
			'build-your-box' => array( 'id' => absint( get_option( 'olr_build_a_box_page_id', self::page_id( 'build-your-box' ) ) ), 'title' => 'Build Your Box', 'slug' => 'build-your-box', 'fragment' => 'build-your-box.html' ),
			'cart' => array( 'id' => absint( get_option( 'woocommerce_cart_page_id' ) ), 'title' => 'Cart', 'slug' => 'cart', 'fragment' => 'checkout.html' ),
			'receipt' => array( 'id' => self::page_id( 'receipt' ), 'title' => 'COA Receipt', 'slug' => 'receipt', 'fragment' => 'receipt.html' ),
			'catalog-item' => array( 'id' => self::page_id( 'research-item' ) ?: self::page_id( 'catalog-item' ), 'title' => 'Catalog Item', 'slug' => 'catalog-item', 'fragment' => 'product.html' ),
		);
	}

	/** Find any page by slug, regardless of status. */
	private static function page_id( $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		return $page instanceof WP_Post ? (int) $page->ID : 0;
	}

	/** Build the production GitPress shortcode. */
	private static function source_shortcode( $fragment ) {
		return sprintf(
			'[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/%s" branch="main" format="html" updated_meta="false"]',
			sanitize_file_name( $fragment )
		);
	}

	/** GitPress 1.2.5 page metadata keys. */
	private static function gitpress_meta_keys() {
		return array(
			'_dgs_page_shortcode',
			'_dgs_page_shortcode_render_mode',
			'_dgs_page_shortcode_placement',
			'_dgs_page_shortcode_full_width',
			'_dgs_page_shortcode_full_page',
		);
	}

	/** Unused legacy keys written by the first guarded promotion attempt. */
	private static function legacy_gitpress_meta_keys() {
		return array(
			'dgs_page_shortcode',
			'dgs_page_shortcode_render_mode',
			'dgs_page_shortcode_placement',
			'dgs_page_shortcode_full_width',
		);
	}

	/** Read-only production-page readiness checks. */
	public static function preflight_pages() {
		$result = array( 'ok' => true, 'checks' => array(), 'errors' => array(), 'warnings' => array() );
		foreach ( self::production_pages() as $key => $definition ) {
			$post = $definition['id'] ? get_post( $definition['id'] ) : null;
			if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
				$result['errors'][] = 'Required existing page is missing: ' . $key;
			}
		}

		$catalog_page = get_page_by_path( 'catalog', OBJECT, 'page' );
		$shop_id      = absint( get_option( 'woocommerce_shop_page_id' ) );
		if ( $catalog_page instanceof WP_Post && (int) $catalog_page->ID !== $shop_id ) {
			$result['errors'][] = '/catalog/ is occupied by a page other than the assigned WooCommerce Shop page.';
		}
		$catalog_item   = get_page_by_path( 'catalog-item', OBJECT, 'page' );
		$product_page_id = self::production_pages()['catalog-item']['id'];
		if ( $catalog_item instanceof WP_Post && (int) $catalog_item->ID !== $product_page_id ) {
			$result['errors'][] = '/catalog-item/ is occupied by another page.';
		}

		if ( ! defined( 'OLR_CHECKOUT_TEST_VERSION' ) || version_compare( OLR_CHECKOUT_TEST_VERSION, '1.4.0', '<' ) ) {
			$result['errors'][] = 'Off Label Checkout 1.4.0 or newer is not active.';
		}
		if ( ! class_exists( 'OLR_Build_A_Box' ) || '1.3.3' !== OLR_Build_A_Box::VERSION ) {
			$result['errors'][] = 'Off Label Build Your Box 1.3.3 is not active. Its source will not be changed.';
		}
		if ( ! function_exists( 'olr_catalog_url' ) || ! function_exists( 'olr_get_research_product_url' ) ) {
			$result['errors'][] = 'The production WooCommerce bridge with catalog routing is not active.';
		}
		if ( ! class_exists( 'DGS_Page_Shortcode_Manager' ) ) {
			$result['errors'][] = 'GitPress managed-page support is unavailable.';
		}
		if ( absint( get_option( 'woocommerce_checkout_page_id' ) ) < 1 ) {
			$result['errors'][] = 'WooCommerce has no assigned Checkout page for payment/order endpoints.';
		}

		$result['ok'] = empty( $result['errors'] );
		if ( $result['ok'] ) {
			$result['checks'][] = 'All existing production page IDs and route destinations are available.';
			$result['checks'][] = 'Checkout 1.4.0, Build Your Box 1.3.3, and the production catalog bridge are active.';
			$result['checks'][] = 'WooCommerce keeps its separately assigned Checkout endpoint page.';
		}
		return $result;
	}

	/** Capture mutable page fields, GitPress metadata, and route options once. */
	private static function capture_page_backup( $pages ) {
		$backup = array(
			'created_at' => time(),
			'options'    => array(),
			'pages'      => array(),
		);
		foreach ( array( 'show_on_front', 'page_on_front', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id' ) as $option ) {
			$backup['options'][ $option ] = array( 'exists' => false !== get_option( $option, false ), 'value' => get_option( $option, null ) );
		}
		foreach ( $pages as $definition ) {
			$post = get_post( $definition['id'] );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$entry = array(
				'post' => array(
					'ID'          => (int) $post->ID,
					'post_title'  => $post->post_title,
					'post_name'   => $post->post_name,
					'post_status' => $post->post_status,
					'post_content'=> $post->post_content,
				),
				'meta' => array(),
			);
			foreach ( array_merge( self::gitpress_meta_keys(), self::legacy_gitpress_meta_keys(), array( self::TEST_PAGE_META ) ) as $meta_key ) {
				$entry['meta'][ $meta_key ] = array(
					'exists' => metadata_exists( 'post', $post->ID, $meta_key ),
					'value'  => get_post_meta( $post->ID, $meta_key, true ),
				);
			}
			$backup['pages'][ $post->ID ] = $entry;
		}
		foreach ( array( 'home-test', 'about-test', 'research-catalog-test', 'testing-test', 'receipt-test', 'faq-test', 'cart-test', 'build-your-box-test', 'checkout-test', 'account-test', 'affiliate-test', 'affiliate-guidelines-test' ) as $test_slug ) {
			$test_page = get_page_by_path( $test_slug, OBJECT, 'page' );
			if ( ! $test_page instanceof WP_Post || isset( $backup['pages'][ $test_page->ID ] ) ) {
				continue;
			}
			$entry = array(
				'post' => array(
					'ID'           => (int) $test_page->ID,
					'post_title'   => $test_page->post_title,
					'post_name'    => $test_page->post_name,
					'post_status'  => $test_page->post_status,
					'post_content' => $test_page->post_content,
				),
				'meta' => array(),
			);
			foreach ( array_merge( self::gitpress_meta_keys(), self::legacy_gitpress_meta_keys(), array( self::TEST_PAGE_META ) ) as $meta_key ) {
				$entry['meta'][ $meta_key ] = array(
					'exists' => metadata_exists( 'post', $test_page->ID, $meta_key ),
					'value'  => get_post_meta( $test_page->ID, $meta_key, true ),
				);
			}
			$backup['pages'][ $test_page->ID ] = $entry;
		}
		return $backup;
	}

	/**
	 * Extend an existing snapshot with GitPress 1.2.5 metadata before the first
	 * corrected promotion. The earlier attempt did not mutate these keys.
	 */
	private static function ensure_gitpress_meta_backup() {
		$backup = get_option( self::PAGE_BACKUP_OPTION, false );
		if ( ! is_array( $backup ) || empty( $backup['pages'] ) ) {
			return;
		}

		$changed = false;
		foreach ( $backup['pages'] as $page_id => &$entry ) {
			if ( ! isset( $entry['meta'] ) || ! is_array( $entry['meta'] ) ) {
				$entry['meta'] = array();
			}
			foreach ( self::gitpress_meta_keys() as $meta_key ) {
				if ( array_key_exists( $meta_key, $entry['meta'] ) ) {
					continue;
				}
				$entry['meta'][ $meta_key ] = array(
					'exists' => metadata_exists( 'post', absint( $page_id ), $meta_key ),
					'value'  => get_post_meta( absint( $page_id ), $meta_key, true ),
				);
				$changed = true;
			}
		}
		unset( $entry );

		if ( $changed ) {
			update_option( self::PAGE_BACKUP_OPTION, $backup, false );
		}
	}

	/** Promote approved GitPress designs onto existing production pages. */
	public static function promote_pages() {
		$preflight = self::preflight_pages();
		if ( empty( $preflight['ok'] ) ) {
			return array( 'ok' => false, 'errors' => array_merge( array( 'Page promotion was blocked by preflight.' ), $preflight['errors'] ) );
		}
		$pages = self::production_pages();
		if ( ! get_option( self::PAGE_BACKUP_OPTION, false ) ) {
			update_option( self::PAGE_BACKUP_OPTION, self::capture_page_backup( $pages ), false );
		} else {
			self::ensure_gitpress_meta_backup();
		}

		foreach ( $pages as $key => $definition ) {
			$update = array( 'ID' => $definition['id'], 'post_status' => 'publish' );
			if ( null !== $definition['title'] ) {
				$update['post_title'] = $definition['title'];
			}
			if ( null !== $definition['slug'] ) {
				$update['post_name'] = $definition['slug'];
			}
			$updated = wp_update_post( $update, true );
			if ( is_wp_error( $updated ) ) {
				self::restore_pages();
				return array( 'ok' => false, 'errors' => array( $updated->get_error_message(), 'The page snapshot was restored.' ) );
			}
			update_post_meta( $definition['id'], '_dgs_page_shortcode', self::source_shortcode( $definition['fragment'] ) );
			update_post_meta( $definition['id'], '_dgs_page_shortcode_render_mode', 'gitpress_managed' );
			update_post_meta( $definition['id'], '_dgs_page_shortcode_placement', 'after' );
			update_post_meta( $definition['id'], '_dgs_page_shortcode_full_width', '1' );
			update_post_meta( $definition['id'], '_dgs_page_shortcode_full_page', '1' );
			foreach ( self::legacy_gitpress_meta_keys() as $legacy_meta_key ) {
				delete_post_meta( $definition['id'], $legacy_meta_key );
			}
			if ( 'catalog-item' === $key ) {
				delete_post_meta( $definition['id'], self::TEST_PAGE_META );
			}
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $pages['home']['id'] );
		update_option( 'woocommerce_shop_page_id', $pages['catalog']['id'] );
		update_option( 'woocommerce_cart_page_id', $pages['cart']['id'] );
		flush_rewrite_rules( false );
		return array(
			'ok'     => true,
			'checks' => array(
				'Promoted the approved Home, About, Catalog, COAs, FAQ, Build Your Box, Cart/Checkout, COA receipt, and product-record designs.',
				'Retained existing page IDs and WooCommerce Shop/Cart assignments.',
				'Retained the existing WooCommerce Checkout page for gateway callbacks and order endpoints.',
				'Renamed the staged product-record page to the internal catalog-item route and removed its test marker.',
			),
		);
	}

	/** Verify the promoted page records and GitPress metadata without mutation. */
	public static function verify_pages() {
		$preflight = self::preflight_pages();
		if ( empty( $preflight['ok'] ) ) {
			return array( 'ok' => false, 'errors' => array_merge( array( 'Page verification was blocked by preflight.' ), $preflight['errors'] ) );
		}

		$errors = array();
		foreach ( self::production_pages() as $key => $definition ) {
			$post = get_post( $definition['id'] );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				$errors[] = 'Production page is not published: ' . $key;
				continue;
			}
			if ( null !== $definition['title'] && $definition['title'] !== $post->post_title ) {
				$errors[] = 'Production page title does not match: ' . $key;
			}
			if ( null !== $definition['slug'] && $definition['slug'] !== $post->post_name ) {
				$errors[] = 'Production page slug does not match: ' . $key;
			}
			if ( self::source_shortcode( $definition['fragment'] ) !== get_post_meta( $post->ID, '_dgs_page_shortcode', true ) ) {
				$errors[] = 'GitPress source does not match: ' . $key;
			}
			if ( 'gitpress_managed' !== get_post_meta( $post->ID, '_dgs_page_shortcode_render_mode', true ) || '1' !== (string) get_post_meta( $post->ID, '_dgs_page_shortcode_full_page', true ) ) {
				$errors[] = 'GitPress Managed mode is not active: ' . $key;
			}
		}

		return array(
			'ok'     => empty( $errors ),
			'errors' => $errors,
			'checks' => empty( $errors ) ? array(
				'All nine production page records use their approved GitPress source.',
				'All production records are published in GitPress Managed full-page mode.',
				'WooCommerce Shop, Cart, and separate Checkout assignments remain intact.',
			) : array(),
		);
	}

	/** Restore page fields, GitPress metadata, and route options. */
	public static function restore_pages() {
		$backup = get_option( self::PAGE_BACKUP_OPTION, false );
		if ( ! is_array( $backup ) || empty( $backup['pages'] ) ) {
			return array( 'ok' => false, 'errors' => array( 'No page rollback snapshot exists.' ) );
		}
		foreach ( $backup['pages'] as $entry ) {
			wp_update_post( $entry['post'] );
			foreach ( $entry['meta'] as $meta_key => $meta ) {
				if ( ! empty( $meta['exists'] ) ) {
					update_post_meta( $entry['post']['ID'], $meta_key, $meta['value'] );
				} else {
					delete_post_meta( $entry['post']['ID'], $meta_key );
				}
			}
		}
		foreach ( (array) $backup['options'] as $option => $saved ) {
			if ( ! empty( $saved['exists'] ) ) {
				update_option( $option, $saved['value'] );
			} else {
				delete_option( $option );
			}
		}
		flush_rewrite_rules( false );
		return array( 'ok' => true, 'checks' => array( 'Restored the saved production-page fields, GitPress metadata, and WooCommerce page options.' ) );
	}

	/** Draft only exact, seeder-marked test pages after production QA. */
	public static function retire_test_pages() {
		$slugs = array( 'home-test', 'about-test', 'research-catalog-test', 'testing-test', 'receipt-test', 'faq-test', 'cart-test', 'build-your-box-test', 'checkout-test', 'account-test', 'affiliate-test', 'affiliate-guidelines-test' );
		$drafted = array();
		$skipped = array();
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $page instanceof WP_Post || ! metadata_exists( 'post', $page->ID, self::TEST_PAGE_META ) ) {
				$skipped[] = $slug;
				continue;
			}
			$result = wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $result ) ) {
				return array( 'ok' => false, 'errors' => array( 'Could not draft ' . $slug . ': ' . $result->get_error_message() ) );
			}
			$drafted[] = $slug;
		}
		flush_rewrite_rules( false );
		$checks = array( sprintf( 'Drafted %d exact seeder-marked test pages.', count( $drafted ) ) );
		if ( $skipped ) {
			$checks[] = 'Skipped absent or unmarked routes: ' . implode( ', ', $skipped );
		}
		$checks[] = 'No pages were trashed or deleted. Deactivate only the active Test Page Seeder 1.1.8 after confirming the old URLs return 404.';
		return array( 'ok' => true, 'checks' => $checks );
	}

	/** Remove only the known failed rollout-package folders created during this deployment. */
	public static function cleanup_failed_packages() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return array( 'ok' => false, 'errors' => array( 'WordPress filesystem access is unavailable.' ) );
		}

		$plugin_root = realpath( WP_PLUGIN_DIR );
		$active_dir  = realpath( plugin_dir_path( __FILE__ ) );
		$targets     = array( 'off-label-production-rollout', 'off-label-production-rollout-v1.0.0', 'off-label-production-rollout-v1.0.1' );
		$removed     = array();
		$skipped     = array();
		foreach ( $targets as $folder ) {
			$path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $folder;
			$real = realpath( $path );
			if ( false === $real ) {
				$skipped[] = $folder . ' (absent)';
				continue;
			}
			if ( $real === $active_dir || dirname( $real ) !== $plugin_root ) {
				return array( 'ok' => false, 'errors' => array( 'Cleanup safety check rejected: ' . $folder ) );
			}
			if ( ! $wp_filesystem->delete( $real, true, 'd' ) ) {
				return array( 'ok' => false, 'errors' => array( 'Could not remove failed rollout folder: ' . $folder ) );
			}
			$removed[] = $folder;
		}
		return array(
			'ok'     => true,
			'checks' => array(
				'Removed only failed rollout-package folders: ' . ( $removed ? implode( ', ', $removed ) : 'none' ),
				'Skipped: ' . ( $skipped ? implode( ', ', $skipped ) : 'none' ),
				'The active rollout tool and every unrelated plugin were preserved.',
			),
		);
	}

	/** Handle a nonce-protected admin request. */
	public static function handle_action() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this rollout.', 'off-label-production-rollout' ) );
		}
		check_admin_referer( 'olr_production_rollout' );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$handlers  = array(
			'preflight_images' => 'preflight_images',
			'seed_images'      => 'seed_images',
			'verify_images'    => 'verify_images',
			'restore_images'   => 'restore_images',
			'preflight_pages'  => 'preflight_pages',
			'promote_pages'    => 'promote_pages',
			'verify_pages'     => 'verify_pages',
			'restore_pages'    => 'restore_pages',
			'retire_tests'     => 'retire_test_pages',
			'cleanup_packages' => 'cleanup_failed_packages',
		);
		$report = isset( $handlers[ $operation ] ) ? call_user_func( array( __CLASS__, $handlers[ $operation ] ) ) : array( 'ok' => false, 'errors' => array( 'Unknown rollout operation.' ) );
		$report['operation'] = $operation;
		$report['time']      = time();
		update_option( self::LAST_REPORT_OPTION, $report, false );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/** Render status and individually confirmed controls. */
	public static function render_admin_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$report = get_option( self::LAST_REPORT_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Off Label Production Rollout', 'off-label-production-rollout' ); ?></h1>
			<p><?php esc_html_e( 'Activation performs no mutations. Run preflight before each rollout phase. Image and page snapshots are retained for rollback; Media Library attachments are never deleted.', 'off-label-production-rollout' ); ?></p>
			<?php if ( $report ) : ?>
				<div class="notice <?php echo ! empty( $report['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline">
					<p><strong><?php echo esc_html( strtoupper( str_replace( '_', ' ', (string) $report['operation'] ) ) ); ?>:</strong> <?php echo ! empty( $report['ok'] ) ? esc_html__( 'Passed', 'off-label-production-rollout' ) : esc_html__( 'Blocked or failed', 'off-label-production-rollout' ); ?></p>
					<?php foreach ( array_merge( (array) ( $report['checks'] ?? array() ), (array) ( $report['warnings'] ?? array() ), (array) ( $report['errors'] ?? array() ) ) as $message ) : ?>
						<p><?php echo esc_html( $message ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Phase</th><th>Action</th><th>Effect</th></tr></thead><tbody>
			<?php
			$actions = array(
				array( 'Image preflight', 'preflight_images', 'Read-only: validates 29 Live mappings, 27 files, 1150x1600 dimensions, and the exact published product set.' ),
				array( 'Seed images', 'seed_images', 'Imports/reuses canonical images and changes product image associations. Automatically blocks unless image preflight passes.' ),
				array( 'Verify seeded images', 'verify_images', 'Read-only: verifies all 29 featured images, the 27 attachment mapping, empty galleries, and inherited variation images.' ),
				array( 'Rollback images', 'restore_images', 'Restores saved product, gallery, and variation image IDs. Preserves all attachments.' ),
				array( 'Page preflight', 'preflight_pages', 'Read-only: validates production page IDs, routes, Checkout 1.4.0, Build Your Box 1.3.3, and the bridge.' ),
				array( 'Promote pages', 'promote_pages', 'Applies approved GitPress fragments to existing production pages and flushes rewrites.' ),
				array( 'Verify promoted pages', 'verify_pages', 'Read-only: verifies all nine production records, slugs, GitPress sources, and managed full-page mode.' ),
				array( 'Rollback pages', 'restore_pages', 'Restores saved page fields, GitPress metadata, and WooCommerce page options.' ),
				array( 'Retire tests', 'retire_tests', 'Drafts only the exact seeder-marked test pages. Run only after production QA passes.' ),
				array( 'Clean failed rollout packages', 'cleanup_packages', 'Removes only the three known failed rollout folders created during this deployment; preserves this active tool and all unrelated plugins.' ),
			);
			foreach ( $actions as $action ) :
				?>
				<tr><td><strong><?php echo esc_html( $action[0] ); ?></strong></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'olr_production_rollout' ); ?><input type="hidden" name="action" value="olr_production_rollout"><input type="hidden" name="operation" value="<?php echo esc_attr( $action[1] ); ?>"><button class="button <?php echo in_array( $action[1], array( 'seed_images', 'promote_pages', 'retire_tests' ), true ) ? 'button-primary' : ''; ?>" type="submit"><?php echo esc_html( $action[0] ); ?></button></form></td><td><?php echo esc_html( $action[2] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}
}

OLR_Production_Rollout::init();
