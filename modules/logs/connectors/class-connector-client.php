<?php
/**
 * Module Logs User connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Client.
 *
 * @package MainWP\Dashboard
 */
class Connector_Client extends Log_Connector {

	/**
	 * Connector name.
	 *
	 * @var string Connector slug.
	 * */
	public $name = 'client';


	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
		'mainwp_client_updated',
		'mainwp_client_deleted',
		'mainwp_client_suspend',
	);

	/**
	 * Return translated connector label
	 *
	 * @return string Translated connector label
	 */
	public function get_label() {
		return esc_html__( 'Clients', 'mainwp' );
	}

	/**
	 * Return translated action term labels
	 *
	 * @return array Action terms label translation
	 */
	public function get_action_labels() {
		return array(
			'updated'   => esc_html__( 'Updated', 'mainwp' ),
			'created'   => esc_html__( 'Created', 'mainwp' ),
			'deleted'   => esc_html__( 'Deleted', 'mainwp' ),
			'suspend'   => esc_html__( 'Suspend', 'mainwp' ),
			'unsuspend' => esc_html__( 'Unsuspend', 'mainwp' ),
			'lead'      => esc_html__( 'Lead', 'mainwp' ),
			'lost'      => esc_html__( 'Lost', 'mainwp' ),
		);
	}

	/**
	 * Return translated context labels
	 *
	 * @return array Context label translations
	 */
	public function get_context_labels() {
		return array(
			'clients' => esc_html__( 'Clients', 'mainwp' ),
		);
	}

	/**
	 * Register log data.
	 */
	public function register() { //phpcs:ignore -- overrided.
		parent::register();
	}

	/**
	 * Log client update
	 *
	 * @action mainwp_client_updated
	 *
	 * @param object $client  Client object.
	 * @param bool   $created true add new, false updated.
	 */
	public function callback_mainwp_client_updated( $client, $created = false ) {
		$action = $created ? 'created' : 'updated';
		// translators: Placeholder refers to a client (e.g. "Jane Doe").
		$message = esc_html__( '%s', 'mainwp' );

		$state = 1;
		$this->log(
			$message,
			array(
				'name'      => $client->name,
				'client_id' => $client->client_id,
			),
			0,
			'clients',
			$action,
			$state
		);
	}

	/**
	 * Log client delete
	 *
	 * @action mainwp_client_deleted
	 *
	 * @param object $client Client deleted.
	 */
	public function callback_mainwp_client_deleted( $client ) {
		// translators: Placeholder refers to a user display name (e.g. "Jane Doe").
		$message = esc_html__( '%s', 'mainwp' );
		$state   = 1;
		$this->log(
			$message,
			array(
				'name'      => $client->name,
				'client_id' => $client->client_id,
			),
			0,
			'clients',
			'deleted',
			$state
		);
	}

	/**
	 * Log client suspend/unsuspend.
	 *
	 * @action mainwp_client_suspend
	 *
	 * @param object $client Client deleted.
	 * @param bool   $status client status.
	 */
	public function callback_mainwp_client_suspend( $client, $status ) {

		if ( ! is_object( $client ) ) {
			return;
		}

		$status = intval( $status );

		$action = 'unsuspend';

		if ( 0 === $status ) {
			$action = 'unsuspend';
		} elseif ( 1 === $status ) {
			$action = 'suspend'; // actived.
		} elseif ( 2 === $status ) {
			$action = 'lead';
		} elseif ( 3 === $status ) {
			$action = 'lost';
		}

		// translators: Placeholder refers to a client name (e.g. "Jane Doe").
		$message = esc_html__( '%s', 'mainwp' );

		$state = 1;
		$this->log(
			$message,
			array(
				'name'          => $client->name,
				'client_id'     => $client->client_id,
				'suspend_value' => $status,
			),
			0,
			'clients',
			$action,
			$state
		);
	}
}
