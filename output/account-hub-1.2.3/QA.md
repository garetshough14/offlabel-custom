# Account Hub 1.2.3 verification

Release: `wordpress-plugins/_deploy/off-label-account-hub-v1.2.3.zip`. SHA-256: `0460ac29d25718f89680050c35501b06676575d9e069d56969957d0fa9b7c45d`.

## Implemented

- Neutral black/cream/gray palette across customer and affiliate tabs, native UAP tables/statuses, metric labels, native privacy buttons and input accents.
- Authenticated nonce-protected custom-code POST; case-insensitive WooCommerce/UAP collision checks including draft/trash coupons; existing own code selection is idempotent. New code becomes primary; previous codes stay working and attributed. Suspended accounts cannot choose codes. Members cannot choose discount percentages.
- Administrator default percentage (initially 20%) updates new/existing hub-managed codes; per-affiliate override applies to that affiliate's active UAP/Woo coupon assignments and survives default updates. Blank restores the default. Unrelated Woo promotions are excluded. UAP commission settings remain separate.
- Actual customer discount shown on account; public offer/default examples update consistently. Atomic updates clear WordPress/Woo object caches after success or rollback.

## Passed

Disposable local WordPress 7.1, WooCommerce 11.1.0/HPOS, UAP 9.7.7, Ultimate Member 2.13.0, ShipStation 5.3.5. No production writes, real mail, transfers or customer tax documents.

- 107 combined integration/coupon assertions: existing affiliate history, custom code selection, case-insensitive conflicts, Woo draft/trash conflicts, invalid names/percentages, permissions, individual/default inheritance, actual 17.5% Woo checkout discount, real UAP attribution, and injected-failure rollback with cache consistency.
- Independent processes: simultaneous claims for the same code have one winner. Invalid member nonce and unauthorized administrator POST fail.
- Existing 69-check affiliate/settlement/checkout/refund suite and concurrent activation, code preparation, payout and credit-spend checks pass; encrypted upload, protected download and tampering checks pass.
- 56 rendered route/viewport checks at 320, 375, 768 and 1280px: no oversized headings, horizontal overflow, clipped commission values, empty surfaces, obsolete payout warning or stray colored text/background/borders. Includes 13 available tabs and the disabled Delete route's fallback to Privacy. Native UM/UAP styles used; full live Divi/plugin stack was not reproduced.
- Open mobile coupon editor validates acceptable code, fits screen, and was inspected alongside desktop affiliate dashboard/metrics and mobile earnings. Copy controls still pass.
- PHP lint, both JavaScript syntax checks, logout route, git diff whitespace check, ZIP CRC/content/source comparison pass. ZIP contains 23 runtime/documentation files, no tests, private data or vendor plugins.

## Release status

Packaged locally, not installed live. No UM, UAP, WooCommerce or ShipStation vendor files were edited. No schema migration, enrollment, payout enablement or tax-storage change. Third-party direct coupon imports do not participate in the hub claim lock; members are checked against WooCommerce and UAP at submission. Use the hub's per-affiliate override to preserve an individual discount through default changes.

Existing unrelated affiliate-surface failure remains separate: the global header lacks the two Affiliate navigation links expected by `scripts/test-affiliate-surfaces.ps1`.
