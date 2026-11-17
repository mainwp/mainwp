<?php
/**
 * Remote MCP token endpoint rate limits.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fixed-window counters for the token endpoint only: per client IP per minute and refreshes per
 * grant per hour. There's no limit shared across addresses, because a few addresses could use it
 * to stop every connected app from refreshing. Registration has its own limiter,
 * OAuth_Registration_Rate_Limiter.
 *
 * Counters live in transients. Two requests racing the same read can both pass, which lets a
 * window run over by a request or two; a limit that close is enough to stop a refresh loop from
 * minting rows, and an exact counter would need a table this module doesn't have.
 */
final class OAuth_Rate_Limiter {

    const IP_LIMIT       = 30;
    const IP_WINDOW      = 60;
    const REFRESH_LIMIT  = 30;
    const REFRESH_WINDOW = 3600;

    const TRANSIENT_PREFIX = 'mainwp_rmcp_rl_';

    /**
     * Clock.
     *
     * @var callable
     */
    private $now;

    /**
     * Constructor.
     *
     * @param callable|null $now Returns the current unix time; time() when null.
     */
    public function __construct( ?callable $now = null ) {
        $this->now = $now ? $now : 'time';
    }

    /**
     * Count one token request against the per-IP limit.
     *
     * @param string $ip Client IP.
     * @return array|null Null when allowed, else `retry_after` (seconds), `first` (whether this is the
     *                    window's first refusal) and `limit` (`ip`).
     */
    public function endpoint_hit( string $ip ): ?array {
        $refused = $this->hit( 'ip|' . $ip, self::IP_LIMIT, self::IP_WINDOW );
        return null === $refused ? null : $refused + array( 'limit' => 'ip' );
    }

    /**
     * Count one refresh against its grant's hourly limit.
     *
     * @param int $grant_id Grant id.
     * @return array|null See endpoint_hit(), with `limit` `refresh`.
     */
    public function refresh_hit( int $grant_id ): ?array {
        $refused = $this->hit( 'refresh|' . $grant_id, self::REFRESH_LIMIT, self::REFRESH_WINDOW );
        return null === $refused ? null : $refused + array( 'limit' => 'refresh' );
    }

    /**
     * Count one hit in the current window.
     *
     * @param string $bucket Bucket name.
     * @param int    $limit  Hits allowed per window.
     * @param int    $window Window length in seconds.
     * @return array|null Null when allowed.
     */
    private function hit( string $bucket, int $limit, int $window ): ?array {
        $now   = (int) call_user_func( $this->now );
        $start = $now - ( $now % $window );
        $key   = self::TRANSIENT_PREFIX . md5( $bucket . '|' . $start );
        $count = (int) get_transient( $key );
        $retry = max( 1, $start + $window - $now );
        if ( $count > $limit ) {
            return array(
                'retry_after' => $retry,
                'first'       => false,
            );
        }
        // One count past the limit marks the window as already refused, so a flood logs one event
        // per window instead of one per request.
        set_transient( $key, $count + 1, $retry );
        if ( $count === $limit ) {
            return array(
                'retry_after' => $retry,
                'first'       => true,
            );
        }
        return null;
    }
}
