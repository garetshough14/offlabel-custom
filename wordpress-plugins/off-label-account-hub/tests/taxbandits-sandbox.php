<?php
/** Real disposable WordPress integration with synthetic, intercepted TaxBandits responses. */
// Explicit test-only host secrets; never use these fixed test strings on a real site.
if ( ! defined( 'AUTH_KEY' ) ) { define( 'AUTH_KEY', str_repeat( 'qa-key-', 10 ) ); }
if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', str_repeat( 'qa-salt-', 10 ) ); }
require __DIR__ . '/integration.php';

$sb_admin = wp_create_user( 'tb_admin_' . bin2hex( random_bytes( 4 ) ), wp_generate_password( 32 ) );
( new WP_User( $sb_admin ) )->set_role( 'administrator' );
wp_set_current_user( $sb_admin );
delete_option( OLR_TaxBandits_Sandbox::CONFIG );
delete_option( OLR_TaxBandits_Sandbox::STATE );
$sb_credentials = array( 'client_id' => 'test-client-123456', 'client_secret' => 'fake-secret-for-local-tests-only', 'user_token' => 'test-user-token-123456' );
$sb_business = '11111111-1111-4111-8111-111111111111';
$sb_submission = '22222222-2222-4222-8222-222222222222';
$sb_status = 'URL_GENERATED';
$sb_fault = '';
$sb_requests = array();
$sb_before = array();
foreach ( array( 'documents', 'requests', 'reservations', 'balances', 'ledger' ) as $name ) { $sb_before[ $name ] = $wpdb->get_results( 'SELECT * FROM ' . OLR_Affiliate_Service::table( $name ), ARRAY_A ); }
$sb_payout_setting = get_option( 'olr_aff_payouts_enabled' );
$sb_auth_log = array();
$sb_pdf_requests = array();
$sb_remote = function ( $pre, $args, $url ) use ( &$sb_requests, &$sb_status, &$sb_fault, $sb_credentials, $sb_business, $sb_submission, &$sb_auth_log, &$sb_pdf_requests ) {
	$sb_requests[] = array( 'url' => $url, 'method' => $args['method'], 'body' => $args['body'] ?? '', 'tls' => $args['sslverify'], 'redirects' => $args['redirection'] );
	if ( str_starts_with( $url, 'https://taxbandits-sb-api.s3.us-east-1.amazonaws.com/' ) ) {
		$sb_pdf_requests[] = array( 'url' => $url, 'args' => $args );
		if ( 's3-offline' === $sb_fault ) { return new WP_Error( 'fail', 'SECRET-S3-ERROR' ); }
		$body = "%PDF-1.4\nsynthetic test document\n%%EOF";
		if ( 's3-invalid' === $sb_fault ) { $body = '<html>SECRET-S3-ERROR</html>'; }
		if ( 's3-truncated' === $sb_fault ) { $body = "%PDF-1.4\ntruncated"; }
		if ( 's3-large' === $sb_fault ) { $body = '%PDF-' . str_repeat( 'x', OLR_TaxBandits_Pdf::MAX_BYTES ) . '%%EOF'; }
		return array( 'response' => array( 'code' => 's3-denied' === $sb_fault ? 403 : ( 's3-redirect' === $sb_fault ? 301 : 200 ) ), 'body' => $body );
	}
	if ( 'offline' === $sb_fault ) { return new WP_Error( 'offline', 'secret: ' . $sb_credentials['client_secret'] ); }
	if ( 'unauthorized' === $sb_fault ) { return array( 'response' => array( 'code' => 401 ), 'body' => wp_json_encode( array( 'Errors' => array( array( 'Message' => 'secret: ' . $sb_credentials['client_secret'] ) ) ) ) ); }
	if ( 'malformed' === $sb_fault ) { return array( 'response' => array( 'code' => 200 ), 'body' => '<html>Error</html>' ); }
	if ( 'redirect' === $sb_fault ) { return array( 'response' => array( 'code' => 302 ), 'body' => '' ); }
	if ( 'body-error' === $sb_fault ) { return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'StatusCode' => 400, 'Errors' => array( array( 'Message' => 'TIN 123456789' ) ) ) ) ); }
	if ( OLR_TaxBandits_Sandbox::AUTH === $url ) {
		$jws = $args['headers']['Authentication'] ?? '';
		$parts = explode( '.', $jws );
		$sb_auth_log[] = array( 'parts' => $parts, 'header' => json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true ), 'claims' => json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) );
		$data = array( 'StatusCode' => 200, 'AccessToken' => 'synthetic.access.token.for.local.tests', 'ExpiresIn' => 3600 );
	} elseif ( str_starts_with( $url, OLR_TaxBandits_Sandbox::API ) ) {
		if ( 'Bearer synthetic.access.token.for.local.tests' !== ( $args['headers']['Authorization'] ?? '' ) ) { throw new LogicException( 'Missing Bearer token' ); }
		$path = substr( strtok( $url, '?' ), strlen( OLR_TaxBandits_Sandbox::API ) );
		$state = get_option( OLR_TaxBandits_Sandbox::STATE, array() );
		if ( 'Business/Create' === $path ) { $data = array( 'BusinessId' => $sb_business, 'EINorSSN' => 'PRIVATE-SENTINEL-TAX-ID' ); }
		elseif ( 'Business/Get' === $path ) { $data = array( 'Business' => array( 'BusinessId' => $sb_business, 'EINorSSN' => 'PRIVATE-SENTINEL-TAX-ID' ) ); }
		elseif ( 'FormW9/RequestByUrl' === $path ) {
			$data = array( 'PayeeRef' => $state['test']['reference'], 'SubmissionId' => $sb_submission, 'W9Url' => 'https://testlinks.taxbandits.io/?uId=private-test-link' );
			if ( str_starts_with( $sb_fault, 'url:' ) ) { $data['W9Url'] = substr( $sb_fault, 4 ); }
			if ( 'wrong-create-ref' === $sb_fault ) { $data['PayeeRef'] = 'unrelated'; }
		} elseif ( 'FormW9/Status' === $path ) {
			$data = array( 'Requester' => array( 'BusinessId' => $sb_business, 'TIN' => 'PRIVATE-SENTINEL-TAX-ID' ), 'PayeeRef' => $state['test']['reference'], 'Status' => array( array( 'SubmissionId' => $sb_submission, 'W9Status' => $sb_status ) ) );
			if ( 'wrong-business' === $sb_fault ) { $data['Requester']['BusinessId'] = wp_generate_uuid4(); }
			if ( 'wrong-recipient' === $sb_fault ) { $data['PayeeRef'] = 'other-recipient'; }
			if ( 'wrong-submission' === $sb_fault ) { $data['Status'][0]['SubmissionId'] = wp_generate_uuid4(); }
			if ( 'duplicate-submission' === $sb_fault ) { $data['Status'][] = $data['Status'][0]; }
		} elseif ( 'FormW9/Get' === $path ) {
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
			if ( ( $query['BusinessId'] ?? '' ) !== $sb_business || ( $query['PayeeRef'] ?? '' ) !== $state['test']['reference'] ) { throw new LogicException( 'Missing exact document scope' ); }
			$data = array( 'Requester' => array( 'BusinessId' => $sb_business ), 'PayeeRef' => $state['test']['reference'], 'SubmissionId' => $sb_submission, 'W9Status' => 'COMPLETED', 'PdfUrl' => 'https://taxbandits-sb-api.s3.amazonaws.com/sample/signed.pdf?signature=private-pdf-token', 'FormData' => array( 'TIN' => 'PRIVATE-SENTINEL-TAX-ID' ) );
			if ( 'pdf-business' === $sb_fault ) { $data['Requester']['BusinessId'] = wp_generate_uuid4(); }
			if ( 'pdf-recipient' === $sb_fault ) { $data['PayeeRef'] = 'unrelated'; }
			if ( 'pdf-submission' === $sb_fault ) { $data['SubmissionId'] = wp_generate_uuid4(); }
			if ( 'pdf-status' === $sb_fault ) { $data['W9Status'] = 'OPENED'; }
			if ( 'pdf-missing' === $sb_fault ) { unset( $data['PdfUrl'] ); }
			if ( str_starts_with( $sb_fault, 'pdf-url:' ) ) { $data['PdfUrl'] = substr( $sb_fault, 8 ); }
			if ( str_starts_with( $sb_fault, 's3-' ) ) { $data['PdfUrl'] = 'pdfs/test/FormW9/test.pdf'; }
		} else { throw new LogicException( 'Unexpected API path' ); }
	} else { throw new LogicException( 'Non-sandbox request attempted: ' . $url ); }
	return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $data ) );
};
add_filter( 'pre_http_request', $sb_remote, 1, 3 );
$sb_call = function ( $command, $input = array() ) { return OLR_TaxBandits_Sandbox::perform( $command, $input ); };
$sb_state = function () { return get_option( OLR_TaxBandits_Sandbox::STATE, array() ); };
$sb_ref = function () use ( $sb_state ) { return array( 'reference' => $sb_state()['test']['reference'] ); };

refuses( fn() => $sb_call( 'connect' ), 'sandbox requires configured credentials' );
refuses( fn() => $sb_call( 'save', array( 'client_id' => 'bad' ) ), 'sandbox requires all three well-formed credentials' );
$sb_call( 'save', $sb_credentials );
$cipher = get_option( OLR_TaxBandits_Sandbox::CONFIG );
verify( is_string( $cipher ) && ! str_contains( $cipher, $sb_credentials['client_secret'] ), 'sandbox credentials stored as authenticated ciphertext' );
verify( 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", OLR_TaxBandits_Sandbox::CONFIG ) ), 'credentials are not autoloaded on member pages' );
$sb_call( 'connect' );
$auth = end( $sb_auth_log );
verify( $auth['header']['alg'] === 'HS256' && $auth['claims']['iss'] === $sb_credentials['client_id'] && $auth['claims']['sub'] === $sb_credentials['client_id'] && $auth['claims']['aud'] === $sb_credentials['user_token'] && abs( time() - $auth['claims']['iat'] ) < 5, 'TaxBandits authentication uses documented header and JWS claims' );
$sig = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $auth['parts'][0] . '.' . $auth['parts'][1], $sb_credentials['client_secret'], true ) ), '+/', '-_' ), '=' );
verify( hash_equals( $sig, $auth['parts'][2] ), 'sandbox JWS has a valid HMAC signature' );
verify( ! empty( $sb_state()['connected_at'] ), 'successful connection records a timestamp' );
refuses( fn() => $sb_call( 'start', array( 'test_only' => '1' ) ), 'form cannot start before a verified test business' );
$sb_call( 'business' );
verify( $sb_state()['business_id'] === $sb_business, 'sample business created and its reference stored' );
$before_repeat = count( $sb_requests );
$sb_call( 'business' );
verify( count( $sb_requests ) === $before_repeat, 'repeated prepare reuses the existing test business' );
refuses( fn() => $sb_call( 'start' ), 'test form requires explicit made-up-data acknowledgement' );
foreach ( array( 'http://testlinks.taxbandits.io/x', 'https://example.com/x', 'https://testlinks.taxbandits.io.evil.example/x', 'https://user@testlinks.taxbandits.io/x', 'https://testlinks.taxbandits.io:444/x', "https://testlinks.taxbandits.io/\n" ) as $unsafe ) {
	$sb_fault = 'url:' . $unsafe;
	refuses( fn() => $sb_call( 'start', array( 'test_only' => '1' ) ), 'unsafe or unexpected provider form URL blocked' );
}
$retained_ref = $sb_state()['test']['reference'];
$sb_fault = 'wrong-create-ref';
refuses( fn() => $sb_call( 'start', array( 'test_only' => '1' ) ), 'form response must match the original recipient reference' );
$sb_fault = '';
$sb_call( 'start', array( 'test_only' => '1' ) );
verify( $sb_state()['test']['reference'] === $retained_ref, 'failed request retries reuse the same PayeeRef' );
verify( ! str_contains( wp_json_encode( $sb_state() ), 'private-test-link' ), 'hosted form bearer URL encrypted in saved state' );
$before_repeat = count( $sb_requests );
$sb_call( 'start', array( 'test_only' => '1' ) );
verify( count( $sb_requests ) === $before_repeat, 'double submission reuses an existing test form' );
refuses( fn() => $sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) ), 'uncompleted provider form cannot be approved' );
refuses( fn() => $sb_call( 'view', $sb_ref() ), 'unfinished W-9 cannot open a PDF' );
$sb_status = 'COMPLETED';
foreach ( array( 'wrong-business', 'wrong-recipient', 'wrong-submission', 'duplicate-submission' ) as $fault ) {
	$sb_fault = $fault;
	refuses( fn() => $sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) ), 'mismatched or ambiguous provider status cannot authorize a review' );
}
$sb_fault = '';
$sb_call( 'status', $sb_ref() );
verify( 'pending_review' === $sb_state()['test']['review'], 'provider completion becomes pending review, not automatic approval' );
$view = $sb_call( 'view', $sb_ref() );
verify( str_contains( $view['pdf_url'], 'private-pdf-token' ) && 'pending_review' === $sb_state()['test']['review'], 'view retrieves the signed PDF without approving the test' );
verify( ! str_contains( wp_json_encode( $sb_state() ), 'private-pdf-token' ) && ! str_contains( wp_json_encode( $sb_state() ), 'PRIVATE-SENTINEL-TAX-ID' ), 'view discards PDF URL and recipient tax data' );
foreach ( array( 'pdf-business', 'pdf-recipient', 'pdf-submission', 'pdf-status', 'pdf-missing' ) as $fault ) {
	$sb_fault = $fault;
	refuses( fn() => $sb_call( 'view', $sb_ref() ), 'missing or mismatched completed document cannot be viewed' );
	refuses( fn() => $sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) ), 'missing or mismatched completed document cannot be approved' );
}
foreach ( array( 'http://taxbandits-sb-api.s3.amazonaws.com/a.pdf', 'https://example.com/a.pdf', 'https://taxbandits-sb-api.s3.amazonaws.com.evil.test/a.pdf', 'https://s3.amazonaws.com/other-bucket/a.pdf', 'https://other-bucket.s3.amazonaws.com/a.pdf', 'https://user@taxbandits-sb-api.s3.amazonaws.com/a.pdf', 'https://taxbandits-sb-api.s3.amazonaws.com:444/a.pdf', 'https://s3.amazonaws.com/taxbandits-sb-api/../other/a.pdf', 'https://taxbandits-sb-api.s3.amazonaws.com/file.html', "https://taxbandits-sb-api.s3.amazonaws.com/a.pdf\r\nLocation:x", 'encrypted-reference' ) as $unsafe ) {
	$sb_fault = 'pdf-url:' . $unsafe;
	refuses( fn() => $sb_call( 'view', $sb_ref() ), 'untrusted, non-PDF or encrypted document location fails closed' );
}
foreach ( array( 'https://s3.amazonaws.com/taxbandits-dev-api/example.Pdf', 'https://taxbandits-sb-api.s3.us-east-1.amazonaws.com/sample.pdf?x=1', 'https://s3.us-east-1.amazonaws.com/taxbandits-sb-api/sample.pdf' ) as $safe ) {
	$sb_fault = 'pdf-url:' . $safe;
	verify( $sb_call( 'view', $sb_ref() )['pdf_url'] === $safe, 'documented sandbox bucket locations accepted without modifying signed URL' );
}
$sb_fault = '';
ob_start(); OLR_TaxBandits_Sandbox::render(); $completed_html = ob_get_clean();
verify( str_contains( $completed_html, 'View completed W-9' ) && str_contains( $completed_html, 'name="command" value="view"' ) && str_contains( $completed_html, 'target="_blank" rel="noopener noreferrer"' ), 'completed form exposes a protected new-tab PDF action' );
verify( ! str_contains( $completed_html, '>Open test W-9</a>' ), 'completed form hides the expired signing link' );
refuses( fn() => $sb_call( 'approve', $sb_ref() ), 'manual approval requires document-review acknowledgement' );
$sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) );
verify( 'approved' === $sb_state()['test']['review'] && $sb_admin === $sb_state()['test']['reviewed_by'], 'test approval records its reviewer' );
verify( ! OLR_Affiliate_Service::tax_approved( $sb_admin ), 'approved sandbox test cannot approve a real member for Zelle' );
$sb_status = 'AWAITING_TIN_CERTIFICATE';
refuses( fn() => $sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) ), 'a later incomplete-TIN provider response removes test approval' );
verify( 'awaiting_submission' === $sb_state()['test']['review'], 'changed provider eligibility clears earlier review state' );
$sb_status = 'UNRECOGNIZED';
refuses( fn() => $sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) ), 'unknown statuses fail closed' );
$sb_status = 'COMPLETED';
$sb_call( 'reject', $sb_ref() + array( 'reviewed' => '1' ) );
$old_ref = $sb_ref();
$sb_call( 'status', $sb_ref() );
verify( 'needs_replacement' === $sb_state()['test']['review'], 'status refresh preserves manual rejection' );
$sb_call( 'replace', $sb_ref() + array( 'test_only' => '1' ) );
verify( $old_ref['reference'] !== $sb_ref()['reference'] && 'awaiting_submission' === $sb_state()['test']['review'], 'replacement starts a new reference with no carried-over approval' );
refuses( fn() => $sb_call( 'approve', $old_ref + array( 'reviewed' => '1' ) ), 'stale administrator form cannot approve the replacement' );
refuses( fn() => $sb_call( 'view', $old_ref ), 'stale administrator form cannot view a replacement document' );
foreach ( array( 'offline', 'unauthorized', 'malformed', 'redirect', 'body-error' ) as $fault ) {
	$sb_fault = $fault;
	try { $sb_call( 'connect' ); throw new LogicException( 'Expected error' ); }
	catch ( RuntimeException $e ) { verify( ! str_contains( $e->getMessage(), $sb_credentials['client_secret'] ) && ! str_contains( $e->getMessage(), '123456789' ), 'provider failures handled without disclosing error payloads or credentials' ); }
	verify( empty( $sb_state()['connected_at'] ), 'failed reconnect clears stale success status' );
}
$sb_fault = '';
$sb_call( 'connect' );
ob_start(); OLR_TaxBandits_Sandbox::render(); $sb_html = ob_get_clean();
verify( str_contains( $sb_html, 'Save sandbox credentials' ) && str_contains( $sb_html, 'Open test W-9' ), 'admin sees the complete connection and test workflow' );
foreach ( $sb_credentials as $secret ) { verify( ! str_contains( $sb_html, $secret ), 'saved credential never echoed into administrator markup' ); }
verify( ! str_contains( wp_json_encode( $sb_state() ), 'PRIVATE-SENTINEL-TAX-ID' ), 'tax IDs returned by provider are discarded rather than stored' );
verify( ! str_contains( get_option( OLR_TaxBandits_Sandbox::CONFIG ), 'synthetic.access.token' ) && ! str_contains( wp_json_encode( $sb_state() ), 'synthetic.access.token' ), 'access token is not persisted' );

// Route security: intercept wp_die only in this disposable CLI test.
add_filter( 'wp_die_handler', static fn() => static function () { throw new RuntimeException( 'Denied request' ); } );
$_SERVER['REQUEST_METHOD'] = 'GET';
refuses( fn() => OLR_TaxBandits_Sandbox::dispatch(), 'sandbox handler rejects GET mutations' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST['_wpnonce'] = 'invalid';
refuses( fn() => OLR_TaxBandits_Sandbox::dispatch(), 'sandbox handler rejects invalid CSRF nonce' );
wp_set_current_user( 0 );
refuses( fn() => $sb_call( 'connect' ), 'guest cannot read or use API credentials' );
refuses( fn() => $sb_call( 'view', $sb_ref() ), 'guest cannot retrieve PDF links' );
wp_set_current_user( $uid );
refuses( fn() => $sb_call( 'connect' ), 'ordinary member cannot use sandbox commands' );
refuses( fn() => $sb_call( 'view', $sb_ref() ), 'ordinary member cannot retrieve PDF links' );
refuses( fn() => OLR_TaxBandits_Sandbox::dispatch(), 'ordinary member cannot invoke sandbox POST handler' );
ob_start(); OLR_TaxBandits_Sandbox::render(); $member_html = ob_get_clean();
verify( '' === $member_html, 'sandbox settings and form links never render for members' );
wp_set_current_user( $sb_admin );
$_SERVER['REQUEST_METHOD'] = 'GET';

// Encrypted provider PDFs: separate TaxBandits-supplied credentials and in-memory response.
$sb_status = 'COMPLETED';
$sb_pdf_credentials = array( 'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE', 'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'base64_key' => base64_encode( str_repeat( 'q', 32 ) ), 's3_bucket' => 'taxbandits-sb-api' );
$preserved_test = $sb_state()['test'];
refuses( fn() => $sb_call( 'save_pdf', array() ), 'PDF setup requires all four provider values' );
foreach ( array( 'base64_key' => base64_encode( 'short' ), 's3_bucket' => 'example.com/path', 'aws_access_key' => "KEY\nHEADER", 'aws_secret_key' => 'short' ) as $field => $bad_value ) {
	$bad_values = $sb_pdf_credentials; $bad_values[$field] = $bad_value;
	refuses( fn() => $sb_call( 'save_pdf', $bad_values ), 'invalid PDF credentials are rejected without changing existing configuration' );
}
$sb_call( 'save_pdf', $sb_pdf_credentials );
verify( $preserved_test === $sb_state()['test'], 'saving PDF credentials preserves the existing completed test' );
foreach ( $sb_pdf_credentials as $secret ) { verify( ! str_contains( get_option( OLR_TaxBandits_Sandbox::CONFIG ), $secret ), 'PDF credential saved only as authenticated ciphertext' ); }
$sb_fault = 'pdf-url:pdfs/test/FormW9/test.pdf';
$private_view = $sb_call( 'view', $sb_ref() );
verify( str_starts_with( $private_view['pdf_body'], '%PDF-' ), 'private provider path retrieves signed PDF bytes' );
$pdf_request = end( $sb_pdf_requests );
verify( $pdf_request['args']['headers']['x-amz-server-side-encryption-customer-algorithm'] === 'AES256' && $pdf_request['args']['headers']['x-amz-server-side-encryption-customer-key'] === $sb_pdf_credentials['base64_key'] && $pdf_request['args']['headers']['x-amz-server-side-encryption-customer-key-md5'] === base64_encode( md5( str_repeat( 'q', 32 ), true ) ), 'S3 request includes correct SSE-C key and checksum headers' );
verify( str_starts_with( $pdf_request['args']['headers']['Authorization'], 'AWS4-HMAC-SHA256 Credential=' ) && false === $pdf_request['args']['stream'] && OLR_TaxBandits_Pdf::MAX_BYTES + 1 === $pdf_request['args']['limit_response_size'], 'S3 requests signed in headers with bounded memory-only download' );
verify( ! str_contains( $pdf_request['url'], $sb_pdf_credentials['base64_key'] ) && ! str_contains( $pdf_request['url'], $sb_pdf_credentials['aws_secret_key'] ), 'PDF credentials never appear in URLs' );
foreach ( array( 's3-offline', 's3-denied', 's3-redirect', 's3-invalid', 's3-truncated', 's3-large' ) as $fault ) {
	$sb_fault = $fault;
	refuses( fn() => $sb_call( 'view', $sb_ref() ), 'unavailable, redirected, invalid or oversized private PDF rejected' );
}
foreach ( array( 'https://evil.example/x.pdf', 'https://other-bucket.s3.us-east-1.amazonaws.com/x.pdf', 'http://taxbandits-sb-api.s3.amazonaws.com/x.pdf', 'pdfs/../other.pdf', 'pdfs/%2e%2e/other.pdf', '//evil.example/x.pdf', 'pdfs/test.html', "pdfs/test\r\n.pdf", 'https://s3.amazonaws.com/other-bucket/a.pdf' ) as $bad_path ) {
	$sb_fault = 'pdf-url:' . $bad_path;
	$before_s3 = count( $sb_pdf_requests );
	refuses( fn() => $sb_call( 'view', $sb_ref() ), 'private PDF path cannot redirect credentials to other hosts or buckets' );
	verify( count( $sb_pdf_requests ) === $before_s3, 'unsafe reference rejected before sending S3 credentials' );
}
foreach ( array( 'https://taxbandits-sb-api.s3.us-east-1.amazonaws.com/pdfs/test.pdf', 'https://s3.amazonaws.com/taxbandits-sb-api/pdfs/test.pdf' ) as $private_url ) {
	$sb_fault = 'pdf-url:' . $private_url;
	verify( isset( $sb_call( 'view', $sb_ref() )['pdf_body'] ), 'exact configured bucket URLs also support encrypted retrieval' );
}
$sb_call( 'save', $sb_credentials );
$sb_fault = 's3-ok';
verify( isset( $sb_call( 'view', $sb_ref() )['pdf_body'] ), 'same-account API credential save preserves PDF settings' );
$sb_call( 'approve', $sb_ref() + array( 'reviewed' => '1' ) );
verify( 'approved' === $sb_state()['test']['review'] && ! OLR_Affiliate_Service::tax_approved( $sb_admin ), 'private PDF workflow supports test approval without real payout eligibility' );
ob_start(); OLR_TaxBandits_Sandbox::render(); $private_html = ob_get_clean();
foreach ( $sb_pdf_credentials as $secret ) { verify( ! str_contains( $private_html, $secret ), 'PDF credentials not echoed into admin forms' ); }
verify( ! str_contains( wp_json_encode( $sb_state() ), '%PDF-' ) && ! str_contains( wp_json_encode( $sb_state() ), 'pdfs/test' ), 'PDF bytes and private references not persisted in test state' );
$sb_fault = '';

$bad = base64_decode( get_option( OLR_TaxBandits_Sandbox::CONFIG ) );
$bad[30] = chr( ord( $bad[30] ) ^ 1 );
update_option( OLR_TaxBandits_Sandbox::CONFIG, base64_encode( $bad ) );
refuses( fn() => $sb_call( 'connect' ), 'tampered encrypted credentials fail authentication before network access' );
$sb_call( 'save', $sb_credentials );
verify( empty( $sb_state()['test'] ), 'credential recovery clears stale test association' );
$sb_call( 'business', array( 'business_id' => $sb_business ) );
verify( $sb_state()['business_id'] === $sb_business, 'existing sandbox business can be verified and selected' );
$sb_call( 'disconnect' );
verify( ! get_option( OLR_TaxBandits_Sandbox::CONFIG ) && ! get_option( OLR_TaxBandits_Sandbox::STATE ), 'disconnect removes local secrets and test state' );
foreach ( $sb_before as $name => $rows ) { verify( $rows === $wpdb->get_results( 'SELECT * FROM ' . OLR_Affiliate_Service::table( $name ), ARRAY_A ), 'sandbox never changes real ' . $name . ' records' ); }
verify( $sb_payout_setting === get_option( 'olr_aff_payouts_enabled' ), 'sandbox setup does not enable or disable real payouts' );
verify( count( array_filter( $sb_requests, fn( $r ) => ! $r['tls'] || 0 !== $r['redirects'] ) ) === 0, 'all provider requests verify TLS and prohibit redirects' );
$creates = array_filter( $sb_requests, fn( $r ) => str_contains( $r['url'], 'FormW9/RequestByUrl' ) );
verify( count( array_filter( $creates, fn( $r ) => false !== json_decode( $r['body'], true )['Recipient']['IsTINMatching'] ) ) === 0, 'sandbox tests do not initiate optional paid TIN matching' );

// Browser fixture contains only synthetic credentials and data, never the user's API account.
$sb_call( 'save', $sb_credentials ); $sb_call( 'connect' ); $sb_call( 'business' ); $sb_call( 'start', array( 'test_only' => '1' ) ); $sb_call( 'status', $sb_ref() );
ob_start(); OLR_TaxBandits_Sandbox::render(); $sb_html = ob_get_clean();
$sb_out = dirname( $fixture ) . '/public/taxbandits-sandbox.html';
file_put_contents( $sb_out, '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px/1.5 Arial,sans-serif;color:#111;background:#f1f1f1}h2{font-size:23px}h3{font-size:18px}input{box-sizing:border-box;padding:6px}.regular-text{width:25em}button,.button{padding:8px 12px}label,code{overflow-wrap:anywhere}form{max-width:100%}</style></head><body>' . $sb_html . '</body></html>' );
$sb_call( 'disconnect' );
remove_filter( 'pre_http_request', $sb_remote, 1 );
echo 'Completed baseline plus sandbox checks: ' . $checks . PHP_EOL;
