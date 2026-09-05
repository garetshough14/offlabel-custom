# Checkout 1.4.3 targeted candidate — 2026-09-04

Based on the user's FileZilla download at `C:/Users/User/Downloads/off-label-checkout-test`, version 1.4.1. Original download and tracked local 1.4.2 were preserved. This is NOT a merge of the local 1.4.2 overlay changes.

## Findings and scope

- Confirmed shipping display cause: JS moves the native shipping-method list out of its table row and CSS hides that row. A separate, server-rendered summary now uses WooCommerce's own shipping-total formatter; it is refreshed with the native review-order fragment and never changes charges. Free shipping and native tax/currency formatting are retained. Native row remains visible without enhanced JS. Multiple shipping-package selectors are moved, and stale selectors are cleared on a fresh response without rates.
- Apply was already type=button, so the exact cause of the reported live submission has NOT been reproduced. It was still inside the checkout form with no event isolation. An intentionally broad delegated click listener reproduces an unwanted WooCommerce order-endpoint call against original 1.4.1 in a local fixture. New code prevents that simulated conflict. This is defensive hardening, not proof of the exact production handler.
- Coupon input/button have a separate external form owner; no nested forms. Click propagation/default and Enter are handled locally. Native coupon-form submit is handled locally. A pending-request guard suppresses duplicate coupon calls. WooCommerce's pre-order event denies order submission during a coupon request or while promo controls are focused; deliberate Place order still reaches the mocked order endpoint.
- No pricing, coupon restrictions, cart allocation, gateway configuration, taxes, stock, order persistence, or private-preview protections changed. The independent Best Offer private preview must remain active during user testing.

## Local checks

- `node scripts/verify-checkout-1.4.3.cjs --baseline`: original source reproduces the simulated broad delegated-click conflict at 390/1440px.
- `node scripts/verify-checkout-1.4.3.cjs`: actual WooCommerce 11.1.0 checkout JS with mocked AJAX endpoints, real jQuery; click, Enter, form submission, type=submit mutation, duplicate click, request failure/retry, fragment recreation, paid/free shipping display, multiple packages, clearing unavailable rates, no horizontal overflow, deliberate non-preview Place order. All requests are intercepted locally. No real order/payment requests.
- PHP shipping rendering fixture: paid/free/native tax markup, address pending, virtual carts, other sessions/missing session; PHP lint.
- Best Offer suite: 4078 assertions, unchanged engine source. Mock persistence; no actual order/email/refund/payment test.
- Desktop/mobile screenshots viewed. They are simplified local fixtures, not exact live-page renders.
- ZIP entries verified byte-for-byte against candidate or original source; each has only the three runtime plugin files under `off-label-checkout-test/`.

Reference sources used to check hooks/event behavior:
- https://raw.githubusercontent.com/woocommerce/woocommerce/11.1.0/plugins/woocommerce/client/legacy/js/frontend/checkout.js
- https://raw.githubusercontent.com/woocommerce/woocommerce/11.1.0/plugins/woocommerce/templates/checkout/review-order.php
- WC_Cart::get_cart_shipping_total in locally audited WooCommerce 11.1.0 source.

## User private-preview acceptance (not yet done)

1. Save rollback ZIP. Replace only Off Label Checkout using candidate ZIP, not Best Offer/Build Your Box/ACO. This updates checkout presentation for visitors too; the private pricing engine itself remains admin-only. Use a quiet period.
2. Hard refresh checkout. Verify private preview banner before pricing tests. Leave payments and real order submission disabled.
3. Three standalone $65 MOTS-C bottles: apply `testbogo` via mouse; product amount $130, coupon $65, automatic $0. No order-validation/payment/terms errors. Remove coupon: $175.50, automatic $19.50.
4. Apply `test` with Enter: product $175.50, coupon $0, automatic $19.50. Repeat a coupon action after changing quantity. Apply an invalid code: coupon error only, Apply becomes usable again.
5. Shipping $8.99 is visible in summary and matches selected native method. If a permitted free-shipping method is actually selected, summary reflects native free shipping after refresh. Test desktop/mobile and information/shipping steps.
6. If the order-submission error recurs, stop, preserve screenshot and action sequence. Needs browser/network reproduction with full production plugin stack; do not disable preview protections.
7. If checkout regresses, replace with rollback ZIP (downloaded 1.4.1 runtime files). No database migration/rollback required by this update. End private preview with its dedicated button when testing is finished.

Outstanding before public pricing release: full-stack Apply acceptance, non-admin isolation control, other pricing edge cases and genuine isolated order/email/refund/payment testing. Do not treat local mocked tests as a public-release signoff.
