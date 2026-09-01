<?php
/**
 * Branded native-data affiliate overview.
 *
 * @var array $data Native UAP overview data.
 */

defined( 'ABSPATH' ) || exit;

$stats       = isset( $data['stats'] ) && is_array( $data['stats'] ) ? $data['stats'] : array();
$month_stats = isset( $data['referralsExtraStats'] ) && is_array( $data['referralsExtraStats'] ) ? $data['referralsExtraStats'] : array();
$report      = isset( $data['referralsStats'] ) && is_array( $data['referralsStats'] ) ? $data['referralsStats'] : array();
$currency    = isset( $stats['currency'] ) ? $stats['currency'] : ( function_exists( 'uapCurrency' ) ? uapCurrency() : 'USD' );
$paid        = isset( $stats['paid_payments_value'] ) ? (float) $stats['paid_payments_value'] : 0.0;
$available   = isset( $stats['unpaid_payments_value'] ) ? (float) $stats['unpaid_payments_value'] : 0.0;
$earnings    = $paid + $available;
$referrals   = isset( $stats['referrals'] ) ? absint( $stats['referrals'] ) : 0;
$payments    = isset( $stats['payments'] ) ? absint( $stats['payments'] ) : 0;
$clicks      = isset( $month_stats['visits'] ) ? absint( $month_stats['visits'] ) : 0;
$conversion  = isset( $report['success_rate'] ) ? (float) $report['success_rate'] : 0.0;
$rank        = OLR_Account_Hub::affiliate_rank( get_current_user_id() );
$referral_url = OLR_Account_Hub::affiliate_referral_url( get_current_user_id() );
$recent      = OLR_Account_Hub::recent_referrals( get_current_user_id(), 5 );
$money       = static function ( $amount ) use ( $currency ) {
	return function_exists( 'uap_format_price_and_currency' )
		? uap_format_price_and_currency( $currency, round( (float) $amount, 2 ) )
		: esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
};
$rate = '';
if ( $rank && isset( $rank['amount_value'] ) ) {
	if ( isset( $rank['amount_type'] ) && 'flat' === $rank['amount_type'] ) {
		$rate = wp_strip_all_tags( $money( $rank['amount_value'] ) );
	} else {
		$rate = number_format_i18n( (float) $rank['amount_value'], 0 ) . '%';
	}
}
?>
<div class="olr-affiliate-overview">
	<section class="olr-affiliate-link-card" aria-labelledby="olr-referral-link-title">
		<div>
			<p class="olr-account-eyebrow" id="olr-referral-link-title"><?php esc_html_e( 'Your referral link', 'off-label-account-hub' ); ?></p>
			<?php if ( $referral_url ) : ?>
				<div class="olr-copy-field"><input id="olr-affiliate-referral-link" type="text" value="<?php echo esc_attr( $referral_url ); ?>" readonly aria-label="<?php esc_attr_e( 'Your referral link', 'off-label-account-hub' ); ?>"><button type="button" data-olr-copy data-copy-value="<?php echo esc_attr( $referral_url ); ?>"><?php esc_html_e( 'COPY LINK', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></button></div>
			<?php else : ?>
				<p><?php esc_html_e( 'Your referral link is not available yet.', 'off-label-account-hub' ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $rate ) : ?>
			<div class="olr-affiliate-rate"><strong><?php echo esc_html( $rate ); ?></strong><span><?php esc_html_e( 'Commission rate', 'off-label-account-hub' ); ?></span><?php if ( ! empty( $rank['label'] ) ) : ?><small><?php echo esc_html( $rank['label'] ); ?></small><?php endif; ?></div>
		<?php endif; ?>
	</section>

	<section class="olr-account-stat-grid" aria-label="<?php esc_attr_e( 'Affiliate totals', 'off-label-account-hub' ); ?>">
		<div class="olr-account-stat"><strong><?php echo wp_kses_post( $money( $earnings ) ); ?></strong><span><?php esc_html_e( 'Total earnings', 'off-label-account-hub' ); ?></span></div>
		<div class="olr-account-stat"><strong><?php echo esc_html( number_format_i18n( $referrals ) ); ?></strong><span><?php esc_html_e( 'Total referrals', 'off-label-account-hub' ); ?></span></div>
		<div class="olr-account-stat"><strong><?php echo wp_kses_post( $money( $paid ) ); ?></strong><span><?php esc_html_e( 'Paid', 'off-label-account-hub' ); ?></span></div>
		<div class="olr-account-stat"><strong><?php echo wp_kses_post( $money( $available ) ); ?></strong><span><?php esc_html_e( 'Available for payout', 'off-label-account-hub' ); ?></span></div>
	</section>

	<section class="olr-affiliate-dashboard-grid">
		<div class="olr-affiliate-performance-card">
			<div class="olr-affiliate-card-heading"><div><p class="olr-account-eyebrow"><?php esc_html_e( 'Performance', 'off-label-account-hub' ); ?></p><h3><?php esc_html_e( 'LAST 30 DAYS.', 'off-label-account-hub' ); ?></h3></div><a href="<?php echo esc_url( add_query_arg( 'um_tab', 'performance', home_url( '/account/' ) ) ); ?>"><?php esc_html_e( 'VIEW REPORTS', 'off-label-account-hub' ); ?> <span aria-hidden="true">&rarr;</span></a></div>
			<?php if ( ! empty( $data['statsForLast30'] ) ) : ?><div class="olr-affiliate-chart"><canvas id="chart-1" class="uap-canvas" aria-label="<?php esc_attr_e( 'Earnings for the last 30 days', 'off-label-account-hub' ); ?>"></canvas></div><?php else : ?><div class="olr-affiliate-chart-empty"><?php esc_html_e( 'Performance data will appear after eligible activity is recorded.', 'off-label-account-hub' ); ?></div><?php endif; ?>
			<div class="olr-affiliate-mini-stats"><div><strong><?php echo esc_html( number_format_i18n( $clicks ) ); ?></strong><span><?php esc_html_e( 'Clicks', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo esc_html( number_format_i18n( $conversion, 1 ) ); ?>%</strong><span><?php esc_html_e( 'Conversion', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo esc_html( number_format_i18n( $payments ) ); ?></strong><span><?php esc_html_e( 'Payouts', 'off-label-account-hub' ); ?></span></div></div>
		</div>

		<div class="olr-affiliate-earnings-card">
			<p class="olr-account-eyebrow"><?php esc_html_e( 'Your earnings', 'off-label-account-hub' ); ?></p>
			<dl><div><dt><?php esc_html_e( 'Pending and available', 'off-label-account-hub' ); ?></dt><dd><?php echo wp_kses_post( $money( $available ) ); ?></dd></div><div><dt><?php esc_html_e( 'Paid', 'off-label-account-hub' ); ?></dt><dd><?php echo wp_kses_post( $money( $paid ) ); ?></dd></div><div><dt><?php esc_html_e( 'Referrals this month', 'off-label-account-hub' ); ?></dt><dd><?php echo esc_html( number_format_i18n( isset( $month_stats['total_referrals'] ) ? absint( $month_stats['total_referrals'] ) : 0 ) ); ?></dd></div></dl>
			<a class="olr-account-text-link" href="<?php echo esc_url( add_query_arg( 'um_tab', 'payouts', home_url( '/account/' ) ) ); ?>"><?php esc_html_e( 'VIEW PAYOUTS', 'off-label-account-hub' ); ?> <span aria-hidden="true">&rarr;</span></a>
		</div>
	</section>

	<?php if ( $recent ) : ?>
	<section class="olr-affiliate-recent" aria-labelledby="olr-recent-commissions-title">
		<div class="olr-affiliate-card-heading"><div><p class="olr-account-eyebrow"><?php esc_html_e( 'Activity', 'off-label-account-hub' ); ?></p><h3 id="olr-recent-commissions-title"><?php esc_html_e( 'RECENT COMMISSIONS.', 'off-label-account-hub' ); ?></h3></div><a href="<?php echo esc_url( add_query_arg( 'um_tab', 'commissions', home_url( '/account/' ) ) ); ?>"><?php esc_html_e( 'VIEW ALL', 'off-label-account-hub' ); ?> <span aria-hidden="true">&rarr;</span></a></div>
		<div class="olr-account-table-wrap"><table class="olr-account-table"><thead><tr><th><?php esc_html_e( 'Date', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Reference', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Commission', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Status', 'off-label-account-hub' ); ?></th></tr></thead><tbody>
		<?php foreach ( $recent as $item ) :
			$status = isset( $item['status'] ) ? absint( $item['status'] ) : 0;
			$labels = array( 0 => __( 'Rejected', 'off-label-account-hub' ), 1 => __( 'Pending', 'off-label-account-hub' ), 2 => __( 'Approved', 'off-label-account-hub' ) );
			?>
			<tr><td data-label="<?php esc_attr_e( 'Date', 'off-label-account-hub' ); ?>"><?php echo esc_html( ! empty( $item['date'] ) && function_exists( 'uap_convert_date_to_us_format' ) ? uap_convert_date_to_us_format( $item['date'] ) : '' ); ?></td><td data-label="<?php esc_attr_e( 'Reference', 'off-label-account-hub' ); ?>">#<?php echo esc_html( isset( $item['id'] ) ? absint( $item['id'] ) : 0 ); ?></td><td data-label="<?php esc_attr_e( 'Commission', 'off-label-account-hub' ); ?>"><?php echo wp_kses_post( $money( isset( $item['amount'] ) ? $item['amount'] : 0 ) ); ?></td><td data-label="<?php esc_attr_e( 'Status', 'off-label-account-hub' ); ?>"><span class="olr-referral-status olr-referral-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Pending', 'off-label-account-hub' ) ); ?></span></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
	</section>
	<?php endif; ?>
</div>

<?php if ( ! empty( $data['statsForLast30'] ) ) : ?>
	<?php wp_enqueue_script( 'uap-moment.js', UAP_URL . 'assets/js/moment.min.js', array( 'jquery' ), UAP_ASSET_VERSION, array( 'in_footer' => true ) ); ?>
	<?php wp_enqueue_script( 'uap-chart.js', UAP_URL . 'assets/js/chart.min.js', array( 'jquery' ), UAP_ASSET_VERSION, array( 'in_footer' => true ) ); ?>
	<?php wp_enqueue_script( 'uap-public-overview', UAP_URL . 'assets/js/public-overview.js', array( 'jquery' ), UAP_ASSET_VERSION, array( 'in_footer' => true ) ); ?>
	<span class="uap-js-overview-earnings-received-label" data-value="<?php echo esc_attr( __( 'Earnings received', 'off-label-account-hub' ) . ' (' . $currency . ')' ); ?>"></span>
	<span class="uap-js-overview-earnings-label" data-value="<?php esc_attr_e( 'Earnings', 'off-label-account-hub' ); ?>"></span>
	<?php foreach ( $data['statsForLast30'] as $date => $amount ) :
		$date_parts = explode( '-', $date );
		$day        = isset( $date_parts[2] ) ? $date_parts[2] : $date;
		?>
		<span class="uap-js-overview-stats-last-30" data-date="<?php echo esc_attr( function_exists( 'uap_convert_date_to_us_format' ) ? uap_convert_date_to_us_format( $date ) : $date ); ?>" data-amount="<?php echo esc_attr( wp_strip_all_tags( $money( $amount ) ) ); ?>" data-base_amount="<?php echo esc_attr( $amount ); ?>" data-label="<?php echo esc_attr( $day ); ?>"></span>
	<?php endforeach; ?>
<?php endif; ?>

