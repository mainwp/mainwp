<?php

class MainWP_API_Settings {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		//MainWP_API_Settings_View::initMenu();
	}

	public static function render() {

	}

	public static function checkUpgrade() {
		$extensions = MainWP_Extensions::loadExtensions();
		$output       = array();
		if ( is_array( $extensions ) ) {
            $check_exts = array();
			foreach ( $extensions as $ext ) {
				if ( isset( $ext['activated_key'] ) && 'Activated' == $ext['activated_key'] ) {
					$args                     = array();
					$args['plugin_name']      = $ext['api'];
					$args['version']          = $ext['version'];
					$args['product_id']       = $ext['product_id'];
					$args['api_key']          = $ext['api_key'];
					$args['activation_email'] = $ext['activation_email'];
					$args['instance']         = $ext['instance_id'];
					$args['software_version'] = $ext['software_version'];
					$check_exts[$args['plugin_name']] = $args;
				}
			}

            if ( empty( $check_exts ) ) return false;

            $i = 0;
            $count = 0;
            $max_check = 6;
            $total_check = count($check_exts);
            $bulk_checks = array();

            foreach($check_exts as $ext_name => $ext) {
                $bulk_checks[$ext_name] = $ext;
                $i++;
                $count++;
                if ($count == $max_check || $i == $total_check) {
                    $results = MainWP_Api_Manager_Plugin_Update::instance()->bulk_update_check( $bulk_checks );
					if ( is_array($results) && count($results) > 0 ) {
						foreach ( $results as $slug => $response ) {
							$rslt                 = new stdClass();
							$rslt->slug           = $slug;
							$rslt->latest_version = $response->new_version;
							$rslt->download_url   = $response->package;
							$rslt->key_status     = '';
							$rslt->apiManager     = 1;

							if ( isset( $response->errors ) ) {
								$rslt->error = $response->errors;
							}
							$output[ $slug ] = $rslt;
						}
					}
                    $count = 0;
                    $bulk_checks = array();
                    sleep(3);
				}
	        }
		}
		return $output;
	}

	public static function getUpgradeInformation( $pSlug ) {
		$extensions = MainWP_Extensions::loadExtensions();
		$rslt       = null;
		if ( is_array( $extensions ) ) {
			foreach ( $extensions as $ext ) {
				if ( isset( $ext['api'] ) && ( $pSlug == $ext['api'] ) && isset( $ext['apiManager'] ) && ! empty( $ext['apiManager'] ) ) {
					$args                     = array();
					$args['plugin_name']      = $ext['api'];
					$args['version']          = $ext['version'];
					$args['product_id']       = $ext['product_id'];
					$args['api_key']          = $ext['api_key'];
					$args['activation_email'] = $ext['activation_email'];
					$args['instance']         = $ext['instance_id'];
					$args['software_version'] = $ext['software_version'];
					$response                 = MainWP_Api_Manager::instance()->update_check( $args );
					if ( ! empty( $response ) ) {
						$rslt                 = new stdClass();
						$rslt->slug           = $ext['api']; //$response->slug
						$rslt->latest_version = $response->new_version;
						$rslt->download_url   = $response->package;
						$rslt->key_status     = '';
						$rslt->apiManager     = 1;
						if ( isset( $response->errors ) ) {
							$rslt->error = $response->errors;
						}
					}
					break;
				}
			}
		}

		return $rslt;
	}

	public static function getPluginInformation( $pSlug ) {
		$extensions = MainWP_Extensions::loadExtensions();
		$rslt       = null;
		if ( is_array( $extensions ) ) {
			foreach ( $extensions as $ext ) {
				if ( $pSlug == $ext['api'] && isset( $ext['apiManager'] ) && ! empty( $ext['apiManager'] ) ) {
					$args                     = array();
					$args['plugin_name']      = $ext['api'];
					$args['version']          = $ext['version'];
					$args['product_id']       = $ext['product_id'];
					$args['api_key']          = $ext['api_key'];
					$args['activation_email'] = $ext['activation_email'];
					$args['instance']         = $ext['instance_id'];
					$args['software_version'] = $ext['software_version'];
					$rslt                     = MainWP_Api_Manager::instance()->request_plugin_information( $args );
					break;
				}
			}
		}

		return $rslt;
	}
}
