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
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Graph_Tags_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Tags_Widget {

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
				<?php esc_html_e( 'Tag Allocation Overview', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Distribution of sites and clients associated with each tag for efficient resource categorization and insight.', 'mainwp' ); ?>
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

		$groups         = MainWP_DB_Common::instance()->get_groups_and_count();
		$groups_nrsites = array();
		if ( ! empty( $groups ) ) {
			foreach ( $groups as $group ) {
				$groups_nrsites[ $group->name ] = ! empty( $group->nrsites ) ? $group->nrsites : 0;

			}
		}
		ksort( $groups_nrsites );

		$params  = array(
			'with_tags' => true,
		);
		$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all', null, ARRAY_A, $params );

		$tags_names = array();
		$tags_count = array();
		if ( ! empty( $clients ) ) {
			foreach ( $clients as $client ) {
				$tags     = ! empty( $client['wpgroups'] ) ? explode( ',', $client['wpgroups'] ) : array();
				$tags_ids = ! empty( $client['wpgroupids'] ) ? explode( ',', $client['wpgroupids'] ) : array();

				if ( ! is_array( $tags ) ) {
					$tags = array();
				}

				if ( ! is_array( $tags_ids ) ) {
					$tags_ids = array();
				}

				foreach ( $tags_ids as $idx => $tagid ) {
					if ( ! isset( $tags_count[ $tagid ] ) ) {

						$tags_count[ $tagid ] = 1;
					} else {
						$tags_count[ $tagid ] += 1;
					}

					if ( ! isset( $tags_names[ $tagid ] ) ) {
						$tags_names[ $tagid ] = $tags[ $idx ];
					}
				}
			}
		}

		asort( $tags_names );
		?>
		<div id="mainwp-module-log-chart-tags-wrapper" ></div>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				var options = {
					chart: { 
						type: 'bar'
					},
					series: [ {
						name: 'Sites',
						data: [
							<?php foreach ( $groups_nrsites as $group => $nrsites ) : ?>
								<?php if ( 'Staging Sites' !== $group ) : ?>
								{ 
								x: '<?php echo esc_js( $group ); ?>',
								y: <?php echo intval( $nrsites ); ?>,
								fillColor: "#18a4e0",
								},
								<?php endif; ?>
							<?php endforeach; ?>
						]
					},{
						name: 'Clients',
						data: [
							<?php foreach ( $tags_names as $tagid => $tagname ) : ?>
								<?php if ( 'Staging Sites' !== $tagname ) : ?>
								{ 
								x: '<?php echo esc_js( $tagname ); ?>',
								y: <?php echo isset( $tags_count[ $tagid ] ) ? intval( $tags_count[ $tagid ] ) : 0; ?>,
								fillColor: "#7fb100",
								},
								<?php endif; ?>
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
				var tags = new ApexCharts(document.querySelector("#mainwp-module-log-chart-tags-wrapper"), options);
				setTimeout(() => {
					tags.render();
				}, 1000);
			} );
		</script>
		<?php
	}
}
