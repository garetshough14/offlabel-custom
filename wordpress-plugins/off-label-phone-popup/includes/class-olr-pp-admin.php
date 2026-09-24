<?php
defined( 'ABSPATH' ) || exit;

final class OLR_PP_Admin {
	public static function menu() {
		add_menu_page( 'Phone Popup', 'Phone Popup', 'manage_options', 'olr-phone-popup', array( __CLASS__, 'page' ), 'dashicons-smartphone', 58 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_olr-phone-popup' !== $hook ) {
			return;
		}
		$base = plugins_url( '../assets/', __FILE__ );
		wp_enqueue_style( 'olr-pp-admin', $base . 'admin.css', array(), OLR_Phone_Popup::VERSION );
		wp_enqueue_style( 'olr-phone-popup', $base . 'popup.css', array(), OLR_Phone_Popup::VERSION );
		wp_enqueue_script( 'olr-pp-admin', $base . 'admin.js', array(), OLR_Phone_Popup::VERSION, true );
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to manage Phone Popup.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect( $message, $error = false ) {
		set_transient( 'olr_pp_notice_' . get_current_user_id(), array( 'text' => $message, 'error' => $error ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=olr-phone-popup' ) );
		exit;
	}

	public static function sanitize( $raw ) {
		$defaults = OLR_Phone_Popup::defaults();
		$s = $defaults;
		foreach ( array( 'enabled', 'all_pages', 'local_numbers', 'sms' ) as $key ) {
			$s[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
		}
		$s['pages'] = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['pages'] ?? array() ) ), function ( $id ) {
			return 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id );
		} ) ) );
		$s['delay'] = min( 60, max( 0, (int) ( is_scalar( $raw['delay'] ?? null ) ? $raw['delay'] : 3 ) ) );
		$s['cooldown'] = min( 365, max( 1, (int) ( is_scalar( $raw['cooldown'] ?? null ) ? $raw['cooldown'] : 7 ) ) );
		$s['audience'] = 'all' === ( $raw['audience'] ?? '' ) ? 'all' : 'missing';
		foreach ( array( 'title' => 100, 'body' => 500, 'button' => 60, 'consent' => 1500 ) as $key => $max ) {
			$value = is_string( $raw[ $key ] ?? null ) ? sanitize_textarea_field( $raw[ $key ] ) : '';
			$s[ $key ] = $value ? mb_substr( $value, 0, $max ) : $defaults[ $key ];
		}
		foreach ( array( 'terms_url', 'privacy_url' ) as $key ) {
			$s[ $key ] = is_string( $raw[ $key ] ?? null ) ? esc_url_raw( $raw[ $key ], array( 'https' ) ) : '';
		}
		return $s;
	}

	public static function save() {
		self::authorize( 'olr_pp_settings' );
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$s = self::sanitize( $raw );
		if ( ! defined( 'OLR_PHONE_POPUP_OMNISEND_KEY' ) ) {
			if ( ! empty( $_POST['remove_key'] ) ) {
				delete_option( OLR_PP_Omnisend::KEY_OPTION );
			} elseif ( isset( $_POST['api_key'] ) && is_string( $_POST['api_key'] ) && trim( $_POST['api_key'] ) ) {
				$key = trim( wp_unslash( $_POST['api_key'] ) );
				if ( ! preg_match( '/^[\x21-\x7e]{8,512}$/D', $key ) ) {
					self::redirect( 'Settings were not saved. The API key contains invalid characters.', true );
				}
				update_option( OLR_PP_Omnisend::KEY_OPTION, $key, false );
			}
		}
		$errors = array();
		if ( $s['enabled'] && ! $s['all_pages'] && ! $s['pages'] ) {
			$errors[] = 'Choose at least one published page.';
		}
		if ( $s['enabled'] && $s['sms'] && ( ! OLR_PP_Omnisend::key() || ! $s['terms_url'] || ! $s['privacy_url'] ) ) {
			$errors[] = 'SMS signup needs an API key and HTTPS links to your SMS terms and privacy policy.';
		}
		if ( $errors ) {
			$s['enabled'] = 0;
		}
		update_option( OLR_Phone_Popup::OPTION, $s, false );
		self::redirect( $errors ? 'Settings saved with the popup switched off. ' . implode( ' ', $errors ) : 'Settings saved.', (bool) $errors );
	}

	public static function connection() {
		self::authorize( 'olr_pp_connection' );
		$result = OLR_PP_Omnisend::request( 'GET', 'contacts?limit=1' );
		self::redirect( is_wp_error( $result ) ? $result->get_error_message() : 'Omnisend connection works. Contacts can be read. Also enable contacts.write on this API key for signups.', is_wp_error( $result ) );
	}

	public static function retry() {
		self::authorize( 'olr_pp_retry' );
		$user_id = isset( $_POST['member'] ) && is_scalar( $_POST['member'] ) ? absint( $_POST['member'] ) : 0;
		if ( get_transient( 'olr_pp_manual_retry_' . $user_id ) ) {
			self::redirect( 'Please wait 30 seconds before retrying this account again.', true );
		}
		if ( ! OLR_Phone_Popup::lock( $user_id ) ) {
			self::redirect( 'This account is already being processed. Try again shortly.', true );
		}
		$state = OLR_Phone_Popup::state( $user_id );
		$valid = ! empty( $state['consent'] ) && in_array( $state['status'] ?? '', array( 'pending', 'failed' ), true ) && ! in_array( $state['error_code'] ?? '', array( 'unsubscribed', 'changed', 'expired' ), true );
		if ( $valid ) {
			$state['status'] = 'pending';
			update_user_meta( $user_id, OLR_Phone_Popup::STATE_META, wp_slash( $state ) );
			set_transient( 'olr_pp_manual_retry_' . $user_id, 1, 30 );
		}
		OLR_Phone_Popup::unlock( $user_id );
		if ( ! $valid ) {
			self::redirect( 'This record cannot be retried. Review its status below.', true );
		}
		OLR_PP_Omnisend::sync( $user_id, $state['id'] );
		$state = OLR_Phone_Popup::state( $user_id );
		self::redirect( 'synced' === $state['status'] ? 'The SMS subscription is confirmed in Omnisend.' : ( $state['error'] ?? 'The signup is queued.' ), 'synced' !== $state['status'] );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = OLR_Phone_Popup::settings();
		$notice = get_transient( 'olr_pp_notice_' . get_current_user_id() );
		delete_transient( 'olr_pp_notice_' . get_current_user_id() );
		$pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) );
		$activity_page = max( 1, absint( $_GET['pp_page'] ?? 1 ) );
		$members = new WP_User_Query( array( 'number' => 15, 'paged' => $activity_page, 'meta_key' => '_olr_pp_saved_at', 'orderby' => 'meta_value_num', 'order' => 'DESC' ) );
		require dirname( __DIR__ ) . '/templates/admin.php';
	}
}
