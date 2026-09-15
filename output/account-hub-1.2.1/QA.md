# Account Hub 1.2.1: dashboard and tracking verification

Verified September 8, 2026 Pacific time. Implemented and packaged locally; production remains unchanged. This build includes the previously delivered 1.2.0 affiliate/credit/W-9 changes. No staging site was created or required.

## Changes

- Customer Dashboard follows the supplied visual reference: cream background, bundled condensed display font, slim sidebar, recent order/tracking/total summary, order/account actions, help links and shop link.
- Real customer names and order data; clear empty states. No invented shipment status or delivery estimate.
- Responsive account menu with keyboard support; two-column order summary with tracking beneath it on phones; stacked order-history rows. Account Details retains Ultimate Member forms and Addresses uses WooCommerce's address view/edit flow.
- Affiliate members also land on the customer dashboard. Affiliate dashboard is a separate navigation entry retaining the existing UAP code/link, reporting and payout functionality. Activation success redirects there.
- Tracking appears on the dashboard, Orders, Tracking and owned order details. Supports multiple packages, current ShipStation notifications, WooCommerce Shipment Tracking metadata and recognized historical English ShipStation notes. Unknown carriers without a supplied tracking URL show the number without a guessed link.
- No Ultimate Member, WooCommerce, UAP or ShipStation vendor code was edited. Bebas Neue is bundled with its OFL license.

## Test results

- **21 dashboard/tracking assertions passed** (`dashboard-tracking.log`), including the actual ShipStation 5.3.5 REST shipment processor calling the hub hook, HPOS persistence, retry deduplication, multiple parcels, standard/custom carrier URLs, historical note parsing, unsafe URL/markup handling, order ownership and guest access, empty states and real Ultimate Member shortcode rendering.
- **69 existing affiliate/payment integration assertions passed** (`affiliate-regression.log`), plus separate concurrent activation/payout/credit-spend processes and HTTP W-9 access/upload/tamper checks.
- Account logout regression passed (`logout.log`). All **16 PHP files** passed syntax checks (`php-lint.log`); both account JS copies passed `node --check`. `git diff --check` passed.
- Chrome visual inspection at **320px, 390px and 1100px** frame widths. Checked customer dashboard, multi-package order list, collapsed/expanded mobile navigation, readable long tracking numbers and outlined button contrast. Inspected actual Ultimate Member account markup with its account CSS loaded and the new hub CSS/JS. Fixed the native paragraph-margin, duplicate heading, hidden-tab, sidebar icon-width and box-sizing conflicts found during this check.
- Read-only production plugin inventory confirmed ShipStation **5.3.5**, Ultimate Member **2.13.0**, WooCommerce **11.1.0** and UAP **9.7.7**. Local tests loaded those plugin versions on WordPress **7.1**, PHP **8.5.10** and MariaDB **11.4.8**, with HPOS enabled. Tests used synthetic accounts/orders and suppressed outgoing email.

The pre-existing affiliate-surface test failure remains separate: the global header lacks the two Affiliate links expected by `scripts/test-affiliate-surfaces.ps1`. See the 1.2.0 QA report/log for that baseline; this change does not edit the global header.

## Setup and limits

Install the new ZIP to apply this change. Keep **Notify Marketplace** enabled in ShipStation so tracking is sent back to WooCommerce. Account Hub consumes the existing authenticated integration; it does not require a new ShipStation API key. Reference: [WooCommerce ShipStation shipped-order documentation](https://woocommerce.com/document/shipstation-for-woocommerce/#shipped-orders).

No real shipping label was purchased, no real customer order was updated, and the production callback/network connection was not tested. Local tests invoke the real notification processor and hook. Existing translated/customized note formats may require structured shipment metadata. Historical note fallback scans the latest 100 notes for each displayed order; new notifications are captured separately in order metadata. Carrier ETA/status API polling is outside this change.

The 1.2.0 private-storage and payout enablement setup still applies. No new ledger migration is needed for 1.2.1. Existing balances, commissions and documents are preserved. Live payment-gateway and full production-theme regression checks remain deployment work; do not treat local visual tests as production installation.

## Package

`wordpress-plugins/_deploy/off-label-account-hub-v1.2.1.zip`

SHA-256: `617fe358c0538f5ffe7a62b7bccdaf96fd3414bbd03a0b1ee1dcbb0851559820`

22 runtime/documentation files, one top-level plugin directory, verified ZIP integrity and byte-for-byte source matching. Tests, local fixtures, secrets and vendor plugins are excluded. Rebuild with `python scripts/package-account-hub.py`.
