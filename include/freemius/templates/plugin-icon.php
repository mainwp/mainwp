<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.1.4
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * @var array $VARS
	 */
	$slug = $VARS['slug'];
	$fs   = freemius( $slug );

	/**
	 * @since 1.1.7.5
	 */
	$local_path = $fs->apply_filters( 'plugin_icon', false );

	if ( is_string( $local_path ) ) {
		$icons = array( $local_path );
	} else {
		global $fs_active_plugins;

		$img_dir = WP_FS__DIR_IMG;

		if ( 1 < count( $fs_active_plugins->plugins ) ) {
			$plugin_or_theme_img_dir = WP_PLUGIN_DIR;

			foreach ( $fs_active_plugins->plugins as $sdk_path => &$data ) {
				if ( $data->plugin_path == $fs->get_plugin_basename() ) {
					$img_dir = $plugin_or_theme_img_dir
					           . '/'
					           . str_replace( '../themes/', '', $sdk_path )
					           . '/assets/img';

					break;
				}
			}
		}

		$icons = glob( fs_normalize_path( $img_dir . '/' . $slug . '.*' ) );
		if ( ! is_array( $icons ) || 0 === count( $icons ) ) {
			$icon_found = false;
			$local_path = fs_normalize_path( $img_dir . '/' . $slug . '.png' );

			$have_write_permissions = ( 'direct' === get_filesystem_method( array(), fs_normalize_path( $img_dir ) ) );

			if ( WP_FS__IS_LOCALHOST && $fs->is_org_repo_compliant() && $have_write_permissions ) {
				/**
				 * IMPORTANT: THIS CODE WILL NEVER RUN AFTER THE PLUGIN IS IN THE REPO.
				 *
				 * This code will only be executed once during the testing
				 * of the plugin in a local environment. The plugin icon file WILL
				 * already exist in the assets folder when the plugin is deployed to
				 * the repository.
				 */
				if ( ! function_exists( 'plugins_api' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
				}

				$plugin_information = plugins_api( 'plugin_information', array(
					'slug'   => $slug,
					'fields' => array(
						'sections' => false,
						'tags'     => false,
						'icons'    => true
					)
				) );

				if (
					! is_wp_error( $plugin_information )
					&& isset( $plugin_information->icons )
					&& ! empty( $plugin_information->icons )
				) {
					/**
					 * Get the smallest icon.
					 *
					 * @author Leo Fajardo (@leorw)
					 * @since  1.2.2
					 */
					$icon = end( $plugin_information->icons );

					if ( 0 !== strpos( $icon, 'http' ) ) {
						$icon = 'http:' . $icon;
					}

					/**
					 * Get a clean file extension, e.g.: "jpg" and not "jpg?rev=1305765".
					 *
					 * @author Leo Fajardo (@leorw)
					 * @since  1.2.2
					 */
					$ext = pathinfo( strtok( $icon, '?' ), PATHINFO_EXTENSION );

					$local_path = fs_normalize_path( $img_dir . '/' . $slug . '.' . $ext );
					fs_download_image( $icon, $local_path );

					$icon_found = true;
				}
			}

			if ( ! $icon_found ) {
				// No icons found, fallback to default icon.
				if ( $have_write_permissions ) {
					// If have write permissions, copy default icon.
					copy( fs_normalize_path( $img_dir . '/plugin-icon.png' ), $local_path );
				} else {
					// If doesn't have write permissions, use default icon path.
					$local_path = fs_normalize_path( $img_dir . '/plugin-icon.png' );
				}
			}

			$icons = array( $local_path );
		}
	}

	$icon_dir     = dirname( $icons[0] );
	$relative_url = fs_img_url( substr( $icons[0], strlen( $icon_dir ) ), $icon_dir );
?>
<div class="fs-plugin-icon">
	<img src="<?php echo $relative_url ?>" width="80" height="80"/>
</div>
