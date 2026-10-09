<?php

declare(strict_types=1);

namespace Tests\Integration;

use Paytrail\WooCommercePaymentGateway\Controllers\SubscriptionToken;
use Tests\Support\IntegrationTestCase;

/**
 * The recurring card token shown on the admin subscription page, and the merchant
 * pointing renewals at another of the customer's saved cards from there.
 *
 * Saving from the edit form reads the POSTed field with filter_input(), so the change
 * itself is pinned through change_recurring_token().
 *
 * @covers \Paytrail\WooCommercePaymentGateway\Controllers\SubscriptionToken
 */
class SubscriptionTokenTest extends IntegrationTestCase {

	protected ?string $storeProfile = 'fi';

	/** The merchant sees which card renewals will be charged with. */
	public function test_the_recurring_token_is_shown_on_the_subscription(): void {
		$subscription = $this->havePaytrailSubscription();
		$subscription->add_payment_token( $this->haveCardToken( [ 'token' => 'the-recurring-card' ] ) );
		$subscription->save();

		$html = $this->render( $subscription );

		$this->assertStringContainsString( 'Paytrail recurring token', $html );
		$this->assertStringContainsString( 'the-recurring-card', $html );
	}

	/** A subscription paid with another gateway has no Paytrail token to show. */
	public function test_nothing_is_shown_for_another_gateways_subscription(): void {
		$subscription = $this->haveSubscription();
		$subscription->set_payment_method( 'bacs' );
		$subscription->save();

		$this->assertSame( '', $this->render( $subscription ) );
	}

	/** Picking another saved card makes renewals charge that card instead. */
	public function test_the_merchant_can_switch_renewals_to_another_saved_card(): void {
		$subscription = $this->havePaytrailSubscription();
		$subscription->add_payment_token( $this->haveCardToken( [ 'token' => 'the-old-card' ] ) );
		$subscription->save();

		$new_card = $this->haveCardToken( [ 'token' => 'the-new-card' ] );

		$this->assertTrue( ( new SubscriptionToken() )->change_recurring_token( $subscription, $new_card->get_id() ) );
		$this->assertSame( [ $new_card->get_id() ], $this->reload( $subscription )->get_payment_tokens() );
		$this->assertOrderHasNote( $subscription, 'Paytrail recurring token changed by admin' );
	}

	/** A card saved by another customer is never attached to the subscription. */
	public function test_another_customers_card_is_not_attached(): void {
		$subscription = $this->havePaytrailSubscription();
		$subscription->set_customer_id( 1 );
		$old_card = $this->haveCardToken( [ 'token' => 'the-old-card', 'user_id' => 1 ] );
		$subscription->add_payment_token( $old_card );
		$subscription->save();

		$someone_elses_card = $this->haveCardToken( [ 'token' => 'the-new-card', 'user_id' => 2 ] );

		$this->assertFalse( ( new SubscriptionToken() )->change_recurring_token( $subscription, $someone_elses_card->get_id() ) );
		$this->assertSame( [ $old_card->get_id() ], $this->reload( $subscription )->get_payment_tokens() );
	}

	private function havePaytrailSubscription(): \WC_Order {
		$subscription = $this->haveSubscription();
		$subscription->set_payment_method( 'paytrail' );
		$subscription->save();

		return $this->reload( $subscription );
	}

	private function render( \WC_Order $subscription ): string {
		ob_start();
		( new SubscriptionToken() )->show_recurring_token( $this->reload( $subscription ) );

		return (string) ob_get_clean();
	}
}
