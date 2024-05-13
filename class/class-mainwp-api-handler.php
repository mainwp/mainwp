<?php
/**
 * Install, activate and deactivate MainWP Extensions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_API_Handler()
 *
 * MainWP API settings page
 */
class MainWP_API_Handler {

	/**
	 * Get Class name.
	 *
	 * @return string __CLASS__.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Check if extension has an update.
	 *
	 * @return array $output List of results.
	 *
	 * @uses MainWP_Api_Manager_Plugin_Update::bulk_update_check()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
	 */
	public static function check_exts_upgrade() {

		$extensions = MainWP_Extensions_Handler::get_extensions();
		$output     = array();
		$check_exts = array();
		foreach ( $extensions as $ext ) {
			if ( isset( $ext['activated_key'] ) && 'Activated' === $ext['activated_key'] ) {
				$args                               = array();
				$args['plugin_name']                = $ext['api'];
				$args['version']                    = $ext['version'];
				$args['product_id']                 = $ext['product_id'];
				$args['api_key']                    = $ext['api_key'];
				$args['instance']                   = $ext['instance_id'];
				$args['software_version']           = $ext['software_version'];
				$check_exts[ $args['plugin_name'] ] = $args;
			}
		}

		if ( empty( $check_exts ) ) {
			return false;
		}

		$i           = 0;
		$count       = 0;
		$max_check   = 6;
		$total_check = count( $check_exts );
		$bulk_checks = array();

		foreach ( $check_exts as $ext_name => $ext ) {
			$bulk_checks[ $ext_name ] = $ext;
			++$i;
			++$count;
			if ( $count === $max_check || $i === $total_check ) {
				$results = MainWP_Api_Manager_Plugin_Update::instance()->bulk_update_check( $bulk_checks ); // bulk check response array of info.
				if ( is_array( $results ) && 0 < count( $results ) ) {
					foreach ( $results as $slug => $response ) {
						if ( ! is_array( $response ) || ! isset( $response['new_version'] ) ) {
							continue;
						}
						$rslt              = new \stdClass();
						$rslt->slug        = $slug;
						$rslt->new_version = $response['new_version'];
						$rslt->package     = $response['package'];
						$rslt->key_status  = '';
						$rslt->apiManager  = 1;

						if ( isset( $response['errors'] ) ) {
							$rslt->error = $response['errors'];
						}
						$output[ $slug ] = $rslt;
					}
				}
				$count       = 0;
				$bulk_checks = array();
				sleep( 3 );
			}
		}
		return $output;
	}

	/**
	 * Check extension information for an update.
	 *
	 * @param string $pSlug Extension slug.
	 *
	 * @return array $rslt An array containing update information.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::update_check()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
	 */
	public static function get_upgrade_information( $pSlug ) {

		$extensions = MainWP_Extensions_Handler::get_extensions();
		$rslt       = null;
		foreach ( $extensions as $ext ) {
			if ( isset( $ext['api'] ) && ( $pSlug === $ext['api'] ) && isset( $ext['apiManager'] ) && ! empty( $ext['apiManager'] ) ) {
				$args                     = array();
				$args['plugin_name']      = $ext['api'];
				$args['version']          = $ext['version'];
				$args['product_id']       = $ext['product_id'];
				$args['api_key']          = $ext['api_key'];
				$args['instance']         = $ext['instance_id'];
				$args['software_version'] = $ext['software_version'];
				$response                 = MainWP_Api_Manager_Plugin_Update::instance()->update_check( $args );

				if ( ! empty( $response ) ) {
					$rslt              = new \stdClass();
					$rslt->slug        = $ext['api'];
					$rslt->new_version = $response->new_version;
					$rslt->package     = $response->package;
					$rslt->key_status  = '';
					$rslt->apiManager  = 1;
					if ( isset( $response->errors ) ) {
						$rslt->error = $response->errors;
					}
				}
				break;
			}
		}
		return $rslt;
	}

	/**
	 * Get extension information.
	 *
	 * @param string $pSlug Extension slug.
	 *
	 * @return array $rslt An array containing extension information.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::request_extension_information()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
	 */
	public static function get_update_information( $pSlug ) {
		$extensions = MainWP_Extensions_Handler::get_extensions();
		$rslt       = null;
		foreach ( $extensions as $ext ) {
			if ( isset( $ext['api'] ) && $pSlug === $ext['api'] && isset( $ext['apiManager'] ) && ! empty( $ext['apiManager'] ) ) {
				$args                     = array();
				$args['plugin_name']      = $ext['api'];
				$args['version']          = $ext['version'];
				$args['product_id']       = $ext['product_id'];
				$args['api_key']          = $ext['api_key'];
				$args['instance']         = $ext['instance_id'];
				$args['software_version'] = $ext['software_version'];
				$rslt                     = MainWP_Api_Manager::instance()->request_extension_information( $args );
				break;
			}
		}
		return $rslt;
	}
}
