# Checkout 1.4.4 — responsive order summary

Supersedes candidate 1.4.3. Built from that candidate (itself based on the exact downloaded live 1.4.1), not the separate tracked 1.4.2 source. Includes coupon isolation and server-rendered native shipping total from 1.4.3. Neither original download nor live site changed.

## Presentation changes

- At 900px and below, Your order appears before contact/address fields. DOM reading/tab order matches visual order. Larger screens keep information/shipping side-by-side; the existing single-column payment stage remains. Resize moves existing elements rather than cloning controls.
- Original and discounted product prices occupy separate lines in a bounded column. No body clipping used as a fix. Totals/coupon messages wrap within the table.
- Native metadata dt/dd pairs are grouped so labels do not float away from values. Applied offer gets a restrained label treatment. Quantity/Remove controls stay together. Box contents and edit/remove actions are retained. Redundant line-price "Offer included" text is hidden, not coupon explanations or named offers.
- Native WooCommerce order-review replacements are normalized once per new row. Cart keys, quantity controls, event listeners, coupon form ownership and values survive rearrangement. No discount calculation or order metadata changes.

## Checks completed locally

- `node scripts/verify-checkout-layout-1.4.4.cjs`: 320/360/390/768/900/1024/1440px; information/shipping/payment stages; DOM ordering; prices/text bounding rectangles within summary; long names and four-digit prices; regular product, variation, box contents; original +/Remove payloads; retained box edit URL; idempotence; AJAX replacement; resize with unsent coupon value retained. Box and Best Offer CSS loaded after checkout CSS to test override specificity.
- `node scripts/verify-checkout-1.4.3.cjs --1.4.4`: actual WooCommerce 11.1.0 checkout JS plus real jQuery, mocked network. Coupon mouse/keyboard/form-submit conflict defenses, failure/retry, duplicates, fragments, shipping states and deliberate non-preview order action remain intact.
- `verify-checkout-1.4.3.php --1.4.4`: native shipping formatting and session guards; PHP lint.
- `verify-best-offer.php`: all 4078 assertions pass; engine untouched.
- Simplified desktop/mobile fixtures rendered and visually inspected. Not a full production-stack browser test. The exact live Apply conflict still needs user acceptance testing in private preview; do not infer public pricing readiness.

## Package and user test

Package: `wordpress-plugins/_deploy/off-label-checkout-test-v1.4.4.zip`. Three runtime files only, matching ZIP root `off-label-checkout-test/`. Earlier 1.4.3 is superseded, not required first. Existing 1.4.1 rollback ZIP remains valid. Original source and tracked checkout 1.4.2 left untouched.

1. Replace only Off Label Checkout with 1.4.4 via WP plugin upload. This changes checkout presentation for visitors too; use a quiet period. No real orders during private preview.
2. Hard refresh; verify preview banner. On mobile Your order should be before Contact; all original/final prices fully visible; named offers grouped with items; shipping row visible.
3. Three MOTS-C: `testbogo` gives $65 coupon savings and $130 product total. Remove gives $19.50 automatic savings and $175.50 product total. Mouse Apply must not produce terms/payment/order submission errors.
4. Change quantity and reapply coupon. Ensure new rows retain layout and controls. Inspect a complete box and its contents/edit links. Check information/shipping stages on phone and desktop columns.
5. If anything regresses, reinstall original 1.4.1 rollback ZIP and report screenshot/action. Do not disable preview protections to bypass errors.
