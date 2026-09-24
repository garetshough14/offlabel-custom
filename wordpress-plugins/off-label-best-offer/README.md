# Off Label Best Offer 0.3.4

Product volume pricing defaults: 3–4 bottles 20%, 5–9 bottles 25%, and 10+ bottles 35%. Each variation qualifies separately. Complete Build Your Box groups receive 25% for five bottles or 35% for ten.

Products with the complete former 3/10%, 5/15%, 10/20% schedule (including blank default fields) resolve to the new default schedule. Deliberate custom schedules, opt-out flags, and coupon override controls remain available. No product or order database migration runs on update. Previously saved order allocations still replay their recorded amounts.

Version 0.3.4 supports stable WooCommerce 11.1.x maintenance releases instead of an exact patch-version allowlist. The old check stopped every live checkout after updates to 11.1.1 and then 11.1.2, despite identical cart, cart totals, coupon, discount, checkout, checkout shortcode and abstract order implementations in all three releases. Prereleases, malformed versions and other release series still require an integration review. Existing pricing calculations and enable/preview settings are unchanged. A compatibility failure while live pricing is enabled now displays an actionable alert throughout wp-admin for users who can manage WooCommerce.

Before upgrading WooCommerce to a different release series, verify its pricing integration on staging and update the supported series in this plugin. Other pricing dependencies retain their audited version requirements. Future maintenance acceptance is a compatibility policy, not a claim that unreleased code has been tested.

Install Best Offer before Build Your Box 1.3.5. Box versions 1.3.3, 1.3.4 and 1.3.5 pass the compatibility check during this update.

Run `php scripts/verify-best-offer-compatibility.php` and `php scripts/verify-best-offer-live.php` from the repository root. Repeat the latter with `OLR_TEST_WC_VERSION=11.1.2`. The suite uses the recorded WooCommerce discount calculator (identical in 11.1.0, 11.1.1 and 11.1.2) and the source-equivalent Advanced Cart Offers 1.1.0 fixture. `OLR_TEST_WC_DISCOUNTS` can select an independently downloaded calculator file. It verifies pricing allocation and order snapshots locally; it does not submit a real payment, order, email, or refund.
