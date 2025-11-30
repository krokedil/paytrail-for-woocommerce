<?php
namespace Paytrail\WooCommercePaymentGateway\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Trait Singleton
 *
 * A trait to make a class a singleton
 */
trait Singleton {

	/**
	 * Instance of the class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Get the instance of the class
	 *
	 * @param mixed ...$args Optional arguments to pass to the class constructor.
	 * @return object
	 */
	public static function get_instance( ...$args ) {
		if ( null === self::$instance ) {
			self::$instance = new self( ...$args );
		}

		return self::$instance;
	}

	/**
	 * Prevent creating a new instance of the class
	 */
	private function __construct() {
	}

	/**
	 * Prevent cloning the instance of the class
	 */
	public function __clone() {
	}

	/**
	 * Prevent unserializing the instance of the class
	 */
	public function __wakeup() {
	}
}
