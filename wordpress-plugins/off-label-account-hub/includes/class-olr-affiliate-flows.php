<?php
/** Member and administrator interfaces; all writes pass through the service. */
defined( 'ABSPATH' ) || exit;

final class OLR_Affiliate_Flows {
	public static function boot() {
		add_action( 'init', array( 'OLR_Affiliate_Service', 'install' ), 0 );
		add_action( 'init', array( __CLASS__, 'guard_native_routes' ), -100 );
		add_action( 'init', array( __CLASS__, 'dispatch' ), 5 );
		add_action( 'wp_ajax_olr_prepare_affiliate_code', array( __CLASS__, 'ajax_prepare_code' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_native_admin' ), 0 );
		add_filter( 'uap_save_as_affiliate_filter', '__return_false', PHP_INT_MAX );
		add_filter( 'uap_filter_insert_affiliate_query', array( 'OLR_Affiliate_Service', 'insert_affiliate_guard' ), PHP_INT_MAX );
		add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'native_shortcode' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_coupon' ), 20, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_first_order' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_filter( 'pre_option_uap_wallet_enable', '__return_zero' );
		add_action( 'init', function () { remove_all_actions( 'uap_cron_job_payments' ); }, PHP_INT_MAX );
	}
	public static function input( $name, $default = '' ) {
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? (string) wp_unslash( $_POST[ $name ] ) : $default;
	}
	public static function account_url( $tab = 'payouts' ) { return add_query_arg( 'um_tab', $tab, home_url( '/account/' ) ); }
	private static function form_start( $action, $admin = false, $upload = false ) {
		$url = $admin ? admin_url( 'admin.php?page=olr-affiliate-management' ) : self::account_url( in_array( $action, array( 'activate', 'prepare_code', 'choose_code' ), true ) ? 'affiliate' : 'payouts' );
		echo '<form class="olr-account-form olr-affiliate-action" method="post" action="' . esc_url( $url ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="olr_aff_action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( 'olr_aff_' . $action, 'olr_aff_nonce' );
	}
	private static function button( $label ) { echo '<button class="olr-account-button button button-primary" type="submit">' . esc_html( $label ) . '</button></form>'; }
	public static function notice() {
		$value = get_transient( 'olr_aff_notice_' . get_current_user_id() );
		if ( ! $value ) { return; }
		delete_transient( 'olr_aff_notice_' . get_current_user_id() );
		echo '<div class="olr-account-notice notice ' . ( $value['error'] ? 'olr-account-notice--error notice-error' : 'notice-success' ) . '" role="' . ( $value['error'] ? 'alert' : 'status' ) . '"><p>' . esc_html( $value['message'] ) . '</p></div>';
	}

	public static function dispatch() {
		if ( isset( $_GET['olr_w9_download'] ) ) { self::download(); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['olr_aff_action'] ) ) { return; }
		$action = sanitize_key( self::input( 'olr_aff_action' ) );
		$admin = in_array( $action, array( 'coupon_default', 'coupon_override', 'prepare_tables', 'settings', 'block', 'unblock', 'approve_w9', 'reject_w9', 'paid', 'reject', 'refund_credit' ), true );
		if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
		if ( $admin && ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
		$message = 'Account updated.';
		$error = false;
		try {
			if ( ! wp_verify_nonce( self::input( 'olr_aff_nonce' ), 'olr_aff_' . $action ) ) { throw new RuntimeException( 'Your form session expired. Refresh the page and try again.' ); }
			$uid = get_current_user_id();
				switch ( $action ) {
				case 'choose_code':
					OLR_Affiliate_Coupons::choose( $uid, self::input( 'coupon_code' ) );
					$message = 'Your referral code is ready to share. Your previous codes remain linked to your account.'; break;
				case 'coupon_default':
					OLR_Affiliate_Coupons::save_default( self::input( 'discount_percent' ) );
					$message = 'Default customer discount updated. Individual affiliate overrides were preserved.'; break;
				case 'coupon_override':
					OLR_Affiliate_Coupons::save_override( absint( self::input( 'user_id' ) ), self::input( 'discount_percent' ) );
					$message = 'Affiliate customer discount updated.'; break;
				case 'prepare_code':
					OLR_Affiliate_Service::prepare_code( $uid );
					$message = 'Your referral code is ready.'; break;
				case 'prepare_tables':
					if ( '1' !== self::input( 'backup_confirmed' ) ) { throw new RuntimeException( 'Confirm a current database backup before converting storage engines.' ); }
					OLR_Affiliate_Service::prepare_tables();
					$message = 'Transactional database storage is ready.'; break;
				case 'activate':
					OLR_Affiliate_Service::activate( $uid, '1' === self::input( 'terms' ) );
					$message = 'Your affiliate account is active. Your code and referral link are ready.';
					break;
				case 'store_credit':
				case 'zelle':
					$id = OLR_Affiliate_Service::request( $uid, $action, self::input( 'operation_key' ), array( 'name' => self::input( 'zelle_name' ), 'destination' => self::input( 'zelle_destination' ) ) );
					$message = 'store_credit' === $action ? 'Store credit is ready to use at checkout.' : 'Your Zelle request is queued for monthly processing.';
					break;
				case 'upload_w9':
					OLR_Affiliate_Service::upload( $uid, $_FILES['w9'] ?? array(), '1' === self::input( 'signed' ) );
					$message = 'Your W-9 was uploaded and is awaiting review. Store credit is available without W-9 approval.';
					break;
				case 'settings':
					$terms = esc_url_raw( self::input( 'terms_url' ), array( 'https' ) );
					if ( ! $terms || ! wp_http_validate_url( $terms ) ) { throw new RuntimeException( 'Enter the HTTPS URL of the published affiliate terms.' ); }
					update_option( OLR_Account_Hub::OPTION_TERMS_URL, $terms );
					update_option( OLR_Account_Hub::OPTION_NOTIFICATION_EMAIL, sanitize_email( self::input( 'notification_email' ) ) );
					if ( '1' === self::input( 'enable_payouts' ) ) {
						$errors = OLR_Affiliate_Service::readiness();
						if ( $errors ) { update_option( 'olr_aff_payouts_enabled', false ); throw new RuntimeException( implode( ' ', $errors ) ); }
					}
					update_option( 'olr_aff_payouts_enabled', '1' === self::input( 'enable_payouts' ), false );
					break;
				case 'block': case 'unblock':
					OLR_Affiliate_Service::set_block( absint( self::input( 'user_id' ) ), 'block' === $action ); break;
				case 'approve_w9': case 'reject_w9':
					OLR_Affiliate_Service::review_document( absint( self::input( 'document_id' ) ), 'approve_w9' === $action, self::input( 'note' ) ); break;
				case 'paid':
					OLR_Affiliate_Service::complete( absint( self::input( 'request_id' ) ), self::input( 'confirmation' ) );
					$message = 'Zelle payment confirmation recorded.'; break;
				case 'reject':
					OLR_Affiliate_Service::reject( absint( self::input( 'request_id' ) ), self::input( 'confirmation' ) );
					$message = 'Request rejected. Reserved commissions were released.'; break;
				case 'refund_credit':
					OLR_Store_Credit::admin_refund( absint( self::input( 'order_id' ) ), self::input( 'credit_amount' ), self::input( 'operation_key' ) );
					$message = 'Store-credit refund recorded.'; break;
				default: throw new RuntimeException( 'Unknown account action.' );
			}
		} catch ( Throwable $e ) {
			$error = true;
			$message = $e instanceof RuntimeException ? $e->getMessage() : 'This operation could not be completed. Please contact support.';
		}
		set_transient( 'olr_aff_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 120 );
		wp_safe_redirect( $admin ? admin_url( 'admin.php?page=olr-affiliate-management' ) : self::account_url( in_array( $action, array( 'activate', 'prepare_code', 'choose_code' ), true ) ? 'affiliate' : 'payouts' ) );
		exit;
	}

	public static function ajax_prepare_code() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() ) { wp_send_json_error( array( 'message' => 'Sign in to prepare your referral code.' ), 403 ); }
		check_ajax_referer( 'olr_aff_prepare_code', 'olr_aff_nonce' );
		try { wp_send_json_success( array( 'code' => OLR_Affiliate_Service::prepare_code( get_current_user_id() ) ) ); }
		catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Your code could not be prepared. Please retry or contact support.' ), 409 ); }
	}
	public static function code_form() {
		echo '<div data-olr-code-setup data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"><p data-olr-code-message role="status">Prepare your referral code to share the first-order offer.</p>';
		self::form_start( 'prepare_code' );
		self::button( 'Prepare my code' );
		echo '</div>';
	}
	public static function code_editor() {
		echo '<details class="olr-code-editor"><summary>Choose your own code</summary>';
		self::form_start( 'choose_code' );
		echo '<label for="olr-custom-code">Your coupon code</label><input id="olr-custom-code" name="coupon_code" type="text" minlength="3" maxlength="32" pattern="[A-Za-z0-9_-]{3,32}" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="olr-code-help" required><p id="olr-code-help">Use 3–32 letters, numbers, hyphens or underscores. Codes must be available. Your previous codes keep working.</p>';
		self::button( 'Save my code' );
		echo '</details>';
	}

	public static function activation_panel() {
		$uid = get_current_user_id();
		$terms = get_option( OLR_Account_Hub::OPTION_TERMS_URL );
		ob_start();
		echo '<section class="olr-account-panel"><div class="olr-account-panel__header"><h2>AFFILIATE PROGRAM.</h2></div>';
		self::notice();
		if ( ! OLR_Affiliate_Service::member_eligible( $uid ) || ( OLR_Account_Hub::affiliate_id( $uid ) && ! OLR_Affiliate_Service::active( $uid ) ) ) {
			echo '<p>Affiliate activation is unavailable for this account. Contact support to review your eligibility. Your member account and orders remain available.</p>';
		} elseif ( ! $terms ) {
			echo '<p>Affiliate activation will be available once the program terms are published.</p>';
		} else {
			echo '<h3>START EARNING WITH OFF LABEL.</h3><p>Activate your affiliate access to get your referral code, share your link, and track commissions immediately.</p>';
			self::form_start( 'activate' );
			echo '<label class="olr-account-checkbox"><input type="checkbox" name="terms" value="1" required><span>I agree to the <a target="_blank" rel="noopener" href="' . esc_url( $terms ) . '">affiliate terms</a>.</span></label>';
			self::button( 'Activate affiliate account' );
		}
		echo '</section>';
		return ob_get_clean();
	}

	public static function payout_panel() {
		global $wpdb;
		$uid = get_current_user_id();
		$balance = OLR_Affiliate_Service::balance( $uid );
		$doc = OLR_Affiliate_Service::latest_document( $uid );
		$labels = array( 'pending' => 'Pending review', 'approved' => 'Approved', 'needs_replacement' => 'Needs replacement' );
		$available = 0;
		try { foreach ( OLR_Affiliate_Service::available_referrals( $uid ) as $row ) { $available += OLR_Affiliate_Service::cents( $row['amount'] ); } } catch ( Exception $e ) { /* Show unavailable, never fabricated earnings. */ }
		$ready = get_option( 'olr_aff_payouts_enabled' ) && ! OLR_Affiliate_Service::readiness();
		ob_start();
		echo '<section class="olr-account-panel olr-payout-panel"><div class="olr-account-panel__header"><h2>PAYOUTS &amp; STORE CREDIT.</h2></div>';
		self::notice();
		echo '<div class="olr-account-stat-grid olr-account-stat-grid--two"><div class="olr-account-stat"><strong>' . wp_kses_post( OLR_Affiliate_Service::money( $available ) ) . '</strong><span>Cleared commissions</span></div><div class="olr-account-stat"><strong>' . wp_kses_post( OLR_Affiliate_Service::money( $balance['balance'] - $balance['reserved'] ) ) . '</strong><span>Available store credit</span></div></div>';
		if ( $balance['reserved'] ) { echo '<p>' . wp_kses_post( OLR_Affiliate_Service::money( $balance['reserved'] ) ) . ' of your credit is reserved for an unpaid order.</p>'; }
		echo '<p>Both payout options require at least $50 in cleared commissions and a 30-day commission hold. Each request uses your full available commission balance.</p>';
		if ( ! $ready ) { echo '<div class="olr-account-notice">Payout setup is being completed. Your commissions remain tracked.</div>'; }
		echo '<div class="olr-payout-options"><article><h3>Store credit</h3><p>Convert commissions instantly. No W-9 required. Credit stays on your account until you spend it and can be used across multiple orders.</p>';
		if ( $ready && $available >= OLR_Affiliate_Service::MINIMUM ) {
			self::form_start( 'store_credit' );
			echo '<input type="hidden" name="operation_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><p>Convert ' . wp_kses_post( OLR_Affiliate_Service::money( $available ) ) . ' into store credit. This conversion cannot be withdrawn as cash.</p>';
			self::button( 'Convert to store credit' );
		} else { echo '<p>Conversion is available when payout setup is complete and cleared commissions reach $50.</p>'; }
		echo '</article><article><h3>Zelle</h3><p>Request a payment for monthly processing after your W-9 is approved. Payments are sent manually to your enrolled Zelle email or mobile number.</p>';
		if ( $ready && $available >= OLR_Affiliate_Service::MINIMUM && OLR_Affiliate_Service::tax_approved( $uid ) ) {
			self::form_start( 'zelle' );
			echo '<input type="hidden" name="operation_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><p><label>Recipient name<input name="zelle_name" autocomplete="name" maxlength="150" required></label></p><p><label>Enrolled email or US mobile number<input name="zelle_destination" maxlength="190" required></label></p><p>Request ' . wp_kses_post( OLR_Affiliate_Service::money( $available ) ) . '. Confirm the recipient details before submitting.</p>';
			self::button( 'Request Zelle payout' );
		} else { echo '<p>Zelle requests require an approved W-9 and at least $50 in cleared commissions.</p>'; }
		echo '</article></div><hr><h3>W-9 for Zelle</h3><p>Status: <strong>' . esc_html( $doc ? ( $labels[ $doc['status'] ] ?? 'Pending review' ) : 'Missing' ) . '</strong></p>';
		if ( $doc && $doc['note'] ) { echo '<p>' . esc_html( $doc['note'] ) . '</p>'; }
		try { OLR_Affiliate_Service::storage(); $storage = true; } catch ( Exception $e ) { $storage = false; }
		if ( $storage ) {
			echo '<p>Upload your completed, signed W-9 as a PDF up to 10 MB. Only authorized administrators can download it. Replacing an approved document pauses Zelle eligibility until the new document is approved.</p><p><a href="https://www.irs.gov/pub/irs-pdf/fw9.pdf" target="_blank" rel="noopener">Download the blank IRS W-9</a></p>';
			self::form_start( 'upload_w9', false, true );
			echo '<p><label>Signed W-9 PDF<input type="file" name="w9" accept="application/pdf,.pdf" required></label></p><label class="olr-account-checkbox"><input type="checkbox" name="signed" value="1" required><span>This is my completed and signed W-9.</span></label>';
			self::button( $doc ? 'Upload replacement W-9' : 'Upload W-9' );
		} else { echo '<p class="olr-account-notice">W-9 uploads are temporarily unavailable while secure storage is being set up. Please contact support before requesting a Zelle payout. Store credit does not require a W-9.</p>'; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OLR_Affiliate_Service::table( 'requests' ) . ' WHERE user_id=%d ORDER BY id DESC LIMIT 50', $uid ), ARRAY_A );
		echo '<h3>Payout history</h3>';
		self::history( $rows );
		$ledger = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OLR_Affiliate_Service::table( 'ledger' ) . ' WHERE user_id=%d AND currency=%s ORDER BY id DESC LIMIT 50', $uid, OLR_Affiliate_Service::currency() ), ARRAY_A );
		echo '<h3>Store-credit activity</h3><div class="olr-affiliate-table"><table><thead><tr><th>Date</th><th>Activity</th><th>Credit change</th><th>Reserved change</th><th>Order</th></tr></thead><tbody>';
		foreach ( $ledger as $entry ) { echo '<tr><td>' . esc_html( $entry['created_at'] ) . '</td><td>' . esc_html( ucwords( str_replace( '_', ' ', $entry['kind'] ) ) ) . '</td><td>' . wp_kses_post( OLR_Affiliate_Service::money( $entry['delta'] ) ) . '</td><td>' . wp_kses_post( OLR_Affiliate_Service::money( $entry['reserved_delta'] ) ) . '</td><td>' . esc_html( $entry['order_id'] ?: '—' ) . '</td></tr>'; }
		if ( ! $ledger ) { echo '<tr><td colspan="5">No store-credit activity yet.</td></tr>'; }
		echo '</tbody></table></div><p>Showing the latest 50 entries.</p></section>';
		return ob_get_clean();
	}
	private static function history( $rows ) {
		echo '<div class="olr-affiliate-table"><table><thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( 'zelle' === $row['method'] ? 'Zelle' : 'Store credit' ) . '</td><td>' . wp_kses_post( OLR_Affiliate_Service::money( $row['amount'] ) ) . '</td><td>' . esc_html( ucfirst( $row['status'] ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="4">No payout requests yet.</td></tr>'; }
		echo '</tbody></table></div>';
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		echo '<div class="wrap"><h1>Affiliate Management</h1>';
		self::notice();
		$errors = OLR_Affiliate_Service::readiness();
		echo '<h2>Program setup</h2><p>Instant activation. Store credit: immediate, $50 minimum, 30-day hold, no W-9. Zelle: monthly manual processing, same minimum and hold, approved W-9 required.</p>';
		foreach ( $errors as $error ) { echo '<div class="notice notice-warning inline"><p>' . esc_html( $error ) . '</p></div>'; }
		echo '<details><summary><strong>Private W-9 upload setup</strong></summary><p>The plugin update does not configure host storage. Ask your hosting administrator to provide a persistent writable directory and a separate key file outside all public web roots, with PHP Sodium and Fileinfo enabled.</p><p>Define <code>OLR_AFFILIATE_PRIVATE_DIR</code> and <code>OLR_AFFILIATE_W9_KEY_FILE</code> in <code>wp-config.php</code>. The key file must contain a base64-encoded, randomly generated 32-byte key. Keep this key backed up securely; never replace it while documents exist. See the plugin README for the complete setup instructions.</p><p>Keep tax documents and the key out of the Media Library, public uploads, and WordPress options. Uploads appear automatically once these checks pass. Payouts also require the enable-payouts setting.</p></details>';
		echo '<p><strong>On WordPress.com hosting:</strong> missing configuration constants do not indicate a Sodium failure. Ask WordPress.com support to confirm persistent, PHP-accessible storage and a separate key file outside the public document root. Then configure the confirmed paths through SFTP. Do not use temporary directories or public uploads. The plugin includes <code>W9-HOSTING-SETUP.md</code> with a ready-to-send support request and completion steps. <a href="https://wordpress.com/support/sftp/troubleshooting-sftp/" target="_blank" rel="noopener noreferrer">WordPress.com file access instructions</a>.</p>';
		if ( ! get_option( 'olr_aff_payouts_enabled' ) ) {
			self::form_start( 'prepare_tables', true );
			echo '<p>UAP may use MyISAM tables. Atomic activation and payouts require InnoDB. This setup action converts the required tables without deleting records or editing vendor plugin files.</p><label><input type="checkbox" name="backup_confirmed" value="1" required> A current database backup is available.</label><p></p>';
			self::button( 'Prepare transactional database storage' );
		}
		self::form_start( 'settings', true );
		echo '<p><label>Published terms URL <input class="regular-text" name="terms_url" type="url" required value="' . esc_attr( get_option( OLR_Account_Hub::OPTION_TERMS_URL ) ) . '"></label></p><p><label>Affiliate notification email <input class="regular-text" type="email" name="notification_email" value="' . esc_attr( get_option( OLR_Account_Hub::OPTION_NOTIFICATION_EMAIL, get_option( 'admin_email' ) ) ) . '"></label></p><p><label><input type="checkbox" name="enable_payouts" value="1" ' . checked( (bool) get_option( 'olr_aff_payouts_enabled' ), true, false ) . '> Enable payouts after completing the installation checks</label></p>';
		self::button( 'Save settings' );
		echo '<h2>Affiliate coupon discounts</h2><p>These percentages are customer discounts, separate from the affiliate commission rate configured in UAP. The default updates new and existing hub-managed coupons. Individual affiliate overrides are preserved. Other WooCommerce promotions are not changed.</p>';
		self::form_start( 'coupon_default', true );
		echo '<p><label>Default customer discount (%) <input name="discount_percent" type="number" min="0.01" max="100" step="0.01" required value="' . esc_attr( OLR_Affiliate_Coupons::default_percent() ) . '"></label></p>';
		self::button( 'Save default coupon discount' );
		echo '<h2>Member eligibility</h2><p>Block preserves the member account, affiliate record, and financial history. Restore permits activation and restores a suspended UAP record. Historical application decisions are retained.</p><form method="get"><input type="hidden" name="page" value="olr-affiliate-management"><label>Find member by email or name <input name="member_search" value="' . esc_attr( isset( $_GET['member_search'] ) && is_scalar( $_GET['member_search'] ) ? sanitize_text_field( wp_unslash( $_GET['member_search'] ) ) : '' ) . '"></label> <button class="button">Search</button></form>';
		$search = isset( $_GET['member_search'] ) && is_scalar( $_GET['member_search'] ) ? sanitize_text_field( wp_unslash( $_GET['member_search'] ) ) : '';
		$members = $search ? get_users( array( 'search' => '*' . $search . '*', 'search_columns' => array( 'user_email', 'display_name', 'user_login' ), 'number' => 30 ) ) : array();
		foreach ( $members as $member ) {
			echo '<p><strong>' . esc_html( $member->display_name . ' — ' . $member->user_email ) . '</strong> · ' . esc_html( OLR_Affiliate_Service::blocked( $member->ID ) ? 'Blocked' : ( OLR_Affiliate_Service::active( $member->ID ) ? 'Active affiliate' : 'Member / inactive affiliate' ) ) . '</p>';
			if ( OLR_Account_Hub::affiliate_id( $member->ID ) ) {
				echo '<p>Primary coupon: <strong>' . esc_html( OLR_Affiliate_Service::coupon_code( $member->ID ) ?: 'Not created yet' ) . '</strong>. Customer offer: ' . esc_html( OLR_Affiliate_Coupons::offer( $member->ID ) ) . ' off.</p>';
				self::form_start( 'coupon_override', true );
				echo '<input type="hidden" name="user_id" value="' . absint( $member->ID ) . '"><p><label>Individual customer discount (%) <input name="discount_percent" type="number" min="0.01" max="100" step="0.01" value="' . esc_attr( get_user_meta( $member->ID, OLR_Affiliate_Coupons::OVERRIDE_META, true ) ) . '" placeholder="Use default"></label></p><p>Leave blank to use the default. Saving applies to all active WooCommerce coupons assigned to this affiliate through UAP, including their previous codes. Use this override to retain an individual rate when the default changes.</p>';
				self::button( 'Save affiliate coupon discount' );
			}
			foreach ( array( 'block' => 'Block affiliate access', 'unblock' => 'Restore eligibility' ) as $action => $label ) {
				self::form_start( $action, true ); echo '<input type="hidden" name="user_id" value="' . absint( $member->ID ) . '">'; self::button( $label );
			}
		}
		$offset = max( 0, absint( $_GET['batch'] ?? 0 ) ) * 25;
		$documents = $wpdb->get_results( $wpdb->prepare( 'SELECT d.* FROM ' . OLR_Affiliate_Service::table( 'documents' ) . ' d WHERE NOT EXISTS (SELECT 1 FROM ' . OLR_Affiliate_Service::table( 'documents' ) . ' newer WHERE newer.user_id=d.user_id AND newer.id>d.id) ORDER BY d.id DESC LIMIT 25 OFFSET %d', $offset ), ARRAY_A );
		echo '<h2>W-9 review</h2><p>Review the completed form and signature before approving. Do not put tax IDs in notes. Notes are visible to the member.</p>';
		foreach ( $documents as $doc ) {
			$user = get_userdata( $doc['user_id'] );
			$url = wp_nonce_url( add_query_arg( 'olr_w9_download', $doc['id'], self::account_url() ), 'olr_w9_download_' . $doc['id'] );
			echo '<hr><h3>' . esc_html( $user ? $user->user_email : 'Deleted member #' . $doc['user_id'] ) . ' — ' . esc_html( $doc['status'] ) . '</h3><p><a href="' . esc_url( $url ) . '">Download protected W-9</a></p>';
			foreach ( array( 'approve_w9' => 'Approve W-9', 'reject_w9' => 'Request replacement' ) as $action => $label ) {
				self::form_start( $action, true ); echo '<input type="hidden" name="document_id" value="' . absint( $doc['id'] ) . '"><p><label>Review note <input class="regular-text" name="note" maxlength="500"></label></p>'; self::button( $label );
			}
		}
		$requests = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OLR_Affiliate_Service::table( 'requests' ) . " WHERE method='zelle' ORDER BY (status='pending') DESC,id DESC LIMIT 25 OFFSET %d", $offset ), ARRAY_A );
		echo '<h2>Zelle payout queue</h2><p>Process eligible requests during your monthly payout run. Verify recipient details and current commission eligibility before sending funds externally. Mark paid only after Zelle confirms the transfer.</p>';
		foreach ( $requests as $request ) {
			$details = json_decode( $request['details'], true );
			echo '<hr><h3>Request #' . absint( $request['id'] ) . ' · ' . wp_kses_post( OLR_Affiliate_Service::money( $request['amount'] ) ) . ' · ' . esc_html( $request['status'] ) . '</h3><p>' . esc_html( ( $details['name'] ?? '' ) . ' — ' . ( $details['destination'] ?? '' ) ) . '</p><p>Submitted: ' . esc_html( $request['created_at'] ) . ' · W-9: ' . ( OLR_Affiliate_Service::tax_approved( $request['user_id'] ) ? 'Approved' : 'Not approved' ) . '</p>';
			if ( 'pending' === $request['status'] ) {
				$eligible = true;
				try { OLR_Affiliate_Service::require_ready(); OLR_Affiliate_Service::validate_pending( $request ); }
				catch ( RuntimeException $e ) { $eligible = false; echo '<p><strong>Do not send:</strong> ' . esc_html( $e->getMessage() ) . '</p>'; }
				if ( $eligible ) { echo '<p>Current eligibility checks passed. Refresh this page immediately before sending.</p>'; }
				foreach ( array( 'paid' => 'Record completed Zelle payment', 'reject' => 'Reject and release commissions' ) as $action => $label ) {
					if ( 'paid' === $action && ! $eligible ) { continue; }
					self::form_start( $action, true ); echo '<input type="hidden" name="request_id" value="' . absint( $request['id'] ) . '"><p><label>' . ( 'paid' === $action ? 'Zelle confirmation/reference' : 'Reason' ) . ' <input name="confirmation" maxlength="190" required></label></p>'; self::button( $label );
				}
			} else { echo '<p>' . esc_html( $request['confirmation'] ) . '</p>'; }
		}
		echo '<p><a class="button" href="' . esc_url( add_query_arg( 'batch', max( 0, $offset / 25 - 1 ) ) ) . '">Previous 25</a> <a class="button" href="' . esc_url( add_query_arg( 'batch', $offset / 25 + 1 ) ) . '">Next 25</a></p>';
		echo '<h2>Return store credit for an order</h2><p>Use for a credit-only order refund or an explicit adjustment to the credit-funded portion. The amount cannot exceed unrefunded credit actually spent on the order. This does not refund cash.</p>';
		self::form_start( 'refund_credit', true );
		echo '<input type="hidden" name="operation_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><p><label>Order ID <input type="number" min="1" name="order_id" required></label></p><p><label>Credit amount <input type="number" min="0.01" step="0.01" name="credit_amount" required></label></p>';
		self::button( 'Refund store-credit portion' );
		echo '</div>';
	}

	private static function download() {
		$id = is_scalar( $_GET['olr_w9_download'] ) ? absint( $_GET['olr_w9_download'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'olr_w9_download_' . $id );
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OLR_Affiliate_Service::table( 'documents' ) . ' WHERE id=%d', $id ), ARRAY_A );
		try {
			if ( ! $doc || ! preg_match( '/^[a-f0-9]{48}$/', $doc['file_key'] ) ) { throw new RuntimeException( 'Document not found.' ); }
			list( $dir, $key ) = OLR_Affiliate_Service::storage();
			$path = $dir . DIRECTORY_SEPARATOR . $doc['file_key'] . '.bin';
			if ( ! is_file( $path ) || is_link( $path ) ) { throw new RuntimeException( 'Document unavailable.' ); }
			$data = file_get_contents( $path );
			$plain = sodium_crypto_secretbox_open( substr( $data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
			if ( false === $plain ) { throw new RuntimeException( 'Document integrity check failed.' ); }
			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="W9-' . $id . '.pdf"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( "Content-Security-Policy: sandbox; default-src 'none'" );
			header( 'Content-Length: ' . strlen( $plain ) );
			update_user_meta( get_current_user_id(), '_olr_w9_last_access', array( 'document_id' => $id, 'at' => current_time( 'mysql' ) ) );
			echo $plain; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authenticated binary download.
			sodium_memzero( $plain );
			exit;
		} catch ( Throwable $e ) { wp_die( 'Protected document could not be downloaded. Check storage and key availability.', '', array( 'response' => 404 ) ); }
	}

	/** Retire vendor entry points, including direct requests outside /account/. */
	public static function guard_native_routes() {
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$deny = in_array( $action, array( 'olr_submit_affiliate_application', 'olr_affiliate_application_action', 'uap_make_wp_user_affiliate_from_public', 'uap_make_wp_user_affiliate', 'uap_approve_affiliate', 'uap_delete_wallet_item_via_ajax' ), true );
		if ( 0 === strpos( $action, 'uap_' ) && preg_match( '/payment|payout|wallet/', $action ) && ! preg_match( '/^uap_ajax_get_|generate_csv/', $action ) ) { $deny = true; }
		if ( isset( $_POST['olr_account_action'] ) && 'submit_affiliate_application' === $_POST['olr_account_action'] ) { $deny = true; }
		if ( isset( $_POST['uapcheck'], $_POST['referrals'] ) || isset( $_POST['uap_payment_type'] ) || isset( $_POST['uap_affiliate_payment_type'] ) ) { $deny = true; }
		$subtab = isset( $_REQUEST['uap_aff_subtab'] ) && is_scalar( $_REQUEST['uap_aff_subtab'] ) ? sanitize_key( wp_unslash( $_REQUEST['uap_aff_subtab'] ) ) : '';
		if ( in_array( $subtab, array( 'wallet', 'payments_settings', 'payment_settings', 'request_payment', 'payout_request' ), true ) ) {
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { $deny = true; }
			else { wp_safe_redirect( self::account_url() ); exit; }
		}
		if ( $deny ) { wp_die( 'Use Affiliate Management or the account payout page for this action.', '', array( 'response' => 403 ) ); }
	}
	public static function native_shortcode( $return, $tag ) {
		if ( in_array( $tag, array( 'uap-register', 'uap-user-become-affiliate' ), true ) ) {
			return '<p><a href="' . esc_url( self::account_url( 'affiliate' ) ) . '">Activate affiliate access in your account</a></p>';
		}
		return $return;
	}
	public static function guard_rest( $result, $server, $request ) {
		if ( preg_match( '~^/ultimate-affiliates-pro/[^/]+/(?:make-user-affiliate|approve-affiliate|.*(?:payment|payout|wallet))~', $request->get_route() ) ) {
			return new WP_Error( 'olr_managed_action', 'Use Account Hub for this action.', array( 'status' => 403 ) );
		}
		return $result;
	}
	public static function redirect_native_admin() {
		if ( ( $_GET['page'] ?? '' ) === 'olr-affiliate-applications' || ( ( $_GET['page'] ?? '' ) === 'ultimate_affiliates_pro' && in_array( $_GET['tab'] ?? '', array( 'payments', 'payouts' ), true ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=olr-affiliate-management' ) ); exit;
		}
	}
	public static function setup_notice() {
		if ( current_user_can( 'manage_options' ) && ! get_option( 'olr_aff_payouts_enabled' ) ) {
			echo '<div class="notice notice-warning"><p>Account Hub: payouts are disabled until private W-9 storage and settlement checks are complete. Configure <a href="' . esc_url( admin_url( 'admin.php?page=olr-affiliate-management' ) ) . '">Affiliate Management</a>.</p></div>';
		}
	}
	public static function validate_coupon( $valid, $coupon, $discounts ) {
		$owner = (int) $coupon->get_meta( '_olr_affiliate_owner' );
		if ( ! $owner ) { return $valid; }
		if ( ! OLR_Affiliate_Service::active( $owner ) || $owner === get_current_user_id() ) { throw new Exception( 'This affiliate code is not available for this order.' ); }
		$email = WC()->customer ? WC()->customer->get_billing_email() : '';
		if ( self::has_prior_order( get_current_user_id(), $email ) ) { throw new Exception( 'This affiliate offer is for your first order only.' ); }
		return $valid;
	}
	private static function has_prior_order( $uid, $email ) {
		$args = array( 'status' => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ), 'limit' => 1, 'return' => 'ids' );
		if ( $uid && wc_get_orders( array_merge( $args, array( 'customer_id' => $uid ) ) ) ) { return true; }
		return $email && (bool) wc_get_orders( array_merge( $args, array( 'billing_email' => $email ) ) );
	}
	public static function validate_first_order( $data, $errors ) {
		if ( ! WC()->cart ) { return; }
		foreach ( WC()->cart->get_coupons() as $coupon ) {
			$owner = (int) $coupon->get_meta( '_olr_affiliate_owner' );
			if ( ! $owner ) { continue; }
			$user = get_userdata( $owner );
			$email = $data['billing_email'] ?? '';
			if ( ! OLR_Affiliate_Service::active( $owner ) || $owner === get_current_user_id() || ( $user && strtolower( $email ) === strtolower( $user->user_email ) ) || self::has_prior_order( get_current_user_id(), $email ) ) {
				$errors->add( 'olr_first_order', 'The affiliate offer is for a new customer’s first order and cannot be used for self-referrals.' );
			}
		}
	}
}
