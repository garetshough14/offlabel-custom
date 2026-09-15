# Off Label Best Offer 0.3.2

Product volume pricing defaults: 3–4 bottles 20%, 5–9 bottles 25%, and 10+ bottles 35%. Each variation qualifies separately. Complete Build Your Box groups receive 25% for five bottles or 35% for ten.

Products with the complete former 3/10%, 5/15%, 10/20% schedule (including blank default fields) resolve to the new default schedule. Deliberate custom schedules, opt-out flags, and coupon override controls remain available. No product or order database migration runs on update. Previously saved order allocations still replay their recorded amounts.

Install Best Offer 0.3.2 before Build Your Box 1.3.5. Box versions 1.3.3, 1.3.4 and 1.3.5 pass the compatibility check during this update. This release adds compatibility for the builder's expired-nonce recovery; pricing and existing enable/preview settings are unchanged.

Run `php scripts/verify-best-offer-live.php` from the repository root. The suite uses the recorded WooCommerce 11.1.0 discount calculator and the source-equivalent Advanced Cart Offers 1.1.0 fixture. It verifies pricing allocation and order snapshots locally; it does not submit a real payment, order, email, or refund.
