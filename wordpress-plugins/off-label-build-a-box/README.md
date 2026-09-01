# Off Label Build Your Box

An update-safe WooCommerce companion plugin for the branded `/build-your-box/` experience.

## What it does

- Registers `[olr_build_a_box]` and creates a fallback **Build Your Box** page on activation.
- Uses explicitly eligible WooCommerce products, featured images, current regular prices, stock, taxes, shipping, checkout, orders, and refunds.
- Offers **The Five** at 25% off and **The Ten** at 30% off.
- Keeps each selected bottle as a native cart and order line under a shared box ID.
- Supports duplicate bottles, variable products, multiple boxes, ordinary cart products, atomic box editing, and whole-box removal.
- Excludes sale-priced, unsupported, hidden, out-of-stock, and image-less products.
- Blocks coupon stacking whenever a research box is present.

No WooCommerce coupon or custom database table is created.

## Install on staging

1. Back up the staging site and WooCommerce settings.
2. Upload `off-label-build-a-box.zip` from **Plugins → Add Plugin → Upload Plugin** and activate it.
3. Confirm that WordPress lists version **1.0.1** and that only one `off-label-build-a-box` plugin directory exists.
4. Go to **Products**, select the approved bottle products, choose **Enable Build Your Box** from **Bulk actions**, and click **Apply**. You can also edit one product and enable **Build Your Box eligible** under **Product data → General**.
5. Confirm every enabled product has a real featured image, a positive regular price, purchasable stock, and is not on sale.
6. Open the created **Build Your Box** page and set its slug to `build-your-box` if WordPress changed it.
7. For the normal GitPress header and footer, select **GitPress Managed** and use:

```text
[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/build-your-box.html" branch="main" format="html" updated_meta="false"]
```

8. Publish the updated managed header and footer fragments.
9. Purge GitPress, WordPress page, object, and CDN caches.

## Acceptance checks

- Exactly 5 bottles receive 25% off; exactly 10 receive 30% off.
- Duplicate products and valid variations work, while incomplete boxes never reach the cart.
- A coupon already in the cart blocks box addition without removing the coupon.
- Coupons are rejected while any box exists and work again after all boxes are removed.
- Editing replaces only the selected box; removing any protected line removes the whole box.
- Multiple boxes and normal products retain correct stock, tax, shipping, checkout, and order behavior.
- Sale, hidden, unsupported, image-less, and out-of-stock products do not appear or validate.
- Desktop, tablet, and mobile layouts use the canonical Off Label bottle imagery without horizontal overflow.

Version 1.0.1 bundles the approved three-vial hero inside the plugin and removes default theme content/sidebar constraints from the builder route.
