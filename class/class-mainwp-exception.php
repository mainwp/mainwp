<?php
/**
 * MainWP_Exception
 */
class MainWP_Exception extends Exception {

	protected $messageExtra;

	public function __construct( $message, $extra = null ) {
		parent::__construct( $message );
		$this->messageExtra = esc_html($extra); // more secure
	}

	public function getMessageExtra() {
		return $this->messageExtra;
	}

}
