<?php
/**
 * Extends MainWP Exception
 *
 * Grabs $extra and stores it in $messageExtra.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Exception
 *
 * @package MainWP\Dashboard
 */
class MainWP_Exception extends \Exception {


	/**
	 * Protected variable to hold the extra messages.
	 *
	 * @var string Error messages.
	 */
	protected $messageExtra;


	/**
	 * Method __construct()
	 *
	 * Grab Exception Message upon creation of the object.
	 *
	 * @param mixed $message Exception message.
	 * @param null  $extra Any HTTP Errors.
	 */
	public function __construct( $message, $extra = null ) {
		parent::__construct( $message );
		$this->messageExtra = esc_html( $extra ); // add more secure.
	}

	/**
	 * Method get_message_extra()
	 *
	 * @return $messageExtra Extra messages.
	 */
	public function get_message_extra() {
		return $this->messageExtra;
	}

}
