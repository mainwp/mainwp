<?php
/**
 * MainWP Extra Exception
 *
 * @package MainWP/Dashboard
 * @since 5.2
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Extra exception class.
 */
class MainWP_Extra_Exception extends \Exception {

    /**
     * Sanitized error code.
     *
     * @var string
     */
    protected $error_code;

    /**
     * Error extra data.
     *
     * @var array
     */
    protected $error_data;

    /**
     * Setup exception.
     *
     * @param string $code             Machine-readable error code, e.g `mainwp_invalid_site_id`.
     * @param string $message          User-friendly translated error message, e.g. 'Site ID is invalid'.
     * @param string $http_status_code HTTP status code.
     * @param array  $data             Extra error data.
     */
    public function __construct( $code, $message, $http_status_code = 400, $data = array() ) {
        $this->error_code = $code;
        $this->error_data = $data;

        parent::__construct( $message, $http_status_code );
    }

    /**
     * Returns the error code.
     *
     * @return string
     */
    public function getErrorCode() {
        return $this->error_code;
    }

    /**
     * Returns error data.
     *
     * @return array
     */
    public function getErrorData() {
        return $this->error_data;
    }
}
