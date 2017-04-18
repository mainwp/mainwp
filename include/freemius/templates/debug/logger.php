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

	$log_book = FS_Logger::get_log();
?>
<h1><?php _efs( 'Log' ) ?></h1>

<table class="widefat" style="font-size: 11px;">
	<thead>
	<tr>
		<th>#</th>
		<th><?php _efs( 'id' ) ?></th>
		<th><?php _efs( 'type' ) ?></th>
		<th><?php _efs( 'function' ) ?></th>
		<th><?php _efs( 'message' ) ?></th>
		<th><?php _efs( 'file' ) ?></th>
		<th><?php _efs( 'timestamp' ) ?></th>
	</tr>
	</thead>
	<tbody>

	<?php $i = 0;
		foreach ( $log_book as $log ) : ?>
			<?php
			/**
			 * @var FS_Logger $logger
			 */
			$logger = $log['logger'];
			?>
			<tr<?php if ( $i % 2 ) {
				echo ' class="alternate"';
			} ?>>
				<td><?php echo $log['cnt'] ?>.</td>
				<td><?php echo $logger->get_id() ?></td>
				<td><?php echo $log['log_type'] ?></td>
				<td><b><code style="color: blue;"><?php echo ( ! empty( $log['class'] ) ? $log['class'] . $log['type'] : '' ) . $log['function'] ?></code></b></td>
				<td>
					<?php
						printf(
							'<a href="#" style="color: darkorange !important;" onclick="jQuery(this).parent().find(\'div\').toggle(); return false;"><nobr>%s</nobr></a>',
							esc_html( substr( $log['msg'], 0, 32 ) ) . ( 32 < strlen( $log['msg'] ) ? '...' : '' )
						);
					?>
					<div style="display: none;">
						<b style="color: darkorange;"><?php echo esc_html( $log['msg'] ) ?></b>
					</div>
				</td>
				<td><?php
						if ( isset( $log['file'] ) ) {
							echo substr( $log['file'], $logger->get_file() ) . ':' . $log['line'];
						}
					?></td>
				<td><?php echo number_format( 100 * ( $log['timestamp'] - WP_FS__SCRIPT_START_TIME ), 2 ) . ' ' . __fs( 'ms' ) ?></td>
			</tr>
			<?php $i ++; endforeach ?>
	</tbody>
</table>