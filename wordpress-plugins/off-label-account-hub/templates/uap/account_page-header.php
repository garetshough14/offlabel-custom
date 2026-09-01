<?php
/**
 * Branded UAP account header used only inside the Off Label account hub.
 *
 * @var array $data Native UAP header data.
 */

defined( 'ABSPATH' ) || exit;

$user       = wp_get_current_user();
$first_name = $user->first_name ? $user->first_name : $user->display_name;
$subtab     = isset( $_GET['uap_aff_subtab'] ) ? sanitize_key( wp_unslash( $_GET['uap_aff_subtab'] ) ) : 'overview';
$titles     = array(
	'overview'          => sprintf( __( 'WELCOME BACK, %s.', 'off-label-account-hub' ), strtoupper( $first_name ) ),
	'reports'           => __( 'PERFORMANCE.', 'off-label-account-hub' ),
	'visits'            => __( 'TRAFFIC.', 'off-label-account-hub' ),
	'campaign_reports'  => __( 'CAMPAIGN PERFORMANCE.', 'off-label-account-hub' ),
	'referrals_history' => __( 'COMMISSION HISTORY.', 'off-label-account-hub' ),
	'referrals'         => __( 'COMMISSIONS.', 'off-label-account-hub' ),
	'source_details'    => __( 'COMMISSION DETAILS.', 'off-label-account-hub' ),
	'payments'          => __( 'PAYOUTS.', 'off-label-account-hub' ),
	'payments_settings' => __( 'PAYOUT SETTINGS.', 'off-label-account-hub' ),
	'affiliate_link'    => __( 'CREATIVE.', 'off-label-account-hub' ),
	'banners'           => __( 'CREATIVE LIBRARY.', 'off-label-account-hub' ),
	'campaigns'         => __( 'CAMPAIGNS.', 'off-label-account-hub' ),
	'simple_links'      => __( 'REFERRAL LINKS.', 'off-label-account-hub' ),
	'landing_pages'     => __( 'LANDING PAGES.', 'off-label-account-hub' ),
	'coupons'           => __( 'AFFILIATE OFFERS.', 'off-label-account-hub' ),
	'product_links'     => __( 'RESEARCH LINKS.', 'off-label-account-hub' ),
	'help'              => __( 'GUIDELINES.', 'off-label-account-hub' ),
);
$title      = isset( $titles[ $subtab ] ) ? $titles[ $subtab ] : __( 'AFFILIATE PORTAL.', 'off-label-account-hub' );
?>
<div class="uap-user-page-wrapper olr-uap-user-page">
	<div class="uap-user-page-content-wrapper olr-uap-content-wrapper">
		<div class="uap-user-page-content olr-uap-content">
			<header class="olr-affiliate-header">
				<div>
					<p class="olr-account-eyebrow"><?php esc_html_e( 'Off Label affiliate', 'off-label-account-hub' ); ?></p>
					<h2><?php echo esc_html( $title ); ?></h2>
				</div>
				<span class="olr-account-status"><span aria-hidden="true"></span><?php esc_html_e( 'Active affiliate', 'off-label-account-hub' ); ?></span>
			</header>

