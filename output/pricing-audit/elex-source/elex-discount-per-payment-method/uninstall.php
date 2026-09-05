<?php
/**
 * Discount per payment method Uninstall
 *
 * Uninstalling  options.
 *
 * @package DiscountPerPaymentMethod\Uninstaller
 * @version 1.0.0
 */


 // if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
   
	die;
}

//Deleting options from the site
delete_option ( 'elex_discount_per_payment_method_options' );

