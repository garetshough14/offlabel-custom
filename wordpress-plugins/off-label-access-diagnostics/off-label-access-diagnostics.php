<?php
/**
 * Plugin Name: Off Label Access Diagnostics
 * Description: Read-only administrator report for GitPress and Ultimate Member access configuration.
 * Version: 1.0.0
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OLR_Access_Diagnostics {
	public static function boot() {
		// No frontend, REST, checkout, authentication or activation handlers.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		}
	}
	public static function menu() {
		add_management_page( 'Off Label Access Check', 'Off Label Access Check', 'manage_options', 'olr-access-check', array( __CLASS__, 'page' ) );
	}
	private static function relative_file( $file ) {
		$file = wp_normalize_path( (string) $file );
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );
		return strpos( $file, $root ) === 0 ? substr( $file, strlen( $root ) ) : basename( $file );
	}
	private static function callbacks( $hook ) {
		global $wp_filter;
		$result = array();
		if ( empty( $wp_filter[$hook] ) || ! isset( $wp_filter[$hook]->callbacks ) ) { return $result; }
		foreach ( $wp_filter[$hook]->callbacks as $priority => $items ) {
			foreach ( $items as $item ) {
				$fn = $item['function'];
				$row = array( 'priority' => $priority );
				try {
					if ( is_array( $fn ) ) {
						$row['callback'] = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1];
						$r = new ReflectionMethod( $fn[0], $fn[1] );
					} elseif ( is_string( $fn ) && strpos( $fn, '::' ) !== false ) {
						$row['callback'] = $fn;
						$r = new ReflectionMethod( ...explode( '::', $fn, 2 ) );
					} elseif ( is_string( $fn ) || $fn instanceof Closure ) {
						$row['callback'] = is_string( $fn ) ? $fn : 'Closure';
						$r = new ReflectionFunction( $fn );
					} else {
						$row['callback'] = is_object( $fn ) ? get_class( $fn ) . '::__invoke' : 'Unknown';
						$r = null;
					}
					if ( $r ) { $row['source'] = self::relative_file( $r->getFileName() ); $row['line'] = $r->getStartLine(); }
				} catch ( Throwable $e ) { $row['inspection'] = 'unavailable'; }
				$result[] = $row;
			}
		}
		return $result;
	}
	public static function collect() {
		if ( ! current_user_can( 'manage_options' ) ) { return array( 'error' => 'Administrator access required.' ); }
		$report = array( 'report_version' => '1.0.0', 'context' => 'admin-only; not an anonymous frontend trace', 'gitpress_version' => defined( 'DGS_VERSION' ) ? DGS_VERSION : null, 'um_version' => defined( 'UM_VERSION' ) ? UM_VERSION : null );
		if ( class_exists( 'DGS_Page_Shortcode_Manager', false ) ) {
			$r = new ReflectionClass( 'DGS_Page_Shortcode_Manager' );
			$report['gitpress_manager'] = array( 'source' => self::relative_file( $r->getFileName() ), 'sha256' => is_readable( $r->getFileName() ) ? hash_file( 'sha256', $r->getFileName() ) : null, 'has_template_selector' => $r->hasMethod( 'select_full_page_template' ), 'has_cache_guard' => $r->hasMethod( 'prevent_member_page_cache' ) );
		}
		$keys = array( 'accessible', 'home_page_accessible', 'category_page_accessible', 'access_redirect', 'access_exclude_uris', 'disable_restriction_pre_queries', 'restricted_access_post_metabox', 'restricted_access_taxonomy_metabox' );
		foreach ( array( 'login', 'register', 'password-reset', 'account', 'logout', 'user', 'members' ) as $core ) { $keys[] = 'core_' . $core; }
		$raw = get_option( 'um_options', array() );
		$report['saved_um_options'] = is_array( $raw ) ? array_intersect_key( $raw, array_flip( $keys ) ) : array();
		try {
			$options = function_exists( 'UM' ) ? UM()->options() : null;
			if ( is_object( $options ) && is_callable( array( $options, 'get' ) ) ) {
				foreach ( $keys as $key ) { $report['effective_um_options'][$key] = $options->get( $key ); }
			}
		} catch ( Throwable $e ) { $report['um_options_read'] = 'unavailable'; }
		$report['page_assignments'] = array();
		foreach ( array( 'page_on_front', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id' ) as $key ) { $report['page_assignments'][$key] = absint( get_option( $key ) ); }
		$ids = array_values( $report['page_assignments'] );
		foreach ( array( 'catalog-item', 'receipt', 'login', 'register', 'password-reset' ) as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p ) { $ids[] = $p->ID; }
		}
		$restriction_keys = array( '_um_custom_access_settings', '_um_accessible', '_um_access_roles', '_um_noaccess_action', '_um_access_redirect', '_um_access_redirect_url', '_um_access_hide_from_queries' );
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$p = get_post( $id );
			if ( ! $p ) { continue; }
			$restriction = get_post_meta( $id, 'um_content_restriction', true );
			$report['pages'][] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => $p->post_status, 'gitpress_mode' => get_post_meta( $id, '_dgs_page_shortcode_render_mode', true ), 'um_restriction' => is_array( $restriction ) ? array_intersect_key( $restriction, array_flip( $restriction_keys ) ) : $restriction );
		}
		foreach ( array( 'wp', 'template_redirect', 'template_include', 'um_access_check_global_settings', 'um_exclude_posts_from_privacy', 'um_post_content_restriction_settings', 'option_um_options', 'pre_option_um_options' ) as $hook ) { $report['hooks'][$hook] = self::callbacks( $hook ); }
		$report['cache_files_present'] = array( 'advanced-cache.php' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ), 'object-cache.php' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ) );
		return $report;
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.' ); }
		echo '<div class="wrap"><h1>Off Label Access Check</h1><p>Read-only. No settings, page content, orders or access rules are changed. No customer records, passwords or API keys are included.</p><p>Click inside the report, press Ctrl+A then Ctrl+C, and paste it into the chat.</p><textarea readonly aria-label="Access diagnostic report" style="width:100%;height:65vh;font-family:monospace">';
		echo esc_textarea( wp_json_encode( self::collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</textarea></div>';
	}
}
OLR_Access_Diagnostics::boot();
