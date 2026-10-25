<?php
/**
 * MainWP Backup Retention Policy.
 *
 * Pure policy evaluation for backup pruning. The unit of retention is a
 * restorable unit: an incremental chain (base full plus its descendants)
 * or a standalone full or db snapshot. A chain prunes only as a whole, so
 * a base can never be deleted out from under its children. Constraints
 * evaluate age first, then count, then the storage cap, oldest first
 * within each pass. The newest complete unit and the newest restorable
 * unit always survive, so unavailable archives cannot displace the site's
 * remaining coverage.
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
     * is_complete, is_restorable, size_bytes, newest_created and oldest_created.
     * Incomplete units are never candidates and never count as a
     * protected backup. Complete units without restorable artifacts
     * remain candidates but supply no keep-count coverage.
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

        usort(
            $complete,
            static function ( $a, $b ) {
                return $b['newest_created'] <=> $a['newest_created'];
            }
        );

        // The newest complete unit stays protected even when it is not restorable.
        // Restorability can change between one pass's evaluation and its deletes
        // (a cloud copy finishes uploading), and two overlapping passes that each
        // protected only their own newest restorable unit could delete each other's.
        // The newest complete unit only changes when a newer backup lands, so every
        // pass agrees on it.
        $protected = array( 0 => true );
        foreach ( $complete as $index => $unit ) {
            if ( $unit['is_restorable'] ?? true ) {
                $protected[ $index ] = true;
                break;
            }
        }

        foreach ( $complete as $index => $unit ) {
            if ( ! empty( $unit['unknown_age'] ) || ! empty( $unit['required_upload_hold'] ) || (int) ( $unit['protected_until'] ?? 0 ) > $now ) {
                $protected[ $index ] = true;
            }
        }

        $reasons = array();

        if ( $this->retention_days > 0 ) {
            $cutoff = $now - $this->retention_days * DAY_IN_SECONDS;
            foreach ( $complete as $index => $unit ) {
                if ( ! isset( $protected[ $index ] ) && $unit['newest_created'] < $cutoff ) {
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
                if ( $kept >= $this->retention_count && ! isset( $protected[ $index ] ) ) {
                    $reasons[ $index ] = 'count';
                } elseif ( $unit['is_restorable'] ?? true ) {
                    ++$kept;
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
                if ( isset( $reasons[ $index ] ) || isset( $protected[ $index ] ) ) {
                    continue;
                }
                $reasons[ $index ] = 'storage_cap';
                $total            -= (int) $complete[ $index ]['size_bytes'];
            }
        }

        $reasons = array_diff_key( $reasons, $protected );

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
