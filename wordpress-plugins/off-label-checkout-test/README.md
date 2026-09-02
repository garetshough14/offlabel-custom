# Off Label Checkout Test

An isolated WooCommerce classic-checkout preview for `/checkout-test/`. It does not replace the store's assigned Checkout page. It displays the store's currently available WooCommerce payment gateways plus a non-charging OLR test option.

Current build: **1.3.6**. This release adds final 320px progress, order-line, coupon, payment, and terms wrapping guards while retaining the global GitPress header/footer and all existing checkout behavior.

The checkout uses a compact three-stage layout and keeps the OLR research-updates choice alongside any store-provided consent choice. All consent rows share the same checkbox-and-copy alignment even when another extension injects a late field. The desktop order summary stays top-aligned beside the information and shipping forms without inheriting their height. The information-stage return link goes to the site home page. Standard order-summary lines include minus, plus, and remove controls that update the cart and checkout totals without sending the customer back to Bag. Companion plugins can replace the product image and quantity area for protected grouped products such as Research Boxes. Coupon responses are normalized into clean text notices before totals refresh, including on stores that return escaped notice markup. Payment-stage notices use the same centered width as the payment content; information and shipping notices retain the full two-column width. Success notices use a high-contrast black panel, while errors use a high-contrast red treatment that cannot be washed out by theme notice styles. When the cart is empty, the page shows a clear empty-bag state and up to four visible, in-stock best sellers. Newest products fill any recommendation spaces when sales history is limited.

The plugin does not render its own site header or footer. It leaves the GitPress-managed global header, announcement, and footer visible while keeping checkout-specific layout resets scoped to the page content. The logged-in WordPress toolbar remains hidden on this customer-facing route.

The route also suppresses Divi's automatically injected page-title strip so no empty black mobile bar appears between the global header and checkout content.

Checkout name fields use the same full row width as the remaining address inputs, and both billing and alternate-shipping Address Line 2 fields have a visible optional label.

## Install

1. Zip the `off-label-checkout-test` directory and upload it at **Plugins → Add Plugin → Upload Plugin**.
2. Activate **Off Label Checkout Test**.
3. Activation creates a public, unlinked WordPress page at `/checkout-test/` containing `[olr_checkout_test]` if that slug does not already exist.
4. Optionally configure that page in GitPress Managed mode using `gitpress/pages/checkout-test.html` as its body source. The plugin-rendered page also works without GitPress Managed mode.
5. Do not assign this page under **WooCommerce → Settings → Advanced → Checkout page**. Keep the existing `/checkout/` assignment.
6. Add a product to the cart and visit `/checkout-test/` directly.

The plugin adds `noindex,nofollow` and removes the page from the WordPress page sitemap. Orders placed with **OLR Test Payment** suppress WooCommerce order emails and stock reduction. Any other active gateway behaves normally and may collect a real payment.
