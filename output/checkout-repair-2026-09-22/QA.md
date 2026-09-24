# Checkout repair — September 22, 2026

## Cause

The original shopper tab at `/cart/` displayed the generic WooCommerce cart-error template, with a return link to itself. The live Off Label Pricing administration page reported WooCommerce 11.1.2 and “Live pricing: PAUSED — compatibility check failed”, with the sole reason “WooCommerce 11.1.0 or 11.1.1 is required.” Private preview was off. Best Offer 0.3.3's exact patch-version allowlist repeated the September 18 outage after another maintenance update.

## Change

Best Offer 0.3.4 accepts stable WooCommerce 11.1.x maintenance releases, avoiding a new allowlist edit for each patch. Prereleases, malformed version strings, older series, and new release series remain blocked pending integration review. Other pricing dependencies retain their existing requirements. No price calculation, coupon rule, payment setting, order, or inventory configuration changed.

An actionable compatibility outage notice now appears throughout wp-admin for users with `manage_woocommerce` when live pricing is enabled but cannot start. The pricing panel documents the supported maintenance series and need to test before larger upgrades. Future maintenance acceptance is policy; unreleased implementations have not been tested.

## Source verification and tests

- Downloaded the official WooCommerce 11.1.2 package from https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip. The GitHub tag was unavailable; it was not used as evidence.
- Seven integration files (cart, cart totals, coupon, discounts, checkout, checkout shortcode, abstract order) are byte-identical to the previously audited 11.1.1 files. See `source-comparison.json`.
- Compatibility tests cover 14 version cases, including simulated future maintenance versions, prereleases, malformed versions and absent WooCommerce. They also verify the admin notice, diagnostic link, capability gate, intentional disabled state, and continued order blocking for unsupported versions.
- 4,166 regression assertions pass with the baseline calculator and again with the independently downloaded 11.1.2 calculator. These cover offer allocation, live opt-in, preview restrictions and saved-order replay. See `pricing-tests.txt` and `compatibility-tests.txt`.
- Both edited runtime files pass PHP lint. `git diff --check` passes.

## Deployment and live verification

Updated the two PHP files through the existing WordPress Plugin File Editor. Original editor contents matched local 0.3.3 backups by text length and FNV-1a fingerprint. Staged full editor buffers matched the tested replacement strings before save; reloading each editor confirmed the persisted source matched exactly. WordPress displayed “File edited successfully.”

The live pricing panel now reports “Live pricing: ON for all shoppers” on WooCommerce 11.1.2, with private preview off. The original shopper cart renders the contact form, Research Box and RT-3 item. Box price is $206.25, merchandise subtotal $375.00, automatic offer $68.75, shipping $8.99, tax $25.27, total $340.51.

Exercised the live asynchronous quantity control from one to two RT-3 items: subtotal $475.00, automatic offer unchanged at $68.75, tax $33.52, total $448.76. Restored the original quantity of one and confirmed the original $340.51 total returned. A subsequent reload retained that quantity, total and checkout form. The shopper's original cart contents are preserved.

No order, payment, customer email, refund or fulfillment was submitted. Payment completion and a separate guest session were not tested. No browser cache or customer session was cleared.

## Artifacts and limits

- Release: `wordpress-plugins/_deploy/off-label-best-offer-v0.3.4.zip`, verified to contain the six runtime files matching source. SHA-256: `f5b9eb0ba93ffdcd2ea4830c27f3defbdbb1c2d1c4cb797f37e54a2f45d10e5b`.
- Original edited files: `backup-0.3.3/`.
- Rollback: `off-label-best-offer-v0.3.3-rollback.zip`. Rolling back while WooCommerce remains 11.1.2 restores the compatibility outage; this is a source recovery artifact, not an operational remedy.
- Per-file release SHA-256 values: `release-hashes.json`.

This removes the observed patch-version outage. WooCommerce 11.2/12.x and other dependency upgrades still require compatibility testing before production deployment; this change is not a guarantee against every possible checkout failure.
