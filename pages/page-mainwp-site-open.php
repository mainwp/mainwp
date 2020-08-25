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
	 * This method chekcs to see if the current user is allow to acess the
	 * Child Site, then grabs the websiteid, location, openurl & passes it onto
	 * either open_site_location or open_site methods.
	 */
	public static function render() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'WP-Admin on child sites', 'mainwp' ) );

			return;
		}
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
			self::open_site( $website, $location, ( isset( $_GET['newWindow'] ) ? wp_unslash( $_GET['newWindow'] ) : null ) );
		}
	}

	/**
	 * This method opens the requested Child Site Admin.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $location Website Location.
	 * @param null  $pNewWindow Open in new window.
	 */
	public static function open_site( $website, $location, $pNewWindow = null ) {
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer">
				<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
			</div>
			<form method="POST" action="<?php echo MainWP_Connect::get_get_data_authed( $website, ( null == $location || '' === $location ) ? 'index.php' : $location ); ?>" id="redirectForm">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * This renders the method open_site _restore()
	 */
	public static function render_restore() {
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
			$file = base64_decode( esc_attr( esc_html( wp_unslash( $_GET['f'] ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}

		self::open_site_restore( $website, $file, esc_attr( esc_html( $_GET['size'] ) ) );
	}

	/**
	 * This opens the site restore.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $file Restore File.
	 * @param mixed $size Post data size.
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
	 * This opens the site location.
	 *
	 * @param mixed $website Website ID.
	 * @param mixed $open_location Website URL.
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

}
