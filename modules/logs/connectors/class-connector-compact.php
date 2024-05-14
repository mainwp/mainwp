<?php
/**
 * Module Compact connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_User
 */
class Connector_Compact extends Log_Connector {
		/**
		 * Connector slug
		 *
		 * @var string
		 */
		public $name = 'compact';


		/**
		 * Actions registered for this connector
		 *
		 * @var array
		 */
		public $actions = array(
			'mainwp_compact_action',
		);

		/**
		 * Return translated connector label
		 *
		 * @return string Translated connector label
		 */
		public function get_label() {
			return esc_html__( 'Compact', 'mainwp' );
		}

		/**
		 * Return translated action term labels
		 *
		 * @return array Action terms label translation
		 */
		public function get_action_labels() {
			return array(
				'saved' => esc_html__( 'Saved', 'mainwp' ),
			);
		}

		/**
		 * Return translated context labels
		 *
		 * @return array Context label translations
		 */
		public function get_context_labels() {
			return array(
				'compact' => esc_html__( 'Compact', 'mainwp' ),
			);
		}

		/**
		 * Log compact actions.
		 *
		 * @action mainwp_compact_action
		 *
		 * @param string $action post action.
		 * @param int    $year compact year.
		 * @param array  $data data array.
		 * @param array  $start_time data array.
		 * @param array  $end_time data array.
		 */
		public function callback_mainwp_compact_action( $action, $year, $data, $start_time, $end_time ) {

			if ( 'saved' !== $action || empty( $year ) || ! is_numeric( $year ) || ! is_array( $data ) ) {
				return;
			}

			// translators: Placeholder refers log item.
			$message = esc_html__( '%s', 'mainwp' );

			$context = $year;

			$args = array(
				'item'         => esc_html__( 'Compact Logs', 'mainwp' ),
				'start_time'   => $start_time,
				'end_time'     => $end_time,
				'compact_logs' => wp_json_encode( $data ),
			);

			$state = 1;

			$this->log(
				$message,
				$args,
				0,
				$context,
				$action,
				$state
			);
		}
}
