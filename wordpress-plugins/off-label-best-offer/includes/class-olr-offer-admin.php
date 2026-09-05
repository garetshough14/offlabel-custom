<?php
defined( 'ABSPATH' ) || exit;

final class OLR_Offer_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_olr_save_best_offer', array( $this, 'save_settings' ) );
		add_action( 'admin_post_olr_save_live_best_offer', array( $this, 'save_live_settings' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'coupon_field' ), 20, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon' ), 20, 2 );
	}
	public function menu() {
		add_submenu_page( 'woocommerce', 'Off Label Pricing', 'Off Label Pricing', 'manage_woocommerce', 'olr-best-offer', array( $this, 'page' ) );
	}
	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		echo '<div class="wrap"><h1>Off Label Pricing</h1>';
		echo '<p>Disabled by default. Targets WooCommerce 11.1.0, Build Your Box 1.3.3, Advanced Cart Offers 1.1.0 and ELEX 1.3.2. Keep Checkout 1.4.4 and your other plugins installed.</p>';
		echo '<p>Environment: <strong>' . esc_html( wp_get_environment_type() ) . '</strong>. WooCommerce: <strong>' . esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : 'not loaded' ) . '</strong>.</p>';
		if ( 'production' === wp_get_environment_type() ) { $this->production_controls(); $this->live_controls(); echo '</div>'; return; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="olr_save_best_offer">';
		wp_nonce_field( 'olr_save_best_offer' );
		echo '<label><input type="checkbox" name="enabled" value="yes" ' . checked( get_option( 'olr_best_offer_enabled', 'no' ), 'yes', false ) . '> Enable on this staging/local site</label>';
		echo '<p>Each SKU gets its best eligible offer. Each complete Research Box is one group. Explicit coupon exclusions take precedence. Coupons keep their original minimum spend, usage limits, individual-use and free-shipping settings. Existing automatic offers win ties.</p>';
		echo '<p>Product controls are under Products → Edit → Product data → General. Variations inherit their parent’s tier settings, but each variation qualifies separately. Coupon restrictions include an explicit “Exclude Build Your Box” control; excluding the Bundles category also excludes boxes.</p>';
		echo '<p>Fixed-cart coupons retain WooCommerce’s original allocation: an unused share is not moved to another SKU. Unsupported price changes or negative fees pause checkout instead of silently stacking. Fixed-amount ELEX rules are not supported in this candidate.</p>';
		submit_button( 'Save staging setting' );
		echo '</form></div>';
	}
	private function live_controls() {
		if ( ! OLR_Offer_Live_Preview::can_preview() ) { echo '<p>Only a signed-in administrator can start a private preview.</p>'; return; }
		$active = OLR_Offer_Live_Preview::active();
		echo '<h2>' . ( $active ? 'Private preview is on for this browser session' : 'Private preview is off' ) . '</h2>';
		if ( $active && empty( $GLOBALS['olr_best_offer_preview_ready'] ) ) { echo '<div class="notice notice-error inline"><p>The pricing engine could not start. Check the plugin versions listed above; end this preview before troubleshooting. Do not update unrelated live plugins to force compatibility.</p></div>'; }
		echo '<p>This is a separate 30-minute, administrator-only cart preview. When live pricing is off, other visitors do not receive the new pricing. Start with an empty cart; your existing items will not be removed. Preview items cannot be ordered, even after the preview expires or live pricing is enabled. End preview and add fresh items before placing a real order. Payment methods and standard checkout submission are blocked in preview.</p>';
		echo '<p>Test product tiers, coupons and complete boxes only. Do not edit real orders, payment settings, shipping integrations, stock, or customer information during this preview. Emails, refunds and payment-method offers are not tested by this mode.</p>';
		foreach ( array( 'olr_start_pricing_preview' => 'Start / restart my 30-minute preview', 'olr_stop_pricing_preview' => 'End preview and remove its test items' ) as $action => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
			wp_nonce_field( $action );
			submit_button( $label, 'olr_start_pricing_preview' === $action ? 'primary' : 'secondary' );
			echo '</form>';
		}
		echo '<p><a href="' . esc_url( home_url( '/catalog/' ) ) . '" target="_blank" rel="noopener">Open catalog in this signed-in browser</a></p><p>Wait for the PRIVATE PRICING PREVIEW banner before testing. To compare ordinary customer pricing, use a different non-admin account in a separate browser. End the preview with the button above when finished.</p>';
	}
	private function production_controls() {
		if ( ! OLR_Offer_Live_Preview::can_preview() ) { echo '<p>Administrator access is required to change live pricing.</p>'; return; }
		$enabled = OLR_Best_Offer::live_requested();
		$errors = OLR_Best_Offer::compatibility_errors();
		echo '<h2>Live pricing: ' . ( $enabled ? ( $errors ? 'PAUSED — compatibility check failed' : 'ON for all shoppers' ) : 'OFF' ) . '</h2>';
		foreach ( $errors as $error ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>'; }
		echo '<div class="notice notice-warning inline"><p>Live order, email, refund and gateway integration has not been verified on staging. Enabling this affects ALL shoppers. Orders placed outside preview are real and may charge a payment method, send emails, reduce stock and trigger fulfillment. This release supports classic checkout only; Blocks/Store API order submission is blocked.</p></div>';
		echo '<p>Before enabling: confirm a recoverable backup, end private preview and remove its test items, and review public test coupons. Clear the website/page cache after changing live pricing. Use the control below to stop new pricing; keep this version installed so saved orders retain their allocation handler. Turning pricing off does not cancel or refund orders. Do not downgrade/deactivate after taking orders without an order-data review. Edited order quantities/prices still require manual review rather than automatic repricing.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="olr_save_live_best_offer">';
		wp_nonce_field( 'olr_save_live_best_offer' );
		echo '<label><input type="checkbox" name="enabled" value="yes" ' . checked( $enabled, true, false ) . '> Enable live pricing for all shoppers</label>';
		if ( ! $enabled ) { echo '<p><label><input type="checkbox" name="acknowledge_live_risk" value="yes"> I have a recoverable backup and accept enabling live pricing before full staging order/payment/refund testing.</label></p>'; }
		submit_button( 'Save live pricing' );
		echo '</form><hr>';
	}
	public function save_live_settings() {
		if ( ! OLR_Offer_Live_Preview::can_preview() ) { wp_die( 'Administrator access required.' ); }
		check_admin_referer( 'olr_save_live_best_offer' );
		if ( 'production' !== wp_get_environment_type() ) { wp_die( 'Use the staging setting on this environment.' ); }
		$enabled = isset( $_POST['enabled'] ) && 'yes' === $_POST['enabled'];
		if ( $enabled ) {
			$errors = OLR_Best_Offer::compatibility_errors();
			if ( $errors ) { wp_die( esc_html( implode( ' ', $errors ) ) ); }
			if ( ! OLR_Best_Offer::live_requested() && ( ! isset( $_POST['acknowledge_live_risk'] ) || 'yes' !== $_POST['acknowledge_live_risk'] ) ) { wp_die( 'Confirm the backup and live-test risk acknowledgement before enabling.' ); }
			if ( OLR_Offer_Live_Preview::protected_cart() ) { wp_die( 'End private preview and remove its marked test items before enabling live pricing.' ); }
		}
		update_option( 'olr_best_offer_live_enabled', $enabled ? 'yes' : 'no', false );
		wp_safe_redirect( admin_url( 'admin.php?page=olr-best-offer' ) );
		exit;
	}
	public function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Not permitted.' ); }
		check_admin_referer( 'olr_save_best_offer' );
		update_option( 'olr_best_offer_enabled', isset( $_POST['enabled'] ) ? 'yes' : 'no', false );
		wp_safe_redirect( admin_url( 'admin.php?page=olr-best-offer' ) );
		exit;
	}
	public function product_fields() {
		global $product_object;
		if ( ! $product_object ) { return; }
		$settings = OLR_Best_Offer::settings( $product_object );
		echo '<div class="options_group show_if_simple show_if_variable"><p class="form-field"><strong>Off Label volume pricing</strong></p>';
		woocommerce_wp_select( array( 'id' => '_olr_volume_enabled', 'label' => 'Volume pricing enabled', 'options' => array( 'yes' => 'Yes', 'no' => 'No' ), 'value' => $settings['enabled'] ? 'yes' : 'no' ) );
		foreach ( $settings['tiers'] as $index => $tier ) {
			$n = $index + 1;
			woocommerce_wp_text_input( array( 'id' => '_olr_volume_q' . $n, 'label' => 'Tier ' . $n . ' quantity', 'type' => 'number', 'value' => $tier['quantity'], 'custom_attributes' => array( 'min' => 1, 'step' => 1 ) ) );
			woocommerce_wp_text_input( array( 'id' => '_olr_volume_p' . $n, 'label' => 'Tier ' . $n . ' percentage', 'type' => 'number', 'value' => $tier['percent'], 'custom_attributes' => array( 'min' => 0, 'max' => 100, 'step' => '0.01' ) ) );
		}
		woocommerce_wp_select( array( 'id' => '_olr_volume_override', 'label' => 'Allow promotional coupons to override volume pricing', 'options' => array( 'yes' => 'Yes', 'no' => 'No' ), 'value' => $settings['override'] ? 'yes' : 'no', 'description' => 'No protects this product whenever it qualifies for volume pricing. Coupon exclusions always apply.' ) );
		echo '</div>';
	}
	public function save_product( $product ) {
		if ( ! isset( $_POST['_olr_volume_enabled'] ) || ! current_user_can( 'edit_post', $product->get_id() ) ) { return; }
		$values = array(); $last_q = 0; $last_p = 0;
		for ( $n = 1; $n <= 3; ++$n ) {
			$q = filter_var( wp_unslash( $_POST['_olr_volume_q' . $n] ?? '' ), FILTER_VALIDATE_INT );
			$p = filter_var( wp_unslash( $_POST['_olr_volume_p' . $n] ?? '' ), FILTER_VALIDATE_FLOAT );
			if ( false === $q || false === $p || $q <= $last_q || $p < $last_p || $p > 100 ) {
				WC_Admin_Meta_Boxes::add_error( 'Volume settings were not saved. Quantities must increase; discounts must be between 0 and 100 and must not decrease.' );
				return;
			}
			$values[ '_olr_volume_q' . $n ] = $q; $values[ '_olr_volume_p' . $n ] = $p;
			$last_q = $q; $last_p = $p;
		}
		foreach ( array( '_olr_volume_enabled', '_olr_volume_override' ) as $key ) { $values[ $key ] = 'no' === ( $_POST[ $key ] ?? 'yes' ) ? 'no' : 'yes'; }
		foreach ( $values as $key => $value ) { $product->update_meta_data( $key, $value ); }
	}
	public function coupon_field( $coupon_id, $coupon ) {
		woocommerce_wp_checkbox( array( 'id' => '_olr_exclude_boxes', 'label' => 'Exclude Build Your Box', 'value' => $coupon->get_meta( '_olr_exclude_boxes' ), 'description' => 'This coupon cannot replace any complete box offer. Excluding Bundles also excludes boxes.' ) );
	}
	public function save_coupon( $coupon_id, $coupon ) {
		if ( ! current_user_can( 'edit_post', $coupon_id ) ) { return; }
		$coupon->update_meta_data( '_olr_exclude_boxes', isset( $_POST['_olr_exclude_boxes'] ) ? 'yes' : 'no' );
		$coupon->save_meta_data();
	}
}
