# Off Label Account Hub

An update-safe account layer that brings Ultimate Member, WooCommerce order history, and Ultimate Affiliate Pro into the existing `/account/` page. It does not edit or bundle any vendor plugin files.

Current build: **1.1.2**. This build prevents repeated initialization, keeps Ultimate Member's arrow width from collapsing mobile account labels into vertical text, preserves the full-bleed affiliate surfaces and responsive guidelines composition, and retains every existing member, application, order, and affiliate state.

## Requirements

- Ultimate Member, with its core Account page set to `/account/`
- WooCommerce
- Ultimate Affiliate Pro 9.7.7, which is the reviewed version for the included template overrides
- GitPress safe inner-shortcode rendering

## Install and configure

1. Upload and activate the `off-label-account-hub` plugin directory or its ZIP.
2. Keep the existing WordPress page at `/account/` assigned as Ultimate Member's Account page.
3. Set that page to **GitPress Managed** and use:

   `[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/account.html" branch="main" format="html" updated_meta="false"]`

4. Publish the draft **Affiliate** and **Affiliate Guidelines** pages created on activation, assign `gitpress/pages/affiliate.html` and `gitpress/pages/affiliate-guidelines.html`, and keep their canonical slugs.
5. Open **Ultimate Affiliate Pro > Affiliate Applications** and save the URL of the published affiliate terms. Applications remain closed until this is configured.
6. Confirm UAP's account-page tabs expose the reports, referrals, payments, and marketing tools that should appear in the unified navigation. Unconfigured areas show honest empty states.
7. Rebuild/publish the GitPress global header so Affiliate appears in desktop and mobile navigation, then purge GitPress, page, object, optimization, and CDN caches.

If a previous upload reports **Plugin file does not exist**, remove only the incomplete
`wp-content/plugins/off-label-account-hub/` directory before uploading the fresh ZIP.
After installation, the main file must be located at
`wp-content/plugins/off-label-account-hub/off-label-account-hub.php` with no second
`off-label-account-hub` directory nested inside it.

The install ZIP contains exactly one top-level `off-label-account-hub` directory.
If WordPress previously created suffixed directories such as
`off-label-account-hub-2`, remove those stale directories through the hosting
File Manager or SFTP before installing this build. The plugin falls back to the
public repository CDN if a local presentation asset is unavailable.

## Account behavior

- Ultimate Member remains responsible for authentication, the compliance gate, profile details, password, and privacy controls.
- WooCommerce order history and owned order details are rendered through WooCommerce APIs inside the Orders tab. Transactional actions still use their native secure endpoints.
- Existing approved UAP affiliates see native affiliate statistics and tools in the branded shell.
- The active-affiliate overview reads UAP referral, visit, commission, payout, rank, and reporting data. Order and customer metrics are resolved only through WooCommerce CRUD APIs; unavailable values are never fabricated.
- `[olr_affiliate_landing]` renders the public Affiliate Program experience. `[olr_affiliate_guidelines]` renders the complete public guidelines. Both pages use the GitPress global header and footer only.
- Members without an affiliate record see the Off Label application. Submitting it does not change their WordPress or Ultimate Member role.
- Submitting an application sends the applicant a received/pending email and sends a review alert to the configured application notification address. Approval uses UAP's configured approval notification and falls back to a plain account-hub email when that notification is disabled or cannot be sent.
- Administrators approve or reject applications from the plugin's UAP submenu. Approval creates one UAP affiliate record and assigns UAP's configured default rank.
- UAP's single and bulk affiliate Delete controls remove affiliate access and affiliate records, preserve the underlying WordPress member account, and clear the previous application so the member can apply again. The separate Reject application action remains locked until an administrator selects Reset.
- The previous UAP account page and the safe legacy Woo dashboard/order routes redirect into `/account/`. Saved-payment, address, and other technical Woo endpoints remain untouched.

## Upgrade safety

The plugin uses Ultimate Member hooks, WooCommerce public APIs, and UAP's `uap_filter_on_load_template` seam. Overrides run only while `/account/` is rendering. An administrator warning appears when the installed UAP version differs from 9.7.7 so the affected views can be checked on staging before launch.
