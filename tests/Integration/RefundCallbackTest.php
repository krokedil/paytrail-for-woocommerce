<?php

declare(strict_types=1);

namespace Tests\Integration;

use Paytrail\SDK\PaytrailClient;
use Paytrail\SDK\Exception\HmacException;
use Paytrail\SDK\Util\Signature;
use Tests\Support\IntegrationTestCase;

/**
 * Paytrail's refund callback, which only counts once it agrees with the signed parameters.
 *
 * @covers \Paytrail\WooCommercePaymentGateway\Gateway::handle_refund_response
 */
class RefundCallbackTest extends IntegrationTestCase {

	protected ?string $storeProfile = 'fi';

	public function test_the_refund_records_the_transaction_paytrail_gave_it(): void {
		$refund = $this->havePendingRefund();

		$this->assertSame( 'paytrail-refund-1', $refund->get_meta( '_checkout_refund_transaction_id' ) );
	}

	public function test_a_successful_refund_callback_settles_the_refund(): void {
		$refund = $this->havePendingRefund();

		$this->receiveRefundCallback( $refund, 'success', [ 'checkout-status' => 'ok' ] );

		$refund = $this->reload( $refund );
		$this->assertSame( '25.5', $refund->get_amount() );
		$this->assertEmpty( $refund->get_meta( '_checkout_refund_processing' ) );
		$this->assertOrderHasNote( $this->orderOf( $refund ), 'Refund process completed.' );
	}

	public function test_a_failed_refund_callback_removes_the_refund(): void {
		$refund = $this->havePendingRefund();
		$order  = $this->orderOf( $refund );

		$this->receiveRefundCallback( $refund, 'cancel', [ 'checkout-status' => 'fail' ] );

		$this->assertSame( [], $this->reload( $order )->get_refunds() );
		$this->assertOrderHasNote( $order, 'Refund was cancelled by the payment provider.' );
	}

	/** The refund created before its transaction id was stored is matched on the order's transaction. */
	public function test_an_older_refund_is_matched_on_the_order_transaction(): void {
		$refund = $this->havePendingRefund();
		$refund->delete_meta_data( '_checkout_refund_transaction_id' );
		$refund->save();

		$this->receiveRefundCallback( $refund, 'success', [ 'checkout-transaction-id' => 'paytrail-transaction-1' ] );

		$this->assertEmpty( $this->reload( $refund )->get_meta( '_checkout_refund_processing' ) );
	}

	/**
	 * @dataProvider replayedCallbacks
	 */
	public function test_a_replayed_signature_does_not_touch_the_refund( string $refund_callback, array $signed ): void {
		$refund = $this->havePendingRefund();

		$this->receiveRefundCallback( $refund, $refund_callback, $signed );

		$refund = $this->reload( $refund );
		$this->assertNotFalse( $refund, 'The refund should not have been deleted' );
		$this->assertSame( '1', $refund->get_meta( '_checkout_refund_processing' ) );
		$this->assertSame( '0', $refund->get_amount() );
	}

	/** @return array<string, array{string, array<string, string>}> */
	public function replayedCallbacks(): array {
		return [
			'from another payment, as a success' => [ 'success', [ 'checkout-transaction-id' => 'another-customers-payment' ] ],
			'from another payment, as a cancel'  => [ 'cancel', [ 'checkout-transaction-id' => 'another-customers-payment', 'checkout-status' => 'fail' ] ],
			'a signed success sent as a cancel'  => [ 'cancel', [ 'checkout-status' => 'ok' ] ],
			'without a transaction id'           => [ 'success', [ 'checkout-transaction-id' => '' ] ],
			'still pending at Paytrail'          => [ 'success', [ 'checkout-status' => 'pending' ] ],
		];
	}

	public function test_the_order_id_in_the_callback_is_ignored(): void {
		$refund      = $this->havePendingRefund();
		$other_order = $this->havePaidPaytrailOrder();

		$this->receiveRefundCallback( $refund, 'success', [], [ 'order_id' => (string) $other_order->get_id() ] );

		$this->assertOrderHasNote( $this->orderOf( $refund ), 'Refund process completed.' );
		$this->assertOrderHasNoNote( $this->reload( $other_order ), 'Refund process completed.' );
	}

	public function test_a_repeated_callback_changes_nothing(): void {
		$refund = $this->havePendingRefund();
		$this->receiveRefundCallback( $refund, 'success' );

		$this->receiveRefundCallback( $refund, 'cancel', [ 'checkout-status' => 'fail' ] );

		$this->assertSame( '25.5', $this->reload( $refund )->get_amount() );
	}

	public function test_a_callback_with_a_bad_signature_is_refused(): void {
		$refund = $this->havePendingRefund();
		$params = $this->signedRefundCallback( $refund, 'success' );

		$params['signature'] = str_repeat( '0', 64 );

		$this->expectException( HmacException::class );
		$this->gateway()->process_scheduled_callback( $params );
	}

	/** A 25.50 refund on a paid 125.50 order, waiting for Paytrail's callback. */
	private function havePendingRefund(): \WC_Order_Refund {
		$order = $this->havePaidPaytrailOrder(
			[
				'items'   => [ [ $this->haveSimpleProduct( [ 'name' => 'Sauna bucket', 'sku' => 'sauna-bucket', 'price' => '100.00' ] ), 1 ] ],
				'billing' => $this->finnishAddress(),
			]
		);

		$this->willRefund();

		return $this->reload( $this->haveGatewayRefund( $order, 25.50 ) );
	}

	/** Runs a refund callback the way the queued callback processing does. */
	private function receiveRefundCallback( \WC_Order_Refund $refund, string $refund_callback, array $signed = [], array $unsigned = [] ): void {
		$this->gateway()->process_scheduled_callback( $this->signedRefundCallback( $refund, $refund_callback, $signed, $unsigned ) );
	}

	/** The query Paytrail calls a refund callback URL with, signed with the store's secret. */
	private function signedRefundCallback( \WC_Order_Refund $refund, string $refund_callback, array $signed = [], array $unsigned = [] ): array {
		$checkout = array_merge(
			[
				'checkout-account'        => '375917',
				'checkout-algorithm'      => 'sha256',
				'checkout-amount'         => '2550',
				'checkout-stamp'          => 'refund-stamp',
				'checkout-reference'      => (string) $refund->get_parent_id(),
				'checkout-transaction-id' => 'paytrail-refund-1',
				'checkout-status'         => 'ok',
				'checkout-provider'       => 'osuuspankki',
			],
			$signed
		);

		$checkout['signature'] = Signature::calculateHmac( $checkout, '', $this->secretKey() );

		return array_merge(
			$checkout,
			[
				'refund_callback'  => $refund_callback,
				'refund_unique_id' => (string) $refund->get_meta( '_checkout_refund_unique_id' ),
				'order_id'         => (string) $refund->get_parent_id(),
			],
			$unsigned
		);
	}

	private function secretKey(): string {
		$secret_key = new \ReflectionProperty( PaytrailClient::class, 'secretKey' );
		$secret_key->setAccessible( true );

		return (string) $secret_key->getValue( $this->gateway()->get_client() );
	}

	private function orderOf( \WC_Order_Refund $refund ): \WC_Order {
		return wc_get_order( $refund->get_parent_id() );
	}
}
