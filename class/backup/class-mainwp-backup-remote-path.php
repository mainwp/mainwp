<?php
/**
 * Remote snapshot paths, bound to the owning site and upload checkpoint.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Remote folders retain their upload-time name across site renames.
 */
class MainWP_Backup_Remote_Path {

    /**
     * Build a folder for a new upload. The ID disambiguates duplicate names.
     *
     * @param object $snapshot Snapshot row.
     * @return string Snapshot prefix.
     */
    public static function for_new_upload( object $snapshot ): string {
        $site_id = (int) ( $snapshot->site_id ?? 0 );
        $site    = MainWP_DB::instance()->get_website_by_id( $site_id );
        $name    = null !== $site && ! empty( $site->name ) ? (string) $site->name : '';
        $slug    = sanitize_title( $name );
        // WordPress percent-encodes non-Latin names; keep the folder ASCII.
        $slug = preg_replace( '/%[a-f0-9]{2}/i', '', $slug );
        $slug = trim( substr( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $slug ) ), 0, 80 ), '-' );
        return $site_id . '-' . ( '' === $slug ? 'site' : $slug ) . '/' . (int) ( $snapshot->id ?? 0 ) . '/';
    }

    /**
     * Resolve an upload's path without consulting the current display name.
     * Existing checkpoints have no prefix and continue using numeric folders.
     *
     * @param object $snapshot Snapshot row.
     * @param array  $checkpoint Upload checkpoint.
     * @return string Snapshot prefix.
     * @throws \RuntimeException When a recorded prefix escapes its owner.
     */
    public static function for_upload( object $snapshot, array $checkpoint ): string {
        $site_id     = (int) $snapshot->site_id;
        $snapshot_id = (int) $snapshot->id;
        $legacy      = $site_id . '/' . $snapshot_id . '/';
        if ( ! array_key_exists( 'remote_prefix', $checkpoint ) ) {
            return $legacy;
        }
        $prefix = $checkpoint['remote_prefix'];
        if ( ! is_string( $prefix ) || ( $legacy !== $prefix && 1 !== preg_match( '/^' . $site_id . '-[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?\/' . $snapshot_id . '\/$/D', $prefix ) ) ) {
            throw new \RuntimeException( 'Invalid remote backup folder.' );
        }
        return $prefix;
    }
}
