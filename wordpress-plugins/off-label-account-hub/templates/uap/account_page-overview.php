<?php
/**
 * Branded native-data affiliate overview.
 *
 * @var array $data Native UAP overview data.
 */

defined( 'ABSPATH' ) || exit;

$vm       = OLR_Account_Hub::affiliate_dashboard_data( get_current_user_id(), isset( $data ) ? $data : array() );
$currency = $vm['currency'];
$money    = static function ( $amount ) use ( $currency ) {
	return function_exists( 'uap_format_price_and_currency' )
		? uap_format_price_and_currency( $currency, round( (float) $amount, 2 ) )
		: esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
};
$account_url     = home_url( '/account/' );
$performance_url = add_query_arg( 'um_tab', 'performance', $account_url );
$commissions_url = add_query_arg( 'um_tab', 'commissions', $account_url );
$payouts_url     = add_query_arg( 'um_tab', 'payouts', $account_url );
$creative_url    = add_query_arg( 'um_tab', 'creative', $account_url );
$guidelines_url  = home_url( '/affiliate-guidelines/' );
$support_email   = sanitize_email( (string) get_option( 'olr_affiliate_notification_email', get_option( 'admin_email', '' ) ) );
$status_labels   = array(
	0 => __( 'Rejected', 'off-label-account-hub' ),
	1 => __( 'Pending', 'off-label-account-hub' ),
	2 => __( 'Approved', 'off-label-account-hub' ),
);
?>
<div class="olr-affiliate-overview olr-affiliate-overview--complete">
	<section class="olr-affiliate-access-card" aria-label="<?php esc_attr_e( 'Affiliate access details', 'off-label-account-hub' ); ?>">
		<div>
			<p class="olr-account-eyebrow"><?php esc_html_e( 'Your code', 'off-label-account-hub' ); ?></p>
			<strong class="olr-affiliate-access-card__value"><?php echo $vm['coupon_code'] ? esc_html( $vm['coupon_code'] ) : esc_html__( 'NOT CONFIGURED', 'off-label-account-hub' ); ?></strong>
			<span><?php echo esc_html( $vm['policy']['customer_discount'] . ' ' . __( 'off their first qualifying order', 'off-label-account-hub' ) ); ?></span>
			<?php if ( $vm['coupon_code'] ) : ?><button type="button" data-olr-copy data-copy-value="<?php echo esc_attr( $vm['coupon_code'] ); ?>"><?php esc_html_e( 'COPY CODE', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></button><?php endif; ?>
		</div>
		<div>
			<p class="olr-account-eyebrow"><?php esc_html_e( 'Your referral link', 'off-label-account-hub' ); ?></p>
			<strong class="olr-affiliate-access-card__link"><?php echo $vm['referral_url'] ? esc_html( preg_replace( '#^https?://#', '', $vm['referral_url'] ) ) : esc_html__( 'Not available yet', 'off-label-account-hub' ); ?></strong>
			<?php if ( $vm['referral_url'] ) : ?><button type="button" data-olr-copy data-copy-value="<?php echo esc_attr( $vm['referral_url'] ); ?>"><?php esc_html_e( 'COPY LINK', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></button><?php endif; ?>
		</div>
		<div class="olr-affiliate-access-card__rate"><strong><?php echo esc_html( $vm['rate'] ); ?></strong><span><?php esc_html_e( 'Lifetime commission', 'off-label-account-hub' ); ?></span><p><?php esc_html_e( 'Earn on qualifying purchases from customers you refer.', 'off-label-account-hub' ); ?></p></div>
	</section>

	<section class="olr-account-stat-grid" aria-label="<?php esc_attr_e( 'Affiliate totals', 'off-label-account-hub' ); ?>">
		<div class="olr-account-stat"><strong><?php echo $vm['order_metrics_available'] ? wp_kses_post( $money( $vm['sales_generated'] ) ) : esc_html__( 'NOT AVAILABLE YET', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Sales generated', 'off-label-account-hub' ); ?></span></div>
		<div class="olr-account-stat"><strong><?php echo $vm['order_metrics_available'] ? esc_html( number_format_i18n( $vm['customer_count'] ) ) : esc_html__( 'NOT AVAILABLE YET', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Customers referred', 'off-label-account-hub' ); ?></span></div>
		<div class="olr-account-stat"><strong><?php echo wp_kses_post( $money( $vm['earnings'] ) ); ?></strong><span><?php esc_html_e( 'Lifetime earnings', 'off-label-account-hub' ); ?></span></div>
		<div class="olr-account-stat"><strong><?php echo wp_kses_post( $money( $vm['available'] ) ); ?></strong><span><?php esc_html_e( 'Available for payout', 'off-label-account-hub' ); ?></span></div>
	</section>

	<section class="olr-affiliate-dashboard-grid olr-affiliate-dashboard-grid--primary">
		<article class="olr-affiliate-earnings-card">
			<p class="olr-account-eyebrow"><?php esc_html_e( 'Your earnings', 'off-label-account-hub' ); ?></p>
			<div class="olr-affiliate-earnings-totals"><div><strong><?php echo wp_kses_post( $money( $vm['pending'] ) ); ?></strong><span><?php esc_html_e( 'Pending', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( $money( $vm['available'] ) ); ?></strong><span><?php esc_html_e( 'Available', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( $money( $vm['paid'] ) ); ?></strong><span><?php esc_html_e( 'Paid', 'off-label-account-hub' ); ?></span></div></div>
			<div class="olr-affiliate-payout-summary"><div><span><?php esc_html_e( 'Next payout', 'off-label-account-hub' ); ?></span><strong><?php echo esc_html( $vm['policy']['payout_schedule'] ); ?></strong><b><?php echo wp_kses_post( $money( $vm['available'] ) ); ?></b></div><div><span><?php esc_html_e( 'Payout method', 'off-label-account-hub' ); ?></span><strong><?php echo esc_html( $vm['policy']['payout_method'] ); ?></strong><a href="<?php echo esc_url( $payouts_url ); ?>"><?php esc_html_e( 'EDIT PAYMENT DETAILS', 'off-label-account-hub' ); ?> &rarr;</a></div></div>
			<p class="olr-affiliate-card-note"><?php echo esc_html( sprintf( __( 'Commissions remain pending for %s before becoming eligible for payout.', 'off-label-account-hub' ), $vm['policy']['hold_period'] ) ); ?></p>
		</article>

		<article class="olr-affiliate-performance-card">
			<div class="olr-affiliate-card-heading"><p class="olr-account-eyebrow"><?php esc_html_e( 'Performance', 'off-label-account-hub' ); ?></p><nav aria-label="<?php esc_attr_e( 'Performance periods', 'off-label-account-hub' ); ?>"><span aria-current="true"><?php esc_html_e( '30 DAYS', 'off-label-account-hub' ); ?></span><a href="<?php echo esc_url( $performance_url ); ?>"><?php esc_html_e( '90 DAYS', 'off-label-account-hub' ); ?></a><a href="<?php echo esc_url( $performance_url ); ?>"><?php esc_html_e( 'ALL TIME', 'off-label-account-hub' ); ?></a></nav></div>
			<?php if ( $vm['chart'] ) : ?><div class="olr-affiliate-chart"><canvas id="chart-1" class="uap-canvas" aria-label="<?php esc_attr_e( 'Earnings for the last 30 days', 'off-label-account-hub' ); ?>"></canvas></div><?php else : ?><div class="olr-affiliate-chart-empty"><?php esc_html_e( 'Performance data will appear after eligible activity is recorded.', 'off-label-account-hub' ); ?></div><?php endif; ?>
			<div class="olr-affiliate-mini-stats"><div><strong><?php echo esc_html( number_format_i18n( $vm['clicks'] ) ); ?></strong><span><?php esc_html_e( 'Clicks', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo esc_html( number_format_i18n( $vm['order_count'] ) ); ?></strong><span><?php esc_html_e( 'Orders', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo esc_html( number_format_i18n( $vm['conversion'], 1 ) ); ?>%</strong><span><?php esc_html_e( 'Conversion', 'off-label-account-hub' ); ?></span></div><div><strong><?php echo $vm['order_metrics_available'] ? wp_kses_post( $money( $vm['average_order_value'] ) ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Avg. order value', 'off-label-account-hub' ); ?></span></div></div>
		</article>
	</section>

	<section class="olr-affiliate-customers" aria-labelledby="olr-affiliate-customers-title">
		<h3 id="olr-affiliate-customers-title"><?php esc_html_e( 'CUSTOMERS YOU’VE BROUGHT OFF LABEL', 'off-label-account-hub' ); ?></h3>
		<div class="olr-affiliate-customer-metrics">
			<div><strong><?php echo $vm['order_metrics_available'] ? esc_html( number_format_i18n( $vm['customer_count'] ) ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Total customers referred', 'off-label-account-hub' ); ?></span></div>
			<div><strong><?php echo $vm['order_metrics_available'] ? esc_html( number_format_i18n( $vm['returning_customers'] ) ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Returning customers', 'off-label-account-hub' ); ?></span></div>
			<div><strong><?php echo $vm['order_metrics_available'] ? esc_html( number_format_i18n( $vm['first_order_only'] ) ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'First-order only', 'off-label-account-hub' ); ?></span></div>
			<div><strong><?php echo $vm['order_metrics_available'] ? esc_html( number_format_i18n( $vm['repeat_rate'], 1 ) . '%' ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Repeat customer rate', 'off-label-account-hub' ); ?></span></div>
			<div><strong><?php echo $vm['order_metrics_available'] ? wp_kses_post( $money( $vm['repeat_sales'] ) ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Repeat customer sales', 'off-label-account-hub' ); ?></span></div>
			<div><strong><?php echo $vm['order_metrics_available'] ? wp_kses_post( $money( $vm['repeat_commission'] ) ) : esc_html__( 'N/A', 'off-label-account-hub' ); ?></strong><span><?php esc_html_e( 'Commission from repeat orders', 'off-label-account-hub' ); ?></span></div>
		</div>
		<p><?php esc_html_e( 'Qualifying customers you acquire remain attributed to you for future eligible purchases under the Off Label Affiliate Terms.', 'off-label-account-hub' ); ?></p>

		<div class="olr-affiliate-recent">
			<div class="olr-affiliate-card-heading"><h3><?php esc_html_e( 'RECENT COMMISSIONS', 'off-label-account-hub' ); ?></h3><a href="<?php echo esc_url( $commissions_url ); ?>"><?php esc_html_e( 'VIEW ALL', 'off-label-account-hub' ); ?> &rarr;</a></div>
			<?php if ( $vm['recent'] ) : ?>
			<div class="olr-account-table-wrap"><table class="olr-account-table"><thead><tr><th><?php esc_html_e( 'Date', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Reference', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Commission', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Status', 'off-label-account-hub' ); ?></th></tr></thead><tbody>
			<?php foreach ( $vm['recent'] as $item ) : $status = isset( $item['status'] ) ? absint( $item['status'] ) : 1; ?>
			<tr><td data-label="<?php esc_attr_e( 'Date', 'off-label-account-hub' ); ?>"><?php echo esc_html( ! empty( $item['date'] ) && function_exists( 'uap_convert_date_to_us_format' ) ? uap_convert_date_to_us_format( $item['date'] ) : ( ! empty( $item['date'] ) ? $item['date'] : '—' ) ); ?></td><td data-label="<?php esc_attr_e( 'Reference', 'off-label-account-hub' ); ?>">#<?php echo esc_html( isset( $item['id'] ) ? absint( $item['id'] ) : 0 ); ?></td><td data-label="<?php esc_attr_e( 'Commission', 'off-label-account-hub' ); ?>"><?php echo wp_kses_post( $money( isset( $item['amount'] ) ? $item['amount'] : 0 ) ); ?></td><td data-label="<?php esc_attr_e( 'Status', 'off-label-account-hub' ); ?>"><span class="olr-referral-status olr-referral-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status_labels[1] ); ?></span></td></tr>
			<?php endforeach; ?>
			</tbody></table></div>
			<?php else : ?><p class="olr-affiliate-card-note"><?php esc_html_e( 'Commission activity will appear after a qualifying referral is recorded.', 'off-label-account-hub' ); ?></p><?php endif; ?>
		</div>
	</section>

	<section class="olr-affiliate-resource-grid">
		<article><p class="olr-account-eyebrow"><?php esc_html_e( 'Payouts', 'off-label-account-hub' ); ?></p><strong><?php echo wp_kses_post( $money( $vm['available'] ) ); ?></strong><span><?php esc_html_e( 'Available', 'off-label-account-hub' ); ?></span><p><?php echo esc_html( sprintf( __( 'Next %1$s payout · %2$s minimum · %3$s', 'off-label-account-hub' ), strtolower( $vm['policy']['payout_schedule'] ), $vm['policy']['minimum_payout'], $vm['policy']['payout_method'] ) ); ?></p><a href="<?php echo esc_url( $payouts_url ); ?>"><?php esc_html_e( 'VIEW PAYOUTS', 'off-label-account-hub' ); ?> &rarr;</a></article>
		<article><p class="olr-account-eyebrow"><?php esc_html_e( 'Creative library', 'off-label-account-hub' ); ?></p><ul><li><a href="<?php echo esc_url( $creative_url ); ?>"><?php esc_html_e( 'CURRENT OFFER', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $creative_url ); ?>"><?php esc_html_e( 'SOCIAL ASSETS', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $creative_url ); ?>"><?php esc_html_e( 'PRODUCT IMAGERY', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $creative_url ); ?>"><?php esc_html_e( 'APPROVED LANGUAGE', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $creative_url ); ?>"><?php esc_html_e( 'BRAND GUIDELINES', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li></ul></article>
		<article><p class="olr-account-eyebrow"><?php esc_html_e( 'Partner guidelines', 'off-label-account-hub' ); ?></p><ul><li><a href="<?php echo esc_url( $guidelines_url ); ?>"><?php esc_html_e( 'AFFILIATE DISCLOSURE', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $guidelines_url ); ?>"><?php esc_html_e( 'RESEARCH USE GUIDELINES', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $guidelines_url ); ?>"><?php esc_html_e( 'WHAT YOU CAN + CANNOT SAY', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li><li><a href="<?php echo esc_url( $guidelines_url ); ?>"><?php esc_html_e( 'AFFILIATE TERMS', 'off-label-account-hub' ); ?> <span>&rarr;</span></a></li></ul><?php if ( $support_email ) : ?><a class="olr-affiliate-support" href="mailto:<?php echo esc_attr( $support_email ); ?>"><?php esc_html_e( 'CONTACT AFFILIATE SUPPORT', 'off-label-account-hub' ); ?> &rarr;</a><?php endif; ?></article>
	</section>
</div>

<?php if ( $vm['chart'] && defined( 'UAP_URL' ) && defined( 'UAP_ASSET_VERSION' ) ) : ?>
	<?php wp_enqueue_script( 'uap-moment.js', UAP_URL . 'assets/js/moment.min.js', array( 'jquery' ), UAP_ASSET_VERSION, array( 'in_footer' => true ) ); ?>
	<?php wp_enqueue_script( 'uap-chart.js', UAP_URL . 'assets/js/chart.min.js', array( 'jquery' ), UAP_ASSET_VERSION, array( 'in_footer' => true ) ); ?>
	<?php wp_enqueue_script( 'uap-public-overview', UAP_URL . 'assets/js/public-overview.js', array( 'jquery' ), UAP_ASSET_VERSION, array( 'in_footer' => true ) ); ?>
	<span class="uap-js-overview-earnings-received-label" data-value="<?php echo esc_attr( __( 'Earnings received', 'off-label-account-hub' ) . ' (' . $currency . ')' ); ?>"></span>
	<span class="uap-js-overview-earnings-label" data-value="<?php esc_attr_e( 'Earnings', 'off-label-account-hub' ); ?>"></span>
	<?php foreach ( $vm['chart'] as $date => $amount ) : $date_parts = explode( '-', $date ); $day = isset( $date_parts[2] ) ? $date_parts[2] : $date; ?>
		<span class="uap-js-overview-stats-last-30" data-date="<?php echo esc_attr( function_exists( 'uap_convert_date_to_us_format' ) ? uap_convert_date_to_us_format( $date ) : $date ); ?>" data-amount="<?php echo esc_attr( wp_strip_all_tags( $money( $amount ) ) ); ?>" data-base_amount="<?php echo esc_attr( $amount ); ?>" data-label="<?php echo esc_attr( $day ); ?>"></span>
	<?php endforeach; ?>
<?php endif; ?>
