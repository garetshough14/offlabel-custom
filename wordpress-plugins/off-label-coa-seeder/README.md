# Off Label COA Seeder

One-time, idempotent importer for the approved 52-file COA archive.

1. Install and activate the plugin while WooCommerce and Off Label COA Manager are active.
2. Open **COA Reports → Seed COAs**.
3. Review the product mapping and blocked-item count.
4. Import in batches of ten until every item is complete.
5. Deactivate and delete this seeder after verification. The imported Media attachments and COA Reports remain.

The importer verifies every bundled PDF against its SHA-256 checksum. Existing reports are matched by their attached PDF filename. Exact records are skipped; records with the correct PDF but incorrect product/date/title/status are repaired.
