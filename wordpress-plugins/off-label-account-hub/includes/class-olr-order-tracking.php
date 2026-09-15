<?php
/** Customer-owned tracking views; ShipStation remains responsible for fulfillment. */
defined( 'ABSPATH' ) || exit;

final class OLR_Order_Tracking {
	public static function boot() {
		// Both ShipStation 5.3.5 XML and REST shipment notifications expose this hook.
		add_action( 'woocommerce_shipstation_shipnotify', array( __CLASS__, 'capture' ), 10, 2 );
	}
	public static function capture( $order, $data ) {
		if ( ! $order instanceof WC_Order || ! is_array( $data ) ) { return; }
		if ( isset( $data['data']['tracking_url'] ) ) { $data['tracking_link'] = $data['data']['tracking_url']; }
		$item = self::normalize( $data );
		if ( ! $item ) { return; }
		// One key per parcel makes retry notifications idempotent and retains split shipments.
		$order->update_meta_data( '_olr_shipstation_' . md5( strtolower( $item['carrier'] ) . '|' . $item['number'] ), $item );
		$order->save_meta_data();
	}
	private static function text( $value ) { return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : ''; }
	public static function normalize( $data ) {
		if ( ! is_array( $data ) ) { return null; }
		$number = self::text( $data['tracking_number'] ?? $data['number'] ?? '' );
		if ( '' === $number || strlen( $number ) > 150 ) { return null; }
		$carrier = self::text( $data['custom_tracking_provider'] ?? '' ) ?: self::text( $data['tracking_provider'] ?? $data['carrier'] ?? '' );
		$url = self::text( $data['custom_tracking_link'] ?? $data['tracking_link'] ?? $data['url'] ?? '' );
		$url = str_replace( array( '%1$s', '%s' ), rawurlencode( $number ), $url );
		$url = esc_url_raw( $url, array( 'https', 'http' ) );
		if ( ! preg_match( '~^https?://~i', $url ) ) { $url = ''; }
		if ( ! $url ) { $url = self::carrier_url( $carrier, $number ); }
		$date = $data['date_shipped'] ?? $data['ship_date'] ?? '';
		$date = is_scalar( $date ) ? $date : '';
		$timestamp = is_numeric( $date ) ? (int) $date : ( $date ? strtotime( $date ) : 0 );
		return array( 'number' => $number, 'carrier' => $carrier, 'url' => $url, 'ship_date' => $timestamp > 0 ? $timestamp : 0 );
	}
	public static function carrier_url( $carrier, $number ) {
		$carrier = strtolower( preg_replace( '/[^a-z0-9]/i', '', $carrier ) );
		$links = array(
			'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
			'ups' => 'https://www.ups.com/track?tracknum=',
			'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=',
			'dhlexpress' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=',
			'dhl' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=',
		);
		return isset( $links[ $carrier ] ) ? $links[ $carrier ] . rawurlencode( $number ) : '';
	}
	/** Older ShipStation installs store shipment details in notes, sometimes private notes.
	 * Only the exact shipping fields are extracted; note bodies are never rendered.
	 */
	public static function from_note( $content ) {
		$text = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' );
		if ( ! preg_match( '/^.+? shipped via (.{1,100}?) on (.{1,80}?) with tracking number ([a-z0-9][a-z0-9 -]{1,149})\s*(?:\(Shipstation\))?\.?$/i', trim( $text ), $match ) ) { return null; }
		return self::normalize( array( 'carrier' => $match[1], 'ship_date' => $match[2], 'tracking_number' => trim( $match[3] ) ) );
	}
	public static function items( $order ) {
		if ( ! is_user_logged_in() || ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) { return array(); }
		$raw = (array) $order->get_meta( '_wc_shipment_tracking_items', true );
		foreach ( $order->get_meta_data() as $meta ) {
			$entry = $meta->get_data();
			if ( 0 === strpos( $entry['key'], '_olr_shipstation_' ) ) { $raw[] = $entry['value']; }
		}
		// Read-only fallback also makes pre-upgrade shipments visible immediately.
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 100, 'orderby' => 'date_created', 'order' => 'DESC' ) ) as $note ) {
			$item = self::from_note( $note->content );
			if ( $item ) { $raw[] = $item; }
		}
		$items = array();
		foreach ( $raw as $data ) {
			$item = self::normalize( $data );
			if ( ! $item ) { continue; }
			$key = strtolower( $item['number'] );
			if ( ! isset( $items[ $key ] ) ) { $items[ $key ] = $item; }
		}
		return array_values( $items );
	}
	public static function render( $order, $compact = false ) {
		$items = self::items( $order );
		if ( ! $items ) { return '<p class="olr-tracking-empty">' . esc_html__( 'Tracking will appear here once your shipment is booked.', 'off-label-account-hub' ) . '</p>'; }
		$html = '<ul class="olr-tracking-list">';
		foreach ( $items as $item ) {
			$html .= '<li><span class="olr-tracking-carrier">' . esc_html( $item['carrier'] ?: __( 'Package', 'off-label-account-hub' ) ) . '</span>';
			$html .= '<span class="olr-tracking-number">' . esc_html( $item['number'] ) . '</span>';
			if ( ! $compact && $item['ship_date'] ) { $html .= '<span class="olr-tracking-date">' . esc_html( sprintf( __( 'Shipped %s', 'off-label-account-hub' ), wp_date( get_option( 'date_format' ), $item['ship_date'] ) ) ) . '</span>'; }
			if ( $item['url'] ) { $html .= '<a class="olr-account-text-link" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Track package', 'off-label-account-hub' ) . ' <span aria-hidden="true">&rarr;</span></a>'; }
			$html .= '</li>';
		}
		return $html . '</ul>';
	}
}
