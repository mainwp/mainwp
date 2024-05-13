<?php
/**
 * MainWP Logs Widget
 *
 * Displays the Logs Info.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Posts_Widget
 *
 * Displays the Logs info.
 */
class Log_Posts_Widget {

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * @param array ...$args Widget callback args.
	 * @return mixed render_site_info()
	 */
	public function render( ...$args ) {
		$this->render_widget( $args );
	}


	/**
	 * Render client overview Info.
	 *
	 * @param array $args Args data.
	 */
	public function render_widget( $args ) {
		$data       = is_array( $args ) && ! empty( $args[1][0] ) && is_array( $args[1][0] ) ? $args[1][0] : array();
		$stats_data = is_array( $data ) && ! empty( $data['stats_data']['posts'] ) ? $data['stats_data']['posts'] : array();
		if ( ! is_array( $stats_data ) ) {
			$stats_data = array();
		}

		$stats_prev_data = is_array( $data ) && ! empty( $data['stats_prev_data']['posts'] ) ? $data['stats_prev_data']['posts'] : array();
		if ( ! is_array( $stats_prev_data ) ) {
			$stats_prev_data = array();
		}

		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
				<?php esc_html_e( 'Posts Management Event Tracker', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Overview of post-related activities with total events tallied.', 'mainwp' ); ?>
				</div>
			</h3>
		</div>

		<div class="mainwp-widget-insights-card">
				<?php
				/**
				 * Action: mainwp_logs_widget_top
				 *
				 * Fires at the top of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_top', 'posts' );
				?>
				<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
				<?php
				wp_nonce_field( 'mainwp-admin-nonce' );
				$this->render_widget_content( $stats_data, $stats_prev_data );
				?>
				<?php
				/**
				 * Action: mainwp_logs_widget_bottom
				 *
				 * Fires at the bottom of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_bottom', 'posts' );
				?>
			</div>
			<div class="mainwp-widget-footer ui four columns stackable grid">
			<div class="column">
			</div>
			<div class="column">
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_widget_content().
	 *
	 * @param array $data Stats data.
	 * @param array $prev_data Previous stats data.
	 */
	public function render_widget_content( $data, $prev_data ) {

		$posts_data = array();
		if ( is_array( $data ) && isset( $data['post'] ) ) {
			$posts_data = $data['post'];
			if ( ! is_array( $posts_data ) ) {
				$posts_data = array();
			}
		}

		$posts_prev_data = array();
		if ( is_array( $prev_data ) && isset( $prev_data['post'] ) ) {
			$posts_prev_data = $prev_data['post'];
			if ( ! is_array( $posts_prev_data ) ) {
				$posts_prev_data = array();
			}
		}

		$columns = array(
			'created'      => esc_html__( 'Created', 'mainwp' ),
			'published'    => esc_html__( 'Published', 'mainwp' ),
			'updated'      => esc_html__( 'Updated', 'mainwp' ),
			'trashed'      => esc_html__( 'Trashed', 'mainwp' ),
			'untrashed'    => esc_html__( 'Untrashed', 'mainwp' ),
			'deleted'      => esc_html__( 'Deleted', 'mainwp' ),
			'total_events' => esc_html__( 'Events', 'mainwp' ),
		);

		?>
		<div class="ui one column grid">
			<div class="left aligned middle aligned column">
				<div class="ui equal width grid">
					<?php
					foreach ( $columns as $act => $title ) {
						Log_Stats::render_stats_info( $posts_data, $act, $title, $posts_prev_data );
					}
					?>
				</div>
			</div>
			<div class="left aligned middle aligned column">
				<div id="mainwp-module-log-chart-posts-management-wrapper" ></div>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						var options = {
							chart: { 
								type: 'bar',
								height: 350,
							},
							plotOptions: {
								bar: {
									columnWidth: '60%'
								}
							},
							series: [ {
								name: 'Events:',
								data: [
								<?php
								foreach ( $columns as $act => $title ) {
									Log_Stats::render_chart_series( $posts_data, $act, $title, $posts_prev_data );
								}
								?>
								],
							} ],
							xaxis: {
								labels: {
									style: {
										colors: '#999999',
									}
								}
							},
							yaxis: {
								labels: {
									style: {
										colors: '#999999',
									}
								}
							},
							tooltip: {
								theme: 'dark'
							},
						}
						var clients = new ApexCharts(document.querySelector("#mainwp-module-log-chart-posts-management-wrapper"), options);
						setTimeout(() => {
							clients.render();
						}, 1000);
					} );
				</script>
			</div>
		</div>
		<?php
	}
}
