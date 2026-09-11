<?php
/**
 * Paytrail for Woocommerce payment Card success controller class
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\WooCommercePaymentGateway\Plugin;
use Paytrail\WooCommercePaymentGateway\Subscriptions;

/**
 * Handles the redirect back from a successful card addition.
 */
class CardSuccess extends AbstractController {

	/**
	 * Store the card and return the customer to the checkout.
	 *
	 * @return void
	 */
	protected function checkout() {
		$gateway = Plugin::instance()->gateway();
		try {
			$gateway->process_card_token();
			wc_add_notice( __( 'Card was added successfully', 'paytrail-for-woocommerce' ), 'success' );
		} catch ( \Throwable $e ) {
			$gateway->log( 'Paytrail: could not add card: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'Could not add card details', 'paytrail-for-woocommerce' ), 'error' );
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Store the card and return the customer to the payment methods page.
	 *
	 * @return void
	 */
	protected function my_account() {
		$gateway = Plugin::instance()->gateway();
		try {
			$gateway->process_card_token();
			wc_add_notice( __( 'Card was added successfully', 'paytrail-for-woocommerce' ), 'success' );
		} catch ( \Throwable $e ) {
			$gateway->log( 'Paytrail: could not add card: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'Could not add card details', 'paytrail-for-woocommerce' ), 'error' );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
		exit;
	}

	/**
	 * Store the card, point the subscription at it, and return the customer to the subscription.
	 *
	 * @return void
	 */
	protected function change_payment_method() {
		$gateway      = Plugin::instance()->gateway();
		$subscription = Subscriptions::get_change_payment_subscription();

		try {
			$token = $gateway->process_card_token();
		} catch ( \Throwable $e ) {
			$gateway->log( 'Paytrail: could not add card: ' . $e->getMessage(), 'error' );
			$token = null;
		}

		// Back to the form on failure, so that the customer can retry or pick an existing card.
		$retry_url = $subscription
			? Subscriptions::get_change_payment_url( $subscription )
			: wc_get_account_endpoint_url( 'subscriptions' );

		if ( ! $token ) {
			wc_add_notice( __( 'Could not add card details', 'paytrail-for-woocommerce' ), 'error' );
			wp_safe_redirect( $retry_url );
			exit;
		}

		if ( ! $subscription ) {
			// The card is saved either way, so say so rather than implying the subscription changed.
			wc_add_notice(
				__( 'Card was added successfully, but the subscription payment method could not be changed. Please try again.', 'paytrail-for-woocommerce' ),
				'error'
			);
			wp_safe_redirect( wc_get_account_endpoint_url( 'subscriptions' ) );
			exit;
		}

		try {
			$subscription = Subscriptions::commit_payment_method_change(
				$subscription,
				$token,
				Subscriptions::is_update_all_requested()
			);
		} catch ( \Throwable $e ) {
			$gateway->log( 'Paytrail: change payment method failed: ' . $e->getMessage(), 'error' );
			wc_add_notice(
				__( 'Card was added successfully, but the subscription payment method could not be changed. Please try again.', 'paytrail-for-woocommerce' ),
				'error'
			);
			wp_safe_redirect( $retry_url );
			exit;
		}

		wc_add_notice( __( 'Payment method updated.', 'paytrail-for-woocommerce' ), 'success' );
		wp_safe_redirect( $subscription->get_view_order_url() );
		exit;
	}
}
