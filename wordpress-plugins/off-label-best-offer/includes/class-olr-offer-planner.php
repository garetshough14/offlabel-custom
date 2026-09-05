<?php
/** Pure allocation rules. All money uses WooCommerce's integer minor units. */
defined( 'ABSPATH' ) || exit;

final class OLR_Offer_Planner {
	public static function tier( $quantity, array $tiers ) {
		$rate = 0;
		usort( $tiers, static function ( $a, $b ) { return $a['quantity'] <=> $b['quantity']; } );
		foreach ( $tiers as $tier ) {
			if ( $quantity >= $tier['quantity'] ) {
				$rate = $tier['percent'];
			}
		}
		return max( 0, min( 100, (float) $rate ) );
	}

	/** Largest-remainder allocation: deterministic, sum-preserving, never over a line's price. */
	public static function allocate( $amount, array $weights ) {
		ksort( $weights );
		$weights = array_map( static function ( $value ) { return max( 0, (int) round( $value ) ); }, $weights );
		$total = array_sum( $weights );
		$amount = min( $total, max( 0, (int) round( $amount ) ) );
		$result = array_fill_keys( array_keys( $weights ), 0 );
		if ( ! $total || ! $amount ) { return $result; }
		$remainders = array();
		foreach ( $weights as $key => $weight ) {
			$exact = $amount * $weight / $total;
			$result[ $key ] = (int) floor( $exact );
			$remainders[ $key ] = $exact - $result[ $key ];
		}
		arsort( $remainders, SORT_NUMERIC );
		$remaining = $amount - array_sum( $result );
		foreach ( $remainders as $key => $unused ) {
			if ( $remaining <= 0 ) { break; }
			if ( $result[ $key ] < $weights[ $key ] ) { ++$result[ $key ]; --$remaining; }
		}
		return $result;
	}

	/**
	 * Groups: id => {prices: key=>cents, automatic: offer=>key=>cents, allow_promos:bool}.
	 * Coupons are independently quoted by native WC_Discounts against the SAME original items.
	 * Fixed-cart allocations are not recycled onto other groups after losing a comparison.
	 * Ties retain the automatic offer, then the first applied coupon. No item gets two offers.
	 */
	public static function choose( array $groups, array $coupons ) {
		$plan = array( 'automatic' => array(), 'coupons' => array(), 'groups' => array() );
		foreach ( $coupons as $code => $map ) { $plan['coupons'][ $code ] = array(); }
		foreach ( $groups as $id => $group ) {
			$winner = 'regular';
			$source = 'automatic';
			$best = array_fill_keys( array_keys( $group['prices'] ), 0 );
			$candidates = $group['automatic'];
			foreach ( $candidates as $offer => $map ) {
				$map = self::clamp( $map, $group['prices'] );
				if ( array_sum( $map ) > array_sum( $best ) ) { $winner = $offer; $best = $map; }
			}
			if ( $group['allow_promos'] ) {
				foreach ( $coupons as $code => $map ) {
					$map = self::clamp( $map, $group['prices'] );
					if ( array_sum( $map ) > array_sum( $best ) ) { $winner = $code; $source = 'coupon'; $best = $map; }
				}
			}
			if ( 'coupon' === $source ) { $plan['coupons'][ $winner ] += $best; }
			else { $plan['automatic'] += $best; }
			$plan['groups'][ $id ] = array( 'offer' => $winner, 'source' => $source, 'savings' => array_sum( $best ), 'lines' => $best );
		}
		return $plan;
	}

	private static function clamp( array $map, array $prices ) {
		$result = array();
		foreach ( $prices as $key => $price ) { $result[ $key ] = min( $price, max( 0, (int) round( $map[ $key ] ?? 0 ) ) ); }
		return $result;
	}
}
