<?php
/**
 * MainWP Backup Storage pCloud Provider.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Access the pCloud API over the WordPress HTTP transport. */
class MainWP_Backup_Storage_PCloud implements MainWP_Backup_Storage_Provider { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    /**
     * Fixed region endpoints; never accept a configured hostname.
     *
     * @var array
     */
    const API_BASES = array(
        1 => 'https://api.pcloud.com',
        2 => 'https://eapi.pcloud.com',
    );

    /**
     * Built-in app client ID.
     *
     * @var string
     */
    const CLIENT_ID = 'OB9klstGJeB';

    /**
     * Built-in app client secret. The plugin is open source, so this identifies the app and protects nothing.
     *
     * @var string
     */
    const CLIENT_SECRET = 'gORUpI9T2C5iwkcy96vFsu20tvD7'; // gitleaks:allow -- public identifier of MainWP's pCloud app, shipped in the open-source plugin.

    /**
     * The pCloud bearer header keeps tokens out of URLs on every method this provider calls.
     *
     * @var string
     */
    const AUTH_SCHEME = 'Bearer ';

    /**
     * 1000 means no token, 2094 an invalid one and 2095 a token revoked by removing the app in pCloud.
     *
     * @var array
     */
    const AUTH_RESULTS = array( 1000, 2000, 2094, 2095 );

    /**
     * Access-denied results pending pCloud probe P4.
     *
     * @var array
     */
    const DENIED_RESULTS = array( 2003 );

    /**
     * Absence codes differ by method: stat answers 2055 (or 2002 without the parent), listfolder 2005, deletefile and getfilelink 2009.
     *
     * @var array
     */
    const MISSING_RESULTS = array( 2009, 2005, 2002, 2055 );

    /**
     * Quota result pending the quota probe.
     *
     * @var int
     */
    const QUOTA_RESULT = 2008;

    /**
     * The getfilelink response returns content hosts such as pmia1.pcloud.com and def3.pcloud.com.
     *
     * @var string
     */
    const DOWNLOAD_HOST_PATTERN = '/^[a-z0-9-]+\.pcloud\.com$/D';

    // Four cold folder creates + upload + confirming stat = 4*30 + 120 + 30 = 270 s, within LATE_WRITE_SECONDS' 600 s bound.
    /**
     * Metadata request timeout in seconds.
     *
     * @var int
     */
    const METADATA_TIMEOUT = 30;

    /**
     * Upload and download timeout in seconds.
     *
     * @var int
     */
    const TRANSFER_TIMEOUT = 120;

    /**
     * Provider settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Fixed API base for the selected region.
     *
     * @var string
     */
    private $api_base;

    /**
     * HTTP transport.
     *
     * @var callable
     */
    private $transport;

    /**
     * Folder paths mapped to string IDs.
     *
     * @var array
     */
    private $folders = array( '' => '0' );

    /**
     * Hosts checked during this operation.
     *
     * @var array
     */
    private $checked_hosts = array();

    /**
     * Set the region and transport.
     *
     * @param array         $settings Provider settings.
     * @param callable|null $transport Transport override for tests.
     * @throws \RuntimeException On an invalid region.
     */
    public function __construct( array $settings, $transport = null ) {
        if ( ! in_array( $settings['locationid'] ?? null, array( 1, 2, '1', '2' ), true ) ) {
            throw new \RuntimeException( 'Invalid pCloud storage region.' );
        }
        $this->settings  = $settings;
        $this->api_base  = self::API_BASES[ (int) $settings['locationid'] ];
        $this->transport = is_callable( $transport ) ? $transport : array( static::class, 'default_transport' );
    }

    /**
     * Use the WordPress HTTP API.
     *
     * @param string $url Request URL.
     * @param array  $args Request options.
     * @return array|\WP_Error
     */
    public static function default_transport( $url, $args ) {
        return wp_remote_request( $url, $args );
    }

    /**
     * App credentials for the code exchange.
     *
     * A site whose wp-config defines both overrides uses its own pCloud app, for example if pCloud revokes MainWP's.
     *
     * @return array {client_id, client_secret}; empty strings when no app is configured.
     */
    public static function app_credentials(): array {
        if ( defined( 'MAINWP_PCLOUD_CLIENT_ID' ) && defined( 'MAINWP_PCLOUD_CLIENT_SECRET' ) ) {
            $client_id     = constant( 'MAINWP_PCLOUD_CLIENT_ID' );
            $client_secret = constant( 'MAINWP_PCLOUD_CLIENT_SECRET' );
            if ( is_string( $client_id ) && is_string( $client_secret ) && '' !== trim( $client_id ) && '' !== trim( $client_secret ) ) {
                return array(
                    'client_id'     => trim( $client_id ),
                    'client_secret' => trim( $client_secret ),
                );
            }
        }
        return array(
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
        );
    }

    /**
     * The pCloud approval page, which shows the user a code to paste back.
     *
     * Without a redirect_uri pCloud displays the code instead of redirecting, so no state is needed.
     *
     * @return string Empty when no app is configured.
     */
    public static function authorize_url(): string {
        $credentials = static::app_credentials();
        if ( '' === $credentials['client_id'] || '' === $credentials['client_secret'] ) {
            return '';
        }
        return 'https://my.pcloud.com/oauth2/authorize?' . http_build_query(
            array(
                'client_id'     => $credentials['client_id'],
                'response_type' => 'code',
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * Exchange a pasted code and find the account's region.
     *
     * The US host is tried first and the EU host only when pCloud itself refuses the code. A transport
     * failure stops there: the code may still be valid and is single use, so it is not sent elsewhere.
     * When the US host refuses and the EU host cannot be reached, the EU error is returned because a new
     * code would not help. A token works only on its own region's host, so userinfo confirms the region,
     * starting with the locationid the token response names (or the exchange host when it names none).
     *
     * @param string        $client_id App client ID.
     * @param string        $client_secret App client secret.
     * @param string        $code Authorization code.
     * @param callable|null $transport Transport override for tests.
     * @return array {access_token, locationid, userid, account_email?} or {error}.
     */
    public static function exchange_code_for_region( string $client_id, string $client_secret, string $code, $transport = null ): array {
        if ( '' === trim( $code ) ) {
            return array( 'error' => esc_html__( 'Paste the authorization code from pCloud, then save.', 'mainwp' ) );
        }
        if ( '' === $client_id || '' === $client_secret ) {
            return array( 'error' => esc_html__( 'pCloud connections are not available on this Dashboard yet.', 'mainwp' ) );
        }
        $code     = trim( $code );
        $exchange = static::exchange_authorization_code( $client_id, $client_secret, $code, 1, $transport );
        $location = 1;
        if ( isset( $exchange['result'] ) ) {
            $eu = static::exchange_authorization_code( $client_id, $client_secret, $code, 2, $transport );
            if ( ! isset( $eu['error'] ) || ! isset( $eu['result'] ) ) {
                $exchange = $eu;
                $location = 2;
            }
        }
        if ( isset( $exchange['error'] ) ) {
            return array( 'error' => $exchange['error'] );
        }
        $location = $exchange['locationid'] ?? $location;
        foreach ( array( $location, 3 - $location ) as $candidate ) {
            try {
                $account = ( new static(
                    array(
                        'locationid'   => $candidate,
                        'access_token' => $exchange['access_token'],
                    ),
                    $transport
                ) )->account_info();
            } catch ( \RuntimeException $e ) {
                continue;
            }
            $result = array(
                'access_token' => $exchange['access_token'],
                'locationid'   => $candidate,
                'userid'       => $account['userid'],
            );
            if ( isset( $account['email'] ) && is_string( $account['email'] ) && is_email( $account['email'] ) ) {
                $result['account_email'] = sanitize_email( $account['email'] );
            }
            return $result;
        }
        return array( 'error' => esc_html__( 'pCloud did not confirm the account region. Click Connect pCloud and paste the new code.', 'mainwp' ) );
    }

    /**
     * Exchange a code on the fixed region host without retaining the client secret.
     *
     * @param string        $client_id App client ID.
     * @param string        $client_secret Exchange-only secret.
     * @param string        $code Authorization code.
     * @param int           $locationid Validated region.
     * @param callable|null $transport Transport override for tests.
     * @return array {access_token, userid?: string, locationid?: int} or {error, result?}; result is pCloud's non-zero refusal code.
     */
    public static function exchange_authorization_code( string $client_id, string $client_secret, string $code, int $locationid, $transport = null ): array {
        if ( ! isset( self::API_BASES[ $locationid ] ) ) {
            return array( 'error' => esc_html__( 'Invalid pCloud storage region. Start the connection again.', 'mainwp' ) );
        }
        $base = self::API_BASES[ $locationid ];
        if ( ! MainWP_Backup_Storage_Destinations::is_safe_host( (string) wp_parse_url( $base, PHP_URL_HOST ) ) ) {
            return array( 'error' => esc_html__( 'The pCloud API host resolves to a link-local or cloud-metadata address, so the connection was refused. Check the dashboard\'s DNS and try again.', 'mainwp' ) );
        }
        $transport = is_callable( $transport ) ? $transport : array( static::class, 'default_transport' );
        $response  = call_user_func(
            $transport,
            $base . '/oauth2_token',
            array(
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 0,
                'body'        => array(
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'code'          => $code,
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return array( 'error' => esc_html__( 'The pCloud code exchange could not be sent. Check the dashboard\'s outbound connectivity and try again.', 'mainwp' ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data   = json_decode( (string) wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
        if ( 200 !== $status || ! is_array( $data ) || ( isset( $data['result'] ) && 0 !== $data['result'] )
            || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) || ! preg_match( '/^[A-Za-z0-9._~+\/=-]{16,512}$/D', $data['access_token'] ) ) {
            $error = array( 'error' => esc_html__( 'pCloud did not issue an access token. Click Connect pCloud and paste the new code.', 'mainwp' ) );
            if ( is_array( $data ) && isset( $data['result'] ) && is_int( $data['result'] ) && 0 !== $data['result'] ) {
                $error['result'] = $data['result'];
            }
            return $error;
        }
        $result = array( 'access_token' => $data['access_token'] );
        if ( isset( $data['userid'] ) && ( is_int( $data['userid'] ) || is_string( $data['userid'] ) ) && preg_match( '/^[0-9]+$/D', (string) $data['userid'] ) ) {
            $result['userid'] = (string) $data['userid'];
        }
        if ( in_array( $data['locationid'] ?? null, array( 1, 2, '1', '2' ), true ) ) {
            $result['locationid'] = (int) $data['locationid'];
        }
        return $result;
    }

    /**
     * Store bytes, creating parent folders as needed.
     *
     * @param string $key Object key.
     * @param string $body Object bytes.
     * @param string $content_type MIME type.
     * @return bool
     * @throws \RuntimeException On a failed or incomplete upload.
     */
    public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ): bool {
        $path   = $this->object_path( $key );
        $params = array(
            'folderid'  => $this->folder_id( dirname( $path ) ),
            'filename'  => basename( $path ),
            'nopartial' => 1,
        );
        $args   = array(
            'method'  => 'PUT',
            'timeout' => self::TRANSFER_TIMEOUT,
            'headers' => array(
                'Content-Type'   => $content_type,
                'Content-Length' => (string) strlen( $body ),
            ),
            'body'    => $body,
        );
        if ( '' === $body ) {
            $boundary        = 'mainwp-' . bin2hex( random_bytes( 16 ) );
            $filename        = str_replace( array( '"', "\r", "\n" ), '_', basename( $path ) );
            $multipart       = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . $filename . "\"\r\nContent-Type: application/octet-stream\r\n\r\n\r\n--" . $boundary . "--\r\n";
            $args['method']  = 'POST';
            $args['body']    = $multipart;
            $args['headers'] = array(
                'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
                'Content-Length' => (string) strlen( $multipart ),
            );
        }
        $data = $this->request( 'uploadfile', $params, $args );
        if ( ! isset( $data['metadata'][0]['size'] ) || (string) strlen( $body ) !== (string) $data['metadata'][0]['size'] ) {
            throw new \RuntimeException( 'pCloud did not confirm the uploaded size.', 500 );
        }
        return true;
    }

    /**
     * Read object size, or report absence.
     *
     * @param string $key Object key.
     * @return array|false
     * @throws \RuntimeException On a failed metadata read.
     */
    public function head_object( string $key ) {
        $data = $this->request( 'stat', array( 'path' => $this->object_path( $key ) ), array(), true );
        if ( false === $data ) {
            return false;
        }
        if ( ! isset( $data['metadata']['size'] ) || ! empty( $data['metadata']['isfolder'] ) ) {
            throw new \RuntimeException( 'pCloud did not return file metadata.', 500 );
        }
        return array( 'size' => (int) $data['metadata']['size'] );
    }

    /**
     * Stream a download from a validated content host without credentials.
     *
     * @param string $key Object key.
     * @param string $local_path Local download path.
     * @return bool
     * @throws \RuntimeException On an invalid link or rejected download.
     * @throws \Throwable After removing a failed partial download.
     */
    public function get_object( string $key, string $local_path ): bool {
        try {
            $link = $this->request( 'getfilelink', array( 'path' => $this->object_path( $key ) ) );
            $host = $link['hosts'][0] ?? '';
            $path = $link['path'] ?? '';
            if ( ! is_string( $host ) || ! preg_match( self::DOWNLOAD_HOST_PATTERN, $host ) || ! is_string( $path )
                || ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) || preg_match( '/[\x00-\x20\\\\]/', $path ) ) {
                throw new \RuntimeException( 'Invalid pCloud download link.', 500 );
            }
            $response = $this->send(
                'https://' . $host . $path,
                array(
                    'method'   => 'GET',
                    'timeout'  => self::TRANSFER_TIMEOUT,
                    'stream'   => true,
                    'filename' => $local_path,
                )
            );
            $status   = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $status ) {
                throw new \RuntimeException( 'pCloud download failed.', $status );
            }
        } catch ( \Throwable $e ) {
            if ( is_file( $local_path ) ) {
                wp_delete_file( $local_path );
            }
            throw $e;
        }
        return true;
    }

    /**
     * Delete an object; absence is success.
     *
     * @param string $key Object key.
     * @return bool
     */
    public function delete_object( string $key ): bool {
        $this->request( 'deletefile', array( 'path' => $this->object_path( $key ) ), array(), true );
        return true;
    }

    /**
     * Flatten the recursive tree under the prefix's deepest complete folder.
     *
     * @param string      $prefix Object prefix.
     * @param string|null $token Unused; pCloud returns the whole tree.
     * @return array
     */
    public function list_objects( string $prefix = '', ?string $token = null ): array {
        unset( $token );
        $prefix = ltrim( $prefix, '/' );
        $slash  = strrpos( $prefix, '/' );
        $folder = false === $slash ? '' : substr( $prefix, 0, $slash );
        $data   = $this->request(
            'listfolder',
            array(
                'path'      => $this->object_path( $folder ),
                'recursive' => 1,
            ),
            array(),
            true
        );
        $result = array(
            'objects'    => array(),
            'next_token' => null,
            'truncated'  => false,
        );
        if ( false !== $data ) {
            $result['objects'] = $this->flatten( $data['metadata']['contents'] ?? array(), '' === $folder ? '' : $folder . '/', $prefix );
        }
        return $result;
    }

    /**
     * Collect file keys relative to the configured base folder.
     *
     * @param array  $entries Folder contents.
     * @param string $parent_path Relative parent path.
     * @param string $prefix Requested prefix.
     * @return array
     */
    private function flatten( array $entries, string $parent_path, string $prefix ): array {
        $objects = array();
        foreach ( $entries as $entry ) {
            $name = (string) ( $entry['name'] ?? '' );
            if ( '' === $name || basename( $name ) !== $name || in_array( $name, array( '.', '..' ), true ) ) {
                continue;
            }
            $key = $parent_path . $name;
            if ( ! empty( $entry['isfolder'] ) ) {
                $objects = array_merge( $objects, $this->flatten( $entry['contents'] ?? array(), $key . '/', $prefix ) );
            } elseif ( str_starts_with( $key, $prefix ) ) {
                $objects[] = array(
                    'key'  => $key,
                    'size' => (int) ( $entry['size'] ?? 0 ),
                );
            }
        }
        return $objects;
    }

    /**
     * Verify write, read and delete access using safe failure messages.
     *
     * @return array
     * @throws \RuntimeException Internally if the probe is absent.
     */
    public function connection_test(): array {
        try {
            $probe = '.mainwp-probe-' . bin2hex( random_bytes( 16 ) ) . '.txt';
            $this->put_object( $probe, 'mainwp-connection-test' );
            $head = $this->head_object( $probe );
            if ( false === $head || 22 !== $head['size'] ) {
                throw new \RuntimeException( 'pCloud did not confirm the connection probe.', 500 );
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
     * Read the authenticated account ID.
     *
     * @return string
     */
    public function account_userid(): string {
        return $this->account_info()['userid'];
    }

    /**
     * Read account details only when pCloud confirms a valid account ID.
     *
     * @return array
     * @throws \RuntimeException On failed userinfo or an invalid account ID.
     */
    private function account_info(): array {
        $data           = $this->request( 'userinfo', array() );
        $data['userid'] = $this->numeric_id( $data['userid'] ?? null );
        return $data;
    }

    /**
     * Resolve the base folder's server-issued ID.
     *
     * @return string
     */
    public function base_folder_id(): string {
        return $this->folder_id( $this->object_path( '' ) );
    }

    /**
     * Create each missing segment and cache its ID for this instance.
     *
     * @param string $path Folder path.
     * @return string
     * @throws \RuntimeException On invalid folder metadata.
     */
    private function folder_id( string $path ): string {
        $path = trim( $path, '/' );
        $key  = '';
        $id   = '0';
        foreach ( '' === $path ? array() : explode( '/', $path ) as $segment ) {
            $key .= '/' . $segment;
            if ( ! isset( $this->folders[ $key ] ) ) {
                $data = $this->request(
                    'createfolderifnotexists',
                    array(
                        'folderid' => $id,
                        'name'     => $segment,
                    )
                );
                if ( empty( $data['metadata']['isfolder'] ) ) {
                    throw new \RuntimeException( 'pCloud did not confirm the backup folder.', 500 );
                }
                $this->folders[ $key ] = $this->numeric_id( $data['metadata']['folderid'] ?? null );
            }
            $id = $this->folders[ $key ];
        }
        return $id;
    }

    /**
     * Keep large IDs intact and reject malformed metadata.
     *
     * @param mixed $id Server ID.
     * @return string
     * @throws \RuntimeException On malformed metadata.
     */
    private function numeric_id( $id ): string {
        if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[0-9]+$/D', (string) $id ) ) {
            throw new \RuntimeException( 'pCloud did not return a storage identity.', 500 );
        }
        return (string) $id;
    }

    /**
     * Combine the base folder and object key.
     *
     * @param string $key Object key.
     * @return string
     * @throws \RuntimeException On traversal segments.
     */
    private function object_path( string $key ): string {
        $path = trim( (string) ( $this->settings['base_path'] ?? 'MainWP Backups' ), '/' );
        $path = trim( $path . '/' . ltrim( $key, '/' ), '/' );
        if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\\\\]~', $path ) ) {
            throw new \RuntimeException( 'Invalid pCloud storage path.', 400 );
        }
        return '/' . $path;
    }

    /**
     * Parse the result envelope at every HTTP status.
     *
     * @param string $method API method.
     * @param array  $params API parameters; never credentials.
     * @param array  $args Transfer options.
     * @param bool   $allow_missing Whether absent paths return false.
     * @return array|false
     * @throws \RuntimeException On service errors.
     */
    private function request( string $method, array $params, array $args = array(), bool $allow_missing = false ) {
        $args                             = array_merge(
            array(
                'method'  => 'GET',
                'timeout' => self::METADATA_TIMEOUT,
            ),
            $args
        );
        $args['headers']                  = $args['headers'] ?? array();
        $args['headers']['Authorization'] = self::AUTH_SCHEME . (string) ( $this->settings['access_token'] ?? '' );
        $url                              = $this->api_base . '/' . $method . ( $params ? '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) : '' );
        $response                         = $this->send( $url, $args );
        $status                           = (int) wp_remote_retrieve_response_code( $response );
        $data                             = json_decode( (string) wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
        if ( is_array( $data ) && isset( $data['result'] ) && is_int( $data['result'] ) && 0 !== $data['result'] ) {
            $result = $data['result'];
            if ( $allow_missing && in_array( $result, self::MISSING_RESULTS, true ) ) {
                return false;
            }
            $code = 500;
            if ( in_array( $result, self::AUTH_RESULTS, true ) ) {
                $code = 401;
            } elseif ( in_array( $result, self::DENIED_RESULTS, true ) ) {
                $code = 403;
            } elseif ( self::QUOTA_RESULT === $result ) {
                $code = 507;
            } elseif ( $result >= 4000 && $result < 5000 ) {
                $code = 429;
            } elseif ( in_array( $result, self::MISSING_RESULTS, true ) ) {
                $code = 404;
            }
            $messages = array(
                // A revoked or invalid token never recovers on retry; only a new code does.
                401 => 'pCloud refused the saved access. Click Connect pCloud and paste the authorization code to reconnect this destination.',
                507 => 'pCloud storage quota exceeded.',
            );
            throw new \RuntimeException( $messages[ $code ] ?? 'pCloud storage request failed.', (int) $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed plain-text messages; consumers escape at output.
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new \RuntimeException( 'pCloud storage request failed.', (int) $status );
        }
        if ( ! is_array( $data ) || ! isset( $data['result'] ) || 0 !== $data['result'] ) {
            throw new \RuntimeException( 'Invalid pCloud storage response.', 500 );
        }
        return $data;
    }

    /**
     * Recheck outbound hosts and refuse redirects before transport.
     *
     * @param string $url Request URL.
     * @param array  $args Request options.
     * @return array
     * @throws \RuntimeException On unsafe hosts or transport failure.
     */
    private function send( string $url, array $args ): array {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( ! isset( $this->checked_hosts[ $host ] ) ) {
            if ( ! MainWP_Backup_Storage_Destinations::is_safe_host( $host ) ) {
                throw new \RuntimeException( 'pCloud storage host is unsafe.', 0 );
            }
            $this->checked_hosts[ $host ] = true;
        }
        $args['redirection'] = 0;
        $response            = call_user_func( $this->transport, $url, $args );
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'pCloud storage transport failed.', 0 );
        }
        return $response;
    }
}
