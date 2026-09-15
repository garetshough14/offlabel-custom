<?php
/** Customer overview rendered in the existing Ultimate Member shell. */
defined( 'ABSPATH' ) || exit;
?>
<section class="olr-account-panel olr-customer-dashboard" aria-labelledby="olr-customer-title">
	<p class="olr-account-eyebrow"><?php esc_html_e( 'My account', 'off-label-account-hub' ); ?></p>
	<h2 id="olr-customer-title" class="olr-customer-title"><?php echo esc_html( sprintf( __( 'WELCOME BACK, %s.', 'off-label-account-hub' ), $first_name ) ); ?></h2>
	<p class="olr-customer-intro"><?php esc_html_e( 'Here’s a quick overview of your account.', 'off-label-account-hub' ); ?></p>
	<?php OLR_Affiliate_Flows::notice(); ?>
	<?php if ( $recent ) : $date = $recent->get_date_created(); ?>
	<div class="olr-recent-order">
		<div><p class="olr-summary-label"><?php esc_html_e( 'Recent order', 'off-label-account-hub' ); ?></p>
			<a class="olr-summary-value" href="<?php echo esc_url( add_query_arg( array( 'um_tab' => 'orders', 'order_id' => $recent->get_id() ), $this->account_url() ) ); ?>"><?php echo esc_html( sprintf( __( 'ORDER #%s', 'off-label-account-hub' ), $recent->get_order_number() ) ); ?></a>
			<?php if ( $date ) : ?><p><?php echo esc_html( $date->date_i18n( get_option( 'date_format' ) ) ); ?></p><?php endif; ?>
			<p class="olr-order-status-label"><?php echo esc_html( wc_get_order_status_name( $recent->get_status() ) ); ?></p>
		</div>
		<div><p class="olr-summary-label"><?php esc_html_e( 'Tracking', 'off-label-account-hub' ); ?></p><?php echo wp_kses_post( OLR_Order_Tracking::render( $recent, true ) ); ?></div>
		<div><p class="olr-summary-label"><?php esc_html_e( 'Order total', 'off-label-account-hub' ); ?></p>
			<strong class="olr-summary-value"><?php echo wp_kses_post( $recent->get_meta( '_olr_credit_gross_total' ) ? wc_price( (int) $recent->get_meta( '_olr_credit_gross_total' ) / 100, array( 'currency' => $recent->get_currency() ) ) : $recent->get_formatted_order_total() ); ?></strong>
			<p class="olr-order-status-label"><?php echo esc_html( sprintf( _n( '%s item', '%s items', $recent->get_item_count(), 'off-label-account-hub' ), number_format_i18n( $recent->get_item_count() ) ) ); ?></p>
		</div>
	</div>
	<?php else : ?>
	<div class="olr-recent-order olr-recent-order--empty"><div><p class="olr-summary-label"><?php esc_html_e( 'Your first order starts here', 'off-label-account-hub' ); ?></p><p><?php esc_html_e( 'Once you place an order, you’ll find its details and shipment tracking here.', 'off-label-account-hub' ); ?></p></div></div>
	<?php endif; ?>
	<div class="olr-dashboard-actions">
		<a class="olr-account-button" href="<?php echo esc_url( $this->account_tab_url( 'orders' ) ); ?>"><?php esc_html_e( 'View all orders', 'off-label-account-hub' ); ?> <span aria-hidden="true">&rarr;</span></a>
		<a class="olr-account-button olr-account-button--outline" href="<?php echo esc_url( $this->account_tab_url( 'general' ) ); ?>"><?php esc_html_e( 'Account details', 'off-label-account-hub' ); ?> <span aria-hidden="true">&rarr;</span></a>
	</div>
	<div class="olr-dashboard-section">
		<p class="olr-account-eyebrow"><?php esc_html_e( 'Need help with an order?', 'off-label-account-hub' ); ?></p>
		<p><?php esc_html_e( 'Visit our shipping page or send us a message.', 'off-label-account-hub' ); ?></p>
		<div class="olr-dashboard-links"><a class="olr-account-text-link" href="<?php echo esc_url( home_url( '/shipping/' ) ); ?>"><?php esc_html_e( 'Shipping & returns', 'off-label-account-hub' ); ?> &rarr;</a><a class="olr-account-text-link" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact us', 'off-label-account-hub' ); ?> &rarr;</a></div>
	</div>
	<div class="olr-dashboard-section olr-dashboard-explore">
		<p class="olr-account-eyebrow"><?php esc_html_e( 'Explore', 'off-label-account-hub' ); ?></p>
		<h3><?php esc_html_e( 'CONTINUE YOUR RESEARCH.', 'off-label-account-hub' ); ?></h3>
		<a class="olr-account-button" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>"><?php esc_html_e( 'Shop all products', 'off-label-account-hub' ); ?> <span aria-hidden="true">&rarr;</span></a>
	</div>
</section>
