<?php
/**
 * Paytrail for Woocommerce payment Callback controller class
 */

namespace Paytrail\WooCommercePaymentGateway\Controllers;

use Paytrail\WooCommercePaymentGateway\Gateway;

class Callback extends AbstractController {

	/**
	 * Index method for the Callback controller
	 */
	protected function index() {
		Gateway::get_instance()->set_callback_mode( true );
	}
}
