<?php
/**
 * Shared protection for remote backup retention and local archive eviction.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Retention evidence shared by selection, execution and confirmation previews. */
class MainWP_Backup_Retention_Protection {

    /** Task opt-in option prefix. */
    const TASK_OPTION_PREFIX = 'mainwp_backup_remove_local_';

    /** Insert-only snapshot destination requirements. */
    const REQUIRED_OPTION_PREFIX = 'mainwp_backup_required_uploads_';

    /**
     * Read the absent-by-default task opt-in.
     *
     * @param int $task_id Task identity.
     * @return bool
     */
    public static function task_removes_local_archives( int $task_id ): bool {
        return $task_id > 0 && (bool) get_option( self::TASK_OPTION_PREFIX . $task_id, false );
    }

    /**
     * Save and verify the task setting; an unchanged value is successful.
     *
     * @param int  $task_id Task identity.
     * @param bool $enabled Opt-in value.
     * @return bool
     */
    public static function save_task_remove_local_archives( int $task_id, bool $enabled ): bool {
        if ( $task_id <= 0 ) {
            return false;
        }
        $key = self::TASK_OPTION_PREFIX . $task_id;
        update_option( $key, $enabled ? 1 : 0, false );
        return get_option( $key, null ) === ( $enabled ? 1 : 0 ) || get_option( $key, null ) === ( $enabled ? '1' : '0' );
    }

    /**
     * Capture the entire destination set before any upload is enqueued.
     *
     * @param int   $snapshot_id Snapshot identity.
     * @param int[] $destinations Required destinations.
     * @return bool Whether this exact requirement is durably present.
     */
    public static function capture_required_destinations( int $snapshot_id, array $destinations ): bool {
        $destinations = array_values( array_unique( array_filter( array_map( 'intval', $destinations ), static fn( $id ) => $id > 0 ) ) );
        sort( $destinations, SORT_NUMERIC );
        if ( $snapshot_id <= 0 || empty( $destinations ) ) {
            return false;
        }
        $key = self::REQUIRED_OPTION_PREFIX . $snapshot_id;
        add_option( $key, $destinations, '', false );
        return get_option( $key, null ) === $destinations;
    }

    /**
     * Distinguish new opt-in snapshots from legacy history without a backfill.
     *
     * @param object $snapshot Snapshot with decoded archive descriptors.
     * @return bool Whether capture was required or already persisted.
     */
    public static function snapshot_requires_uploads( object $snapshot ): bool {
        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['retention_requires_uploads'] ) ) {
                return true;
            }
        }
        return null !== get_option( self::REQUIRED_OPTION_PREFIX . (int) $snapshot->id, null );
    }

    /**
     * Every captured destination must have persisted terminal verification.
     *
     * @param int $snapshot_id Snapshot identity.
     * @return bool
     */
    public static function required_uploads_verified( int $snapshot_id ): bool {
        $required = get_option( self::REQUIRED_OPTION_PREFIX . $snapshot_id, null );
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        if ( null === $snapshot || 'complete' !== $snapshot->status || ! is_array( $required ) || empty( $required ) ) {
            return false;
        }
        $jobs = array();
        foreach ( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id ) as $job ) {
            $jobs[ (int) $job->destination_id ] = $job;
        }
        $remote = false;
        foreach ( $required as $destination_id ) {
            $job = $jobs[ (int) $destination_id ] ?? null;
            if ( null === $job || MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE !== $job->status || empty( $job->checkpoint['manifest_submitted'] ) || empty( $job->checkpoint['uploaded_at'] ) || ! self::checkpoint_covers_snapshot( $snapshot, $job->checkpoint ) ) {
                return false;
            }
            $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $destination_id );
            if ( null === $destination || ! empty( $destination->credentials_invalid ) || ! is_array( $destination->settings )
                || empty( $job->provider ) || $destination->provider !== $job->provider ) {
                return false;
            }
            $binding = self::destination_binding( $destination );
            if ( '' === $binding || empty( $job->checkpoint['destination_binding'] ) || ! hash_equals( $binding, (string) $job->checkpoint['destination_binding'] ) ) {
                return false;
            }
            if ( 'local' !== $destination->provider ) {
                $remote = true;
            }
        }
        return $remote;
    }

    /**
     * Bind upload evidence to its original destination without storing settings.
     *
     * @param object $destination Decrypted destination row.
     * @return string Keyed binding, or empty when settings are unavailable.
     */
    public static function destination_binding( object $destination ): string {
        if ( ! empty( $destination->credentials_invalid ) || ! is_array( $destination->settings ) || empty( $destination->provider ) ) {
            return '';
        }
        $settings = $destination->settings;
        unset( $settings['minimum_remote_retention_days'] );
        $sort    = static function ( array $values ) use ( &$sort ): array {
            ksort( $values );
            foreach ( $values as &$value ) {
                if ( is_array( $value ) ) {
                    $value = $sort( $value );
                }
            }
            unset( $value );
            return $values;
        };
        $encoded = wp_json_encode(
            array(
                'provider' => (string) $destination->provider,
                'settings' => $sort( $settings ),
            )
        );
        return false === $encoded ? '' : hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
    }

    /**
     * Verify that a completed index covers every recorded archive before eviction.
     *
     * @param object $snapshot Snapshot with immutable archive descriptors.
     * @param array  $checkpoint Verified destination checkpoint.
     * @return bool Whether every artifact can be resolved remotely.
     */
    private static function checkpoint_covers_snapshot( object $snapshot, array $checkpoint ): bool {
        $artifacts = array();
        foreach ( (array) ( $checkpoint['artifacts'] ?? array() ) as $artifact ) {
            if ( is_array( $artifact ) && ! empty( $artifact['file'] ) ) {
                $artifacts[ (string) $artifact['file'] ] = $artifact;
            }
        }
        if ( empty( $snapshot->archives ) ) {
            return false;
        }
        foreach ( (array) $snapshot->archives as $archive ) {
            $file     = (string) ( $archive['file'] ?? '' );
            $artifact = $artifacts[ $file ] ?? null;
            if ( '' === $file || basename( $file ) !== $file || null === $artifact || empty( $artifact['done'] )
                || ! isset( $archive['size'], $artifact['size'] ) || (int) $archive['size'] !== (int) $artifact['size']
                || ( $archive['role'] ?? '' ) !== ( $artifact['role'] ?? '' )
                || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $artifact['sha256'] ?? '' ) ) ) {
                return false;
            }
            if ( ! empty( $archive['sha256'] ) && ! array_key_exists( 'hash_skipped', $archive )
                && ! hash_equals( (string) $archive['sha256'], (string) $artifact['sha256'] ) ) {
                return false;
            }
            if ( 'whole' === ( $artifact['mode'] ?? '' ) ) {
                continue;
            }
            if ( 'chunked' !== ( $artifact['mode'] ?? '' ) || empty( $artifact['chunks'] ) || ! is_array( $artifact['chunks'] ) ) {
                return false;
            }
            $offset = 0;
            foreach ( $artifact['chunks'] as $chunk ) {
                if ( empty( $chunk['remote_present'] ) || empty( $chunk['hash'] ) || (int) ( $chunk['offset'] ?? -1 ) !== $offset || (int) ( $chunk['length'] ?? 0 ) <= 0 ) {
                    return false;
                }
                $offset += (int) $chunk['length'];
            }
            if ( $offset !== (int) $archive['size'] ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Destination minimum protection, independent of remote-only upload holds.
     *
     * @param int[]    $snapshot_ids Snapshot identities, including dependencies.
     * @param int|null $now Evaluation instant.
     * @return array Deadline and unknown-age evidence.
     */
    public static function remote_protection( array $snapshot_ids, ?int $now = null ): array {
        unset( $now );
        $protection = array(
            'protected_until' => 0,
            'unknown_age'     => false,
        );
        foreach ( $snapshot_ids as $snapshot_id ) {
            $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $snapshot_id );
            foreach ( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( (int) $snapshot_id ) as $job ) {
                $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
                if ( null === $destination ) {
                    continue;
                }
                $age                           = self::upload_age_evidence( $job, $snapshot );
                $one                           = self::destination_protection( $destination, $age );
                $protection['protected_until'] = max( $protection['protected_until'], $one['protected_until'] );
                $protection['unknown_age']     = $protection['unknown_age'] || $one['unknown_age'];
            }
        }
        return $protection;
    }

    /**
     * Preserve upload age when upload rows are replaced by delete jobs.
     *
     * @param object      $job Upload job.
     * @param object|null $snapshot Snapshot row.
     * @return array Immutable storage age or an explicit unknown marker.
     */
    public static function upload_age_evidence( object $job, ?object $snapshot ): array {
        if ( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE === $job->status ) {
            $uploaded = (int) ( $job->checkpoint['uploaded_at'] ?? 0 );
            if ( $uploaded <= 0 && null !== $snapshot ) {
                // Legacy completed jobs predate storage timestamps; no rows are backfilled.
                $uploaded = (int) $snapshot->created;
            }
            return array(
                'uploaded_at' => $uploaded,
                'unknown_age' => $uploaded <= 0,
            );
        }
        // Partial writes may be newer than every durable timestamp in a failed job.
        return array(
            'uploaded_at' => 0,
            'unknown_age' => true,
        );
    }

    /**
     * Apply the current destination minimum to persisted storage-age evidence.
     *
     * @param object $destination Decrypted destination row.
     * @param array  $evidence Storage age evidence.
     * @return array Deadline and unknown-age marker.
     */
    public static function destination_protection( object $destination, array $evidence ): array {
        if ( ! empty( $destination->credentials_invalid ) || ! is_array( $destination->settings ) ) {
            return array(
                'protected_until' => 0,
                'unknown_age'     => true,
            );
        }
        $days = max( 0, min( 36500, (int) ( $destination->settings['minimum_remote_retention_days'] ?? 0 ) ) );
        if ( 0 === $days ) {
            return array(
                'protected_until' => 0,
                'unknown_age'     => false,
            );
        }
        $uploaded = (int) ( $evidence['uploaded_at'] ?? 0 );
        return array(
            'protected_until' => $uploaded > 0 ? $uploaded + $days * DAY_IN_SECONDS : 0,
            'unknown_age'     => ! empty( $evidence['unknown_age'] ) || $uploaded <= 0,
        );
    }

    /**
     * Hold automatic removal for minimum age or required unfinished uploads.
     *
     * @param int[]    $snapshot_ids Whole retention unit.
     * @param int|null $now Evaluation instant.
     * @return bool
     */
    public static function holds_automatic_removal( array $snapshot_ids, ?int $now = null ): bool {
        $now = null === $now ? time() : $now;
        // Reused chain candidates can include a leaf already confirmed pruned.
        $snapshot_ids = array_values(
            array_filter(
                $snapshot_ids,
                static function ( $id ) {
                    $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $id );
                    return null === $snapshot || 'pruned' !== $snapshot->status;
                }
            )
        );
        $protection   = self::remote_protection( $snapshot_ids, $now );
        if ( $protection['unknown_age'] || $protection['protected_until'] > $now ) {
            return true;
        }
        foreach ( $snapshot_ids as $id ) {
            $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $id );
            if ( null !== $snapshot && self::task_removes_local_archives( (int) $snapshot->task_id ) && self::snapshot_requires_uploads( $snapshot ) && ! self::required_uploads_verified( (int) $id ) ) {
                return true;
            }
        }
        return false;
    }
}
