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

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Graph_Plugins_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Plugins_Widget {

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
	 */
	public function render() {
		$this->render_widget();
	}


	/**
	 * Render client overview Info.
	 */
	public function render_widget() {
		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
				<?php esc_html_e( 'Site Plugin Status Breakdown', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Total number of plugins per site, differentiated by active (blue) and inactive (green) status for quick assessment.', 'mainwp' ); ?>
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
				do_action( 'mainwp_logs_widget_top', 'status' );
				?>
				<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
				<?php
				wp_nonce_field( 'mainwp-admin-nonce' );
				$this->render_widget_content();
				?>
				<?php
				/**
				 * Action: mainwp_logs_widget_bottom
				 *
				 * Fires at the bottom of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_bottom', 'status' );
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
	 * Method render_widget_content()
	 */
	public function render_widget_content() {
		$wp_plugins = array();
		$websites   = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			$plugins = ! empty( $website->plugins ) ? json_decode( $website->plugins, 1 ) : array();
			if ( ! is_array( $plugins ) ) {
				$plugins = array();
			}
			$active   = MainWP_Utility::get_sub_array_having( $plugins, 'active', 1 );
			$inactive = MainWP_Utility::get_sub_array_having( $plugins, 'active', 0 );

			if ( ! isset( $wp_plugins[ $website->id ] ) ) {
				$wp_plugins[ $website->id ] = array();
			}

			$wp_plugins[ $website->id ]['active']   = count( $active );
			$wp_plugins[ $website->id ]['inactive'] = count( $inactive );
			$wp_plugins[ $website->id ]['name']     = $website->name;
		}
		MainWP_DB::free_result( $websites );

		?>
		<div id="mainwp-module-log-chart-plugins-wrapper" ></div>

		<script type="text/javascript">
			jQuery( document ).ready( function() {
				var options = {
					chart: { 
						type: 'bar',
						stacked: true
					},
					plotOptions: {
						bar: {
							horizontal: true
						}
					},
					series: [ {
						name: 'Active Plugins',
						data: [
							<?php foreach ( $wp_plugins as $value ) : ?>
							{ 
							x: '<?php echo esc_js( $value['name'] ) . '"'; ?>',
							y: <?php echo intval( $value['active'] ); ?>,
							fillColor: "#18a4e0",
							},
							<?php endforeach; ?>
						]
					}, {
						name: 'Inactive Plugins',
						data: [
							<?php foreach ( $wp_plugins as $value ) : ?>
							{ 
							x: '<?php echo esc_js( $value['name'] ) . '"'; ?>',
							y: <?php echo intval( $value['inactive'] ); ?>,
							},
							<?php endforeach; ?>
						]
					}
					],
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
				var plugin = new ApexCharts(document.querySelector("#mainwp-module-log-chart-plugins-wrapper"), options);
				setTimeout(() => {
					plugin.render();
				}, 1000);
			} );
		</script>
		<?php
	}
}
