<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-olr-taxbandits-pdf.php';
echo json_encode( OLR_TaxBandits_Pdf::request( 'pdfs/test/FormW9/test.pdf', array(
	'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
	'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	'base64_key' => base64_encode( str_repeat( 'q', 32 ) ),
	's3_bucket' => 'taxbandits-sb-api',
), '20130524T000000Z' ) );
