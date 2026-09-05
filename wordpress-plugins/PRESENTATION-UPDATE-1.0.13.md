# Presentation update 1.0.13

Prepared locally only. Nothing was uploaded, activated, pushed to GitHub, or edited in WordPress.

## Install the display fixes

1. Take a current hosting backup and keep the ZIP of the installed Production Rollout version.
2. In Plugins, confirm which folder contains the active **Off Label Production Rollout**. This ZIP uses `off-label-production-rollout`. Earlier deployments sometimes used `off-label-production-rollout-live`; do not activate a second copy if that is the active folder. In that case replace the files inside that existing folder with the extracted ZIP contents via FileZilla, or ask for a ZIP matching that folder.
3. For the canonical folder, Plugins → Add Plugin → Upload Plugin → select `off-label-production-rollout-v1.0.13.zip`. Choose **Replace current with uploaded**. If WordPress offers a fresh install instead of replacement, stop and confirm the active folder first.
4. Do **not** run Seed Images, Promote Pages, rollback, or test-page cleanup. None is needed. This update has no activation migration.
5. Clear the site/page cache and GitPress cache, then check the product page and `/cart/` in a private browser window.

The update applies the new Terms URL and Terms/Privacy-only Support column to existing branded global and checkout footers. It hides product description blocks and in-stock counts in the branded product record, centers the main image on white, and uses uncropped originals for legacy related-product thumbnails. Stock enforcement and stored descriptions/images are unchanged; sold-out notices stay visible. No checkout, payment, order, login, REST, XML-RPC, or Build Your Box behavior is modified.

## Replace the old Divi header/footer yourself

The plugin provides `[olr_site_header]` and `[olr_site_footer]`. They output the approved shared navigation and updated footer, including the cart icon/count. They do not assign or overwrite a Divi template automatically.

1. Open **Divi → Theme Builder** and export the existing templates as a backup.
2. Identify the template supplying the old header/footer on `/terms-and-conditions/`, `/terms-conditions/`, `/login/`, and the WooCommerce-assigned Checkout page. Order confirmation is an endpoint of that Checkout page; keep the WooCommerce body and order endpoint intact.
3. Replace only that template's **header layout** with a section/row containing one **Text module** whose text is `[olr_site_header]`. Replace only its **footer layout** with a Text module containing `[olr_site_footer]`. Use the Text module's Text tab. Do not put these inside a Code module or keep the old header/footer beside them.
4. Set each surrounding section/row to zero padding and margin, row width/max-width 100%, and module spacing zero. Keep row/section overflow visible for the mobile menu.
5. Apply these layouts to the affected pages. For the default/global layout, first confirm which pages inherit it; do not add a second header/footer to pages already using GitPress Managed chrome. Where a more-specific template overrides the global one, update its header/footer too. Leave all body layouts and page content alone.
6. Save Theme Builder and clear page caches. The old Terms URL may stay accessible with the new layout; the footer now points to `/terms-and-conditions/`. No redirect or legal text change is included.

## Verify before broadening template assignments

- Epitalon: no description or stock count; main image centered on white; strength, quantity and Add to Research still work.
- Recently Researched at 375/390/768px: complete bottle images with no cropping.
- Terms, Login, and an existing order-confirmation URL: one header and one footer; Home, About, Catalog, COAs, and cart icon; mobile menu opens; Support contains only Terms and Privacy.
- Login form and order confirmation content remain intact. Check an existing order confirmation privately; do not place a new order just for this check.
- Check a sold-out product still displays its unavailable state.

## Rollback

Replace the plugin files with the saved previous ZIP and restore the exported Divi header/footer templates if necessary. Clear caches. Do not use the rollout tool's image/page rollback for these display changes.

## Source sync

The canonical local `gitpress/partials/footer.html`, `styles-product.css`, and `gitpress/woocommerce-bridge.php` were updated too. The ZIP provides compatibility with the currently installed bridge and footer, so a GitPress push or manual bridge replacement is not required for this installation. Future GitPress source deployment should include those local edits.
