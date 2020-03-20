<?php
/**
 * Extends MainWP Exception
 * 
 * Grabs $extra and stores it in $messageExtra.
 */

/**
 * MainWP_Exception
 */
class MainWP_Exception extends Exception {


	/**
	 * @var undefined $messageExtra WPERROR messages.
	 */
	protected $messageExtra;


	/**
	 * Method __construct()
	 * 
	 * Grab Exception Message.
	 * 
	 * @param mixed $message
	 * @param null $extra
	 * 
	 */
	public function __construct( $message, $extra = null ) {
		parent::__construct( $message );
		$this->messageExtra = esc_html($extra); // more secure
	}

	/**
	 * Method getMessageExtra()
	 * 
	 * @return $messageExtra
	 */
	public function getMessageExtra() {
		return $this->messageExtra;
	}

}
