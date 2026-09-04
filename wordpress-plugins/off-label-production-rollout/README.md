# Off Label Production Rollout

Administrator-only, explicit-action tooling for the approved product-image and production-page rollout. Current build: **1.0.9**.

Version 1.0.7 also loads the exact approved, commit-pinned shared design system on the public site. This gives the production homepage, managed header, and managed footer the same styling used by the approved test pages without changing their markup or the canonical stylesheet.

Version 1.0.8 adds a narrowly scoped precedence lock for the homepage compliance modal so theme-level label and span rules cannot collapse the confirmation copy or push the modal actions below the viewport.

Version 1.0.9 loads the compliance controller directly from the plugin, restores the visible confirmation checkbox, closes the modal after an accepted confirmation, and removes the unnecessary desktop panel scrollbar.

- Activation performs no mutations.
- Image preflight requires exactly 29 Live manifest rows, 27 unique bundled images, 1150x1600 dimensions, and the exact 29-product published catalog.
- Image seeding stores the previous product, gallery, and variation image IDs and never deletes Media Library attachments.
- Post-seed verification checks all 29 featured-image mappings, all 27 attachment IDs, empty galleries, and inherited variation images without changing data.
- Page promotion updates existing production page IDs and stores page fields, GitPress metadata, and WooCommerce route options for rollback.
- Page verification checks the nine approved records against GitPress 1.2.5's managed full-page metadata without changing data.
- Test-page retirement drafts only exact pages carrying the Test Page Seeder marker. It never trashes or deletes them.

Use **Tools -> Off Label Rollout** and run the corresponding preflight before each mutation.
