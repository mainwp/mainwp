<?php
/**
 * MainWP Backup Storage Dropbox Provider.
 *
 * Dropbox API v2 over the WP HTTP API using the OAuth refresh-token
 * flow: Dropbox apps issue four-hour access tokens, and uploads run
 * from cron, so the destination stores the app key, app secret, and a
 * refresh token, and the provider mints access tokens itself. Minted
 * tokens stay on the provider instance for the request and re-mint
 * once on a 401. Uploads and downloads use the content endpoint with
 * the path in the Dropbox-API-Arg header; metadata, delete, and list
 * calls use the RPC endpoint. Dropbox reports many distinct failures
 * as HTTP 409, disambiguated only by the error summary in the body, so
 * missing paths map to absence and every other 409 throws. Every
 * method throws \RuntimeException on transport or service errors with
 * the HTTP status as the code (0 for network failures), matching the
 * other providers, so the upload driver can map 401/403 to a re-enter
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
 * Class MainWP_Backup_Storage_Dropbox
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_Dropbox implements MainWP_Backup_Storage_Provider { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * RPC endpoint base.
     *
     * @var string
     */
    const API_BASE = 'https://api.dropboxapi.com';

    /**
     * Content endpoint base for uploads and downloads.
     *
     * @var string
     */
    const CONTENT_BASE = 'https://content.dropboxapi.com';

    /**
     * Provider settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Keep bearer tokens out of persistent storage.
     *
     * @var string|null
     */
    private ?string $cached_access_token = null;

    /**
     * HTTP transport callable: fn(string $url, array $args): array|\WP_Error.
     *
     * @var callable
     */
    private $transport;

    /**
     * Hosts whose SSRF re-check has already run this operation.
     *
     * @var array<string,bool>
     */
    private $ssrf_checked_hosts = array();

    /**
     * Constructor.
     *
     * @param array         $settings  Provider settings: app_key, app_secret, refresh_token, base_path.
     * @param callable|null $transport Transport override for tests.
     */
    public function __construct( array $settings, $transport = null ) {
        // Earlier versions persisted bearer tokens in this transient, including external caches.
        $app_key       = isset( $settings['app_key'] ) ? (string) $settings['app_key'] : '';
        $refresh_token = isset( $settings['refresh_token'] ) ? (string) $settings['refresh_token'] : '';
        delete_transient( 'mainwp_dropbox_token_' . md5( $app_key . '|' . $refresh_token ) );

        $this->settings  = $settings;
        $this->transport = is_callable( $transport ) ? $transport : array( static::class, 'default_transport' );
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
     * @param string $content_type MIME type; Dropbox uploads always go as octet-stream.
     *
     * @return bool
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ): bool {
        unset( $content_type );

        $response = $this->content_request(
            '/2/files/upload',
            array(
                'path' => $this->object_path( $key ),
                'mode' => 'overwrite',
                'mute' => true,
            ),
            $body
        );

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
        $response = $this->api_request( '/2/files/get_metadata', array( 'path' => $this->object_path( $key ) ) );

        if ( $this->is_missing_path( $response ) ) {
            return false;
        }

        $this->expect_status( $response, array( 200 ), 'HEAD ' . $key );

        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        return array( 'size' => is_array( $data ) && isset( $data['size'] ) ? (int) $data['size'] : 0 );
    }

    /**
     * Download an object to a local file, streamed.
     *
     * @param string $key        Object key.
     * @param string $local_path Target file path.
     *
     * @return bool
     *
     * @throws \Throwable On transport or service errors, after removing a partial download.
     */
    public function get_object( string $key, string $local_path ): bool {
        try {
            $response = $this->content_request(
                '/2/files/download',
                array( 'path' => $this->object_path( $key ) ),
                null,
                array(
                    'stream'   => true,
                    'filename' => $local_path,
                )
            );
        } catch ( \Throwable $e ) {
            if ( is_file( $local_path ) ) {
                wp_delete_file( $local_path );
            }
            throw $e;
        }

        if ( 200 !== $this->status( $response ) && is_file( $local_path ) ) {
            if ( is_readable( $local_path ) ) {
                $body = file_get_contents( $local_path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the bounded local response body.
                if ( false !== $body ) {
                    $response['body'] = $body;
                }
            }
            wp_delete_file( $local_path );
        }

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
        $response = $this->api_request( '/2/files/delete_v2', array( 'path' => $this->object_path( $key ) ) );

        if ( $this->is_missing_path( $response ) ) {
            return true;
        }

        $this->expect_status( $response, array( 200 ), 'DELETE ' . $key );

        return true;
    }

    /**
     * List one page of objects under a prefix.
     *
     * @param string      $prefix Key prefix filter; folder-aligned on Dropbox.
     * @param string|null $token  Continuation cursor from the previous page.
     *
     * @return array
     *
     * @throws \RuntimeException On transport or service errors.
     */
    public function list_objects( string $prefix = '', ?string $token = null ): array {
        if ( null !== $token && '' !== $token ) {
            $response = $this->api_request( '/2/files/list_folder/continue', array( 'cursor' => $token ) );
        } else {
            $response = $this->api_request(
                '/2/files/list_folder',
                array(
                    'path'      => $this->folder_path( $prefix ),
                    'recursive' => true,
                    'limit'     => 1000,
                )
            );

            if ( $this->is_missing_path( $response ) ) {
                return array(
                    'objects'    => array(),
                    'next_token' => null,
                    'truncated'  => false,
                );
            }
        }

        $this->expect_status( $response, array( 200 ), 'LIST ' . $prefix );

        return $this->parse_list_response( (string) wp_remote_retrieve_body( $response ) );
    }

    /**
     * Prove the credentials work: mint a token, write a probe object,
     * verify it, delete it.
     *
     * @return array
     *
     * @throws \RuntimeException Internally on a missing probe; always caught and reported in the result.
     */
    public function connection_test(): array {
        $probe = '.mainwp-probe-' . uniqid() . '.txt';

        try {
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
     * Send an RPC request with a JSON body.
     *
     * @param string $endpoint Endpoint path on the API base.
     * @param array  $arg      Request argument object.
     *
     * @return array Transport response.
     */
    private function api_request( string $endpoint, array $arg ) {
        return $this->authed_request(
            static::API_BASE . $endpoint,
            array( 'Content-Type' => 'application/json' ),
            (string) wp_json_encode( $arg )
        );
    }

    /**
     * Send a content request: the argument rides in the Dropbox-API-Arg
     * header and the body carries raw bytes (or nothing for downloads).
     *
     * @param string      $endpoint   Endpoint path on the content base.
     * @param array       $arg        Request argument object.
     * @param string|null $body       Raw bytes to upload.
     * @param array       $extra_args Additional WP HTTP args (stream, filename).
     *
     * @return array Transport response.
     */
    private function content_request( string $endpoint, array $arg, ?string $body = null, array $extra_args = array() ) {
        $headers = array( 'Dropbox-API-Arg' => str_replace( "\x7f", '\u007f', (string) wp_json_encode( $arg ) ) );
        if ( null !== $body ) {
            $headers['Content-Type'] = 'application/octet-stream';
        }

        return $this->authed_request( static::CONTENT_BASE . $endpoint, $headers, $body, $extra_args );
    }

    /**
     * Send a bearer-authed request, re-minting the access token once
     * when Dropbox rejects it.
     *
     * @param string      $url        Request URL.
     * @param array       $headers    Request headers without authorization.
     * @param string|null $body       Request body.
     * @param array       $extra_args Additional WP HTTP args.
     *
     * @return array Transport response.
     */
    private function authed_request( string $url, array $headers, ?string $body = null, array $extra_args = array() ) {
        $response = $this->send_authed( $url, $headers, $body, $extra_args, $this->access_token() );

        if ( 401 === $this->status( $response ) ) {
            $this->cached_access_token = null;
            $response                  = $this->send_authed( $url, $headers, $body, $extra_args, $this->access_token() );
        }

        return $response;
    }

    /**
     * Send one request with a bearer token through the transport.
     *
     * @param string      $url        Request URL.
     * @param array       $headers    Request headers without authorization.
     * @param string|null $body       Request body.
     * @param array       $extra_args Additional WP HTTP args.
     * @param string      $token      Access token.
     *
     * @return array Transport response.
     */
    private function send_authed( string $url, array $headers, ?string $body, array $extra_args, string $token ) {
        $headers['Authorization'] = 'Bearer ' . $token;

        $args = array_merge(
            array(
                'method'  => 'POST',
                'headers' => $headers,
                'timeout' => 120,
            ),
            $extra_args
        );

        if ( null !== $body ) {
            $args['body'] = $body;
        }

        return $this->send( $url, $args );
    }

    /**
     * Call the transport and normalize transport-level failures.
     *
     * @param string $url  Request URL.
     * @param array  $args Request args.
     *
     * @return array Transport response.
     *
     * @throws \RuntimeException When the transport itself fails.
     */
    private function send( string $url, array $args ) {
        $this->guard_outbound_host( $url );

        $response = call_user_func( $this->transport, $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Storage request failed: ' . esc_html( $response->get_error_message() ), 0 );
        }

        return $response;
    }

    /**
     * Re-check the request host right before connecting so a DNS rebind cannot
     * redirect the transfer to a link-local / cloud-metadata address. Dropbox
     * uses hardcoded public endpoints, so this is defense-in-depth; the result
     * is cached per host for the operation's lifetime.
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
     * Exchange an OAuth authorization code for a refresh token.
     *
     * The Connect Dropbox flow calls this once on the OAuth callback;
     * the refresh token it returns is what the destination stores. The
     * error message never echoes the response body, only the HTTP
     * status, so nothing the service returns can leak into the UI.
     *
     * @param string        $app_key      App key (OAuth client id).
     * @param string        $app_secret   App secret.
     * @param string        $code         Single-use authorization code.
     * @param string        $redirect_uri The exact redirect URI the authorize step used.
     * @param callable|null $transport    Transport override for tests.
     *
     * @return array { refresh_token } on success, { error } on failure.
     */
    public static function exchange_authorization_code( string $app_key, string $app_secret, string $code, string $redirect_uri, $transport = null ): array {
        $transport = is_callable( $transport ) ? $transport : array( static::class, 'default_transport' );

        // The exchange is the one transport call that runs outside the guarded
        // send() path (it is static and happens at config time, before any
        // provider instance exists), so it re-checks the API host itself.
        $api_host = (string) wp_parse_url( static::API_BASE, PHP_URL_HOST );
        if ( '' !== $api_host && ! MainWP_Backup_Storage_Destinations::is_safe_host( $api_host ) ) {
            return array( 'error' => esc_html__( 'The Dropbox API host resolves to a link-local or cloud-metadata address, so the connection was refused. Check the dashboard\'s DNS and try again.', 'mainwp' ) );
        }

        $response = call_user_func(
            $transport,
            static::API_BASE . '/oauth2/token',
            array(
                'method'  => 'POST',
                'timeout' => 30,
                'body'    => array(
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'client_id'     => $app_key,
                    'client_secret' => $app_secret,
                    'redirect_uri'  => $redirect_uri,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => esc_html__( 'The Dropbox code exchange could not be sent. Check the dashboard\'s outbound connectivity and try again.', 'mainwp' ) );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $token  = is_array( $data ) && isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '';

        if ( 200 !== $status || '' === $token ) {
            return array(
                'error' => sprintf(
                    /* translators: %d: HTTP status of the failed exchange. */
                    esc_html__( 'Dropbox did not issue a refresh token (HTTP %d). The access code is single use and expires quickly; start the connection again.', 'mainwp' ),
                    $status
                ),
            );
        }

        return array( 'refresh_token' => $token );
    }

    /**
     * A usable access token: the cached one, or a fresh mint from the
     * refresh token.
     *
     * @return string
     *
     * @throws \RuntimeException When the mint fails; the HTTP status rides as the code.
     */
    private function access_token(): string {
        if ( null !== $this->cached_access_token ) {
            return $this->cached_access_token;
        }

        $response = $this->send(
            static::API_BASE . '/oauth2/token',
            array(
                'method'  => 'POST',
                'timeout' => 30,
                'body'    => array(
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => isset( $this->settings['refresh_token'] ) ? (string) $this->settings['refresh_token'] : '',
                    'client_id'     => isset( $this->settings['app_key'] ) ? (string) $this->settings['app_key'] : '',
                    'client_secret' => isset( $this->settings['app_secret'] ) ? (string) $this->settings['app_secret'] : '',
                ),
            )
        );

        if ( 400 === $this->status( $response ) ) {
            $error = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            if ( is_array( $error ) && in_array( $error['error'] ?? '', array( 'invalid_grant', 'invalid_client' ), true ) ) {
                $detail = isset( $error['error_description'] ) && is_string( $error['error_description'] ) ? $error['error_description'] : $error['error'];
                throw new \RuntimeException( 'Storage request token request failed: ' . esc_html( $this->redact_diagnostic( $detail ) ), 401 );
            }
        }

        $this->expect_status( $response, array( 200 ), 'token request' );

        $data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $token = is_array( $data ) && isset( $data['access_token'] ) ? (string) $data['access_token'] : '';

        if ( '' === $token ) {
            throw new \RuntimeException( 'Storage request token request returned no access token.', 0 );
        }

        $this->cached_access_token = $token;

        return $token;
    }

    /**
     * Whether a response is Dropbox's missing-path 409.
     *
     * @param array $response Transport response.
     *
     * @return bool
     */
    private function is_missing_path( $response ): bool {
        if ( 409 !== $this->status( $response ) ) {
            return false;
        }

        $data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $summary = is_array( $data ) && isset( $data['error_summary'] ) ? (string) $data['error_summary'] : '';

        return false !== strpos( $summary, 'not_found' );
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
            $body    = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $body, true );
            $detail  = '';
            if ( is_array( $decoded ) && isset( $decoded['error_summary'] ) && is_string( $decoded['error_summary'] ) ) {
                $detail = $decoded['error_summary'];
            } elseif ( JSON_ERROR_NONE !== json_last_error() && preg_match( '//u', $body ) && ! preg_match( '/^(?:\xEF\xBB\xBF)?\s*[\[{"<]/', $body ) ) {
                $detail = $body;
            }

            $request_id = wp_remote_retrieve_header( $response, 'x-dropbox-request-id' );
            if ( is_array( $request_id ) ) {
                $request_id = reset( $request_id );
            }
            $diagnostics = array(
                'detail'     => $detail,
                'request_id' => is_scalar( $request_id ) ? (string) $request_id : '',
            );
            foreach ( $diagnostics as $key => $value ) {
                $diagnostics[ $key ] = $this->redact_diagnostic( $value );
            }

            $message = 'Storage request ' . $context . ' returned HTTP ' . $code;
            if ( '' !== $diagnostics['detail'] ) {
                $message .= ': ' . $diagnostics['detail'];
            }
            if ( '' !== $diagnostics['request_id'] ) {
                $message .= ' (Dropbox request id ' . $diagnostics['request_id'] . ')';
            }
            throw new \RuntimeException( esc_html( $message . '.' ), (int) $code );
        }
    }

    /**
     * Redact credentials and normalize a diagnostic for display.
     *
     * @param string $value Diagnostic text.
     *
     * @return string
     */
    private function redact_diagnostic( string $value ): string {
        // Redact before normalization can split credentials or join benign runs.
        // Markup characters are dropped rather than encoded: the message is
        // already esc_html'd at the throw and the list view escapes it again,
        // so an encoded entity would reach the tooltip as &amp;lt;. The cast
        // before trim() covers a PCRE failure returning null, which would
        // otherwise blank the diagnostic this path exists to carry.
        // An sl. prefix marks the rest of the run as credential, so it runs to
        // the next real space and crosses any control byte, including a line
        // break. The generic run keeps the non-whitespace control bytes, so a
        // credential split by one cannot survive as two sub-40 halves, but not
        // tab, LF, VT, FF or CR: a multi-line body of bare identifiers is
        // diagnostic text, and redacting it whole would cost the operator the
        // cause this path exists to report.
        $value = preg_replace( '/sl\.(?:[^\s]|[\x09-\x0d])+|[A-Za-z0-9_\-\x00-\x08\x0e-\x1f\x7f]{40,}/', '[redacted]', $value );
        $value = preg_replace( '/\bAuthorization[ ]*[:=][ ]*\K[^\x00-\x1f\x7f]*|\b(?:Bearer|Basic)\s+\K\S+|\b(?:access_token|refresh_token|id_token|token|client_secret|app_secret|secret|api_key|apikey|password|passwd|key)["\']?[ ]*[:=][ ]*["\']?\K(?:\[redacted\]|[^\s"\',&}\]])+/i', '[redacted]', $value );
        $value = preg_replace( '/[\x00-\x1f\x7f]/', ' ', $value );
        $value = str_replace( array( '<', '>', '&', '"', "'" ), '', $value );
        $value = preg_replace( '/\s+/', ' ', $value );
        return mb_substr( trim( (string) $value ), 0, 200 );
    }

    /**
     * The Dropbox path of an object key under the configured base path.
     *
     * @param string $key Object key.
     *
     * @return string
     */
    private function object_path( string $key ): string {
        $base = $this->base_path();
        return '/' . ( '' !== $base ? $base . '/' : '' ) . ltrim( $key, '/' );
    }

    /**
     * The Dropbox folder path of a key prefix; the root is the empty
     * string per the Dropbox API.
     *
     * @param string $prefix Key prefix.
     *
     * @return string
     */
    private function folder_path( string $prefix ): string {
        $base = $this->base_path();
        $path = trim( ( '' !== $base ? $base . '/' : '' ) . $prefix, '/' );
        return '' === $path ? '' : '/' . $path;
    }

    /**
     * The normalized base path setting.
     *
     * @return string
     */
    private function base_path(): string {
        return isset( $this->settings['base_path'] ) ? trim( (string) $this->settings['base_path'], '/' ) : '';
    }

    /**
     * Parse a list_folder response page onto the provider pagination
     * contract: file entries as keys relative to the base path.
     *
     * @param string $body Response body.
     *
     * @return array {objects, next_token, truncated}.
     *
     * @throws \RuntimeException When the body is not parseable JSON.
     */
    private function parse_list_response( string $body ): array {
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            throw new \RuntimeException( 'Storage list response was not valid JSON.', 0 );
        }

        $base    = $this->base_path();
        $objects = array();
        foreach ( isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array() as $entry ) {
            if ( ! is_array( $entry ) || 'file' !== ( $entry['.tag'] ?? '' ) ) {
                continue;
            }
            $key = ltrim( isset( $entry['path_display'] ) ? (string) $entry['path_display'] : '', '/' );
            if ( '' !== $base && 0 === strpos( $key, $base . '/' ) ) {
                $key = substr( $key, strlen( $base ) + 1 );
            }
            $objects[] = array(
                'key'  => $key,
                'size' => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
            );
        }

        $truncated = ! empty( $data['has_more'] );

        return array(
            'objects'    => $objects,
            'next_token' => $truncated && isset( $data['cursor'] ) ? (string) $data['cursor'] : null,
            'truncated'  => $truncated,
        );
    }
}
