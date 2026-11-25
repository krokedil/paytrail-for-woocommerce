<?php
/**
 * Paytrail for Woocommerce subscription retries controller class
 *
 * @package Paytrail\WooCommercePaymentGateway\Controllers
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\WooCommercePaymentGateway\Plugin;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;

/**
 * Stops WooCommerce Subscriptions from retrying renewals Paytrail says must not be retried.
 *
 * @package Paytrail\WooCommercePaymentGateway\Controllers
 */
class SubscriptionRetries {

	/**
	 * Acquirer response codes Paytrail says must never be retried, by acquirer.
	 *
	 * @see https://docs.paytrail.com/#/?id=retrying-failed-payments
	 */
	const NON_RETRYABLE_ACQUIRER_RESPONSE_CODES = array(
		'nets' => array( '111', '119', '165', '200', '207', '208', '209', '902', '908', '909' ),
		'amex' => array( '181', '183', '187', '189', '200' ),
	);

	/**
	 * SubscriptionRetries constructor.
	 */
	public function __construct() {
		add_filter( 'wcs_get_retry_rule', array( $this, 'maybe_skip_retry' ), 10, 3 );
	}

	/**
	 * Drop the WooCommerce Subscriptions retry for a renewal Paytrail says must not be retried.
	 *
	 * @param object|null $rule         The retry rule to apply, or null when there is none.
	 * @param int         $retry_number The position in the retry queue.
	 * @param int         $order_id     The renewal order the failed payment belongs to.
	 * @return object|null The rule, or null to skip the retry.
	 */
	public function maybe_skip_retry( $rule, $retry_number, $order_id ) {
		$order = wc_get_order( $order_id );

		if ( null === $rule || ! $order instanceof WC_Order || Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return $rule;
		}

		$code = (string) $order->get_meta( '_paytrail_acquirer_response_code' );

		return self::is_non_retryable_decline( $order, $code ) ? null : $rule;
	}

	/**
	 * Whether an acquirer response code is one that must never be retried for the order's card.
	 *
	 * @param WC_Order $order The order that was declined.
	 * @param string   $code  The acquirer response code.
	 * @return bool
	 */
	public static function is_non_retryable_decline( $order, $code ) {
		if ( '' === $code ) {
			return false;
		}

		/**
		 * Filter the acquirer response codes that must never be retried.
		 *
		 * @since 2.11.0
		 *
		 * @param array<string, string[]> $codes The codes, keyed by acquirer: 'nets' and 'amex'.
		 * @param WC_Order                $order The order that was declined.
		 */
		$codes    = (array) apply_filters( 'paytrail_non_retryable_acquirer_response_codes', self::NON_RETRYABLE_ACQUIRER_RESPONSE_CODES, $order );
		$acquirer = self::is_amex_card( $order ) ? 'amex' : 'nets';

		return in_array( $code, array_map( 'strval', (array) ( $codes[ $acquirer ] ?? array() ) ), true );
	}

	/**
	 * Whether the card a renewal order is charged with is an American Express card.
	 *
	 * @param WC_Order $order The renewal order.
	 * @return bool
	 */
	private static function is_amex_card( $order ) {
		foreach ( WC_Payment_Tokens::get_order_tokens( $order->get_id() ) as $token ) {
			// A renewal charges the first valid card on the order.
			if ( $token instanceof WC_Payment_Token_CC && $token->validate() ) {
				return in_array( strtolower( $token->get_card_type() ), array( 'amex', 'american express', 'american-express' ), true );
			}
		}

		return false;
	}
}
