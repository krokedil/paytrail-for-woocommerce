<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/**
 * Renewing a subscription, which Paytrail charges as a merchant-initiated transaction
 * against the card stored on the renewal order.
 *
 * @covers \Paytrail\WooCommercePaymentGateway\Gateway::scheduled_subscription_payment
 */
class SubscriptionsTest extends IntegrationTestCase {

	protected ?string $storeProfile = 'fi';

	/** The renewal is charged and the order paid without the shopper being present. */
	public function test_a_renewal_is_charged_against_the_stored_card(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willChargeStoredCard( 'paytrail-mit-1' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$reloaded = $this->reload( $renewal );

		$this->assertSame( 'paytrail-mit-1', $reloaded->get_transaction_id() );
		$this->assertNotEmpty( $reloaded->get_date_paid() );
		$this->assertOrderHasNote( $renewal, 'Transaction paytrail-mit-1 created by token payment using card.' );
	}

	/** The card token and the renewal's own total are what Paytrail is asked to charge. */
	public function test_the_charge_carries_the_card_and_the_renewal_amount(): void {
		$renewal = $this->haveRenewalWithCard( 'card-token-abc' );

		$this->willChargeStoredCard();
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$body = $this->apiRequestTo( '/payments/token/mit/charge' )['json'];

		$this->assertSame( 'card-token-abc', $body['token'] );
		$this->assertSame( 12550, $body['amount'] );
		$this->assertItemsAddUp( $body );
	}

	/** The reference is stored, so the renewal's callback can find the order again. */
	public function test_the_renewal_reference_is_stored_on_the_order(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willChargeStoredCard();
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$reference = $this->apiRequestTo( '/payments/token/mit/charge' )['json']['reference'];

		$this->assertSame( $reference, $this->reload( $renewal )->get_meta( '_checkout_reference' ) );
	}

	/**
	 * Without a card there is nothing to charge, so the renewal is left for
	 * WooCommerce Subscriptions to retry rather than sent to Paytrail.
	 */
	public function test_a_renewal_without_a_card_is_not_charged(): void {
		$renewal = $this->haveRenewalOrder();

		$this->assertFalse( $this->gateway()->scheduled_subscription_payment( 100.00, $renewal ) );
		$this->assertNoApiRequests();
		$this->assertOrderHasNote( $renewal, 'Cannot schedule subscription payment. No valid tokens found for order.' );
	}

	/** A declined renewal leaves the order unpaid, with a note a merchant can act on. */
	public function test_a_declined_renewal_leaves_the_order_unpaid(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willRejectWith( '/payments/token/mit/charge', 'card declined', 403 );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertEmpty( $this->reload( $renewal )->get_date_paid() );
		$this->assertOrderHasNote( $renewal, 'Failed to create token payment using card.' );
	}

	/** An answer with no transaction id is not something the order can be paid against. */
	public function test_a_charge_without_a_transaction_id_does_not_pay_the_order(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willChargeStoredCard( '' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertEmpty( $this->reload( $renewal )->get_date_paid() );
	}

	/** A renewal order for a subscription, with a saved card attached to it. */
	private function haveRenewalWithCard( string $token = 'card-token-1' ): \WC_Order {
		$renewal = $this->haveRenewalOrder();

		$renewal->add_payment_token( $this->haveCardToken( [ 'token' => $token ] ) );
		$renewal->save();

		return $renewal;
	}

	private function haveRenewalOrder(): \WC_Order {
		$subscription = $this->haveSubscription( [ 'billing' => $this->finnishAddress() ] );

		return $this->haveRenewalOrderFor(
			$subscription,
			[
				'items'   => [ [ $this->haveSimpleProduct( [ 'name' => 'Sauna membership', 'sku' => 'sauna-membership', 'price' => '100.00' ] ), 1 ] ],
				'billing' => $this->finnishAddress(),
			]
		);
	}
}
