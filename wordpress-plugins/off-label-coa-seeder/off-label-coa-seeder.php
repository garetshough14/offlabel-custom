<?php
/**
 * Plugin Name: Off Label COA Seeder
 * Description: One-time, idempotent importer for the approved Off Label COA archive.
 * Version: 1.0.0
 * Author: Off Label Research
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OLR_COA_Seeder {
	const POST_TYPE     = 'olr_coa';
	const MANIFEST_FILE = __DIR__ . '/data/manifest.json';
	const BATCH_SIZE    = 10;
	const NOTICE_KEY    = 'olr_coa_seed_notice_';

	private static $instance;
	private $products_by_name;
	private $reports_by_filename;
	private $reports_by_seed_key;
	private $attachments_by_filename;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
		add_action( 'admin_post_olr_coa_seed_import', array( $this, 'import_batch' ) );
	}

	public function admin_menu() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			'Seed COA Reports',
			'Seed COAs',
			'manage_woocommerce',
			'olr-coa-seeder',
			array( $this, 'render_page' )
		);
	}

	private function can_import() {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'upload_files' );
	}

	private function requirements_error() {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return 'Off Label COA Manager must be active.';
		}
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_products' ) ) {
			return 'WooCommerce must be active.';
		}
		if ( ! is_readable( self::MANIFEST_FILE ) ) {
			return 'The COA seed manifest is missing.';
		}
		return '';
	}

	private function manifest() {
		$contents = file_get_contents( self::MANIFEST_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = json_decode( (string) $contents, true );
		return is_array( $manifest ) ? $manifest : array();
	}

	private function normalize_name( $value ) {
		$value = strtolower( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
		return preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	private function products_by_name() {
		if ( null !== $this->products_by_name ) {
			return $this->products_by_name;
		}
		$this->products_by_name = array();
		$products = wc_get_products(
			array(
				'status' => array( 'publish', 'private', 'draft' ),
				'limit'  => -1,
				'return' => 'objects',
			)
		);
		foreach ( $products as $product ) {
			$this->products_by_name[ $this->normalize_name( $product->get_name() ) ] = $product;
		}
		return $this->products_by_name;
	}

	private function resolve_product( $entry ) {
		$expected_id   = isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
		$expected_name = isset( $entry['product_name'] ) ? (string) $entry['product_name'] : '';
		$product       = $expected_id ? wc_get_product( $expected_id ) : false;
		if ( $product && $this->normalize_name( $product->get_name() ) === $this->normalize_name( $expected_name ) ) {
			return $product;
		}
		$products = $this->products_by_name();
		$key      = $this->normalize_name( $expected_name );
		return isset( $products[ $key ] ) ? $products[ $key ] : false;
	}

	private function reports_by_filename() {
		if ( null !== $this->reports_by_filename ) {
			return $this->reports_by_filename;
		}
		$this->reports_by_filename = array();
		$reports = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $reports as $report_id ) {
			$pdf_id = absint( get_post_meta( $report_id, '_olr_pdf_id', true ) );
			$file   = $pdf_id ? get_attached_file( $pdf_id ) : '';
			if ( $file ) {
				$this->reports_by_filename[ strtolower( basename( $file ) ) ] = absint( $report_id );
			}
		}
		return $this->reports_by_filename;
	}

	private function reports_by_seed_key() {
		if ( null !== $this->reports_by_seed_key ) {
			return $this->reports_by_seed_key;
		}
		$this->reports_by_seed_key = array();
		$reports = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_olr_coa_seed_key',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $reports as $report_id ) {
			$key = (string) get_post_meta( $report_id, '_olr_coa_seed_key', true );
			if ( $key ) {
				$this->reports_by_seed_key[ $key ] = absint( $report_id );
			}
		}
		return $this->reports_by_seed_key;
	}

	private function attachments_by_filename() {
		global $wpdb;
		if ( null !== $this->attachments_by_filename ) {
			return $this->attachments_by_filename;
		}
		$this->attachments_by_filename = array();
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $row ) {
			if ( preg_match( '/\.pdf$/i', $row['meta_value'] ) ) {
				$this->attachments_by_filename[ strtolower( basename( $row['meta_value'] ) ) ] = absint( $row['post_id'] );
			}
		}
		return $this->attachments_by_filename;
	}

	private function source_path( $entry ) {
		$filename = isset( $entry['filename'] ) ? (string) $entry['filename'] : '';
		if ( ! $filename || basename( $filename ) !== $filename || ! preg_match( '/\.pdf$/i', $filename ) ) {
			return '';
		}
		return __DIR__ . '/data/pdfs/' . $filename;
	}

	private function plan_entry( $entry ) {
		$filename = isset( $entry['filename'] ) ? (string) $entry['filename'] : '';
		$product  = $this->resolve_product( $entry );
		$source   = $this->source_path( $entry );
		$date     = isset( $entry['test_date'] ) ? (string) $entry['test_date'] : '';
		$hash     = is_readable( $source ) ? hash_file( 'sha256', $source ) : '';
		if ( ! $filename || ! is_readable( $source ) ) {
			return array( 'status' => 'blocked', 'reason' => 'PDF missing from seed package.', 'entry' => $entry, 'product' => $product );
		}
		if ( empty( $entry['sha256'] ) || ! hash_equals( strtolower( $entry['sha256'] ), strtolower( $hash ) ) ) {
			return array( 'status' => 'blocked', 'reason' => 'PDF checksum does not match the manifest.', 'entry' => $entry, 'product' => $product );
		}
		if ( ! $product ) {
			return array( 'status' => 'blocked', 'reason' => 'WooCommerce product was not found.', 'entry' => $entry, 'product' => false );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array( 'status' => 'blocked', 'reason' => 'Test date is invalid.', 'entry' => $entry, 'product' => $product );
		}

		$seed_key       = isset( $entry['seed_key'] ) ? (string) $entry['seed_key'] : '';
		$seeded_reports = $this->reports_by_seed_key();
		$reports        = $this->reports_by_filename();
		$media_filename = strtolower( sanitize_file_name( $filename ) );
		$report_id      = isset( $seeded_reports[ $seed_key ] ) ? absint( $seeded_reports[ $seed_key ] ) : 0;
		if ( ! $report_id && isset( $reports[ $media_filename ] ) ) {
			$report_id = absint( $reports[ $media_filename ] );
		}
		if ( $report_id ) {
			$is_exact = absint( get_post_meta( $report_id, '_olr_product_id', true ) ) === $product->get_id()
				&& $date === (string) get_post_meta( $report_id, '_olr_test_date', true )
				&& 'publish' === get_post_status( $report_id );
			return array(
				'status'    => $is_exact ? 'complete' : 'repair',
				'reason'    => $is_exact ? 'Already imported.' : 'Existing PDF report needs its product, date, title, or status corrected.',
				'entry'     => $entry,
				'product'   => $product,
				'report_id' => $report_id,
			);
		}

		return array( 'status' => 'create', 'reason' => 'Ready to import.', 'entry' => $entry, 'product' => $product );
	}

	private function build_plan() {
		$plan = array();
		foreach ( $this->manifest() as $entry ) {
			$plan[] = $this->plan_entry( $entry );
		}
		return $plan;
	}

	private function attachment_for_entry( $entry ) {
		$filename    = (string) $entry['filename'];
		$attachments = $this->attachments_by_filename();
		$key         = strtolower( sanitize_file_name( $filename ) );
		if ( isset( $attachments[ $key ] ) && 'application/pdf' === get_post_mime_type( $attachments[ $key ] ) ) {
			return absint( $attachments[ $key ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$source = $this->source_path( $entry );
		$upload = wp_upload_bits( $filename, null, file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'olr_coa_upload_failed', $upload['error'] );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( $metadata ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		update_post_meta( $attachment_id, '_olr_coa_seed_sha256', sanitize_text_field( $entry['sha256'] ) );
		$this->attachments_by_filename[ strtolower( basename( $upload['file'] ) ) ] = absint( $attachment_id );
		return absint( $attachment_id );
	}

	private function import_entry( $item ) {
		$entry      = $item['entry'];
		$product    = $item['product'];
		$report_id  = ! empty( $item['report_id'] ) ? absint( $item['report_id'] ) : 0;
		$attachment = $this->attachment_for_entry( $entry );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		$title = $product->get_name() . ' — ' . $entry['test_date'];
		if ( $report_id ) {
			$result = wp_update_post(
				array(
					'ID'          => $report_id,
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);
		} else {
			$result = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);
			$report_id = is_wp_error( $result ) ? 0 : absint( $result );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $report_id ) {
			$report_id = absint( $result );
		}
		update_post_meta( $report_id, '_olr_product_id', $product->get_id() );
		update_post_meta( $report_id, '_olr_pdf_id', $attachment );
		update_post_meta( $report_id, '_olr_test_date', sanitize_text_field( $entry['test_date'] ) );
		update_post_meta( $report_id, '_olr_coa_seed_key', sanitize_text_field( $entry['seed_key'] ) );
		return $report_id;
	}

	public function import_batch() {
		if ( ! $this->can_import() ) {
			wp_die( esc_html__( 'You are not allowed to import COA reports.', 'off-label-coa-seeder' ) );
		}
		check_admin_referer( 'olr_coa_seed_import', 'olr_coa_seed_nonce' );
		$error = $this->requirements_error();
		if ( $error ) {
			wp_die( esc_html( $error ) );
		}
		$created = 0;
		$repaired = 0;
		$errors = array();
		$handled = 0;
		foreach ( $this->build_plan() as $item ) {
			if ( ! in_array( $item['status'], array( 'create', 'repair' ), true ) ) {
				continue;
			}
			$result = $this->import_entry( $item );
			if ( is_wp_error( $result ) ) {
				$errors[] = $item['entry']['filename'] . ': ' . $result->get_error_message();
			} elseif ( 'repair' === $item['status'] ) {
				++$repaired;
			} else {
				++$created;
			}
			++$handled;
			if ( $handled >= self::BATCH_SIZE ) {
				break;
			}
		}
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			array( 'created' => $created, 'repaired' => $repaired, 'errors' => $errors ),
			120
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=olr-coa-seeder' ) );
		exit;
	}

	public function render_page() {
		if ( ! $this->can_import() ) {
			wp_die( esc_html__( 'You are not allowed to import COA reports.', 'off-label-coa-seeder' ) );
		}
		$error = $this->requirements_error();
		?>
		<div class="wrap">
			<h1>Seed COA Reports</h1>
			<p>This one-time importer verifies each bundled PDF, maps it to the approved WooCommerce product, and skips certificates that are already correct.</p>
			<?php
			$notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
			if ( $notice ) {
				delete_transient( self::NOTICE_KEY . get_current_user_id() );
				$class = empty( $notice['errors'] ) ? 'notice-success' : 'notice-warning';
				echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>Batch finished.</strong> Created: ' . esc_html( $notice['created'] ) . '; repaired: ' . esc_html( $notice['repaired'] ) . '.</p>';
				if ( ! empty( $notice['errors'] ) ) {
					echo '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $notice['errors'] ) ) . '</li></ul>';
				}
				echo '</div>';
			}
			if ( $error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div></div>';
				return;
			}
			$plan   = $this->build_plan();
			$counts = array_count_values( wp_list_pluck( $plan, 'status' ) );
			$ready  = isset( $counts['create'] ) ? $counts['create'] : 0;
			$repair = isset( $counts['repair'] ) ? $counts['repair'] : 0;
			$done   = isset( $counts['complete'] ) ? $counts['complete'] : 0;
			$blocked = isset( $counts['blocked'] ) ? $counts['blocked'] : 0;
			?>
			<div class="notice notice-info inline"><p><strong>Archive:</strong> <?php echo esc_html( count( $plan ) ); ?> PDFs &nbsp; <strong>Ready:</strong> <?php echo esc_html( $ready ); ?> &nbsp; <strong>Repairs:</strong> <?php echo esc_html( $repair ); ?> &nbsp; <strong>Complete:</strong> <?php echo esc_html( $done ); ?> &nbsp; <strong>Blocked:</strong> <?php echo esc_html( $blocked ); ?></p></div>
			<?php if ( $ready || $repair ) : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="olr_coa_seed_import">
					<?php wp_nonce_field( 'olr_coa_seed_import', 'olr_coa_seed_nonce' ); ?>
					<?php submit_button( 'Import next ' . self::BATCH_SIZE . ' reports', 'primary', 'submit', false ); ?>
				</form>
			<?php elseif ( ! $blocked ) : ?>
				<div class="notice notice-success inline"><p><strong>Import complete.</strong> Every bundled COA is published and linked.</p></div>
			<?php endif; ?>
			<table class="widefat striped" style="margin-top:16px">
				<thead><tr><th>PDF</th><th>WooCommerce product</th><th>Test date</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ( $plan as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item['entry']['filename'] ); ?></td>
						<td><?php echo esc_html( $item['product'] ? $item['product']->get_name() : $item['entry']['product_name'] ); ?></td>
						<td><?php echo esc_html( $item['entry']['test_date'] ); ?></td>
						<td><strong><?php echo esc_html( ucfirst( $item['status'] ) ); ?></strong> — <?php echo esc_html( $item['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

OLR_COA_Seeder::instance();
