<?php
/**
 * MainWP Monitor - Bootstrap
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a System Monitor check result.
 *
 * Encapsulates the monitor identifier, check name, affected entity,
 * issue code, severity, and additional payload data for a single
 * System Monitor result.
 */
class MainWP_System_Monitor_Result {

    /**
     * Monitor identifier.
     *
     * @var string
     */
    private $monitor;

    /**
     * Check name.
     *
     * @var string
     */
    private $check_name;

    /**
     * Entity associated with the result.
     *
     * @var string|int
     */
    private $entity;

    /**
     * Issue code.
     *
     * @var string
     */
    private $issue_code;

    /**
     * Issue severity.
     *
     * @var string|null
     */
    private $severity = null;

    /**
     * Additional result payload.
     *
     * @var array
     */
    private $data = array();

    /**
     * Constructor.
     *
     * @param string      $monitor    Monitor identifier.
     * @param string      $check_name Check name.
     * @param string|int  $entity     Entity associated with the result.
     * @param string      $issue_code Issue code.
     * @param array       $data       Additional result payload.
     * @param string|null $severity   Issue severity.
     */
    public function __construct(
        $monitor,
        $check_name,
        $entity,
        $issue_code,
        array $data = array(),
        $severity = null
    ) {
        $this->monitor    = $monitor;
        $this->check_name = $check_name;
        $this->entity     = $entity;
        $this->issue_code = $issue_code;
        $this->data       = $data;
        $this->severity   = $severity;
    }

    /**
     * Get the monitor identifier.
     *
     * @return string Monitor identifier.
     */
    public function get_monitor() {
        return $this->monitor;
    }

    /**
     * Get the check name.
     *
     * @return string Check name.
     */
    public function get_check_name() {
        return $this->check_name;
    }

    /**
     * Get the issue code.
     *
     * @return string Issue code.
     */
    public function get_issue_code() {
        return $this->issue_code;
    }

    /**
     * Get the associated entity.
     *
     * @return string|int Entity identifier.
     */
    public function get_entity() {
        return $this->entity;
    }

    /**
     * Get the issue severity.
     *
     * @return string|null Issue severity.
     */
    public function get_severity() {
        return $this->severity;
    }

    /**
     * Get the additional result payload.
     *
     * @return array Result payload.
     */
    public function get_payload() {
        return $this->data;
    }
}
