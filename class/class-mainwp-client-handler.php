<?php
/**
 * Client Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Handler
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Client_Handler
 */
class MainWP_Client_Handler {

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
	 * Create a public static instance.
	 *
	 * @static
	 * @return MainWP_Client_Handler
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Get default client fields.
	 *
	 * Get default client fields.
	 */
	public static function get_default_client_fields() {

		return array(
			'client.name'              => array(
				'title'    => esc_html__( 'Client Name (Required)', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the Client name', 'mainwp' ),
				'db_field' => 'name',
			),
			'client.email'             => array(
				'title'    => esc_html__( 'Client email', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client email', 'mainwp' ),
				'db_field' => 'client_email',
			),
			'client.phone'             => array(
				'title'    => esc_html__( 'Client phone', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client phone', 'mainwp' ),
				'db_field' => 'client_phone',
			),
			'client.facebook'          => array(
				'title'    => esc_html__( 'Client Facebook', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client Facebook', 'mainwp' ),
				'db_field' => 'client_facebook',
			),
			'client.twitter'           => array(
				'title'    => esc_html__( 'Client Twitter', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client Twitter', 'mainwp' ),
				'db_field' => 'client_twitter',
			),
			'client.instagram'         => array(
				'title'    => esc_html__( 'Client Instagram', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client Instagram', 'mainwp' ),
				'db_field' => 'client_instagram',
			),
			'client.linkedin'          => array(
				'title'    => esc_html__( 'Client LinkedIn', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client LinkedIn', 'mainwp' ),
				'db_field' => 'client_linkedin',
			),
			'client.contact.address.1' => array(
				'title'    => esc_html__( 'Client address 1', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client contact address 1', 'mainwp' ),
				'db_field' => 'address_1',
			),
			'client.contact.address.2' => array(
				'title'    => esc_html__( 'Client address 2', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client contact address 2', 'mainwp' ),
				'db_field' => 'address_2',
			),
			'client.city'              => array(
				'title'    => esc_html__( 'Client city', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client city', 'mainwp' ),
				'db_field' => 'city',
			),
			'client.state'             => array(
				'title'    => esc_html__( 'Client state', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client state', 'mainwp' ),
				'db_field' => 'state',
			),
			'client.zip'               => array(
				'title'    => esc_html__( 'Client ZIP', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client ZIP code', 'mainwp' ),
				'db_field' => 'zip',
			),
			'client.country'           => array(
				'title'    => esc_html__( 'Client country', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client country', 'mainwp' ),
				'db_field' => 'country',
			),
			'client.note'              => array(
				'title'    => esc_html__( 'Client notes', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client note', 'mainwp' ),
				'db_field' => 'note',
			),
			'client.suspended'         => array(
				'title'    => esc_html__( 'Client status', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client suspended state', 'mainwp' ),
				'db_field' => 'suspended',
			),
			'client.created'           => array(
				'title'    => esc_html__( 'Added on', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client added on', 'mainwp' ),
				'tooltip'  => esc_html__( 'Set the date your client was added to your MainWP Dashboard.', 'mainwp' ),
				'db_field' => 'created',
			),
		);
	}

	/**
	 * Get default client fields.
	 *
	 * Get default client fields.
	 */
	public static function get_mini_default_client_fields() {

		return array(
			'client.name'  => array(
				'title'    => esc_html__( 'Client Name (Required)', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the Client name', 'mainwp' ),
				'db_field' => 'name',
			),
			'client.email' => array(
				'title'    => esc_html__( 'Client email', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client email', 'mainwp' ),
				'db_field' => 'client_email',
			),
			'client.phone' => array(
				'title'    => esc_html__( 'Client phone', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client phone', 'mainwp' ),
				'db_field' => 'client_phone',
			),
		);
	}


	/**
	 * Get default contact fields.
	 *
	 * Get default contact fields.
	 */
	public static function get_default_contact_fields() {
		return array(
			'client.contact.name' => array(
				'title'    => esc_html__( 'Contact name (Required)', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client contact name', 'mainwp' ),
				'db_field' => 'contact_name',
			),
			'contact.role'        => array(
				'title'    => esc_html__( 'Contact role', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact role', 'mainwp' ),
				'db_field' => 'contact_role',
			),
			'contact.email'       => array(
				'title'    => esc_html__( 'Contact email (Required)', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact email', 'mainwp' ),
				'db_field' => 'contact_email',
			),
			'contact.phone'       => array(
				'title'    => esc_html__( 'Contact phone', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact phone', 'mainwp' ),
				'db_field' => 'contact_phone',
			),
			'contact.facebook'    => array(
				'title'    => esc_html__( 'Contact Facebook', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact Facebook', 'mainwp' ),
				'db_field' => 'facebook',
			),
			'contact.twitter'     => array(
				'title'    => esc_html__( 'Contact Twitter', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact Twitter', 'mainwp' ),
				'db_field' => 'twitter',
			),
			'contact.instagram'   => array(
				'title'    => esc_html__( 'Contact Instagram', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact Instagram', 'mainwp' ),
				'db_field' => 'instagram',
			),
			'contact.linkedin'    => array(
				'title'    => esc_html__( 'Contact LinkedIn', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact LinkedIn', 'mainwp' ),
				'db_field' => 'linkedin',
			),
		);
	}

	/**
	 * Get default contact fields.
	 *
	 * Get default contact fields.
	 */
	public static function get_mini_default_contact_fields() {
		return array(
			'client.contact.name' => array(
				'title'    => esc_html__( 'Contact name (Required)', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the client contact name', 'mainwp' ),
				'db_field' => 'contact_name',
			),
			'contact.role'        => array(
				'title'    => esc_html__( 'Contact role', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact role', 'mainwp' ),
				'db_field' => 'contact_role',
			),
			'contact.email'       => array(
				'title'    => esc_html__( 'Contact email (Required)', 'mainwp' ),
				'desc'     => esc_html__( 'Displays the contact email', 'mainwp' ),
				'db_field' => 'contact_email',
			),
		);
	}


	/**
	 * Method get_compatible_tokens().
	 *
	 * Get compatible tokens.
	 */
	public static function get_compatible_tokens() {
		return array(
			'client.contact.name' => 'client.contact.name',
			'client.email'        => 'contact.email',
			'client.phone'        => 'contact.phone',
		);
	}
	/**
	 * Method rest_api_add_client().
	 *
	 * Rest API add client.
	 *
	 * @param array $data fields array.
	 * @param bool  $edit Is edit.
	 *
	 *  $data fields.
	 *  'client_email'.
	 *  'name'.
	 *  'client_facebook'.
	 *  'client_twitter'.
	 *  'client_instagram'.
	 *  'client_linkedin'.
	 *  'address_1'.
	 *  'address_2'.
	 *  'city'.
	 *  'zip'.
	 *  'state'.
	 *  'note'.
	 *  'selected_sites'.
	 *  'client_id'. - to edit client.
	 *
	 * @throws \Exception Error message.
	 * @return mixed Results.
	 */
	public static function rest_api_add_client( $data, $edit = false ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.  

		$params = array();

		if ( $edit ) {

			$client_id = isset( $data['client_id'] ) ? intval( $data['client_id'] ) : 0;

			if ( empty( $client_id ) ) { // edit client.
				return array(
					'error' => esc_html__( 'Client ID field is required! Please enter a Client ID.', 'mainwp' ),
				);
			}

			$params['client_id'] = $client_id;

			if ( isset( $data['name'] ) ) {
				$params['name'] = sanitize_text_field( wp_unslash( $data['name'] ) );
			}

			if ( isset( $data['address_1'] ) ) {
				$params['address_1'] = sanitize_text_field( wp_unslash( $data['address_1'] ) );
			}
			if ( isset( $data['address_2'] ) ) {
				$params['address_2'] = sanitize_text_field( wp_unslash( $data['address_2'] ) );
			}
			if ( isset( $data['city'] ) ) {
				$params['city'] = sanitize_text_field( wp_unslash( $data['city'] ) );
			}
			if ( isset( $data['zip'] ) ) {
				$params['zip'] = sanitize_text_field( wp_unslash( $data['zip'] ) );
			}
			if ( isset( $data['state'] ) ) {
				$params['state'] = sanitize_text_field( wp_unslash( $data['state'] ) );
			}

			if ( isset( $data['country'] ) ) {
				$params['country'] = sanitize_text_field( wp_unslash( $data['country'] ) );
			}

			if ( isset( $data['note'] ) ) {
				$params['note'] = sanitize_text_field( wp_unslash( $data['note'] ) );
			}

			if ( isset( $data['client_email'] ) ) {
				$params['client_email'] = sanitize_text_field( wp_unslash( $data['client_email'] ) );
			}

			if ( isset( $data['client_phone'] ) ) {
				$params['client_phone'] = sanitize_text_field( wp_unslash( $data['client_phone'] ) );
			}

			if ( isset( $data['client_facebook'] ) ) {
				$params['client_facebook'] = sanitize_text_field( wp_unslash( $data['client_facebook'] ) );
			}

			if ( isset( $data['client_twitter'] ) ) {
				$params['client_twitter'] = sanitize_text_field( wp_unslash( $data['client_twitter'] ) );
			}

			if ( isset( $data['client_instagram'] ) ) {
				$params['client_instagram'] = sanitize_text_field( wp_unslash( $data['client_instagram'] ) );
			}

			if ( isset( $data['client_linkedin'] ) ) {
				$params['client_linkedin'] = sanitize_text_field( wp_unslash( $data['client_linkedin'] ) );
			}

			if ( isset( $data['created'] ) && is_numeric( $data['created'] ) ) {
				$params['created'] = intval( $data['created'] );
			}

			try {
				MainWP_DB_Client::instance()->update_client( $params, true );
			} catch ( \Exception $e ) {
				throw $e;
			}

			if ( isset( $data['selected_sites'] ) && is_array( $data['selected_sites'] ) ) {
				$selected_sites = array_map( 'sanitize_text_field', wp_unslash( $data['selected_sites'] ) );
				MainWP_DB_Client::instance()->update_selected_sites_for_client( $client_id, $selected_sites );
			}

			if ( isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ) {
				$custom_fields = wp_unslash( $data['custom_fields'] );
				foreach ( $custom_fields as $field ) {
					$fie_name  = isset( $field['field_name'] ) ? sanitize_text_field( wp_unslash( $field['field_name'] ) ) : '';
					$fie_value = isset( $field['field_value'] ) ? sanitize_text_field( wp_unslash( $field['field_value'] ) ) : '';
					$fie_desc  = isset( $field['field_desc'] ) ? sanitize_text_field( wp_unslash( $field['field_desc'] ) ) : '';
					if ( ! empty( $fie_name ) && ! empty( $fie_value ) ) {
						$get_gen_field = MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $fie_name, 0 );
						if ( $get_gen_field ) { // it is general field.
							if ( ! empty( $fie_desc ) ) {
								MainWP_DB_Client::instance()->update_client_field(
									$get_gen_field->field_id,
									array(
										'field_desc' => $fie_desc,
									)
								);
							}
							MainWP_DB_Client::instance()->update_client_field_value( $get_gen_field->field_id, $fie_value, $client_id ); // add or update general field value for the client.
						} else {
							$indi_gen_field = MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $fie_name, $client_id );
							if ( $indi_gen_field ) { // it is individual field.

								if ( ! empty( $fie_desc ) ) {
									MainWP_DB_Client::instance()->update_client_field(
										$indi_gen_field->field_id,
										array(
											'field_desc' => $fie_desc,
											'client_id'  => $client_id,
										)
									);
								}
								MainWP_DB_Client::instance()->update_client_field_value( $indi_gen_field->field_id, $fie_value, $client_id ); // add or update individual field value for the client.
							}
						}
					}
				}
			}

			return array(
				'success'  => true,
				'clientid' => $client_id,
			);

		} else {

			$params['name'] = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';

			if ( empty( $params['name'] ) ) {
				return array(
					'error' => esc_html__( 'Client name field is required! Please enter a Client name.', 'mainwp' ),
				);
			}

			$params['address_1']        = isset( $data['address_1'] ) ? sanitize_text_field( wp_unslash( $data['address_1'] ) ) : '';
			$params['address_2']        = isset( $data['address_2'] ) ? sanitize_text_field( wp_unslash( $data['address_2'] ) ) : '';
			$params['city']             = isset( $data['city'] ) ? sanitize_text_field( wp_unslash( $data['city'] ) ) : '';
			$params['zip']              = isset( $data['zip'] ) ? sanitize_text_field( wp_unslash( $data['zip'] ) ) : '';
			$params['state']            = isset( $data['state'] ) ? sanitize_text_field( wp_unslash( $data['state'] ) ) : '';
			$params['country']          = isset( $data['country'] ) ? sanitize_text_field( wp_unslash( $data['country'] ) ) : '';
			$params['note']             = isset( $data['note'] ) ? sanitize_text_field( wp_unslash( $data['note'] ) ) : '';
			$params['client_email']     = isset( $data['client_email'] ) ? sanitize_text_field( wp_unslash( $data['client_email'] ) ) : '';
			$params['client_phone']     = isset( $data['client_phone'] ) ? sanitize_text_field( wp_unslash( $data['client_phone'] ) ) : '';
			$params['client_facebook']  = isset( $data['client_facebook'] ) ? sanitize_text_field( wp_unslash( $data['client_facebook'] ) ) : '';
			$params['client_twitter']   = isset( $data['client_twitter'] ) ? sanitize_text_field( wp_unslash( $data['client_twitter'] ) ) : '';
			$params['client_instagram'] = isset( $data['client_instagram'] ) ? sanitize_text_field( wp_unslash( $data['client_instagram'] ) ) : '';
			$params['client_linkedin']  = isset( $data['client_linkedin'] ) ? sanitize_text_field( wp_unslash( $data['client_linkedin'] ) ) : '';

			$inserted = MainWP_DB_Client::instance()->update_client( $params );

			if ( empty( $inserted ) ) {
				return array(
					'error' => esc_html__( 'Undefined error. Please try again.', 'mainwp' ),
				);
			}

			$selected_sites = ( isset( $data['selected_sites'] ) && is_array( $data['selected_sites'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $data['selected_sites'] ) ) : array();

			MainWP_DB_Client::instance()->update_selected_sites_for_client( $inserted->client_id, $selected_sites );

			return array(
				'success'  => true,
				'clientid' => $inserted->client_id,
			);
		}
	}


	/**
	 * Method get_website_client_tokens_data()
	 *
	 * Get website client tokens.
	 *
	 * @param int $websiteid Website ID.
	 *
	 * @return mixed $result Result of tokens.
	 */
	public function get_website_client_tokens_data( $websiteid = false ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated. 
		if ( empty( $websiteid ) ) {
			return false;
		}
		$website = MainWP_DB::instance()->get_website_by_id( $websiteid );
		if ( empty( $website ) ) {
			return false;
		}

		$client_tokens = array();

		$client_tokens['client.site.url']  = $website->url;
		$client_tokens['client.site.name'] = $website->name;

		// init values.
		$default_client_fields = self::get_default_client_fields();
		foreach ( $default_client_fields as $tok_name => $field ) {
			$client_tokens[ $tok_name ] = '';  // the tokens with empty value will be removed from the report.
		}
		$contact_fields = self::get_default_contact_fields();
		if ( ! empty( $contact_fields ) && is_array( $contact_fields ) ) {
			foreach ( $contact_fields as $tok_name => $field ) {
				$client_tokens[ $tok_name ] = ''; // the tokens with empty value will be removed from the report.
			}
		}

		$custom_tokens_fields = MainWP_DB_Client::instance()->get_client_fields( true, $website->client_id );
		if ( ! empty( $custom_tokens_fields ) && is_array( $custom_tokens_fields ) ) {
			foreach ( $custom_tokens_fields as $fields ) {
				$client_tokens[ $fields->field_name ] = ''; // the tokens with empty value will be removed from the report.
			}
		}

		if ( empty( $website->client_id ) ) {
			return $client_tokens;
		}

		$clientid = $website->client_id;

		$client_info = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $clientid );

		if ( empty( $client_info ) ) {
			return $client_tokens;
		}

		if ( ! empty( $custom_tokens_fields ) && is_array( $custom_tokens_fields ) ) {
			foreach ( $custom_tokens_fields as $fields ) {
				$client_tokens[ $fields->field_name ] = $fields->field_value;
			}
		}

		foreach ( $default_client_fields as $tok_name => $field ) {
			$db_field = $field['db_field'];
			if ( property_exists( $client_info, $db_field ) ) {
				$client_tokens[ $tok_name ] = $client_info->{$db_field};
			}
		}

		if ( ! empty( $contact_fields ) && is_array( $contact_fields ) ) {
			foreach ( $contact_fields as $tok_name => $field ) {
				$db_field = isset( $field['db_field'] ) ? $field['db_field'] : '';
				$val      = '' !== $db_field && property_exists( $client_info, $db_field ) ? $client_info->{$db_field} : false;
				if ( false !== $val ) {
					$client_tokens[ $tok_name ] = $val;
				}
			}
		}
		$client_tokens = apply_filters( 'mainwp_clients_website_client_tokens', $client_tokens, $websiteid, $clientid );
		return $client_tokens;
	}

	/**
	 * Method get_favico_url()
	 *
	 * Get Child Site favicon URL.
	 *
	 * @param string $image_path Client image url path.
	 *
	 * @return mixed $full_url Full image URL.
	 */
	public static function get_client_image_url( $image_path ) {
		$full_url = '';
		if ( ! empty( $image_path ) ) {
			if ( false !== stripos( $image_path, 'assets/images/demo/clients/' ) || false !== stripos( $image_path, 'assets/images/demo/contacts/' ) ) {
				$full_url = MAINWP_PLUGIN_URL . $image_path;
			} else {
				$dirs = MainWP_System_Utility::get_mainwp_dir( 'client-images', true );
				if ( file_exists( $dirs[0] . $image_path ) ) {
					$full_url = $dirs[1] . $image_path;
				} else {
					$full_url = '';
				}
			}
		}
		return $full_url;
	}

	/**
	 * Method show_notice_existed_contact_emails()
	 */
	public static function show_notice_existed_contact_emails() {
		$existed_emails = MainWP_Utility::get_flash_message( 'contact_existed_emails' );
		if ( ! empty( $existed_emails ) ) {
			$existed_emails = esc_html( $existed_emails );
			$existed_emails = str_replace( '|', '<br/>', $existed_emails );
			?>
			<div class="ui yellow message">
				<?php printf( esc_html__( 'Existed contact emails.%sPlease try again.', 'mainwp' ), '<br/>' . $existed_emails . '</br>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php
		}
	}
}
