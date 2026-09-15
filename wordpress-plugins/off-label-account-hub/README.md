# Off Label Account Hub 1.2.4

## Desktop menu correction (1.2.4)

The mobile **Account menu** control is explicitly hidden on desktop, including when theme button styles override its display. It remains above account content at widths up to 800px, with state reset when resizing across that breakpoint. Includes all 1.2.3 coupon and color changes.

An account layer for Ultimate Member, WooCommerce and Ultimate Affiliate Pro (UAP). All changes live in this plugin; vendor plugin files are neither edited nor bundled.

## Custom coupon codes and discounts (1.2.3)

The account palette uses black, cream and neutral gray, including native UAP labels, tables, statuses and Ultimate Member privacy buttons.

Members use **Affiliate dashboard > Your code > Choose your own code**. Codes accept 3–32 ASCII letters, numbers, hyphens and underscores; availability is case-insensitive and checked against WooCommerce coupons (including draft/trash) and UAP assignments. Members cannot set discount amounts. Previous codes remain working and attributed to the same affiliate. Selecting an existing own code is safe to repeat. Concurrent member claims are serialized through the existing hub transaction lock. Code creation does not change affiliate IDs, ranks, referrals or commission settings.

Administrators use **Ultimate Affiliate Pro > Affiliate Management > Affiliate coupon discounts** for the default customer percentage. The initial default is **20%**. Saving updates existing hub-managed coupons that inherit the default and sets the discount for future codes. Unrelated WooCommerce promotions and externally created affiliate coupons that have not been brought under this control remain unchanged.

For an individual rate, search for the member in **Member eligibility** and set **Individual customer discount (%)**. Saving updates that affiliate's active UAP-assigned WooCommerce coupons, including previous codes; blank restores the default. Overrides survive later default changes. This controls the customer's coupon discount, **not the affiliate's commission rate**, which remains in UAP. WooCommerce's coupon editor also supports individual amounts, but use the hub's affiliate override to retain that rate across global hub updates.

Member offer copy uses the actual selected WooCommerce coupon amount. Public offer copy and examples use the configured default. Existing coupon restrictions and UAP commission overrides carry forward to a newly chosen code. Keep editing affiliate assignments through UAP and the hub; third-party/direct database imports do not participate in the hub's claim lock.

No new database tables, automatic enrollment or payout enablement are introduced by this update. Tax documents and settlement data remain unchanged.

## Account cleanup (1.2.2)

All account tabs use a compact type scale (25–28px page titles, 19–20px section titles, 14px body text). Earnings display one amount per labeled row. Mobile orders, recent commissions, native referral reports and payout/credit history display complete labeled values without the old UAP 576px minimum width. Native account forms keep 16px inputs. UAP's obsolete payment-setting warning is suppressed only in the hub.

Existing active affiliates missing a live UAP coupon assignment now prepare a code automatically through an authenticated, nonce-protected POST when viewing their dashboard. Without JavaScript, **Prepare my code** submits the same protected action. Retries reuse the assigned coupon; suspended accounts cannot create codes. No affiliate is enrolled automatically and existing codes/ranks/history are preserved.

**Why W-9 upload can be unavailable:** installing the ZIP does not provision private server storage or an encryption key. Affiliate Management now identifies missing configuration constants separately from missing PHP Sodium, filesystem access, invalid keys and upload limits. The member message directs users to support. The host must finish the private-storage setup below; there is no public-upload fallback.

## Customer dashboard and ShipStation tracking (1.2.1)

The default Dashboard uses the supplied reference's cream background, condensed headings, thin dividers, recent-order summary and help/shop links. The greeting, order number, date, status, item quantity and total are real account data. New customers see an empty state. At narrow widths, navigation becomes an accessible expandable menu, summary fields rearrange and order rows stack. The display font is bundled locally under its SIL Open Font License (`assets/fonts/OFL.txt`).

Orders and Tracking show parcel numbers and carrier links; order details show every recorded package and shipping date. The adapter uses WooCommerce order CRUD and enforces signed-in ownership. It supports ShipStation for WooCommerce **5.3.5**, WooCommerce Shipment Tracking metadata, and historical standard English ShipStation order notes. Only parsed shipping fields are shown; arbitrary/private note bodies are never displayed. Multi-package shipments are retained and repeated notifications are deduplicated. Standard USPS, UPS, FedEx and DHL numbers get carrier links; supplied custom tracking URLs are supported. Unknown carriers without a URL still show the tracking number.

**ShipStation setup:** keep **Notify Marketplace** enabled when shipping so ShipStation sends fulfillment information back to WooCommerce. No new API key or separate tracking subscription is required by Account Hub. The existing authenticated ShipStation XML/REST callback supplies new shipment data through its public hook. Historical notes with a customized or translated format may need structured tracking metadata; unrecognized notes are not guessed. Without tracking data, the account shows a pending-tracking message. Delivery estimates are not fabricated or fetched from carrier APIs. See [WooCommerce's ShipStation shipped-orders documentation](https://woocommerce.com/document/shipstation-for-woocommerce/#shipped-orders).

Affiliates also land on the customer Dashboard; their original referral code, earnings, reports and payout tools remain under **Affiliate dashboard** and the affiliate navigation entries. Activation returns directly to that affiliate view. Account Details keeps Ultimate Member's form handling; Addresses shows WooCommerce's saved addresses and links to its native edit-address flow.

## Member flow

| Action | Requirements | Result |
| --- | --- | --- |
| Activate affiliate account | Signed-in eligible member accepts published terms | Immediate existing UAP dashboard, referral link and coupon |
| Convert to store credit | At least $50 of approved, unpaid commissions aged 30 days | Full available commissions become reusable credit immediately; no W-9 required |
| Request Zelle | Same minimum and hold, currently approved W-9, recipient name and enrolled email/US mobile | Full available commissions reserved for monthly manual processing |

Pending applicants can activate. Historical rejection, explicit blocks, suspended UAP records, unapproved Ultimate Member accounts and administrators cannot self-enroll. Restoring eligibility is an explicit administrator action. Activation preserves WordPress roles, UM roles, existing affiliate IDs, ranks and earnings. New affiliates receive the configured active UAP default rank and a unique first-order WooCommerce coupon at the configured default customer discount (initially 20%), associated through UAP. Existing live UAP coupon assignments take precedence over old display metadata. Coupon commission overrides remain empty so configured UAP commission rules apply.

Members choose conversion; earnings are never automatically converted. Credit has no expiry, transfer or cash redemption. A W-9 is never required from the member for store credit.

## Requirements and compatibility

- WordPress with Ultimate Member's existing Account page at `/account/`.
- WooCommerce classic checkout (including the existing Off Label Checkout integration); HPOS supported through order CRUD. Checkout Blocks are not implemented by this adapter.
- UAP **9.7.7**: its creation, rank, coupon and settlement methods were inspected and exercised locally. Other versions fail the payout readiness check until the adapter is reviewed.
- UAP and WooCommerce must use the same currency and two decimal places. Current policy is USD ($50 minimum). Changing currency does not convert existing balances.
- PHP Sodium, Fileinfo, MySQL/MariaDB advisory locks and InnoDB transaction support. PHP upload limit at least 10 MB and POST limit at least 12 MB.
- A host-provided writable directory and a readable encryption key file, both outside every publicly served directory. Database-only hosting access is insufficient for W-9 setup.
- Existing GitPress inner-shortcode rendering for the public pages.

## Upgrade and setup

No staging site is required for these instructions. Local test coverage and its remaining limits are recorded in the release QA report. This ZIP does not install itself or enable payouts.

1. Back up the database and current plugin. Before upgrading, reconcile any existing native UAP pending payments and wallet coupons against transfers already sent. Do not mark previously sent funds unpaid. New payouts remain blocked while native UAP payment records have pending status; native payout administration is replaced after upgrade.
2. Upload `off-label-account-hub-v1.2.4.zip` as an update to the existing Account Hub plugin. The installed main file must be `wp-content/plugins/off-label-account-hub/off-label-account-hub.php` with exactly one plugin directory level. Keep Ultimate Member, UAP, WooCommerce and existing checkout plugins installed.
3. Keep the existing `/account/` page assigned in Ultimate Member. Its GitPress content remains:

   `[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/account.html" branch="main" format="html" updated_meta="false"]`

4. Open **Ultimate Affiliate Pro > Affiliate Management**. Confirm a backup is available and use **Prepare transactional database storage** while payouts are disabled. UAP 9.7.7 creates some MyISAM tables; this action changes only storage engines to InnoDB for UAP affiliates, coupon assignments, referrals and payments, plus WordPress posts/postmeta/usermeta if necessary. It preserves rows and does not edit vendor PHP. Large tables may require a host maintenance window. Activation also requires its affected tables to support transactions.
5. Configure an active default registration rank in UAP and save the published HTTPS affiliate terms URL and notification email in Affiliate Management. Review published terms against the new activation and payout policy.
6. Configure private W-9 storage as described below. Keep the key out of WordPress options, the Media Library, this repository, email, and public directories.
7. Resolve every readiness message in Affiliate Management, then select **Enable payouts after completing the installation checks** and save. Both conversions and Zelle fail closed until private storage and settlement checks pass. Activation does not require a W-9.
8. Keep the existing public pages using `[olr_affiliate_landing]` and `[olr_affiliate_guidelines]` through their GitPress fragments. The plugin creates missing pages as drafts; it does not republish pages or change the global header. Purge account/public-page asset caches and exclude authenticated account pages from CDN/page caching.
9. On the installed site, check member login, profile, order history, code/link copying, and the current checkout gateway's handling of a mixed-credit order before processing real payouts. These live gateway and full-theme checks were not performed as part of the local release tests.

### Private W-9 configuration

**WordPress.com hosting:** the live site's current error identifies missing `OLR_AFFILIATE_PRIVATE_DIR` and `OLR_AFFILIATE_W9_KEY_FILE` definitions; this does not establish a Sodium failure. WordPress.com documents Sodium and Fileinfo as available and allows `wp-config.php` editing through SFTP. Its documentation does not establish an available persistent directory outside the public root for this site. First obtain host confirmation of suitable storage and key paths; do not paste the example paths below unchanged. See [W9-HOSTING-SETUP.md](W9-HOSTING-SETUP.md) for the exact support request and completion steps.

Have the host create separate private storage and secrets directories outside the document root, owned by the PHP service account. Recommended permissions are 0700 for directories and 0600 for files (or equivalent restricted ACLs). Neither location may have a public web-server alias or CDN mapping. If the host cannot provide this, leave payouts disabled; do not substitute `wp-content/uploads`.

Generate the key **once**, outside the web root, without printing it:

```php
<?php
// Run from a trusted host CLI. Replace this example path before running.
$path = '/srv/offlabel-secrets/w9.key';
$handle = fopen($path, 'x'); // Refuse to overwrite an existing key.
if (!$handle) { throw new RuntimeException('Key file already exists or cannot be created.'); }
fwrite($handle, base64_encode(random_bytes(32)) . PHP_EOL);
fclose($handle);
chmod($path, 0600);
```

Add the following deployment-specific constants to `wp-config.php` before WordPress loads:

```php
define('OLR_AFFILIATE_PRIVATE_DIR', '/srv/offlabel-private/w9');
define('OLR_AFFILIATE_W9_KEY_FILE', '/srv/offlabel-secrets/w9.key');
```

Uploaded PDF bytes are encrypted with Sodium secretbox and a fresh nonce. Only a random filename and review metadata enter the database. Authenticated administrator downloads require `manage_options` and an action nonce, decrypt in memory, and return attachment/no-cache headers. Files are never Media Library attachments or email attachments. Members attest that the PDF is signed; an administrator must inspect the form and signature before approval. File checks do not certify a signature or act as a general malware scanner.

Back up the database, encrypted files and encryption key together under restricted access, keeping the key separate from document backups. Replacing or losing the key without re-encrypting existing files makes those files unreadable. Deactivation and plugin deletion do not purge the ledger or private documents. There is no automatic retention purge in 1.2.0.

## Administrator operations

- **Block affiliate access** preserves the member, UAP record and financial history, sets UAP inactive and prevents self-reactivation. **Restore eligibility** clears the explicit block and restores an existing suspended affiliate record without changing the member's roles.
- Legacy application metadata remains in usermeta for reference. No migration enrolls members; legacy rejected decisions remain restricted. New application forms, questions, review screens and application emails are retired.
- W-9 states are Missing, Pending review, Approved and Needs replacement. Only the latest document can be approved. Replacement immediately invalidates prior approval for new and queued Zelle requests. Review notes are visible to members; do not include tax IDs in notes.
- The Zelle queue freezes recipient details on submission. Refresh the queue immediately before sending externally; eligibility failures display **Do not send**. After the external transfer succeeds, record its unique Zelle confirmation. Completion checks current approval, affiliate status and every reserved referral again. A retry records the same settlement without issuing another credit or transfer; this plugin never sends Zelle itself.
- Reject a request to release its reserved commissions. If commissions changed or were refunded, reject the pending request and have the member resubmit the remaining eligible balance. Do not reject a transfer that has already been sent: reconcile that transfer and its confirmation first.
- Native enrollment, wallet conversion, payout/payment-setting submissions, relevant REST/AJAX mutations and native automatic payment cron are intercepted while Account Hub is active. Report data remains in UAP. Existing one-use wallet coupons are not imported into the reusable ledger.

## Credit accounting and checkout

The hub owns versioned `olr_aff_requests`, `olr_aff_reservations`, `olr_aff_balances`, `olr_aff_ledger` and `olr_aff_documents` tables using the site's table prefix. Balances and ledger entries use integer minor units. A shared site lock, database transaction, unique request/ledger identifiers and unique referral reservations protect competing submissions. Successful conversions record a completed native UAP payment and paid referral status in the same transaction as the credit. Interrupted transactions roll back; a lost success response can be retried using the same operation key. Native UAP payment details also retain the hub method and frozen recipient snapshot.

The classic checkout offers **Use store credit**. Credit is applied after coupons, shipping and taxes. Order creation reserves the selected credit; payment completion/processing captures it. Failed or canceled unpaid orders release reservations. Credit is reserved during pending/on-hold orders until payment or cancellation, including WooCommerce's configured unpaid-order cancellation behavior. A late payment whose credit cannot be recaptured is put on hold with an administrator reconciliation note.

**Order totals and reporting:** WooCommerce's payable order total is the remaining cash amount so existing gateways charge that amount. Line totals and tax amounts remain unchanged. HPOS-compatible order metadata `_olr_credit_gross_total` and `_olr_credit_amount` retain gross payable value and credit tender in minor units; `_olr_credit_currency` records currency. Customer order totals display gross, credit used and remaining payment. Native analytics/exports that read only `get_total()` will report cash due, not combined tender value; combined sales reporting must include this metadata and the credit ledger. Credit is not implemented as a coupon or a negative tax-affecting fee.

**Refunds:** For a mixed order, enter only the cash portion in WooCommerce's refund amount. The hub returns the matching proportion of credit automatically, using cumulative cash refunds and rounding once. Example: an $88 gross order paid with $50 credit and $38 cash; a $19 WooCommerce cash refund restores $25 credit. Refunding all $38 restores all $50 credit. For credit-only orders or an explicit credit-only adjustment, use **Affiliate Management > Return store credit for an order**. This control does not refund cash, restock items or change order status. Total credit returned is capped at the original captured credit; retries do not duplicate it. Order notes flag any reconciliation failure.

## Verification and packaging

Repository tests are intentionally excluded from the install ZIP. `tests/run-integration.py` requires a disposable real WordPress/WooCommerce/UAP installation, a database named `olr_affiliate_120_test` on `127.0.0.1:33317`, and the private-storage constants above. Never point it at production. Set `OLR_TEST_WP`, `OLR_TEST_PHP` and `OLR_TEST_PHP_INI`, then run the Python script. It creates synthetic data, suppresses outgoing mail and starts a temporary loopback HTTP upload fixture. It does not send money.

Run `scripts/package-account-hub.py` from the repository to rebuild the versioned ZIP and SHA-256 checksum. Packaging includes runtime PHP, templates, assets and this README, excludes tests and private files, and verifies ZIP topology.

The pre-existing `scripts/test-affiliate-surfaces.ps1` failure expects Affiliate links in the global desktop/mobile header, where those links are currently absent. This release does not alter the header; that failure is separate from the new integration results.

## Rollback and future updates

Keep the previous plugin ZIP for rollback. Once new balances or pending Zelle requests exist, disabling/reverting Account Hub also disables its route guards and checkout credit handling. Stop payout activity and reconcile outstanding reservations before reverting; do not resume native payouts against hub-reserved earnings. Preserve all hub tables and private files. No uninstall hook deletes financial or tax-document data.

After UAP, WooCommerce, Ultimate Member, checkout or gateway updates, rerun local compatibility checks and review live account/checkout behavior before enabling affected payment operations. A UAP version change automatically blocks new hub settlements until its adapter is reviewed; it does not erase balances or history.
