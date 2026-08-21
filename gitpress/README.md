# Off Label Research + GitPress

This directory contains the WordPress-ready Off Label Research redesign for GitPress 1.2.5.

The root HTML files are standalone browser previews. WordPress uses the files in `gitpress/pages/` as page bodies and the files in `gitpress/partials/` as the shared header and footer.

## Repository structure

```text
styles.css
index.html
about.html
research.html
testing.html
journal.html
faq.html
cart.html
checkout.html
account.html
branding/
images/
gitpress/
  README.md
  build-inline-header.ps1
  woocommerce-bridge.php
  pages/
    home.html
    about.html
    research.html
    product.html
    testing.html
    journal.html
    faq.html
    cart.html
    checkout.html
    account.html
  partials/
    header.template.html
    header.html
    footer.html
```

`styles.css` is the one canonical stylesheet. `gitpress/partials/header.template.html` is the editable header source. The build script copies the canonical CSS into that template and writes the deployable `gitpress/partials/header.html`.

Do not edit the embedded CSS in `header.html` directly. Edit `styles.css`, then rebuild the header.

## WordPress and GitPress settings

Install and activate GitPress 1.2.5. Open **WordPress Admin > GitPress** and keep safe inner shortcode rendering enabled.

Use this value for **GitPress Managed Header Shortcode**:

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/partials/header.html" branch="main" format="html" updated_meta="false"]
```

Use this value for **GitPress Managed Footer Shortcode**:

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/partials/footer.html" branch="main" format="html" updated_meta="false"]
```

For every page listed below, edit the existing WordPress page, paste the matching shortcode into its **GitPress Shortcode** metabox, and choose **GitPress Managed** as the render mode.

### Home

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/home.html" branch="main" format="html" updated_meta="false"]
```

### About

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/about.html" branch="main" format="html" updated_meta="false"]
```

### Research shop

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/research.html" branch="main" format="html" updated_meta="false"]
```

### Single product

Create a WordPress page named **Research Item** with the slug `research-item`. Use this GitPress shortcode and choose **GitPress Managed** as the render mode:

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/product.html" branch="main" format="html" updated_meta="false"]
```

The WooCommerce bridge automatically routes catalog product-title, product-image, and card-action links to this page with the selected product ID. The `[olr_product_page]` shortcode then renders WooCommerce's native `[product_page]` output for that product. To test a product directly, visit `/research-item/?product_id=123` and replace `123` with a published WooCommerce product ID.

### Testing and COAs

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/testing.html" branch="main" format="html" updated_meta="false"]
```

### Journal

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/journal.html" branch="main" format="html" updated_meta="false"]
```

### FAQ

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/faq.html" branch="main" format="html" updated_meta="false"]
```

### Cart

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/cart.html" branch="main" format="html" updated_meta="false"]
```

### Checkout

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/checkout.html" branch="main" format="html" updated_meta="false"]
```

### My Account

```text
[divi_github_content owner="garetshough14" repo="offlabel-newsite" path="gitpress/pages/account.html" branch="main" format="html" updated_meta="false"]
```

GitPress Managed renders the global header, the selected page body, and the global footer as one server-rendered document.

## WooCommerce bridge

Install `gitpress/woocommerce-bridge.php` through Code Snippets, a child theme, or `mu-plugins`.

The bridge allowlists the WooCommerce and Off Label Research shortcodes used by these page fragments, including products, single-product output, categories, cart, checkout, account, journal, document archive, and cart count output. It also routes catalog product links to the managed Research Item page and loads page-specific frontend interactions. It does not add, remove, or configure payment gateways. WooCommerce remains responsible for products, prices, inventory, customers, carts, checkout, orders, shipping, taxes, and the site's existing payment methods.

## Styling behavior

GitPress preserves inline `<style>` blocks but removes remote `<link>` and `<script>` tags. This is why the complete stylesheet must be embedded in `gitpress/partials/header.html`.

After changing `styles.css`, run this command from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\gitpress\build-inline-header.ps1
```

Or use PowerShell 7:

```powershell
pwsh -NoProfile -File .\gitpress\build-inline-header.ps1
```

Commit `styles.css`, `header.template.html`, and the rebuilt `header.html` together.

## Root static previews

The root HTML files are complete static previews for browser review. Open the repository root with VS Code Live Server and visit:

```text
http://127.0.0.1:5500/
```

GitPress does not load the root preview files. WordPress only loads the files named in the GitPress shortcodes above.

## Public images and branding

WordPress page URLs cannot resolve relative repository asset paths correctly. Production fragments therefore use absolute jsDelivr URLs under:

```text
https://cdn.jsdelivr.net/gh/garetshough14/offlabel-newsite@main/
```

The GitHub repository must remain public for these browser-loaded assets. A GitPress token only authenticates the server-side HTML request. It does not give visitors access to images in a private repository.

If the repository becomes private, move the public assets to the WordPress Media Library or another public CDN and update every asset URL.

## Build and publish

1. Edit the appropriate file in `gitpress/pages/`, `gitpress/partials/`, `styles.css`, `branding/`, or `images/`.
2. If `styles.css` or the header template changed, run `gitpress/build-inline-header.ps1`.
3. Confirm `gitpress/partials/header.html` contains the current CSS and no `/*__OLR_INLINE_CSS__*/` placeholder.
4. Commit and push the page bodies, partials, stylesheet, bridge, and required assets to the public `main` branch.
5. Install or update `gitpress/woocommerce-bridge.php` in WordPress when that file changes.
6. Confirm the managed header, managed footer, and page-level shortcodes in WordPress match this guide.
7. Purge the changed GitPress cache entries or use the signed GitHub push webhook.
8. Test navigation, responsive layouts, products, cart, checkout, account access, order flows, and every existing payment method on staging before launch.
