<?php
/**
 * Paytrail for Woocommerce subscription recurring token controller class
 *
 * @package Paytrail\WooCommercePaymentGateway\Controllers
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\WooCommercePaymentGateway\Plugin;
use Paytrail\WooCommercePaymentGateway\View;

/**
 * Shows the recurring card token on the admin subscription page and lets the merchant switch it to another of the customer's saved cards.
 *
 * @package Paytrail\WooCommercePaymentGateway\Controllers
 */
class SubscriptionToken extends AbstractController {

	/**
	 * The posted field holding the selected payment token ID.
	 */
	public const FIELD = '_paytrail_recurring_token_id';

	/**
	 * SubscriptionToken constructor.
	 */
	public function __construct() {
		// Show the recurring token on the subscription page in the billing fields.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'show_recurring_token' ) );
		// Fires for every order type, with and without HPOS, after WooCommerce has verified the edit nonce. Run after the core order data save so the payment method is final.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_recurring_token' ), 60 );
	}

	/**
	 * Show the recurring token for a Paytrail subscription.
	 *
	 * @param \WC_Order $order The order being edited.
	 * @return void
	 */
	public function show_recurring_token( $order ) {
		if ( ! $this->is_paytrail_subscription( $order ) ) {
			return;
		}

		$current = $this->get_current_token( $order );
		$tokens  = \WC_Payment_Tokens::get_customer_tokens( $order->get_customer_id(), Plugin::GATEWAY_ID );

		// Keep the current card selectable even if it is not among the customer's saved cards.
		if ( $current && ! isset( $tokens[ $current->get_id() ] ) ) {
			$tokens[ $current->get_id() ] = $current;
		}

		( new View( 'SubscriptionToken' ) )->render(
			array(
				'field'   => self::FIELD,
				'current' => $current,
				'tokens'  => $tokens,
			)
		);
	}

	/**
	 * Save the recurring token selected by the merchant.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	public function save_recurring_token( $order_id ) {
		// The nonce is verified by WooCommerce before woocommerce_process_shop_order_meta fires.
		$token_id = absint( filter_input( INPUT_POST, self::FIELD, FILTER_SANITIZE_NUMBER_INT ) );
		if ( ! $token_id || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wcs_get_subscription' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce core capability.
			return;
		}

		$subscription = wcs_get_subscription( $order_id );
		if ( $subscription ) {
			$this->change_recurring_token( $subscription, $token_id );
		}
	}

	/**
	 * Point a Paytrail subscription's renewals at another of the customer's saved cards.
	 *
	 * @param \WC_Order $subscription The subscription.
	 * @param int       $token_id     The saved card to charge renewals with.
	 * @return bool Whether the subscription was changed.
	 */
	public function change_recurring_token( $subscription, $token_id ) {
		if ( ! $this->is_paytrail_subscription( $subscription ) ) {
			return false;
		}

		$current = $this->get_current_token( $subscription );
		if ( $current && $current->get_id() === $token_id ) {
			return false;
		}

		$token = \WC_Payment_Tokens::get( $token_id );

		// Only a Paytrail card owned by the subscription's customer may be charged for its renewals.
		if ( ! $token || Plugin::GATEWAY_ID !== $token->get_gateway_id() || $subscription->get_customer_id() !== $token->get_user_id() ) {
			return false;
		}

		$subscription->get_data_store()->update_payment_token_ids( $subscription, array( $token->get_id() ) );
		$subscription->add_order_note(
			sprintf(
				/* translators: %s: card display name, e.g. "Visa ending in 1234 (expires 11/26)". */
				__( 'Paytrail recurring token changed by admin to %s.', 'paytrail-for-woocommerce' ),
				$token->get_display_name()
			)
		);

		return true;
	}

	/**
	 * Whether the order is a subscription paid with Paytrail.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	private function is_paytrail_subscription( $order ) {
		return $order instanceof \WC_Order
			&& 'shop_subscription' === $order->get_type()
			&& Plugin::GATEWAY_ID === $order->get_payment_method();
	}

	/**
	 * Get the Paytrail token that renewals of the subscription will be charged with.
	 *
	 * @param \WC_Order $order The subscription.
	 * @return \WC_Payment_Token|null
	 */
	private function get_current_token( $order ) {
		foreach ( \WC_Payment_Tokens::get_order_tokens( $order->get_id() ) as $token ) {
			if ( Plugin::GATEWAY_ID === $token->get_gateway_id() ) {
				return $token;
			}
		}

		return null;
	}
}
