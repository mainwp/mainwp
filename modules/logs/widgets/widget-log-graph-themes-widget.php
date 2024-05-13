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
 * Class Log_Graph_Themes_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Themes_Widget {

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
				<?php esc_html_e( 'Active Themes Overview', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Number of sites using each theme. The bar height indicates the prevalence of a theme across your network.', 'mainwp' ); ?>
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
				do_action( 'mainwp_logs_widget_top', 'themes' );
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
				do_action( 'mainwp_logs_widget_bottom', 'themes' );
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
		$themes   = array();
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'site_info' ) ) );
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			$site_info = ! empty( $website->site_info ) ? json_decode( $website->site_info, true ) : array();
			if ( ! is_array( $site_info ) ) {
				$site_info = array();
			}
			$theme = isset( $site_info['themeactivated'] ) ? $site_info['themeactivated'] : 'N/A';
			if ( ! isset( $themes[ $theme ] ) ) {
				$themes[ $theme ] = 1;
			}
			$themes[ $theme ] += 1;
		}
		MainWP_DB::free_result( $websites );
		?>
		<div id="mainwp-module-log-chart-themes-wrapper"></div>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				var options = {
					chart: { 
						type: 'bar'
					},
					series: [ {
						name: 'Sites',
						data: [
							<?php foreach ( $themes as $theme => $count ) : ?>
							{ 
							x: '<?php echo esc_js( $theme ); ?>',
							y: <?php echo intval( $count ); ?>,
							fillColor: <?php echo $count >= 3 ? '"#7fb100"' : '"#18a4e0"'; ?>,
							},
							<?php endforeach; ?>
						]
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
				var theme = new ApexCharts(document.querySelector("#mainwp-module-log-chart-themes-wrapper"), options);
				setTimeout(() => {
					theme.render();
				}, 1000);
			} );
		</script>
		<?php
	}
}
