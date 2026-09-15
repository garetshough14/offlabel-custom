# Account Hub 1.2.4 verification

Changes: desktop/mobile menu visibility, viewport state reset, host-specific W-9 setup instructions. Includes all 1.2.3 functionality. No vendor source or settlement/storage logic changed. No live deployment or hosting configuration writes performed.

- PHP syntax checks passed for the main plugin and affiliate flows; JavaScript syntax checks passed for both the runtime script and browser verifier.
- Disposable real WordPress 7.1, Ultimate Member 2.13.0, UAP 9.7.7, WooCommerce 11.1.0 with HPOS, ShipStation 5.3.5: 21 dashboard/tracking checks plus six coupon repair/presentation checks passed while generating actual native account markup.
- Headless Chrome: 56 account-route/viewport combinations at 320, 375, 768 and 1280px passed. Fourteen routes include the unavailable Delete route falling back to Privacy. Checks cover visible content, heading sizes, horizontal overflow, table values, neutral colors and retired payout warnings.
- On every route, verified exactly one menu toggle, hidden on desktop despite a simulated theme-wide important button display rule; mobile toggle precedes content; menu opens, Escape closes, and focus returns to the toggle. Desktop sidebar remains visible.
- In-place resize sequence 800 -> 801 -> 375 -> 1280 -> 800px verifies visibility and expanded-state reset across the breakpoint.
- Code preparation/copy UI passed with mocked protected AJAX response; real preparation service checked by PHP fixture. Financial and custom-code concurrency suites passed in 1.2.3 and were not repeated for this presentation/documentation patch.
- Desktop and mobile overview screenshots visually inspected. No extra Account menu control on desktop; mobile control remains at the top.
- ZIP contains 24 source-matching runtime/documentation files, valid CRCs and correct single-directory topology; tests/private files excluded. SHA-256 is in the adjacent `.zip.sha256` file.

Live read-only findings: installed Account Hub 1.2.2; WordPress.com Business, PHP 8.4, SFTP enabled, SSH disabled. Affiliate Management reports both private storage constants missing. This occurs before the Sodium check; do not describe it as a confirmed Sodium failure. No persistent private paths were provided in hosting settings. A ready-to-send WordPress.com support request and subsequent setup steps are bundled in W9-HOSTING-SETUP.md. W-9 uploads and payouts remain unconfigured live.

Existing unrelated affiliate-surface test failure remains separate: it expects two affiliate links in the global header, which are absent. This release does not change the global header.
