# Off Label Best Offer 0.3.3

Product volume pricing defaults: 3–4 bottles 20%, 5–9 bottles 25%, and 10+ bottles 35%. Each variation qualifies separately. Complete Build Your Box groups receive 25% for five bottles or 35% for ten.

Products with the complete former 3/10%, 5/15%, 10/20% schedule (including blank default fields) resolve to the new default schedule. Deliberate custom schedules, opt-out flags, and coupon override controls remain available. No product or order database migration runs on update. Previously saved order allocations still replay their recorded amounts.

Version 0.3.3 accepts the audited WooCommerce 11.1.1 maintenance release alongside 11.1.0. The earlier exact-version check paused every live checkout after WooCommerce updated to 11.1.1. The upstream cart, cart totals, coupon, discount, checkout, checkout shortcode and abstract order files are identical between these releases. Unverified versions still fail the compatibility check; pricing and existing enable/preview settings are unchanged.

Install Best Offer before Build Your Box 1.3.5. Box versions 1.3.3, 1.3.4 and 1.3.5 pass the compatibility check during this update.

Run `php scripts/verify-best-offer-compatibility.php` and `php scripts/verify-best-offer-live.php` from the repository root. Repeat the latter with `OLR_TEST_WC_VERSION=11.1.1`. The suite uses the recorded WooCommerce discount calculator (identical in 11.1.0 and 11.1.1) and the source-equivalent Advanced Cart Offers 1.1.0 fixture. `OLR_TEST_WC_DISCOUNTS` can select an independently downloaded calculator file. It verifies pricing allocation and order snapshots locally; it does not submit a real payment, order, email, or refund.
