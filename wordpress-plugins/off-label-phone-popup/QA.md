# Phone Popup validation

## Version 1.0.1 copy update

The heading, description, and button now invite members to the Off Label Text Club and exclusive text deals. The admin preview uses the same defaults. A one-time migration updates saved 1.0.0 default wording while preserving customized copy and all other settings. PHP/JavaScript syntax, migration behavior, and the rebuilt ZIP were checked. The underlying account-saving and Omnisend flows are unchanged; their 1.0.0 validation is recorded below.

## Version 1.0.0 integration validation

Validated locally on 2026-09-23. No live site changes, customer imports, or real SMS sends were performed.

## Environment

- Disposable WordPress 7.1.2 installation, PHP 8.5.10, WordPress SQLite Database Integration 1.8.0.
- Chromium browser through Playwright CLI, default WordPress Twenty Twenty-Five theme.
- Synthetic `.test` user accounts only. Supplied CSV inspected for schema and aggregate phone-field completeness; no customer rows are included in this plugin, its fixtures, or its ZIP.
- Omnisend requests intercepted with deterministic responses matching the documented `2026-03-15` API contract. No real API key was available or used.

## Completed checks

The integration suite passed 150 assertions against actual WordPress user metadata, options, and scheduled events. These cover phone normalization; rejection of malformed numbers; published-page targeting; settings sanitization; writing only `billing_phone`; preserving `shipping_phone`; collection without external calls; explicit SMS consent; repeat submissions; stored disclosure and policy URLs; server-only credentials; unchanged email subscription preferences and contact tags; contact-identity conflicts; unsubscribe protection; checking API results rather than trusting HTTP 200; retained account phone after API failure; retries using the original consent time; response loss after a successful external write; retry exhaustion; changed phone/email; disabling queued SMS writes; concurrent submissions; and privacy export/erasure.

Browser checks passed:

- The **Phone Popup** top-level WordPress sidebar menu and settings page render, and settings save through the real WordPress admin endpoint.
- Preview opens using unsaved settings without changing an account or calling Omnisend. Both collection and SMS-consent layouts were visually inspected at desktop and mobile sizes.
- The popup fits at widths 320, 375, 768, and 1440 pixels without internal horizontal overflow. Native dialog keyboard navigation, Escape, and dismissal persistence were exercised.
- A logged-out visitor receives neither the popup nor its configuration. A signed-in subscriber sees it only on selected pages.
- Invalid nonce, stale form revision, unselected page, and malformed phone requests fail. A subscriber cannot access the admin settings page. No API key appears in frontend HTML or configuration.
- A real subscriber AJAX submission normalizes and saves its number to `billing_phone`, shows success, and suppresses the popup on reload. Adding a forged user ID/email to a repeat request leaves the other account’s phone unchanged.
- Existing account numbers are not replaced through the default missing-number audience.
- All plugin PHP files pass `php -l`. Both JavaScript files pass `node --check`.
- The packaged ZIP was installed and activated successfully through WordPress, reporting plugin version 1.0.0.

## Remaining deployment checks

The live theme, cache/CDN, installed Ultimate Member/WooCommerce/Omnisend versions, and the actual Omnisend automation were not exercised. The Ultimate Member cache-removal method used by the plugin was checked against its published source. Confirm the live host permits authenticated AJAX and runs WP-Cron or an equivalent scheduler.

After installation, configure the real Omnisend key and workflow, then use one controlled member account/phone with consent to verify the complete coupon text. Confirm the number appears in the same billing-phone field and that the generated coupon works. Delivery timing, sender verification, SMS credits, quiet hours, and offer restrictions remain controlled by Omnisend/WooCommerce.

## Reproduce the storage/API checks

In a disposable WordPress installation with this plugin activated and `OLR_PP_TEST_ENV` defined as `true`:

```sh
wp eval-file /path/to/off-label-phone-popup/tests/integration.php
```

The test script is retained in the repository and excluded from the installable ZIP. Do not run it against production. The ZIP packages only plugin runtime files, assets, templates, and documentation under one `off-label-phone-popup/` directory.
