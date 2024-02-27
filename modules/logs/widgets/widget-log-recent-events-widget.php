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
 * Class Log_Recent_Events_Widget
 *
 * Displays the Logs info.
 */
class Log_Recent_Events_Widget {

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
	 * @return mixed render_site_info()
	 */
	public function render() {
		$this->render_recent_events();
	}


	/**
	 * Render client overview Info.
	 */
	public function render_recent_events() {
		$manager    = Log_Manager::instance();
		$list_table = new Log_Events_List_Table( $manager );
		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
				<?php esc_html_e( 'Recent Activity Log', 'mainwp' ); ?>
				<div class="sub header">
				<?php esc_html_e( 'Chronological log of the latest activities performed on the system.', 'mainwp' ); ?>
				</div>
			</h3>
		</div>

		<div class="mainwp-scrolly-overflow">
				<?php
				/**
				 * Actoin: mainwp_logs_widget_top
				 *
				 * Fires at the top of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_top', 'recent_events' );
				?>
					<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
					<?php
					wp_nonce_field( 'mainwp-admin-nonce' );
					$list_table->display();
					?>
				<?php
				/**
				 * Action: mainwp_logs_widget_bottom
				 *
				 * Fires at the bottom of the widget.
				 *
				 * @since 4.6
				 */
				do_action( 'mainwp_logs_widget_bottom', 'recent_events' );
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
}
