<?php
/**
 * Subscription recurring token view.
 *
 * @package Paytrail\WooCommercePaymentGateway\View
 *
 * Rendered from Controllers\SubscriptionToken::show_recurring_token().
 *
 * @var array{field: string, current: \WC_Payment_Token|null, tokens: \WC_Payment_Token[]} $data The view data.
 */

$current = $data['current'];
?>
<div class="order_data_column" style="clear:both; float:none; width:100%;">
	<div class="address">
		<p>
			<strong><?php esc_html_e( 'Paytrail recurring token', 'paytrail-for-woocommerce' ); ?>:</strong>
			<?php if ( $current ) : ?>
				<?php echo esc_html( $current->get_token() ); ?>
				<br/><?php echo esc_html( $current->get_display_name() ); ?>
			<?php else : ?>
				<?php esc_html_e( 'No card saved. Renewals cannot be charged automatically.', 'paytrail-for-woocommerce' ); ?>
			<?php endif; ?>
		</p>
	</div>
	<div class="edit_address">
		<p class="form-field form-field-wide">
			<label for="<?php echo esc_attr( $data['field'] ); ?>"><?php esc_html_e( 'Paytrail recurring token', 'paytrail-for-woocommerce' ); ?>:</label>
			<?php if ( empty( $data['tokens'] ) ) : ?>
				<?php esc_html_e( 'The customer has no saved Paytrail cards.', 'paytrail-for-woocommerce' ); ?>
			<?php else : ?>
				<select id="<?php echo esc_attr( $data['field'] ); ?>" name="<?php echo esc_attr( $data['field'] ); ?>" style="width:100%;">
					<?php if ( ! $current ) : ?>
						<option value=""><?php esc_html_e( 'Select a card', 'paytrail-for-woocommerce' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $data['tokens'] as $token ) : ?>
						<option value="<?php echo esc_attr( (string) $token->get_id() ); ?>" <?php selected( $current && $current->get_id() === $token->get_id() ); ?>>
							<?php echo esc_html( $token->get_display_name() . ' – ' . $token->get_token() ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</p>
	</div>
</div>
