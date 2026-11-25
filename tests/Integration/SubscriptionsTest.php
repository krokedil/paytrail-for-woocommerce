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

	/** The acquirer's reason is kept on the order, since some declines must never be retried. */
	public function test_a_declined_renewal_records_why_the_card_was_declined(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willDeclineStoredCard( '116', 'Insufficient funds' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$reloaded = $this->reload( $renewal );

		$this->assertSame( '116', $reloaded->get_meta( '_paytrail_acquirer_response_code' ) );
		$this->assertSame( 'Insufficient funds', $reloaded->get_meta( '_paytrail_acquirer_response_code_description' ) );
		$this->assertOrderHasNote( $renewal, 'Failed to create token payment using card. Acquirer response code: 116 (Insufficient funds).' );
	}

	/** Integrations get the decline code so they can decide whether a retry is allowed. */
	public function test_a_declined_renewal_tells_integrations_the_decline_code(): void {
		$renewal  = $this->haveRenewalWithCard();
		$received = [];

		add_action(
			'paytrail_mit_payment_failed',
			static function ( $order, $code, $description ) use ( &$received ) {
				$received[] = [ $order->get_id(), $code, $description ];
			},
			10,
			3
		);

		$this->willDeclineStoredCard( '116', 'Insufficient funds' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertSame( [ [ $renewal->get_id(), '116', 'Insufficient funds' ] ], $received );
	}

	/** A retry that fails for another reason must not keep showing the earlier decline code. */
	public function test_a_failure_without_a_decline_code_forgets_the_previous_one(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willDeclineStoredCard( '116', 'Insufficient funds' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->willFailToConnect( '/payments/token/mit/charge' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $this->reload( $renewal ) );

		$reloaded = $this->reload( $renewal );

		$this->assertSame( '', $reloaded->get_meta( '_paytrail_acquirer_response_code' ) );
		$this->assertSame( '', $reloaded->get_meta( '_paytrail_acquirer_response_code_description' ) );
	}

	/** A paid renewal has no decline to act on, so an earlier one is not left on the order. */
	public function test_a_retry_that_succeeds_forgets_the_earlier_decline_code(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willDeclineStoredCard( '116', 'Insufficient funds' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->willChargeStoredCard( 'paytrail-mit-1' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $this->reload( $renewal ) );

		$reloaded = $this->reload( $renewal );

		$this->assertNotEmpty( $reloaded->get_date_paid() );
		$this->assertSame( '', $reloaded->get_meta( '_paytrail_acquirer_response_code' ) );
	}

	/** An answer with no transaction id is not something the order can be paid against. */
	public function test_a_charge_without_a_transaction_id_does_not_pay_the_order(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willChargeStoredCard( '' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertEmpty( $this->reload( $renewal )->get_date_paid() );
	}

	/**
	 * A card added while changing the subscription's payment method replaces the one it
	 * was paying with. A renewal charges the first card it finds, so a card added next to
	 * the old one would never be used.
	 *
	 * @covers \Paytrail\WooCommercePaymentGateway\Gateway::set_subscription_card
	 */
	public function test_a_new_card_replaces_the_one_the_subscription_was_paying_with(): void {
		$subscription = $this->haveSubscription();
		$subscription->add_payment_token( $this->haveCardToken( [ 'token' => 'the-old-card' ] ) );
		$subscription->save();

		$new_card = $this->haveCardToken( [ 'token' => 'the-new-card' ] );

		$this->assertTrue( $this->gateway()->set_subscription_card( $subscription->get_id(), $new_card->get_id() ) );
		$this->assertSame( [ $new_card->get_id() ], $this->reload( $subscription )->get_payment_tokens() );
	}

	/**
	 * The subscription to re-point is named in the URL Paytrail returns to, so a card may
	 * only be moved onto a subscription belonging to the shopper who saved it. Saying so
	 * is what stops the shopper being told their subscription was updated when it was not.
	 *
	 * @covers \Paytrail\WooCommercePaymentGateway\Gateway::set_subscription_card
	 */
	public function test_a_card_is_not_moved_onto_another_shoppers_subscription(): void {
		$subscription = $this->haveSubscription();
		$subscription->set_customer_id( 1 );
		$old_card = $this->haveCardToken( [ 'token' => 'the-old-card', 'user_id' => 1 ] );
		$subscription->add_payment_token( $old_card );
		$subscription->save();

		$someone_elses_card = $this->haveCardToken( [ 'token' => 'the-new-card', 'user_id' => 2 ] );

		$this->assertFalse( $this->gateway()->set_subscription_card( $subscription->get_id(), $someone_elses_card->get_id() ) );
		$this->assertSame( [ $old_card->get_id() ], $this->reload( $subscription )->get_payment_tokens() );
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
