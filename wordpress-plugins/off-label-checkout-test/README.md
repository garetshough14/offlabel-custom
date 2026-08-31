# Off Label Checkout Test

An isolated WooCommerce classic-checkout preview for `/checkout-test/`. It does not replace the store's assigned Checkout page. It displays the store's currently available WooCommerce payment gateways plus a non-charging OLR test option.

The checkout uses a compact three-stage layout and keeps one OLR research-updates consent field while suppressing duplicate marketing opt-ins on this route. Each order-summary line includes minus, plus, and remove controls that update the cart and checkout totals without sending the customer back to Bag. When the cart is empty, the page shows a clear empty-bag state and up to four visible, in-stock best sellers. Newest products fill any recommendation spaces when sales history is limited.

The checkout header and footer use the same canonical WebP logo and Neue Montreal-based type stack as the managed GitPress site.

## Install

1. Zip the `off-label-checkout-test` directory and upload it at **Plugins → Add Plugin → Upload Plugin**.
2. Activate **Off Label Checkout Test**.
3. Activation creates a public, unlinked WordPress page at `/checkout-test/` containing `[olr_checkout_test]` if that slug does not already exist.
4. Optionally configure that page in GitPress Managed mode using `gitpress/pages/checkout-test.html` as its body source. The plugin-rendered page also works without GitPress Managed mode.
5. Do not assign this page under **WooCommerce → Settings → Advanced → Checkout page**. Keep the existing `/checkout/` assignment.
6. Add a product to the cart and visit `/checkout-test/` directly.

The plugin adds `noindex,nofollow` and removes the page from the WordPress page sitemap. Orders placed with **OLR Test Payment** suppress WooCommerce order emails and stock reduction. Any other active gateway behaves normally and may collect a real payment.
