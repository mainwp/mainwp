<?php
/**
 * Remote MCP activity log connector.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The logs-module connector for remote AI connections. Loaded only from the
 * `mainwp_module_log_connectors` filter, where the connector base class exists. It hooks no action
 * of its own: Remote_MCP_Audit hands it each event after re-checking the event and its fields.
 */
class Connector_Remote_MCP extends \MainWP\Dashboard\Module\Log\Log_Connector {

    /**
     * Connector name.
     *
     * @var string
     */
    public $name = 'remote_mcp';

    /**
     * Log context.
     *
     * @var string
     */
    const CONTEXT = 'remote_mcp';

    /**
     * Label.
     *
     * @return string
     */
    public function get_label() {
        return esc_html__( 'Remote AI connections', 'mainwp' );
    }

    /**
     * One label per audit event.
     *
     * @return array
     */
    public function get_action_labels() {
        $labels = array();
        foreach ( Remote_MCP_Events::AUDIT_EVENTS as $event ) {
            $labels[ $event ] = ucfirst( str_replace( '_', ' ', $event ) );
        }
        return $labels;
    }

    /**
     * Context labels.
     *
     * @return array
     */
    public function get_context_labels() {
        return array( self::CONTEXT => esc_html__( 'Remote AI', 'mainwp' ) );
    }

    /**
     * Write one event. The log stores scalar meta, so site ids are kept as comma-separated text;
     * they are the only argument values the log ever holds.
     *
     * @param string $event  Event, already checked against the event list.
     * @param array  $fields Typed fields, already filtered.
     * @return bool
     */
    public function record( string $event, array $fields ): bool {
        $user_id = isset( $fields['user_id'] ) && is_int( $fields['user_id'] ) ? $fields['user_id'] : 0;
        $site_id = 0;
        if ( isset( $fields['site_ids'] ) ) {
            $ids                = (array) $fields['site_ids'];
            $site_id            = 1 === count( $ids ) ? (int) reset( $ids ) : 0;
            $fields['site_ids'] = implode( ',', array_map( 'intval', $ids ) );
        }
        $args = array( 'event' => $this->get_action_labels()[ $event ] ?? $event ) + $fields;
        return false !== $this->log( '%s', $args, $site_id, self::CONTEXT, $event, 1, $user_id );
    }
}
