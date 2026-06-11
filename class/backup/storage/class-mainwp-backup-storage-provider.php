<?php
/**
 * MainWP Backup Storage Provider interface.
 *
 * The contract every storage destination implements. Methods return
 * definitive outcomes (present/absent, success) and throw
 * \RuntimeException for transport or service errors, carrying the HTTP
 * status as the exception code (0 for network-level failures) so the
 * upload driver can tell retryable trouble from rejected credentials.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface MainWP_Backup_Storage_Provider
 *
 * @package MainWP\Dashboard
 */
interface MainWP_Backup_Storage_Provider {

    /**
     * Store an object.
     *
     * @param string $key          Object key, forward-slash separated.
     * @param string $body         Object bytes.
     * @param string $content_type MIME type.
     *
     * @return bool True on success.
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ): bool;

    /**
     * Check whether an object exists.
     *
     * @param string $key Object key.
     *
     * @return array|false {size: int} when present, false when absent.
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function head_object( string $key );

    /**
     * Download an object to a local file, streamed.
     *
     * @param string $key        Object key.
     * @param string $local_path Local file path to write.
     *
     * @return bool True on success.
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function get_object( string $key, string $local_path ): bool;

    /**
     * Delete an object. Deleting a missing object succeeds.
     *
     * @param string $key Object key.
     *
     * @return bool True on success.
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function delete_object( string $key ): bool;

    /**
     * List objects under a prefix, one page at a time.
     *
     * @param string      $prefix Key prefix filter.
     * @param string|null $token  Continuation token from the previous page.
     *
     * @return array {objects: array<{key, size}>, next_token: ?string, truncated: bool}.
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function list_objects( string $prefix = '', ?string $token = null ): array;

    /**
     * Prove the destination is reachable and writable before saving it.
     *
     * @return array {ok: bool, error: ?string}.
     */
    public function connection_test(): array;
}
