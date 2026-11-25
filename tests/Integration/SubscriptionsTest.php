<?php

declare(strict_types=1);

namespace Tests\Integration;

use Paytrail\WooCommercePaymentGateway\Plugin;
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
	 * Without a card there is nothing to charge, so the renewal is failed for
	 * WooCommerce Subscriptions to act on rather than sent to Paytrail.
	 */
	public function test_a_renewal_without_a_card_is_not_charged(): void {
		$renewal = $this->haveRenewalOrder();

		$this->assertFalse( $this->gateway()->scheduled_subscription_payment( 100.00, $renewal ) );
		$this->assertNoApiRequests();
		$this->assertSame( 'failed', $this->reload( $renewal )->get_status() );
		$this->assertOrderHasNote( $renewal, 'Cannot schedule subscription payment. No valid tokens found for order.' );
	}

	/** WooCommerce Subscriptions only retries or notifies when the renewal order is failed, not pending. */
	public function test_a_declined_renewal_fails_the_order(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willRejectWith( '/payments/token/mit/charge', 'card declined', 403 );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertEmpty( $this->reload( $renewal )->get_date_paid() );
		$this->assertSame( 'failed', $this->reload( $renewal )->get_status() );
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

	/**
	 * Paytrail forbids retrying some declines, and the list depends on the card's acquirer.
	 *
	 * @covers \Paytrail\WooCommercePaymentGateway\Controllers\SubscriptionRetries::maybe_skip_retry
	 * @dataProvider provide_declines_and_whether_they_may_be_retried
	 */
	public function test_a_decline_that_must_not_be_retried_gets_no_retry( string $card_type, string $code, bool $may_retry ): void {
		$renewal = $this->haveRenewalWithCard( 'card-token-1', $card_type );
		$rule    = new \stdClass();

		$this->willDeclineStoredCard( $code, 'Declined' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$applied = apply_filters( 'wcs_get_retry_rule', $rule, 0, $renewal->get_id() );

		$this->assertSame( $may_retry ? $rule : null, $applied );
	}

	/** @return array<string, array{string, string, bool}> */
	public static function provide_declines_and_whether_they_may_be_retried(): array {
		return [
			'a Nets code on a visa card'          => [ 'visa', '111', false ],
			'an Amex code on an amex card'        => [ 'amex', '181', false ],
			'a code both acquirers refuse'        => [ 'amex', '200', false ],
			'an Amex only code on a visa card'    => [ 'visa', '181', true ],
			'a Nets only code on an amex card'    => [ 'amex', '111', true ],
			'insufficient funds, which may clear' => [ 'visa', '116', true ],
		];
	}

	/** The merchant is told on the order, since no retry will follow. */
	public function test_a_decline_that_must_not_be_retried_says_so_on_the_order(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willDeclineStoredCard( '111', 'Card expired' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertOrderHasNote( $renewal, 'Acquirer response code: 111 (Card expired). This decline must not be retried, the customer needs to add a new card.' );
	}

	/**
	 * @covers \Paytrail\WooCommercePaymentGateway\Controllers\SubscriptionRetries::maybe_skip_retry
	 */
	public function test_an_order_paid_with_another_gateway_keeps_its_retry(): void {
		$renewal = $this->haveRenewalWithCard();
		$rule    = new \stdClass();

		$renewal->set_payment_method( 'other-gateway' );
		$renewal->update_meta_data( '_paytrail_acquirer_response_code', '111' );
		$renewal->save();

		$this->assertSame( $rule, apply_filters( 'wcs_get_retry_rule', $rule, 0, $renewal->get_id() ) );
	}

	/** An answer with no transaction id is not something the order can be paid against. */
	public function test_a_charge_without_a_transaction_id_does_not_pay_the_order(): void {
		$renewal = $this->haveRenewalWithCard();

		$this->willChargeStoredCard( '' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertEmpty( $this->reload( $renewal )->get_date_paid() );
		$this->assertSame( 'failed', $this->reload( $renewal )->get_status() );
	}

	/** Retry rules run when the order fails, so the decline code has to be stored by then. */
	public function test_the_decline_code_is_stored_before_the_order_fails(): void {
		$renewal = $this->haveRenewalWithCard();
		$seen    = null;

		add_action(
			'woocommerce_order_status_failed',
			static function ( $order_id ) use ( &$seen ) {
				$seen = wc_get_order( $order_id )->get_meta( '_paytrail_acquirer_response_code' );
			}
		);

		$this->willDeclineStoredCard( '116', 'Insufficient funds' );
		$this->gateway()->scheduled_subscription_payment( 100.00, $renewal );

		$this->assertSame( '116', $seen );
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
	private function haveRenewalWithCard( string $token = 'card-token-1', string $card_type = 'visa' ): \WC_Order {
		$renewal = $this->haveRenewalOrder();

		$renewal->add_payment_token( $this->haveCardToken( [ 'token' => $token, 'card_type' => $card_type ] ) );
		$renewal->set_payment_method( Plugin::GATEWAY_ID );
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
