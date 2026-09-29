<?php
/** Administrator-only TaxBandits onboarding. No customer, tax-approval or payout writes. */
defined( 'ABSPATH' ) || exit;

final class OLR_TaxBandits_Sandbox {
	const CONFIG = 'olr_tb_sandbox_config';
	const STATE = 'olr_tb_sandbox_state';
	const AUTH = 'https://testoauth.expressauth.net/v2/tbsauth';
	const API = 'https://testapi.taxbandits.com/v1.7.3/';
	private static $token = '';

	public static function boot() { add_action( 'admin_post_olr_tb_sandbox', array( __CLASS__, 'dispatch' ) ); }
	public static function url() { return admin_url( 'admin.php?page=olr-affiliate-management#olr-taxbandits' ); }
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Administrator access required.' ); }
	}
	private static function key() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) { throw new RuntimeException( 'PHP Sodium is required to protect the API credentials.' ); }
		// Require host configuration: wp_salt() can fall back to a database secret.
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			if ( ! defined( $name ) || ! is_string( constant( $name ) ) || strlen( constant( $name ) ) < 32 || false !== strpos( constant( $name ), 'put your unique phrase here' ) ) { throw new RuntimeException( 'Host-configured WordPress AUTH_KEY and AUTH_SALT are required to encrypt API credentials outside the database. Ask the host to configure the WordPress security salts.' ); }
		}
		return hash_hmac( 'sha256', 'off-label-taxbandits-sandbox-v1', AUTH_KEY . AUTH_SALT, true );
	}
	private static function seal( $data ) {
		$key = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( wp_json_encode( $data ), $nonce, $key ) );
	}
	private static function unseal( $data ) {
		$key = self::key();
		$raw = base64_decode( (string) $data, true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) { throw new RuntimeException( 'Saved sandbox settings are unavailable. Enter all three credentials again.' ); }
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		if ( false === $plain ) { throw new RuntimeException( 'Saved sandbox settings cannot be decrypted. Enter all three credentials again after a WordPress salt change.' ); }
		$result = json_decode( $plain, true );
		sodium_memzero( $plain );
		if ( ! is_array( $result ) ) { throw new RuntimeException( 'Invalid sandbox settings. Enter all three credentials again.' ); }
		return $result;
	}
	private static function config() {
		$value = get_option( self::CONFIG );
		if ( ! $value ) { throw new RuntimeException( 'Save your three sandbox credentials first.' ); }
		return self::unseal( $value );
	}
	private static function save_state( $state ) { update_option( self::STATE, $state, false ); }
	private static function guid( $value ) { return is_string( $value ) && (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value ); }
	private static function b64( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }

	/** Fixed sandbox hosts, verified TLS, no redirects, no raw API errors or payload logs. */
	private static function http( $url, $method, $headers, $body = null ) {
		$args = array( 'method' => $method, 'timeout' => 25, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 262144, 'headers' => $headers );
		$args['headers']['Accept'] = 'application/json';
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); $args['headers']['Content-Type'] = 'application/json'; }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { throw new RuntimeException( 'TaxBandits sandbox could not be reached. Check outbound HTTPS access and retry. No approval was recorded.' ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || ! empty( $data['Errors'] ) || ( isset( $data['StatusCode'] ) && ( (int) $data['StatusCode'] < 200 || (int) $data['StatusCode'] >= 300 ) ) ) {
			if ( 401 === $code || 403 === $code ) { throw new RuntimeException( 'TaxBandits rejected sandbox authentication/access. Check all three sandbox credentials, API access and the server clock.' ); }
			// Codes are safe diagnostics. Provider messages may echo a tax ID or credential.
			throw new RuntimeException( 'TaxBandits sandbox returned an unsuccessful response (HTTP ' . $code . '). Check the request in your TaxBandits sandbox API logs. No approval was recorded.' );
		}
		return $data;
	}
	private static function token() {
		if ( self::$token ) { return self::$token; }
		$c = self::config();
		$unsigned = self::b64( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) ) . '.' . self::b64( wp_json_encode( array( 'iss' => $c['client_id'], 'sub' => $c['client_id'], 'aud' => $c['user_token'], 'iat' => time() ) ) );
		$jws = $unsigned . '.' . self::b64( hash_hmac( 'sha256', $unsigned, $c['client_secret'], true ) );
		$data = self::http( self::AUTH, 'GET', array( 'Authentication' => $jws ) );
		$token = $data['AccessToken'] ?? '';
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9_.-]{20,8192}$/D', $token ) ) { throw new RuntimeException( 'TaxBandits did not return a valid sandbox access token.' ); }
		// Access tokens are request-local only, never stored in WordPress options.
		self::$token = $token;
		return $token;
	}
	private static function api( $path, $method = 'GET', $body = null, $query = array() ) {
		if ( ! in_array( $path, array( 'Business/Create', 'Business/Get', 'FormW9/RequestByUrl', 'FormW9/Status', 'FormW9/Get' ), true ) ) { throw new LogicException( 'Unsupported sandbox endpoint.' ); }
		$url = self::API . $path;
		if ( $query ) { $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); }
		return self::http( $url, $method, array( 'Authorization' => 'Bearer ' . self::token() ), $body );
	}
	private static function form_url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\\\\]/', $url ) ) { throw new RuntimeException( 'TaxBandits returned an invalid sandbox form link.' ); }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || 'testlinks.taxbandits.io' !== strtolower( $p['host'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) ) { throw new RuntimeException( 'TaxBandits returned an unexpected form host. The link was blocked; check the sandbox API response.' ); }
		return $url;
	}

	/** Only TaxBandits' documented sandbox S3 buckets; never accept arbitrary S3 tenants. */
	private static function pdf_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) { throw new RuntimeException( 'TaxBandits did not include a PDF link. Check Settings > W-9/W-8 Settings > PDF Preferences in the sandbox, then retry.' ); }
		if ( 0 !== strpos( $url, 'https://' ) ) { throw new RuntimeException( 'TaxBandits returned an encrypted or unsupported PDF reference. This connector currently supports HTTPS PDF links. Keep encryption enabled; encrypted PDF retrieval needs a separate integration.' ); }
		$p = wp_parse_url( $url );
		if ( strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\\\\]/', $url ) || ! is_array( $p ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) { throw new RuntimeException( 'TaxBandits returned an invalid PDF link.' ); }
		$host = strtolower( $p['host'] ?? '' );
		$path = rawurldecode( $p['path'] ?? '' );
		$virtual = preg_match( '/^taxbandits-(?:sb|dev)-api\.s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com$/D', $host );
		$bucket_path = preg_match( '/^s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com$/D', $host ) && preg_match( '#^/taxbandits-(?:sb|dev)-api/#D', $path );
		if ( ! ( $virtual || $bucket_path ) || preg_match( '#(?:^|/)\.{1,2}(?:/|$)|[\x00-\x20\\\\]#', $path ) || ! preg_match( '/\.pdf$/iD', $path ) ) { throw new RuntimeException( 'TaxBandits returned an unexpected sandbox PDF location. The link was blocked. Report this message so the provider location can be verified.' ); }
		return $url;
	}
	private static function document_url( $state ) {
		// Get returns the latest completed form, so bind its response to the selected submission.
		$data = self::api( 'FormW9/Get', 'GET', null, array( 'PayeeRef' => $state['test']['reference'], 'BusinessId' => $state['business_id'] ) );
		if ( ( $data['PayeeRef'] ?? '' ) !== $state['test']['reference'] || strtolower( $data['Requester']['BusinessId'] ?? '' ) !== $state['business_id'] || strtolower( $data['SubmissionId'] ?? '' ) !== $state['test']['submission'] || 'COMPLETED' !== ( $data['W9Status'] ?? '' ) ) { throw new RuntimeException( 'The completed PDF did not match this test business, recipient and submission. No review was recorded.' ); }
		// Discard all recipient details. Neither the response nor the PDF URL is persisted.
		$reference = $data['PdfUrl'] ?? null;
		$c = self::config();
		if ( ! empty( $c['pdf'] ) ) {
			OLR_TaxBandits_Pdf::object_key( $reference, $c['pdf']['s3_bucket'] );
			return array( 'private_reference' => $reference );
		}
		if ( is_string( $reference ) && 0 !== strpos( $reference, 'https://' ) ) { throw new RuntimeException( 'This W-9 requires the four TaxBandits PDF credentials. Save them in step 1b, then select View completed W-9 again. Keep PDF encryption enabled.' ); }
		return array( 'pdf_url' => self::pdf_url( $reference ) );
	}

	/** All commands are additionally protected by the POST handler's capability and nonce. */
	public static function perform( $command, $input = array() ) {
		self::guard();
		global $wpdb;
		$lock = 'olr_tb_sb_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 30 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { throw new RuntimeException( 'Another sandbox operation is running. Wait for it to finish, then retry.' ); }
		self::$token = '';
		try { return self::run( $command, $input ); }
		finally { self::$token = ''; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	private static function run( $command, $input ) {
		$state = get_option( self::STATE, array() );
		if ( 'save' === $command ) {
			self::key();
			$c = array();
			foreach ( array( 'client_id', 'client_secret', 'user_token' ) as $field ) {
				$value = isset( $input[ $field ] ) && is_string( $input[ $field ] ) ? trim( $input[ $field ] ) : '';
				if ( ! preg_match( '/^[\x21-\x7e]{8,2048}$/D', $value ) ) { throw new RuntimeException( 'Paste all three sandbox credentials. Spaces and line breaks are not accepted.' ); }
				$c[ $field ] = $value;
			}
			// Preserve test identity for a secret rotation in the same API account only.
			$old = array();
			try { $old = self::config(); } catch ( RuntimeException $e ) { /* First setup or salt recovery. */ }
			if ( ( $old['client_id'] ?? '' ) !== $c['client_id'] || ( $old['user_token'] ?? '' ) !== $c['user_token'] ) { $state = array(); }
			elseif ( ! empty( $old['pdf'] ) ) { $c['pdf'] = $old['pdf']; }
			$state['connected_at'] = '';
			update_option( self::CONFIG, self::seal( $c ), false );
			self::save_state( $state );
			return 'Sandbox credentials saved. Click Test connection next.';
		}
		if ( 'disconnect' === $command ) {
			delete_option( self::CONFIG );
			delete_option( self::STATE );
			return 'Sandbox connection removed locally. Test records remain in TaxBandits. Member records and payouts were not changed.';
		}
		$c = self::config();
		if ( 'save_pdf' === $command ) {
			$c['pdf'] = OLR_TaxBandits_Pdf::credentials( $input );
			update_option( self::CONFIG, self::seal( $c ), false );
			return 'TaxBandits PDF credentials saved. Your completed test is preserved. Select View completed W-9 in step 4.';
		}
		if ( 'connect' === $command ) {
			$state['connected_at'] = '';
			self::save_state( $state );
			self::token();
			$state['connected_at'] = current_time( 'mysql' );
			self::save_state( $state );
			return 'Sandbox authentication succeeded. Next, prepare the test business.';
		}
		if ( 'business' === $command ) {
			$business = isset( $input['business_id'] ) && is_string( $input['business_id'] ) ? trim( $input['business_id'] ) : '';
			if ( '' !== $business && ! self::guid( $business ) ) { throw new RuntimeException( 'Enter a valid sandbox Business ID, or leave it blank to create the sample business.' ); }
			if ( ! $business && ! empty( $state['business_id'] ) ) { return 'The test business is already prepared.'; }
			if ( $business ) {
				$data = self::api( 'Business/Get', 'GET', null, array( 'BusinessId' => $business ) );
				if ( strtolower( $data['Business']['BusinessId'] ?? '' ) !== strtolower( $business ) ) { throw new RuntimeException( 'The sandbox business could not be verified.' ); }
			} else {
				if ( empty( $state['payer_ref'] ) ) { $state['payer_ref'] = 'OLR-SB-' . wp_generate_uuid4(); self::save_state( $state ); }
				// Synthetic payer using TaxBandits' published example EIN/address; no real identity.
				$data = self::api( 'Business/Create', 'POST', array(
					'BusinessNm' => 'Off Label Sandbox Test Only', 'PayerRef' => $state['payer_ref'],
					'IsDefaultBusiness' => false, 'IsEIN' => true, 'EINorSSN' => '23-3456789',
					'Email' => 'offlabel-sandbox@example.com', 'ContactNm' => 'Sandbox Tester', 'Phone' => '2025550147',
					'IsForeign' => false, 'USAddress' => array( 'Address1' => '3576 AIRPORT WAY', 'Address2' => 'UNIT 9', 'City' => 'FAIRBANKS', 'State' => 'AK', 'ZipCd' => '99709' ),
				) );
				$business = $data['BusinessId'] ?? '';
				if ( ! self::guid( $business ) ) { throw new RuntimeException( 'TaxBandits did not return a Business ID. Check the sandbox console before retrying; an existing test Business ID can be entered here.' ); }
			}
			if ( ( $state['business_id'] ?? '' ) !== strtolower( $business ) ) { unset( $state['test'] ); }
			$state['business_id'] = strtolower( $business );
			self::save_state( $state );
			return 'Sandbox business prepared. Create a test W-9 next.';
		}
		if ( empty( $state['business_id'] ) ) { throw new RuntimeException( 'Prepare the sandbox business first.' ); }
		if ( 'start' === $command || 'replace' === $command ) {
			if ( '1' !== ( $input['test_only'] ?? '' ) ) { throw new RuntimeException( 'Confirm that you will use made-up test information only.' ); }
			if ( 'replace' === $command ) {
				if ( empty( $state['test']['reference'] ) || ! hash_equals( $state['test']['reference'], (string) ( $input['reference'] ?? '' ) ) ) { throw new RuntimeException( 'The test changed. Refresh before requesting a replacement.' ); }
				if ( ! in_array( $state['test']['review'] ?? '', array( 'approved', 'needs_replacement' ), true ) ) { throw new RuntimeException( 'Finish reviewing the existing test before starting a replacement.' ); }
				unset( $state['test'] );
			}
			if ( empty( $state['test'] ) ) {
				$state['test'] = array( 'reference' => 'OLR-SB-' . wp_generate_uuid4(), 'submission' => '', 'status' => 'NOT_STARTED', 'review' => 'missing', 'created_at' => current_time( 'mysql' ) );
				self::save_state( $state ); // Retries retain the same PayeeRef if a remote call is interrupted.
			}
			if ( ! empty( $state['test']['link'] ) ) { return 'Your existing test link is ready below. Repeated clicks do not create another test.'; }
			$data = self::api( 'FormW9/RequestByUrl', 'POST', array(
				'Requester' => array( 'BusinessId' => $state['business_id'] ),
				'Recipient' => array( 'PayeeRef' => $state['test']['reference'], 'Name' => 'Sandbox Tester', 'Email' => 'offlabel-sandbox@example.com', 'IsTINMatching' => false ),
				'Customization' => array( 'InterviewFlow' => true, 'PrimaryColor' => '#111111', 'SecondaryColor' => '#F5F3EF', 'ShowDownloadPage' => true ),
				'RedirectUrls' => array( 'ReturnUrl' => self::url(), 'CancelUrl' => self::url(), 'RedirectTime' => 5 ),
			) );
			if ( ( $data['PayeeRef'] ?? '' ) !== $state['test']['reference'] || ! self::guid( $data['SubmissionId'] ?? '' ) ) { throw new RuntimeException( 'The sandbox form response did not match this test request.' ); }
			$link = self::form_url( $data['W9Url'] ?? '' );
			$state['test']['submission'] = strtolower( $data['SubmissionId'] );
			$state['test']['link'] = self::seal( array( 'url' => $link ) );
			$state['test']['status'] = 'URL_GENERATED';
			$state['test']['review'] = 'awaiting_submission';
			self::save_state( $state );
			return 'Test W-9 created. Open the test form below, complete it with made-up information, then Check test status.';
		}
		if ( ! in_array( $command, array( 'status', 'view', 'approve', 'reject' ), true ) ) { throw new RuntimeException( 'Unknown sandbox action.' ); }
		if ( empty( $state['test']['submission'] ) || ! hash_equals( $state['test']['reference'], (string) ( $input['reference'] ?? '' ) ) ) { throw new RuntimeException( 'The test changed or is incomplete. Refresh this page.' ); }
		$data = self::api( 'FormW9/Status', 'GET', null, array( 'PayeeRef' => $state['test']['reference'], 'BusinessId' => $state['business_id'], 'SubmissionId' => $state['test']['submission'] ) );
		if ( ( $data['PayeeRef'] ?? '' ) !== $state['test']['reference'] || strtolower( $data['Requester']['BusinessId'] ?? '' ) !== $state['business_id'] ) { throw new RuntimeException( 'The provider status did not match this sandbox recipient and business.' ); }
		$matches = array_values( array_filter( (array) ( $data['Status'] ?? array() ), function ( $row ) use ( $state ) { return is_array( $row ) && strtolower( $row['SubmissionId'] ?? '' ) === $state['test']['submission']; } ) );
		if ( count( $matches ) !== 1 ) { throw new RuntimeException( 'A unique matching submission was not returned by TaxBandits.' ); }
		$status = $matches[0]['W9Status'] ?? '';
		$allowed = array( 'URL_GENERATED', 'ORDER_CREATED', 'SCHEDULED', 'SENT', 'OPENED', 'COMPLETED', 'AWAITING_TIN_CERTIFICATE', 'COMPLETED_AND_TIN_MATCH_INPROGRESS', 'INVALID', 'BOUNCED', 'ORDER_NOT_CREATED' );
		if ( ! in_array( $status, $allowed, true ) ) { throw new RuntimeException( 'TaxBandits returned an unrecognized W-9 status. No approval was recorded.' ); }
		$old_status = $state['test']['status'];
		$state['test']['status'] = $status;
		$state['test']['checked_at'] = current_time( 'mysql' );
		if ( 'COMPLETED' !== $status ) { $state['test']['review'] = 'INVALID' === $status ? 'needs_replacement' : 'awaiting_submission'; unset( $state['test']['reviewed_by'], $state['test']['reviewed_at'] ); }
		elseif ( 'COMPLETED' !== $old_status || ! in_array( $state['test']['review'], array( 'approved', 'needs_replacement' ), true ) ) { $state['test']['review'] = 'pending_review'; }
		self::save_state( $state );
		if ( 'view' === $command ) {
			if ( 'COMPLETED' !== $status ) { throw new RuntimeException( 'Only a completed and signed test W-9 has a reviewable PDF.' ); }
			$document = self::document_url( $state );
			if ( isset( $document['private_reference'] ) ) { return array( 'pdf_body' => OLR_TaxBandits_Pdf::fetch( $document['private_reference'], $c['pdf'] ) ); }
			return $document;
		}
		if ( 'approve' === $command || 'reject' === $command ) {
			if ( 'COMPLETED' !== $status ) { throw new RuntimeException( 'Only a completed and signed test W-9 can be reviewed.' ); }
			if ( '1' !== ( $input['reviewed'] ?? '' ) ) { throw new RuntimeException( 'Confirm you reviewed the completed test document in TaxBandits.' ); }
			self::document_url( $state ); // A newer or mismatched PDF must not receive the old form's approval.
			$state['test']['review'] = 'approve' === $command ? 'approved' : 'needs_replacement';
			$state['test']['reviewed_by'] = get_current_user_id();
			$state['test']['reviewed_at'] = current_time( 'mysql' );
			self::save_state( $state );
			return 'Sandbox review recorded. This is a test only and cannot authorize real payouts.';
		}
		return 'Sandbox status checked with TaxBandits: ' . $status . '. No automatic approval was applied.';
	}

	public static function dispatch() {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Administrator POST required.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'olr_tb_sandbox' );
		$input = array();
		foreach ( array( 'command', 'client_id', 'client_secret', 'user_token', 'business_id', 'test_only', 'reviewed', 'reference', 'aws_access_key', 'aws_secret_key', 'base64_key', 's3_bucket' ) as $key ) { $input[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; }
		try {
			$message = self::perform( $input['command'], $input ); $error = false;
			if ( 'view' === $input['command'] && is_array( $message ) ) {
				while ( ob_get_level() ) { if ( ! ob_end_clean() ) { break; } }
				nocache_headers();
				header( 'Cache-Control: private, no-store, max-age=0' );
				header( 'Referrer-Policy: no-referrer' );
				header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
				if ( isset( $message['pdf_body'] ) ) {
					header( 'Content-Type: application/pdf' );
					header( 'Content-Disposition: inline; filename="test-w9.pdf"' );
					header( 'X-Content-Type-Options: nosniff' );
					header( "Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'" );
					header( 'Content-Length: ' . strlen( $message['pdf_body'] ) );
					echo $message['pdf_body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated PDF bytes, not HTML.
					exit;
				}
				$url = self::pdf_url( $message['pdf_url'] );
				// External redirect is deliberate and restricted by pdf_url(), never user input.
				wp_redirect( $url, 303, 'Account Hub sandbox W-9 review' ); exit;
			}
		}
		catch ( Throwable $e ) { $message = $e instanceof RuntimeException ? $e->getMessage() : 'The sandbox operation failed. Refresh and retry.'; $error = true; }
		set_transient( 'olr_aff_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 120 );
		wp_safe_redirect( self::url() ); exit;
	}
	private static function form( $command, $label, $fields = '' ) {
		echo '<form method="post"' . ( 'view' === $command ? ' target="_blank" rel="noopener noreferrer"' : '' ) . ' action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0"><input type="hidden" name="action" value="olr_tb_sandbox"><input type="hidden" name="command" value="' . esc_attr( $command ) . '">';
		wp_nonce_field( 'olr_tb_sandbox' );
		echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal markup with individually escaped data.
		echo '<p><button class="button button-secondary" type="submit">' . esc_html( $label ) . '</button></p></form>';
	}
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$state = get_option( self::STATE, array() );
		echo '<section id="olr-taxbandits" style="box-sizing:border-box;overflow-wrap:anywhere;max-width:900px;padding:20px;border:1px solid #b9b6af;background:#f7f5f1;margin:20px 0"><h2>TaxBandits sandbox setup</h2><p><strong>Administrator testing only.</strong> Use made-up information. Test approvals never enable real Zelle payouts. This connection does not need private WordPress document storage. Production payout setup notices do not prevent these sandbox tests.</p>';
		$saved = (bool) get_option( self::CONFIG );
		try { if ( $saved ) { self::config(); } else { self::key(); } }
		catch ( RuntimeException $e ) { echo '<p role="alert">' . esc_html( $e->getMessage() ) . '</p>'; }
		echo '<h3>1. Save sandbox credentials</h3><p>Copy these from TaxBandits Sandbox &rarr; Settings &rarr; API Credentials. Credentials are encrypted using this site\'s WordPress authentication salts and are never displayed after saving. Re-enter all three to replace them.</p>';
		echo '<p>Credentials: <strong>' . ( $saved ? 'Saved' : 'Not saved' ) . '</strong></p>';
		$fields = '';
		foreach ( array( 'client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'user_token' => 'User Token' ) as $key => $label ) {
			$fields .= '<p><label for="olr-tb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" style="box-sizing:border-box;max-width:100%" type="password" id="olr-tb-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="" autocomplete="new-password" spellcheck="false" maxlength="2048" required></p>';
		}
		self::form( 'save', 'Save sandbox credentials', $fields );
		if ( $saved ) {
			self::form( 'connect', 'Test connection' );
			if ( ! empty( $state['connected_at'] ) ) { echo '<p>Last successful authentication: ' . esc_html( $state['connected_at'] ) . ' (site time).</p>'; }
			$pdf_saved = false;
			try { $pdf_saved = ! empty( self::config()['pdf'] ); } catch ( RuntimeException $e ) { /* Main credentials diagnostic shown above. */ }
			echo '<h3>1b. Encrypted PDF access</h3><p>Copy these four additional values from TaxBandits Sandbox &rarr; Settings &rarr; Credentials. These are provided by TaxBandits; you do not need your own AWS account. Keep PDF encryption enabled. Values stay blank after saving.</p><p>PDF credentials: <strong>' . ( $pdf_saved ? 'Saved' : 'Not saved' ) . '</strong></p>';
			$pdf_fields = '';
			foreach ( array( 'aws_access_key' => 'AWS AccessKey', 'aws_secret_key' => 'AWS SecretKey', 'base64_key' => 'Base64Key (PDF key)', 's3_bucket' => 'S3 Bucket Name' ) as $key => $label ) {
				$pdf_fields .= '<p><label for="olr-tb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" style="box-sizing:border-box;max-width:100%" type="password" id="olr-tb-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="" autocomplete="new-password" spellcheck="false" maxlength="2048" required></p>';
			}
			self::form( 'save_pdf', 'Save PDF credentials', $pdf_fields );
			echo '<h3>2. Prepare a test business</h3><p>Leave the field blank to create our clearly labeled sample payer in your sandbox. If one already exists, paste its Business ID. This does not send email or file any tax forms.</p>';
			self::form( 'business', 'Prepare test business', '<p><label>Existing sandbox Business ID (optional)<br><input class="regular-text" style="box-sizing:border-box;max-width:100%" name="business_id" maxlength="36" value="' . esc_attr( $state['business_id'] ?? '' ) . '"></label></p>' );
			if ( ! empty( $state['business_id'] ) ) {
				echo '<h3>3. Complete a test W-9</h3><p>Open the generated form in TaxBandits. Fill and sign it using made-up details, then return here. TaxBandits stores the form; Account Hub stores only the test reference and status.</p>';
				self::form( 'start', 'Create / resume test W-9', '<label><input type="checkbox" name="test_only" value="1" required> I will use made-up test information only.</label>' );
				$t = $state['test'] ?? array();
				if ( ! empty( $t['link'] ) ) {
					try { if ( 'COMPLETED' !== $t['status'] ) { $link = self::form_url( self::unseal( $t['link'] )['url'] ?? '' ); echo '<p><a class="button button-primary" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">Open test W-9</a></p>'; } }
					catch ( RuntimeException $e ) { echo '<p>' . esc_html( $e->getMessage() ) . '</p>'; }
					echo '<p>Reference: <code>' . esc_html( $t['reference'] ) . '</code><br>Provider status: <strong>' . esc_html( $t['status'] ) . '</strong><br>Test review: <strong>' . esc_html( str_replace( '_', ' ', $t['review'] ) ) . '</strong></p>';
					$ref = '<input type="hidden" name="reference" value="' . esc_attr( $t['reference'] ) . '">';
					self::form( 'status', 'Check test status', $ref );
					echo '<h3>4. Review the test submission</h3><p>Once the status is COMPLETED, use View completed W-9 below. The signed PDF opens in a new tab (or downloads, depending on your browser). Encrypted PDFs are retrieved privately from TaxBandits and passed to your browser without being saved in WordPress. Return here after reviewing the document and signature.</p>';
					if ( 'COMPLETED' === $t['status'] ) {
						self::form( 'view', 'View completed W-9', $ref );
						$review = $ref . '<label><input type="checkbox" name="reviewed" value="1" required> I reviewed the completed test document and signature in TaxBandits.</label>';
						self::form( 'approve', 'Approve test W-9', $review );
						self::form( 'reject', 'Request test replacement', $review );
					}
					if ( in_array( $t['review'], array( 'approved', 'needs_replacement' ), true ) ) { self::form( 'replace', 'Start replacement test', $ref . '<label><input type="checkbox" name="test_only" value="1" required> Start a new test using made-up information. The previous test approval will no longer apply.</label>' ); }
				}
			}
			echo '<details><summary>Remove sandbox connection</summary><p>Removes saved credentials and local test status. Remote TaxBandits records remain in your sandbox.</p>';
			self::form( 'disconnect', 'Remove saved sandbox credentials' ); echo '</details>';
		}
		echo '<p><strong>After testing:</strong> production access requires TaxBandits approval and pricing agreement, plus the member-facing production integration. This version intentionally has no live API switch.</p></section>';
	}
}
