<?php
/**
 * MainWP Premium Update
 *
 * MainWP Premium Update functions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

/**
 * Class MainWP_Premium_Update
 *
 * @package MainWP\Dashboard
 *
 * Check for premium plugin updates.
 */
class MainWP_Premium_Update {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method check_premium_updates()
	 *
	 * Check for Premium Plugin updates.
	 *
	 * @param array $updates Array of updates.
	 * @param mixed $type Type of update.
	 *
	 * @return boolean true|false.
	 */
	public static function check_premium_updates( $updates, $type ) {

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return false;
		}

		if ( 'plugin' === $type ) {

			$premiums = array(
				'ithemes-security-pro/ithemes-security-pro.php',
				'monarch/monarch.php',
				'cornerstone/cornerstone.php',
				'updraftplus/updraftplus.php',
				'wp-all-import-pro/wp-all-import-pro.php',
				'bbq-pro/bbq-pro.php',
				'seedprod-coming-soon-pro-5/seedprod-coming-soon-pro-5.php',
				'oxygen/functions.php',
				'elementor-pro/elementor-pro.php',
				'bbpowerpack/bb-powerpack.php',
				'bb-ultimate-addon/bb-ultimate-addon.php',
				'webarx/webarx.php',
				'leco-client-portal/leco-client-portal.php',
				'elementor-extras/elementor-extras.php',
				'wp-schema-pro/wp-schema-pro.php',
				'convertpro/convertpro.php',
				'astra-addon/astra-addon.php',
				'astra-portfolio/astra-portfolio.php',
				'astra-pro-sites/astra-pro-sites.php',
				'custom-facebook-feed-pro/custom-facebook-feed.php',
				'convertpro/convertpro.php',
				'convertpro-addon/convertpro-addon.php',
				'wp-schema-pro/wp-schema-pro.php',
				'ultimate-elementor/ultimate-elementor.php',
				'gp-premium/gp-premium.php',
				'flying-press/flying-press.php',
				'wp-rocket/wp-rocket.php',
				'fluentformpro/fluentformpro.php',
				'fluentform-signature/fluentform-signature.php',
				'fluentcampaign-pro/fluentcampaign-pro.php',
				'fluent-support-pro/fluent-support-pro.php',
				'ninja-tables-pro/ninja-tables-pro.php',
				'fluent-booking-pro/fluent-booking-pro.php',
				'wp-social-ninja-pro/wp-social-ninja-pro.php',
				'wp-payment-form-pro/wp-payment-form-pro.php',
			);

			/**
			 * Filter: mainwp_detect_premiums_updates
			 *
			 * Use mainwp_detect_premium_plugins_update instead.
			 *
			 * @deprecated
			 */
			$premiums = apply_filters( 'mainwp_detect_premiums_updates', $premiums );

			/**
			 * Filter: mainwp_detect_premium_plugins_update
			 *
			 * Filters supported premium plugins to fix compatiblity issues with detecting premium plugins updates.
			 *
			 * @since Unknown
			 */
			$premiums = apply_filters( 'mainwp_detect_premium_plugins_update', $premiums );

			if ( is_array( $premiums ) && 0 < count( $premiums ) ) {
				foreach ( $updates as $info ) {
					if ( isset( $info['slug'] ) ) {
						if ( in_array( $info['slug'], $premiums ) ) {
							return true;
						} elseif ( false !== strpos( $info['slug'], 'yith-' ) ) {
							return true;
						}
					}
				}
			}
		} elseif ( 'theme' === $type ) {

			$premiums = array();

			/**
			 * Filter: mainwp_detect_premium_themes_update
			 *
			 * Filters supported premium themes to fix compatiblity issues with detecting premium themes updates.
			 *
			 * @since Unknown
			 */
			$premiums = apply_filters( 'mainwp_detect_premium_themes_update', $premiums );

			if ( is_array( $premiums ) && 0 < count( $premiums ) ) {
				foreach ( $updates as $info ) {
					if ( isset( $info['slug'] ) ) {
						if ( in_array( $info['slug'], $premiums ) ) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Method maybe_request_premium_updates()
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $what stats|upgradeplugintheme What function to perform.
	 * @param mixed $params plugin|theme Update Type.
	 *
	 * @return mixed $request_update
	 */
	public static function maybe_request_premium_updates( $website, $what, $params ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$request_update = false;
		if ( 'stats' === $what || ( 'upgradeplugintheme' === $what && isset( $params['type'] ) ) ) {

			$update_type = '';

			$check_premi_plugins = array();
			$check_premi_themes  = array();

			if ( 'stats' === $what ) {
				if ( '' !== $website->plugins ) {
					$check_premi_plugins = json_decode( $website->plugins, 1 );
				}
				if ( '' !== $website->themes ) {
					$check_premi_themes = json_decode( $website->themes, 1 );
				}
			} elseif ( 'upgradeplugintheme' === $what ) {
				$update_type = ( isset( $params['type'] ) ) ? $params['type'] : '';
				if ( 'plugin' === $update_type ) {
					if ( '' !== $website->plugins ) {
						$check_premi_plugins = json_decode( $website->plugins, 1 );
					}
				} elseif ( 'theme' === $update_type ) {
					if ( '' !== $website->themes ) {
						$check_premi_themes = json_decode( $website->themes, 1 );
					}
				}
			}

			if ( self::check_premium_updates( $check_premi_plugins, 'plugin' ) ) {
				self::try_to_detect_premiums_update( $website, 'plugin' );
			}

			if ( self::check_premium_updates( $check_premi_themes, 'theme' ) ) {
				self::try_to_detect_premiums_update( $website, 'theme' );
			}

			if ( 'upgradeplugintheme' === $what ) {
				if ( 'plugin' === $update_type || 'theme' === $update_type ) {
					if ( self::check_request_update_premium( $params['list'], $update_type ) ) {
						self::request_premiums_update( $website, $update_type, $params['list'] );
						$request_update = true;
					}
				}
			}
		}

		return $request_update;
	}

	/**
	 * Method check_request_update_premium()
	 *
	 * Check if any updates are on the premiums list.
	 *
	 * @param array  $list_items List of updates.
	 * @param string $type Type of update. plugin|theme.
	 *
	 * @return bool true|false.
	 */
	public static function check_request_update_premium( $list_items, $type ) {

		$updates = explode( ',', $list_items );

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return false;
		}

		if ( 1 < count( $updates ) ) {
			return false;
		}

		if ( 'plugin' === $type ) {

			$update_premiums = array(
				'yith-woocommerce-request-a-quote-premium/init.php',
			);

			/**
			 * Filter: mainwp_request_update_premium_plugins
			 *
			 * Filters supported premium plugins to fix compatibility problmes with updating premium plugins.
			 *
			 * @since Unknown
			 */
			$update_premiums = apply_filters( 'mainwp_request_update_premium_plugins', $update_premiums );

			if ( is_array( $update_premiums ) && 0 < count( $update_premiums ) ) {
				foreach ( $updates as $slug ) {
					if ( ! empty( $slug ) ) {
						if ( in_array( $slug, $update_premiums ) ) {
							return true;
						}
					}
				}
			}
		} elseif ( 'theme' === $type ) {

			$update_premiums = array();

			/**
			 * Filter: mainwp_request_update_premium_themes
			 *
			 * Filters supported premium themes to fix compatibility problmes with updating premium themes.
			 *
			 * @since Unknown
			 */
			$update_premiums = apply_filters( 'mainwp_request_update_premium_themes', $update_premiums );
			if ( is_array( $update_premiums ) && 0 < count( $update_premiums ) ) {
				foreach ( $themes as $slug ) {
					if ( ! empty( $slug ) ) {
						if ( in_array( $slug, $update_premiums ) ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * Method redirect_request_site()
	 *
	 * Redirect to requested Site.
	 *
	 * @param mixed $website Child Site.
	 * @param mixed $where_url page to redirerct to.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 * @uses \MainWP\Dashboard\MainWP_System::$version
	 */
	public static function redirect_request_site( $website, $where_url ) {

		$request_url = MainWP_Connect::get_get_data_authed( $website, $where_url );

		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$args  = array(
			'timeout'     => 25,
			'httpversion' => '1.1',
			'User-Agent'  => $agent,
		);

		if ( ! empty( $website->http_user ) && ! empty( $website->http_pass ) ) {
			$args['headers'] = array(
				'Authorization' => 'Basic ' . base64_encode( $website->http_user . ':' . stripslashes( $website->http_pass ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			);
		}

		MainWP_Logger::instance()->debug( ' :: tryRequest :: [website=' . $website->url . ']' );
		return wp_remote_get( $request_url, $args );
	}

	/**
	 * Method request_premiums_update()
	 *
	 * Request to update plugin or theme.
	 *
	 * @param mixed $website Child Site to update.
	 * @param mixed $type Type of update, plugin|theme.
	 * @param mixed $list_items list of plugins & themes installed.
	 *
	 * @return mixed null|true.
	 */
	public static function request_premiums_update( $website, $type, $list_items ) {
		if ( 'plugin' === $type ) {
			$where_url = 'plugins.php?_request_update_premiums_type=plugin&list=' . $list_items;
		} elseif ( 'theme' === $type ) {
			$where_url = 'update-core.php?_request_update_premiums_type=theme&list=' . $list_items;
		} else {
			return null;
		}
		self::redirect_request_site( $website, $where_url );
		return true;
	}

	/**
	 * Method try_to_detect_premiums_update()
	 *
	 * Try to detect if pugin and themes are premium.
	 *
	 * @param mixed $website Child Site.
	 * @param mixed $type Type of update, plugin|theme.
	 *
	 * @return mixed false|self::redirect_request_site()
	 */
	public static function try_to_detect_premiums_update( $website, $type ) {
		if ( 'plugin' === $type ) {
			$where_url = 'plugins.php?_detect_plugins_updates=yes';
		} elseif ( 'theme' === $type ) {
			$where_url = 'update-core.php?_detect_themes_updates=yes';
		} else {
			return false;
		}
		self::redirect_request_site( $website, $where_url );
	}
}
