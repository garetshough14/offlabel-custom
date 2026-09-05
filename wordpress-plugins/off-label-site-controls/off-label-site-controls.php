<?php
/**
 * Plugin Name: Off Label Site Controls
 * Description: Editable promotional banner, scoped storefront refinements and a post-add cart preview.
 * Version: 1.0.9
 * Requires PHP: 7.4
 * Author: Off Label Research
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OLR_Site_Controls {
	const OPTION = 'olr_promo_banner';
	const VERSION = '1.0.9';
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'replace_banner' ), 99, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'replace_about_image' ), 99, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'replace_box_image' ), 99, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'refine_layout' ), 100, 2 );
		OLR_Cart_Preview::init();
	}
	public static function defaults() {
		return array(
			array( 'text' => 'Research use only', 'url' => '' ),
			array( 'text' => 'Not for human consumption', 'url' => '' ),
			array( 'text' => 'Document archive', 'url' => home_url( '/coas/' ) ),
		);
	}
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$rows = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$row = isset( $input[$i] ) && is_array( $input[$i] ) ? $input[$i] : array();
			$text = isset( $row['text'] ) && is_scalar( $row['text'] ) ? sanitize_text_field( (string) $row['text'] ) : '';
			$url = isset( $row['url'] ) && is_scalar( $row['url'] ) ? esc_url_raw( (string) $row['url'], array( 'http', 'https' ) ) : '';
			$rows[] = array( 'text' => wp_html_excerpt( $text, 80, '' ), 'url' => $url );
		}
		return $rows;
	}
	public static function rows() { return self::sanitize( get_option( self::OPTION, self::defaults() ) ); }
	public static function menu() {
		add_menu_page( 'Promo Banner', 'Promo Banner', 'manage_options', 'olr-promo-banner', array( __CLASS__, 'page' ), 'dashicons-megaphone', 59 );
	}
	public static function settings() {
		register_setting( 'olr_promo_banner_group', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => self::defaults(), 'show_in_rest' => false ) );
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.' ); }
		$rows = self::rows();
		echo '<div class="wrap"><h1>Promo Banner</h1><p>Edit the black bar above the header. Existing colors, font and separators stay the same. Leave a message blank to omit it; leave all three blank to hide the bar.</p>';
		settings_errors( self::OPTION );
		echo '<form action="options.php" method="post">';
		settings_fields( 'olr_promo_banner_group' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $rows as $i => $row ) {
			echo '<tr><th scope="row"><label for="olr-message-' . (int) $i . '">Message ' . (int) ( $i + 1 ) . '</label></th><td><input class="regular-text" type="text" maxlength="80" id="olr-message-' . (int) $i . '" name="' . esc_attr( self::OPTION ) . '[' . (int) $i . '][text]" value="' . esc_attr( $row['text'] ) . '"><p><label for="olr-link-' . (int) $i . '">Optional link</label><br><input class="regular-text" type="url" id="olr-link-' . (int) $i . '" name="' . esc_attr( self::OPTION ) . '[' . (int) $i . '][url]" value="' . esc_attr( $row['url'] ) . '" placeholder="https://offlabelresearch.com/catalog/"></p></td></tr>';
		}
		echo '</table>';
		submit_button( 'Save Banner' );
		echo '</form><p>After saving, clear the hosting/page cache if visitors still see an older banner. No image seeding or page promotion is needed.</p></div>';
	}
	public static function markup() {
		$parts = array();
		foreach ( self::rows() as $row ) {
			if ( '' === $row['text'] ) { continue; }
			$parts[] = '' === $row['url'] ? '<span>' . esc_html( $row['text'] ) . '</span>' : '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['text'] ) . '</a>';
		}
		if ( ! $parts ) { return ''; }
		return '<aside class="olr-announcement" aria-label="Store announcements"><div class="olr-shell olr-announcement__inner">' . implode( '<span class="olr-announcement__separator" aria-hidden="true">/</span>', $parts ) . '</div></aside>';
	}
	public static function replace_banner( $output, $tag ) {
		if ( is_admin() || ! in_array( $tag, array( 'divi_github_content', 'olr_site_header' ), true ) || ! is_string( $output ) || false === strpos( $output, 'olr-announcement' ) ) { return $output; }
		// Only replace the known announcement aside, never other header markup.
		return preg_replace_callback( '~<aside\b[^>]*\bclass\s*=\s*([\'"])[^\'"]*\bolr-announcement\b[^\'"]*\1[^>]*>.*?</aside\s*>~is', static function () { return self::markup(); }, $output ) ?? $output;
	}
	public static function replace_about_image( $output, $tag ) {
		if ( is_admin() || 'divi_github_content' !== $tag || ! is_string( $output ) || false === strpos( $output, 'olr-page--about' ) ) { return $output; }
		// Exact legacy artwork only; replace after GitPress's content cache.
		return preg_replace_callback( '~<img\b[^>]*\bsrc\s*=\s*([\'"])https://cdn\.jsdelivr\.net/gh/garetshough14/offlabel-custom@main/images/editorial/about-philosophy-record-no-research\.png\1[^>]*>~i', static function () {
			return '<picture class="olr-about-artwork"><source media="(max-width: 48.875rem)" srcset="' . esc_url( plugins_url( 'assets/about-philosophy-approved-v1.png', __FILE__ ) ) . '"><img src="' . esc_url( plugins_url( 'assets/about-philosophy-wide-v2.png', __FILE__ ) ) . '" alt="Off Label research bottles with molecular diagrams" width="2041" height="770" loading="lazy" decoding="async" data-olr-about-artwork="approved"></picture>';
		}, $output ) ?? $output;
	}
	public static function replace_box_image( $output, $tag ) {
		if ( is_admin() || ! in_array( $tag, array( 'olr_build_a_box', 'divi_github_content' ), true ) || ! is_string( $output ) || false === strpos( $output, 'olr-build-box__hero-media' ) ) { return $output; }
		// Touch only the builder hero figure; no product, cart or selection markup.
		return preg_replace_callback( '~(<figure\b[^>]*\bclass\s*=\s*([\'"])[^\'"]*\bolr-build-box__hero-media\b[^\'"]*\2[^>]*>)(.*?)(</figure\s*>)~is', static function ( $match ) {
			$image = '<img src="' . esc_url( plugins_url( 'assets/build-box-transparent-v1.png', __FILE__ ) ) . '" alt="Open Off Label Research box with branded research bottles floating above it" width="1254" height="1254" loading="eager" decoding="async" fetchpriority="high" data-olr-box-artwork="transparent">';
			return $match[1] . ( preg_replace( '~<img\b[^>]*>~i', $image, $match[3], 1 ) ?? $match[3] ) . $match[4];
		}, $output ) ?? $output;
	}
	public static function refine_layout( $output, $tag ) {
		if ( is_admin() || ! is_string( $output ) || ! in_array( $tag, array( 'divi_github_content', 'olr_site_header', 'olr_research_catalog', 'olr_product_page' ), true ) ) { return $output; }
		// Remove complete, uniquely scoped sections after any GitPress cache read.
		$output = preg_replace( '~<nav\b[^>]*class="olr-research-tabs"[^>]*>.*?</nav>~is', '', $output ) ?? $output;
		$output = preg_replace( '~<details\b[^>]*class="olr-product-information__section"[^>]*data-icon="quality"[^>]*>.*?</details>~is', '', $output ) ?? $output;
		$output = preg_replace_callback( '~(<nav\b[^>]*class="olr-primary-nav olr-primary-nav--(?:desktop|mobile)"[^>]*>)(.*?)(</nav>)~is', static function ( $m ) {
			if ( false === strpos( $m[2], 'data-nav="build-your-box"' ) ) {
				$link = '<a href="' . esc_url( home_url( '/build-your-box/' ) ) . '" data-nav="build-your-box">Build Your Box</a>';
				$m[2] = preg_replace( '~(<a\b[^>]*data-nav="catalog"[^>]*>.*?</a>)~is', '$1' . $link, $m[2], 1 ) ?? $m[2];
			}
			return $m[1] . $m[2] . $m[3];
		}, $output ) ?? $output;
		return preg_replace_callback( '~(<a\b[^>]*class="olr-bag-link"[^>]*>)(.*?)(</a>)~is', static function ( $m ) {
			return $m[1] . str_replace( '<span class="screen-reader-text">Cart</span>', '<span class="olr-cart-label">Cart</span>', $m[2] ) . $m[3];
		}, $output ) ?? $output;
	}
	public static function assets() {
		wp_enqueue_style( 'olr-site-controls', plugins_url( 'assets/site-controls.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'olr-promo-banner', plugins_url( 'assets/promo-banner.js', __FILE__ ), array(), self::VERSION, true );
		// Fallback for an existing theme template not rendered through GitPress.
		wp_add_inline_script( 'olr-promo-banner', 'window.olrPromoBanner=' . wp_json_encode( self::rows(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
require_once __DIR__ . '/cart-preview.php';
OLR_Site_Controls::init();
