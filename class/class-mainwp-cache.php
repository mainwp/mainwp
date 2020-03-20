<?php
/**
 * Search Cache Handler
 * 
 * Handles all search content.
 */

/**
 * Class MainWP_Cache
 */
class MainWP_Cache {

	
	/**
	 * Method initSession()
	 * 
	 * Start a Session.
	 */
	public static function initSession() {
		if ( '' === session_id() ) {
			session_start();
		}
	}

	/**
	 * Method initCache()
	 * 
	 * Set session variables.
	 * 
	 * @param mixed $page
	 * 
	 */
	public static function initCache( $page ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ]        = '';
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = '';
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ]  = '';
	}

	/**
	 * Method addContext()
	 * 
	 * Set time & Search Context. 
	 * 
	 * @param mixed $page
	 * @param mixed $context
	 */
	public static function addContext( $page, $context ) {
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$context['time']                                = time();
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = $context;
	}

	/**
	 * Method addbody()
	 * 
	 * Set body Session Variable. 
	 * 
	 * @param mixed $page
	 * @param mixed $body
	 * 
	 * @return void
	 */
	public static function addBody( $page, $body ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ] .= $body;
	}

	/**
	 * Method getCachedContext()
	 * 
	 * Grab any cached searches.
	 * 
	 * @param mixed $page
	 * 
	 * @return mixed $cachedSearch
	 */
	public static function getCachedContext( $page ) {
		$cachedSearch = ( isset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) && is_array( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) ? $_SESSION[ 'MainWP' . $page . 'SearchContext' ] : null );

		if ( null != $cachedSearch ) {
			if ( ( time() - ( 2 * 60 * 60 ) ) > $cachedSearch['time'] ) {
				unset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'Search' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] );
				$cachedSearch = null;
			}
		}
		if ( null != $cachedSearch && isset( $cachedSearch['status'] ) ) {
			$cachedSearch['status'] = explode( ',', $cachedSearch['status'] );
		}

		return $cachedSearch;
	}


	/**
	 * Method echoBody()
	 * 
	 * Grab & echo cached search body.
	 * 
	 * @param mixed $page
	 * @return $body
	 */
	public static function echoBody( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'Search' ] ) ) {
			echo $_SESSION[ 'MainWP' . $page . 'Search' ];
		}
	}

	/**
	 * Method addResult()
	 * 
	 * Grab Search Results & Store them in Session.
	 * 
	 * @param mixed $page
	 * @param mixed $result
	 */
	public static function addResult( $page, $result ) {
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ] = $result;
	}

	/**
	 * Method getCachedResult()
	 * 
	 * Grab cached Search Results.
	 * 
	 * @param mixed $page
	 */
	public static function getCachedResult( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] ) ) {
			return $_SESSION[ 'MainWP' . $page . 'SearchResult' ];
		}
	}

}
