<?php

class MainWP_Cache {

	public static function initSession() {
		if ( session_id() == '' ) {
			session_start();
		}
	}

	public static function initCache( $page ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ]        = '';
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = '';
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ]  = ''; // extra cache
	}

	public static function addContext( $page, $context ) {
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$context['time']                                = time();
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = $context;
	}

	public static function addBody( $page, $body ) {
		$_SESSION[ 'MainWP' . $page . 'Search' ] .= $body;
	}

	public static function getCachedContext( $page ) {
		$cachedSearch = ( isset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) && is_array( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) ? $_SESSION[ 'MainWP' . $page . 'SearchContext' ] : null );

		if ( $cachedSearch != null ) {
			if ( $cachedSearch['time'] < ( time() - ( 2 * 60 * 60 ) ) ) {
				// More then two hours ago, clean this cache
				unset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'Search' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] );
				$cachedSearch = null;
			}
		}
		if ( $cachedSearch != null && isset( $cachedSearch['status'] ) ) {
			$cachedSearch['status'] = explode( ',', $cachedSearch['status'] );
		}

		return $cachedSearch;
	}

	public static function echoBody( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'Search' ] ) ) {
			echo $_SESSION[ 'MainWP' . $page . 'Search' ];
		}
	}

	public static function addResult( $page, $result ) {
		$_SESSION[ 'MainWP' . $page . 'SearchResult' ] = $result;
	}

	public static function getCachedResult( $page ) {
		if ( isset( $_SESSION[ 'MainWP' . $page . 'SearchResult' ] ) ) {
			return $_SESSION[ 'MainWP' . $page . 'SearchResult' ];
		}
	}

}
