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
class MainWP_Exception extends \Exception { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
        $this->messageExtra = $extra; // escape in get method.
        $this->errorCode    = esc_html( $errCode );
    }

    /**
     * Method get_message_extra()
     *
     * @param bool $escape_msg Exception message.
     *
     * @return $messageExtra Extra messages.
     */
    public function get_message_extra( $escape_msg = true ) {
        return $escape_msg ? esc_html( wp_strip_all_tags( $this->messageExtra ) ) : $this->messageExtra;
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
