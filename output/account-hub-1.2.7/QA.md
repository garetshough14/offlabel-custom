# Account Hub 1.2.7 verification

- Read the real sandbox FormW9/Get log through the authenticated browser. It returns a relative `pdfs/.../FormW9/...pdf` reference with COMPLETED status; no user credentials or tax data copied into source or fixtures.
- Implemented TaxBandits' documented AES256 SSE-C GetObject flow with four extra provider credentials. Uses fixed us-east-1, no redirects, exact configured S3 bucket, headers-only credentials, bounded memory response and PDF signature/EOF checks. No filesystem writes, PDF options or public attachments.
- 218 checks passed in the disposable WP7.1/UAP9.7.7/Woo11.1.0 HPOS environment (69 baseline affiliate/checkout and 149 sandbox assertions). TaxBandits and S3 responses are intercepted synthetic fixtures, not actual decrypted provider PDFs.
- AWS request signature independently matched AWS botocore with fixed public example credentials and timestamp. No AWS SDK runtime dependency is bundled; the narrow signer uses standard HMAC-SHA256 and WordPress HTTP.
- All four admin viewport checks passed (320, 375, 768, 1280): no overflow, seven blank password inputs, protected POST forms and visible new-tab viewer. Screenshots saved for desktop/mobile inspection.
- Existing completed submission and encrypted API credentials survive upgrade. Same-account API credential save preserves PDF settings. Account changes and salt recovery clear stale association. Real commission, payout, credit and tax-document tables remain unchanged.
- Known unrelated baseline: Woo fixture missing payment_method warning; previously recorded affiliate-surface test expects absent global-header links. No customer frontend changes in this release.
- Pending external check: user uploads ZIP, supplies the four PDF values directly in WordPress, opens existing completed test PDF and performs manual test review. Production/member collection and real payout approval remain disabled/unimplemented as before.
