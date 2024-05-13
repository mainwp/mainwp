<?php
/**
 * Centralized manager for WordPress backend functionality.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Class - Log_Stats
 */
class Log_Stats {

	/**
	 * Class constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get stats data.
	 *
	 *  @param array $items Logs items array.
	 */
	public static function get_stats_data( $items ) {
		$data = array();
		if ( is_array( $items ) ) {
			$total_count     = 0;
			$total_dura_time = 0;
			foreach ( $items as $item ) {
				if ( 'compact' !== $item->connector ) {
					if ( ! isset( $data[ $item->connector ] ) ) {
						$data[ $item->connector ] = array();
					}
					if ( ! isset( $data[ $item->connector ][ $item->context ] ) ) {
						$data[ $item->connector ][ $item->context ] = array();
					}

					if ( ! isset( $data[ $item->connector ][ $item->context ]['total_events'] ) ) {
						$data[ $item->connector ][ $item->context ]['total_events'] = array(
							'count'     => 0,
							'dura_time' => 0,
						);
					}

					if ( ! isset( $data[ $item->connector ][ $item->context ][ $item->action ] ) ) {
						$data[ $item->connector ][ $item->context ][ $item->action ] = array(
							'count'     => 1,
							'dura_time' => $item->duration,
						);
					} else {
						$data[ $item->connector ][ $item->context ][ $item->action ]['count']     += 1;
						$data[ $item->connector ][ $item->context ][ $item->action ]['dura_time'] += $item->duration;
					}
					$data[ $item->connector ][ $item->context ]['total_events']['count']     += 1;
					$data[ $item->connector ][ $item->context ]['total_events']['dura_time'] += $item->duration;
				}
			}
		}
		return $data;
	}

	/**
	 * Render stats count.
	 *
	 * @param array  $data Stats data array.
	 * @param string $action Action name.
	 */
	public static function get_stats_count( $data, $action ) {
		return isset( $data[ $action ] ) && ! empty( $data[ $action ]['count'] ) ? $data[ $action ]['count'] : 0;
	}


	/**
	 * Render stats duration time.
	 *
	 * @param array  $data Stats data array.
	 * @param string $action Action name.
	 */
	public static function render_stats_duration_time( $data, $action ) {
		$dura_time = isset( $data[ $action ] ) && ! empty( $data[ $action ]['dura_time'] ) ? $data[ $action ]['dura_time'] : 0;
		echo MainWP_Utility::format_duration_time( $dura_time ) ; //phpcs:ignore -- ok.
	}

	/**
	 * Render stats column content.
	 *
	 * @param array  $data Data stats array.
	 * @param string $action Action name.
	 * @param string $title Action title.
	 * @param array  $data_prev Data Stats previous array.
	 */
	public static function render_stats_info( $data, $action, $title, $data_prev ) {
		$count      = self::get_stats_count( $data, $action );
		$prev_count = self::get_stats_count( $data_prev, $action );
		?>
		<div class="center aligned middle aligned column">
			<div class="ui mini vertical statistic">
				<div class="value">
					<?php self::render_stats_compare( $count, $prev_count ); ?> <?php echo intval( $count ); ?>
				</div>
				<div class="label">
					<?php echo esc_html( $title ); ?>	
				</div>
				<span class="ui small text">
					<?php self::render_stats_duration_time( $data, $action ); ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render stats compare.
	 *
	 * @param int $count Count stats.
	 * @param int $prev_count Count previous stats.
	 */
	public static function render_stats_compare( $count, $prev_count ) {
		echo '<span data-tooltip="' . intval( $count ) . ' actions this period, ' . intval( $prev_count ) . ' actions previous period, showing growth or decline." data-position="top left" data-inverted="">';
		if ( $count === $prev_count ) {
			?>
			<i class="angle right icon"></i>
			<?php
		} elseif ( $count > $prev_count ) {
			?>
			<i class="angle up green icon"></i>
			<?php
		} else {
			?>
			<i class="angle down red icon"></i>
			<?php
		}
		echo '</span>';
	}


	/**
	 * Method render_chart_series().
	 *
	 * @param array  $data Stats data.
	 * @param string $action Action.
	 * @param string $title Title.
	 * @param array  $data_prev Stats previous data.
	 */
	public static function render_chart_series( $data, $action, $title, $data_prev ) {
		$count      = self::get_stats_count( $data, $action );
		$prev_count = self::get_stats_count( $data_prev, $action );
		echo '
			{ 
			x: "' . esc_html( $title ) . '",
			y: "' . intval( $count ) . '",
			goals: [
				{
				  name: "Previous",
				  value: "' . intval( $prev_count ) . '",
				  strokeHeight: 2,
				  strokeColor: "#7fb100",
				}
			],
			fillColor: "#18a4e0",
			},
			
		';
	}
}
