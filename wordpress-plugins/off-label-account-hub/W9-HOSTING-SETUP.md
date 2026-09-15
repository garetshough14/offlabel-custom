# W-9 setup for offlabelresearch.com

The live Affiliate Management page was checked on September 8, 2026 (Pacific time). It reports missing `OLR_AFFILIATE_PRIVATE_DIR` and `OLR_AFFILIATE_W9_KEY_FILE` constants. The hosting dashboard shows WordPress.com Business, PHP 8.4, SFTP enabled and SSH disabled. No live configuration was changed.

That error occurs before the Sodium check. It means the document location and encryption-key file have not been connected to the plugin; it does not mean all three checks failed.

WordPress.com lists Sodium and Fileinfo in its [PHP environment](https://wordpress.com/support/php-environment/) and permits `wp-config.php` edits through [SFTP](https://wordpress.com/support/sftp/troubleshooting-sftp/). It uses NGINX, does not use `.htaccess`, and does not permit per-site NGINX or php.ini changes. A public uploads directory protected only by `.htaccess` does not meet this plugin's private-storage requirement.

## First obtain the host's private storage paths

Open WordPress.com Hosting for this site and choose **Help**. Send the following request to support (this document has not been sent):

> For offlabelresearch.com on WordPress.com Business, our custom Account Hub plugin needs to store encrypted affiliate W-9 PDFs. Can this plan provide a persistent directory outside the site's public document root that the site's PHP process can read and write, plus a separate private encryption-key file readable by PHP? Both must survive server/container restarts and be available to every PHP worker, with no public URL, web-server alias, or CDN mapping. Please confirm the actual absolute PHP filesystem paths, access permissions, and backup/restore coverage for these locations. We cannot use temporary storage or wp-content/uploads. If this is unsupported on our plan, please confirm that limitation so we can use a private external storage integration instead.

Do not send a W-9, password, or encryption key in that request. The plugin cannot safely choose a persistent private host path based only on the WordPress admin session.

## After the host confirms support

1. Have the host provision the confirmed persistent private document directory and a separate secrets directory, accessible only to the required service account. Use restricted filesystem permissions or equivalent host ACLs.
2. Generate a random 32-byte key once on the server and store its base64 encoding in the separate private key file. The README includes a CLI example that refuses to overwrite an existing key. Do not use the sample paths as real host paths; do not put the key in Code Snippets, WordPress options, or the plugin ZIP.
3. Through SFTP, back up `wp-config.php` securely and add the following before WordPress loads, replacing both placeholder values with the host-confirmed absolute paths. These constants contain paths, not the key itself:

```php
define('OLR_AFFILIATE_PRIVATE_DIR', 'HOST_CONFIRMED_ABSOLUTE_DOCUMENT_DIRECTORY');
define('OLR_AFFILIATE_W9_KEY_FILE', 'HOST_CONFIRMED_ABSOLUTE_KEY_FILE');
```

4. Reopen **Ultimate Affiliate Pro > Affiliate Management**. The storage error must disappear. If another readiness error appears, resolve that specific error; the earlier missing-constant error did not verify later checks.
5. Check a clearly marked synthetic PDF upload through an eligible test member, replacement requiring renewed approval, and authorized admin download. Verify signed-out and ordinary-member downloads are denied. Never test with a real tax ID. Confirm the host backs up the documents and key separately with restricted access and can restore them together with the database.
6. Enable payouts in Affiliate Management only once all installation and settlement checks pass. Successful storage setup alone does not turn payouts on. Neither activation nor member eligibility for store credit requires a W-9, but this release's program-level payout readiness gate requires private storage before either payout method is enabled.

If WordPress.com cannot provide the required persistent private filesystem, the current local-file implementation cannot be enabled there as designed. A separate integration with private external document storage and protected key management is required. Keep uploads disabled until that implementation and its configuration are verified. The dashboard menu fix does not provision hosting storage.
