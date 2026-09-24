<?php
/**
 * Plugin Name: Off Label Phone Popup
 * Description: Collect member phone numbers on selected pages, save to billing_phone, and optionally connect SMS opt-ins to Omnisend.
 * Version: 1.0.1
 * Author: Off Label Research
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: off-label-phone-popup
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-olr-pp-omnisend.php';
require_once __DIR__ . '/includes/class-olr-pp-admin.php';

final class OLR_Phone_Popup {

	const VERSION = '1.0.1';
	const OPTION = 'olr_phone_popup_settings';
	const PHONE_META = 'billing_phone';
	const STATE_META = '_olr_pp_state';
	const DISMISS_META = '_olr_pp_dismiss_until';
	const CRON = 'olr_pp_retry_sync';
	private static $locks = array();

	public static function boot() {
		add_action( 'plugins_loaded', array( __CLASS__, 'upgrade_copy' ) );
		add_action( 'admin_menu', array( 'OLR_PP_Admin', 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'OLR_PP_Admin', 'assets' ) );
		add_action( 'admin_post_olr_pp_settings', array( 'OLR_PP_Admin', 'save' ) );
		add_action( 'admin_post_olr_pp_connection', array( 'OLR_PP_Admin', 'connection' ) );
		add_action( 'admin_post_olr_pp_retry', array( 'OLR_PP_Admin', 'retry' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
		foreach ( array( 'status', 'save', 'dismiss' ) as $action ) {
			add_action( 'wp_ajax_olr_pp_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( self::CRON, array( 'OLR_PP_Omnisend', 'sync' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_links' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'privacy_eraser' ) );
	}

	public static function defaults() {
		return array(
			'copy_version' => '1.0.1',
			'enabled' => 0, 'pages' => array(), 'all_pages' => 0, 'delay' => 3, 'cooldown' => 7,
			'audience' => 'missing', 'local_numbers' => 1, 'sms' => 0,
			'title' => 'Join the Off Label Text Club.',
			'body' => 'Get exclusive text club deals and special offers sent straight to your phone.',
			'button' => 'Join the Text Club',
			'consent' => 'I agree to receive recurring automated marketing texts from Off Label Research at the number provided. Consent is not a condition of purchase. Message frequency varies. Message and data rates may apply. Reply STOP to unsubscribe or HELP for help.',
			'terms_url' => '', 'privacy_url' => '',
		);
	}

	/** Update the original saved wording once, preserving independently customized copy. */
	public static function upgrade_copy() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || ! $saved || isset( $saved['copy_version'] ) ) {
			return;
		}
		$original = array(
			'title' => 'Let’s keep in touch.',
			'body' => 'Add your mobile number to your account so we can keep your details up to date.',
			'button' => 'Save my number',
		);
		$defaults = self::defaults();
		foreach ( $original as $key => $value ) {
			if ( isset( $saved[ $key ] ) && $value === $saved[ $key ] ) {
				$saved[ $key ] = $defaults[ $key ];
			}
		}
		$saved['copy_version'] = '1.0.1';
		update_option( self::OPTION, $saved, false );
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function revision() {
		return hash( 'sha256', wp_json_encode( self::settings() ) );
	}

	public static function state( $user_id ) {
		$value = get_user_meta( $user_id, self::STATE_META, true );
		return is_array( $value ) ? $value : array();
	}

	public static function phone_hash( $phone ) {
		return hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
	}

	/** Format validation only; this does not verify ownership or mobile capability. */
	public static function normalize_phone( $raw, $allow_local = true ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 40 || ! preg_match( '/^[+0-9() .\-]+$/D', trim( $raw ) ) ) {
			return new WP_Error( 'phone', 'Enter a phone number without letters or an extension.' );
		}
		$phone = preg_replace( '/[() .\-]/', '', trim( $raw ) );
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}
		if ( $allow_local && preg_match( '/^1?[2-9][0-9]{2}[2-9][0-9]{6}$/D', $phone ) ) {
			$phone = '+' . ( 10 === strlen( $phone ) ? '1' : '' ) . $phone;
		}
		if ( ! preg_match( '/^\+[1-9][0-9]{7,14}$/D', $phone ) || ( 0 === strpos( $phone, '+1' ) && ! preg_match( '/^\+1[2-9][0-9]{2}[2-9][0-9]{6}$/D', $phone ) ) ) {
			return new WP_Error( 'phone', 'Enter a valid number with its country code, such as +1 415 555 0123.' );
		}
		return $phone;
	}

	public static function configured() {
		$s = self::settings();
		return $s['enabled'] && ( $s['all_pages'] || $s['pages'] ) && ( ! $s['sms'] || ( OLR_PP_Omnisend::key() && $s['terms_url'] && $s['privacy_url'] && $s['consent'] ) );
	}

	public static function selected_page( $id ) {
		$s = self::settings();
		return $id > 0 && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) && ( $s['all_pages'] || in_array( (int) $id, array_map( 'intval', $s['pages'] ), true ) );
	}

	public static function current_page() {
		return function_exists( 'is_shop' ) && is_shop() ? (int) wc_get_page_id( 'shop' ) : (int) get_queried_object_id();
	}

	public static function active_page() {
		return ! is_admin() && is_user_logged_in() && self::configured() && self::selected_page( self::current_page() ) && ( is_page() || ( function_exists( 'is_shop' ) && is_shop() ) );
	}

	public static function no_cache() {
		if ( self::active_page() ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}

	public static function assets() {
		if ( ! self::active_page() ) {
			return;
		}
		wp_enqueue_style( 'olr-phone-popup', plugins_url( 'assets/popup.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'olr-phone-popup', plugins_url( 'assets/popup.js', __FILE__ ), array(), self::VERSION, true );
		wp_localize_script( 'olr-phone-popup', 'olrPhonePopup', array(
			'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'olr_phone_popup' ),
			'page' => self::current_page(), 'revision' => self::revision(), 'delay' => (int) self::settings()['delay'],
		) );
	}

	public static function footer() {
		if ( self::active_page() ) {
			self::render_dialog( self::settings() );
		}
	}

	public static function render_dialog( $settings, $preview = false ) {
		require __DIR__ . '/templates/popup.php';
	}

	/** Local capture history is not presented as a live Omnisend subscription status. */
	public static function eligible( $user_id ) {
		$s = self::settings();
		$phone = trim( (string) get_user_meta( $user_id, self::PHONE_META, true ) );
		if ( (int) get_user_meta( $user_id, self::DISMISS_META, true ) > time() ) {
			return false;
		}
		if ( 'missing' === $s['audience'] || ! $s['sms'] ) {
			return '' === $phone;
		}
		$state = self::state( $user_id );
		// Do not nag prior opt-ins, including people who subsequently unsubscribe in Omnisend.
		return empty( $state['consent'] );
	}

	private static function request_user() {
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() || ! check_ajax_referer( 'olr_phone_popup', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Your session expired. Refresh the page and sign in again.' ), 403 );
		}
		$page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
		$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		if ( ! self::configured() || ! self::selected_page( $page ) || ! hash_equals( self::revision(), $revision ) ) {
			wp_send_json_error( array( 'message' => 'This form has changed. Refresh the page to continue.' ), 409 );
		}
		return get_current_user_id(); // Never accept a user ID or email from the browser.
	}

	public static function ajax_status() {
		$user_id = self::request_user();
		$visible = self::eligible( $user_id );
		wp_send_json_success( array( 'visible' => $visible, 'phone' => $visible ? (string) get_user_meta( $user_id, self::PHONE_META, true ) : '' ) );
	}

	public static function ajax_dismiss() {
		$user_id = self::request_user();
		update_user_meta( $user_id, self::DISMISS_META, time() + DAY_IN_SECONDS * (int) self::settings()['cooldown'] );
		wp_send_json_success();
	}

	/** Serialize submissions and retries for an account. Unique option insertion is atomic. */
	public static function lock( $user_id ) {
		global $wpdb;
		$key = 'olr_pp_lock_' . (int) $user_id;
		$old = get_option( $key );
		if ( $old && (int) $old < time() - 180 ) {
			// Compare-and-delete prevents two stale-lock recoveries deleting a new owner's lock.
			$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => (string) $old ) );
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$token = time() . '|' . wp_generate_uuid4();
		if ( add_option( $key, $token, '', false ) ) {
			self::$locks[ $user_id ] = $token;
			return true;
		}
		return false;
	}

	public static function unlock( $user_id ) {
		global $wpdb;
		if ( ! isset( self::$locks[ $user_id ] ) ) {
			return;
		}
		$key = 'olr_pp_lock_' . (int) $user_id;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => self::$locks[ $user_id ] ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		unset( self::$locks[ $user_id ] );
	}

	public static function ajax_save() {
		$user_id = self::request_user();
		$raw = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '';
		$consent = isset( $_POST['consent'] ) && '1' === $_POST['consent'];
		$result = self::capture( $user_id, $raw, $consent, absint( $_POST['page'] ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'field' => $result->get_error_code() ), 422 );
		}
		wp_send_json_success( $result );
	}

	public static function capture( $user_id, $raw, $consent, $page ) {
		$s = self::settings();
		$phone = self::normalize_phone( $raw, ! empty( $s['local_numbers'] ) );
		if ( is_wp_error( $phone ) ) {
			return $phone;
		}
		if ( $s['sms'] && ! $consent ) {
			return new WP_Error( 'consent', 'Please check the SMS consent box to join the text list, or choose Not now.' );
		}
		if ( ! self::lock( $user_id ) ) {
			return new WP_Error( 'busy', 'Your number is being saved. Please try again in a moment.' );
		}
		try {
			$state = self::state( $user_id );
			$existing = trim( (string) get_user_meta( $user_id, self::PHONE_META, true ) );
			// A repeated request must not issue another subscription or reward.
			if ( isset( $state['phone_hash'] ) && hash_equals( $state['phone_hash'], self::phone_hash( $phone ) ) && $existing === $phone && ( ! $s['sms'] || ! empty( $state['consent'] ) ) ) {
				return self::success_message( $state );
			}
			if ( ! self::eligible( $user_id ) ) {
				return new WP_Error( 'ineligible', 'Your account already has a saved number, or this offer has been dismissed. Refresh the page to continue.' );
			}
			// A stale tab must not overwrite a number added through checkout or Account Details.
			if ( $existing && 'missing' === $s['audience'] ) {
				return new WP_Error( 'changed', 'Your account details have changed. Refresh the page to continue.' );
			}
			update_user_meta( $user_id, self::PHONE_META, $phone );
			if ( (string) get_user_meta( $user_id, self::PHONE_META, true ) !== $phone ) {
				return new WP_Error( 'save', 'We could not save your number. Please try again.' );
			}
			$state = array( 'id' => wp_generate_uuid4(), 'status' => $s['sms'] ? 'pending' : 'saved', 'phone_hash' => self::phone_hash( $phone ), 'saved_at' => gmdate( 'c' ), 'attempts' => 0 );
			if ( $s['sms'] ) {
				$state['email_hash'] = self::phone_hash( strtolower( get_userdata( $user_id )->user_email ) );
				$state['consent'] = array(
					'createdAt' => gmdate( 'c' ), 'source' => 'off-label-phone-popup',
					'ip' => filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '',
					'userAgent' => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 512 ),
				);
				$state['disclosure'] = $s['consent'];
				$state['terms_url'] = $s['terms_url'];
				$state['privacy_url'] = $s['privacy_url'];
				$state['source_url'] = get_permalink( $page );
			}
			update_user_meta( $user_id, self::STATE_META, wp_slash( $state ) );
			update_user_meta( $user_id, '_olr_pp_saved_at', time() );
			delete_user_meta( $user_id, self::DISMISS_META );
			if ( function_exists( 'UM' ) && is_object( UM()->user() ) && method_exists( UM()->user(), 'remove_cache' ) ) {
				UM()->user()->remove_cache( $user_id );
			}
		} finally {
			self::unlock( $user_id );
		}
		if ( $s['sms'] ) {
			// Durable fallback survives a request timeout; the worker verifies the saved consent again.
			wp_schedule_single_event( time() + 120, self::CRON, array( $user_id, $state['id'] ) );
			OLR_PP_Omnisend::sync( $user_id, $state['id'] );
		}
		return self::success_message( self::state( $user_id ) );
	}

	public static function success_message( $state ) {
		$message = 'Your mobile number is saved to your account.';
		if ( 'synced' === ( $state['status'] ?? '' ) ) {
			$message .= ' You’re on the text list. Look out for a message from us.';
		} elseif ( 'pending' === ( $state['status'] ?? '' ) ) {
			$message .= ' Your text signup is still processing. You don’t need to submit again.';
		} elseif ( ! empty( $state['consent'] ) ) {
			$message .= ' We couldn’t complete the text signup. Please contact us for help.';
		}
		return array( 'message' => $message );
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=olr-phone-popup' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function deactivate() {
		wp_unschedule_hook( self::CRON );
	}

	public static function privacy_exporter( $exporters ) {
		$exporters['olr-phone-popup'] = array( 'exporter_friendly_name' => 'Off Label Phone Popup', 'callback' => array( __CLASS__, 'export_data' ) );
		return $exporters;
	}

	public static function export_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user && 1 === (int) $page ) {
			$state = self::state( $user->ID );
			if ( $state ) {
				$data[] = array( 'group_id' => 'olr-phone-popup', 'group_label' => 'Phone popup', 'item_id' => 'phone-popup-' . $user->ID, 'data' => array(
					array( 'name' => 'Account phone (billing_phone)', 'value' => get_user_meta( $user->ID, self::PHONE_META, true ) ),
					array( 'name' => 'Capture and consent record', 'value' => wp_json_encode( $state ) ),
				) );
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function privacy_eraser( $erasers ) {
		$erasers['olr-phone-popup'] = array( 'eraser_friendly_name' => 'Off Label Phone Popup', 'callback' => array( __CLASS__, 'erase_data' ) );
		return $erasers;
	}

	public static function erase_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			if ( ! self::lock( $user->ID ) ) {
				return array( 'items_removed' => false, 'items_retained' => true, 'messages' => array( 'Phone signup is processing. Retry erasure after it finishes.' ), 'done' => true );
			}
			$state = self::state( $user->ID );
			if ( ! empty( $state['id'] ) ) {
				wp_clear_scheduled_hook( self::CRON, array( $user->ID, $state['id'] ) );
			}
			$removed = delete_user_meta( $user->ID, self::STATE_META );
			$removed = delete_user_meta( $user->ID, self::DISMISS_META ) || $removed;
			$removed = delete_user_meta( $user->ID, '_olr_pp_saved_at' ) || $removed;
			self::unlock( $user->ID );
		}
		return array( 'items_removed' => (bool) $removed, 'items_retained' => false, 'messages' => array( 'Billing data is managed by WooCommerce. Erase or unsubscribe the Omnisend contact separately when required.' ), 'done' => true );
	}
}

OLR_Phone_Popup::boot();
register_deactivation_hook( __FILE__, array( 'OLR_Phone_Popup', 'deactivate' ) );
