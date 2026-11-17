<?php
/** Minimal WP_Error for the isolated support-settings handler run. */

class WP_Error {
    private $message;

    public function __construct( $code = '', $message = '' ) {
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }
}
