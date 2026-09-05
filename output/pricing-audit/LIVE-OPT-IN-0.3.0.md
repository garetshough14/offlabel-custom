# Best Offer 0.3.0 — live opt-in candidate

The user explicitly requested a live release and intends to place their own test orders, despite staging remaining unavailable. This package was prepared locally only. It is NOT a claim that the missing staging integration tests passed. No live site changes or orders were performed by the assistant.

## What changes

- Production pricing is controlled by `olr_best_offer_live_enabled`, default `no`. Existing staging settings and private preview grants cannot enable public pricing.
- WooCommerce > Off Label Pricing provides an explicit Live ON/OFF form. Saving requires both administrator/commerce capabilities, a valid nonce and the production environment. First enable also requires an acknowledgement that a recoverable backup exists and live testing risks are accepted. Active/marked preview carts must be cleared through End Preview before enabling. Disable does not require acknowledgement or successful compatibility checks.
- Existing exact WooCommerce/Box/ACO/ELEX version and reserved coupon checks remain. When live pricing is requested but compatibility fails, classic checkout receives a pause notice and final order creation is blocked, rather than silently taking orders under fallback pricing.
- Unsupported Blocks/Store API checkout/order/batch writes are blocked while this engine is active. Ordinary Store API cart operations/reads are untouched. This is a classic-checkout release, not Blocks integration.
- Allocation/replay handlers remain registered even when new-cart pricing is switched off, so recorded allocations remain available for previously created orders. This is covered by a fresh-hook-registry fixture. Do not deactivate or downgrade the plugin after taking orders without reviewing those orders.
- No pricing planner, coupon eligibility, preview purchase guard, checkout plugin, builder, payment plugin, shipping settings or coupon database changes. Old marked preview carts remain unpurchasable after live activation.

## Install and enable (user-operated)

1. Confirm a current recoverable site/database backup. Review `test`, `test2`, and `testbogo`: disable them or restrict them to the test buyer's email if they should not be usable by shoppers. Do not change normal promotional coupons unnecessarily.
2. Upload `off-label-best-offer-v0.3.0-LIVE-OPT-IN.zip` through Plugins > Add New > Upload Plugin, replacing Best Offer. Keep Checkout 1.4.4 and other plugins unchanged. Installing alone does not enable live pricing.
3. WooCommerce > Off Label Pricing: use End preview and remove its test items. This explicitly removes only marked preview cart items, not unrelated shopper carts.
4. Select Enable live pricing for all shoppers, read/confirm the backup and risk acknowledgement, and Save live pricing. Clear the website/page cache so selector HTML does not retain the prior mode. The setting shows ON for all shoppers when enabled and compatible.
5. Build a fresh cart outside preview. The user must review and submit any order. Ordinary live checkout can charge, email, decrement stock and trigger fulfillment; these are not sandbox orders. Do not change all shoppers' payment settings to test mode as a shortcut.

## Stop new pricing

Uncheck Enable live pricing for all shoppers and Save live pricing, then clear the website/page cache. Keep 0.3.0 installed and active so its saved-order allocation handler remains available. This stops new engine pricing on subsequent cart calculations; it is not cancellation/refund/rollback of existing orders. In-flight orders and real charges need separate review. Downgrading to preview builds or deactivating removes the newly persistent order adapter and is not an automatic safe rollback for orders already taken.

## Evidence and limitations

`verify-best-offer-live.php`: 4,155 assertions total (4,078 base, 36 additional offer/allocation cases, 41 live-control cases). Actual WooCommerce 11.1.0 discount calculator and ACO 1.1.0 with in-memory WordPress/product/order wrappers. Permission/nonce/default-off/acknowledgement/preview-marker/compatibility gates, guest live pricing, ordinary gateway passthrough, Store API boundaries, switch-off and saved allocation replay all pass locally. Selector actual WooCommerce variation lifecycle and five sizes pass; Checkout 1.4.4 actual JS in mocked-network mobile/desktop fixtures passes. All four runtime PHP files lint successfully on PHP 8.5.10. The admin page's ON/OFF/form/acknowledgement/capability text is checked with output fixtures, not a real WordPress admin session.

Not verified: actual order database persistence, tax-class/address integration, payment gateway/express-wallet behavior, delivered emails, partial refunds, actual stock/fulfillment side effects, or full admin edit integration. The current order adapter rejects changed quantities/prices and missing snapshots for manual review; it does not automatically reprice edited orders. Native account/usage/expiry database interactions still need integration checks. Support for Blocks/Store API order submission is deliberately blocked. User acceptance of live rollout does not make these tests passed.
