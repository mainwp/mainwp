<?php
/**
 * MainWP Backup Snapshot Catalog - the restore-facing view of snapshots.
 *
 * Snapshot rows are the restore catalog. The catalog resolves a chosen
 * snapshot into its parent chain, decides where each artifact can come
 * from (local disk, a completed cloud upload, or nowhere - pruned), and
 * assembles the ordered operation list a restore applies: the base
 * full's archives first, each increment's parts and tombstones in chain
 * order, and the chain-newest database dump last (every incremental
 * carries a fresh dump, so the newest one wins).
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Snapshot_Catalog
 */
class MainWP_Backup_Snapshot_Catalog { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Snapshot_Catalog|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Snapshot_Catalog
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * List a site's snapshots with restore availability annotations.
     *
     * @param int $site_id Child site id.
     *
     * @return array[] Entries: { snapshot: object, source: string,
     *                 restorable: bool } where source is
     *                 local|remote|remote_chunked|unavailable.
     */
    public function list_snapshots_for_site( int $site_id ): array {
        $files_restorable = $this->site_supports_restore_files( $site_id );
        $multisite_gated  = $this->site_is_multisite( $site_id ) && ! $this->site_supports_restore_multisite( $site_id );

        $removal_tooltips = array(
            MainWP_Backup_Snapshots::REMOVAL_REASON_MANUAL => __( 'Deleted manually; no copy survives locally or in cloud storage.', 'mainwp' ),
            MainWP_Backup_Snapshots::REMOVAL_REASON_RETENTION => __( 'Removed by retention; no copy survives locally or in cloud storage.', 'mainwp' ),
            MainWP_Backup_Snapshots::REMOVAL_REASON_TASK_DELETED => __( 'Deleted with its backup task; no copy survives locally or in cloud storage.', 'mainwp' ),
            MainWP_Backup_Snapshots::REMOVAL_REASON_CLEANUP => __( 'Removed by Clean Up; no copy survives locally or in cloud storage.', 'mainwp' ),
        );
        $entries          = array();
        foreach ( MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) as $snapshot ) {
            $source         = $this->snapshot_source( $snapshot );
            $damaged        = 'complete' === $snapshot->status && MainWP_Backup_Snapshots::snapshot_has_damage( $snapshot );
            $removal_reason = 'pruned' === $snapshot->status && isset( $removal_tooltips[ $snapshot->removal_reason ?? '' ] )
                ? (string) $snapshot->removal_reason : MainWP_Backup_Snapshots::REMOVAL_REASON_UNKNOWN;

            $reason = '';
            if ( 'unavailable' === $source ) {
                if ( 'complete' === $snapshot->status && $damaged ) {
                    $reason = wp_specialchars_decode( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_DAMAGED ), ENT_QUOTES );
                } elseif ( 'complete' === $snapshot->status ) {
                    $reason = __( 'The backup files are missing from this Dashboard and no cloud copy finished uploading, so it cannot be restored.', 'mainwp' );
                } elseif ( 'pruned' === $snapshot->status && MainWP_Backup_Snapshots::REMOVAL_REASON_UNKNOWN === $removal_reason ) {
                    // Pruned before the cause was recorded (DB 9.0.2.32), so the retention default would be a guess.
                    $reason = __( 'Removed; no copy survives locally or in cloud storage.', 'mainwp' );
                } else {
                    $reason = $removal_tooltips[ $removal_reason ] ?? $removal_tooltips[ MainWP_Backup_Snapshots::REMOVAL_REASON_RETENTION ];
                }
            } elseif ( 'files' === $snapshot->type && ! $files_restorable ) {
                $reason = __( 'Update the MainWP Child plugin on the site to restore files-only backups.', 'mainwp' );
            } elseif ( $multisite_gated ) {
                $reason = __( 'This site runs WordPress Multisite. Update the MainWP Child plugin to enable network-wide restore.', 'mainwp' );
            }

            $entries[] = array(
                'snapshot'       => $snapshot,
                'source'         => $source,
                'damaged'        => $damaged,
                'restorable'     => '' === $reason,
                'restore_reason' => $reason,
                'removal_reason' => $removal_reason,
            );
        }
        $by_id = array();
        foreach ( $entries as $entry ) {
            $by_id[ (int) $entry['snapshot']->id ] = $entry;
        }
        foreach ( $entries as $index => $entry ) {
            if ( 'incremental' !== $entry['snapshot']->type || '' !== $entry['restore_reason'] ) {
                continue;
            }
            $parent_id = (int) $entry['snapshot']->parent_id;
            $seen      = array( (int) $entry['snapshot']->id => true );
            while ( isset( $by_id[ $parent_id ] ) && ! isset( $seen[ $parent_id ] ) ) {
                $seen[ $parent_id ] = true;
                $ancestor           = $by_id[ $parent_id ];
                if ( $ancestor['damaged'] && 'unavailable' === $ancestor['source'] ) {
                    $entries[ $index ]['restorable']     = false;
                    $entries[ $index ]['restore_reason'] = wp_specialchars_decode( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_DAMAGED ), ENT_QUOTES );
                    break;
                }
                $parent_id = (int) $ancestor['snapshot']->parent_id;
            }
        }
        return $entries;
    }

    /**
     * Whether a site's child advertised the files-only restore pipeline.
     *
     * @param int $site_id Child site id.
     *
     * @return bool True when files snapshots are restorable on the site.
     */
    private function site_supports_restore_files( int $site_id ): bool {
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        return ! empty( $website ) && MainWP_Backup_Monitor::child_supports_restore_files( $website );
    }

    /**
     * Whether a site's child reported running a multisite network.
     *
     * @param int $site_id Child site id.
     *
     * @return bool True when the child is a multisite install.
     */
    private function site_is_multisite( int $site_id ): bool {
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        return ! empty( $website ) && MainWP_Backup_Monitor::child_is_multisite( $website );
    }

    /**
     * Whether a site's child advertised whole-network restore support.
     *
     * @param int $site_id Child site id.
     *
     * @return bool True when the child restores multisite networks.
     */
    private function site_supports_restore_multisite( int $site_id ): bool {
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        return ! empty( $website ) && MainWP_Backup_Monitor::child_supports_restore_multisite( $website );
    }

    /**
     * Resolve a snapshot into a restorable chain.
     *
     * @param int $snapshot_id      Snapshot to restore.
     * @param int $expected_site_id Site the caller is authorized for. When
     *                              greater than zero, the snapshot must
     *                              belong to it; this stops a user scoped to
     *                              one site from restoring another site's
     *                              backup by passing its snapshot id.
     *
     * @return array { ok: bool, error: string|null, chain: object[],
     *                 sub_type: string, plan: array[] } - plan entries:
     *                 { role, file, size, sha256, snapshot_id, source,
     *                 local_path, remote_key, adopt_only }.
     */
    public function resolve_restorable_chain( int $snapshot_id, int $expected_site_id = 0 ): array {
        $snapshots = MainWP_Backup_Snapshots::instance();
        $leaf      = $snapshots->get_snapshot_by_id( $snapshot_id );
        if ( null === $leaf ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND, __( 'The selected backup no longer exists.', 'mainwp' ) );
        }
        if ( $expected_site_id > 0 && (int) $leaf->site_id !== $expected_site_id ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_WRONG_SITE, __( 'The selected backup does not belong to this site.', 'mainwp' ) );
        }
        if ( 'complete' !== $leaf->status ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_PRUNED, __( 'The selected backup was removed by retention and can no longer be restored.', 'mainwp' ) );
        }
        if ( 'files' === $leaf->type && ! $this->site_supports_restore_files( (int) $leaf->site_id ) ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_FILES_CHILD_OUTDATED, __( 'This backup contains the site\'s files without a database, and the site\'s MainWP Child plugin predates files-only restore. Update the child plugin to restore it, or download the archive to recover individual files.', 'mainwp' ) );
        }
        if ( $this->site_is_multisite( (int) $leaf->site_id ) && ! $this->site_supports_restore_multisite( (int) $leaf->site_id ) ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_MULTISITE_CHILD_OUTDATED, __( 'This site runs WordPress Multisite, and its MainWP Child plugin predates network-wide restore. Update the child plugin to restore this backup.', 'mainwp' ) );
        }

        $chain = $snapshots->get_chain( $snapshot_id );
        if ( empty( $chain ) ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_EMPTY, __( 'The selected backup has no restorable data.', 'mainwp' ) );
        }

        // An incremental chain must reach a complete full base.
        if ( 'incremental' === $leaf->type && 'full' !== $chain[0]->type ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_BASE_MISSING, __( 'The selected backup belongs to an incremental chain whose base full backup is missing.', 'mainwp' ) );
        }
        foreach ( $chain as $link ) {
            if ( 'complete' !== $link->status ) {
                return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_CHAIN_PRUNED, __( 'Part of the selected backup chain was removed by retention, so this point in time can no longer be restored.', 'mainwp' ) );
            }
        }

        $plan = $this->assemble_plan( $chain );
        if ( empty( $plan ) ) {
            return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_NO_ARTIFACTS, __( 'No artifacts for the selected backup are available locally or in cloud storage.', 'mainwp' ) );
        }
        foreach ( $plan as $entry ) {
            if ( 'unavailable' === $entry['source'] ) {
                if ( ! empty( $entry['damaged'] ) ) {
                    return $this->refusal( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_DAMAGED, wp_specialchars_decode( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_DAMAGED ), ENT_QUOTES ) );
                }
                return $this->refusal(
                    MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_ARTIFACT_UNAVAILABLE,
                    sprintf(
                        /* translators: %s: artifact file name. */
                        __( 'A required backup file is no longer available locally or in cloud storage: %s', 'mainwp' ),
                        $entry['file']
                    )
                );
            }
        }

        $has_files = false;
        foreach ( $plan as $entry ) {
            if ( in_array( $entry['role'], array( 'full', 'part' ), true ) ) {
                $has_files = true;
            }
        }

        // A files snapshot restores through the child's db-skipping
        // pipeline; its archive rides the full role, so the role-based
        // calculation below would mislabel it a full restore.
        $sub_type = $has_files ? 'full' : 'db';
        if ( 'files' === $leaf->type ) {
            $sub_type = 'files';
        }

        return array(
            'ok'       => true,
            'error'    => null,
            'chain'    => $chain,
            'sub_type' => $sub_type,
            'plan'     => $plan,
        );
    }

    /**
     * Build the ordered restore operation list for a chain.
     *
     * @param object[] $chain Snapshot rows, base first.
     *
     * @return array[] Plan entries in apply order.
     */
    public function assemble_plan( array $chain ): array {
        $file_ops       = array();
        $db_entry       = null;
        $manifest_entry = null;
        $leaf           = empty( $chain ) ? null : end( $chain );

        foreach ( $chain as $snapshot ) {
            $remote = $this->remote_artifact_index( $snapshot );

            foreach ( (array) $snapshot->archives as $archive ) {
                $role = isset( $archive['role'] ) ? (string) $archive['role'] : '';
                if ( ! in_array( $role, array( 'full', 'part', 'tombstones', 'db', 'manifest' ), true ) ) {
                    continue;
                }
                if ( 'manifest' === $role && ( (int) $snapshot->id !== (int) $leaf->id || ! isset( $archive['walk'] ) || ! is_array( $archive['walk'] ) ) ) {
                    continue; // Only the selected point in time can bound reconciliation.
                }

                $entry = $this->plan_entry( $snapshot, $archive, $remote );
                if ( 'db' === $role ) {
                    $db_entry = $entry; // Chain-newest dump wins.
                } elseif ( 'manifest' === $role ) {
                    $manifest_entry = $entry;
                    if ( isset( $archive['walk'] ) && is_array( $archive['walk'] ) ) {
                        $manifest_entry['walk'] = $archive['walk'];
                    }
                } else {
                    $file_ops[] = $entry;
                }
            }
        }

        $has_files = (bool) array_intersect( array( 'full', 'part' ), array_column( $file_ops, 'role' ) );
        if ( $has_files && null !== $manifest_entry ) {
            $file_ops[] = $manifest_entry;
        }
        if ( null !== $db_entry ) {
            $file_ops[] = $db_entry; // Files extract first; the dump replays last.
        }

        return $file_ops;
    }

    /**
     * Build one plan entry with its source resolution.
     *
     * @param object $snapshot Snapshot row.
     * @param array  $archive  Archive entry from the row.
     * @param array  $remote   Remote artifact index for the snapshot.
     *
     * @return array Plan entry.
     */
    private function plan_entry( object $snapshot, array $archive, array $remote ): array {
        $file       = isset( $archive['file'] ) ? (string) $archive['file'] : '';
        $local_path = isset( $archive['path'] ) ? (string) $archive['path'] : '';
        $flat_name  = basename( $file ) === $file;
        $damaged    = ! empty( $archive['damaged'] );
        $has_local  = ! $damaged && $flat_name && '' !== $local_path && file_exists( $local_path );

        $source          = 'unavailable';
        $remote_key      = '';
        $expected_sha256 = '';
        $candidates      = array();
        if ( $has_local ) {
            $source = 'local';
        } elseif ( $flat_name && isset( $remote[ $file ] ) ) {
            $source          = $remote[ $file ]['chunked'] ? 'remote_chunked' : 'remote';
            $remote_key      = $remote[ $file ]['key'];
            $expected_sha256 = isset( $remote[ $file ]['sha256'] ) ? (string) $remote[ $file ]['sha256'] : '';
            $candidates      = isset( $remote[ $file ]['candidates'] ) ? (array) $remote[ $file ]['candidates'] : array();
        }

        return array(
            'role'              => (string) $archive['role'],
            // The wire/staging name is namespaced by snapshot: every
            // increment in a chain carries the same sidecar basenames
            // (tombstones.list, partNNN), and colliding names would
            // silently lose all but the first copy.
            'file'              => 's' . (int) $snapshot->id . '-' . $file,
            'source_file'       => $file,
            'size'              => isset( $archive['size'] ) ? (int) $archive['size'] : 0,
            'recorded_size'     => $archive['size'] ?? null,
            'sha256'            => '',
            'recorded_sha256'   => array_key_exists( 'hash_skipped', $archive ) ? '' : (string) ( $archive['sha256'] ?? '' ),
            'damaged'           => $damaged,
            // Trusted backup-time hash for a remote artifact, empty when the
            // upload predates artifact hashing. The staging fallback verifies
            // a downloaded object against this when present.
            'expected_sha256'   => $expected_sha256,
            'snapshot_id'       => (int) $snapshot->id,
            'source'            => $source,
            'local_path'        => $has_local ? $local_path : '',
            'remote_key'        => $remote_key,
            // Every destination holding a completed copy, in job order.
            // Staging tries them in sequence, so a corrupt or missing
            // object at the primary fails over instead of failing the
            // restore.
            'remote_candidates' => $candidates,
            'adopt_only'        => false,
        );
    }

    /**
     * Index a snapshot's remotely stored artifacts from its completed
     * upload jobs.
     *
     * The upload checkpoint records each artifact's mode: whole files
     * live at {site}/{snapshot}/files/{basename}; chunked artifacts
     * exist only as content-addressed chunks plus the manifest.
     *
     * @param object $snapshot Snapshot row.
     *
     * @return array<string,array{key:string,chunked:bool,destination_id:int,sha256:string,candidates:array[]}> By basename.
     */
    private function remote_artifact_index( object $snapshot ): array {
        $index = array();
        $jobs  = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( (int) $snapshot->id );

        foreach ( $jobs as $job ) {
            if ( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE !== $job->status || ! is_array( $job->checkpoint ) ) {
                continue;
            }
            $artifacts = isset( $job->checkpoint['artifacts'] ) ? (array) $job->checkpoint['artifacts'] : array();
            foreach ( $artifacts as $artifact ) {
                if ( empty( $artifact['file'] ) ) {
                    continue;
                }
                $chunked   = isset( $artifact['mode'] ) && 'chunked' === $artifact['mode'];
                $candidate = array(
                    'key'            => $snapshot->site_id . '/' . $snapshot->id . '/files/' . $artifact['file'],
                    'chunked'        => $chunked,
                    'destination_id' => (int) $job->destination_id,
                    // Backup-time hash recorded at upload. Carried into the
                    // plan so the staging fallback can verify a downloaded
                    // cloud object instead of trusting whatever bytes arrive.
                    'sha256'         => isset( $artifact['sha256'] ) ? (string) $artifact['sha256'] : '',
                );

                // Every completed destination is retained as a failover
                // candidate, in job order; the first stays the primary the
                // top-level fields describe, so one corrupt or unavailable
                // destination cannot defeat a restore another replica could
                // serve.
                if ( isset( $index[ $artifact['file'] ] ) ) {
                    $index[ $artifact['file'] ]['candidates'][] = $candidate;
                    continue;
                }
                $primary                    = $candidate;
                $primary['candidates']      = array( $candidate );
                $index[ $artifact['file'] ] = $primary;
            }
        }

        return $index;
    }

    /**
     * Cheap source classification for the list view.
     *
     * @param object $snapshot Snapshot row.
     *
     * @return string local|remote|remote_chunked|unavailable.
     */
    private function snapshot_source( object $snapshot ): string {
        if ( 'complete' !== $snapshot->status ) {
            return 'unavailable';
        }

        $all_local = ! empty( $snapshot->archives );
        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['damaged'] ) || empty( $archive['path'] ) || ! file_exists( $archive['path'] ) ) {
                $all_local = false;
                break;
            }
        }
        if ( $all_local ) {
            return 'local';
        }

        $remote = $this->remote_artifact_index( $snapshot );
        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['damaged'] ) && ! isset( $remote[ $archive['file'] ?? '' ] ) ) {
                return 'unavailable';
            }
        }

        $has_remote  = ! empty( $remote );
        $has_chunked = false;
        foreach ( $remote as $info ) {
            if ( $info['chunked'] ) {
                $has_chunked = true;
            }
        }

        if ( $has_remote ) {
            return $has_chunked ? 'remote_chunked' : 'remote';
        }
        return 'unavailable';
    }

    /**
     * Build a refusal result.
     *
     * @param string $code Admission refusal code.
     * @param string $message Reason.
     *
     * @return array Refusal.
     */
    private function refusal( string $code, string $message ): array {
        return array(
            'ok'       => false,
            'code'     => $code,
            'error'    => $message,
            'chain'    => array(),
            'sub_type' => 'full',
            'plan'     => array(),
        );
    }
}
