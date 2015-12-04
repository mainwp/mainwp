<?php

class MainWP_Exception extends Exception {
	protected $messageExtra;

	public function __construct( $message, $extra = null ) {
		parent::__construct( $message );
		$this->messageExtra = $extra;
	}

	public function getMessageExtra() {
		return $this->messageExtra;
	}
}

