# Final checks — 2026-09-04

Scope: Best Offer Private Preview 0.2.1 and Checkout 1.4.4. The user authorized the remaining tests, then confirmed hosted staging is still unavailable. No public pricing enablement, live configuration changes, real orders, emails, charges or refunds were performed. Runtime plugin files and deployment ZIPs were not changed in this test round.

## Completed locally

- `scripts/verify-best-offer-final.php`: 4,114 assertions (existing 4,078 plus 36 additional checks). Actual WC_Discounts 11.1.0 and Advanced Cart Offers 1.1.0 code with in-memory WordPress/product/order wrappers; not a database integration test.
- Additional cases: product/category inclusion and exclusion precedence; variation-parent restrictions; exact minimum/maximum spend boundaries; whole-box coupon inclusion and exclusion; fixed coupon caps; buy-two-get-one quantity boundaries; cheapest/highest item selection; repeat/max-set limits; overlap enabled/disabled; BOGO qualifying spend; separate qualifying/target scopes; qualifying/target exclusions; excluded box components cannot unlock standalone BOGO; per-SKU payment/volume competition; payment switching; recorded allocation replay after coupon percentage changes; changed order price and missing snapshots fail closed; legacy orders unchanged.
- `scripts/verify-best-offer.cjs`: five viewport sizes, quotes, controls, badge bounds, sale/variation changes, authorized/denied private quote responses, four initial-selection timing scenarios using actual WooCommerce variation frontend code.
- `scripts/verify-checkout-1.4.3.cjs --1.4.4`: actual WooCommerce checkout JS in mocked-network fixtures; coupon click/Enter/form isolation, retry/duplicate/fragment behavior, deliberate order path, native shipping and multiple packages at desktop/mobile sizes.
- `scripts/verify-checkout-layout-1.4.4.cjs`: seven sizes from 320 to 1440px, summary position, long names/stacked prices/boxes, fragment replacement, controls and resize.
- `scripts/verify-checkout-1.4.3.php --1.4.4`: shipping-rendering fixture checks.

User-provided live-preview screenshots additionally confirm RT-3 strengths are independent: one 10-strength plus two 30-strength bottles yield no automatic savings; increasing the 30-strength to three yields exactly $79.50 savings on that line alone, with the 10-strength line unchanged at $100. Selector display fix was also confirmed by the user.

## Still blocked — not passed, not simulated away

1. Full staging checkout/order persistence with site-specific shipping/tax and sandbox or offline payment-method behavior. Preview intentionally blocks order submission and payment discounts; do not remove those protections to test on production.
2. Reconcile the stored order, confirmation page and captured customer/admin email totals. Capture email inside staging, without sending to real customers. Check real coupon usage/expiry/account restrictions as part of database integration.
3. Partial refund and admin order-edit/recalculation flows against persisted WooCommerce orders, plus staging verification of guest/non-preview isolation. Current candidate explicitly rejects edited quantities/prices requiring review; it does not promise automatic repricing of edited orders. No real charge or payment refund is authorized.

Next requirement: restore hosted staging or obtain an explicitly authorized isolated test copy. Before testing, confirm the staging URL/environment, matching plugin versions, disabled outbound customer email and fulfillment/webhooks, and sandbox/offline payment configuration. Use only synthetic test customer/order data. Do not publish pricing based on fixture assertions alone.
