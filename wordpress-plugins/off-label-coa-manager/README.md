# Off Label COA Manager

Install the `off-label-coa-manager` directory as a WordPress plugin and activate it with WooCommerce active.

## One-time setup

Activation creates a published **Receipt** page with the slug `receipt`. In the page's GitPress metabox:

1. Choose **GitPress Managed**.
2. Set its GitPress shortcode to:

   `[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/receipt.html" branch="main" format="html" updated_meta="false"]`

3. Save the page, then visit **Settings → Permalinks** and click **Save Changes** once if `/coas/product-slug/` returns a 404.
4. Keep `gitpress/woocommerce-bridge.php` active. It allowlists `[olr_coa_page]` and sends Testing archive links to the singular receipt page.

## Adding a report

Go to **COA Reports → Add COA Report**, select a WooCommerce product, enter the report fields, choose a PDF, and publish. The newest test date becomes that product's current report. Older reports are shown automatically under Previous Testing.

Drafts and entries without a valid product, ISO-format date, and PDF cannot appear publicly. The Testing archive receives its rows automatically through `olr_document_archive_items`.

## Public routes

- `/coas/` — existing searchable Testing archive.
- `/coas/{product-slug}/` — canonical current report and testing-history link used by the archive.
- `/coa/{product-slug}/` — supported singular alias.
- `/receipt/?product_id=123` — direct diagnostic fallback that bypasses pretty routing.
- `[olr_coa_page product_id="123"]` — explicit diagnostic shortcode interface.

The interactive PDF viewer uses a pinned PDF.js release from jsDelivr and always exposes direct open/download links if the viewer cannot load.
