<?php
/**
 * MainWP Backup Task Phase enum.
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
 * Enum MainWP_Backup_Task_Phase
 */
enum MainWP_Backup_Task_Phase: string {

    case Queued         = 'queued';
    case DumpingDb      = 'dumping_db';
    case ArchivingFiles = 'archiving_files';
    case Uploading      = 'uploading';
    case Submitting     = 'submitting';
    case Completed      = 'completed';
    case Failed         = 'failed';

    /**
     * Whether the phase is a terminal state.
     *
     * @return bool True for completed and failed, false otherwise.
     */
    public function is_terminal(): bool {
        return self::Completed === $this || self::Failed === $this;
    }

    /**
     * The human label for this phase. Escape at render time.
     *
     * @return string Translated label.
     */
    public function label(): string {
        return match ( $this ) {
            self::Queued         => __( 'Waiting to start', 'mainwp' ),
            self::DumpingDb      => __( 'Exporting database', 'mainwp' ),
            self::ArchivingFiles => __( 'Archiving files', 'mainwp' ),
            self::Uploading      => __( 'Uploading', 'mainwp' ),
            self::Submitting     => __( 'Finalizing', 'mainwp' ),
            self::Completed      => __( 'Completed', 'mainwp' ),
            self::Failed         => __( 'Failed', 'mainwp' ),
        };
    }
}
