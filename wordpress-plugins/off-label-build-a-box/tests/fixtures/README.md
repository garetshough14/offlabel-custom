# WordPress nonce fixture

`wp-nonces.php` contains the unmodified `check_ajax_referer`, `wp_nonce_tick`, `wp_verify_nonce`, and `wp_create_nonce` function bodies from [WordPress 7.1 pluggable.php](https://github.com/WordPress/WordPress/blob/7.1/wp-includes/pluggable.php), licensed under [GPL-2.0-or-later](https://github.com/WordPress/WordPress/blob/7.1/license.txt).

The AJAX worker supplies a fixed disposable user, session token, and hash salt. The expiry test generates a token two nonce ticks behind the current tick; it does not change wall-clock time or simulate an arbitrary HTTP error. The real plugin checks that nonce before validating or adding any bottles. Product/cart storage are separate test doubles, not a WooCommerce integration database.

Source SHA-256: `3a2482a65b50d62ebae75a728be98b78985d3a3f5a3e007cb2d0c1338bf05777`.
