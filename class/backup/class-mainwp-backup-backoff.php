<?php
/**
 * MainWP Backup Backoff - the one retry schedule for backup lanes.
 *
 * The upload driver, cloud delete driver, and restore orchestrator all
 * retry transient failures on the same exponential schedule. The
 * constants and the formula live here so the lanes cannot drift apart
 * (they previously expressed the same series two different ways).
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Backoff
 */
class MainWP_Backup_Backoff { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Attempts before a retryable failure goes permanent.
     */
    const MAX_ATTEMPTS = 7;

    /**
     * Backoff base, seconds (doubles per attempt).
     */
    const RETRY_BASE_SECONDS = 300;

    /**
     * Backoff ceiling, seconds.
     */
    const RETRY_MAX_SECONDS = 14400;

    /**
     * The delay before the given attempt may run.
     *
     * @param int $attempts The attempt number being scheduled, 1-based
     *                      (the new attempt count after the failure).
     *
     * @return int Delay in seconds: 300, 600, 1200, ... capped at 14400.
     */
    public static function delay_for_attempt( int $attempts ): int {
        $attempts = max( 1, $attempts );
        return (int) min( self::RETRY_MAX_SECONDS, self::RETRY_BASE_SECONDS * ( 2 ** ( $attempts - 1 ) ) );
    }

    /**
     * Whether the given attempt count exhausts the retry budget.
     *
     * @param int $attempts The new attempt count after the failure.
     *
     * @return bool True when no further retry is allowed.
     */
    public static function attempts_exhausted( int $attempts ): bool {
        return $attempts >= self::MAX_ATTEMPTS;
    }
}
