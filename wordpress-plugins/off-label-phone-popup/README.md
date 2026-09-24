# Off Label Phone Popup 1.0.1

A standalone WordPress plugin with a **Phone Popup** item in the left admin sidebar. Choose published pages, edit the popup, collect a signed-in member’s mobile number, and optionally enroll them in Omnisend SMS.

Version 1.0.1 updates the default invitation to **“Join the Off Label Text Club.”**, with **“Get exclusive text club deals and special offers sent straight to your phone.”** and a **“Join the Text Club”** button. Updating from 1.0.0 replaces the original default wording automatically and preserves customized wording. Enable SMS signup and configure Omnisend before using this invitation to enroll members in the text club.

## Install and choose your pages

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `off-label-phone-popup-v1.0.1.zip`, install it, and activate it. If version 1.0.0 is already installed, choose **Replace current with uploaded**.
3. Open **Phone Popup** in the left sidebar.
4. Select the pages where members should see the popup, such as Account or Shop. You can also choose all published pages.
5. Edit the heading, message, and button text. Use **Preview popup** to try your current wording without saving any account data or sending a text.
6. Set the delay and the number of days to wait after a member dismisses the popup.
7. Turn on **Enable the popup**, then **Save settings**.

The plugin starts with the popup and Omnisend integration switched off. Saving phone numbers alone does not require an Omnisend API key.

## The phone field

The plugin writes only to the current member’s existing **`billing_phone`** user-meta field. This is the `billing_phone` column in the supplied customer export and WooCommerce’s customer billing-phone field. It does not create `phone_number`, `mobile_number`, or a separate plugin phone field. It does not change the existing `shipping_phone` field or historical orders.

Numbers are normalized to international format, such as `+14155550123`. With the US / Canada setting enabled, a local 10-digit number gets `+1`. Other countries must include `+` and their country code (an initial `00` is also accepted). Validation checks formatting, not ownership or whether a number can receive SMS.

The plugin records consent, submission status, and dismissal timing in separate metadata. Those records use a phone hash, not an additional copy of the number. If Ultimate Member is active, its user cache is cleared after saving. An Ultimate Member form that displays this number must use the same `billing_phone` field key; this plugin does not add or change Ultimate Member registration/profile fields.

## Who sees it

- Only signed-in members, including administrators who meet the audience criteria, on selected published WordPress pages. The WooCommerce Shop page is supported. Posts, product detail pages, and other archives are outside this version’s page picker.
- By default, only members with an empty `billing_phone` field.
- Optionally, with SMS signup enabled, members who have not given consent through this popup. Their existing billing number is prefilled so they can confirm or edit it.
- Members who dismiss the popup are suppressed across devices until the configured interval ends.
- Members who have already submitted SMS consent through the popup are not prompted again by the broader audience setting, including after an Omnisend unsubscribe. The admin activity record is capture history, not a mirror of current Omnisend subscription status.

For new registrations, choose the page members reach after signing in. If Ultimate Member requires email verification or admin approval before login, the popup appears after that process. Logged-out visitors cannot submit a phone to an existing account by entering someone’s email address. This plugin does not change your registration rules.

The popup waits for the site’s research-access gate or another visible modal to close. It supports keyboard dismissal, focus management, reduced motion, and narrow screens. It requires a browser supporting native HTML dialogs; unsupported browsers continue browsing without a popup.

## Enable the Omnisend coupon text

1. In Omnisend, create an API key with **contacts.read** and **contacts.write** permissions. Paste it into **Phone Popup → Text signup & Omnisend**, then save. The key stays on the server, is not shown back in the settings form, and is never included in frontend JavaScript. A blank key field retains the saved key.
2. Use **Test saved Omnisend connection** to verify read access. This test does not create contacts or send messages; it cannot prove write permissions or SMS delivery.
3. Configure a Welcome automation in Omnisend with **Subscribed to Marketing** as the trigger. Set **Channel subscribed to = SMS**, **First subscription = true**, and **Origin = API**. Remove the default **Subscription method = Signup form** filter so API signups can enter.
4. Enable WooCommerce unique-discount generation in Omnisend. Add an SMS block containing the **Discount Code** personalization tag, configure the amount and expiry, and remove intentional delay steps. Enable this automation before collecting live opt-ins.
5. In WordPress, enable **Collect SMS consent and send opt-ins to Omnisend**. Review the disclosure and supply your published HTTPS SMS terms and privacy-policy URLs.
6. Change the popup wording to match the actual offer you configured, for example “Get your welcome offer by text” and “Text me my code.” Save settings and enable the popup.

The SMS checkbox is never prechecked. Enabling SMS signup requires that checkbox for this optional popup offer; members can dismiss it and continue using their account. The plugin does not subscribe anyone just because a number already exists in the database or CSV. Email subscription status and MailWizz are not changed.

The plugin immediately tries to synchronize a consented submission. **Omnisend creates and sends the coupon**, using your existing verified SMS sender, SMS balance, and enabled automation. Quiet hours and carrier conditions can delay delivery. Already-subscribed contacts do not receive another first-subscription reward. Creating or changing a coupon, its value, expiry, or restrictions is done in Omnisend/WooCommerce, not in this plugin.

Omnisend references checked for this release:

- [Welcome automation and API trigger filters](https://support.omnisend.com/en/articles/9653272-welcome-automation)
- [Unique discount codes in automated SMS](https://support.omnisend.com/en/articles/9470439-add-unique-discounts-to-automated-sms-mms)
- [Enable WooCommerce discount generation](https://support.omnisend.com/en/articles/5846981-woocommerce-add-configure-discount-item)
- [Contact identifiers and channel consent](https://api-docs.omnisend.com/reference/contacts)
- [Create or update contacts](https://api-docs.omnisend.com/reference/post_contacts)

## Sync status and retries

The settings page lists captured accounts:

| Status | Meaning |
| --- | --- |
| Saved | Number saved to the account; SMS integration was off. |
| Pending | Number and consent saved; Omnisend is temporarily unavailable and a retry is scheduled. |
| Synced | Omnisend confirmed the phone’s SMS subscription. This does not confirm a text was delivered. |
| Failed | Signup needs attention; a specific reason appears beside it. The account phone stays saved. |

Temporary HTTP failures retry through WP-Cron, for at most five automatic attempts in total. Hosting must run WP-Cron or an equivalent scheduled runner. **Retry sync** uses the existing consent record; it does not manufacture a new opt-in. Submissions older than 24 hours are not sent. Turning the popup or SMS integration off prevents queued subscriptions from being sent. Deactivation removes queued jobs; interrupted signups can be reviewed and manually retried after reactivation, within that consent window.

Phone or email changes after consent stop a pending sync. Existing SMS unsubscribes are never overridden. If a phone belongs to another contact, or the matched email has a different phone in Omnisend, synchronization stops for manual review instead of merging identities. After resolving a contact conflict in Omnisend, use **Retry sync**. Use Omnisend’s own resubscription process for people who previously unsubscribed.

Retries first check whether the phone is already subscribed, avoiding another subscription write if an earlier request succeeded but its response was lost. The first-subscription automation filter prevents repeated welcome rewards. Configure any other Omnisend workflows so they do not independently send duplicate welcome messages.

## Operation and maintenance

- Requirements: WordPress 6.4+, PHP 7.4+, HTTPS on the live site, and a theme that runs `wp_head()`/`wp_footer()`. Omnisend is optional for account-only capture. WooCommerce/Ultimate Member are compatible through shared WordPress user metadata; no vendor plugin files are modified.
- Exclude authenticated pages and `wp-admin/admin-ajax.php` from full-page/CDN caching. The plugin emits no-cache headers on its targeted signed-in pages and checks eligibility through an authenticated AJAX request.
- Settings changes and connection/retry actions require `manage_options` and a WordPress nonce. Member requests require a logged-in session and nonce. The account ID comes only from the authenticated session. API calls use Omnisend’s `2026-03-15` contract with no automatic email enrollment or tag replacement.
- Optionally define `OLR_PHONE_POPUP_OMNISEND_KEY` in `wp-config.php` instead of saving the key in WordPress options. Protect database backups if you use the settings field.
- Consent records include the time, disclosure wording, policy URLs, source page, request IP, and user agent. Account phone remains in `billing_phone`. WordPress’s personal-data export/erasure tools include the plugin record. Billing-data erasure remains with WooCommerce; Omnisend erasure/unsubscribe must be handled separately.
- Deactivating or deleting the plugin does not delete account phone numbers or consent records. Reinstalling retains its saved settings. There is no CSV import, bulk contact upload, historical SMS enrollment, or phone-verification text.

## Validation

See `QA.md` for test coverage and its limits. The source-only `tests/integration.php` requires a disposable WordPress installation with `OLR_PP_TEST_ENV=true`. It creates synthetic `.test` accounts and intercepts Omnisend HTTP requests; never run it against production. The installable ZIP excludes development tests and contains no customer export or credentials.
