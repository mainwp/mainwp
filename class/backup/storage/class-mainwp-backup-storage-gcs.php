<?php
/**
 * MainWP Backup Storage Google Cloud Storage Provider.
 *
 * Google Cloud Storage through its S3-interoperable XML API with HMAC
 * keys, reusing the SigV4 request behavior of the S3 provider. The
 * endpoint is pinned to storage.googleapis.com so stored settings can
 * never redirect traffic elsewhere, and addressing is always path-style
 * so dotted bucket names never produce a host that fails TLS
 * verification. GCS validates SigV4 credentials against any region
 * scope, so signing defaults to us-east1; a region setting overrides it
 * for installations that prefer a matching scope.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_GCS
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_GCS extends MainWP_Backup_Storage_S3 { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * The pinned GCS XML API endpoint.
     *
     * @var string
     */
    const GCS_ENDPOINT = 'storage.googleapis.com';

    /**
     * Default SigV4 signing region; GCS accepts it for buckets in any
     * location.
     *
     * @var string
     */
    const DEFAULT_REGION = 'us-east1';

    /**
     * Constructor.
     *
     * @param array                        $settings  Provider settings: bucket, access_key, secret_key, region (optional).
     * @param callable|null                $transport Transport override for tests.
     * @param MainWP_Backup_S3_Signer|null $signer    Signer override for tests.
     */
    public function __construct( array $settings, $transport = null, ?MainWP_Backup_S3_Signer $signer = null ) {
        $settings['endpoint']   = static::GCS_ENDPOINT;
        $settings['path_style'] = 1;
        if ( empty( $settings['region'] ) ) {
            $settings['region'] = static::DEFAULT_REGION;
        }
        parent::__construct( $settings, $transport, $signer );
    }
}
