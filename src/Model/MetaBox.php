<?php
/**
 * Paytrail for Woocommerce Meta box model class
 *
 * @package Paytrail\WooCommercePaymentGateway\Model
 */

namespace Paytrail\WooCommercePaymentGateway\Model;

use Paytrail\SDK\Request\PaymentStatusRequest;
use Paytrail\WooCommercePaymentGateway\Gateway;

/**
 * Paytrail for Woocommerce Meta box model class
 */
class MetaBox {

	/**
	 * The order the metabox retrieves its data from.
	 *
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * The Paytrail order status.
	 *
	 * @var array
	 */
	private $status;

	/**
	 * The Paytrail order amount.
	 *
	 * @var string
	 */
	private $amount;

	/**
	 * The WC order currency.
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * The Paytrail transaction ID.
	 *
	 * @var string
	 */
	private $transaction_id;

	/**
	 * Constructor.
	 *
	 * @param \WC_Order $order The WC order.
	 */
	public function __construct( $order ) {
		$this->order = $order;
	}

	/**
	 * Retrieves the Paytrail order status.
	 *
	 * @return array|null The Paytrail order status.
	 */
	public function get_status() {
		if ( empty( $this->status ) ) {
			$gateway = new Gateway();

			$request = new PaymentStatusRequest();
			$request->setTransactionId( $this->order->get_transaction_id() );

			$client = $gateway->get_client();
			try {
				$response     = $client->getPaymentStatus( $request );
				$this->status = $response->getTransactionId() === $this->order->get_transaction_id() ? $response->getStatus() : null;
			} catch ( \Exception $e ) {
				$this->status = null;
			}
		}

		return $this->status;
	}

	/**
	 * Retrieves the Paytrail order amount.
	 *
	 * @return string The Paytrail order amount.
	 */
	public function get_amount() {
		if ( empty( $this->amount ) ) {
			$gateway = new Gateway();

			$request = new PaymentStatusRequest();
			$request->setTransactionId( $this->order->get_transaction_id() );

			$client = $gateway->get_client();
			try {
				$response     = $client->getPaymentStatus( $request );
				$this->amount = $response->getTransactionId() === $this->order->get_transaction_id() ? $response->getAmount() : null;
			} catch ( \Exception $e ) {
				$this->amount = null;
			}
		}

		return $this->amount;
	}

	/**
	 * Retrieves the WC order currency.
	 *
	 * @return string The WC order currency.
	 */
	public function get_currency() {
		if ( empty( $this->currency ) ) {
			$this->currency = $this->order->get_currency();
		}

		return $this->currency;
	}

	/**
	 * Retrieves the Paytrail transaction ID.
	 *
	 * @return string The Paytrail transaction ID.
	 */
	public function get_transaction_id() {
		// The Paytrail transaction ID is stored as the order's transaction ID. We don't need to fetch it from Paytrail.
		if ( empty( $this->transaction_id ) ) {
			$this->transaction_id = $this->order->get_transaction_id();
		}

		return $this->transaction_id;
	}
}
