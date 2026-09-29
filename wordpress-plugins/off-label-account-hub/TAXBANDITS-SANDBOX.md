# Connect your TaxBandits sandbox

Account Hub 1.2.7 supports encrypted TaxBandits PDFs in the administrator-only test area. No staging site, personal AWS account or private document directory is needed. It does not expose sandbox tax forms to customers or authorize real payouts. Updating from 1.2.5 or 1.2.6 preserves your saved credentials, business and current test; do not create a replacement just to view the existing form.

## Existing completed form: enable encrypted viewing

1. Upload Account Hub 1.2.7 as a plugin update.
2. In **TaxBandits Sandbox > Settings > Credentials**, locate the four additional PDF access values: **AWS AccessKey**, **AWS SecretKey**, **Base64Key** (PDF key), and **S3 Bucket Name**. These belong to TaxBandits' document storage; do not create your own bucket or AWS account. Keep W-9/W-8 PDF encryption enabled.
3. In **Affiliate Management > TaxBandits sandbox setup > 1b. Encrypted PDF access**, enter all four and select **Save PDF credentials**. Do not paste them into chat or email. They are stored encrypted with the existing API credentials and remain blank in the form after saving.
4. Under step 4, select **View completed W-9** again. It retrieves the existing signed PDF in memory and serves it to the authenticated administrator with no-store headers. It creates no Media Library attachment, temporary PDF, database PDF or public file.
5. Review the test document and signature, return, check the review acknowledgment and select **Approve test W-9**. Sandbox approval still cannot unlock real payouts.

The PDF request uses AWS Signature V4 and the provider's AES256 SSE-C key headers over verified HTTPS, fixed to us-east-1 as TaxBandits documents. Credentials are sent only to the exact configured S3 bucket. The provider response must match the current business, recipient and submission before retrieval. Files are limited to 10 MB, must have a PDF signature and EOF marker, and must return HTTP 200 without redirects. Raw S3/API error payloads are not displayed or saved. No keys appear in browser links. Avoid third-party HTTP/debug logging plugins that capture authorization headers, encryption keys or PDF response bodies.

If storage returns 403, check all four PDF values and the bucket copied from the same TaxBandits sandbox account. A malformed Base64Key is rejected before saving. Saving the same API account again preserves PDF credentials; switching API accounts or recovering after a WordPress salt change clears that association so PDF values must be re-entered.

Provider reference: [TaxBandits PDF Security](https://developer.taxbandits.com/docs/2.0.0/pdfretrieval/pdfsecurity/).

## Start a new sandbox test

1. Upload `off-label-account-hub-v1.2.5.zip` to WordPress as an update to Account Hub.
2. Open **Ultimate Affiliate Pro > Affiliate Management**. The **TaxBandits sandbox setup** section is near the top.
3. Paste **Client ID**, **Client Secret** and **User Token** from your TaxBandits sandbox account. Select **Save sandbox credentials**, then **Test connection**. All fields remain blank on subsequent page loads; “Saved” confirms their presence. Never send these values in chat, screenshots or email.
4. Select **Prepare test business** with the optional Business ID blank. This creates a clearly labeled sample payer with TaxBandits' published sample EIN/address, without changing your default business. If you already created one, enter its sandbox Business ID instead; the API verifies it. If creation times out or reports a duplicate, check your TaxBandits sandbox business list and enter the existing Business ID before retrying.
5. Confirm you will use made-up information, then select **Create / resume test W-9**. Open **Open test W-9**, fill out the hosted form with synthetic test information and sign it for testing. Do not enter an actual SSN or EIN. No email invitation, SMS, TIN-matching purchase or tax filing is initiated by this plugin.
6. Return to Affiliate Management and select **Check test status**. It verifies the exact business, recipient reference and submission ID directly with TaxBandits. A completed signed form becomes **pending review**. A browser redirect or checkbox alone cannot establish completion.
7. Under **Review the test submission**, select **View completed W-9**. The plugin verifies the business, recipient and exact submission, then opens TaxBandits' PDF in a new tab (your browser may download it instead). Review the completed synthetic document and signature, return to Account Hub, confirm your review and select **Approve test W-9** or **Request test replacement**. Approval checks both provider status and document identity again. A replacement has a new reference and requires a new approval. The sandbox console link and expired signing link are not document viewers.

The PDF button uses a capability-checked, nonce-protected POST. Each click retrieves the current provider reference; no PDF URL, tax data or document is saved in WordPress. With PDF credentials configured, the plugin retrieves from the exact configured bucket and serves PDF bytes with no-store, no-referrer, nosniff and restrictive CSP headers. The browser may download instead of displaying inline. Without PDF credentials, the older HTTPS-link flow remains available only for documented sandbox/development S3 buckets; those provider links expire (documented as 24 hours) and must not be shared.

If the PDF reference is missing, check **Settings > W-9/W-8 Settings > PDF Preferences** in the TaxBandits sandbox. For a private file path, complete step 1b above; keep encryption enabled.

## What this release stores

- Encrypted API credentials in a non-autoloaded WordPress option. Encryption uses PHP Sodium and the host-configured WordPress AUTH_KEY and AUTH_SALT constants. It refuses missing or placeholder salts rather than deriving the encryption key from a database fallback. WordPress.com manages these existing WordPress security settings; they are different from the unavailable private W-9 filesystem paths.
- Minimal sandbox state: business ID, request/submission references, provider status, test reviewer and timestamps. The hosted test URL is also encrypted at rest because possession of the URL grants access to the test form.
- API access tokens exist only in request memory. No PDFs, recipient tax IDs, full API responses or request payload logs are written by this connector. Other debugging or HTTP-logging plugins can inspect WordPress requests, so do not enable payload/header logging for these calls.
- There is no webhook to configure in this release. The **Check test status** action checks the provider API. Production synchronization will be added with the member-facing integration.

Changing WordPress security salts makes stored credentials/links unreadable. Enter the credentials again and select the existing sandbox Business ID to resume testing. Removing the sandbox connection deletes the local credentials and test state only; remote TaxBandits test data remains in that service. Deactivation preserves the saved settings. No member accounts, coupon assignments, UAP earnings, store credit, payout requests or production W-9 approvals are changed.

## Troubleshooting

- **Authentication/access rejected:** confirm these are sandbox credentials, replace all three together if needed, and check the TaxBandits API account is enabled. Time-based authentication also requires a correct server clock.
- **Host security salts unavailable:** the connector requires real AUTH_KEY and AUTH_SALT configuration outside the database. Do not paste the API keys into a public file as a workaround.
- **Could not be reached:** check outbound HTTPS access to `testoauth.expressauth.net` and `testapi.taxbandits.com`.
- **Unexpected form host:** only the documented `https://testlinks.taxbandits.io` sandbox host is allowed. Verify any changed provider domain before changing that allowlist.
- **Unsuccessful API response:** use TaxBandits' sandbox API logs to inspect the specific validation error. The plugin deliberately avoids echoing raw provider errors, which can contain sensitive values.
- **Incomplete/TIN-pending/unknown status:** no test approval is possible until the provider reports `COMPLETED`. Testing does not request optional TIN matching.
- **Private W-9 storage warning elsewhere in Affiliate Management:** this is the existing production payout gate. It does not stop the independent sandbox tests above. Do not try to configure unsupported filesystem paths on WordPress.com.

## After the sandbox test succeeds

Record the results in your sandbox console and follow TaxBandits' **Go Live** process, including their pricing/license agreement. This version has no live credentials fields or live API toggle. Production member collection, account-linked status synchronization, protected provider review and the production readiness replacement must be implemented and verified before enabling real customer W-9 collection. Sandbox approvals will never be migrated to real member approvals.

## API references checked September 14, 2026

- [Authentication](https://developers.taxbandits.com/docs/apireference/oauth2.0authentication/): JWS in the `Authentication` header, then the returned access token in API `Authorization: Bearer` headers.
- [Versions and endpoints](https://developer.taxbandits.com/docs/apiversioning/): W-9 endpoints currently use the supported 1.7.3 API; the 2.0 documentation still directs unsupported form types to 1.7.3.
- [Create business](https://developers.taxbandits.com/docs/business/create/), [Get business](https://developers.taxbandits.com/docs/business/get/).
- [RequestByUrl](https://developers.taxbandits.com/docs/formw9/requestbyurl/), [FormW9 Status](https://developer.taxbandits.com/docs/formw9/status/).
- [Production onboarding](https://developers.taxbandits.com/docs/overview/).
