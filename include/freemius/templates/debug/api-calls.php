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

	if ( class_exists( 'Freemius_Api' ) ) {
		$logger = Freemius_Api::GetLogger();
	} else {
		$logger = array();
	}

	$counters = array(
		'GET'    => 0,
		'POST'   => 0,
		'PUT'    => 0,
		'DELETE' => 0
	);

	$show_body = false;
	foreach ( $logger as $log ) {
		$counters[ $log['method'] ] ++;

		if ( ! is_null( $log['body'] ) ) {
			$show_body = true;
		}
	}

	$pretty_print = $show_body && defined( 'JSON_PRETTY_PRINT' ) && version_compare( phpversion(), '5.3', '>=' );

	$root_path_len = strlen( ABSPATH );
?>
<h1><?php _efs( 'API' ) ?></h1>

<h2><span>Total Time:</span><?php echo Freemius_Debug_Bar_Panel::total_time() ?></h2>

<h2><span>Total Requests:</span><?php echo Freemius_Debug_Bar_Panel::requests_count() ?></h2>
<?php foreach ( $counters as $method => $count ) : ?>
	<h2><span><?php echo $method ?>:</span><?php echo number_format( $count ) ?></h2>
<?php endforeach ?>
<table class="widefat">
	<thead>
	<tr>
		<th>#</th>
		<th><?php _efs( 'Method' ) ?></th>
		<th><?php _efs( 'Code' ) ?></th>
		<th><?php _efs( 'Length' ) ?></th>
		<th><?php _efs( 'Path' ) ?></th>
		<?php if ( $show_body ) : ?>
			<th><?php _efs( 'Body' ) ?></th>
		<?php endif ?>
		<th><?php _efs( 'Result' ) ?></th>
		<th><?php _efs( 'Start' ) ?></th>
		<th><?php _efs( 'End' ) ?></th>
	</tr>
	</thead>
	<tbody>
	<?php foreach ( $logger as $log ) : ?>
		<tr>
			<td><?php echo $log['id'] ?>.</td>
			<td><?php echo $log['method'] ?></td>
			<td><?php echo $log['code'] ?></td>
			<td><?php echo number_format( 100 * $log['total'], 2 ) . ' ' . __fs( 'ms' ) ?></td>
			<td>
				<?php
					printf( '<a href="#" onclick="jQuery(this).parent().find(\'table\').toggle(); return false;">%s</a>',
						$log['path']
					);
				?>
				<table class="widefat" style="display: none">
					<tbody>
					<?php for ( $i = 0, $bt = $log['backtrace'], $len = count( $bt ); $i < $len; $i ++ ) : ?>
						<tr>
							<td><?php echo( $len - $i ) ?></td>
							<td><?php if ( isset( $bt[ $i ]['function'] ) ) {
									echo ( isset( $bt[ $i ]['class'] ) ? $bt[ $i ]['class'] . $bt[ $i ]['type'] : '' ) . $bt[ $i ]['function'];
								} ?></td>
							<td><?php if ( isset( $bt[ $i ]['file'] ) ) {
									echo substr( $bt[ $i ]['file'], $root_path_len ) . ':' . $bt[ $i ]['line'];
								} ?></td>
						</tr>
					<?php endfor ?>
					</tbody>
				</table>
			</td>
			<?php if ( $show_body ) : ?>
				<td>
					<?php if ( 'GET' !== $log['method'] ) : ?>
						<?php
						$body = $log['body'];
						printf(
							'<a href="#" onclick="jQuery(this).parent().find(\'pre\').toggle(); return false;">%s</a>',
							substr( $body, 0, 32 ) . ( 32 < strlen( $body ) ? '...' : '' )
						);
						if ( $pretty_print ) {
							$body = json_encode( json_decode( $log['body'] ), JSON_PRETTY_PRINT );
						}
						?>
						<pre style="display: none"><code><?php echo esc_html( $body ) ?></code></pre>
					<?php endif ?>
				</td>
			<?php endif ?>
			<td>
				<?php
					$result = $log['result'];

					$is_not_empty_result = ( is_string( $result ) && ! empty( $result ) );

					if ( $is_not_empty_result ) {
						printf(
							'<a href="#" onclick="jQuery(this).parent().find(\'pre\').toggle(); return false;">%s</a>',
							substr( $result, 0, 32 ) . ( 32 < strlen( $result ) ? '...' : '' )
						);
					}

					if ( $is_not_empty_result && $pretty_print ) {
						$decoded = json_decode( $result );
						if ( ! is_null( $decoded ) ) {
							$result = json_encode( $decoded, JSON_PRETTY_PRINT );
						}
					} else {
						$result = is_string( $result ) ? $result : json_encode( $result );
					}
				?>
				<pre<?php if ( $is_not_empty_result ) : ?> style="display: none"<?php endif ?>><code><?php echo esc_html( $result ) ?></code></pre>
			</td>
			<td><?php echo number_format( 100 * ( $log['start'] - WP_FS__SCRIPT_START_TIME ), 2 ) . ' ' . __fs( 'ms' ) ?></td>
			<td><?php echo number_format( 100 * ( $log['end'] - WP_FS__SCRIPT_START_TIME ), 2 ) . ' ' . __fs( 'ms' ) ?></td>
		</tr>
	<?php endforeach ?>
	</tbody>
</table>