# Off Label Checkout Test

An isolated WooCommerce classic-checkout preview for `/checkout-test/`. It does not replace the store's assigned Checkout page and cannot collect a real payment.

## Install

1. Zip the `off-label-checkout-test` directory and upload it at **Plugins → Add Plugin → Upload Plugin**.
2. Activate **Off Label Checkout Test**.
3. Activation creates a public, unlinked WordPress page at `/checkout-test/` containing `[olr_checkout_test]` if that slug does not already exist.
4. Optionally configure that page in GitPress Managed mode using `gitpress/pages/checkout-test.html` as its body source. The plugin-rendered page also works without GitPress Managed mode.
5. Do not assign this page under **WooCommerce → Settings → Advanced → Checkout page**. Keep the existing `/checkout/` assignment.
6. Add a product to the cart and visit `/checkout-test/` directly.

The plugin adds `noindex,nofollow`, removes the page from the WordPress page sitemap, suppresses WooCommerce order emails for its test orders, prevents stock reduction, and exposes only the non-charging **OLR Test Payment** gateway.
