<?php
/**
 * Compatibility loader for installations that still reference the legacy flat
 * template path. The nested template is the canonical implementation.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/templates/uap/account_page-overview.php';
