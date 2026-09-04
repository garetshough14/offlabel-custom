# Off Label Production Rollout

Administrator-only, explicit-action tooling for the approved product-image and production-page rollout. Current build: **1.0.4**.

- Activation performs no mutations.
- Image preflight requires exactly 29 Live manifest rows, 27 unique bundled images, 1150x1600 dimensions, and the exact 29-product published catalog.
- Image seeding stores the previous product, gallery, and variation image IDs and never deletes Media Library attachments.
- Post-seed verification checks all 29 featured-image mappings, all 27 attachment IDs, empty galleries, and inherited variation images without changing data.
- Page promotion updates existing production page IDs and stores page fields, GitPress metadata, and WooCommerce route options for rollback.
- Test-page retirement drafts only exact pages carrying the Test Page Seeder marker. It never trashes or deletes them.

Use **Tools -> Off Label Rollout** and run the corresponding preflight before each mutation.
