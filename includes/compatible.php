<?php
/**
 * MainWP Compatible Functions.
 *
 * @package     MainWP/Dashboard
 */

// phpcs:disable -- compatible functions

if ( ! class_exists( 'MainWP_DB' ) ) {

	/**
	 * MainWP Database Compatible class
	 */
	class MainWP_DB {
		/**
		 * @static
		 * @var (self|null) $instance Instance of MainWP_DB_Backup or null.
		 */
		private static $instance = null;

		/**
		 * Method instance()
		 *
		 * Create public static instance.
		 *
		 * @static
		 * @return MainWP_DB
		 */
		public static function instance() {
			if ( null == self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Method getWebsiteById()
		 *
		 * Get child site by id.
		 *
		 * @param int   $id Child site ID.
		 * @param array $selectGroups Select groups.
		 *
		 * @return object|null Database query results or null on failure.
		 */
		public function getWebsiteById( $id, $selectGroups = false ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $id, $selectGroups );
		}

		/**
		 * Method getWebsitesByIds()
		 *
		 * Get child sites by child site IDs.
		 *
		 * @param array $ids Child site IDs.
		 * @param int   $userId User ID.
		 *
		 * @return object|null Database uery result or null on failure.
		 */
		public function getWebsitesByIds( $ids, $userId = null ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_websites_by_ids( $ids, $userId );
		}

		/**
		 * Method getWebsitesByGroupIds()
		 *
		 * Get child sites by child site IDs.
		 *
		 * @param array $ids Child site IDs.
		 * @param int   $userId User ID.
		 *
		 * @return object|null Database uery result or null on failure.
		 */
		public function getWebsitesByGroupIds( $ids, $userId = null ) {
			return MainWP\Dashboard\MainWP_DB::instance()->get_websites_by_group_ids( $ids, $userId );
		}

	}
}

if ( ! class_exists( 'MainWP_System' ) ) {

	/**
	 * MainWP System Compatible class
	 */
	class MainWP_System {

		/**
		 * Public static variable to hold the current plugin version.
		 *
		 * @var string Current plugin version.
		 */
		public static $version = '4.0.7.2';

		/**
		 * @static
		 * @return MainWP_System
		 */
		static function Instance() {
			return MainWP\Dashboard\Instance();
		}
	}
}

if ( ! class_exists( 'MainWP_Extensions_View' ) ) {

	/**
	 * MainWP Extensions View Compatible class
	 */
	class MainWP_Extensions_View {

		/**
		* Method getAvailableExtensions()
		*
		* Static Arrays of all Available Extensions.
		*
		* @todo Move to MainWP Server via an XML file.
		*/
		public static function getAvailableExtensions() {
			return MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions();
		}
	}
}
