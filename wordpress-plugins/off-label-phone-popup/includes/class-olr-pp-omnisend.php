<?php
defined( 'ABSPATH' ) || exit;

final class OLR_PP_Omnisend {
	const KEY_OPTION = 'olr_phone_popup_api_key';
	const BASE = 'https://api.omnisend.com/api/';
	const API_VERSION = '2026-03-15';

	public static function key() {
		return defined( 'OLR_PHONE_POPUP_OMNISEND_KEY' ) ? (string) OLR_PHONE_POPUP_OMNISEND_KEY : (string) get_option( self::KEY_OPTION, '' );
	}

	/** No API response bodies, contact data or credentials are exposed in errors. */
	public static function request( $method, $path, $body = null ) {
		if ( ! self::key() ) {
			return new WP_Error( 'configuration', 'Add an Omnisend API key with contacts.read and contacts.write access.' );
		}
		$args = array( 'method' => $method, 'timeout' => 8, 'redirection' => 0, 'headers' => array(
			'Authorization' => 'Omnisend-API-Key ' . self::key(), 'Omnisend-Version' => self::API_VERSION,
			'Accept' => 'application/json', 'Content-Type' => 'application/json',
		) );
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'temporary', 'Omnisend could not be reached. A retry is scheduled.' );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || 408 === $code || $code >= 500 ) {
			return new WP_Error( 'temporary', 'Omnisend is busy or unavailable. A retry is scheduled.' );
		}
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'configuration', 'Omnisend rejected the API key or its permissions.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'rejected', 'Omnisend rejected the request (HTTP ' . (int) $code . '). Review the contact in Omnisend.' );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) ? $json : new WP_Error( 'temporary', 'Omnisend returned an unexpected response. A retry is scheduled.' );
	}

	private static function find( $field, $value ) {
		$result = self::request( 'GET', 'contacts?' . http_build_query( array( $field => $value, 'limit' => 2 ), '', '&', PHP_QUERY_RFC3986 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['contacts'] ) || ! is_array( $result['contacts'] ) ) {
			return new WP_Error( 'temporary', 'Omnisend returned an unexpected contact list.' );
		}
		if ( count( $result['contacts'] ) > 1 ) {
			return new WP_Error( 'conflict', 'Multiple Omnisend contacts match. Resolve the contact identity before retrying.' );
		}
		$contact = $result['contacts'][0] ?? array();
		if ( $contact && ( empty( $contact['id'] ) || ! isset( $contact['identifiers'] ) || ! is_array( $contact['identifiers'] ) ) ) {
			return new WP_Error( 'temporary', 'Omnisend returned incomplete contact details.' );
		}
		return $contact;
	}

	private static function phone_identifier( $contact, $phone ) {
		foreach ( $contact['identifiers'] ?? array() as $identifier ) {
			if ( 'phone' === ( $identifier['type'] ?? '' ) && $phone === ( $identifier['id'] ?? '' ) ) {
				return $identifier;
			}
		}
		return array();
	}

	private static function synchronize( $user, $phone, $state ) {
		$by_email = self::find( 'email', $user->user_email );
		if ( is_wp_error( $by_email ) ) {
			return $by_email;
		}
		$by_phone = self::find( 'phone', $phone );
		if ( is_wp_error( $by_phone ) ) {
			return $by_phone;
		}
		// Never claim a phone-only contact or merge two contacts without proof of ownership.
		if ( $by_phone && ( ! $by_email || $by_phone['id'] !== $by_email['id'] ) ) {
			return new WP_Error( 'conflict', 'This phone belongs to another Omnisend contact. Resolve it in Omnisend before retrying.' );
		}
		foreach ( $by_email['identifiers'] ?? array() as $identifier ) {
			if ( 'phone' === ( $identifier['type'] ?? '' ) && $phone !== ( $identifier['id'] ?? '' ) ) {
				return new WP_Error( 'conflict', 'The Omnisend contact has a different phone number. Review it in Omnisend before retrying.' );
			}
		}
		$identifier = self::phone_identifier( $by_email, $phone );
		$status = $identifier['channels']['sms']['status'] ?? '';
		if ( 'unsubscribed' === $status ) {
			return new WP_Error( 'unsubscribed', 'This contact has unsubscribed from SMS. Use Omnisend’s resubscription process; this plugin will not override it.' );
		}
		if ( 'subscribed' === $status ) {
			return true; // A retry after a successful but interrupted response must not send again.
		}
		$identifiers = array( array(
			'type' => 'phone', 'id' => $phone, 'source' => 'api', 'sendWelcomeMessage' => true,
			'channels' => array( 'sms' => array( 'status' => 'subscribed', 'statusChangedAt' => $state['consent']['createdAt'] ) ),
			'consent' => $state['consent'],
		) );
		if ( ! $by_email ) {
			// Omit the email channel entirely: SMS consent is not email consent.
			$identifiers[] = array( 'type' => 'email', 'id' => $user->user_email, 'sendWelcomeMessage' => false );
		}
		$payload = array( 'identifiers' => $identifiers );
		$result = self::request( $by_email ? 'PATCH' : 'POST', $by_email ? 'contacts/' . rawurlencode( $by_email['id'] ) : 'contacts', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Omnisend can ignore an identifier collision. Never treat HTTP 200 alone as success.
		$verified = self::find( 'email', $user->user_email );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$phone_record = self::phone_identifier( $verified, $phone );
		if ( 'subscribed' !== ( $phone_record['channels']['sms']['status'] ?? '' ) ) {
			return new WP_Error( 'unconfirmed', 'Omnisend did not confirm the phone subscription. Review the contact before retrying.' );
		}
		return true;
	}

	public static function sync( $user_id, $submission_id ) {
		$user_id = (int) $user_id;
		if ( ! OLR_Phone_Popup::lock( $user_id ) ) {
			if ( ! wp_next_scheduled( OLR_Phone_Popup::CRON, array( $user_id, $submission_id ) ) ) {
				wp_schedule_single_event( time() + 120, OLR_Phone_Popup::CRON, array( $user_id, $submission_id ) );
			}
			return;
		}
		try {
			$state = OLR_Phone_Popup::state( $user_id );
			if ( empty( $state['consent'] ) || $submission_id !== ( $state['id'] ?? '' ) || 'pending' !== ( $state['status'] ?? '' ) ) {
				return;
			}
			$user = get_userdata( $user_id );
			$phone = (string) get_user_meta( $user_id, OLR_Phone_Popup::PHONE_META, true );
			$s = OLR_Phone_Popup::settings();
			$result = null;
			if ( ! $s['enabled'] || ! $s['sms'] || ! self::key() ) {
				$result = new WP_Error( 'configuration', 'SMS signup is disabled or its API key is missing. No subscription was sent.' );
			} elseif ( ! $user || ! hash_equals( $state['phone_hash'], OLR_Phone_Popup::phone_hash( $phone ) ) || ! hash_equals( $state['email_hash'] ?? '', OLR_Phone_Popup::phone_hash( strtolower( $user->user_email ) ) ) ) {
				$result = new WP_Error( 'changed', 'Account details changed after consent. This submission will not be sent.' );
			} elseif ( strtotime( $state['consent']['createdAt'] ) < time() - DAY_IN_SECONDS ) {
				$result = new WP_Error( 'expired', 'This signup is more than 24 hours old. Collect a new opt-in through Omnisend.' );
			} else {
				$state['attempts'] = (int) $state['attempts'] + 1;
				// Persist before the external request, so interrupted requests retain their attempt count.
				update_user_meta( $user_id, OLR_Phone_Popup::STATE_META, wp_slash( $state ) );
				$result = self::synchronize( $user, $phone, $state );
			}
			wp_clear_scheduled_hook( OLR_Phone_Popup::CRON, array( $user_id, $submission_id ) );
			if ( is_wp_error( $result ) ) {
				$state['error'] = $result->get_error_message();
				$state['error_code'] = $result->get_error_code();
				$retryable = 'temporary' === $result->get_error_code() && $state['attempts'] < 5;
				$state['status'] = $retryable ? 'pending' : 'failed';
				if ( $retryable ) {
					$delays = array( 120, 600, 1800, 7200, 21600 );
					$scheduled = wp_schedule_single_event( time() + $delays[ min( 4, max( 0, $state['attempts'] - 1 ) ) ], OLR_Phone_Popup::CRON, array( $user_id, $submission_id ), true );
					if ( is_wp_error( $scheduled ) || false === $scheduled ) {
						$state['status'] = 'failed';
						$state['error'] = 'WordPress could not schedule a retry. Check WP-Cron, then retry here.';
					}
				}
			} else {
				$state['status'] = 'synced';
				$state['synced_at'] = gmdate( 'c' );
				unset( $state['error'], $state['error_code'] );
			}
			update_user_meta( $user_id, OLR_Phone_Popup::STATE_META, wp_slash( $state ) );
		} finally {
			OLR_Phone_Popup::unlock( $user_id );
		}
	}
}
