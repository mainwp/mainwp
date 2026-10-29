<?php
/**
 * MainWP Backup S3 Signer.
 *
 * Pure AWS Signature Version 4 implementation for S3-compatible storage:
 * canonical request, string to sign, derived signing key, Authorization
 * header. No IO and no state beyond an injectable clock, so it can be
 * verified against the worked examples in the Amazon S3 API reference.
 * The request path must already be RFC 3986 encoded as it will be sent
 * on the wire; S3 signs the single-encoded path verbatim.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || die();

/**
 * Class MainWP_Backup_S3_Signer
 */
class MainWP_Backup_S3_Signer { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Payload hash sentinel for unsigned bodies.
     *
     * @var string
     */
    const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * Clock callable returning a unix timestamp.
     *
     * @var callable
     */
    private $clock;

    /**
     * Constructor.
     *
     * @param callable|null $clock Clock override for deterministic signing in tests.
     */
    public function __construct( $clock = null ) {
        $this->clock = is_callable( $clock ) ? $clock : 'time';
    }

    /**
     * Sign a request and return the full header set to send, including
     * Authorization, Host, x-amz-date, and x-amz-content-sha256.
     *
     * @param string $method       HTTP method.
     * @param string $url          Full request URL with an already-encoded path.
     * @param string $region       Region for the credential scope.
     * @param string $access_key   Access key id.
     * @param string $secret_key   Secret access key.
     * @param array  $headers      Additional headers to send and sign.
     * @param string $payload_hash Hex sha256 of the request body, or UNSIGNED_PAYLOAD.
     *
     * @return array Headers to send with the request.
     */
    public function sign( $method, $url, $region, $access_key, $secret_key, array $headers = array(), $payload_hash = self::UNSIGNED_PAYLOAD ) {
        $parts = wp_parse_url( $url );
        $host  = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        $path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
        $query = isset( $parts['query'] ) ? $parts['query'] : '';

        // One clock read: the date in x-amz-date and the credential scope must agree,
        // and two reads straddling UTC midnight would sign with mismatched days.
        $now      = call_user_func( $this->clock );
        $amz_date = gmdate( 'Ymd\THis\Z', $now );
        $scope    = gmdate( 'Ymd', $now ) . '/' . $region . '/s3/aws4_request';

        $send_headers                         = $headers;
        $send_headers['Host']                 = $host;
        $send_headers['x-amz-date']           = $amz_date;
        $send_headers['x-amz-content-sha256'] = $payload_hash;

        $canonical_headers = array();
        foreach ( $send_headers as $name => $value ) {
            $canonical_headers[ strtolower( $name ) ] = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
        }
        ksort( $canonical_headers, SORT_STRING );

        $signed_header_names = implode( ';', array_keys( $canonical_headers ) );

        $canonical_header_lines = '';
        foreach ( $canonical_headers as $name => $value ) {
            $canonical_header_lines .= $name . ':' . $value . "\n";
        }

        $canonical_request = implode(
            "\n",
            array(
                strtoupper( $method ),
                $path,
                static::canonical_query( $query ),
                $canonical_header_lines,
                $signed_header_names,
                $payload_hash,
            )
        );

        $string_to_sign = implode(
            "\n",
            array(
                'AWS4-HMAC-SHA256',
                $amz_date,
                $scope,
                hash( 'sha256', $canonical_request ),
            )
        );

        $signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $region, $secret_key, $now ) );

        $send_headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
            $access_key,
            $scope,
            $signed_header_names,
            $signature
        );

        return $send_headers;
    }

    /**
     * Presign a GET URL with query-parameter authentication, per
     * "Authenticating Requests: Using Query Parameters". Only the host
     * header is signed and the payload is UNSIGNED-PAYLOAD, so any
     * client can use the URL until it expires.
     *
     * @param string $url        Full object URL with an already-encoded path.
     * @param string $region     Region for the credential scope.
     * @param string $access_key Access key id.
     * @param string $secret_key Secret access key.
     * @param int    $expires    Lifetime in seconds.
     *
     * @return string The presigned URL.
     */
    public function presign( $url, $region, $access_key, $secret_key, $expires ) {
        $parts = wp_parse_url( $url );
        $host  = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        $path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
        $query = isset( $parts['query'] ) ? $parts['query'] : '';

        $now      = call_user_func( $this->clock );
        $amz_date = gmdate( 'Ymd\THis\Z', $now );
        $scope    = gmdate( 'Ymd', $now ) . '/' . $region . '/s3/aws4_request';

        $auth_params = array(
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $access_key . '/' . $scope,
            'X-Amz-Date'          => $amz_date,
            'X-Amz-Expires'       => (string) (int) $expires,
            'X-Amz-SignedHeaders' => 'host',
        );

        foreach ( $auth_params as $name => $value ) {
            $query .= ( '' === $query ? '' : '&' ) . $name . '=' . rawurlencode( $value );
        }

        $canonical_query = static::canonical_query( $query );

        $canonical_request = implode(
            "\n",
            array(
                'GET',
                $path,
                $canonical_query,
                'host:' . $host . "\n",
                'host',
                static::UNSIGNED_PAYLOAD,
            )
        );

        $string_to_sign = implode(
            "\n",
            array(
                'AWS4-HMAC-SHA256',
                $amz_date,
                $scope,
                hash( 'sha256', $canonical_request ),
            )
        );

        $signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $region, $secret_key, $now ) );

        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';

        return $scheme . '://' . $host . $path . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Derive the SigV4 signing key for the request's date.
     *
     * @param string $region     Region for the credential scope.
     * @param string $secret_key Secret access key.
     * @param int    $now        The request's single clock read, shared with its scope date.
     *
     * @return string Binary signing key.
     */
    private function signing_key( $region, $secret_key, $now ) {
        return hash_hmac(
            'sha256',
            'aws4_request',
            hash_hmac(
                'sha256',
                's3',
                hash_hmac(
                    'sha256',
                    $region,
                    hash_hmac( 'sha256', gmdate( 'Ymd', $now ), 'AWS4' . $secret_key, true ),
                    true
                ),
                true
            ),
            true
        );
    }

    /**
     * Canonicalize a query string: split, sort by key, re-encode per
     * RFC 3986. Valueless parameters keep an empty value.
     *
     * @param string $query Raw query string from the URL.
     *
     * @return string
     */
    private static function canonical_query( $query ) {
        if ( '' === $query ) {
            return '';
        }

        $pairs = array();
        foreach ( explode( '&', $query ) as $pair ) {
            if ( '' === $pair ) {
                continue;
            }
            $split   = explode( '=', $pair, 2 );
            $name    = rawurlencode( rawurldecode( $split[0] ) );
            $value   = isset( $split[1] ) ? rawurlencode( rawurldecode( $split[1] ) ) : '';
            $pairs[] = array( $name, $value );
        }

        usort(
            $pairs,
            function ( $a, $b ) {
                return strcmp( $a[0], $b[0] ) ? strcmp( $a[0], $b[0] ) : strcmp( $a[1], $b[1] );
            }
        );

        $encoded = array();
        foreach ( $pairs as $pair ) {
            $encoded[] = $pair[0] . '=' . $pair[1];
        }

        return implode( '&', $encoded );
    }
}
