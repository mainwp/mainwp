<?php
/**
 * MainWP Backup Retention Policy.
 *
 * Pure policy evaluation for backup pruning. The unit of retention is a
 * restorable unit: an incremental chain (base full plus its descendants)
 * or a standalone full or db snapshot. A chain prunes only as a whole, so
 * a base can never be deleted out from under its children. Constraints
 * evaluate age first, then count, then the storage cap, oldest first
 * within each pass, and the newest complete unit always survives - a
 * site keeps at least one restorable backup no matter how tight the
 * policy.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Retention_Policy
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Retention_Policy { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Restorable units to keep, 0 for no limit.
     *
     * @var int
     */
    private $retention_count;

    /**
     * Maximum unit age in days, 0 for no limit.
     *
     * @var int
     */
    private $retention_days;

    /**
     * Storage cap in megabytes across the pair's units, 0 for no limit.
     *
     * @var int
     */
    private $retention_storage_mb;

    /**
     * Constructor.
     *
     * @param int $retention_count      Units to keep, 0 = no limit.
     * @param int $retention_days       Maximum age in days, 0 = no limit.
     * @param int $retention_storage_mb Storage cap in MB, 0 = no limit.
     */
    public function __construct( int $retention_count, int $retention_days, int $retention_storage_mb ) {
        $this->retention_count      = max( 0, $retention_count );
        $this->retention_days       = max( 0, $retention_days );
        $this->retention_storage_mb = max( 0, $retention_storage_mb );
    }

    /**
     * Whether any constraint is configured.
     *
     * @return bool
     */
    public function has_policy(): bool {
        return $this->retention_count > 0 || $this->retention_days > 0 || $this->retention_storage_mb > 0;
    }

    /**
     * Evaluate the policy against one site and task pair's units.
     *
     * Each unit is an array with snapshots (rows, base first),
     * is_complete, size_bytes, newest_created and oldest_created.
     * Incomplete units are never candidates and never count as the
     * protected last backup.
     *
     * @param array $units Restorable units for one (site, task) pair.
     * @param int   $now   Evaluation instant, UTC timestamp.
     *
     * @return array Prune candidates, oldest first, each carrying
     *               snapshot_ids, reason (age|count|storage_cap),
     *               size_bytes and newest_created.
     */
    public function evaluate( array $units, int $now ): array {
        if ( ! $this->has_policy() ) {
            return array();
        }

        $complete = array_values(
            array_filter(
                $units,
                static function ( $unit ) {
                    return ! empty( $unit['is_complete'] );
                }
            )
        );

        if ( empty( $complete ) ) {
            return array();
        }

        // Newest first: position 0 is the protected last restorable backup.
        usort(
            $complete,
            static function ( $a, $b ) {
                return $b['newest_created'] <=> $a['newest_created'];
            }
        );

        $reasons = array();

        if ( $this->retention_days > 0 ) {
            $cutoff = $now - $this->retention_days * DAY_IN_SECONDS;
            foreach ( $complete as $index => $unit ) {
                if ( $unit['newest_created'] < $cutoff ) {
                    $reasons[ $index ] = 'age';
                }
            }
        }

        if ( $this->retention_count > 0 ) {
            $kept = 0;
            foreach ( $complete as $index => $unit ) {
                if ( isset( $reasons[ $index ] ) ) {
                    continue;
                }
                ++$kept;
                if ( $kept > $this->retention_count ) {
                    $reasons[ $index ] = 'count';
                }
            }
        }

        if ( $this->retention_storage_mb > 0 ) {
            $cap   = $this->retention_storage_mb * MB_IN_BYTES;
            $total = 0;
            foreach ( $complete as $index => $unit ) {
                if ( ! isset( $reasons[ $index ] ) ) {
                    $total += (int) $unit['size_bytes'];
                }
            }
            for ( $index = count( $complete ) - 1; $index >= 0 && $total > $cap; $index-- ) {
                if ( isset( $reasons[ $index ] ) ) {
                    continue;
                }
                $reasons[ $index ] = 'storage_cap';
                $total            -= (int) $complete[ $index ]['size_bytes'];
            }
        }

        // Never give up the last restorable backup.
        unset( $reasons[0] );

        krsort( $reasons );

        $candidates = array();
        foreach ( $reasons as $index => $reason ) {
            $unit         = $complete[ $index ];
            $candidates[] = array(
                'snapshot_ids'   => array_map(
                    static function ( $snapshot ) {
                        return (int) $snapshot->id;
                    },
                    $unit['snapshots']
                ),
                'reason'         => $reason,
                'size_bytes'     => (int) $unit['size_bytes'],
                'newest_created' => (int) $unit['newest_created'],
            );
        }

        return $candidates;
    }
}
