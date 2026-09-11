<?php
/**
 * Paytrail for WooCommerce WooCommerce Subscriptions integration
 */

namespace Paytrail\WooCommercePaymentGateway;

/**
 * Handles the integration of Paytrail card tokens with WooCommerce Subscriptions.
 */
class Subscriptions {

	/**
	 * Meta key holding the Paytrail card token value, exposed to Subscriptions.
	 */
	const TOKEN_META_KEY = '_paytrail_card_token';

	/**
	 * Query arg carrying the "apply to all my subscriptions" choice across the add card redirect.
	 */
	const UPDATE_ALL_QUERY_ARG = 'paytrail-update-all';

	/**
	 * Object constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . Plugin::GATEWAY_ID, array( $this, 'validate_subscription_payment_meta' ), 10, 2 );

		add_action(
			'woocommerce_subscription_payment_meta_input_' . Plugin::GATEWAY_ID . '_post_meta_' . self::TOKEN_META_KEY,
			array( $this, 'render_admin_token_select' ),
			10,
			4
		);

		add_action( 'woocommerce_subscription_payment_method_updated_to_' . Plugin::GATEWAY_ID, array( $this, 'reconcile' ), 10, 1 );
		add_action( 'woocommerce_subscription_token_changed', array( $this, 'reconcile' ), 10, 1 );
		// The admin edit screen writes the meta directly and never fires the payment method hooks.
		add_action( 'woocommerce_process_shop_subscription_meta', array( $this, 'reconcile_by_id' ), 20, 1 );
	}

	/**
	 * Whether Subscriptions is active and its change payment method APIs are available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wcs_get_subscription' ) && class_exists( '\WC_Subscriptions_Change_Payment_Gateway' );
	}

	/**
	 * Whether the current request is a subscription change payment method request.
	 *
	 * @return bool
	 */
	public static function is_change_payment_method_request() {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( ! empty( \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) ) {
			return true;
		}

		return (bool) self::get_requested_subscription_id();
	}

	/**
	 * Get the subscription ID from the change_payment_method query arg.
	 *
	 * @return int The subscription ID, or 0 when the arg is absent.
	 */
	private static function get_requested_subscription_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Not a state change. The ID only selects which subscription to look up; access is authorized in get_change_payment_subscription().
		return absint( filter_input( INPUT_GET, 'change_payment_method', FILTER_SANITIZE_NUMBER_INT ) );
	}

	/**
	 * Whether the customer asked for the new card to be used on all of their subscriptions.
	 *
	 * @return bool
	 */
	public static function is_update_all_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Not a state change. Only ever widens an update the customer is already authorized to make, and every target subscription is re-checked by Subscriptions.
		return (bool) absint( filter_input( INPUT_GET, self::UPDATE_ALL_QUERY_ARG, FILTER_SANITIZE_NUMBER_INT ) );
	}

	/**
	 * Get the subscription the current user is allowed to change the payment method of.
	 *
	 * @param int|null $subscription_id The subscription to load, or null to read it from the request.
	 * @return \WC_Subscription|null The subscription, or null when it cannot be changed by this user.
	 */
	public static function get_change_payment_subscription( $subscription_id = null ) {
		if ( ! self::is_available() ) {
			return null;
		}

		if ( null === $subscription_id ) {
			$subscription_id = self::get_requested_subscription_id();
		}

		$subscription_id = absint( $subscription_id );

		if ( ! $subscription_id ) {
			return null;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce Subscriptions, which is the only thing that grants this flow.
		if ( ! current_user_can( 'edit_shop_subscription_payment_method', $subscription->get_id() ) ) {
			return null;
		}

		if ( ! $subscription->can_be_updated_to( 'new-payment-method' ) ) {
			return null;
		}

		return $subscription;
	}

	/**
	 * Build the URL of a subscription's change payment method form.
	 *
	 * @param \WC_Subscription $subscription The subscription to link to.
	 * @return string
	 */
	public static function get_change_payment_url( $subscription ) {
		return wp_nonce_url(
			add_query_arg(
				array( 'change_payment_method' => $subscription->get_id() ),
				$subscription->get_checkout_payment_url()
			)
		);
	}

	/**
	 * Whether the current request should copy the paid card token onto its subscriptions.
	 *
	 * @return bool
	 */
	public static function is_automatic_subscription_context() {
		return function_exists( 'wcs_get_subscriptions_for_order' );
	}

	/**
	 * Store a card token as the one to charge for a subscription's renewals.
	 *
	 * @param \WC_Subscription  $subscription The subscription to store the token on.
	 * @param \WC_Payment_Token $token        The token to store.
	 * @return void
	 */
	public static function set_subscription_token( $subscription, $token ) {
		$subscription->update_meta_data( self::TOKEN_META_KEY, $token->get_token() );
		$subscription->save();

		$subscription->get_data_store()->update_payment_token_ids( $subscription, array( $token->get_id() ) );
	}

	/**
	 * Get the card token to charge for a subscription's renewals.
	 *
	 * @param \WC_Subscription $subscription The subscription to read.
	 * @param bool             $backfill     Whether to persist a re-derived token back to the subscription.
	 * @return \WC_Payment_Token|null The token, or null when none can be resolved.
	 */
	public static function get_subscription_token( $subscription, $backfill = true ) {
		$token_value = (string) $subscription->get_meta( self::TOKEN_META_KEY );

		if ( '' !== $token_value ) {
			return self::find_customer_token_by_value( $subscription->get_customer_id(), $token_value );
		}

		$token = self::get_newest_valid_order_token( $subscription );

		if ( $token && $backfill ) {
			self::set_subscription_token( $subscription, $token );
		}

		return $token;
	}

	/**
	 * Find one of a customer's saved Paytrail cards by its token value.
	 *
	 * @param int    $customer_id The customer to search.
	 * @param string $token_value The Paytrail token value to match.
	 * @return \WC_Payment_Token|null
	 */
	public static function find_customer_token_by_value( $customer_id, $token_value ) {
		if ( ! $customer_id || '' === $token_value ) {
			return null;
		}

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $customer_id, Plugin::GATEWAY_ID ) as $token ) {
			if ( $token->get_token() === $token_value ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Get the newest usable Paytrail token attached to an order or subscription.
	 *
	 * @param \WC_Abstract_Order $order The order or subscription to read.
	 * @return \WC_Payment_Token|null
	 */
	private static function get_newest_valid_order_token( $order ) {
		$newest = null;

		foreach ( \WC_Payment_Tokens::get_order_tokens( $order->get_id() ) as $token ) {
			if ( Plugin::GATEWAY_ID !== $token->get_gateway_id() || ! $token->validate() ) {
				continue;
			}

			if ( null === $newest || $token->get_id() > $newest->get_id() ) {
				$newest = $token;
			}
		}

		return $newest;
	}

	/**
	 * Get the card token to charge for a renewal order.
	 *
	 * @param \WC_Order $order The renewal order about to be charged.
	 * @return \WC_Payment_Token|null
	 */
	public static function resolve_renewal_token( $order ) {
		$subscriptions = function_exists( 'wcs_get_subscriptions_for_renewal_order' )
			? wcs_get_subscriptions_for_renewal_order( $order )
			: array();

		foreach ( $subscriptions as $subscription ) {
			$token = self::get_subscription_token( $subscription );

			if ( $token ) {
				return $token->validate() ? $token : null;
			}

			if ( '' !== (string) $subscription->get_meta( self::TOKEN_META_KEY ) ) {
				return null;
			}
		}

		// No subscription has nominated a card, so fall back to whatever the order itself carries.
		return self::get_newest_valid_order_token( $order );
	}

	/**
	 * Point a subscription at a new card and record the change.
	 *
	 * @param \WC_Subscription  $subscription The subscription to update.
	 * @param \WC_Payment_Token $token        The card to charge from now on.
	 * @param bool              $update_all   Whether to apply the card to the customer's other
	 *                                        subscriptions too. Leave false when the Subscriptions
	 *                                        form handler is driving, as it does this itself.
	 * @return \WC_Subscription The saved subscription.
	 */
	public static function commit_payment_method_change( $subscription, $token, $update_all = false ) {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateway  = isset( $gateways[ Plugin::GATEWAY_ID ] ) ? $gateways[ Plugin::GATEWAY_ID ] : null;

		$requires_manual_renewal = ( $gateway && function_exists( 'wcs_should_require_manual_renewal' ) )
			? wcs_should_require_manual_renewal( $subscription, $gateway )
			: $subscription->get_requires_manual_renewal();

		self::set_subscription_token( $subscription, $token );

		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $subscription, Plugin::GATEWAY_ID );

		// update_payment_method() saved the subscription, so re-read it before writing again.
		$subscription = wcs_get_subscription( $subscription->get_id() );

		$subscription->set_requires_manual_renewal( $requires_manual_renewal );
		$subscription->save();

		$subscription->add_order_note(
			sprintf(
				/* translators: %s: card display name, e.g. "Visa ending in 0313" */
				__( 'Paytrail card for renewal payments changed to %s.', 'paytrail-for-woocommerce' ),
				$token->get_display_name()
			)
		);

		if ( $update_all
			&& $gateway
			&& \WC_Subscriptions_Change_Payment_Gateway::can_update_all_subscription_payment_methods( $gateway, $subscription )
		) {
			\WC_Subscriptions_Change_Payment_Gateway::update_all_payment_methods_from_subscription( $subscription, Plugin::GATEWAY_ID );
		}

		return $subscription;
	}

	/**
	 * Bring a subscription's stored token relation back in line with its token meta.
	 *
	 * @param \WC_Subscription $subscription The subscription to reconcile.
	 * @return void
	 */
	public function reconcile( $subscription ) {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		$token = self::get_subscription_token( $subscription, false );

		if ( $token ) {
			self::set_subscription_token( $subscription, $token );
		}
	}

	/**
	 * Reconcile a subscription given its ID.
	 *
	 * @param int $subscription_id The subscription to reconcile.
	 * @return void
	 */
	public function reconcile_by_id( $subscription_id ) {
		if ( ! self::is_available() ) {
			return;
		}

		$subscription = wcs_get_subscription( absint( $subscription_id ) );

		if ( $subscription instanceof \WC_Subscription && Plugin::GATEWAY_ID === $subscription->get_payment_method() ) {
			$this->reconcile( $subscription );
		}
	}

	/**
	 * Expose the card token to Subscriptions.
	 *
	 * @param array            $payment_meta Payment meta keyed by gateway ID.
	 * @param \WC_Subscription $subscription The subscription being described.
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$token = self::get_subscription_token( $subscription, false );

		$payment_meta[ Plugin::GATEWAY_ID ] = array(
			'post_meta' => array(
				self::TOKEN_META_KEY => array(
					'value' => $token ? $token->get_token() : (string) $subscription->get_meta( self::TOKEN_META_KEY ),
					'label' => __( 'Paytrail card', 'paytrail-for-woocommerce' ),
				),
			),
		);

		return $payment_meta;
	}

	/**
	 * Reject a card that is not one of the customer's saved Paytrail cards.
	 *
	 * @param array            $payment_meta The submitted meta for this gateway.
	 * @param \WC_Subscription $subscription The subscription being updated.
	 * @return void
	 * @throws \Exception If the submitted token is missing or does not belong to the customer.
	 */
	public function validate_subscription_payment_meta( $payment_meta, $subscription ) {
		$token_value = isset( $payment_meta['post_meta'][ self::TOKEN_META_KEY ]['value'] )
			? (string) $payment_meta['post_meta'][ self::TOKEN_META_KEY ]['value']
			: '';

		if ( '' === $token_value ) {
			throw new \Exception(
				esc_html__( 'A saved Paytrail card is required to charge this subscription\'s renewals.', 'paytrail-for-woocommerce' )
			);
		}

		if ( ! self::find_customer_token_by_value( $subscription->get_customer_id(), $token_value ) ) {
			throw new \Exception(
				esc_html__( 'That card is not one of this customer\'s saved Paytrail cards.', 'paytrail-for-woocommerce' )
			);
		}
	}

	/**
	 * Render the customer's saved cards instead of a free text token field.
	 *
	 * @param \WC_Subscription $subscription The subscription being edited.
	 * @param string           $field_id     The input name and ID to use.
	 * @param string           $field_value  The currently stored token value.
	 * @param array            $meta_data    The field's meta data. Unused; part of the hook signature.
	 * @return void
	 */
	public function render_admin_token_select( $subscription, $field_id, $field_value, $meta_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the hook signature.
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $subscription->get_customer_id(), Plugin::GATEWAY_ID );

		if ( empty( $tokens ) ) {
			printf(
				'<input type="text" class="short" name="%1$s" id="%1$s" value="%2$s" readonly>',
				esc_attr( $field_id ),
				esc_attr( $field_value )
			);
			echo '<span class="description">'
				. esc_html__( 'This customer has no saved Paytrail cards.', 'paytrail-for-woocommerce' )
				. '</span>';

			return;
		}

		echo '<select name="' . esc_attr( $field_id ) . '" id="' . esc_attr( $field_id ) . '" style="width:100%">';

		$has_current = false;

		foreach ( $tokens as $token ) {
			$is_current   = $token->get_token() === $field_value;
			$has_current  = $has_current || $is_current;
			$option_label = sprintf(
				/* translators: 1: card display name, 2: expiry month, 3: expiry year */
				__( '%1$s (expires %2$s/%3$s)', 'paytrail-for-woocommerce' ),
				$token->get_display_name(),
				$token->get_expiry_month(),
				$token->get_expiry_year()
			);

			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $token->get_token() ),
				$is_current ? ' selected' : '',
				esc_html( $option_label )
			);
		}

		if ( ! $has_current && '' !== $field_value ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $field_value ),
				esc_html__( 'Current card (no longer saved to this customer)', 'paytrail-for-woocommerce' )
			);
		}

		echo '</select>';
	}
}
