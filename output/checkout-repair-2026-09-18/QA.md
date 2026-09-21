# Checkout repair — September 18, 2026

## Cause and change

The live Off Label Pricing panel reported WooCommerce 11.1.1 and **Live pricing: PAUSED — compatibility check failed**, with the sole compatibility error **WooCommerce 11.1.0 is required.** Best Offer 0.3.2's exact-version guard prevented all classic checkout sessions when live pricing was enabled. The combined cart/checkout showed WooCommerce's generic cart-error template, whose return link led to the same page.

Best Offer 0.3.3 explicitly accepts 11.1.0 and 11.1.1. Other versions retain the existing pause. No pricing calculations, saved settings, inventory, orders or payment configuration changed. The administrator version notice was updated to match.

## Evidence and validation

- Compared seven upstream files from the official `woocommerce/woocommerce` 11.1.0 and 11.1.1 tags: cart, cart totals, coupon, discounts, checkout, abstract order and checkout shortcode. All seven are byte-identical. See `source-comparison.json` and the downloaded sources in the version folders. The existing regression discount-calculator fixture also exactly matches the downloaded 11.1.1 file.
- `php scripts/verify-best-offer-compatibility.php`: both audited versions start live pricing without pausing checkout; 11.1.2, 11.2.0, 11.0.0, 11.1.1-rc.1 and a missing version still block checkout and order creation.
- `php scripts/verify-best-offer-live.php`: 4,166 assertions pass with WooCommerce 11.1.0.
- `OLR_TEST_WC_VERSION=11.1.1 OLR_TEST_WC_DISCOUNTS=output/checkout-repair-2026-09-18/11.1.1/includes/class-wc-discounts.php php scripts/verify-best-offer-live.php`: 4,166 assertions pass with the freshly downloaded 11.1.1 calculator.
- Both changed plugin PHP files and the new regression runner pass PHP lint; `git diff --check` passes.

## Deployment and live check

Applied the two changed PHP files through the existing WordPress Plugin File Editor. Compared the installed originals with repository originals before editing, and verified staged editor contents against the tested source before each save.

After deployment, the pricing panel reports **Live pricing: ON for all shoppers**, with WooCommerce 11.1.1 and private preview off. Reloading the user's existing `/cart/` now renders the contact form and both the five-bottle Research Box and the individual RT-3 item. Box subtotal $275.00, box price $206.25, automatic savings $68.75; combined merchandise subtotal $375.00, shipping $8.99, tax $25.27, total $340.51. The error loop is gone without changing cart contents.

No order, charge, email or refund was submitted. Payment completion was not tested.

## Artifacts

- Install package: `wordpress-plugins/_deploy/off-label-best-offer-v0.3.3.zip` (six runtime files, verified against source).
- Original edited files and `off-label-best-offer-v0.3.2-rollback.zip` are saved in this directory. Rolling back to 0.3.2 while WooCommerce remains 11.1.1 would restore the compatibility pause.
- `release-hashes.json` records the packaged source hashes.
