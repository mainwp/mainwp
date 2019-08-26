<?php

class MainWP_API_Settings {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function checkUpgrade() {

		$extensions	 = MainWP_Extensions::loadExtensions();
        $api_public_key = get_option( 'mainwp_extensions_api_public_key' );
        $api_token = get_option( 'mainwp_extensions_api_token' );

        if ( empty($api_public_key) || empty($api_token) ) {
            return false;
        }

		$output		 = array();
		if ( is_array( $extensions ) ) {
			$check_exts = array();
			foreach ( $extensions as $ext ) {
				if ( isset( $ext[ 'activated_key' ] ) && 'Activated' == $ext[ 'activated_key' ] ) {
                    $args = array(
                        'item_id'    => isset( $ext['product_id'] ) ? $ext['product_id'] : false,
                        'version'    => isset( $ext['version'] ) ? $ext['version'] : '',
                        'license'    => isset( $ext['api_key'] ) ? $ext['api_key'] : '',
                        'slug'       => $ext[ 'slug' ]
                    );
                    $check_exts[ $ext[ 'api' ] ]	 = $args;
				}
			}

			if ( empty( $check_exts ) )
				return false;

			$i			 = 0;
			$count		 = 0;
			$max_check	 = 6;
			$total_check = count( $check_exts );
			$bulk_checks = array();

			foreach ( $check_exts as $ext_name => $ext ) {
				$bulk_checks[ $ext_name ] = $ext;
				$i++;
				$count++;
				if ( $count == $max_check || $i == $total_check ) {
					$results = MainWP_Api_Manager_Plugin_Update::instance()->bulk_update_check( $bulk_checks, $api_public_key, $api_token );
                    if ( is_array( $results ) && count( $results ) > 0 ) {
						foreach ( $results as $slug => $response ) {
                            // $response is array()
                            if ( is_array( $response ) && isset( $response['new_version'] ) && !empty( $response['new_version'] ) ) {
                                $rslt					 = new stdClass();
                                $rslt->slug				 = $slug;
                                $rslt->latest_version	 = $response['new_version'];
                                $rslt->download_url		 = $response['package'];
                                $output[ $slug ] = $rslt;
                            }
						}
					}
					$count		 = 0;
					$bulk_checks = array();
					sleep( 3 );
				}
			}
		}
        return $output;
	}

	public static function getUpgradeInformation( $pSlug ) {

		$extensions	 = MainWP_Extensions::loadExtensions();
		$rslt		 = null;
		if ( is_array( $extensions ) ) {
			foreach ( $extensions as $slug => $ext ) {
                // get info and return
				if ( isset( $ext[ 'api' ] ) && ( $pSlug == $ext[ 'api' ] ) && isset( $ext[ 'apiManager' ] ) && !empty( $ext[ 'apiManager' ] ) ) {

                    $args						 = array(
                        'license' => $ext[ 'api_key' ],
                        'item_id' => $ext[ 'product_id' ],
                        'version' => $ext[ 'version' ],
                        'slug' => $slug,
                    );

                    $response = MainWP_Api_Manager_Plugin_Update::instance()->update_check( $args );

					if ( !empty( $response ) ) {
						$response->slug				 = $ext[ 'api' ]; //$response->slug
						$response->latest_version	 = $response->new_version;
						$response->download_url		 = $response->package;
						if ( isset( $response->errors ) ) {
							$response->error = $response->errors;
						}
                        $rslt = $response;
					}
					break;
				}
			}
		}

		return $rslt;
	}
}
