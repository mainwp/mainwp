<?php
/**
 * MainWP Twitter Bragger
 *
 * Each time a Child Site is updated, build a Tweet to be sent out to brag
 * that MainWP was used and how fast it was.
 *
 * @package MainWP/Twitter
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Twitter
 *
 * @package MainWP\Dashboard
 */
class MainWP_Twitter {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return string CLASS Class Name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}


	/**
	 * Method get_filter()
	 *
	 * @return array Filter List.
	 */
	public static function get_filter() {
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

	/**
	 * Method enabled_twitter_messages()
	 *
	 * Check if Twitter Bragger should be hidden or not.
	 *
	 * @return boolean True|False.
	 */
	public static function enabled_twitter_messages() {
		if ( ! get_option( 'mainwp_hide_twitters_message', 0 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method clear_all_twitter_messages()
	 *
	 * Clear the twitter messages for current user.
	 */
	public static function clear_all_twitter_messages() {
		$filters = self::get_filter();
		$user_id = get_current_user_id();

		foreach ( $filters as $what ) {
			$opt_name = 'mainwp_tt_message_' . $what;
			delete_user_option( $user_id, $opt_name );
		}
	}

	/**
	 * Method random_word()
	 *
	 * Array of random words to use in each tweet.
	 *
	 * @return string $words
	 */
	public static function random_word() {
		$words = array(
			__( 'Awesome', 'mainwp' ),
			__( 'Fabulous', 'mainwp' ),
			__( 'Impressive', 'mainwp' ),
			__( 'Incredible', 'mainwp' ),
			__( 'Super', 'mainwp' ),
			__( 'Wonderful', 'mainwp' ),
			__( 'Wow', 'mainwp' ),
		);

		return $words[ wp_rand( 0, 6 ) ];
	}

	/**
	 * Method get_notice()
	 *
	 * Build Twitter message based on what action was performed.
	 *
	 * @param mixed $what Performed action.
	 * @param mixed $value Performed action details.
	 *
	 * @return mixed $message Twit content.
	 */
	public static function get_notice( $what, $value ) {

		if ( ! is_array( $value ) || empty( $value['sites'] ) || ! isset( $value['seconds'] ) ) {
			return '';
		}

		$message    = '';
		$first_word = self::random_word();
		switch ( $what ) {
			case 'upgrade_everything':
				$message = _n( sprintf( 'you just updated <strong>%d</strong> site', $value['sites'] ), sprintf( 'you just updated <strong>%d</strong> sites', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'upgrade_all_wp_core':
				$message = _n( sprintf( 'you just updated <strong>%d</strong> WordPress site', $value['sites'] ), sprintf( 'you just updated <strong>%d</strong> WordPress sites', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'upgrade_all_plugins':
				$message = _n( sprintf( 'you just updated <strong>%d</strong> plugin', $value['sites'] ), sprintf( 'you just updated <strong>%d</strong> plugins', $value['sites'] ), $value['items'], 'mainwp' ) . ' ' . _n( sprintf( 'on <strong>%d</strong> site', $value['sites'] ), sprintf( 'on <strong>%d</strong> sites', $value['sites'] ), $value['items'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'upgrade_all_themes':
				$message = _n( sprintf( 'you just updated <strong>%d</strong> theme', $value['sites'] ), sprintf( 'you just updated <strong>%d</strong> themes', $value['sites'] ), $value['items'], 'mainwp' ) . ' ' . _n( sprintf( 'on <strong>%d</strong> site', 'on <strong>%d</strong> sites', $value['sites'], 'mainwp' ), $value['items'], $value['sites'] );
				$message = $first_word . ', ' . $message;
				break;
			case 'new_post':
				$message = _n( sprintf( 'you just published a new post on <strong>%d</strong> site', $value['sites'] ), sprintf( 'you just published a new post on <strong>%d</strong> sites', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'new_page':
				$message = _n( sprintf( 'you just published a new page on <strong>%d</strong> site', $value['sites'] ), sprintf( 'you just published a new page on <strong>%d</strong> sites', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'installing_new_plugin':
				$message = _n( sprintf( 'you just installed a new plugin on <strong>%d</strong> site', $value['sites'] ), sprintf( 'you just installed a new plugin on <strong>%d</strong> sites', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'installing_new_theme':
				$message = _n( sprintf( 'you just installed a new theme on <strong>%d</strong> site', $value['sites'] ), sprintf( 'you just installed a new theme on <strong>%d</strong> sites', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
			case 'create_new_user':
				$message = _n( sprintf( 'you just created a new user on <strong>%d</strong> site', $value['sites'] ), sprintf( 'you just created a new user on <strong>%d</strong> site', $value['sites'] ), $value['sites'], 'mainwp' );
				$message = $first_word . ', ' . $message;
				break;
		}

		if ( ! empty( $message ) ) {
			$in_sec = $value['seconds'];
			if ( $in_sec <= 60 ) {
				if ( 'upgrade_all_plugins' == $what || 'upgrade_all_themes' == $what || 'upgrade_everything' == $what ) {
					$real_updated = $value['real_items'];
					$message     .= ', ' . _n( sprintf( '<strong>%d</strong> total update', $real_updated ), sprintf( '<strong>%d</strong> total updates', $real_updated ), $real_updated, 'mainwp' );
				}
				$message .= ' ' . _n( sprintf( 'in <strong>%d</strong> second', $real_updated ), sprintf( 'in <strong>%d</strong> seconds', $in_sec ), $in_sec, 'mainwp' );
			}
			$message .= '!';
		}

		return $message;
	}

	/**
	 * Method gen_twitter_button()
	 *
	 * Build Twitter Brag button.
	 *
	 * @param mixed   $content Twit content.
	 * @param boolean $echo Echo or return content.
	 *
	 * @return mixed $return Button HTML
	 */
	public static function gen_twitter_button( $content, $echo = true ) {
		ob_start();
		$content
		?>
		<button class="ui mini twitter button mainwp_tweet_this" msg="<?php echo rawurlencode( $content ); ?>">
			<i class="twitter icon"></i>
			<?php esc_html_e( 'Brag on Twitter', 'mainwp' ); ?>
		</button>
		<?php
		$return = ob_get_clean();

		if ( $echo ) {
			echo $return;
		} else {
			return $return;
		}
	}

	/**
	 * Method update_twitter_info()
	 *
	 * Build Twitter message to be sent.
	 *
	 * @param mixed   $what What task was performed.
	 * @param integer $countSites Number of Sites updated.
	 * @param integer $countSec Second it took to update.
	 * @param integer $coutRealItems Number of items updated.
	 * @param integer $twId Twitter ID.
	 * @param integer $countItems Total number of Items together.
	 *
	 * @return boolean True|False.
	 */
	public static function update_twitter_info( $what, $countSites = 0, $countSec = 0, $coutRealItems = 0, $twId = 0, $countItems = 1 ) {
		if ( empty( $twId ) ) {
			return false;
		}

		$filters = self::get_filter();

		if ( ! in_array( $what, $filters ) ) {
			return false;
		}

		$clear_twit = false;
		if ( empty( $coutRealItems ) || 1 == $coutRealItems ) {
			$clear_twit = true;
		}

		$opt_name = 'mainwp_tt_message_' . $what;
		$user_id  = get_current_user_id();

		if ( $clear_twit ) {
			delete_user_option( $user_id, $opt_name );
		} else {
			if ( empty( $countSec ) ) {
				$countSec = 1;
			}
			// store one twitt info only.
			$data = array(
				$twId => array(
					'sites'      => $countSites,
					'seconds'    => $countSec,
					'items'      => $countItems,
					'real_items' => $coutRealItems,
				),
			);
			if ( update_user_option( $user_id, $opt_name, $data ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Method clear_twitter_info
	 *
	 * @param mixed   $what What task was performed.
	 * @param integer $twId Twitter ID.
	 *
	 * @return boolean True|False.
	 */
	public static function clear_twitter_info( $what, $twId = 0 ) {
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

	/**
	 * Method get_twitter_notice()
	 *
	 * Grab Twitter message that was built.
	 *
	 * @param mixed   $what What task was performed.
	 * @param integer $twId Twitter ID.
	 *
	 * @return mixed $return[ $time ] = $mess.
	 */
	public static function get_twitter_notice( $what, $twId = 0 ) {

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
					$mess  = self::get_notice( $what, $value );
					if ( ! empty( $mess ) ) {
						$return[ $twId ] = $mess;
					}
				}
			} else {
				foreach ( $twitter_messages as $time => $value ) {
					$mess = self::get_notice( $what, $value );
					if ( ! empty( $mess ) ) {
						$return[ $time ] = $mess;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Method get_twit_to_send()
	 *
	 * Example @MyMainWP I just quickly updated 3 plugins on 3 #WordPress sites, 5 total updates in 12 seconds.
	 *
	 * @param mixed   $what What task was performed.
	 * @param integer $twId Twitter ID.
	 *
	 * @return string Tweet to send.
	 */
	public static function get_twit_to_send( $what, $twId = 0 ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$filters = self::get_filter();

		if ( ! in_array( $what, $filters ) ) {
			return '';
		}

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
						$twit = _n( 'Thanks to @MyMainWP I just quickly updated %d theme', 'Thanks to @MyMainWP I just quickly updated %d themes', $value['items'], 'mainwp' ) . ' ' . _n( 'on %d #WordPress site', 'on %d #WordPress sites', $value['sites'], 'mainwp' );
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
						if ( 'upgrade_all_plugins' == $what || 'upgrade_all_themes' == $what || 'upgrade_everything' == $what ) {
							$real_updated = $value['real_items'];
							$twit        .= ', ' . sprintf( _n( '%d total update', '%d total updates', $real_updated, 'mainwp' ), $real_updated );
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
