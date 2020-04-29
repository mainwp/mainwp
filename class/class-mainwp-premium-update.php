<?php
/**
 * MainWP Premium Update
 *
 * MainWP Premium Update functions.
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.
/**
 * MainWP Utility
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
			);

			$premiums = apply_filters( 'mainwp_detect_premiums_updates', $premiums );

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

	public static function check_request_update_premium( $list, $type ) {

		$updates = explode( ',', $list );

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

		MainWP_Logger::instance()->debug( ' :: tryRequest :: [website=' . $website->url . '] [url=' . $where_url . ']' );

		$reponse = wp_remote_get( $request_url, $args );
		$body    = is_array( $reponse ) && isset( $reponse['body'] ) ? $reponse['body'] : '';

		MainWP_Logger::instance()->debug( ' :: Response :: ' . $body );

		return $reponse;
	}

	public static function request_premiums_update( $website, $type, $list ) {
		if ( 'plugin' === $type ) {
			$where_url = 'plugins.php?_request_update_premiums_type=plugin&list=' . $list;
		} elseif ( 'theme' === $type ) {
			$where_url = 'update-core.php?_request_update_premiums_type=theme&list=' . $list;
		} else {
			return null;
		}
		self::redirect_request_site( $website, $where_url );
		return true;
	}

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
