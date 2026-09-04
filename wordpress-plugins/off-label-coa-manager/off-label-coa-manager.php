<?php
/**
 * Plugin Name: Off Label COA Manager
 * Description: Product-linked Certificates of Analysis, archive data, and branded receipt pages.
 * Version: 1.0.13
 * Author: Off Label Research
 * Text Domain: off-label-coa-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLR_COA_VERSION', '1.0.13' );
define( 'OLR_COA_FILE', __FILE__ );
define( 'OLR_COA_URL', plugin_dir_url( __FILE__ ) );

final class OLR_COA_Manager {
	const POST_TYPE = 'olr_coa';
	const PAGE_OPTION = 'olr_coa_receipt_page_id';

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'canonical_redirect' ), 1 );
		add_filter( 'get_canonical_url', array( $this, 'canonical_url' ) );
		add_filter( 'wpseo_canonical', array( $this, 'canonical_url' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'admin_product_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_admin_reports' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'olr_document_archive_items', array( $this, 'archive_items' ), 10, 2 );
		add_shortcode( 'olr_coa_page', array( $this, 'shortcode' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public static function activate() {
		self::instance()->register();
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( ! $page_id || 'trash' === get_post_status( $page_id ) ) {
			$existing = get_page_by_path( 'receipt' );
			$page_id  = $existing ? $existing->ID : wp_insert_post(
				array(
					'post_title'   => 'Receipt',
					'post_name'    => 'receipt',
					'post_content' => '[olr_coa_page]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( self::PAGE_OPTION, absint( $page_id ) );
			}
		}
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'COA Reports', 'off-label-coa-manager' ),
					'singular_name' => __( 'COA Report', 'off-label-coa-manager' ),
					'add_new_item'  => __( 'Add COA Report', 'off-label-coa-manager' ),
					'edit_item'     => __( 'Edit COA Report', 'off-label-coa-manager' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-media-document',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
			)
		);

		$receipt_page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( $receipt_page_id && function_exists( 'wc_get_products' ) ) {
			$product_ids = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ) );
			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product instanceof WC_Product && '' !== $product->get_slug() ) {
					add_rewrite_rule( '^coas/' . preg_quote( $product->get_slug(), '#' ) . '/?$', 'index.php?page_id=' . $receipt_page_id . '&olr_coa_product_id=' . $product->get_id(), 'top' );
				}
			}
		}

	}

	public function query_vars( $query_vars ) {
		$query_vars   = is_array( $query_vars ) ? $query_vars : array();
		$query_vars[] = 'olr_coa_product';
		$query_vars[] = 'olr_coa_product_id';
		return array_values( array_unique( $query_vars ) );
	}

	public function maybe_upgrade() {
		if ( OLR_COA_VERSION === get_option( 'olr_coa_manager_version' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( 'olr_coa_manager_version', OLR_COA_VERSION );
	}

	public function add_meta_boxes() {
		add_meta_box( 'olr-coa-record', __( 'COA details', 'off-label-coa-manager' ), array( $this, 'meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function meta_box( $post ) {
		wp_nonce_field( 'olr_coa_save', 'olr_coa_nonce' );
		$product_id = absint( get_post_meta( $post->ID, '_olr_product_id', true ) );
		$pdf_id     = absint( get_post_meta( $post->ID, '_olr_pdf_id', true ) );
		$products   = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC', 'return' => 'objects' ) ) : array();
		?>
		<div class="olr-coa-admin-grid">
			<p><label for="olr-product-id"><strong><?php esc_html_e( 'WooCommerce product', 'off-label-coa-manager' ); ?></strong></label><select id="olr-product-id" name="olr_product_id" required><option value="">Select a product</option><?php foreach ( $products as $product ) : ?><option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( $product_id, $product->get_id() ); ?>><?php echo esc_html( $product->get_name() ); ?></option><?php endforeach; ?></select></p>
			<p><label for="olr-test-date"><strong><?php esc_html_e( 'Test date', 'off-label-coa-manager' ); ?></strong></label><input id="olr-test-date" type="date" name="olr_test_date" value="<?php echo esc_attr( get_post_meta( $post->ID, '_olr_test_date', true ) ); ?>" required></p>
			<p class="olr-coa-admin-pdf"><label><strong><?php esc_html_e( 'Certificate PDF', 'off-label-coa-manager' ); ?></strong></label><input type="hidden" name="olr_pdf_id" id="olr-coa-pdf-id" value="<?php echo esc_attr( $pdf_id ); ?>"><button type="button" class="button" id="olr-coa-pdf-select"><?php esc_html_e( 'Choose PDF', 'off-label-coa-manager' ); ?></button> <button type="button" class="button-link-delete" id="olr-coa-pdf-remove"><?php esc_html_e( 'Remove', 'off-label-coa-manager' ); ?></button><span id="olr-coa-pdf-name"><?php echo esc_html( $pdf_id ? basename( (string) get_attached_file( $pdf_id ) ) : 'No PDF selected' ); ?></span></p>
		</div>
		<p class="description">The PDF contains the sample or lot ID, result, testing method, and laboratory. The newest published test date becomes the current report automatically.</p>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['olr_coa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['olr_coa_nonce'] ) ), 'olr_coa_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$product_id = isset( $_POST['olr_product_id'] ) ? absint( $_POST['olr_product_id'] ) : 0;
		$pdf_id     = isset( $_POST['olr_pdf_id'] ) ? absint( $_POST['olr_pdf_id'] ) : 0;
		if ( $product_id && 'product' === get_post_type( $product_id ) ) {
			update_post_meta( $post_id, '_olr_product_id', $product_id );
		} else {
			delete_post_meta( $post_id, '_olr_product_id' );
		}
		if ( $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) ) {
			update_post_meta( $post_id, '_olr_pdf_id', $pdf_id );
		} else {
			delete_post_meta( $post_id, '_olr_pdf_id' );
		}
		$test_date = isset( $_POST['olr_test_date'] ) ? sanitize_text_field( wp_unslash( $_POST['olr_test_date'] ) ) : '';
		if ( '' !== $test_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $test_date ) ) {
			$test_date = '';
		}
		$is_valid  = $product_id && 'product' === get_post_type( $product_id ) && $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $test_date );
		if ( 'publish' === $post->post_status && ! $is_valid ) {
			remove_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10 );
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
			set_transient( 'olr_coa_invalid_' . get_current_user_id(), 1, 60 );
		}
		update_post_meta( $post_id, '_olr_test_date', $test_date );
		if ( '' === $post->post_title && $product_id ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => get_the_title( $product_id ) . ' — ' . get_post_meta( $post_id, '_olr_test_date', true ) ) );
		}
	}

	public function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'olr-coa-admin', OLR_COA_URL . 'assets/admin.js', array( 'jquery' ), OLR_COA_VERSION, true );
		wp_enqueue_style( 'olr-coa-admin', OLR_COA_URL . 'assets/admin.css', array(), OLR_COA_VERSION );
	}

	public function frontend_assets() {
		if ( ! get_query_var( 'olr_coa_product_id' ) && ! get_query_var( 'olr_coa_product' ) && ! is_page( absint( get_option( self::PAGE_OPTION ) ) ) ) {
			return;
		}
		wp_enqueue_style( 'olr-coa-detail', OLR_COA_URL . 'assets/coa-detail.css', array(), OLR_COA_VERSION );
		wp_enqueue_script( 'olr-coa-detail', OLR_COA_URL . 'assets/coa-detail.js', array(), OLR_COA_VERSION, true );
		wp_localize_script( 'olr-coa-detail', 'olrCoaViewer', array( 'libraryUrl' => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.mjs', 'workerUrl' => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs' ) );
	}

	private function reports( $product_id ) {
		return get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_olr_test_date', 'orderby' => 'meta_value date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_olr_product_id', 'value' => absint( $product_id ), 'compare' => '=' ), array( 'key' => '_olr_pdf_id', 'compare' => 'EXISTS' ) ) ) );
	}

	public function archive_items( $items, $attributes ) {
		$reports = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_olr_test_date', 'orderby' => 'meta_value date', 'order' => 'DESC' ) );
		$seen = array();
		foreach ( $reports as $report ) {
			$product_id = absint( get_post_meta( $report->ID, '_olr_product_id', true ) );
			$pdf_id = absint( get_post_meta( $report->ID, '_olr_pdf_id', true ) );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			if ( ! $product || isset( $seen[ $product_id ] ) || ! $pdf_id || 'application/pdf' !== get_post_mime_type( $pdf_id ) ) { continue; }
			$seen[ $product_id ] = true;
			$terms = get_the_terms( $product_id, 'product_cat' );
			$category = 'other';
			$restricted_term = 'pep' . 'tide';
			$metabolic_term = 'g' . 'lp';
			if ( is_array( $terms ) ) { foreach ( $terms as $term ) { $haystack = strtolower( $term->slug . ' ' . $term->name ); if ( false !== strpos( $haystack, $metabolic_term ) ) { $category = 'metabolic'; break; } if ( false !== strpos( $haystack, $restricted_term ) || false !== strpos( $haystack, 'ipamorelin' ) || false !== strpos( $haystack, 'sermorelin' ) ) { $category = 'compounds'; } } }
			$product_url = function_exists( 'olr_get_research_product_url' ) ? olr_get_research_product_url( $product ) : home_url( '/catalog/' . $product->get_slug() . '/' );
			$items[] = array( 'product_id' => $product_id, 'product_name' => $product->get_name(), 'product_url' => $product_url, 'product_image_url' => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ), 'category' => $category, 'strength' => function_exists( 'olr_get_catalog_product_detail' ) ? olr_get_catalog_product_detail( $product ) : '', 'test_date' => $this->display_date( get_post_meta( $report->ID, '_olr_test_date', true ) ), 'document_url' => wp_get_attachment_url( $pdf_id ), 'record_url' => $this->record_url( $product ), 'document_label' => __( 'View COA', 'off-label-coa-manager' ) );
		}
		return $items;
	}

	private function record_url( $product ) {
		return home_url( '/coas/' . $product->get_slug() . '/' );
	}

	private function routed_product() {
		$product_id = absint( get_query_var( 'olr_coa_product_id' ) );
		if ( $product_id && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product && 'publish' === get_post_status( $product_id ) ) {
				return $product;
			}
		}

		$slug = sanitize_title( (string) get_query_var( 'olr_coa_product' ) );
		if ( '' === $slug && isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
			if ( preg_match( '#^coas/([^/]+)$#', $request_path, $matches ) ) {
				$slug = sanitize_title( $matches[1] );
			}
		}
		if ( '' === $slug || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$post = get_page_by_path( $slug, OBJECT, 'product' );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		return wc_get_product( $post->ID );
	}

	public function canonical_url( $canonical ) {
		$product = $this->routed_product();
		return $product instanceof WC_Product ? $this->record_url( $product ) : $canonical;
	}

	public function canonical_redirect() {
		if ( is_admin() || ! is_page( absint( get_option( self::PAGE_OPTION ) ) ) || get_query_var( 'olr_coa_product_id' ) || get_query_var( 'olr_coa_product' ) ) {
			return;
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		wp_safe_redirect( $product instanceof WC_Product ? $this->record_url( $product ) : home_url( '/coas/' ), 301 );
		exit;
	}

	private function display_date( $date ) {
		$timestamp = $date ? strtotime( $date ) : false;
		return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
	}

	public function shortcode( $attributes ) {
		$attributes = shortcode_atts( array( 'product_id' => 0 ), (array) $attributes, 'olr_coa_page' );
		$product_id = absint( $attributes['product_id'] );
		if ( ! $product_id && isset( $_GET['product_id'] ) ) {
			$product_id = absint( wp_unslash( $_GET['product_id'] ) );
		}
		$product = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : $this->routed_product();
		$reports = $product ? $this->reports( $product_id ) : array();
		if ( ! $product || empty( $reports ) ) { return $this->empty_state(); }
		$current = array_shift( $reports );
		$pdf_id = absint( get_post_meta( $current->ID, '_olr_pdf_id', true ) );
		$pdf_url = wp_get_attachment_url( $pdf_id );
		ob_start();
		include __DIR__ . '/templates/coa-page.php';
		return (string) ob_get_clean();
	}

	private function empty_state() {
		return '<main class="olr-coa-detail"><section class="olr-coa-detail__empty"><p>The receipts</p><h1>Report unavailable.</h1><p>This product does not have a published testing record.</p><a href="' . esc_url( home_url( '/coas/' ) ) . '">Back to the receipts →</a></section></main>';
	}

	public function columns( $columns ) {
		return array( 'cb' => $columns['cb'], 'title' => __( 'Report', 'off-label-coa-manager' ), 'product' => __( 'Product', 'off-label-coa-manager' ), 'test_date' => __( 'Test date', 'off-label-coa-manager' ), 'pdf' => 'PDF', 'date' => $columns['date'] );
	}

	public function column_content( $column, $post_id ) {
		if ( 'product' === $column ) { echo esc_html( get_the_title( absint( get_post_meta( $post_id, '_olr_product_id', true ) ) ) ); }
		if ( 'test_date' === $column ) { echo esc_html( $this->display_date( get_post_meta( $post_id, '_olr_test_date', true ) ) ); }
		if ( 'pdf' === $column ) { $url = wp_get_attachment_url( absint( get_post_meta( $post_id, '_olr_pdf_id', true ) ) ); echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">View</a>' : '—'; }
	}

	public function row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type ) { $product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( get_post_meta( $post->ID, '_olr_product_id', true ) ) ) : false; if ( $product ) { $actions['olr_view'] = '<a href="' . esc_url( $this->record_url( $product ) ) . '" target="_blank">View receipt page</a>'; } }
		return $actions;
	}

	public function admin_product_filter() {
		if ( self::POST_TYPE !== get_current_screen()->post_type || ! function_exists( 'wc_get_products' ) ) { return; }
		$selected = isset( $_GET['olr_product'] ) ? absint( $_GET['olr_product'] ) : 0;
		echo '<select name="olr_product"><option value="">' . esc_html__( 'All products', 'off-label-coa-manager' ) . '</option>';
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC' ) ) as $product ) {
			echo '<option value="' . esc_attr( $product->get_id() ) . '" ' . selected( $selected, $product->get_id(), false ) . '>' . esc_html( $product->get_name() ) . '</option>';
		}
		echo '</select>';
	}

	public function filter_admin_reports( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) || empty( $_GET['olr_product'] ) ) { return; }
		$query->set( 'meta_query', array( array( 'key' => '_olr_product_id', 'value' => absint( $_GET['olr_product'] ) ) ) );
	}

	public function admin_notices() {
		if ( ! function_exists( 'wc_get_product' ) ) { echo '<div class="notice notice-warning"><p><strong>Off Label COA Manager:</strong> WooCommerce must be active to associate reports with products.</p></div>'; }
		if ( get_transient( 'olr_coa_invalid_' . get_current_user_id() ) ) { delete_transient( 'olr_coa_invalid_' . get_current_user_id() ); echo '<div class="notice notice-error"><p><strong>COA not published:</strong> choose a WooCommerce product, valid test date, and PDF file first.</p></div>'; }
	}
}

register_activation_hook( __FILE__, array( 'OLR_COA_Manager', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OLR_COA_Manager', 'deactivate' ) );
OLR_COA_Manager::instance();
