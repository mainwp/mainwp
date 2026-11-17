<?php
/**
 * Remote MCP registration rate limiter.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-address registration counter, keyed on the address the endpoint resolved: REMOTE_ADDR or the
 * site's mainwp_remote_mcp_client_ip mapping, never a proxy header. It shares no code with the
 * token endpoint's limiter.
 */
class OAuth_Registration_Rate_Limiter {

    const LIMIT  = 20;
    const WINDOW = HOUR_IN_SECONDS;

    /**
     * How long a logged limit hit keeps further hits out of the log.
     *
     * @var int
     */
    const LOG_INTERVAL = HOUR_IN_SECONDS;

    /**
     * Separate registration and document-fetch budgets for the same address.
     *
     * @var string
     */
    private $prefix;

    /**
     * Constructor.
     *
     * @param string $prefix Transient prefix.
     */
    public function __construct( string $prefix = 'mainwp_remote_mcp_reg_' ) {
        $this->prefix = $prefix;
    }

    /**
     * Seconds until this address may register again.
     *
     * @param string $ip Client address.
     * @return int Seconds until it may, 0 when it may now.
     */
    public function retry_after( string $ip ): int {
        $bucket = $this->bucket( $ip );
        if ( null === $bucket || $bucket['count'] < self::LIMIT ) {
            return 0;
        }
        return max( 1, $bucket['start'] + self::WINDOW - $this->now() );
    }

    /**
     * Count one registration from this address.
     *
     * @param string $ip Client address.
     */
    public function record( string $ip ): void {
        $bucket = $this->bucket( $ip );
        if ( null === $bucket ) {
            $bucket = array(
                'start' => $this->now(),
                'count' => 0,
            );
        }
        ++$bucket['count'];
        set_transient( $this->key( $ip ), $bucket, max( 1, $bucket['start'] + self::WINDOW - $this->now() ) );
    }

    /**
     * Whether a limit hit should be logged now; true at most once per LOG_INTERVAL.
     *
     * @return bool
     */
    public function claim_log_slot(): bool {
        if ( false !== get_transient( 'mainwp_remote_mcp_reg_limited' ) ) {
            return false;
        }
        set_transient( 'mainwp_remote_mcp_reg_limited', 1, self::LOG_INTERVAL );
        return true;
    }

    /**
     * Current window of an address, or null when none is open.
     *
     * @param string $ip Client address.
     * @return array|null `start`, `count`.
     */
    private function bucket( string $ip ): ?array {
        $stored = get_transient( $this->key( $ip ) );
        if ( ! is_array( $stored ) || ! is_int( $stored['start'] ?? null ) || ! is_int( $stored['count'] ?? null ) || $stored['start'] + self::WINDOW <= $this->now() ) {
            return null;
        }
        return $stored;
    }

    /**
     * Transient key. The address is hashed so the key length is fixed.
     *
     * @param string $ip Client address.
     * @return string
     */
    private function key( string $ip ): string {
        return $this->prefix . substr( hash( 'sha256', $ip ), 0, 32 );
    }

    /**
     * Current time.
     *
     * @return int
     */
    protected function now(): int {
        return time();
    }
}
