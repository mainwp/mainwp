<?php
/**
 * MainWP Backup Storage S3 Provider.
 *
 * S3-compatible object storage over the WP HTTP API with SigV4 signing.
 * A custom endpoint setting covers S3-compatible services; path-style
 * addressing covers self-hosted endpoints that do not resolve bucket
 * subdomains. Every method throws \RuntimeException on transport or
 * service errors with the HTTP status as the code (0 for network
 * failures), so the upload driver can map 401/403 to a re-enter
 * credentials state and everything else to retry.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_S3
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_S3 implements MainWP_Backup_Storage_Provider, MainWP_Backup_Presignable_Provider { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Default AWS endpoint.
     *
     * @var string
     */
    const DEFAULT_ENDPOINT = 's3.amazonaws.com';

    /**
     * Provider settings.
     *
     * @var array
     */
    private $settings;

    /**
     * HTTP transport callable: fn(string $url, array $args): array|\WP_Error.
     *
     * @var callable
     */
    private $transport;

    /**
     * SigV4 signer.
     *
     * @var MainWP_Backup_S3_Signer
     */
    private $signer;

    /**
     * Hosts whose SSRF re-check has already run this operation, so a chunked
     * upload does not repeat the DNS lookup on every request.
     *
     * @var array<string,bool>
     */
    private $ssrf_checked_hosts = array();

    /**
     * Constructor.
     *
     * @param array                        $settings  Provider settings: bucket, region, access_key, secret_key, endpoint, path_style.
     * @param callable|null                $transport Transport override for tests.
     * @param MainWP_Backup_S3_Signer|null $signer    Signer override for tests.
     */
    public function __construct( array $settings, $transport = null, ?MainWP_Backup_S3_Signer $signer = null ) {
        $this->settings  = $settings;
        $this->transport = is_callable( $transport ) ? $transport : array( static::class, 'default_transport' );
        $this->signer    = $signer ? $signer : new MainWP_Backup_S3_Signer();
    }

    /**
     * Default transport over the WP HTTP API.
     *
     * @param string $url  Request URL.
     * @param array  $args Request args.
     *
     * @return array|\WP_Error
     */
    public static function default_transport( $url, $args ) {
        return wp_remote_request( $url, $args );
    }

    /**
     * Store an object.
     *
     * @param string $key          Object key.
     * @param string $body         Object bytes.
     * @param string $content_type MIME type.
     *
     * @return bool
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ): bool {
        $headers = array( 'Content-Type' => $content_type );

        // Optional bucket-side encryption, opt-in per destination and off by
        // default so S3-compatible endpoints that reject the header (MinIO in
        // its default mode, GCS over the S3 API) are never sent it. The header
        // joins the signed set, as AWS requires for x-amz-* headers.
        $sse = isset( $this->settings['sse'] ) ? (string) $this->settings['sse'] : '';
        if ( '' !== $sse ) {
            $headers['x-amz-server-side-encryption'] = $sse;
        }

        $response = $this->request( 'PUT', $this->object_url( $key ), $headers, $body );

        $this->expect_status( $response, array( 200 ), 'PUT ' . $key );

        return true;
    }

    /**
     * Check whether an object exists.
     *
     * @param string $key Object key.
     *
     * @return array|false
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function head_object( string $key ) {
        $response = $this->request( 'HEAD', $this->object_url( $key ) );

        $code = $this->status( $response );
        if ( 404 === $code ) {
            return false;
        }

        $this->expect_status( $response, array( 200 ), 'HEAD ' . $key );

        $length = wp_remote_retrieve_header( $response, 'content-length' );

        return array( 'size' => (int) $length );
    }

    /**
     * Download an object to a local file, streamed.
     *
     * @param string $key        Object key.
     * @param string $local_path Target file path.
     *
     * @return bool
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function get_object( string $key, string $local_path ): bool {
        $response = $this->request(
            'GET',
            $this->object_url( $key ),
            array(),
            null,
            array(
                'stream'   => true,
                'filename' => $local_path,
            )
        );

        $this->expect_status( $response, array( 200 ), 'GET ' . $key );

        return true;
    }

    /**
     * Delete an object; a missing object counts as deleted.
     *
     * @param string $key Object key.
     *
     * @return bool
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function delete_object( string $key ): bool {
        $response = $this->request( 'DELETE', $this->object_url( $key ) );

        $this->expect_status( $response, array( 200, 204, 404 ), 'DELETE ' . $key );

        return true;
    }

    /**
     * List one page of objects under a prefix.
     *
     * @param string      $prefix Key prefix filter.
     * @param string|null $token  Continuation token from the previous page.
     *
     * @return array
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function list_objects( string $prefix = '', ?string $token = null ): array {
        $query = array(
            'list-type' => '2',
            'max-keys'  => '1000',
        );
        if ( '' !== $prefix ) {
            $query['prefix'] = $prefix;
        }
        if ( null !== $token && '' !== $token ) {
            $query['continuation-token'] = $token;
        }

        $response = $this->request( 'GET', $this->bucket_url() . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );

        $this->expect_status( $response, array( 200 ), 'LIST ' . $prefix );

        return static::parse_list_response( (string) wp_remote_retrieve_body( $response ) );
    }

    /**
     * Prove the credentials and bucket work: HEAD the bucket, write a
     * probe object, verify it, delete it.
     *
     * @return array
     *
     * @throws \RuntimeException Internally on a missing probe; always caught and reported in the result.
     */
    public function connection_test(): array {
        $probe = '.mainwp-probe-' . uniqid() . '.txt';

        try {
            $response = $this->request( 'HEAD', $this->bucket_url() );
            $this->expect_status( $response, array( 200 ), 'HEAD bucket' );

            $this->put_object( $probe, 'mainwp-connection-test' );

            if ( false === $this->head_object( $probe ) ) {
                throw new \RuntimeException( 'Probe object missing after upload.', 0 );
            }

            $this->delete_object( $probe );
        } catch ( \RuntimeException $e ) {
            return array(
                'ok'    => false,
                'error' => $e->getMessage() . ( $e->getCode() ? ' (HTTP ' . $e->getCode() . ')' : '' ),
            );
        }

        return array(
            'ok'    => true,
            'error' => null,
        );
    }

    /**
     * Whether this destination can issue presigned GET URLs: it needs
     * the full credential set for query-parameter authentication.
     *
     * @return bool
     */
    public function supports_presigned_get(): bool {
        foreach ( array( 'bucket', 'region', 'access_key', 'secret_key' ) as $field ) {
            if ( empty( $this->settings[ $field ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * A presigned GET URL for an object, computed offline from the
     * stored credentials.
     *
     * @param string $key Object key.
     * @param int    $ttl Lifetime in seconds.
     *
     * @return string
     */
    public function presigned_get_url( string $key, int $ttl ): string {
        return $this->signer->presign(
            $this->object_url( $key ),
            isset( $this->settings['region'] ) ? (string) $this->settings['region'] : 'us-east-1',
            isset( $this->settings['access_key'] ) ? (string) $this->settings['access_key'] : '',
            isset( $this->settings['secret_key'] ) ? (string) $this->settings['secret_key'] : '',
            $ttl
        );
    }

    /**
     * Send a signed request through the transport, following at most one
     * redirect hop of its own accord.
     *
     * WP's HTTP layer is told never to follow redirects itself (see
     * dispatch()): a 3xx response is instead followed here, one hop only,
     * against a Location that is re-guarded and re-signed as its own
     * request. This closes the gap where a compromised or malicious
     * S3-compatible endpoint could 3xx a signed request onward to a
     * link-local / cloud-metadata address without the SSRF guard ever
     * seeing that target. A second consecutive 3xx is returned as-is and
     * rejected by the caller's expect_status().
     *
     * @param string      $method     HTTP method.
     * @param string      $url        Request URL.
     * @param array       $headers    Extra headers to sign and send.
     * @param string|null $body       Request body.
     * @param array       $extra_args Additional WP HTTP args (stream, filename).
     *
     * @return array Transport response.
     *
     * @throws \RuntimeException When the transport fails, or a redirect target is unsafe.
     */
    private function request( string $method, string $url, array $headers = array(), ?string $body = null, array $extra_args = array() ) {
        $response = $this->dispatch( $method, $url, $headers, $body, $extra_args );

        $code = $this->status( $response );
        if ( $code >= 300 && $code < 400 ) {
            $location = (string) wp_remote_retrieve_header( $response, 'location' );
            if ( '' !== $location ) {
                $redirect_url = \WP_Http::make_absolute_url( $location, $url );
                $response     = $this->dispatch( $method, $redirect_url, $headers, $body, $extra_args );
            }
        }

        return $response;
    }

    /**
     * Guard, sign, and issue a single request through the transport.
     *
     * @param string      $method     HTTP method.
     * @param string      $url        Request URL.
     * @param array       $headers    Extra headers to sign and send.
     * @param string|null $body       Request body.
     * @param array       $extra_args Additional WP HTTP args (stream, filename).
     *
     * @return array Transport response.
     *
     * @throws \RuntimeException When the host is unsafe or the transport itself fails.
     */
    private function dispatch( string $method, string $url, array $headers, ?string $body, array $extra_args ) {
        $this->guard_outbound_host( $url );

        $payload_hash = hash( 'sha256', null === $body ? '' : $body );

        $signed = $this->signer->sign(
            $method,
            $url,
            isset( $this->settings['region'] ) ? (string) $this->settings['region'] : 'us-east-1',
            isset( $this->settings['access_key'] ) ? (string) $this->settings['access_key'] : '',
            isset( $this->settings['secret_key'] ) ? (string) $this->settings['secret_key'] : '',
            $headers,
            $payload_hash
        );

        $args = array_merge(
            array(
                'method'      => $method,
                'headers'     => $signed,
                'timeout'     => 120,
                'redirection' => 0,
            ),
            $extra_args
        );

        if ( null !== $body ) {
            $args['body'] = $body;
        }

        $response = call_user_func( $this->transport, $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Storage request failed: ' . esc_html( $response->get_error_message() ), 0 );
        }

        return $response;
    }

    /**
     * Re-check the request host right before connecting so a DNS rebind cannot
     * redirect the transfer to a link-local / cloud-metadata address. The
     * result is cached per host for the operation's lifetime, since a chunked
     * upload hits the same host many times.
     *
     * @param string $url Request URL.
     *
     * @return void
     *
     * @throws \RuntimeException When the host resolves to a blocked address.
     */
    private function guard_outbound_host( string $url ): void {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( '' === $host || isset( $this->ssrf_checked_hosts[ $host ] ) ) {
            return;
        }
        if ( ! MainWP_Backup_Storage_Destinations::is_safe_host( $host ) ) {
            throw new \RuntimeException( 'Storage destination host resolves to a link-local or cloud-metadata address: ' . esc_html( $host ), 0 );
        }
        $this->ssrf_checked_hosts[ $host ] = true;
    }

    /**
     * The HTTP status of a transport response.
     *
     * @param array $response Transport response.
     *
     * @return int
     */
    private function status( $response ): int {
        return (int) wp_remote_retrieve_response_code( $response );
    }

    /**
     * Throw unless the response status is one of the accepted codes.
     *
     * @param array  $response Transport response.
     * @param array  $accepted Accepted status codes.
     * @param string $context  Operation description for the error message.
     *
     * @return void
     *
     * @throws \RuntimeException With the HTTP status as the exception code.
     */
    private function expect_status( $response, array $accepted, string $context ): void {
        $code = $this->status( $response );
        if ( ! in_array( $code, $accepted, true ) ) {
            throw new \RuntimeException( esc_html( 'Storage request ' . $context . ' returned HTTP ' . $code . '.' ), (int) $code );
        }
    }

    /**
     * The host every presigned URL for this destination resolves to. It
     * is derived from the same bucket_url() the presigned URLs are built
     * from, so virtual-hosted ({bucket}.{endpoint}) and path-style
     * ({endpoint}/{bucket}) destinations both report the correct host.
     *
     * @return string
     */
    public function object_host(): string {
        $host = wp_parse_url( $this->bucket_url(), PHP_URL_HOST );
        return is_string( $host ) ? $host : '';
    }

    /**
     * The URL of the bucket root, per addressing style.
     *
     * @return string
     */
    private function bucket_url(): string {
        $endpoint = isset( $this->settings['endpoint'] ) && '' !== $this->settings['endpoint'] ? (string) $this->settings['endpoint'] : static::DEFAULT_ENDPOINT;
        if ( false === strpos( $endpoint, '://' ) ) {
            $endpoint = 'https://' . $endpoint;
        }
        $endpoint = rtrim( $endpoint, '/' );

        $bucket = isset( $this->settings['bucket'] ) ? (string) $this->settings['bucket'] : '';

        if ( ! empty( $this->settings['path_style'] ) ) {
            return $endpoint . '/' . $bucket;
        }

        $parts = wp_parse_url( $endpoint );
        return $parts['scheme'] . '://' . $bucket . '.' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
    }

    /**
     * The URL of an object, with each key segment encoded.
     *
     * @param string $key Object key.
     *
     * @return string
     */
    private function object_url( string $key ): string {
        $segments = array_map( 'rawurlencode', explode( '/', $key ) );
        return $this->bucket_url() . '/' . implode( '/', $segments );
    }

    /**
     * Parse a ListObjectsV2 XML response.
     *
     * @param string $xml Response body.
     *
     * @return array {objects, next_token, truncated}.
     *
     * @throws \RuntimeException When the body is not parseable XML.
     */
    private static function parse_list_response( string $xml ): array {
        $previous = libxml_use_internal_errors( true );
        $parsed   = simplexml_load_string( $xml );
        libxml_use_internal_errors( $previous );

        if ( false === $parsed ) {
            throw new \RuntimeException( 'Storage list response was not valid XML.', 0 );
        }

        $objects = array();
        if ( isset( $parsed->Contents ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML schema.
            foreach ( $parsed->Contents as $entry ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML schema.
                $objects[] = array(
                    'key'  => (string) $entry->Key, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML schema.
                    'size' => (int) $entry->Size, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML schema.
                );
            }
        }

        $truncated = isset( $parsed->IsTruncated ) && 'true' === (string) $parsed->IsTruncated; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML schema.
        $token     = isset( $parsed->NextContinuationToken ) ? (string) $parsed->NextContinuationToken : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML schema.

        return array(
            'objects'    => $objects,
            'next_token' => '' !== $token ? $token : null,
            'truncated'  => $truncated,
        );
    }
}
