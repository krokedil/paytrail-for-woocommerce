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
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$gateway        = Plugin::instance()->gateway();
		$transaction_id = $order->get_transaction_id();
		if ( empty( $transaction_id ) ) {
			$gateway->log(
				"Cannot activate manual invoice for order $order_id: missing transaction ID",
				'error'
			);
			return;
		}

		if ( 'yes' === $order->get_meta( '_paytrail_klarna_authorization_hold' ) ) {
			try {
				$gateway->commit_klarna_authorization_hold( $order );
			} catch ( \Exception $e ) {
				$sanitized_message = sanitize_text_field( $e->getMessage() );
				$gateway->log( "Failed to capture Klarna authorization hold for order $order_id with transaction id $transaction_id: $sanitized_message", 'error' );
				$order->set_status(
					'on-hold',
					sprintf(
						// Translators: %s is the error message.
						__( 'Failed to capture Klarna authorization hold: %s', 'paytrail-for-woocommerce' ),
						$sanitized_message
					)
				);
				$order->save();
			}
			return;
		}

		if ( ! $this->may_hold_invoice( $order, array( 'walley', 'klarna' ) ) ) {
			return;
		}

		try {
			$model = new Model\MetaBox( $order );
			// A failed lookup must surface when an invoice may be waiting, or it is never activated.
			$payment_status   = $model->get_payment_status( $this->expects_manual_invoice( $order ) );
			$payment_provider = $payment_status ? (string) $payment_status->getProvider() : '';

			if ( empty( $payment_status ) || 'pending' !== $payment_status->getStatus() || ! $this->is_invoice_provider( $payment_provider, array( 'walley', 'klarna' ) ) ) {
				return;
			}

			$client = $gateway->get_client();

			$response = $client->activateInvoice( $transaction_id );
			$this->ensure_ok_status( $response->getStatus() );

			$gateway->log(
				InvoiceActivationResponse::class . " Successfully activated invoice for order $order_id with transaction id $transaction_id: " . wp_json_encode( $response )
			);
		} catch ( \Exception $e ) {
			$sanitized_message = sanitize_text_field( $e->getMessage() );
			$gateway->log(
				"Failed to send manual invoice for order $order_id with transaction id $transaction_id: $sanitized_message",
				'error'
			);
			$order->set_status(
				'on-hold',
				sprintf(
					// Translators: %s is the error message.
					__( 'Failed to activate manual invoice: %s', 'paytrail-for-woocommerce' ),
					$sanitized_message
				)
			);
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
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$gateway        = Plugin::instance()->gateway();
		$transaction_id = $order->get_transaction_id();
		if ( empty( $transaction_id ) ) {
			$gateway->log(
				"Cannot cancel pending Klarna invoice for order $order_id: missing transaction ID",
				'error'
			);
			return;
		}

		if ( 'yes' === $order->get_meta( '_paytrail_klarna_authorization_hold' ) ) {
			try {
				$gateway->revert_klarna_authorization_hold( $order );
			} catch ( \Exception $e ) {
				$sanitized_message = sanitize_text_field( $e->getMessage() );
				$gateway->log( "Failed to void Klarna authorization hold for order $order_id with transaction id $transaction_id: $sanitized_message", 'error' );
				$order->add_order_note(
					sprintf(
						// Translators: %s is the error message.
						__( 'Failed to void Klarna authorization hold: %s', 'paytrail-for-woocommerce' ),
						$sanitized_message
					)
				);
			}
			return;
		}

		if ( ! $this->may_hold_invoice( $order, array( 'klarna' ) ) ) {
			return;
		}

		try {
			$model = new Model\MetaBox( $order );
			// A failed lookup must surface when an invoice may be waiting, or it is never cancelled.
			$payment_status = $model->get_payment_status( $this->expects_manual_invoice( $order ) );
			if ( empty( $payment_status ) || 'pending' !== $payment_status->getStatus() ) {
				return;
			}

			if ( ! $this->is_invoice_provider( (string) $payment_status->getProvider(), array( 'klarna' ) ) ) {
				return;
			}

			$client = $gateway->get_client();

			$response = $client->cancelInvoice( $transaction_id );
			$this->ensure_ok_status( $response->getStatus(), $response->getMessage() );

			$gateway->log(
				InvoiceCancellationResponse::class . " Successfully cancelled invoice for order $order_id with transaction id $transaction_id: " . wp_json_encode( $response )
			);
			$order->add_order_note( __( 'Cancelled Klarna invoice.', 'paytrail-for-woocommerce' ) );
		} catch ( \Exception $e ) {
			$sanitized_message = sanitize_text_field( $e->getMessage() );
			$gateway->log(
				"Failed to cancel invoice for order $order_id with transaction id $transaction_id: $sanitized_message",
				'error'
			);
			$order->add_order_note(
				sprintf(
					// Translators: %s is the error message.
					__( 'Failed to cancel Klarna invoice: %s', 'paytrail-for-woocommerce' ),
					$sanitized_message
				)
			);
		}
	}

	/**
	 * Paytrail can refuse an invoice action in the body of a successful response, which must be handled like a failed request.
	 *
	 * @param string|null $status  The status Paytrail answered with.
	 * @param string|null $message The message Paytrail answered with, if any.
	 * @return void
	 * @throws \Exception When the status is not "ok".
	 */
	private function ensure_ok_status( $status, $message = null ) {
		if ( 'ok' === $status ) {
			return;
		}

		$reason = empty( $message ) ? 'Paytrail answered with status ' . $status : $message;
		throw new \Exception( esc_html( $reason ) );
	}

	/**
	 * Whether Paytrail may be holding an invoice for the order, judged from what was stored when the payment was made.
	 *
	 * @param \WC_Order $order     The WC order.
	 * @param string[]  $providers The providers that hold invoices for this action.
	 * @return bool
	 */
	private function may_hold_invoice( $order, $providers ) {
		if ( 'no' === $order->get_meta( '_paytrail_manual_invoice_activation' ) ) {
			return false;
		}

		$stored_provider = (string) $order->get_meta( '_checkout_payment_provider' );
		if ( '' === $stored_provider || Plugin::GATEWAY_ID === $stored_provider ) {
			return true;
		}

		return $this->is_invoice_provider( $stored_provider, $providers );
	}

	/**
	 * Whether manual invoice activation was requested for the order's payment.
	 *
	 * @param \WC_Order $order The WC order.
	 * @return bool
	 */
	private function expects_manual_invoice( $order ) {
		$requested = $order->get_meta( '_paytrail_manual_invoice_activation' );
		if ( '' === $requested ) {
			$requested = Plugin::instance()->gateway()->get_option( 'manual_invoice_activation', 'no' );
		}

		return wc_string_to_bool( $requested );
	}

	/**
	 * Whether a Paytrail provider id is one of the given invoice providers.
	 *
	 * @param string   $provider  The Paytrail provider id.
	 * @param string[] $providers The invoice providers to match.
	 * @return bool
	 */
	private function is_invoice_provider( $provider, $providers ) {
		$provider = strtolower( $provider );
		foreach ( $providers as $invoice_provider ) {
			if ( false !== strpos( $provider, $invoice_provider ) ) {
				return true;
			}
		}

		return false;
	}
}
