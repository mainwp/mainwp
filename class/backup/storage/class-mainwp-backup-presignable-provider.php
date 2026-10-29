<?php
/**
 * MainWP Backup Presignable Provider interface.
 *
 * A storage provider that can issue time-limited, query-authenticated
 * GET URLs for its objects. Restore transfers use this to let a child
 * site pull artifacts straight from cloud storage instead of routing
 * the bytes through the dashboard. Providers without an offline URL
 * authentication scheme (Local, SFTP, Dropbox) simply do not implement
 * it, and restores for their destinations always take the staged
 * transfer path.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface MainWP_Backup_Presignable_Provider {

    /**
     * Whether the configured destination can issue presigned GET URLs.
     *
     * @return bool
     */
    public function supports_presigned_get(): bool;

    /**
     * A presigned GET URL for an object.
     *
     * @param string $key Object key.
     * @param int    $ttl Lifetime in seconds.
     *
     * @return string
     */
    public function presigned_get_url( string $key, int $ttl ): string;

    /**
     * The host every presigned URL for this destination resolves to,
     * accounting for the provider's addressing style (virtual-hosted
     * {bucket}.{endpoint} vs path-style {endpoint}/{bucket}). The
     * restore transfer driver asserts each presigned URL points at this
     * host before handing the manifest to a child.
     *
     * @return string
     */
    public function object_host(): string;
}
