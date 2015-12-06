<?php

class MainWP_Cache {
	public static function initCache( $page ) {
		if ( session_id() == '' ) {
			session_start();
		}
		$_SESSION[ 'MainWP' . $page . 'Search' ]        = '';
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = '';
	}

	public static function addContext( $page, $context ) {
		if ( session_id() == '' ) {
			session_start();
		}
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$context['time']                                = time();
		$_SESSION[ 'MainWP' . $page . 'SearchContext' ] = $context;
	}

	public static function addBody( $page, $body ) {
		if ( session_id() == '' ) {
			session_start();
		}
		$_SESSION[ 'MainWP' . $page . 'Search' ] .= $body;
	}

	public static function getCachedContext( $page ) {
		if ( session_id() == '' ) {
			session_start();
		}
		$cachedSearch = ( isset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) && is_array( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] ) ? $_SESSION[ 'MainWP' . $page . 'SearchContext' ] : null );

		if ( $cachedSearch != null ) {
			if ( $cachedSearch['time'] < ( time() - ( 2 * 60 * 60 ) ) ) {
				//More then two hours ago, clean this cache
				unset( $_SESSION[ 'MainWP' . $page . 'SearchContext' ] );
				unset( $_SESSION[ 'MainWP' . $page . 'tSearch' ] );
				$cachedSearch = null;
			}
		}
		if ( $cachedSearch != null && isset( $cachedSearch['status'] ) ) {
			$cachedSearch['status'] = explode( ',', $cachedSearch['status'] );
		}

		return $cachedSearch;
	}

	public static function echoBody( $page ) {
		if ( session_id() == '' ) {
			session_start();
		}
		if ( isset( $_SESSION[ 'MainWP' . $page . 'Search' ] ) ) {
			echo $_SESSION[ 'MainWP' . $page . 'Search' ];
		}
	}
}
