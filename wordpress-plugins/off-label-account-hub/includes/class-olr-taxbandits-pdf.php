<?php
/** TaxBandits SSE-C PDF retrieval. No filesystem, database, logging or browser credentials. */
defined( 'ABSPATH' ) || exit;

final class OLR_TaxBandits_Pdf {
	const MAX_BYTES = 10485760;
	public static function credentials( $input ) {
		$c = array();
		foreach ( array( 'aws_access_key', 'aws_secret_key', 'base64_key', 's3_bucket' ) as $field ) {
			$c[ $field ] = isset( $input[ $field ] ) && is_string( $input[ $field ] ) ? trim( $input[ $field ] ) : '';
		}
		if ( ! preg_match( '/^[A-Z0-9]{16,128}$/D', $c['aws_access_key'] ) || ! preg_match( '#^[A-Za-z0-9/+=]{16,128}$#D', $c['aws_secret_key'] ) ) { throw new RuntimeException( 'Enter the AWS AccessKey and AWS SecretKey supplied by TaxBandits for this sandbox.' ); }
		$key = base64_decode( $c['base64_key'], true );
		if ( false === $key || 32 !== strlen( $key ) || base64_encode( $key ) !== $c['base64_key'] ) { throw new RuntimeException( 'The TaxBandits Base64Key must decode to exactly 32 bytes. Copy the complete value.' ); }
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $c['s3_bucket'] ) ) { throw new RuntimeException( 'Enter only the S3 Bucket Name supplied by TaxBandits, without a URL or path.' ); }
		return $c;
	}
	public static function object_key( $reference, $bucket ) {
		if ( ! is_string( $reference ) || '' === $reference || strlen( $reference ) > 4096 || preg_match( '/[\x00-\x20\\\\]/', $reference ) ) { throw new RuntimeException( 'TaxBandits returned an invalid private PDF reference.' ); }
		$key = $reference;
		if ( 0 === strpos( $reference, 'https://' ) ) {
			$p = wp_parse_url( $reference );
			if ( ! is_array( $p ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) ) { throw new RuntimeException( 'TaxBandits returned an unsupported private PDF URL.' ); }
			$host = strtolower( $p['host'] ?? '' );
			$key = ltrim( rawurldecode( $p['path'] ?? '' ), '/' );
			if ( in_array( $host, array( $bucket . '.s3.amazonaws.com', $bucket . '.s3.us-east-1.amazonaws.com', $bucket . '.s3-us-east-1.amazonaws.com' ), true ) ) { /* Exact configured bucket. */ }
			elseif ( in_array( $host, array( 's3.amazonaws.com', 's3.us-east-1.amazonaws.com', 's3-us-east-1.amazonaws.com' ), true ) && 0 === strpos( $key, $bucket . '/' ) ) { $key = substr( $key, strlen( $bucket ) + 1 ); }
			else { throw new RuntimeException( 'The PDF URL does not match the TaxBandits bucket configured here. No credentials were sent.' ); }
		}
		// The actual sandbox returns pdfs/.../FormW9/...pdf; retain that key exactly.
		if ( strlen( $key ) > 1024 || ! preg_match( '#^[A-Za-z0-9_/-][A-Za-z0-9_./-]*\.pdf$#iD', $key ) || '/' === $key[0] || preg_match( '#(?:^|/)\.{1,2}(?:/|$)|//#', $key ) ) { throw new RuntimeException( 'TaxBandits returned an unsupported private PDF path.' ); }
		return $key;
	}
	/** AWS Signature V4, fixed S3 GetObject in us-east-1 as required by TaxBandits. */
	public static function request( $reference, $credentials, $timestamp = null ) {
		$c = self::credentials( $credentials );
		$key = self::object_key( $reference, $c['s3_bucket'] );
		$host = $c['s3_bucket'] . '.s3.us-east-1.amazonaws.com';
		$uri = '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
		$date = null === $timestamp ? gmdate( 'Ymd\THis\Z' ) : $timestamp;
		if ( ! preg_match( '/^[0-9]{8}T[0-9]{6}Z$/D', $date ) ) { throw new LogicException( 'Invalid signing timestamp.' ); }
		$headers = array(
			'host' => $host, 'x-amz-content-sha256' => hash( 'sha256', '' ), 'x-amz-date' => $date,
			'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
			'x-amz-server-side-encryption-customer-key' => $c['base64_key'],
			'x-amz-server-side-encryption-customer-key-md5' => base64_encode( md5( base64_decode( $c['base64_key'], true ), true ) ),
		);
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $name => $value ) { $canonical_headers .= $name . ':' . $value . "\n"; }
		$signed = implode( ';', array_keys( $headers ) );
		$canonical = "GET\n" . $uri . "\n\n" . $canonical_headers . "\n" . $signed . "\n" . $headers['x-amz-content-sha256'];
		$day = substr( $date, 0, 8 );
		$scope = $day . '/us-east-1/s3/aws4_request';
		$signing_key = hash_hmac( 'sha256', $day, 'AWS4' . $c['aws_secret_key'], true );
		foreach ( array( 'us-east-1', 's3', 'aws4_request' ) as $part ) { $signing_key = hash_hmac( 'sha256', $part, $signing_key, true ); }
		$signature = hash_hmac( 'sha256', "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash( 'sha256', $canonical ), $signing_key );
		$headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $c['aws_access_key'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
		return array( 'url' => 'https://' . $host . $uri, 'args' => array( 'method' => 'GET', 'headers' => $headers, 'timeout' => 30, 'sslverify' => true, 'redirection' => 0, 'stream' => false, 'limit_response_size' => self::MAX_BYTES + 1 ) );
	}
	public static function fetch( $reference, $credentials ) {
		$r = self::request( $reference, $credentials );
		$response = wp_remote_request( $r['url'], $r['args'] );
		if ( is_wp_error( $response ) ) { throw new RuntimeException( 'The encrypted PDF could not be retrieved. Check outbound HTTPS access and retry. No review was recorded.' ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) { throw new RuntimeException( 'TaxBandits private PDF storage returned HTTP ' . $code . '. Check all four PDF credentials, the bucket name and server clock. No review was recorded.' ); }
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES || 0 !== strpos( $body, '%PDF-' ) || false === strpos( substr( $body, -1024 ), '%%EOF' ) ) { throw new RuntimeException( 'The private response was not a complete PDF within the 10 MB viewing limit.' ); }
		return $body;
	}
}
