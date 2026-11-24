<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/**
 * Invoices Paytrail holds open for a merchant to activate later, which Walley and
 * Klarna support and the plugin drives off the WooCommerce order status.
 *
 * @covers \Paytrail\WooCommercePaymentGateway\Controllers\OrderManagement
 */
class OrderManagementTest extends IntegrationTestCase {

	protected ?string $storeProfile = 'fi';

	/**
	 * Completing the order is the merchant saying the goods have shipped, which is when
	 * a manually activated invoice is sent to the shopper.
	 *
	 * @dataProvider provide_activatable_invoices
	 */
	public function test_a_pending_invoice_is_activated_when_the_order_completes( string $provider ): void {
		$order = $this->haveInvoiceOrder( $provider );

		$this->willReportPaymentStatus( 'paytrail-transaction-1', [ 'status' => 'pending', 'provider' => $provider ] );
		$this->willActivateInvoice();

		$order->update_status( 'completed' );

		$this->assertApiRequestCount( 1, '/activate-invoice' );
	}

	/** @return array<string, array{0: string}> */
	public function provide_activatable_invoices(): array {
		return [
			'Klarna' => [ 'klarna' ],
			'Walley' => [ 'walley' ],
			// Paytrail names the invoice products separately from the provider itself.
			'a Klarna invoice product' => [ 'klarna-invoice' ],
		];
	}

	/**
	 * Anything already settled, or paid with a provider that has no invoice to
	 * activate, must not be sent an activation.
	 *
	 * @dataProvider provide_orders_left_alone
	 */
	public function test_an_order_with_no_invoice_to_activate_is_left_alone( array $payment_status ): void {
		$order = $this->haveInvoiceOrder( $payment_status['provider'] );

		$this->willReportPaymentStatus( 'paytrail-transaction-1', $payment_status );

		$order->update_status( 'completed' );

		$this->assertApiRequestCount( 0, '/activate-invoice' );
	}

	/** @return array<string, array{0: array<string, string>}> */
	public function provide_orders_left_alone(): array {
		return [
			'an already settled payment' => [ [ 'status' => 'ok', 'provider' => 'klarna' ] ],
			'a bank payment'             => [ [ 'status' => 'pending', 'provider' => 'osuuspankki' ] ],
		];
	}

	/**
	 * An order that is not the plugin's business never reaches Paytrail, and neither
	 * does one with no transaction to act on.
	 *
	 * @dataProvider provide_orders_not_ours
	 */
	public function test_an_order_the_plugin_has_no_business_with_is_not_looked_up( array $overrides ): void {
		$order = $this->haveInvoiceOrder();

		foreach ( $overrides as $setter => $value ) {
			$order->{$setter}( $value );
		}
		$order->save();

		$order->update_status( 'completed' );

		$this->assertNoApiRequests();
	}

	/** @return array<string, array{0: array<string, string>}> */
	public function provide_orders_not_ours(): array {
		return [
			'paid with another gateway' => [ [ 'set_payment_method' => 'cod' ] ],
			'no transaction id'         => [ [ 'set_transaction_id' => '' ] ],
		];
	}

	/** A failed activation is a merchant problem, so the order waits on hold. */
	public function test_a_failed_activation_puts_the_order_on_hold(): void {
		$order = $this->haveInvoiceOrder();

		$this->willReportPaymentStatus( 'paytrail-transaction-1', [ 'status' => 'pending', 'provider' => 'klarna' ] );
		$this->willRejectWith( '/activate-invoice', 'Invoice already activated', 400 );

		$order->update_status( 'completed' );

		$this->assertSame( 'on-hold', $this->statusOf( $order ) );
	}

	/** Paytrail can refuse an activation in the body of a successful response. */
	public function test_an_activation_answered_with_an_error_puts_the_order_on_hold(): void {
		$order = $this->haveInvoiceOrder();

		$this->willReportPaymentStatus( 'paytrail-transaction-1', [ 'status' => 'pending', 'provider' => 'klarna' ] );
		$this->willActivateInvoice( 'error' );

		$order->update_status( 'completed' );

		$this->assertSame( 'on-hold', $this->statusOf( $order ) );
		$this->assertOrderHasNote( $order, 'Failed to activate manual invoice: Paytrail answered with status error' );
	}

	/** Activation is only tried on this one status change, so a lookup that fails must not hide the invoice. */
	public function test_a_failed_lookup_puts_an_order_with_a_held_invoice_on_hold(): void {
		$this->haveGatewaySettings( [ 'manual_invoice_activation' => 'yes' ] );
		$order = $this->haveInvoiceOrder();

		$this->willRejectWith( '/payments/paytrail-transaction-1', 'Service unavailable', 503 );

		$order->update_status( 'completed' );

		$this->assertSame( 'on-hold', $this->statusOf( $order ) );
		$this->assertOrderHasNote( $order, 'Failed to activate manual invoice' );
		$this->assertApiRequestCount( 0, '/activate-invoice' );
	}

	/**
	 * Without an invoice Paytrail could be holding, a failed lookup is no reason to stop the order.
	 *
	 * @dataProvider provide_orders_with_no_held_invoice
	 */
	public function test_a_failed_lookup_leaves_an_order_with_no_held_invoice_completed( string $setting, string $provider, ?string $requested ): void {
		$this->haveGatewaySettings( [ 'manual_invoice_activation' => $setting ] );
		$order = $this->haveInvoiceOrder( $provider );
		if ( null !== $requested ) {
			$order->update_meta_data( '_paytrail_manual_invoice_activation', $requested );
			$order->save();
		}

		$this->willRejectWith( '/payments/paytrail-transaction-1', 'Service unavailable', 503 );

		$order->update_status( 'completed' );

		$this->assertSame( 'completed', $this->statusOf( $order ) );
		$this->assertOrderHasNoNote( $order, 'Failed to activate manual invoice' );
	}

	/** @return array<string, array{0: string, 1: string, 2: ?string}> */
	public function provide_orders_with_no_held_invoice(): array {
		return [
			'a bank payment'                           => [ 'yes', 'osuuspankki', null ],
			'manual activation not asked for at payment' => [ 'yes', 'klarna', 'no' ],
			'an older order with the setting now off'  => [ 'no', 'klarna', null ],
		];
	}

	/** Cancelling the order withdraws the invoice, so the shopper is not billed. */
	public function test_cancelling_the_order_cancels_a_pending_klarna_invoice(): void {
		$order = $this->haveInvoiceOrder();

		$this->willReportPaymentStatus( 'paytrail-transaction-1', [ 'status' => 'pending', 'provider' => 'klarna' ] );
		$this->willCancelInvoice();

		$order->update_status( 'cancelled' );

		$this->assertApiRequestCount( 1, '/cancel-order' );
		$this->assertOrderHasNote( $order, 'Cancelled Klarna invoice.' );
	}

	/** Only Klarna invoices can be withdrawn, so a Walley one is left as it is. */
	public function test_cancelling_the_order_leaves_a_walley_invoice_alone(): void {
		$order = $this->haveInvoiceOrder( 'walley' );

		$this->willReportPaymentStatus( 'paytrail-transaction-1', [ 'status' => 'pending', 'provider' => 'walley' ] );

		$order->update_status( 'cancelled' );

		$this->assertApiRequestCount( 0, '/cancel-order' );
	}

	/** The invoice could still be waiting, so a lookup that fails on cancellation is recorded too. */
	public function test_a_failed_lookup_on_cancellation_is_noted_on_the_order(): void {
		$this->haveGatewaySettings( [ 'manual_invoice_activation' => 'yes' ] );
		$order = $this->haveInvoiceOrder();

		$this->willRejectWith( '/payments/paytrail-transaction-1', 'Service unavailable', 503 );

		$order->update_status( 'cancelled' );

		$this->assertOrderHasNote( $order, 'Failed to cancel Klarna invoice' );
		$this->assertApiRequestCount( 0, '/cancel-order' );
	}

	/** A cancellation Paytrail refuses is recorded, so a merchant can chase it. */
	public function test_a_failed_cancellation_is_noted_on_the_order(): void {
		$order = $this->haveInvoiceOrder();

		$this->willReportPaymentStatus( 'paytrail-transaction-1', [ 'status' => 'pending', 'provider' => 'klarna' ] );
		$this->willRejectWith( '/cancel-order', 'Order already captured', 400 );

		$order->update_status( 'cancelled' );

		$this->assertOrderHasNote( $order, 'Failed to cancel Klarna invoice: Order already captured' );
	}

	/** A paid Paytrail order sitting in processing, waiting to be shipped. */
	private function haveInvoiceOrder( string $provider = 'klarna' ): \WC_Order {
		return $this->havePaidPaytrailOrder(
			[
				'items'    => [ [ $this->haveSimpleProduct( [ 'name' => 'Sauna bucket', 'sku' => 'sauna-bucket', 'price' => '100.00' ] ), 1 ] ],
				'billing'  => $this->finnishAddress(),
				'paytrail' => [ 'provider' => $provider ],
			]
		);
	}
}
