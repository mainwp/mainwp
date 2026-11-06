<?php
/**
 * In-memory pCloud HTTP fixture using the documented result and metadata shapes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/** Shared transport state for upload, deletion and handler tests. */
class MainWP_PCloud_Transport_Fixture {
	/**
	 * Build a pre_http_request callback with mutable server state.
	 *
	 * @param array $state Objects, folders, requests and account identity.
	 * @return \Closure
	 */
	public static function transport( array &$state ): \Closure {
		$state += array(
			'objects'  => array(),
			'folders'  => array( '' => '0' ),
			'requests' => array(),
			'userid'   => '101',
			'email'    => 'owner@example.com',
			'folderid' => '230807',
		);
		return static function ( $pre, $args, $url ) use ( &$state ) {
			unset( $pre );
			$state['requests'][] = array(
				'url'  => $url,
				'args' => $args,
			);
			$method              = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $params );
			if ( isset( $state['before_request'] ) ) {
				call_user_func( $state['before_request'], $method );
			}
			if ( ( $state['fail_method'] ?? '' ) === $method || '*' === ( $state['fail_method'] ?? '' ) ) {
				return self::response(
					array(
						'result' => $state['result'] ?? 5000,
						'error'  => 'private-server-text',
					)
				);
			}
			if ( 'userinfo' === $method ) {
				return self::response(
					array(
						'result' => 0,
						'userid' => $state['userid'],
						'email'  => $state['email'],
					)
				);
			}
			if ( 'createfolderifnotexists' === $method ) {
				$parent = array_search( (string) $params['folderid'], $state['folders'], true );
				if ( false === $parent ) {
					throw new \RuntimeException( 'Unknown mock parent folder.' );
				}
				$path = $parent . '/' . $params['name'];
				if ( ! isset( $state['folders'][ $path ] ) ) {
					$state['folders'][ $path ] = '' === $parent ? $state['folderid'] : (string) ( 300000 + count( $state['folders'] ) );
				}
				return self::response(
					array(
						'result'   => 0,
						'metadata' => array(
							'isfolder' => true,
							'folderid' => $state['folders'][ $path ],
						),
					)
				);
			}
			if ( 'uploadfile' === $method ) {
				$parent = array_search( (string) $params['folderid'], $state['folders'], true );
				if ( false === $parent ) {
					throw new \RuntimeException( 'Unknown mock upload folder.' );
				}
				$path                      = $parent . '/' . $params['filename'];
				$body                      = 'PUT' === $args['method'] ? $args['body'] : '';
				$state['objects'][ $path ] = $body;
				return self::response(
					array(
						'result'   => 0,
						'metadata' => array(
							array(
								'isfolder' => false,
								'size'     => strlen( $body ),
								'fileid'   => '1729212',
							),
						),
					)
				);
			}
			$path = $params['path'] ?? '';
			if ( 'stat' === $method ) {
				return self::response(
					isset( $state['objects'][ $path ] ) ? array(
						'result'   => 0,
						'metadata' => array(
							'isfolder' => false,
							'size'     => strlen( $state['objects'][ $path ] ),
						),
					) : array( 'result' => isset( $state['folders'][ rtrim( dirname( $path ), '/' ) ] ) ? 2055 : 2002 )
				);
			}
			if ( 'deletefile' === $method ) {
				$exists = isset( $state['objects'][ $path ] );
				unset( $state['objects'][ $path ] );
				return self::response( array( 'result' => $exists ? 0 : 2009 ) );
			}
			if ( 'listfolder' === $method ) {
				$entries = self::contents( $state['objects'], rtrim( $path, '/' ) . '/' );
				return self::response(
					array(
						'result'   => 0,
						'metadata' => array(
							'isfolder' => true,
							'contents' => $entries,
						),
					)
				);
			}
			throw new \RuntimeException( 'Unexpected mock pCloud method: ' . $method );
		};
	}

	/**
	 * Produce recursive listfolder contents from object paths.
	 *
	 * @param array  $objects Stored bytes.
	 * @param string $prefix Parent path.
	 * @return array
	 */
	private static function contents( array $objects, string $prefix ): array {
		$entries = array();
		foreach ( $objects as $path => $body ) {
			if ( ! str_starts_with( $path, $prefix ) ) {
				continue;
			}
			$relative         = substr( $path, strlen( $prefix ) );
			$parts            = explode( '/', $relative, 2 );
			$name             = $parts[0];
			$entries[ $name ] = isset( $parts[1] )
				? array(
					'name'     => $name,
					'isfolder' => true,
					'contents' => self::contents( $objects, $prefix . $name . '/' ),
				)
				: array(
					'name'     => $name,
					'isfolder' => false,
					'size'     => strlen( $body ),
				);
		}
		return array_values( $entries );
	}

	/**
	 * Wrap JSON in a WP HTTP response.
	 *
	 * @param array $data Response data.
	 * @return array
	 */
	private static function response( array $data ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $data ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}
}
