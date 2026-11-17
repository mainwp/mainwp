<?php
/**
 * Remote MCP audit writer.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes every `mainwp_remote_mcp_audit` event to the activity log through the module's logs
 * connector. There is no separate audit table; while the log is off, the gate
 * refuses calls instead of running them unaudited.
 *
 * The event action is public, so anything can fire it directly. The writer re-applies the event
 * list and the field types, so a direct emitter can't log an unknown event or an argument value.
 */
class Remote_MCP_Audit implements Hook_Provider {

    /**
     * The connector rows are written through, once the logs module has loaded it.
     *
     * @var Connector_Remote_MCP|null
     */
    private $connector;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services  Module services.
     * @param object|null         $connector Connector to write through; tests pass their own.
     */
    public function __construct( Remote_MCP_Services $services, $connector = null ) {
        $this->connector = $connector;
    }

    /**
     * Hook the writer and the connector registration. Runs at every boot, connector on or off,
     * so events from a request that turned the connector off are still written.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function register_hooks( Remote_MCP_Services $services ): void {
        add_action( Remote_MCP_Events::AUDIT, array( $this, 'record' ), 10, 2 );
        add_filter( 'mainwp_module_log_connectors', array( $this, 'add_connector' ), 10, 1 );
        // Extension workers call the public check from cron, outside any module request, so it
        // must load on every boot, and register_hooks() runs on every boot.
        require_once __DIR__ . '/remote-mcp-functions.php';
    }

    /**
     * Add the module's connector. The logs module only applies this filter while logging is on,
     * and its connector base class exists only then.
     *
     * @param mixed $connectors Connectors by name.
     * @return mixed
     */
    public function add_connector( $connectors ) {
        if ( ! is_array( $connectors ) || ! class_exists( '\MainWP\Dashboard\Module\Log\Log_Connector' ) ) {
            return $connectors;
        }
        require_once __DIR__ . '/class-connector-remote-mcp.php';
        if ( null === $this->connector ) {
            $this->connector = new Connector_Remote_MCP();
        }
        $connectors[ $this->connector->name ] = $this->connector;
        return $connectors;
    }

    /**
     * Write one audit event.
     *
     * @param mixed $event  Event name.
     * @param mixed $fields Fields.
     * @return bool Whether a row was written.
     */
    public function record( $event, $fields = array() ): bool {
        if ( ! is_string( $event ) || ! in_array( $event, Remote_MCP_Events::AUDIT_EVENTS, true ) || ! is_array( $fields ) || null === $this->connector ) {
            return false;
        }
        $kept = array();
        foreach ( Remote_MCP_Events::LOGGED_FIELDS as $name => $type ) {
            if ( array_key_exists( $name, $fields ) && self::has_type( $fields[ $name ], $type ) ) {
                $kept[ $name ] = 'string' === $type ? substr( $fields[ $name ], 0, 191 ) : $fields[ $name ];
            }
        }
        return (bool) $this->connector->record( $event, $kept );
    }

    /**
     * The same type rules Remote_MCP_Events::audit() applies.
     *
     * @param mixed  $value Value.
     * @param string $type  Type.
     * @return bool
     */
    private static function has_type( $value, string $type ): bool {
        switch ( $type ) {
            case 'int':
                return is_int( $value );
            case 'string':
                return is_string( $value );
            case 'policy':
                return is_string( $value ) && in_array( $value, OAuth_Store::POLICIES, true );
            case 'scope':
                if ( ! is_string( $value ) || '' === $value ) {
                    return false;
                }
                $tokens = explode( ' ', $value );
                return count( $tokens ) === count( array_unique( $tokens ) ) && ! array_diff( $tokens, OAuth_Store::SCOPES );
            case 'int_list':
                if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
                    return false;
                }
                foreach ( $value as $id ) {
                    if ( ! is_int( $id ) ) {
                        return false;
                    }
                }
                return true;
        }
        return false;
    }
}
