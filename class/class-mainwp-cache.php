<?php
/**
 * Search Cache Handler
 *
 * Handles all search content.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Cache
 *
 * @package MainWP\Dashboard
 */
class MainWP_Cache {

	/**
	 * Method init_session()
	 *
	 * Start session.
	 */
	public static function init_session() {
		if ( PHP_SESSION_NONE === session_status() ) {
			session_start();
		}
	}

	/**
	 * Method init_cache()
	 *
	 * Set session variables.
	 *
	 * @param mixed $page Page information.
	 */
	public static function init_cache( $page ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ]        = '';
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = '';
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ]  = '';
	}

	/**
	 * Method add_context()
	 *
	 * Set time & search context.
	 *
	 * @param mixed $page Page information.
	 * @param array $context Search context.
	 */
	public static function add_context( $page, $context ) {
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$context['time']                                = time();
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = $context;
	}

	/**
	 * Method add_body()
	 *
	 * Set search body session variable.
	 *
	 * @param string $page Page information..
	 * @param mixed  $body Search body.
	 */
	public static function add_body( $page, $body ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ] .= $body;
	}

	/**
	 * Method get_cached_context()
	 *
	 * Grab any cached searches.
	 *
	 * @param mixed $page Page information.
	 *
	 * @return array $cachedSearch Cached search array.
	 */
	public static function get_cached_context( $page ) {
		$cachedSearch = ( isset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) && is_array( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) ? $_SESSION[ 'MainWP' . $page . 'SearchContext' ] : null ); //phpcs:ignore -- ok.

		if ( null !== $cachedSearch ) {
			if ( ( time() - ( 2 * 60 * 60 ) ) > $cachedSearch['time'] ) {
				unset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'Search' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] );
				$cachedSearch = null;
			}
		}
		if ( null !== $cachedSearch && isset( $cachedSearch['status'] ) ) {
			$cachedSearch['status'] = explode( ',', $cachedSearch['status'] );
		}

		return $cachedSearch;
	}

	/**
	 * Method echo_body()
	 *
	 * Grab & echo cached search body.
	 *
	 * @param mixed $page Page information.
	 */
	public static function echo_body( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'Search' ] ) ) {
			echo $_SESSION[ 'MainWP' . $page . 'Search' ]; // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
	}

	/**
	 * Method add_result()
	 *
	 * Grab search results & store them in session.
	 *
	 * @param mixed $page Page information.
	 * @param mixed $result Search results.
	 */
	public static function add_result( $page, $result ) {
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ] = $result;
	}

	/**
	 * Method get_cached_result()
	 *
	 * Grab cached search results.
	 *
	 * @param mixed $page Page information.
	 */
	public static function get_cached_result( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] ) ) {
			return $_SESSION[ 'MainWP' . $page . 'SearchResult' ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		}
	}
}
