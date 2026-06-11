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
        $entries = array();
        foreach ( MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) as $snapshot ) {
            $source    = $this->snapshot_source( $snapshot );
            $entries[] = array(
                'snapshot'   => $snapshot,
                'source'     => $source,
                'restorable' => 'unavailable' !== $source,
            );
        }
        return $entries;
    }

    /**
     * Resolve a snapshot into a restorable chain.
     *
     * @param int $snapshot_id Snapshot to restore.
     *
     * @return array { ok: bool, error: string|null, chain: object[],
     *                 sub_type: string, plan: array[] } - plan entries:
     *                 { role, file, size, sha256, snapshot_id, source,
     *                 local_path, remote_key, adopt_only }.
     */
    public function resolve_restorable_chain( int $snapshot_id ): array {
        $snapshots = MainWP_Backup_Snapshots::instance();
        $leaf      = $snapshots->get_snapshot_by_id( $snapshot_id );
        if ( null === $leaf ) {
            return $this->refusal( __( 'The selected backup no longer exists.', 'mainwp' ) );
        }
        if ( 'complete' !== $leaf->status ) {
            return $this->refusal( __( 'The selected backup was removed by retention and can no longer be restored.', 'mainwp' ) );
        }

        $chain = $snapshots->get_chain( $snapshot_id );
        if ( empty( $chain ) ) {
            return $this->refusal( __( 'The selected backup has no restorable data.', 'mainwp' ) );
        }

        // An incremental chain must reach a complete full base.
        if ( 'incremental' === $leaf->type && 'full' !== $chain[0]->type ) {
            return $this->refusal( __( 'The selected backup belongs to an incremental chain whose base full backup is missing.', 'mainwp' ) );
        }
        foreach ( $chain as $link ) {
            if ( 'complete' !== $link->status ) {
                return $this->refusal( __( 'Part of the selected backup chain was removed by retention, so this point in time can no longer be restored.', 'mainwp' ) );
            }
        }

        $plan = $this->assemble_plan( $chain );
        if ( empty( $plan ) ) {
            return $this->refusal( __( 'No artifacts for the selected backup are available locally or in cloud storage.', 'mainwp' ) );
        }
        foreach ( $plan as $entry ) {
            if ( 'unavailable' === $entry['source'] ) {
                return $this->refusal(
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

        return array(
            'ok'       => true,
            'error'    => null,
            'chain'    => $chain,
            'sub_type' => $has_files ? 'full' : 'db',
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
        $file_ops = array();
        $db_entry = null;

        foreach ( $chain as $snapshot ) {
            $remote = $this->remote_artifact_index( $snapshot );

            foreach ( (array) $snapshot->archives as $archive ) {
                $role = isset( $archive['role'] ) ? (string) $archive['role'] : '';
                if ( ! in_array( $role, array( 'full', 'part', 'tombstones', 'db' ), true ) ) {
                    continue; // Manifest sidecars do not participate in a restore.
                }

                $entry = $this->plan_entry( $snapshot, $archive, $remote );
                if ( 'db' === $role ) {
                    $db_entry = $entry; // Chain-newest dump wins.
                } else {
                    $file_ops[] = $entry;
                }
            }
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
        $has_local  = '' !== $local_path && file_exists( $local_path );

        $source     = 'unavailable';
        $remote_key = '';
        if ( $has_local ) {
            $source = 'local';
        } elseif ( isset( $remote[ $file ] ) ) {
            $source     = $remote[ $file ]['chunked'] ? 'remote_chunked' : 'remote';
            $remote_key = $remote[ $file ]['key'];
        }

        return array(
            'role'        => (string) $archive['role'],
            'file'        => $file,
            'size'        => isset( $archive['size'] ) ? (int) $archive['size'] : 0,
            'sha256'      => '',
            'snapshot_id' => (int) $snapshot->id,
            'source'      => $source,
            'local_path'  => $has_local ? $local_path : '',
            'remote_key'  => $remote_key,
            'adopt_only'  => false,
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
     * @return array<string,array{key:string,chunked:bool,destination_id:int}> By basename.
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
                if ( empty( $artifact['file'] ) || isset( $index[ $artifact['file'] ] ) ) {
                    continue;
                }
                $chunked                    = isset( $artifact['mode'] ) && 'chunked' === $artifact['mode'];
                $index[ $artifact['file'] ] = array(
                    'key'            => $snapshot->site_id . '/' . $snapshot->id . '/files/' . $artifact['file'],
                    'chunked'        => $chunked,
                    'destination_id' => (int) $job->destination_id,
                );
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
            if ( empty( $archive['path'] ) || ! file_exists( $archive['path'] ) ) {
                $all_local = false;
                break;
            }
        }
        if ( $all_local ) {
            return 'local';
        }

        $remote      = $this->remote_artifact_index( $snapshot );
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
     * @param string $message Reason.
     *
     * @return array Refusal.
     */
    private function refusal( string $message ): array {
        return array(
            'ok'       => false,
            'error'    => $message,
            'chain'    => array(),
            'sub_type' => 'full',
            'plan'     => array(),
        );
    }
}
