<?php
/** WordPress 7.1 nonce functions, unmodified; GPL-2.0-or-later.
 * Source: https://github.com/WordPress/WordPress/blob/7.1/wp-includes/pluggable.php
 * Only environment dependencies are stubbed by ajax-worker.php.
 */
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		if ( -1 === $action ) {
			_doing_it_wrong( __FUNCTION__, __( 'You should specify an action to be verified by using the first parameter.' ), '4.7.0' );
		}

		$nonce = '';

		if ( $query_arg && isset( $_REQUEST[ $query_arg ] ) ) {
			$nonce = $_REQUEST[ $query_arg ];
		} elseif ( isset( $_REQUEST['_ajax_nonce'] ) ) {
			$nonce = $_REQUEST['_ajax_nonce'];
		} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = $_REQUEST['_wpnonce'];
		}

		$result = wp_verify_nonce( $nonce, $action );

		/**
		 * Fires once the Ajax request has been validated or not.
		 *
		 * @since 2.1.0
		 *
		 * @param string    $action The Ajax nonce action.
		 * @param false|int $result False if the nonce is invalid, 1 if the nonce is valid and generated between
		 *                          0-12 hours ago, 2 if the nonce is valid and generated between 12-24 hours ago.
		 */
		do_action( 'check_ajax_referer', $action, $result );

		if ( $stop && false === $result ) {
			if ( wp_doing_ajax() ) {
				wp_die( -1, 403 );
			} else {
				die( '-1' );
			}
		}

		return $result;
	}

	function wp_nonce_tick( $action = -1 ) {
		/**
		 * Filters the lifespan of nonces in seconds.
		 *
		 * @since 2.5.0
		 * @since 6.1.0 Added `$action` argument to allow for more targeted filters.
		 *
		 * @param int        $lifespan Lifespan of nonces in seconds. Default 86,400 seconds, or one day.
		 * @param string|int $action   The nonce action, or -1 if none was provided.
		 */
		$nonce_life = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );

		return ceil( time() / ( $nonce_life / 2 ) );
	}

	function wp_verify_nonce( $nonce, $action = -1 ) {
		$nonce = (string) $nonce;
		$user  = wp_get_current_user();
		$uid   = (int) $user->ID;
		if ( ! $uid ) {
			/**
			 * Filters whether the user who generated the nonce is logged out.
			 *
			 * @since 3.5.0
			 *
			 * @param int        $uid    ID of the nonce-owning user.
			 * @param string|int $action The nonce action, or -1 if none was provided.
			 */
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		if ( empty( $nonce ) ) {
			return false;
		}

		$token = wp_get_session_token();
		$i     = wp_nonce_tick( $action );

		// Nonce generated 0-12 hours ago.
		$expected = substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 1;
		}

		// Nonce generated 12-24 hours ago.
		$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 2;
		}

		/**
		 * Fires when nonce verification fails.
		 *
		 * @since 4.4.0
		 *
		 * @param string     $nonce  The invalid nonce.
		 * @param string|int $action The nonce action.
		 * @param WP_User    $user   The current user object.
		 * @param string     $token  The user's session token.
		 */
		do_action( 'wp_verify_nonce_failed', $nonce, $action, $user, $token );

		// Invalid nonce.
		return false;
	}

	function wp_create_nonce( $action = -1 ) {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			/** This filter is documented in wp-includes/pluggable.php */
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		$token = wp_get_session_token();
		$i     = wp_nonce_tick( $action );

		return substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
	}

