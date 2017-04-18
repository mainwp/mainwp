<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.1.7.3
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	$fs_options  = FS_Option_Manager::get_manager( WP_FS__ACCOUNTS_OPTION_NAME, true );
	$all_plugins = $fs_options->get_option( 'all_plugins' );
	$all_themes  = $fs_options->get_option( 'all_themes' );
?>
<h1><?php _efs( 'plugins-themes-sync' ) ?></h1>
<table class="widefat">
	<thead>
	<tr>
		<th></th>
		<th><?php _efs( 'total' ) ?></th>
		<th><?php _efs( 'Last' ) ?></th>
	</tr>
	</thead>
	<tbody>
	<?php if ( is_object( $all_plugins ) ) : ?>
		<tr>
			<td><?php _efs( 'plugins' ) ?></td>
			<td><?php echo count( $all_plugins->plugins ) ?></td>
			<td><?php
					if ( isset( $all_plugins->timestamp ) && is_numeric( $all_plugins->timestamp ) ) {
						$diff       = abs( WP_FS__SCRIPT_START_TIME - $all_plugins->timestamp );
						$human_diff = ( $diff < MINUTE_IN_SECONDS ) ?
							$diff . ' ' . __fs( 'sec' ) :
							human_time_diff( WP_FS__SCRIPT_START_TIME, $all_plugins->timestamp );

						if ( WP_FS__SCRIPT_START_TIME < $all_plugins->timestamp ) {
							printf( __fs( 'in-x' ), $human_diff );
						} else {
							printf( __fs( 'x-ago' ), $human_diff );
						}
					}
				?></td>
		</tr>
	<?php endif ?>
	<?php if ( is_object( $all_themes ) ) : ?>
		<tr>
			<td><?php _efs( 'themes' ) ?></td>
			<td><?php echo count( $all_themes->themes ) ?></td>
			<td><?php
					if ( isset( $all_themes->timestamp ) && is_numeric( $all_themes->timestamp ) ) {
						$diff       = abs( WP_FS__SCRIPT_START_TIME - $all_themes->timestamp );
						$human_diff = ( $diff < MINUTE_IN_SECONDS ) ?
							$diff . ' ' . __fs( 'sec' ) :
							human_time_diff( WP_FS__SCRIPT_START_TIME, $all_themes->timestamp );

						if ( WP_FS__SCRIPT_START_TIME < $all_themes->timestamp ) {
							printf( __fs( 'in-x' ), $human_diff );
						} else {
							printf( __fs( 'x-ago' ), $human_diff );
						}
					}
				?></td>
		</tr>
	<?php endif ?>
	</tbody>
</table>
