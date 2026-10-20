<?php
/**
 * MainWP Backup Chunk Transfer Sizes.
 *
 * One set of chunk-size constants for every dashboard-to-child chunk
 * sender (restore artifact transfer, staged manifest push). The senders
 * share the same adaptive behavior: start at CHUNK_BYTES and halve on a
 * transport failure down to CHUNK_MIN_BYTES before giving up, because
 * an oversized-request failure is a capacity signal from a proxy or
 * request-body cap, not a fault.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface MainWP_Backup_Chunk_Transfer_Sizes
 *
 * @package MainWP/Dashboard
 */
interface MainWP_Backup_Chunk_Transfer_Sizes {

    /**
     * Starting raw bytes per chunk (~4 MiB of base64 on the wire).
     */
    const CHUNK_BYTES = 3145728;

    /**
     * Smallest chunk size the adaptive shrink will try.
     */
    const CHUNK_MIN_BYTES = 262144;

    /**
     * Largest chunk size a size override is allowed to request. Caps a
     * filtered value so a stray override cannot exhaust memory on the
     * dashboard or overrun the child's request-body limit.
     */
    const CHUNK_MAX_BYTES = 67108864;
}
