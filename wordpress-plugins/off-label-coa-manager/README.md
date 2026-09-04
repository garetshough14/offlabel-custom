# Off Label COA Manager

Install the `off-label-coa-manager` directory as a WordPress plugin and activate it with WooCommerce active.

Current build: **1.0.17**. This release carries the approved singular-receipt presentation into production: the decorative COA seal and hero image are removed, the product heading is smaller and ink black, supporting copy keeps the ink color, and the black evidence banner keeps white text. Product-return links continue to use `/catalog/{product-slug}/`.

## One-time setup

Activation creates a published **Receipt** page with the slug `receipt`. In the page's GitPress metabox:

1. Choose **GitPress Managed**.
2. Set its GitPress shortcode to:

   `[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/receipt.html" branch="main" format="html" updated_meta="false"]`

3. Save the page, then visit **Settings → Permalinks** and click **Save Changes** once if `/coas/product-slug/` returns a 404.
4. Keep `gitpress/woocommerce-bridge.php` active. It allowlists `[olr_coa_page]` and sends Testing archive links to the singular receipt page.

## Adding a report

Go to **COA Reports → Add COA Report**, select a WooCommerce product, enter the test date, choose the original PDF, and publish. The newest test date becomes that product's current report. Older reports are shown automatically under Previous Testing.

Sample or lot ID, purity/result, testing method, and testing laboratory are intentionally not duplicated as WordPress inputs. Those details remain authoritative inside the uploaded laboratory PDF.

Drafts and entries without a valid product, ISO-format date, and PDF cannot appear publicly. The Testing archive receives its rows automatically through `olr_document_archive_items`.

## Testing and live-page behavior

- `/coas/` — production archive powered by the plugin.
- `/coas/{product-slug}/` — original published WordPress child page, such as `/coas/glow/`. Staging archive links resolve this existing page.
- `/receipt/?product_id=123` — isolated diagnostic page for previewing the generated singular design; it is not linked from the staging archive.
- `[olr_coa_page product_id="123"]` — explicit diagnostic shortcode interface.

The interactive PDF viewer uses a pinned PDF.js release from jsDelivr and always exposes direct open/download links if the viewer cannot load.

Public COA pages use the same system sans-serif type stack as the managed GitPress site. The public archive uses the compliance-safe **Compounds** category label.
