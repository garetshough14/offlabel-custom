# Off Label Build Your Box

An update-safe WooCommerce companion plugin for the branded `/build-your-box/` experience.

## What it does

- Registers `[olr_build_a_box]` and creates a fallback **Build Your Box** page on activation.
- Adds a centralized **WooCommerce → Build Your Box** product eligibility manager with search, pagination, readiness checks, and select-all controls.
- Uses explicitly eligible WooCommerce products, featured images, current regular prices, stock, taxes, shipping, checkout, orders, and refunds.
- Offers **The Five** at 25% off and **The Ten** at 30% off.
- Shows each completed box as one locked cart and checkout row with its selected bottles listed underneath.
- Uses dedicated transparent five-vial and ten-vial Off Label artwork for the corresponding box row.
- Keeps hidden native product allocations behind that row so WooCommerce still owns stock, shipping, tax, HPOS order lines, refunds, and transactional data.
- Supports duplicate bottles, variable products, multiple boxes, ordinary cart products, atomic box editing, and whole-box removal. Removing or changing any protected allocation removes the complete box.
- Excludes sale-priced, unsupported, hidden, out-of-stock, and image-less products.
- Keeps every protected box allocation outside WooCommerce coupon calculations while allowing coupons on eligible ordinary cart products.

No WooCommerce coupon or custom database table is created.

## Install on staging

1. Back up the staging site and WooCommerce settings.
2. Upload `off-label-build-a-box.zip` from **Plugins → Add Plugin → Upload Plugin** and activate it.
3. Confirm that WordPress lists version **1.3.2** and that only one `off-label-build-a-box` plugin directory exists.
4. Open **WooCommerce → Build Your Box**, select every approved bottle, and click **Save eligible products**. You do not need to open each product.
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
- A coupon already in the cart remains applied when a completed box is added.
- Percentage, fixed-product, and fixed-cart coupon discounts apply only to eligible ordinary products and never reduce any protected box allocation.
- Cart, mini-cart, `checkout-test`, customer order, and email views show one box row with the complete contents manifest.
- Editing replaces only the selected box; removing or externally changing any protected line removes the whole box.
- Multiple boxes and normal products retain correct stock, tax, shipping, checkout, and order behavior.
- Sale, hidden, unsupported, image-less, and out-of-stock products do not appear or validate.
- Desktop, tablet, and mobile layouts use the canonical Off Label bottle imagery without horizontal overflow.

Version 1.3.2 allows coupons beside Research Boxes while excluding every protected box allocation from product validation and discount distribution. It retains one customer-facing box row backed by native WooCommerce stock allocations and adds final compact-mobile layout guards.
