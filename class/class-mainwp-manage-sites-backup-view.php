<?php
/**
 * This file manages the view for the MainWP Manage Sites Backup Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites_Backup_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_Backup_View {

	/**
	 * Shows the available backups taken.
	 *
	 * @param object $website Child Site info.
	 * @param array  $fullBackups Full Backups Array.
	 * @param array  $dbBackups DB Backups Array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
	 */
	public static function show_backups( &$website, $fullBackups, $dbBackups ) {
		$mwpDir = MainWP_System_Utility::get_mainwp_dir();
		$mwpDir = $mwpDir[0];
		// phpcs:disable WordPress.Security.EscapeOutput
		$output = '';
		foreach ( $fullBackups as $key => $fullBackup ) {
			$downloadLink = admin_url( '?sig=' . MainWP_System_Utility::get_download_sig( $fullBackup ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullBackup ) ) );
			$output      .= '<div class="ui grid field">';
			$output      .= '<label class="six wide column middle aligned">' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( filemtime( $fullBackup ) ) ) . ' - ' . MainWP_Utility::human_filesize( filesize( $fullBackup ) ) . '</label>';
			$output      .= '<div class="ten wide column ui toggle checkbox"><a title="' . basename( $fullBackup ) . '" href="' . esc_url( $downloadLink ) . '" class="button">Download</a>';
			$output      .= '<a href="admin.php?page=SiteRestore&websiteid=' . intval( $website->id ) . '&f=' . base64_encode( $downloadLink ) . '&size=' . filesize( $fullBackup ) . '&_opennonce=' . wp_create_nonce( 'mainwp-admin-nonce' ) . '" class="mainwp-upgrade-button button" target="_blank" title="' . basename( $fullBackup ) . '">Restore</a>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode() used for http encoding compatible.
			$output      .= '</div>';
			$output      .= '</div>';
		}

		?>
		<h3 class="ui dividing header"><?php esc_html_e( 'Backup Details', 'mainwp' ); ?></h3>
		<h3 class="header"><?php echo ( '' === $output ) ? esc_html__( 'No full backup has been taken yet', 'mainwp' ) : esc_html__( 'Last backups from your files', 'mainwp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h3>
		<?php
		echo $output;

		$output = '';
		foreach ( $dbBackups as $key => $dbBackup ) {
			$downloadLink = admin_url( '?sig=' . MainWP_System_Utility::get_download_sig( $dbBackup ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $dbBackup ) ) );
			$output      .= '<div class="ui grid field">';
			$output      .= '<label class="six wide column middle aligned">' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( filemtime( $dbBackup ) ) ) . ' - ' . MainWP_Utility::human_filesize( filesize( $dbBackup ) ) . '</label><div class="ten wide column ui toggle checkbox"><a title="' . basename( $dbBackup ) . '" href="' . esc_url( $downloadLink ) . '" download class="button">Download</a></div>';
			$output      .= '</div>';
		}
		?>
		<h3 class="header"><?php echo ( '' === $output ? esc_html__( 'No database only backup has been taken yet', 'mainwp' ) : esc_html__( 'Last backups from your database', 'mainwp' ) ); ?></h3>
		<?php
		echo $output;
		// phpcs:enable
	}

	/**
	 * Renders the Backup Site Dialog.
	 *
	 * @param mixed $website Child Site info.
	 *
	 * @return string Backup Site html.
	 */
	public static function render_backup_site( &$website ) {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'execute_backups' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'execute backups', 'mainwp' ) );
			return;
		}

		/** This filter is documented in ../pages/page-mainwp-server-information-handler.php */
		$primary_methods      = array();
		$primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead.
		$primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}
		?>
		<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
		<div id="ajax-information-zone" class="updated" style="display: none;"></div>

		<?php if ( 0 === count( $primaryBackupMethods ) ) { ?>
			<div class="mainwp-notice mainwp-notice-blue"><?php printf( esc_html__( 'Did you know that MainWP has Extensions for working with popular backup plugins? Visit the %1$sExtensions Site%2$s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a> <i class="external alternate icon"></i>' ); ?></div>
			<?php
		}
		?>
		<div class="ui alt segment">
			<div class="mainwp-main-content">
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<?php
			self::render_backup_details( $website->id );
			self::render_backup_options( $website->id );
			?>
			</div>
		</div>

		<div class="ui modal" id="managesite-backup-status-box" tabindex="0">
			<div class="header">
				<?php echo esc_html_e( 'Backup ', 'mainwp' ); ?><?php echo esc_html( stripslashes( $website->name ) ); ?>
			</div>
			<div class="scrolling content mainwp-modal-content">
			</div>
			<div class="actions mainwp-modal-actions">
				<input id="managesite-backup-status-close" type="button" name="Close" value="<?php esc_attr_e( 'Cancel ', 'mainwp' ); ?>" class="button" />
			</div>
		</div>

		<?php
	}

	/**
	 * Render Backup Details.
	 *
	 * @param mixed $websiteid Child Site ID.
	 *
	 * @return string Backup Details.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites::show_backups()
	 */
	public static function render_backup_details( $websiteid ) {
		$website = MainWP_DB::instance()->get_website_by_id( $websiteid );
		if ( empty( $website ) ) {
			return;
		}
		MainWP_Manage_Sites::show_backups( $website );
	}

	/**
	 * Render Backup Options.
	 *
	 * @param mixed $websiteid Child Site ID.
	 *
	 * @return string Backup Options.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_website_backup_settings()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 */
	public static function render_backup_options( $websiteid ) {
		$website = MainWP_DB::instance()->get_website_by_id( $websiteid );

		if ( empty( $website ) ) {
			return;
		}

		?>
			<h3 class="ui dividing header"><?php esc_html_e( 'Backup Options', 'mainwp' ); ?></h3>
			<form method="POST" action="" class="ui form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<input type="hidden" name="site_id" id="backup_site_id" value="<?php echo intval( $website->id ); ?>"/>
				<input type="hidden" name="backup_site_full_size" id="backup_site_full_size" value="<?php echo esc_attr( $website->totalsize ); ?>"/>
				<input type="hidden" name="backup_site_db_size" id="backup_site_db_size" value="<?php echo esc_attr( $website->dbsize ); ?>"/>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Backup file name', 'mainwp' ); ?></label>
					<div class="ten wide column">
						<input type="text" name="backup_filename" id="backup_filename" value="" class="" />
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Backup type', 'mainwp' ); ?></label>
					<div class="ten wide column">
						<select name="mainwp-backup-type" id="mainwp-backup-type" class="ui dropdown">
							<option value="full" selected><?php esc_html_e( 'Full Backup', 'mainwp' ); ?></option>
							<option value="db"><?php esc_html_e( 'Database Backup', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>

				<?php do_action( 'mainwp_backups_remote_settings', array( 'website' => $website->id ) ); ?>

				<?php
				$globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
				if ( false === $globalArchiveFormat ) {
					$globalArchiveFormat = 'tar.gz';
				}
				if ( 'zip' === $globalArchiveFormat ) {
					$globalArchiveFormatText = 'Zip';
				} elseif ( 'tar' === $globalArchiveFormat ) {
					$globalArchiveFormatText = 'Tar';
				} elseif ( 'tar.gz' === $globalArchiveFormat ) {
					$globalArchiveFormatText = 'Tar GZip';
				} elseif ( 'tar.bz2' === $globalArchiveFormat ) {
					$globalArchiveFormatText = 'Tar BZip2';
				}

				$backupSettings = MainWP_DB_Backup::instance()->get_website_backup_settings( $website->id );
				$archiveFormat  = $backupSettings->archiveFormat;
				$useGlobal      = ( 'global' === $archiveFormat );
				?>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Archive type', 'mainwp' ); ?></label>
					<div class="ten wide column">
						<select name="mainwp_archiveFormat" id="mainwp_archiveFormat" class="ui dropdown">
							<option value="global"
							<?php
							if ( $useGlobal ) :
								?>
								selected<?php endif; ?>>Global setting (<?php echo esc_html( $globalArchiveFormatText ); ?>)</option>
							<option value="zip"
							<?php
							if ( 'zip' === $archiveFormat ) :
								?>
								selected<?php endif; ?>>Zip</option>
							<option value="tar"
							<?php
							if ( 'tar' === $archiveFormat ) :
								?>
								selected<?php endif; ?>>Tar</option>
							<option value="tar.gz"
							<?php
							if ( 'tar.gz' === $archiveFormat ) :
								?>
								selected<?php endif; ?>>Tar GZip</option>
							<option value="tar.bz2"
							<?php
							if ( 'tar.bz2' === $archiveFormat ) :
								?>
								selected<?php endif; ?>>Tar BZip2</option>
					</select>
					</div>
				</div>
				<div class="mainwp-backup-full-exclude">
					<h3 class="header"><?php esc_html_e( 'Backup Excludes', 'mainwp' ); ?></h3>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Known backup locations', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox">
							<input type="checkbox" id="mainwp-known-backup-locations">
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"></label>
						<div class="ten wide column ui toggle checkbox">
							<textarea id="mainwp-kbl-content" disabled></textarea><br />
							<em><?php esc_html_e( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?></em>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Known cache locations', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox">
							<input type="checkbox" id="mainwp-known-cache-locations"><br />
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"></label>
						<div class="ten wide column ui toggle checkbox">
							<textarea id="mainwp-kcl-content" disabled></textarea><br />
							<em><?php esc_html_e( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to timeout. Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></em>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Non-WordPress folders', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox">
							<input type="checkbox" id="mainwp-non-wordpress-folders"><br />
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"></label>
						<div class="ten wide column ui toggle checkbox">
							<textarea id="mainwp-nwl-content" disabled></textarea><br />
							<em><?php esc_html_e( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></em>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'ZIP archives', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox">
							<input type="checkbox" id="mainwp-zip-archives">
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Custom excludes', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox">
							<textarea id="excluded_folders_list"></textarea>
						</div>
					</div>
				</div>
				<input type="button" name="backup_btnSubmit" id="backup_btnSubmit" class="ui button green big floated" value="<?php esc_attr_e( 'Backup Now', 'mainwp' ); ?>"/>
			</form>
		<?php
	}
}
