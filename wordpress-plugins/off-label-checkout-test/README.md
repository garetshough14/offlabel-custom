# Off Label Checkout

The production combined cart and WooCommerce checkout experience for `/cart/`. It displays only the real payment gateways currently available from WooCommerce; the assigned `/checkout/` page remains available for payment callbacks and order endpoints.

Current build: **1.4.0**. This release promotes the approved Checkout 1.3.11 layout without redesigning it. It preserves the checkout-owned trust strip, logo header, wide two-column desktop canvas, compact order rows, mobile padding, responsive footer, and failed-totals-overlay recovery. It also removes the non-charging test gateway and all staging-only order behavior.

The checkout uses a compact three-stage layout and keeps the OLR research-updates choice alongside any store-provided consent choice. All consent rows share the same checkbox-and-copy alignment even when another extension injects a late field. The desktop order summary stays top-aligned beside the information and shipping forms without inheriting their height. Standard order-summary lines include minus, plus, and remove controls that update the cart and checkout totals without leaving the page. Companion plugins can replace the product image and quantity area for protected grouped products such as Research Boxes. Coupon responses are normalized before totals refresh. Payment-stage notices use the same centered width as the payment content; information and shipping notices retain the full two-column width. When the cart is empty, the page shows a clear empty-bag state and up to four visible, in-stock recommendations.

The plugin renders its own simplified checkout header and footer on this route and suppresses the standard managed site chrome there. The logged-in WordPress toolbar remains hidden on this customer-facing route. Route-scoped overrides prevent generic WooCommerce and theme rules from changing the approved checkout proportions or responsive spacing.

Checkout name fields use the same full row width as the remaining address inputs, and both billing and alternate-shipping Address Line 2 fields have a visible optional label.

## Install

1. Zip the `off-label-checkout-test` directory and upload it at **Plugins -> Add Plugin -> Upload Plugin** so WordPress upgrades the existing plugin in place.
2. Activate **Off Label Checkout**.
3. Configure `/cart/` in GitPress Managed mode using `gitpress/pages/checkout.html` as its body source. The source renders `[olr_checkout]`.
4. Keep WooCommerce's existing `/checkout/` page assignment. Do not assign `/cart/` as the WooCommerce Checkout page.
5. Add a product to the cart and visit `/cart/`.

`[olr_checkout_test]` remains available only as a compatibility alias for the old staged page source.
