<?php
/**
 * Paytrail for Woocommerce payment Callback controller class
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\WooCommercePaymentGateway\Gateway;

class Callback extends AbstractController {

	protected function index() {
		Gateway::get_instance( array( 'callbackMode' => true ) );
	}
}
