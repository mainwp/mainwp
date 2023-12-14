<?php
/**
 * MainWP Database Client
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Client
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Client extends MainWP_DB {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, Generic.Metrics.CyclomaticComplexity -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create public static instance.
	 *
	 * @static
	 * @return MainWP_DB_Client
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		parent::__construct();
		add_filter( 'mainwp_db_install_tables', array( $this, 'hook_db_install_tables' ), 10, 3 );
	}

	/**
	 * Method hook_db_install_tables()
	 *
	 * Get the query to install db tables.
	 *
	 * @param array  $sql input filter.
	 * @param string $currentVersion Current db Version.
	 * @param string $charset_collate charset collate.
	 *
	 * @return array $sql queries.
	 */
	public function hook_db_install_tables( $sql, $currentVersion, $charset_collate ) {

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_clients' ) . " (
	client_id int(11) NOT NULL auto_increment,
	image varchar(255) NOT NULL default '',	
	name varchar(255) NOT NULL,
	address_1 varchar(255) NOT NULL default '',
	address_2 varchar(255) NOT NULL default '',
	city varchar(100) NOT NULL default '',
	zip varchar(32) NOT NULL default '',
	state varchar(200) NOT NULL default '',
	country varchar(200) NOT NULL default '',
	note longtext NOT NULL default '',
	client_email varchar(191) NOT NULL,
	client_phone varchar(100) NOT NULL default '',
	client_facebook varchar(255) NOT NULL default '',
	client_twitter varchar(255) NOT NULL default '',
	client_instagram varchar(255) NOT NULL default '',
	client_linkedin varchar(255) NOT NULL default '',
	created int(11) NOT NULL DEFAULT 0,
	`suspended` tinyint(1) NOT NULL DEFAULT 0,
	primary_contact_id int(11) NOT NULL default 0";

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.61', '<=' ) ) {
			$tbl .= ',
		PRIMARY KEY (client_id)';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_clients_fields' ) . ' (
	field_id int(11) NOT NULL auto_increment,
	field_name varchar(191) NOT NULL DEFAULT "",
	field_desc varchar(255) NOT NULL DEFAULT "",
	client_id int(11) NOT NULL,
	UNIQUE KEY client_id_field_name (client_id,field_name)';

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.61', '<=' ) ) {
			$tbl .= ',
		PRIMARY KEY  (`field_id`)  ';
		}

		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_clients_field_values' ) . ' (
	value_id int(11) NOT NULL auto_increment,
	field_id int(11) NOT NULL,
	field_value longtext NOT NULL DEFAULT "",
	value_client_id int(11) NOT NULL,
	UNIQUE KEY value_client_id_field_id (value_client_id, field_id)';

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.61', '<=' ) ) {
			$tbl .= ',
		PRIMARY KEY  (`value_id`) ';
		}

		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_clients_contacts' ) . ' (
	contact_id int(11) NOT NULL auto_increment,
	contact_client_id int(11) NOT NULL,
	contact_email varchar(191) NOT NULL,
	contact_image varchar(255) NOT NULL default "",
	contact_name varchar(255) NOT NULL,
	contact_phone varchar(100) NOT NULL default "",
	contact_role varchar(255) NOT NULL default "",
	facebook varchar(255) NOT NULL default "",
	twitter varchar(255) NOT NULL default "",
	instagram varchar(255) NOT NULL default "",
	linkedin varchar(255) NOT NULL default "",
	UNIQUE KEY contact_email(contact_email)';

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.69', '<=' ) ) {
			$tbl .= ',
		PRIMARY KEY (contact_id)';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		return $sql;
	}

	/**
	 * Method check_to_updates_reports_data_861()
	 *
	 * Check version less than 8.61 to import reports data.
	 *
	 * @param string $currentVersion Current db Version.
	 *
	 * @return void.
	 */
	public function check_to_updates_reports_data_861( $currentVersion ) {

		$convert_pro_reports_dt    = false;
		$convert_client_reports_dt = false;
		if ( is_plugin_active( 'mainwp-pro-reports-extension/mainwp-pro-reports-extension.php' ) ) {
			$convert_pro_reports_dt = true;
		} elseif ( is_plugin_active( 'mainwp-client-reports-extension/mainwp-client-reports-extension.php' ) ) {
			$convert_client_reports_dt = true;
		}

		if ( ! $convert_pro_reports_dt && ! $convert_client_reports_dt ) {
			$rslt = $this->query( "SHOW TABLES LIKE '" . $this->table_name( 'pro_reports_token' ) . "'" );
			if ( self::num_rows( $rslt ) ) {
				$convert_pro_reports_dt = true;
			}

			$rslt = $this->query( "SHOW TABLES LIKE '" . $this->table_name( 'client_report_token' ) . "'" );
			if ( self::num_rows( $rslt ) ) {
				$convert_client_reports_dt = true;
			}
		}

		$this->insert_client_fields_861( $currentVersion );

		if ( $convert_pro_reports_dt ) {
			$this->pro_reports_check_updates_861( $currentVersion );
		} elseif ( $convert_client_reports_dt ) {
			$this->client_reports_check_updates_861( $currentVersion );
		}
	}

	/**
	 * Method insert_client_fields_861()
	 *
	 * Check version less than 8.61 to import client reports data.
	 *
	 * @param string $currentVersion Current db Version.
	 *
	 * @return null.
	 */
	public function insert_client_fields_861( $currentVersion ) {

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.61', '>' ) ) {
			return;
		}

		$default_tokens = array(
			'client.name'              => 'Displays the Client Name',
			'client.contact.name'      => 'Displays the Client Contact Name',
			'client.contact.address.1' => 'Displays the Client Contact Address 1',
			'client.contact.address.2' => 'Displays the Client Contact Address 2',
			'client.city'              => 'Displays the Client City',
			'client.state'             => 'Displays the Client State',
			'client.zip'               => 'Displays the Client Zip',
			'client.phone'             => 'Displays the Client Phone',
			'client.email'             => 'Displays the Client Email',
			'client.note'              => 'Displays the Client Note',
		);

		// create or update default token.
		foreach ( $default_tokens as $field_name => $field_desc ) {
			$current = $this->get_client_fields_by( 'field_name', $field_name );
			if ( $current ) {
				$this->update_client_field(
					$current->field_id,
					array(
						'field_name' => $field_name,
						'field_desc' => $field_desc,
					)
				);
			} else {
				$this->add_client_field(
					array(
						'field_name' => $field_name,
						'field_desc' => $field_desc,
						'client_id'  => 0,
					)
				);

			}
		}
	}

	/**
	 * Method client_reports_check_updates_861()
	 *
	 * Check version less than 8.61 to import client reports data.
	 *
	 * @param string $currentVersion Current db Version.
	 *
	 * @return null.
	 */
	public function client_reports_check_updates_861( $currentVersion ) {

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.61', '>' ) ) {
			return;
		}

		$tokens              = $this->client_reports_get_tokens();
		$sites_tokens_values = $this->client_reports_get_site_token_values();

		$this->reports_check_updates_861( $tokens, $sites_tokens_values );
	}

	/**
	 * Method pro_reports_check_updates_861()
	 *
	 * Check version less than 8.61 to import pro reports data.
	 *
	 * @param string $currentVersion Current db Version.
	 *
	 * @return null.
	 */
	public function pro_reports_check_updates_861( $currentVersion ) {

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.61', '>' ) ) {
			return;
		}

		$tokens              = $this->pro_reports_get_tokens();
		$sites_tokens_values = $this->pro_reports_get_site_token_values();

		$this->reports_check_updates_861( $tokens, $sites_tokens_values );
	}


	/**
	 * Method reports_check_updates_861()
	 *
	 * Check version less than 8.61 to import reports data.
	 *
	 * @param array $tokens Tokens.
	 * @param array $sites_tokens_values Tokens values.
	 *
	 * @return void.
	 */
	public function reports_check_updates_861( $tokens, $sites_tokens_values ) { // phpcs:ignore -- complexex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$default_client_fields  = MainWP_Client_Handler::get_default_client_fields();
		$default_contact_fields = MainWP_Client_Handler::get_default_contact_fields();

		$compatible_tokens = MainWP_Client_Handler::get_compatible_tokens();

		$ids_to_tokens = array();

		if ( $tokens ) {
			foreach ( $tokens as $token ) {
				$tok_name                    = $token->token_name;
				$ids_to_tokens[ $token->id ] = array(
					'token_name' => $tok_name,
					'token_desc' => $token->token_description,
				);
			}
		}

		$ids_to_tokens_values = array();

		if ( $sites_tokens_values ) {
			foreach ( $sites_tokens_values as $token ) {
				if ( ! isset( $ids_to_tokens[ $token->token_id ] ) ) {
					continue;
				}
				if ( empty( $token->token_value ) ) {
					continue;
				}
				$tok_name = $ids_to_tokens[ $token->token_id ]['token_name'];

				$ids_to_tokens_values[ $token->site_id ][ $tok_name ] = array(
					'token_value' => $token->token_value,
					'token_desc'  => $ids_to_tokens[ $token->token_id ]['token_desc'],
				);
			}
		}

		$clients_to_add          = array();
		$primary_contacts_to_add = array();
		$custom_tokens_to_add    = array();

		foreach ( $ids_to_tokens_values as $site_id => $tokens ) {

			if ( empty( $tokens['client.email'] ) ) {
				continue;
			}

			$email = $tokens['client.email']['token_value'];

			if ( ! isset( $clients_to_add[ $email ] ) ) {
				$clients_to_add[ $email ] = array();
			}

			if ( ! isset( $clients_to_add[ $email ]['site_ids'] ) ) {
				$clients_to_add[ $email ]['site_ids'] = array();
			}

			$clients_to_add[ $email ]['site_ids'][] = $site_id;

			// prepare clients to add.
			foreach ( $default_client_fields as $field_name => $field ) {
				if ( ! isset( $tokens[ $field_name ] ) ) {
					continue;
				}
				$clients_to_add[ $email ][ $field['db_field'] ] = $tokens[ $field_name ]['token_value'];
			}

			$flip_compatible_tokens = array_flip( $compatible_tokens ); // flip: new_token_name => old_token_name.
			// prepare primary contacts to add.
			foreach ( $default_contact_fields as $field_name => $field ) {

				// $field_name - new_token_name.
				// $tok_name - old_token_name.
				$tok_name = isset( $flip_compatible_tokens[ $field_name ] ) ? $flip_compatible_tokens[ $field_name ] : $field_name;
				if ( ! isset( $tokens[ $tok_name ] ) ) {
					continue;
				}

				$primary_contacts_to_add[ $email ][ $field['db_field'] ] = $tokens[ $tok_name ]['token_value'];
			}

			foreach ( $tokens as $tok_name => $tok_value_desc ) {
				if ( isset( $default_client_fields[ $tok_name ] ) || isset( $default_contact_fields[ $tok_name ] ) ) {
					continue;
				}
				if ( 'client.site.url' === $tok_name || 'client.site.name' === $tok_name ) { // avoid those tokens.
					continue;
				}
				if ( isset( $compatible_tokens[ $tok_name ] ) ) {
					continue;
				}
				$custom_tokens_to_add[ $email ][ $tok_name ] = $tok_value_desc;
			}
		}

		$emails_to_ids = array();
		foreach ( $clients_to_add as $email => $client_add ) {
			$selected_sites = array();
			if ( isset( $client_add['site_ids'] ) ) {
				$selected_sites = $client_add['site_ids'];
				unset( $client_add['site_ids'] );
			}

			$inserted = $this->update_client( $client_add ); // add new client.

			if ( $inserted ) {
				$client_id = $inserted->client_id;
				$this->update_selected_sites_for_client( $client_id, $selected_sites );
				$emails_to_ids[ $email ] = $client_id;
			}
		}

		foreach ( $emails_to_ids as $email => $client_id ) {

			if ( isset( $primary_contacts_to_add[ $email ] ) ) {
				$contact_add                      = $primary_contacts_to_add[ $email ];
				$contact_add['contact_client_id'] = $client_id;
				$inserted                         = $this->update_client_contact( $contact_add );
				if ( $inserted ) {
					$params = array(
						'client_id'          => $client_id, // update the client.
						'primary_contact_id' => $inserted->contact_id,
					);
					$this->update_client( $params );
				}
			}

			if ( isset( $custom_tokens_to_add[ $email ] ) ) {
				$custom_tokens = $custom_tokens_to_add[ $email ];
				foreach ( $custom_tokens as $tok_name => $tok_value_desc ) {
					$field_id = 0;
					$current  = $this->get_client_fields_by( 'field_name', $tok_name ); // get general field by name.
					if ( $current ) {
						$field_id = $current->field_id;
					} else {
						$field     = array(
							'field_name' => $tok_name,
							'field_desc' => $tok_value_desc['token_desc'],
							'client_id'  => 0, // it is general tokens.
						);
						$new_field = $this->add_client_field( $field );
						if ( $new_field ) {
							$field_id = $new_field->field_id;
						}
					}

					if ( $field_id ) {
						$this->update_client_field_value( $field_id, $tok_value_desc['token_value'], $client_id );
					}
				}
			}
		}
	}


	/**
	 * Method update_client.
	 *
	 * Create or update client.
	 *
	 * @param array $data Client data.
	 * @param bool  $throw_out Throw or return error.
	 *
	 * @throws MainWP_Exception On errors.
	 *
	 * @return bool.
	 */
	public function update_client( $data, $throw_out = false ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		$client_id = isset( $data['client_id'] ) ? (int) $data['client_id'] : 0;

		if ( isset( $data['client_email'] ) && ! empty( $data['client_email'] ) ) {
			$client_existed = $this->get_wp_client_by( 'client_email', $data['client_email'], ARRAY_A );
			if ( is_array( $client_existed ) && isset( $client_existed['client_id'] ) && ( empty( $client_id ) || $client_id !== (int) $client_existed['client_id'] ) ) {
				if ( $throw_out ) {
					throw new MainWP_Exception( esc_html__( 'Client email exists. Please try again.', 'mainwp' ) );
				} else {
					return false;
				}
			}
		}

		if ( ! empty( $client_id ) ) {
			$this->wpdb->update( $this->table_name( 'wp_clients' ), $data, array( 'client_id' => intval( $client_id ) ) );
			return $this->get_wp_client_by( 'client_id', $client_id );
		} elseif ( $this->wpdb->insert( $this->table_name( 'wp_clients' ), $data ) ) {
				return $this->get_wp_client_by( 'client_id', $this->wpdb->insert_id );
		}
		return false;
	}


	/**
	 * Method update_client.
	 *
	 * Create or update client contact.
	 *
	 * @param array $data Contact data.
	 *
	 * @return bool.
	 */
	public function update_client_contact( $data ) {

		if ( empty( $data ) ) {
			return false;
		}

		$contact_id = isset( $data['contact_id'] ) ? (int) $data['contact_id'] : 0;

		$contact_email = isset( $data['contact_email'] ) ? $data['contact_email'] : '';
		if ( ! empty( $contact_email ) ) {
			$existed_email = false;
			// check to valid email.
			$curret_contact = $this->get_wp_client_contact_by( 'contact_email', $contact_email );
			if ( $curret_contact && empty( $contact_id ) ) { // add new contact with existed email => failed.
				$existed_email = true;
			}

			if ( $curret_contact && ! empty( $contact_id ) ) {
				if ( (int) $curret_contact->contact_id !== $contact_id ) { // update contact with existed email => failed.
					$existed_email = true;
				}
			}

			if ( $existed_email ) {
				MainWP_Utility::update_flash_message( 'contact_existed_emails', $contact_email );
				return false;
			}
		}

		if ( ! empty( $contact_id ) ) {
			$this->wpdb->update( $this->table_name( 'wp_clients_contacts' ), $data, array( 'contact_id' => intval( $contact_id ) ) );
			return $this->get_wp_client_contact_by( 'contact_id', $contact_id );
		} elseif ( $this->wpdb->insert( $this->table_name( 'wp_clients_contacts' ), $data ) ) {
				return $this->get_wp_client_contact_by( 'contact_id', $this->wpdb->insert_id );
		}
		return false;
	}

	/**
	 * Method get_wp_client_contact_by.
	 *
	 * Get client by.
	 *
	 * @param string $by by.
	 * @param mixed  $value by value.
	 * @param mixed  $obj Format data.
	 * @param array  $params other params.
	 *
	 * @return mixed $result results.
	 */
	public function get_wp_client_contact_by( $by = 'contact_id', $value = null, $obj = OBJECT, $params = array() ) {

		$with_selected_sites = isset( $params['with_selected_sites'] ) && $params['with_selected_sites'] ? true : false;
		$with_tags           = isset( $params['with_tags'] ) && $params['with_tags'] ? true : false;

		$sql = '';

		if ( 'client_id' === $by && ! empty( $value ) ) {
			$sql = $this->wpdb->prepare( 'SELECT wc.*  FROM ' . $this->table_name( 'wp_clients_contacts' ) . ' wc WHERE wc.contact_client_id=%d ', $value );
		}

		if ( ! empty( $sql ) ) {
			if ( OBJECT === $obj ) {
				$result = $this->wpdb->get_results( $sql, OBJECT );
			} else {
				$result = $this->wpdb->get_results( $sql, ARRAY_A );
			}
			return $result;
		}

		if ( 'contact_id' === $by && is_numeric( $value ) ) {
			$sql = $this->wpdb->prepare( 'SELECT wc.* FROM ' . $this->table_name( 'wp_clients_contacts' ) . ' wc WHERE wc.contact_id=%d ', $value );
		} elseif ( 'contact_email' === $by && ! empty( $value ) ) {
			$sql = $this->wpdb->prepare( 'SELECT wc.*  FROM ' . $this->table_name( 'wp_clients_contacts' ) . ' wc WHERE wc.contact_email=%s ', $value );
		}

		$result = null;
		if ( ! empty( $sql ) ) {
			if ( OBJECT === $obj ) {
				$result = $this->wpdb->get_row( $sql, OBJECT );
			} else {
				$result = $this->wpdb->get_row( $sql, ARRAY_A );
			}
		}
		return $result;
	}


	/**
	 * Method delete_client.
	 *
	 * Delete client.
	 *
	 * @param int $client_id Client id of the contact.
	 * @param int $contact_id Contact id to delete.
	 *
	 * @return bool Results.
	 */
	public function delete_client_contact( $client_id, $contact_id ) {
		$current = $this->get_wp_client_contact_by( 'contact_id', $contact_id );
		if ( $current && $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients_contacts' ) . ' WHERE contact_client_id=%d AND contact_id = %d', $client_id, $contact_id ) ) ) {
			if ( ! empty( $current->contact_image ) ) {
				$dirs     = MainWP_System_Utility::get_mainwp_dir( 'client-images', true );
				$base_dir = $dirs[0];
				$old_file = $base_dir . '/' . $current->contact_image;
				MainWP_Utility::delete_file( $old_file );
			}
			return true;
		}
		return false;
	}


	/**
	 * Method delete_client_contacts_by_client_id.
	 *
	 * Delete client contacts by client id.
	 *
	 * @param int $client_id Client id.
	 *
	 * @return bool Results.
	 */
	public function delete_client_contacts_by_client_id( $client_id ) {

		if ( empty( $client_id ) ) {
			return false;
		}

		$client_contacts = $this->get_wp_client_contact_by( 'client_id', $client_id, ARRAY_A );
		if ( $client_contacts ) {
			foreach ( $client_contacts as $contact ) {
				$this->delete_client_contact( $client_id, $contact['contact_id'] );
			}
		}
		return true;
	}


	/**
	 * Method get_wp_client_by.
	 *
	 * Get client by.
	 *
	 * @param string $by by.
	 * @param mixed  $value by value.
	 * @param mixed  $obj Format data.
	 * @param bool   $params Others params.
	 *
	 * @return mixed $result results.
	 */
	public function get_wp_client_by( $by = 'client_id', $value = null, $obj = OBJECT, $params = array() ) { // phpcs:ignore -- complexex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$with_selected_sites = isset( $params['with_selected_sites'] ) && $params['with_selected_sites'] ? true : false;
		$with_tags           = isset( $params['with_tags'] ) && $params['with_tags'] ? true : false;
		$group_ids           = isset( $params['by_tags'] ) ? $params['by_tags'] : '';

		// valid group ids.
		if ( is_array( $group_ids ) ) {
			$group_ids = array_filter(
				$group_ids,
				function ( $e ) {
					if ( 'nogroups' === $e ) {
						return true;
					}
					return ( is_numeric( $e ) && 0 < $e ) ? true : false;
				}
			);
		} else {
			$group_ids = '';
		}

		$select_sites  = '';
		$select_tags   = '';
		$where_clients = '';

		$sql = '';

		if ( $with_selected_sites ) {
			$select_sites .= ',( SELECT GROUP_CONCAT(wp.id SEPARATOR ",") FROM ' . $this->table_name( 'wp' ) . ' wp WHERE wp.client_id = wc.client_id ) as selected_sites ';
		}

		if ( ! empty( $group_ids ) ) {

			$join_group  = '';
			$where_group = '';

			if ( in_array( 'nogroups', $group_ids ) ) {
				$join_group = ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
				$group_ids  = array_filter(
					$group_ids,
					function ( $e ) {
						return 'nogroups' !== $e;
					}
				);
				if ( 0 < count( $group_ids ) ) {
					$groups      = implode( ',', $group_ids );
					$where_group = ' AND ( wpgroup.groupid IS NULL OR wpgroup.groupid IN (' . $groups . ') ) ';
				} else {
					$where_group = ' AND wpgroup.groupid IS NULL ';
				}
			} else {
				$groups      = implode( ',', $group_ids );
				$join_group  = ' JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
				$where_group = ' AND wpgroup.groupid IN (' . $groups . ') ';
			}

			$sql_tags    = ' SELECT wp.client_id FROM ' . $this->table_name( 'wp' ) . ' wp ';
			$sql_tags   .= $join_group;
			$sql_tags   .= ' WHERE 1 ' . $where_group;
			$result_tags = $this->wpdb->get_results( $sql_tags );

			if ( empty( $result_tags ) ) {
				return array();
			} else {
				$cli_ids = array();
				foreach ( $result_tags as $item ) {
					$cli_ids[] = $item->client_id;
				}
				$cli_ids       = array_values( array_unique( $cli_ids ) );
				$where_clients = ' AND wc.client_id IN (' . implode( ',', $cli_ids ) . ') ';
			}
		}

		if ( $with_tags ) {
			$select_tags .= ", ( SELECT GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ',') FROM " . $this->table_name( 'wp' ) . ' wp ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id ';
			$select_tags .= ' WHERE wp.client_id = wc.client_id ) as wpgroups ';

			$select_tags .= ", ( SELECT GROUP_CONCAT(gr.id ORDER BY gr.id SEPARATOR ',') FROM " . $this->table_name( 'wp' ) . ' wp ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id ';
			$select_tags .= ' WHERE wp.client_id = wc.client_id ) as wpgroupids ';
		}

		$join_contact = ' LEFT JOIN ' . $this->table_name( 'wp_clients_contacts' ) . ' cc ON wc.primary_contact_id = cc.contact_id ';

		if ( 'all' === $by ) {
			$sql  = ' SELECT wc.*, cc.* ' . $select_sites . $select_tags;
			$sql .= ' FROM ' . $this->table_name( 'wp_clients' ) . ' wc ';
			$sql .= $join_contact;
			$sql .= ' WHERE 1 ' . $where_clients;
			$sql .= ' ORDER BY wc.name';
		}

		if ( ! empty( $sql ) ) {
			if ( OBJECT === $obj ) {
				$result = $this->wpdb->get_results( $sql, OBJECT );
			} else {
				$result = $this->wpdb->get_results( $sql, ARRAY_A );
			}
			return $result;
		}

		if ( 'client_id' === $by && is_numeric( $value ) ) {
			$sql = $this->wpdb->prepare( 'SELECT wc.*, cc.* ' . $select_sites . $select_tags . ' FROM ' . $this->table_name( 'wp_clients' ) . ' wc ' . $join_contact . ' WHERE wc.client_id=%d ', $value );
		} elseif ( 'client_email' === $by && ! empty( $value ) ) {
			$sql = $this->wpdb->prepare( 'SELECT wc.*, cc.* ' . $select_sites . $select_tags . ' FROM ' . $this->table_name( 'wp_clients' ) . ' wc ' . $join_contact . ' WHERE wc.client_email=%s ', $value );
		}

		$result = null;
		if ( ! empty( $sql ) ) {
			if ( OBJECT === $obj ) {
				$result = $this->wpdb->get_row( $sql, OBJECT );
			} else {
				$result = $this->wpdb->get_row( $sql, ARRAY_A );
			}
		}
		return $result;
	}

	/**
	 * Method get_wp_clients.
	 *
	 * Get clients.
	 *
	 * @param array $params params.
	 *
	 * @return mixed result.
	 */
	public function get_wp_clients( $params = array() ) {

		$custom_field  = false;
		$with_tags     = false;
		$with_contacts = false;

		$where       = '';
		$select_tags = '';

		if ( $params && is_array( $params ) ) {
			$client = isset( $params['client'] ) ? sanitize_text_field( wp_unslash( $params['client'] ) ) : '';
			if ( '' !== $client ) {
				$clients     = explode( ';', $client );
				$clientWhere = '';
				foreach ( $clients as $clt ) {
					if ( is_numeric( $clt ) ) {
						$clientWhere .= intval( $clt ) . ', ';
					}
				}
				$clientWhere = rtrim( $clientWhere, ', ' );

				$where .= ' AND wc.client_id IN (' . $clientWhere . ') ';
			}
			$custom_field = $params['custom_fields'] && $params['custom_fields'] ? true : false;

			$with_tags     = isset( $params['with_tags'] ) && $params['with_tags'] ? true : false;
			$with_contacts = isset( $params['with_contacts'] ) && $params['with_contacts'] ? true : false;
		}

		if ( $with_tags ) {
			$select_tags .= ", ( SELECT GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ',') FROM " . $this->table_name( 'wp' ) . ' wp ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id ';
			$select_tags .= ' WHERE wp.client_id = wc.client_id ) as wpgroups ';

			$select_tags .= ", ( SELECT GROUP_CONCAT(gr.id ORDER BY gr.id SEPARATOR ',') FROM " . $this->table_name( 'wp' ) . ' wp ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid ';
			$select_tags .= ' LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id ';
			$select_tags .= ' WHERE wp.client_id = wc.client_id ) as wpgroupids ';
		}

		$sql  = ' SELECT wc.* ';
		$sql .= ',( SELECT GROUP_CONCAT(wp.id SEPARATOR ",") FROM ' . $this->table_name( 'wp' ) . ' wp WHERE wp.client_id = wc.client_id ) as selected_sites ' . $select_tags;
		$sql .= ' FROM ' . $this->table_name( 'wp_clients' ) . ' wc ';
		$sql .= ' WHERE 1 ' . $where . ' ORDER BY wc.name';

		$result = $this->wpdb->get_results( $sql, ARRAY_A );

		$contact_fields = array(
			'contact_id',
			'contact_client_id',
			'contact_email',
			'contact_name',
			'contact_phone',
			'contact_role',
			'contact_image',
			'facebook',
			'twitter',
			'instagram',
			'linkedin',
		);

		$output = array();

		foreach ( $result as $rst ) {

			if ( $custom_field ) {
				$custom_fields = $this->get_client_fields( true, $rst['client_id'] );
				if ( $custom_fields ) {
					foreach ( $custom_fields as $field ) {
						$rst[ $field->field_name ] = $field->field_value;
					}
				}
			}
			if ( $with_tags && ! empty( $rst['wpgroupids'] ) && ! empty( $rst['wpgroups'] ) ) {
				$wpgroupids = explode( ',', $rst['wpgroupids'] );
				$wpgroups   = explode( ',', $rst['wpgroups'] );

				$tags = array();
				if ( is_array( $wpgroupids ) ) {
					foreach ( $wpgroupids as $gidx => $groupid ) {
						if ( $groupid && ! isset( $tags[ $groupid ] ) ) {
							$tags[ $groupid ] = isset( $wpgroups[ $gidx ] ) ? $wpgroups[ $gidx ] : '';
						}
					}
				}

				$rst['tags'] = $tags;
			}

			if ( $with_contacts ) {
				$client_contacts = $this->get_wp_client_contact_by( 'client_id', $rst['client_id'], ARRAY_A );

				$contacts = array();

				if ( is_array( $client_contacts ) ) {
					foreach ( $client_contacts as $gidx => $contact ) {
						$contacts[ $contact['contact_id'] ] = MainWP_Utility::map_fields( $contact, $contact_fields, false );
					}
				}
				$rst['contacts'] = $contacts;
			}

			$output[] = $rst;
		}
		return $output;
	}

	/**
	 * Method update_selected_sites_for_client.
	 *
	 * Update client.
	 *
	 * @param int   $client_id client id.
	 * @param array $site_ids site ids.
	 *
	 * @return bool true|false.
	 */
	public function update_selected_sites_for_client( $client_id, $site_ids ) {
		if ( empty( $client_id ) ) {
			return false;
		}

		$site_ids = array_filter(
			$site_ids,
			function ( $e ) {
				return ( is_numeric( $e ) && 0 < $e ) ? true : false;
			}
		);

		if ( is_array( $site_ids ) && count( $site_ids ) > 0 ) {
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET client_id=0 WHERE client_id=%d AND id NOT IN (' . implode( ',', $site_ids ) . ')', $client_id ) );
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET client_id=%d WHERE id IN (' . implode( ',', $site_ids ) . ')', $client_id ) );
			return true;
		} else {
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET client_id=0 WHERE client_id=%d ', $client_id ) );
			return true;
		}
		return false;
	}

	/**
	 * Method delete_client.
	 *
	 * Delete client.
	 *
	 * @param int $client_id Client id to delete.
	 * @return bool Results.
	 */
	public function delete_client( $client_id ) {

		$current = $this->get_wp_client_by( 'client_id', $client_id );
		if ( $current && $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients' ) . ' WHERE client_id=%d ', $client_id ) ) ) {
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET client_id=0 WHERE client_id=%d ', $client_id ) );
			$fields = $this->get_client_fields_by( 'client_id', $client_id );
			if ( $fields ) {
				foreach ( $fields as $field ) {
					$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients_fields' ) . ' WHERE field_id=%d ', $field->field_id ) );
					$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients_field_values' ) . ' WHERE field_id=%d AND value_client_id=%d ', $field->field_id, $client_id ) );
				}
			}

			if ( ! empty( $current->image ) ) {
				$dirs     = MainWP_System_Utility::get_mainwp_dir( 'client-images', true );
				$base_dir = $dirs[0];
				$old_file = $base_dir . '/' . $current->image;
				MainWP_Utility::delete_file( $old_file );
			}

			$this->delete_client_contacts_by_client_id( $client_id );

			/**
			 * Delete client
			 *
			 * Fires after delete a client.
			 *
			 * @param object $current client deleted.
			 *
			 * @since 4.5.1.1
			 */
			do_action( 'mainwp_client_deleted', $current );

			return true;
		}
		return false;
	}

	/**
	 * Method get_websites_by_client_ids.
	 *
	 * Get websites by client.
	 *
	 * @param int   $client_ids Client ids.
	 * @param array $params other params.
	 *
	 * @return mixed $result Results.
	 */
	public function get_websites_by_client_ids( $client_ids, $params = array() ) {

		if ( empty( $client_ids ) ) {
			return false;
		}

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$client = '';
		if ( is_array( $client_ids ) ) {
			// valid client ids.
			$client_ids = array_filter(
				$client_ids,
				function ( $e ) {
					return is_numeric( $e ) && ! empty( $e ) ? true : false; // to valid client ids.
				}
			);
			$client     = implode( ';', $client_ids );
		} else {
			$client = $client_ids;
		}

		$params['client'] = $client;

		return MainWP_DB::instance()->get_websites_for_current_user( $params );
	}

	/**
	 * Method get_number_sites_of_client.
	 *
	 * Get client by.
	 *
	 * @param int $client_id Client id.
	 *
	 * @return int Number of sites.
	 */
	public function get_number_sites_of_client( $client_id ) {
		if ( empty( $client_id ) ) {
			return false;
		}
		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp WHERE wp.client_id =%d', $client_id ) );
	}

	/**
	 * Method count_total_clients.
	 *
	 * Count total of Clients.
	 *
	 * @return int Total number.
	 */
	public function count_total_clients() {
		return $this->wpdb->get_var( 'SELECT COUNT(client_id) FROM ' . $this->table_name( 'wp_clients' ) );
	}


	/**
	 * Method add_client_field.
	 *
	 * Add client field.
	 *
	 * @param mixed $field Client fields data.
	 *
	 * @return mixed bool|results.
	 */
	public function add_client_field( $field ) {
		if ( ! empty( $field['field_name'] ) && isset( $field['client_id'] ) ) {
			if ( $this->get_client_fields_by( 'field_name', $field['field_name'], $field['client_id'] ) ) { // field name existed for the client can not create.
				return false;
			}
			if ( $this->wpdb->insert( $this->table_name( 'wp_clients_fields' ), $field ) ) {
				return $this->get_client_fields_by( 'field_id', $this->wpdb->insert_id );
			}
		}
		return false;
	}

	/**
	 * Method update_client_field.
	 *
	 * Update client field.
	 *
	 * @param int   $field_id field id.
	 * @param array $field field data.
	 *
	 * @return mixed|false field.
	 */
	public function update_client_field( $field_id, $field ) {
		if ( $field_id && ! empty( $field['field_name'] ) ) {
			$current = $this->get_client_fields_by( 'field_id', $field_id );
			if ( $current ) {
				if ( $this->wpdb->update( $this->table_name( 'wp_clients_fields' ), $field, array( 'field_id' => $field_id ) ) ) {
					return $this->get_client_fields_by( 'field_id', $current->field_id );
				}
			}
		}
		return false;
	}

	/**
	 * Method update_client_field_value.
	 *
	 * Update client field value.
	 *
	 * @param int   $field_id field id.
	 * @param mixed $value field value.
	 * @param int   $client_id client id.
	 *
	 * @return mixed|false field data.
	 */
	public function update_client_field_value( $field_id, $value, $client_id ) {
		if ( ! empty( $field_id ) ) {
			$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_clients_field_values' ) . ' WHERE field_id = %d AND value_client_id=%d ', $field_id, $client_id ) );
			if ( $row ) {
				if ( $row->field_value != $value ) { //phpcs:ignore -- to valid.
					$this->wpdb->update( $this->table_name( 'wp_clients_field_values' ), array( 'field_value' => $value ), array( 'value_id' => $row->value_id ) );
				}
				return true;
			} else {
				return $this->wpdb->insert(
					$this->table_name( 'wp_clients_field_values' ),
					array(
						'field_id'        => $field_id,
						'field_value'     => $value,
						'value_client_id' => $client_id,
					)
				);
			}
		}
		return false;
	}

	/**
	 * Method get_client_fields_by.
	 *
	 * Get client field.
	 *
	 * @param string $by by.
	 * @param mixed  $value field value.
	 * @param int    $client_id client id.
	 *
	 * @return mixed|null $field field data.
	 */
	public function get_client_fields_by( $by = 'field_id', $value = null, $client_id = 0 ) {

		if ( empty( $by ) || empty( $value ) ) {
			return null;
		}

		$sql = '';

		if ( 'client_id' === $by ) {
			$sql = $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_clients_fields' ) . ' WHERE `client_id`=%d ', $value );
			return $this->wpdb->get_results( $sql );
		}

		if ( 'field_name' === $by ) {
			$value = str_replace( array( '[', ']' ), '', $value );
		}

		if ( 'field_id' === $by ) {
			$sql = $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_clients_fields' ) . ' WHERE `field_id`=%d', $value );
		} elseif ( 'field_name' === $by ) {
			$sql = $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_clients_fields' ) . ' WHERE `field_name` = %s AND `client_id`=%d ', $value, $client_id );
		}

		$field = null;

		if ( ! empty( $sql ) ) {
			$field = $this->wpdb->get_row( $sql );
		}

		return $field;
	}

	/**
	 * Method get_client_fields()
	 *
	 * Get client fields.
	 *
	 * @param bool $general Get general fields OR not.
	 * @param int  $client_id Client id.
	 * @param bool $field_name_index index result with field name.
	 *
	 * @return mixed results.
	 */
	public function get_client_fields( $general = true, $client_id = 0, $field_name_index = false ) {
		$where = '';

		if ( $client_id ) {
			if ( true === $general ) {
				$where = ' f.client_id = ' . intval( $client_id ) . ' OR ( f.client_id = 0 AND ( v.value_client_id IS NULL OR v.value_client_id = ' . intval( $client_id ) . ') ) ';
			} else {
				$where = ' f.client_id = ' . intval( $client_id );
			}
		} elseif ( true === $general ) {
			$where = ' f.client_id = 0';
		}

		if ( '' === $where ) {
			return false;
		}

		$gene_results = array();

		if ( $general ) {
			// to fix: missing general fields for client without client fields values.
			$gene_sql     = 'SELECT f.* FROM ' . $this->table_name( 'wp_clients_fields' ) . ' f WHERE f.client_id = 0 ORDER BY f.field_name ';
			$gene_results = $this->wpdb->get_results( $gene_sql );
		}

		if ( $client_id ) { // get fields values of client.
			$sql = 'SELECT f.*, v.field_value as field_value FROM ' . $this->table_name( 'wp_clients_fields' ) . ' f LEFT JOIN ' .
			$this->table_name( 'wp_clients_field_values' ) . ' v ON f.field_id = v.field_id WHERE ' . $where . ' ORDER BY f.field_name ';
		} else {
			$sql = 'SELECT f.* FROM ' . $this->table_name( 'wp_clients_fields' ) . ' f WHERE ' . $where . ' ORDER BY f.field_name '; // value_client_id to compatible result.
		}

		$results = $this->wpdb->get_results( $sql );

		$fields_list = array();
		if ( $field_name_index ) {
			if ( $results ) {
				foreach ( $results as $item ) {
					$fields_list[ $item->field_name ] = $item;
				}
			}
			if ( $gene_results ) {
				foreach ( $gene_results as $gene_item ) {
					if ( ! isset( $fields_list[ $gene_item->field_name ] ) ) {
						$gene_item->field_value                = '';
						$fields_list[ $gene_item->field_name ] = $gene_item;
					}
				}
			}
		} else {
			$check_list = array();
			if ( $results ) {
				foreach ( $results as $item ) {
					$check_list[ $item->field_name ] = true;
					$fields_list[]                   = $item;
				}
			}
			if ( $gene_results ) {
				foreach ( $gene_results as $gene_item ) {
					if ( ! isset( $check_list[ $gene_item->field_name ] ) ) {
						$check_list[ $gene_item->field_name ] = true;
						$gene_item->field_value               = '';
						$fields_list[]                        = $gene_item;
					}
				}
			}
		}
		return $fields_list;
	}

	/**
	 * Suspend - Unsuspended websites.
	 *
	 * @param int $client_id Client id.
	 * @param int $suspend_val Suspend value: 0 or 1.
	 *
	 * @return int|boolean The result.
	 */
	public function suspend_unsuspend_websites_by_client_id( $client_id, $suspend_val ) {
		if ( empty( $client_id ) ) {
			return false;
		}
		return $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET suspended=%d WHERE client_id=%d ', $suspend_val, $client_id ) );
	}

	/**
	 * Method delete_client_field_by.
	 *
	 * Delete client field by.
	 *
	 * @param string $by by.
	 * @param mixed  $value field value.
	 * @param int    $client_id client id.
	 *
	 * @return bool Deleted or not.
	 */
	public function delete_client_field_by( $by = 'field_id', $value = false, $client_id = false ) {

		if ( 'field_id' === $by ) {
			if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients_fields' ) . ' WHERE field_id=%d AND client_id=%d', $value, $client_id ) ) ) { // delete individual or general client field.
				if ( $client_id > 0 ) { // individual field value.
					$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients_field_values' ) . ' WHERE field_id=%d AND value_client_id=%d', $value, $client_id ) ); // delete individual tokens values, for one client.
				} else {
					$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_clients_field_values' ) . ' WHERE field_id=%d', $value ) ); // delete general tokens values, multi clients (value_client_id).
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * Method pro_reports_get_tokens()
	 *
	 * Get pro reports get tokens data.
	 *
	 * @return mixed results.
	 */
	public function pro_reports_get_tokens() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'pro_reports_token' ) . ' WHERE 1 = 1 ORDER BY type DESC, token_name ASC' );
	}

	/**
	 * Method pro_reports_get_site_token_values()
	 *
	 * Get pro reports get tokens values data.
	 *
	 * @return mixed results.
	 */
	public function pro_reports_get_site_token_values() {
		return $this->wpdb->get_results( ' SELECT * FROM ' . $this->table_name( 'pro_reports_site_token' ) . ' WHERE 1 ' );
	}

	/**
	 * Method client_reports_get_tokens()
	 *
	 * Get client reports get tokens data.
	 *
	 * @return mixed results.
	 */
	public function client_reports_get_tokens() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE 1 = 1 ORDER BY token_name ASC' );
	}

	/**
	 * Method client_reports_get_site_token_values()
	 *
	 * Get client reports get tokens values data.
	 *
	 * @return mixed results.
	 */
	public function client_reports_get_site_token_values() {
		return $this->wpdb->get_results( ' SELECT * FROM ' . $this->table_name( 'client_report_site_token' ) . ' WHERE 1 ' );
	}
}
