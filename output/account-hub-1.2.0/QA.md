# Account Hub 1.2.0 release verification

Verified 2026-09-08 America/Los_Angeles (2026-09-09 UTC). Code and install ZIP are local. Nothing was installed on production, no real member records were changed, no tax documents were retrieved, and no payments or emails were sent to customers. No staging site was created or required.

## Release

- ZIP: `wordpress-plugins/_deploy/off-label-account-hub-v1.2.0.zip`
- SHA-256: `c25108c92b98b7a27b95141ffccb3a9b7670ba87bbce45c7fb27a7e25a5c327b`
- 18 packaged runtime/documentation files; one top-level `off-label-account-hub` directory. ZIP CRC and byte-for-byte comparisons with source passed. Tests, fixtures, secrets and vendor plugins are excluded.
- Changes are scoped to Account Hub, its tests/docs, the packaging script and QA artifacts. Ultimate Member, UAP and WooCommerce vendor files are untouched.
- Setup and accounting instructions: `wordpress-plugins/off-label-account-hub/README.md`.

## Environment

Read-only production plugin inspection confirmed WordPress 7.1, UAP 9.7.7, WooCommerce 11.1.0, Ultimate Member 2.13.0, Off Label Checkout 1.4.4 and existing Account Hub 1.1.1.

Local tests used WordPress 7.1, WooCommerce 11.1.0 with HPOS enabled, the available original UAP 9.7.7 source, PHP 8.5.10 and MariaDB 11.4.8. UAP's actual creation, rank, coupon mapping, referral attribution and payment methods were exercised. Synthetic members/orders and loopback services were used. Outgoing mail was suppressed. Test scripts refuse databases outside their named disposable fixture.

## Passed

`integration.log` records **69 integration assertions** plus independent concurrent request and real HTTP upload checks:

- Terms, pending applicant activation, configured default rank, preserved roles/history, existing-code reuse, legacy rejection, explicit block/restore, administrator refusal and absent dependency handling.
- Live coupon assignment overrides stale metadata. A real new-customer WooCommerce order receives the 20% coupon discount and creates an attributed UAP referral using configured commission rules.
- $49.99/$50 and 30-day boundaries; ineligible/paid/wrong-currency referrals excluded; request ownership, reservations, rejection release, changed/refunded commissions and replay protection.
- Zelle refuses missing/pending/replaced W-9 states; only latest documents can be approved; pre-send queue validation and completion both check approval. UAP settlement retains frozen recipient details.
- Injected ledger/UAP failures roll back credit and referral settlement. Separate PHP processes test same-key retries, different-key competing payouts, concurrent first activation and simultaneous credit spending.
- Actual HPOS checkout calculates an $88 gross order after a 20% coupon and 10% tax; $50 credit leaves $38 cash due with tax unchanged. Reservation, capture, failed-payment release/retry, partial and full native refunds, full-credit checkout and capped administrator credit refunds pass.
- Five direct native mutation routes, REST enrollment, native creation filter and invalid CSRF nonce are rejected.
- Real multipart HTTP uploads reject non-PDF data, wrong extensions, missing signature attestation, explicit active PDF content and oversized uploads. Valid synthetic PDFs are encrypted; member download is denied; administrator download returns original bytes with private headers; ciphertext tampering fails closed.
- All 13 PHP files pass lint (`php-lint.log`). Existing account logout redirect passes (`logout.log`). `git diff --check` passes.
- Chrome inspection of actual PHP-rendered payout markup at 390px and desktop widths: cards stack, labels/buttons fit, amounts remain readable, and recipient/W-9 fields and histories remain present. This was a local isolated layout preview, not a full production-theme session.

## Existing failure, separate from regressions

`scripts/test-affiliate-surfaces.ps1` still fails **Desktop and mobile headers include Affiliate** because it expects two `data-nav="affiliate"` links and the existing global header has neither. The preceding 12 surface checks pass. The script terminates at that assertion. See `existing-affiliate-surface.log`. This release does not modify the header.

## Deployment requirements and verification limits

The release defaults to payouts disabled. Hosting must supply a writable private document directory, a separate readable key file outside the public root, Sodium/Fileinfo and sufficient upload limits. Affiliate Management also checks the UAP version, matching currency, transaction engines and pre-existing native pending payments. The explicit database preparation action requires a current-backup acknowledgement. Activation needs an active default rank and published terms.

The actual production gateway, Ultimate Member login/registration/profile forms, complete live theme, referral-link cookie round trip, clipboard behavior and large-table engine conversion were not exercised end-to-end. Existing wrapper/report/copy code remains; do not treat preservation as a passing live regression test. There was no live installation or real financial transaction. Private host filesystem access and deployment configuration remain outstanding.

Classic checkout is supported; Blocks are not included. WooCommerce payable order totals reflect cash due. Combined-tender reports must include the preserved gross/credit order metadata and ledger. For mixed refunds, administrators enter the cash refund and the hub returns proportional credit; for credit-only refunds use Affiliate Management. These accounting semantics and rollback constraints are described in the README.

Deactivation preserves hub tables and encrypted documents but stops enforcement hooks. Reconcile outstanding requests/orders before reverting or returning to native payouts. Keep the key with restricted, separate backups; do not regenerate it for an existing document collection.
