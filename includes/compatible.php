<?php
/**
 * MainWP compatible legacy functions.
 *
 * @package MainWP/Dashboard
 */

// phpcs:disable -- legacy functions for backwards compatibility. Required.

if ( ! class_exists( 'MainWP_DB' ) ) {

	/**
	 * MainWP Database Compatible class.
	 */
	class MainWP_DB {

		/**
		 * Private static variable to hold the single instance of the class.
		 *
		 * @var mixed Default null
		 */
		private static $instance = null;

		/**
		 * Create public static instance.
		 *
		 * @return MainWP_DB
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Get child site by ID.
		 *
		 * @param int   $id           Child site ID.
		 * @param array $selectGroups Select groups.
		 *
		 * @return object|null Database query results or null on failure.
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_by_id()
		 */
		public function getWebsiteById( $id, $selectGroups = false ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $id, $selectGroups );
		}

		/**
		 * Get child sites by child site IDs.
		 *
		 * @param array $ids Child site IDs.
		 * @param int   $userId User ID.
		 *
		 * @return object|null Database uery result or null on failure.
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_by_ids()
		 */
		public function getWebsitesByIds( $ids, $userId = null ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_websites_by_ids( $ids, $userId );
		}

		/**
		 * Get child sites by groups IDs.
		 *
		 * @param array $ids    Groups IDs.
		 * @param int   $userId User ID.
		 *
		 * @return object|null Database uery result or null on failure.
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_by_group_ids()
		 */
		public function getWebsitesByGroupIds( $ids, $userId = null ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_websites_by_group_ids( $ids, $userId );
		}

		/**
		 * Get sites by user ID.
		 *
		 * @param int    $userid       User ID.
		 * @param bool   $selectgroups Selected groups.
		 * @param null   $search_site  Site search field value.
		 * @param string $orderBy      Order list by. Default: URL.			
		 *
		 * @return object|null Database query results or null on failure.
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_websites_by_user_id()
		 */
		public function getWebsitesByUserId( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url' ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_websites_by_user_id( $userid, $selectgroups, $search_site, $orderBy );
		}

		/**
		 * Get Child site wp_options database table.
		 *
		 * @param array $website Child Site array.
		 * @param mixed $option  Child Site wp_options table name.
		 *
		 * @return string|null Database query result (as string), or null on failure.
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_option()
		 */
		public function getWebsiteOption( $website, $option ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $website, $option );
		}

		/**
		 * Update child site options.
		 *
		 * @param object $website Child site object.
		 * @param mixed  $option  Option to update.
		 * @param mixed  $value   Value to update with.
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::instance()::update_website_option()
		 */
		public function updateWebsiteOption( $website, $option, $value ) {
			return MainWP\Dashboard\MainWP_DB::instance()->update_website_option( $website, $option, $value );
		}
	}
}

if ( ! class_exists( 'MainWP_System' ) ) {

	/**
	 * MainWP System Compatible class
	 *
	 * @internal
	 */
	class MainWP_System {

		/**
		 * Public static variable to hold the current plugin version.
		 *
		 * @var string Current plugin version.
		 */
		public static $version = '4.0.7.2';

		/**
		 * Create public static instance.
		 *
		 * @return MainWP_System
		 */
		static function Instance() {
			return MainWP\Dashboard\Instance();
		}
	}
}

if ( ! class_exists( 'MainWP_Extensions_View' ) ) {

	/**
	 * MainWP Extensions View Compatible class.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
	 */
	class MainWP_Extensions_View {

		/**
		 * Get all available extensions.
		 *
		 * @return array Available extensions.
		 *
		 * @todo Move to MainWP Server via an XML file.
		 */
		public static function getAvailableExtensions() {
			return MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions();
		}
	}
}


/**
 * To compatible with php version < 7.3.0.
 */
if ( ! function_exists( 'array_key_first' ) ) {
	function array_key_first( array $arr ) {
		foreach ( $arr as $key => $unused ) {
			return $key;
		}
		return null;
	}
}

