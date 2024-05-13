<?php
/**
 * MainWP Tokens Reports Class.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Reports_Helper.
 *
 * @package MainWP\Dashboard
 */
class MainWP_Reports_Helper {

	/**
	 * Reports sites values
	 *
	 * @static
	 * @var array $reports_sites_values array values.
	 */
	private static $reports_sites_values = array();

	/**
	 * Protected static variable to hold the instance.
	 *
	 * @var null Default value.
	 */
	private static $instance = null;

	/**
	 * Create Instance.
	 *
	 * @return self $instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct method.
	 */
	public function __construct() {
		// construct.
	}


	/**
	 * Method hook_get_reports_group_values()
	 *
	 * Get dashboard tokens values for reports.
	 *
	 * @param array  $values Input values.
	 * @param string $group Group tokens.
	 * @param array  $types Types of tokens.
	 * @param int    $site_id Site ID.
	 */
	public function hook_get_reports_group_values( $values, $group, $types, $site_id ) {
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		if ( ! isset( self::$reports_sites_values[ $site_id ] ) ) {
			self::$reports_sites_values[ $site_id ] = $this->get_group_reports_data_of_site( $site_id );
		}

		if ( empty( $group ) && empty( $types ) ) {
			return isset( self::$reports_sites_values[ $site_id ] ) ? self::$reports_sites_values[ $site_id ] : array();
		}

		if ( ! empty( $group ) && empty( $types ) ) {
			$values[ $group ] = isset( self::$reports_sites_values[ $site_id ][ $group ] ) ? self::$reports_sites_values[ $site_id ][ $group ] : array();
			return $values;
		}

		if ( ! empty( $group ) && is_array( $types ) ) {
			foreach ( $types as $type ) {
				$values[ $group ][ $type ] = isset( self::$reports_sites_values[ $site_id ][ $group ][ $type ] ) ? self::$reports_sites_values[ $site_id ][ $group ][ $type ] : array();
			}
		}

		return $values;
	}

	/**
	 * Get tokens values of site.
	 *
	 * @param int $site_id Site ID.
	 */
	public function get_group_reports_data_of_site( $site_id ) {

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$abandoned_plugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
		$abandoned_plugins = ! empty( $abandoned_plugins ) ? json_decode( $abandoned_plugins, true ) : array();

		if ( ! is_array( $abandoned_plugins ) ) {
			$abandoned_plugins = array();
		}

		$abandoned_themes = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
		$abandoned_themes = ! empty( $abandoned_themes ) ? json_decode( $abandoned_themes, true ) : array();

		if ( ! is_array( $abandoned_themes ) ) {
			$abandoned_themes = array();
		}

		$wp_upgrades = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
		$wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

		$plugin_upgrades      = json_decode( $website->plugin_upgrades, true );
		$theme_upgrades       = json_decode( $website->theme_upgrades, true );
		$translation_upgrades = json_decode( $website->translation_upgrades, true );

		$results = array(
			'plugins'     => array(
				'abandoned' => $abandoned_plugins,
				'pending'   => $plugin_upgrades,
			),
			'themes'      => array(
				'abandoned' => $abandoned_themes,
				'pending'   => $theme_upgrades,
			),
			'wordpress'  => array( // phpcs:ignore -- wordpress.
				'pending' => array( 'wordpress' => $wp_upgrades ), // phpcs:ignore -- wordpress.
			),
			'translation' => array(
				'pending' => $translation_upgrades,
			),
		);
		return $results;
	}
}
