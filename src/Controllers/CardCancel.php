<?php
/**
 * Paytrail for Woocommerce payment Card cancel controller class
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\WooCommercePaymentGateway\Subscriptions;

/**
 * Handles the redirect back from a cancelled card addition.
 */
class CardCancel extends AbstractController {

	/**
	 * Return the customer to the checkout.
	 *
	 * @return void
	 */
	protected function checkout() {
		wc_add_notice( __( 'Could not add card details', 'paytrail-for-woocommerce' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Return the customer to the payment methods page.
	 *
	 * @return void
	 */
	protected function my_account() {
		wc_add_notice( __( 'Could not add card details', 'paytrail-for-woocommerce' ), 'error' );
		wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
		exit;
	}

	/**
	 * Return the customer to the change payment method form.
	 *
	 * @return void
	 */
	protected function change_payment_method() {
		$subscription = Subscriptions::get_change_payment_subscription();

		wc_add_notice( __( 'Could not add card details', 'paytrail-for-woocommerce' ), 'error' );
		wp_safe_redirect(
			$subscription
				? Subscriptions::get_change_payment_url( $subscription )
				: wc_get_account_endpoint_url( 'subscriptions' )
		);
		exit;
	}
}
