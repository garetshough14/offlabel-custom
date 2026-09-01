# Off Label Account Hub

An update-safe account layer that brings Ultimate Member, WooCommerce order history, and Ultimate Affiliate Pro into the existing `/account/` page. It does not edit or bundle any vendor plugin files.

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

4. Open **Ultimate Affiliate Pro > Affiliate Applications** and save the URL of the published affiliate terms. Applications remain closed until this is configured.
5. Confirm UAP's account-page tabs expose the reports, referrals, payments, marketing tools, and help content that should appear in the unified navigation. Unconfigured areas are hidden.
6. Purge GitPress, page, and CDN caches.

If a previous upload reports **Plugin file does not exist**, remove only the incomplete
`wp-content/plugins/off-label-account-hub/` directory before uploading the fresh ZIP.
After installation, the main file must be located at
`wp-content/plugins/off-label-account-hub/off-label-account-hub.php` with no second
`off-label-account-hub` directory nested inside it.

## Account behavior

- Ultimate Member remains responsible for authentication, the compliance gate, profile details, password, and privacy controls.
- WooCommerce order history and owned order details are rendered through WooCommerce APIs inside the Orders tab. Transactional actions still use their native secure endpoints.
- Existing approved UAP affiliates see native affiliate statistics and tools in the branded shell.
- Members without an affiliate record see the Off Label application. Submitting it does not change their WordPress or Ultimate Member role.
- Administrators approve or reject applications from the plugin's UAP submenu. Approval creates one UAP affiliate record and assigns UAP's configured default rank.
- The previous UAP account page and the safe legacy Woo dashboard/order routes redirect into `/account/`. Saved-payment, address, and other technical Woo endpoints remain untouched.

## Upgrade safety

The plugin uses Ultimate Member hooks, WooCommerce public APIs, and UAP's `uap_filter_on_load_template` seam. Overrides run only while `/account/` is rendering. An administrator warning appears when the installed UAP version differs from 9.7.7 so the affected views can be checked on staging before launch.
