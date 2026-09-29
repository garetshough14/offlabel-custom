# Account Hub 1.2.6 verification

- 171 checks passed in the disposable local WordPress fixture, including 69 existing affiliate/checkout checks and 102 TaxBandits sandbox checks. Provider responses are synthetic and intercepted; the new FormW9/Get request has not yet been tested against the user's TaxBandits account.
- PDF viewing checks cover administrator access, unfinished forms, stale references, exact business/recipient/submission matching, absent PDF links, unsafe hosts and paths, encrypted references, and discarding PDF URLs and tax data. Review rechecks document identity before recording approval or rejection.
- All existing financial and production W-9 tables remain unchanged by sandbox commands. Real payouts remain disabled according to their existing readiness gate.
- Local browser fixture passed at 320, 375, 768 and 1280 pixels: no overflow, protected POST forms, blank masked credentials and a visible new-tab viewer action. Desktop and mobile screenshots inspected.
- PHP syntax and git diff whitespace checks passed. ZIP contains 26 source-matched runtime/docs files, no vendor plugins or tests; ZIP CRC validated.
- Existing WooCommerce fixture warning about missing payment_method remains. The prior affiliate-surface failure expecting absent global-header Affiliate links is unrelated; customer frontend code was not changed or retested in this patch.
- Supported viewer transport: documented TaxBandits sandbox/development S3 HTTPS PDF URLs. Optional encrypted-PDF retrieval with additional provider credentials is not implemented; it fails closed with a diagnostic. No production/member-facing TaxBandits integration is enabled.
- Next external verification: upload the ZIP, check the existing completed test status, select View completed W-9, visually review the signed test PDF, return and record test approval. No new submission or credential entry is required.
