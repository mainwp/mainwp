<?php
/**
 * MainWP Monitor - Bootstrap
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class MainWP_System_Monitor_Result {

    private $monitor;

    private $check_name;

    private $entity;

    private $issue_code;

    private $severity = null;

    private $data = array();

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

    public function get_monitor() {
        return $this->monitor;
    }

    public function get_check_name() {
        return $this->check_name;
    }

    public function get_issue_code() {
        return $this->issue_code;
    }

    public function get_entity() {
        return $this->entity;
    }

    public function get_severity() {
        return $this->severity;
    }
    public function get_payload() {
        return $this->data;
    }
}
