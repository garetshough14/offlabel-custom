# Off Label Test Page Seeder

This staging utility creates the isolated WordPress pages used to test every GitPress fragment before the production pages are changed.

Current build: **1.1.5**. Test routes reuse the production plugin asset handles so WordPress emits each stylesheet and script once, preventing duplicate account controls and duplicate event initialization.

## Behavior

- Creates 13 published test routes on activation.
- Uses the canonical `gitpress/pages/*.html` fragment for each route.
- Configures GitPress Managed mode and full-width rendering.
- Loads the same responsive stylesheet and page-specific scripts used by the production routes.
- Initializes WooCommerce product, cart, box-builder, account, affiliate, FAQ, testing, and receipt assets on their test slugs.
- Opens a real published product and a real report-backed COA when the dynamic preview pages are visited without query parameters.
- Marks every test route `noindex,nofollow` while the plugin remains active.
- Reuses the existing `/checkout-test/`, `/research-catalog-test/`, or `/research-item/` page when present.
- Never edits the existing production Home, About, COAs, FAQ, Cart, Checkout, Account, Affiliate, or policy pages.
- Adds **Tools > Off Label Test Pages** with View/Edit links and an idempotent repair button.

## Installation

1. Upload `off-label-test-page-seeder.zip` in **Plugins > Add Plugin > Upload Plugin**.
2. Activate the plugin. Activation creates or repairs the test-page set.
3. Open **Tools > Off Label Test Pages** to view every route.
4. Keep the plugin active during QA so the test pages remain marked `noindex,nofollow`.

GitPress and the relevant Off Label plugins must be active for their nested shortcodes to render.
