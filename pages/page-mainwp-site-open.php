<?php
/**
 * MainWP Site Open Page
 *
 * This page is used to redirect to child site WP Admin
 *
 * @package MainWP/Site_Open
 */

namespace MainWP\Dashboard;

/**
 * MainWP Site Open
 */
class MainWP_Site_Open {

	public static function get_class_name() {
		return __CLASS__;
	}

	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'WP-Admin on child sites', 'mainwp' ) );

			return;
		}
		if ( ! isset( $_GET['websiteid'] ) ) {
			exit();
		}

		$id      = intval( $_GET['websiteid'] );
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$location = '';
		if ( isset( $_GET['location'] ) ) {
			$location = base64_decode( $_GET['location'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
		}

		if ( isset( $_GET['openUrl'] ) && 'yes' === $_GET['openUrl'] ) {
			self::open_site_location( $website, $location );
		} else {
			self::open_site( $website, $location, ( isset( $_GET['newWindow'] ) ? $_GET['newWindow'] : null ) );
		}
	}

	public static function open_site( $website, $location, $pNewWindow = null ) {
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer">
				<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
			</div>
			<form method="POST" action="<?php echo MainWP_Utility::get_get_data_authed( $website, ( null == $location || '' === $location ) ? 'index.php' : $location ); ?>" id="redirectForm">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			</form>
		</div>
		<?php
	}

	public static function render_restore() {
		if ( ! isset( $_GET['websiteid'] ) ) {
			exit();
		}

		$id      = intval( $_GET['websiteid'] );
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$file = '';
		if ( isset( $_GET['f'] ) ) {
			$file = base64_decode( esc_attr( esc_html( $_GET['f'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
		}

		self::open_site_restore( $website, $file, esc_attr( esc_html( $_GET['size'] ) ) );
	}

	public static function open_site_restore( $website, $file, $size ) {
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer">
				<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
			</div>
			<?php

			$url  = ( isset( $website->url ) && '' !== $website->url ? $website->url : $website->siteurl );
			$url .= ( '/' !== substr( $url, - 1 ) ? '/' : '' );

			$postdata         = MainWP_Utility::get_get_data_authed( $website, $file, MainWP_Utility::get_file_parameter( $website ), true );
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

	public static function open_site_location( $website, $open_location ) {
		?>
		<div class="ui segment" style="padding: 25rem">
			<div class="ui active inverted dimmer">
				<div class="ui massive text loader"><?php esc_html_e( 'Redirecting...', 'mainwp' ); ?></div>
			</div>
			<?php

			$url  = ( isset( $website->url ) && '' !== $website->url ? $website->url : $website->siteurl );
			$url .= ( '/' !== substr( $url, - 1 ) ? '/' : '' );

			$postdata                  = MainWP_Utility::get_get_data_authed( $website, 'index.php', 'where', true );
			$postdata['open_location'] = base64_encode( $open_location ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
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
