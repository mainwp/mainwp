<?php

class MainWP_Twitter {
	public static function getClassName() {
		return __CLASS__;
	}

	static function get_filter() {
		return array(
			'upgrade_everything',
			'upgrade_all_wp_core',
			'upgrade_all_plugins',
			'upgrade_all_themes',
			'new_post',
			'new_page',
			'installing_new_plugin',
			'installing_new_theme',
			'create_new_user',
		);
	}

	public static function enabledTwitterMessages() {
		if ( ! get_option( 'mainwp_hide_twitters_message', 0 ) ) {
			return true;
		}

		return false;
	}

	public static function clearAllTwitterMessages() {
		$filters = self::get_filter();
		$user_id = get_current_user_id();

		foreach ( $filters as $what ) {
			$opt_name = 'mainwp_tt_message_' . $what;
			delete_user_option( $user_id, $opt_name );
		}
	}

	static function randomWord() {
		$words = array(
			__( 'Awesome', 'mainwp' ),
			__( 'Fabulous', 'mainwp' ),
			__( 'Impressive', 'mainwp' ),
			__( 'Incredible', 'mainwp' ),
			__( 'Super', 'mainwp' ),
			__( 'Wonderful', 'mainwp' ),
			__( 'Wow', 'mainwp' ),
		);

		return $words[ rand( 0, 6 ) ];
	}

	static function getNotice( $what, $value ) {

		if ( ! is_array( $value ) || empty( $value['sites'] ) || ! isset( $value['seconds'] ) ) {
			return '';
		}

		$message    = '';
		$first_word = self::randomWord();
		switch ( $what ) {
			case 'upgrade_everything':
				$message = $first_word . ', ' . _n( 'you just updated <strong>%d</strong> site', 'you just updated <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['sites'] );
				break;
			case 'upgrade_all_wp_core':
				$message = $first_word . ', ' . _n( 'you just updated <strong>%d</strong> WordPress site', 'you just updated <strong>%d</strong> WordPress sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['sites'] );
				break;
			case 'upgrade_all_plugins':
				$message = $first_word . ', ' . _n( 'you just updated <strong>%d</strong> plugin', 'you just updated <strong>%d</strong> plugins', $value['items'], 'mainwp' ) . ' ' . _n( 'on <strong>%d</strong> site', 'on <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['items'], $value['sites'] );
				break;
			case 'upgrade_all_themes':
				$message = $first_word . ', ' . _n( 'you just updated <strong>%d</strong> theme', 'you just updated <strong>%d</strong> themes', $value['items'], 'mainwp' ) . ' ' . _n( 'on <strong>%d</strong> site',  'on <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['items'], $value['sites'] );
				break;
			case 'new_post':
				$message = $first_word . ', ' . _n( 'you just published a new post on <strong>%d</strong> site', 'you just published a new post on <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['sites'] );
				break;
			case 'new_page':
				$message = $first_word . ', ' . _n( 'you just published a new page on <strong>%d</strong> site', 'you just published a new page on <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['sites'] );
				break;
			case 'installing_new_plugin':
				$message = $first_word . ', ' . _n( 'you just installed a new plugin on <strong>%d</strong> site', 'you just installed a new plugin on <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( __( $message ), $value['sites'] );
				break;
			case 'installing_new_theme':
				$message = $first_word . ', ' . _n( 'you just installed a new theme on <strong>%d</strong> site', 'you just installed a new theme on <strong>%d</strong> sites', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['sites'] );
				break;
			case 'create_new_user':
				$message = $first_word . ', ' . _n( 'you just created a new user on <strong>%d</strong> site', 'you just created a new user on <strong>%d</strong> site', $value['sites'], 'mainwp' );
				$message = sprintf( $message, $value['sites'] );
				break;
		}

		if ( ! empty( $message ) ) {
			$in_sec = $value['seconds'];
			if ( $in_sec <= 60 ) {
				if ( $what == 'upgrade_all_plugins' || $what == 'upgrade_all_themes' || $what == 'upgrade_everything' ) {
					$real_updated = $value['real_items'];
					$message .= ', ' . sprintf( _n( '<strong>%d</strong> total update', '<strong>%d</strong> total updates', $real_updated, 'mainwp' ), $real_updated );
				}
				$message .= ' ' . sprintf( _n( 'in <strong>%d</strong> second', 'in <strong>%d</strong> seconds', $in_sec, 'mainwp' ), $in_sec );
			}
			$message .= '!';
		}

		return $message;
	}

	public static function genTwitterButton( $content, $echo = true ) {
		ob_start();$content
		?>
		<button class="mainwp_tweet_this" msg="<?php echo urlencode($content); ?>">
			<i class="fa fa-twitter fa-1x" style="color: #4099FF;"></i>&nbsp;
			<?php _e( 'Brag on Twitter', 'mainwp' ); ?></button>
		<?php
		$return = ob_get_clean();

		if ( $echo ) {
			echo $return;
		} else {
			return $return;
		}
	}

	public static function updateTwitterInfo( $what, $countSites = 0, $countSec = 0, $coutRealItems = 0, $twId = 0, $countItems = 1 ) {
		if ( empty( $twId ) ) {
			return false;
		}

		$filters = self::get_filter();

		if ( ! in_array( $what, $filters ) ) {
			return false;
		}

		$clear_twit = false;
		if ( empty($coutRealItems) || $coutRealItems == 1 ) {
			$clear_twit = true;
		}

		$opt_name = 'mainwp_tt_message_' . $what;
		$user_id = get_current_user_id();

		if ( $clear_twit ) {
			delete_user_option( $user_id, $opt_name );
		} else {
			if ( empty( $countSec ) ) $countSec = 1;
			// store one twitt info only
			$data = array( $twId => array( 'sites' => $countSites, 'seconds' => $countSec, 'items' => $countItems, 'real_items' => $coutRealItems ) );
			if ( update_user_option( $user_id, $opt_name, $data ) ) {
				return true;
			}
		}
		return false;
	}

	public static function clearTwitterInfo( $what, $twId = 0 ) {
		if ( empty( $twId ) ) {
			return false;
		}

		$filters = self::get_filter();

		if ( ! in_array( $what, $filters ) ) {
			return false;
		}

		$opt_name = 'mainwp_tt_message_' . $what;

		$data = get_user_option( $opt_name );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( isset( $data[ $twId ] ) ) {
			unset( $data[ $twId ] );
			$user_id = get_current_user_id();
			update_user_option( $user_id, $opt_name, $data );
		}

		return true;
	}

	public static function getTwitterNotice( $what, $twId = 0 ) {

		$filters = self::get_filter();

		if ( ! in_array( $what, $filters ) ) {
			return false;
		}

		$opt_name         = 'mainwp_tt_message_' . $what;
		$twitter_messages = get_user_option( $opt_name );

		$return = array();

		if ( is_array( $twitter_messages ) ) {
			if ( ! empty( $twId ) ) {
				if ( isset( $twitter_messages[ $twId ] ) ) {
					$value = $twitter_messages[ $twId ];
					$mess  = self::getNotice( $what, $value );
					if ( ! empty( $mess ) ) {
						$return[ $twId ] = $mess;
					}
				}
			} else {
				foreach ( $twitter_messages as $time => $value ) {
					$mess = self::getNotice( $what, $value );
					if ( ! empty( $mess ) ) {
						$return[ $time ] = $mess;
					}
				}
			}
		}

		return $return;
	}

	public static function getTwitToSend( $what, $twId = 0 ) {

		$filters = self::get_filter();

		if ( ! in_array( $what, $filters ) ) {
			return '';
		}
		//@MyMainWP I just quickly updated 3 plugins on 3 #WordPress sites, 5 total updates in 12 seconds
		$opt_name         = 'mainwp_tt_message_' . $what;
		$twitter_messages = get_user_option( $opt_name );
		if ( is_array( $twitter_messages[ $twId ] ) && isset( $twitter_messages[ $twId ] ) ) {
			$value = $twitter_messages[ $twId ];
			if ( is_array( $value ) && ! empty( $value['sites'] ) && ! empty( $value['seconds'] ) ) {
				$twit = '';
				switch ( $what ) {
					case 'upgrade_everything':
						$twit = _n( 'Thanks to @MyMainWP I just quickly updated %d #WordPress site', 'Thanks to @MyMainWP I just quickly updated %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
					case 'upgrade_all_wp_core':
						$twit = _n( 'Thanks to @MyMainWP I just quickly updated %d #WordPress site', 'Thanks to @MyMainWP I just quickly updated %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
					case 'upgrade_all_plugins':
						$twit = _n( 'Thanks to @MyMainWP I just quickly updated %d plugin', 'Thanks to @MyMainWP I just quickly updated %d plugins', $value['items'], 'mainwp' ) . ' ' . _n( 'on %d #WordPress site', 'on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['items'], $value['sites'] );
						break;
					case 'upgrade_all_themes':
						$twit = _n( 'Thanks to @MyMainWP I just quickly updated %d theme', 'Thanks to @MyMainWP I just quickly updated %d themes', $value['items'], 'mainwp' ) . ' ' . _n('on %d #WordPress site', 'on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['items'], $value['sites'] );
						break;
					case 'new_post':
						$twit = _n( 'Thanks to @MyMainWP I just quickly published a new post on %d #WordPress site', 'Thanks to @MyMainWP I just quickly published a new post on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
					case 'new_page':
						$twit = _n( 'Thanks to @MyMainWP I just quickly published a new page on %d #WordPress site', 'Thanks to @MyMainWP I just quickly published a new page on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
					case 'installing_new_plugin':
						$twit = _n( 'Thanks to @MyMainWP I just quickly installed a new plugin on %d #WordPress site', 'Thanks to @MyMainWP I just quickly installed a new plugin on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
					case 'installing_new_theme':
						$twit = _n( 'Thanks to @MyMainWP I just quickly installed a new theme on %d #WordPress site', 'Thanks to @MyMainWP I just quickly installed a new theme on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
					case 'create_new_user':
						$twit = _n( 'Thanks to @MyMainWP I just quickly created a new user on %d #WordPress site', 'Thanks to @MyMainWP I just quickly created a new user on %d #WordPress sites', $value['sites'], 'mainwp' );
						$twit = sprintf( $twit, $value['sites'] );
						break;
				}
				if ( ! empty( $twit ) ) {
					$in_sec = $value['seconds'];
					if ( $in_sec <= 60 ) {
						if ( $what == 'upgrade_all_plugins' || $what == 'upgrade_all_themes' || $what == 'upgrade_everything' ) {
							$real_updated = $value['real_items'];
							$twit .= ', ' . sprintf( _n( '%d total update', '%d total updates', $real_updated, 'mainwp' ), $real_updated );
						}
						$twit .= ' ' . sprintf( _n( 'in %d second', 'in %d seconds', $in_sec, 'mainwp' ), $in_sec );
					}
					$twit .= '! https://mainwp.com';

					return $twit;
				}
			}
		}

		return '';
	}
}
