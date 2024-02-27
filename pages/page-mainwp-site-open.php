<?php
/**
 * This Class takes the requested Child Sites,
 * and then redirects to child site WP Admin.
 *
 * @package MainWP/Site_Open
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Site_Open
 *
 * @package MainWP\Dashboard
 */
class MainWP_Site_Open {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Child Site Dashboard Link redirect handler.
	 *
	 * This method checks to see if the current user is allow to access the
	 * Child Site, then grabs the websiteid, location, openurl & passes it onto
	 * either open_site_location or open_site methods.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function render() {

		self::verify_open_nonce();

		if ( ! mainwp_current_user_have_right( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'WP-Admin on child sites', 'mainwp' ) );

			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_GET['websiteid'] ) ) {
			exit();
		}

		$id      = intval( $_GET['websiteid'] );
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$location = '';
		if ( isset( $_GET['location'] ) ) {
			$location = base64_decode( wp_unslash( $_GET['location'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_decode used for HTTP compatible char.
		}

		if ( isset( $_GET['openUrl'] ) && 'yes' === $_GET['openUrl'] ) {
			self::open_site_location( $website, $location );
		} else {
			$allow_params = array();

			$allow_vars = array(
				'filedl',
				'dirdl',
			);
			$allow_vars = apply_filters( 'mainwp_open_site_allow_vars', $allow_vars );
			if ( is_array( $allow_vars ) ) {
				foreach ( $allow_vars as $var ) {
					if ( is_string( $var ) && isset( $_GET[ $var ] ) ) {
						$allow_params[ $var ] = $_GET[ $var ]; // phpcs:ignore -- ok.
					}
				}
			}
			self::open_site( $website, $location, $allow_params );
		}
		// phpcs:enable
	}

	/**
	 * This method opens the requested Child Site Admin.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $location Website Location.
	 * @param array $params others params.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
	 */
	private static function open_site( $website, $location, $params = array() ) {
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			$action = $website->url . 'wp-admin.html';
		} else {
			$action = MainWP_Connect::get_get_data_authed( $website, ( null === $location || '' === $location ) ? 'index.php' : $location, 'where', false, $params );
		}
		$open_download = ! empty( $params['filedl'] ) ? true : false;
		$close_window  = ! empty( $_GET['closeWindow'] ) ? true : false; //phpcs:ignore -- ok.
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer <?php echo $open_download || $close_window ? 'open-site-close-window' : ''; ?>">
				<?php
				if ( $open_download ) {
					?>
					<div class="ui massive text loader"><?php esc_html_e( 'Downloading...', 'mainwp' ); ?></div>
					<?php
				} else {
					?>
					<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
					<?php
				}
				?>
			</div>
			<form method="POST" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" id="redirectForm">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * This renders the method open_site _restore()
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function render_restore() {

		self::verify_open_nonce();

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_GET['websiteid'] ) ) {
			exit();
		}

		$id      = intval( $_GET['websiteid'] );
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$file = '';
		if ( isset( $_GET['f'] ) ) {
			$file = base64_decode( esc_html( wp_unslash( $_GET['f'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}

		$site = isset( $_GET['size'] ) ? esc_html( wp_unslash( $_GET['size'] ) ) : '';
		// phpcs:enable

		self::open_site_restore( $website, $file, $site );
	}

	/**
	 * This opens the site restore.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $file Restore File.
	 * @param mixed $size Post data size.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
	 */
	public static function open_site_restore( $website, $file, $size ) {
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer">
				<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
			</div>
			<?php

			$url  = ( isset( $website->url ) && '' !== $website->url ? $website->url : $website->siteurl );
			$url .= ( '/' !== substr( $url, - 1 ) ? '/' : '' );

			$postdata         = MainWP_Connect::get_get_data_authed( $website, $file, 'f', true );
			$postdata['size'] = $size;
			?>
			<form method="POST" action="<?php echo esc_url( $url ); ?>" id="redirectForm">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<?php
				foreach ( $postdata as $name => $value ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * This verify opens the site nonce.
	 */
	public static function verify_open_nonce() {
		$nonce = '_opennonce';
		if ( isset( $_GET[ $nonce ] ) && wp_verify_nonce( sanitize_key( $_GET[ $nonce ] ), 'mainwp-admin-nonce' ) ) {
			return true;
		} else {
			wp_die( esc_html__( 'Unauthorized request. Invalid or missing nonce, be sure you are using the current version of the MainWP Dashboard and Extensions.', 'mainwp' ) );
		}
	}

	/**
	 * This opens the site location.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $open_location Website URL.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
	 */
	public static function open_site_location( $website, $open_location ) {
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer">
				<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
			</div>
			<?php

			$url  = ( isset( $website->url ) && '' !== $website->url ? $website->url : $website->siteurl );
			$url .= ( '/' !== substr( $url, - 1 ) ? '/' : '' );

			$postdata                  = MainWP_Connect::get_get_data_authed( $website, 'index.php', 'where', true );
			$postdata['open_location'] = base64_encode( $open_location ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			?>
			<form method="POST" action="<?php echo esc_url( $url ); ?>" id="redirectForm">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<?php
				foreach ( $postdata as $name => $value ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				}
				?>
			</form>
		</div>
		<?php
	}


	/**
	 * Method get_open_site_url()
	 *
	 * Render render open site url.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $location open location.
	 * @param bool  $echo_out Echo or not.
	 *
	 * @return mixed Render modal window for themes selection.
	 */
	public static function get_open_site_url( $website, $location = '', $echo_out = true ) {

		$site_id = 0;

		if ( is_numeric( $website ) ) {
			$site_id = $website;
		} elseif ( is_object( $website ) ) {
			$site_id = $website->id;
		} else {
			return '';
		}

		$open_url = '';

		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $site_id ) ) {
			$open_url = MainWP_Demo_Handle::get_instance()->get_open_site_demo_url( $site_id );
		} else {
			$open_url = 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $site_id . '&_opennonce=' . esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) );
			if ( ! empty( $location ) ) {
				$open_url .= '&location=' . $location;
			}
		}

		if ( $echo_out ) {
			echo $open_url; //phpcs:ignore WordPress.Security.EscapeOutput
		}

		return $open_url;
	}
}
