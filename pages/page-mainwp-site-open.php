<?php

/**
 * MainWP Site Open
 */
class MainWP_Site_Open {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function render() {
		if ( !mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'WP-Admin on child sites', 'mainwp' ) );

			return;
		}
		if ( !isset( $_GET[ 'websiteid' ] ) ) {
			exit();
		}

		$id = $_GET[ 'websiteid' ];
		$website = MainWP_DB::Instance()->getWebsiteById( $id );

		if ( !MainWP_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$location = '';
		if ( isset( $_GET[ 'location' ] ) ) {
			$location = base64_decode( $_GET[ 'location' ] );
		}

		if ( isset( $_GET[ 'openUrl' ] ) && $_GET[ 'openUrl' ] == 'yes' ) {
			MainWP_Site_Open::openSiteLocation( $website, $location );
		} else {
			MainWP_Site_Open::openSite( $website, $location, ( isset( $_GET[ 'newWindow' ] ) ? $_GET[ 'newWindow' ] : null ) );
		}
	}

	public static function openSite( $website, $location, $pNewWindow = null ) {
		?>
		<div class="ui segment" style="padding: 25rem">
		  <div class="ui active inverted dimmer">
		    <div class="ui massive text loader">Redirecting...</div>
		  </div>
			<form method="POST" action="<?php echo MainWP_Utility::getGetDataAuthed( $website, ( $location == null || $location == '' ) ? 'index.php' : $location  ); ?>" id="redirectForm"></form>
		</div>
		<?php
	}

	public static function renderRestore() {
		if ( !isset( $_GET[ 'websiteid' ] ) ) {
			exit();
		}

		$id = $_GET[ 'websiteid' ];
		$website = MainWP_DB::Instance()->getWebsiteById( $id );

		if ( !MainWP_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$file = '';
		if ( isset( $_GET[ 'f' ] ) ) {
			$file = base64_decode( esc_attr( esc_html( $_GET[ 'f' ] ) ) );
		}

		MainWP_Site_Open::openSiteRestore( $website, $file, esc_attr( esc_html( $_GET[ 'size' ] ) ) );
	}

	public static function openSiteRestore( $website, $file, $size ) {
		?>
		<div class="ui segment" style="padding: 25rem">
		  <div class="ui active inverted dimmer">
		    <div class="ui massive text loader">Redirecting...</div>
		  </div>
			<?php

			$url = ( isset( $website->url ) && $website->url != '' ? $website->url : $website->siteurl );
			$url .= ( substr( $url, - 1 ) != '/' ? '/' : '' );

			$postdata = MainWP_Utility::getGetDataAuthed( $website, $file, MainWP_Utility::getFileParameter( $website ), true );
			$postdata[ 'size' ] = $size;
			?>
			<form method="POST" action="<?php echo esc_url($url); ?>" id="redirectForm">
				<?php
				foreach ( $postdata as $name => $value ) {
					echo '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
				}
				?>
			</form>
		</div>
		<?php
	}

	public static function openSiteLocation( $website, $open_location ) {
		?>
		<div class="ui segment" style="padding: 25rem">
		  <div class="ui active inverted dimmer">
		    <div class="ui massive text loader">Redirecting...</div>
		  </div>
			<?php

			$url = ( isset( $website->url ) && $website->url != '' ? $website->url : $website->siteurl );
			$url .= ( substr( $url, - 1 ) != '/' ? '/' : '' );

			$postdata = MainWP_Utility::getGetDataAuthed( $website, 'index.php', 'where', true );
			$postdata[ 'open_location' ] = base64_encode( $open_location );
			?>
			<form method="POST" action="<?php echo $url; ?>" id="redirectForm">
				<?php
				foreach ( $postdata as $name => $value ) {
					echo '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
				}
				?>
			</form>
		</div>
		<?php
	}

}
