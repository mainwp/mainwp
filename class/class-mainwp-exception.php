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
	 * Protected variable to hold the Error Code.
	 *
	 * @var string Error Code.
	 */
	protected $errorCode;

	/**
	 * Protected variable to hold the extra data.
	 *
	 * @var array data.
	 */
	protected $data;

	/**
	 * MainWP_Exception constructor.
	 *
	 * Grab Exception Message upon creation of the object.
	 *
	 * @param mixed  $message Exception message.
	 * @param null   $extra Any extra Errors.
	 * @param string $errCode Errors code.
	 */
	public function __construct( $message, $extra = null, $errCode = '' ) {
		parent::__construct( $message );
		$this->messageExtra = esc_html( $extra ); // add more secure.
		$this->errorCode    = esc_html( $errCode );
	}

	/**
	 * Method get_message_extra()
	 *
	 * @return $messageExtra Extra messages.
	 */
	public function get_message_extra() {
		return $this->messageExtra;
	}

	/**
	 * Method get_message_error_code()
	 *
	 * @return string $errorCode Errors code.
	 */
	public function get_message_error_code() {
		return $this->errorCode;
	}

	/**
	 * Method set_data()
	 *
	 * @param mixed $data Addition data.
	 */
	public function set_data( $data ) {
		$this->data = $data;
	}

	/**
	 * Method get_data()
	 *
	 * @return $data Addition data.
	 */
	public function get_data() {
		return $this->data;
	}
}
