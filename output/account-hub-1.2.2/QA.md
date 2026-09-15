# Account Hub 1.2.2 verification

Release ZIP: `wordpress-plugins/_deploy/off-label-account-hub-v1.2.2.zip`.
SHA-256: `cbfee92ce11af0b8d00e4bfbfc303a242b5e9e61b0b0e052abca65081032d024`.

## Changes

- Compact, scoped type scale across account, affiliate and native UM/UAP tabs; no vendor PHP edits.
- Earnings use separate labeled rows. Mobile tables no longer inherit UAP's 576px minimum; native commission and payout/credit history receive readable labels.
- Existing active affiliates without a live UAP coupon assignment prepare one through nonce-protected POST automatically, with a no-JavaScript form fallback. Existing assignments, IDs, ranks and roles are preserved. Suspended accounts cannot repair codes.
- Suppress obsolete native payment-settings warning in the hub, correct instant-credit/monthly-Zelle copy, fix support-button contrast.
- Separate missing W-9 configuration constants from missing PHP Sodium diagnostics; add protected admin setup guidance. No public-upload fallback.

## Passed locally

WordPress 7.1, Ultimate Member 2.13.0, UAP 9.7.7, WooCommerce 11.1.0 with HPOS, ShipStation 5.3.5. Disposable local database only; no real email, payments or tax forms.

- 69 financial/integration assertions plus independent-process activation, settlement and credit-spending races; code preparation reuse and invalid AJAX nonce refusal.
- Multipart W-9 validation, actual encryption, member-download refusal, administrator download and tamper rejection.
- 21 dashboard/tracking assertions plus 6 code-repair assertions, including legacy missing assignment, idempotency, suspended-account refusal and protected repair markup.
- 56 route/viewport checks at 320, 375, 768 and 1280px: Dashboard, Orders, Tracking, Account details, Password, Privacy, Addresses, Affiliate dashboard, Performance, Commissions, Payouts, Creative, Guidelines, and a disabled Delete route falling back to Privacy. Native UM/UAP styles and actual PHP-rendered markup, with populated orders/commissions/payout history. No visible horizontal overflow, clipped commission text, oversized headings, obsolete payout warning or blank account surface. Public Guidelines remains a separate destination; the in-account guidelines summary was checked.
- Screenshots inspected for mobile commission values, earnings rows, profile forms, payouts, performance, creative and desktop affiliate dashboard.
- Automatic code POST and repaired-code clipboard control tested in Chromium with a mocked API response; the real service, concurrency and nonce rejection are covered separately in PHP.
- PHP lint, both JavaScript syntax checks, logout route and ZIP content/CRC/source-byte verification passed.

## Live status and limits

Read-only live Affiliate Management inspection on September 8, 2026 confirmed Account Hub 1.2.1 had been uploaded by the user. Its readiness message was ?Configure private W-9 storage, its separate key file, and PHP Sodium.? Payouts were disabled. That grouped message does not reveal which individual requirement is absent; 1.2.2 makes those diagnostics specific. This session did not install 1.2.2, change live settings, provision host directories/keys, inspect customer tax documents or process funds. The private-storage setup still requires host access. Local layout coverage does not reproduce the entire live Divi/plugin stack.

Pre-existing affiliate-surface test failure remains separate: the global header lacks the two Affiliate navigation links expected by `scripts/test-affiliate-surfaces.ps1`. No global header change was made for this release.
