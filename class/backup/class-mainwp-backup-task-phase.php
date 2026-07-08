<?php
/**
 * MainWP Backup Task Phase.
 *
 * The phases a resumable child backup task walks through. The string
 * values are the wire format reported by child sites in task status
 * payloads, so they must not change. Unknown phase strings from newer
 * children must be treated as still-working, which is why callers use
 * tryFrom() and keep the null case non-terminal.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Backup_Task_Phase
 */
final class MainWP_Backup_Task_Phase {

    const QUEUED          = 'queued';
    const DUMPING_DB      = 'dumping_db';
    const ARCHIVING_FILES = 'archiving_files';
    const UPLOADING       = 'uploading';
    const SUBMITTING      = 'submitting';
    const COMPLETED       = 'completed';
    const FAILED          = 'failed';

    /**
     * Private constructor. This class is a static namespace, not meant to be instantiated.
     */
    private function __construct() {}

    /**
     * All known wire values, in phase order.
     *
     * @return string[] Wire values.
     */
    public static function cases(): array {
        return array(
            self::QUEUED,
            self::DUMPING_DB,
            self::ARCHIVING_FILES,
            self::UPLOADING,
            self::SUBMITTING,
            self::COMPLETED,
            self::FAILED,
        );
    }

    /**
     * Resolve a wire value to itself, or null when unrecognized.
     *
     * @param string $value Wire value.
     *
     * @return string|null Matching value, or null when unknown.
     */
    public static function tryFrom( string $value ): ?string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors PHP's native enum tryFrom()/from() naming so consumer call sites need no rename.
        return in_array( $value, self::cases(), true ) ? $value : null;
    }

    /**
     * Resolve a wire value to itself, throwing when unrecognized.
     *
     * @param string $value Wire value.
     *
     * @return string Matching value.
     *
     * @throws \InvalidArgumentException When the value is not a known case (7.4-safe stand-in for the native enum ValueError).
     */
    public static function from( string $value ): string {
        $result = self::tryFrom( $value );
        if ( null === $result ) {
            throw new \InvalidArgumentException( esc_html( sprintf( '"%s" is not a valid backing value for %s', $value, self::class ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $result;
    }

    /**
     * Whether the phase is a terminal state.
     *
     * @param string $phase Wire value.
     *
     * @return bool True for completed and failed, false otherwise.
     */
    public static function is_terminal( string $phase ): bool {
        return self::COMPLETED === $phase || self::FAILED === $phase;
    }

    /**
     * The human label for a phase. Escape at render time.
     *
     * @param string $phase Wire value.
     *
     * @return string Translated label.
     */
    public static function label( string $phase ): string {
        switch ( $phase ) {
            case self::QUEUED:
                return __( 'Waiting to start', 'mainwp' );
            case self::DUMPING_DB:
                return __( 'Exporting database', 'mainwp' );
            case self::ARCHIVING_FILES:
                return __( 'Archiving files', 'mainwp' );
            case self::UPLOADING:
                return __( 'Uploading', 'mainwp' );
            case self::SUBMITTING:
                return __( 'Finalizing', 'mainwp' );
            case self::COMPLETED:
                return __( 'Completed', 'mainwp' );
            case self::FAILED:
                return __( 'Failed', 'mainwp' );
            default:
                return '';
        }
    }
}
