<?php
/** Run only in disposable WordPress: wp eval-file path/to/tests/integration.php */
if ( ! defined( 'OLR_PP_TEST_ENV' ) || ! OLR_PP_TEST_ENV ) {
	throw new RuntimeException( 'This test requires a disposable WordPress site with OLR_PP_TEST_ENV=true.' );
}

$GLOBALS['checks'] = 0;
function pp_assert( $value, $message ) {
	global $checks;
	++$checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
}
function pp_user( $suffix ) {
	$email = 'pp-' . $suffix . '@example.test';
	$user = get_user_by( 'email', $email );
	$id = $user ? $user->ID : wp_insert_user( array( 'user_login' => 'pp-' . $suffix, 'user_email' => $email, 'user_pass' => 'local-member-qa-only', 'role' => 'subscriber' ) );
	foreach ( array( 'billing_phone', 'shipping_phone', 'phone_number', 'mobile_number', OLR_Phone_Popup::STATE_META, OLR_Phone_Popup::DISMISS_META, '_olr_pp_saved_at' ) as $key ) { delete_user_meta( $id, $key ); }
	delete_option( 'olr_pp_lock_' . $id );
	return $id;
}
function pp_response( $body, $code = 200 ) {
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'Test' ), 'cookies' => array(), 'filename' => null );
}
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Phone Popup Test', 'post_content' => '<h2>Your member account</h2><p>A local test page for phone signup.</p><p><a href="/">Continue browsing</a></p>' ) );
$other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'No Popup Here' ) );
$draft = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft' ) );
$settings = OLR_Phone_Popup::defaults();
$settings['enabled'] = 1;
$settings['pages'] = array( $page );
$settings['delay'] = 0;
update_option( OLR_Phone_Popup::OPTION, $settings );
update_option( OLR_PP_Omnisend::KEY_OPTION, 'qa-placeholder-not-a-real-key', false );
wp_unschedule_hook( OLR_Phone_Popup::CRON );

$remote = array( 'contacts' => array(), 'writes' => array(), 'mode' => 'ok', 'calls' => array() );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$remote ) {
	if ( 0 !== strpos( $url, OLR_PP_Omnisend::BASE ) ) { return $pre; }
	$remote['calls'][] = array( 'url' => $url, 'args' => $args );
	pp_assert( '2026-03-15' === $args['headers']['Omnisend-Version'], 'pinned current Omnisend contract' );
	pp_assert( 0 === strpos( $args['headers']['Authorization'], 'Omnisend-API-Key ' ), 'server-side API authentication' );
	if ( 'offline' === $remote['mode'] ) { return new WP_Error( 'http_request_failed', 'Synthetic timeout' ); }
	if ( 'bad-key' === $remote['mode'] ) { return pp_response( array( 'detail' => 'private payload must never be exposed' ), 401 ); }
	if ( 'bad-schema' === $remote['mode'] ) { return pp_response( array( 'unexpected' => array() ) ); }
	$parts = wp_parse_url( $url );
	parse_str( $parts['query'] ?? '', $query );
	if ( 'GET' === $args['method'] ) {
		$matched = array();
		foreach ( $remote['contacts'] as $contact ) {
			foreach ( $contact['identifiers'] as $identifier ) {
				if ( isset( $query[ $identifier['type'] ] ) && $query[ $identifier['type'] ] === $identifier['id'] ) { $matched[] = $contact; break; }
			}
		}
		return pp_response( array( 'contacts' => $matched ) );
	}
	$payload = json_decode( $args['body'], true );
	$remote['writes'][] = $payload;
	pp_assert( ! isset( $payload['tags'] ), 'existing contact tags are never replaced' );
	foreach ( $payload['identifiers'] as $identifier ) {
		if ( 'email' === $identifier['type'] ) { pp_assert( ! isset( $identifier['channels'] ) && false === $identifier['sendWelcomeMessage'], 'no email marketing consent or welcome email' ); }
	}
	$id = 'PATCH' === $args['method'] ? basename( $parts['path'] ) : 'contact-' . ( count( $remote['contacts'] ) + 1 );
	$current = $remote['contacts'][ $id ] ?? array( 'id' => $id, 'identifiers' => array() );
	foreach ( $payload['identifiers'] as $identifier ) {
		$current['identifiers'] = array_values( array_filter( $current['identifiers'], function ( $old ) use ( $identifier ) { return $old['id'] !== $identifier['id']; } ) );
		$current['identifiers'][] = $identifier;
	}
	if ( 'ignore-write' !== $remote['mode'] ) { $remote['contacts'][ $id ] = $current; }
	if ( 'timeout-after-write' === $remote['mode'] ) { $remote['mode'] = 'ok'; return new WP_Error( 'http_request_failed', 'Synthetic response loss' ); }
	return pp_response( $current );
}, 10, 3 );

pp_assert( '+14155550123' === OLR_Phone_Popup::normalize_phone( '(415) 555-0123' ), 'US normalization' );
pp_assert( '+442079460123' === OLR_Phone_Popup::normalize_phone( '00 44 20 7946 0123' ), 'international normalization' );
foreach ( array( '', '123', '+1 111 111 1111', '+14155550123 ext 2', 'test1234567890', '+1234567890123456', '++44123456789', array() ) as $invalid ) {
	pp_assert( is_wp_error( OLR_Phone_Popup::normalize_phone( $invalid ) ), 'reject invalid phone input' );
}
pp_assert( is_wp_error( OLR_Phone_Popup::normalize_phone( '4155550123', false ) ), 'international-only setting' );
pp_assert( OLR_Phone_Popup::selected_page( $page ) && ! OLR_Phone_Popup::selected_page( $other ) && ! OLR_Phone_Popup::selected_page( $draft ), 'published page targeting' );
pp_assert( ! in_array( $draft, OLR_PP_Admin::sanitize( array( 'pages' => array( $draft, $page ) ) )['pages'], true ), 'draft targets stripped' );
$sanitized = OLR_PP_Admin::sanitize( array( 'title' => '<script>alert(1)</script>Hello', 'terms_url' => 'javascript:alert(1)', 'delay' => 999, 'cooldown' => -9 ) );
pp_assert( false === strpos( $sanitized['title'], '<' ) && '' === $sanitized['terms_url'] && 60 === $sanitized['delay'] && 1 === $sanitized['cooldown'], 'settings sanitize text, URLs and numeric bounds' );

$id = pp_user( 'collect' );
update_user_meta( $id, 'shipping_phone', '+14155550999' );
$before = count( $remote['calls'] );
$result = OLR_Phone_Popup::capture( $id, '4155550123', false, $page );
pp_assert( ! is_wp_error( $result ) && '+14155550123' === get_user_meta( $id, 'billing_phone', true ), 'real WordPress billing_phone save' );
pp_assert( '+14155550999' === get_user_meta( $id, 'shipping_phone', true ) && '' === get_user_meta( $id, 'phone_number', true ) && '' === get_user_meta( $id, 'mobile_number', true ), 'no duplicate phone fields or shipping mutation' );
pp_assert( $before === count( $remote['calls'] ), 'collect-only mode makes no Omnisend calls' );
pp_assert( ! OLR_Phone_Popup::eligible( $id ), 'saved accounts suppressed' );
pp_assert( ! is_wp_error( OLR_Phone_Popup::capture( $id, '4155550123', false, $page ) ), 'repeat submission is idempotent' );
pp_assert( is_wp_error( OLR_Phone_Popup::capture( $id, '4155550124', false, $page ) ), 'stale form cannot replace an existing billing number' );
$dismissed = pp_user( 'dismiss' );
update_user_meta( $dismissed, OLR_Phone_Popup::DISMISS_META, time() + 86400 );
pp_assert( ! OLR_Phone_Popup::eligible( $dismissed ) && is_wp_error( OLR_Phone_Popup::capture( $dismissed, '4155550125', false, $page ) ), 'server-side dismissal suppresses all devices' );

$settings['sms'] = 1;
$settings['consent'] .= ' Reference: SMS\\2026.';
$settings['terms_url'] = 'https://example.test/sms-terms';
$settings['privacy_url'] = 'https://example.test/privacy';
update_option( OLR_Phone_Popup::OPTION, $settings );
$id = pp_user( 'sms' );
pp_assert( is_wp_error( OLR_Phone_Popup::capture( $id, '4155550126', false, $page ) ) && '' === get_user_meta( $id, 'billing_phone', true ), 'SMS requires explicit consent before saving' );
$result = OLR_Phone_Popup::capture( $id, '4155550126', true, $page );
$state = OLR_Phone_Popup::state( $id );
pp_assert( 'synced' === $state['status'] && ! isset( $state['phone'] ), 'confirmed sync and no duplicate raw phone in consent state' );
pp_assert( false === strpos( wp_json_encode( $state ), '+14155550126' ), 'phone stored only in billing_phone' );
pp_assert( $state['disclosure'] === $settings['consent'] && $state['terms_url'] === $settings['terms_url'], 'exact disclosure and policy links retained' );
$writes = count( $remote['writes'] );
OLR_Phone_Popup::capture( $id, '4155550126', true, $page );
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( $writes === count( $remote['writes'] ), 'double submit and completed cron cannot repeat subscription' );

$id = pp_user( 'offline' );
$remote['mode'] = 'offline';
OLR_Phone_Popup::capture( $id, '4155550127', true, $page );
$state = OLR_Phone_Popup::state( $id );
pp_assert( 'pending' === $state['status'] && '+14155550127' === get_user_meta( $id, 'billing_phone', true ), 'API failure retains account phone and pending signup' );
pp_assert( (bool) wp_next_scheduled( OLR_Phone_Popup::CRON, array( $id, $state['id'] ) ), 'durable retry exists' );
$consent_at = $state['consent']['createdAt'];
$remote['mode'] = 'ok';
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( 'synced' === OLR_Phone_Popup::state( $id )['status'] && $consent_at === OLR_Phone_Popup::state( $id )['consent']['createdAt'], 'retry reuses original consent timestamp' );

$id = pp_user( 'lost-response' );
$remote['mode'] = 'timeout-after-write';
OLR_Phone_Popup::capture( $id, '4155550128', true, $page );
$state = OLR_Phone_Popup::state( $id );
$writes = count( $remote['writes'] );
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( 'synced' === OLR_Phone_Popup::state( $id )['status'] && $writes === count( $remote['writes'] ), 'lost API response does not repeat successful subscription' );

$id = pp_user( 'conflict' );
$remote['contacts']['other-person'] = array( 'id' => 'other-person', 'identifiers' => array( array( 'type' => 'phone', 'id' => '+14155550129', 'channels' => array( 'sms' => array( 'status' => 'subscribed' ) ) ) ) );
$writes = count( $remote['writes'] );
OLR_Phone_Popup::capture( $id, '4155550129', true, $page );
pp_assert( 'conflict' === OLR_Phone_Popup::state( $id )['error_code'] && $writes === count( $remote['writes'] ), 'phone identity conflicts never merge contacts' );

$id = pp_user( 'unsubscribed' );
$remote['contacts']['unsubscribed'] = array( 'id' => 'unsubscribed', 'identifiers' => array(
	array( 'type' => 'email', 'id' => get_userdata( $id )->user_email, 'channels' => array( 'email' => array( 'status' => 'unsubscribed' ) ) ),
	array( 'type' => 'phone', 'id' => '+14155550130', 'channels' => array( 'sms' => array( 'status' => 'unsubscribed' ) ) ),
) );
$writes = count( $remote['writes'] );
OLR_Phone_Popup::capture( $id, '4155550130', true, $page );
pp_assert( 'unsubscribed' === OLR_Phone_Popup::state( $id )['error_code'] && $writes === count( $remote['writes'] ), 'never override STOP/unsubscribed status' );

$id = pp_user( 'existing-email' );
$remote['contacts']['existing'] = array( 'id' => 'existing', 'identifiers' => array( array( 'type' => 'email', 'id' => get_userdata( $id )->user_email, 'channels' => array( 'email' => array( 'status' => 'unsubscribed' ) ) ) ) );
OLR_Phone_Popup::capture( $id, '4155550131', true, $page );
pp_assert( 'synced' === OLR_Phone_Popup::state( $id )['status'] && 'unsubscribed' === $remote['contacts']['existing']['identifiers'][0]['channels']['email']['status'], 'email unsubscribe preserved when adding SMS' );

$id = pp_user( 'changed' );
$remote['mode'] = 'offline';
OLR_Phone_Popup::capture( $id, '4155550132', true, $page );
$state = OLR_Phone_Popup::state( $id );
update_user_meta( $id, 'billing_phone', '+14155550199' );
$before = count( $remote['calls'] );
$remote['mode'] = 'ok';
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( 'changed' === OLR_Phone_Popup::state( $id )['error_code'] && $before === count( $remote['calls'] ), 'queued consent cannot apply to a changed phone' );

$id = pp_user( 'ignored' );
$remote['mode'] = 'ignore-write';
OLR_Phone_Popup::capture( $id, '4155550133', true, $page );
pp_assert( 'unconfirmed' === OLR_Phone_Popup::state( $id )['error_code'], 'HTTP 200 without actual phone subscription is not success' );
$id = pp_user( 'bad-key' );
$remote['mode'] = 'bad-key';
OLR_Phone_Popup::capture( $id, '4155550134', true, $page );
pp_assert( 'failed' === OLR_Phone_Popup::state( $id )['status'] && false === strpos( OLR_Phone_Popup::state( $id )['error'], 'private payload' ), 'configuration failure is visible without leaking response payload' );

$id = pp_user( 'exhausted' );
$remote['mode'] = 'offline';
OLR_Phone_Popup::capture( $id, '4155550137', true, $page );
$state = OLR_Phone_Popup::state( $id );
for ( $retry = 0; $retry < 4; ++$retry ) { OLR_PP_Omnisend::sync( $id, $state['id'] ); }
pp_assert( 'failed' === OLR_Phone_Popup::state( $id )['status'] && ! wp_next_scheduled( OLR_Phone_Popup::CRON, array( $id, $state['id'] ) ), 'automatic retries stop after five attempts' );

$id = pp_user( 'stopped-before-retry' );
OLR_Phone_Popup::capture( $id, '4155550138', true, $page );
$state = OLR_Phone_Popup::state( $id );
$remote['contacts']['stop-after-timeout'] = array( 'id' => 'stop-after-timeout', 'identifiers' => array(
	array( 'type' => 'email', 'id' => get_userdata( $id )->user_email ),
	array( 'type' => 'phone', 'id' => '+14155550138', 'channels' => array( 'sms' => array( 'status' => 'unsubscribed' ) ) ),
) );
$remote['mode'] = 'ok';
$writes = count( $remote['writes'] );
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( 'unsubscribed' === OLR_Phone_Popup::state( $id )['error_code'] && $writes === count( $remote['writes'] ), 'retry respects a later SMS unsubscribe' );

$id = pp_user( 'email-change' );
$remote['mode'] = 'offline';
OLR_Phone_Popup::capture( $id, '4155550139', true, $page );
$state = OLR_Phone_Popup::state( $id );
$state['email_hash'] = OLR_Phone_Popup::phone_hash( 'previous-address@example.test' );
update_user_meta( $id, OLR_Phone_Popup::STATE_META, $state );
$before = count( $remote['calls'] );
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( 'changed' === OLR_Phone_Popup::state( $id )['error_code'] && count( $remote['calls'] ) === $before, 'retry cannot apply consent to a changed account email' );

$id = pp_user( 'disabled-before-retry' );
OLR_Phone_Popup::capture( $id, '4155550140', true, $page );
$state = OLR_Phone_Popup::state( $id );
$settings['enabled'] = 0;
update_option( OLR_Phone_Popup::OPTION, $settings );
$before = count( $remote['calls'] );
OLR_PP_Omnisend::sync( $id, $state['id'] );
pp_assert( 'configuration' === OLR_Phone_Popup::state( $id )['error_code'] && count( $remote['calls'] ) === $before, 'disabling plugin settings prevents queued remote writes' );
$settings['enabled'] = 1;
update_option( OLR_Phone_Popup::OPTION, $settings );

$id = pp_user( 'locked' );
OLR_Phone_Popup::lock( $id );
pp_assert( is_wp_error( OLR_Phone_Popup::capture( $id, '4155550141', true, $page ) ) && '' === get_user_meta( $id, 'billing_phone', true ), 'concurrent submit is serialized before any mutation' );
OLR_Phone_Popup::unlock( $id );

$settings['audience'] = 'all';
update_option( OLR_Phone_Popup::OPTION, $settings );
$id = pp_user( 'prefill' );
update_user_meta( $id, 'billing_phone', '4155550135' );
pp_assert( OLR_Phone_Popup::eligible( $id ), 'optional audience includes existing phone without popup consent' );
$remote['mode'] = 'ok';
OLR_Phone_Popup::capture( $id, '4155550135', true, $page );
pp_assert( ! OLR_Phone_Popup::eligible( $id ), 'prior consent suppresses popup even for broad audience' );

$id = pp_user( 'erase' );
OLR_Phone_Popup::capture( $id, '4155550136', true, $page );
pp_assert( count( OLR_Phone_Popup::export_data( get_userdata( $id )->user_email )['data'] ) === 1, 'WordPress privacy exporter includes account record' );
OLR_Phone_Popup::erase_data( get_userdata( $id )->user_email );
pp_assert( ! OLR_Phone_Popup::state( $id ) && '+14155550136' === get_user_meta( $id, 'billing_phone', true ), 'privacy eraser clears plugin state' );

// Leave a clean browser fixture in collect-only mode, with no external calls needed.
$settings['sms'] = 0; $settings['audience'] = 'missing';
update_option( OLR_Phone_Popup::OPTION, $settings );
$browser_user = pp_user( 'browser' );
update_option( 'olr_pp_qa_fixture', array( 'page' => $page, 'other_page' => $other, 'user' => $browser_user ) );
wp_unschedule_hook( OLR_Phone_Popup::CRON );
echo 'PASS: ' . $GLOBALS['checks'] . ' assertions against real WordPress storage and mocked Omnisend HTTP.' . PHP_EOL;
echo 'Browser fixture: ' . wp_json_encode( get_option( 'olr_pp_qa_fixture' ) ) . PHP_EOL;
