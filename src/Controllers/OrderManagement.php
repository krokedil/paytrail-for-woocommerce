<?php
/**
 * Paytrail for Woocommerce order management controller class
 *
 * @package Paytrail\WooCommercePaymentGateway\Controllers
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\SDK\Response\InvoiceActivationResponse;
use Paytrail\SDK\Response\InvoiceCancellationResponse;
use Paytrail\WooCommercePaymentGateway\Model;
use Paytrail\WooCommercePaymentGateway\Plugin;

/**
 * Class OrderManagement
 *
 * @package Paytrail\WooCommercePaymentGateway\Controllers
 */
class OrderManagement extends AbstractController {

	/**
	 * OrderManagement constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_handle_manual_invoice_request' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'maybe_cancel_pending_klarna_invoice' ) );
	}

	/**
	 * Handles manual invoice activation for supported providers.
	 *
	 * @param int $order_id The WC order ID.
	 * @return void
	 */
	public function maybe_handle_manual_invoice_request( $order_id ) {
		$order   = wc_get_order( $order_id );
		$gateway = Plugin::instance()->gateway();

		try {
			if ( ! $order ) {
				return;
			}

			if ( Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
				return;
			}

			$model            = new Model\MetaBox( $order );
			$payment_status   = $model->get_payment_status();
			$payment_provider = $payment_status ? strtolower( $payment_status->getProvider() ) : '';

			if ( empty( $payment_status ) || 'pending' !== $payment_status->getStatus() || ( ! str_contains( $payment_provider, 'walley' ) && ! str_contains( $payment_provider, 'klarna' ) ) ) {
				return;
			}

			$client   = $gateway->get_client();
			$response = $client->activateInvoice( $order->get_transaction_id() );

			$gateway->log(
				InvoiceActivationResponse::class . " Successfully activated invoice for order $order_id with transaction id {$order->get_transaction_id()}: " . wp_json_encode( $response )
			);
		} catch ( \Exception $e ) {
			$message = $e->getMessage();
			$gateway->log(
				"Failed to send manual invoice for order $order_id with transaction id {$order->get_transaction_id()}: $message",
				'error'
			);
			$order->set_status( 'on-hold', __( 'Failed to activate manual invoice: ' . $message, 'paytrail-for-woocommerce' ) );
			$order->save();
		}
	}

	/**
	 * Handles cancellation of pending Klarna invoices when an order is cancelled.
	 *
	 * @param int $order_id The WC order ID.
	 * @return void
	 */
	public function maybe_cancel_pending_klarna_invoice( $order_id ) {
		$order   = wc_get_order( $order_id );
		$gateway = Plugin::instance()->gateway();

		try {
			if ( ! $order ) {
				return;
			}

			if ( Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
				return;
			}

			$model = new Model\MetaBox( $order );

			$payment_status = $model->get_payment_status();
			if ( empty( $payment_status ) || 'pending' !== $payment_status->getStatus() ) {
				return;
			}

			$payment_provider = $payment_status ? strtolower( $payment_status->getProvider() ) : '';
			if ( ! str_contains( $payment_provider, 'klarna' ) ) {
				return;
			}

			$client   = $gateway->get_client();
			$response = $client->cancelInvoice( $order->get_transaction_id() );

			$gateway->log(
				InvoiceCancellationResponse::class . " Successfully cancelled invoice for order $order_id with transaction id {$order->get_transaction_id()}: " . wp_json_encode( $response )
			);
			$order->add_order_note( __( 'Cancelled Klarna invoice.', 'paytrail-for-woocommerce' ) );
			$order->save();
		} catch ( \Exception $e ) {
			$message = $e->getMessage();
			$gateway->log(
				"Failed to cancel invoice for order $order_id with transaction id {$order->get_transaction_id()}: $message",
				'error'
			);
			$order->set_status( 'on-hold', __( 'Failed to cancel invoice: ' . $message, 'paytrail-for-woocommerce' ) );
			$order->save();
		}
	}
}
