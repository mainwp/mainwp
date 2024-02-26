<?php
/**
 * Module Logs Site connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Site
 */
class Connector_Site extends Log_Connector {

	/**
	 * Connector name.
	 *
	 * @var string Connector slug.
	 */
	public $name = 'site';

	/**
	 * Connector name.
	 *
	 * @var array Actions registered for this connector.
	 **/
	public $actions = array(
		'mainwp_site_added', // site::added.
		'mainwp_site_updated', // site::updated.
		'mainwp_site_sync', // site::synced.
		'mainwp_site_deleted', // site::deleted.
		'mainwp_site_reconnected', // site::reconnected.
		'mainwp_site_suspended', // site::updated suspended value.
		'mainwp_site_tag_action',
	);

	/**
	 * Return translated connector label.
	 *
	 * @return string Translated connector label.
	 */
	public function get_label() {
		return esc_html__( 'Sites', 'mainwp' );
	}

	/**
	 * Return translated action labels.
	 *
	 * @return array Action label translations.
	 */
	public function get_action_labels() {
		return array(
			'added'     => esc_html__( 'Added', 'mainwp' ),
			'updated'   => esc_html__( 'Updated', 'mainwp' ),
			'sync'      => esc_html__( 'Sync Data', 'mainwp' ),
			'deleted'   => esc_html__( 'Deleted', 'mainwp' ),
			'suspend'   => esc_html__( 'Suspend', 'mainwp' ),
			'unsuspend' => esc_html__( 'Unsuspend', 'mainwp' ),
			'reconnect' => esc_html__( 'Reconnected', 'mainwp' ),
		);
	}

	/**
	 * Return translated context labels.
	 *
	 * @return array Context label translations.
	 */
	public function get_context_labels() {
		return array(
			'sites' => esc_html__( 'Sites', 'mainwp' ),
		);
	}

	/**
	 * Register log data.
	 */
	public function register() { //phpcs:ignore -- overrided.
		parent::register();
	}

	/**
	 * Log site added process.
	 *
	 * @action transition_post_status.
	 *
	 * @param object $website Website object data.
	 * @param array  $information Sync array data.
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_added( $website, $information = array() ) {
		if ( empty( $website ) || ! is_object( $website ) || empty( $website->id ) ) {
			return;
		}
		$state = 1;
		$this->log(
			esc_html_x(
				'%1$s',
				'1. item',
				'mainwp'
			),
			array(
				'item'      => esc_html__( 'Added', 'mainwp' ),
				'site_name' => $website->name,
				'siteurl'   => $website->url,
			),
			$website->id,
			'sites',
			'added',
			$state
		);
		return true;
	}

	/**
	 * Log site sync process.
	 *
	 * @param object $website Website object data.
	 * @param array  $information Sync array data.
	 * @param bool   $success Sync success or failed.
	 * @param string $sync_error Sync error data (options).
	 * @param array  $post_data addition post data (options).
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_sync( $website, $information, $success, $sync_error = '', $post_data = array() ) {

		$action = 'sync';
		$state  = null;
		if ( $success ) {
			$message = esc_html_x(
				'%1$s',
				'1. Item',
				'mainwp'
			);
			$state   = 1;
		} else {
			$message = esc_html_x(
				'%1$s',
				'1. Item',
				'mainwp'
			);
			$state   = 0;
		}

		$args = array(
			'item'      => esc_html__( 'Sync Data', 'mainwp' ),
			'site_name' => $website->name,
			'siteurl'   => $website->url,
		);

		if ( ! empty( $sync_error ) ) {
			$info               = array(
				'sync_error' => $sync_error,
			);
			$args['extra_info'] = wp_json_encode( $info );
		}

		$this->log(
			$message,
			$args,
			$website->id,
			'sites',
			$action,
			$state
		);

		return true;
	}


	/**
	 * Log site sync process.
	 *
	 * @action transition_post_status.
	 *
	 * @param object $website Website object data.
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_deleted( $website ) {
		$state = 1;
		$this->log(
			esc_html_x(
				'%1$s',
				'1. Item',
				'mainwp'
			),
			array(
				'item'      => esc_html__( 'Deleted', 'mainwp' ),
				'site_name' => $website->name,
				'siteurl'   => $website->url,
			),
			$website->id,
			'sites',
			'deleted',
			$state
		);
		return true;
	}

	/**
	 * Log site sync process.
	 *
	 * @action transition_post_status.
	 *
	 * @param object $website Website object data.
	 * @param bool   $success success or not.
	 * @param string $error reconnect error.
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_reconnected( $website, $success = true, $error = '' ) {

		if ( $success ) {
			$message = esc_html_x(
				'%1$s',
				'1. Item',
				'mainwp'
			);
		} else {
			$message = esc_html_x(
				'%1$s',
				'1. Item',
				'mainwp'
			);
		}

		$args = array(
			'item'      => esc_html__( 'Reconnect', 'mainwp' ),
			'site_name' => $website->name,
			'siteurl'   => $website->url,
		);

		if ( ! empty( $error ) ) {
			$other              = array(
				'error' => $error,
			);
			$args['extra_info'] = wp_json_encode( $other );
		}

		$state = $success ? 1 : 0;
		$this->log(
			$message,
			$args,
			$website->id,
			'sites',
			'reconnect',
			$state
		);
		return true;
	}


	/**
	 * Log site updated process.
	 *
	 * @action transition_post_status.
	 *
	 * @param object $website Website object data.
	 * @param array  $post_values POST values.
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_updated( $website, $post_values ) {
		$state = 1;
		$this->log(
			esc_html_x(
				'%1$s',
				'1. item',
				'mainwp'
			),
			array(
				'item'      => esc_html__( 'Updated', 'mainwp' ),
				'site_name' => $website->name,
				'siteurl'   => $website->url,
			),
			$website->id,
			'sites',
			'updated',
			$state
		);
		return true;
	}

	/**
	 * Log site updated suspended process.
	 *
	 * @action mainwp_site_suspended.
	 *
	 * @param object $website Website object data.
	 * @param int    $suspended suspended value.
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_suspended( $website, $suspended ) {

		$action = $suspended ? 'suspend' : 'unsuspend';

		if ( $suspended ) {
			$message = esc_html_x(
				'%1$s',
				'1. item',
				'mainwp'
			);
			$args    = array(
				'item' => esc_html__( 'Suspend', 'mainwp' ),
			);
		} else {
			$message = esc_html_x(
				'%1$s',
				'1. item',
				'mainwp'
			);
			$args    = array(
				'item' => esc_html__( 'Unsuspend', 'mainwp' ),
			);
		}

		$args['siteurl']   = $website->url;
		$args['site_name'] = $website->name;

		$state = 1;
		$this->log(
			$message,
			$args,
			$website->id,
			'sites',
			$action,
			$state
		);
		return true;
	}


	/**
	 * Log Tag actions.
	 *
	 * @action mainwp_site_tag_action.
	 *
	 * @param object $tag tag object data.
	 * @param string $action tag action.
	 * @param array  $data other data array (option).
	 *
	 * @return bool Return TRUE.
	 */
	public function callback_mainwp_site_tag_action( $tag, $action, $data = array() ) {

		if ( empty( $tag ) || ! is_string( $action ) || ! in_array( $action, array( 'created', 'updated', 'deleted' ), true ) ) {
			return;
		}

		$args = array(
			'name' => $tag->name,
		);

		if ( 'created' === $action ) {
			$message = esc_html_x(
				'%1$s',
				'1. Tag name',
				'mainwp'
			);
		} elseif ( 'updated' === $action ) {
			$message = esc_html_x(
				'%1$s',
				'1. Tag name',
				'mainwp'
			);
			$args    = array(
				'name'     => $tag->name,
				'old_name' => is_array( $data ) && isset( $data['old_name'] ) ? $data['old_name'] : '',
			);
		} elseif ( 'deleted' === $action ) {
			$message = esc_html_x(
				'%1$s',
				'1. Tag name',
				'mainwp'
			);
		} else {
			return;
		}

		$state = 1;

		$this->log(
			$message,
			$args,
			0,
			'tags',
			$action,
			$state
		);
		return true;
	}
}
