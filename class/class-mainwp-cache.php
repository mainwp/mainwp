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
	 * Method init_session()
	 * 
	 * Start a Session.
	 */
	public static function init_session() {
		if ( '' === session_id() ) {
			session_start();
		}
	}

	/**
	 * Method init_cache()
	 * 
	 * Set session variables.
	 * 
	 * @param mixed $page
	 * 
	 */
	public static function init_cache( $page ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ]        = '';
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = '';
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ]  = '';
	}
  
	/**
	 * Method add_context()
	 * 
	 * Set time & Search Context. 
	 * 
	 * @param mixed $page
	 * @param mixed $context
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
	 * Set body Session Variable. 
	 * 
	 * @param mixed $page
	 * @param mixed $body
	 * 
	 * @return void
	 */
	public static function add_body( $page, $body ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ] .= $body;
	}

	/**
	 * Method get_cached_context()
	 * 
	 * Grab any cached searches.
	 * 
	 * @param mixed $page
	 * 
	 * @return mixed $cachedSearch
	 */
	public static function get_cached_context( $page ) {
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
	 * Method echo_body()
	 * 
	 * Grab & echo cached search body.
	 * 
	 * @param mixed $page
	 * @return $body
	 */
	public static function echo_body( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'Search' ] ) ) {
			echo $_SESSION[ 'MainWP' . $page . 'Search' ];
		}
	}

	/**
	 * Method add_result()
	 * 
	 * Grab Search Results & Store them in Session.
	 * 
	 * @param mixed $page
	 * @param mixed $result
	 */
	public static function add_result( $page, $result ) {
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ] = $result;
	}

	/**
	 * Method get_cached_result()
	 * 
	 * Grab cached Search Results.
	 * 
	 * @param mixed $page
	 */
	public static function get_cached_result( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] ) ) {
			return $_SESSION[ 'MainWP' . $page . 'SearchResult' ];
		}
	}

}
