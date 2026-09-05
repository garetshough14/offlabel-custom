# GitPress 1.2.6 local access fix

Input: user-supplied `C:/Users/User/Downloads/Gitpress-main.zip`, GitPress 1.2.5.
Output: `_deploy/Gitpress-main-v1.2.6.zip`, same plugin folder and bootstrap filename.
No live uploads, settings changes, deployments, orders or cache purges performed.

## Confirmed defect

The original page manager registers `maybe_render_full_page_canvas` on
`template_redirect` at priority 0 and exits after sending the page. Ultimate
Member's published Access class registers its global restriction check at 1000.
That check cannot run on the original full-page rendering path.

## Narrow patch

- Select a bundled canvas with `template_include` after the redirect action completes.
- Reuse the original document renderers without HTML/CSS/JavaScript changes.
- Leave admin, AJAX, cron, REST, XML-RPC, WC API and WooCommerce endpoints alone.
- Send no-cache headers and DONOTCACHEPAGE for full-page GitPress requests when
  Ultimate Member's global access is set to members only (`accessible = 2`).
- No new login logic, account creation, newsletter changes, member exceptions,
  callback exemptions, order-ownership changes or database mutations.

## Local test coverage

`scripts/verify-gitpress-access.cjs` runs PHP workers against the original and
patched page managers, the actual upstream WordPress hook dispatcher, and the
actual upstream Ultimate Member Access class with stubbed site options/data.
This is not a full WordPress database/browser integration test, and the installed
Ultimate Member version was not supplied. Upstream UM Access SHA256 tested:
`671307454D09F69BE6EC6A4FEE860D3B5D8665C110BDF0E9BCD07BA3E07B6D12`.

Passed: reproduction of original bypass; redirect before shortcode execution on
Home/Catalog/product/COA/cart; identical allowed-user HTML for both canvas modes;
login/register/reset/Terms/Privacy exceptions; public-site configuration; member
and admin access; service-request passthrough; authenticated payment/confirmation
endpoints retaining their existing template; expired confirmation sessions still
using Ultimate Member login; theme-wrapped rendering unchanged.

## Installation and remaining verification

Keep the original ZIP as rollback and take a hosting backup. Replace the existing
GitPress plugin with the new ZIP (do not activate a duplicate folder). If WordPress
does not offer replacement, confirm the active plugin folder before proceeding.
Leave Ultimate Member and WooCommerce settings unchanged for this patch.

Purge WordPress.com page/edge cache plus GitPress cache after replacing it.
An already cached response can bypass PHP, so the code alone cannot purge or fix
an existing cache entry. Ask hosting support to exclude membership-dependent HTML
from full-page/CDN caching; static CSS/JS/images may still be cached.

First test on staging if available. Use a fresh private window for anonymous
Home, Catalog, product, COA and cart requests; all must redirect to login. Check
public form/policy pages, then log in as a normal member and verify page access,
cart, mobile layout, and an existing order's confirmation without submitting a
new paid order. Test registration/email activation with the owner's approval.
No assertion is made that live QA or newsletter enrollment has passed.

Rollback: replace with the saved original ZIP and purge caches. This also restores
the original access bypass, so it is not a secure long-term solution. No image or
production-page rollout tools are involved in installation or rollback.
