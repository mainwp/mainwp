<?php
/**
 * Regression tests for wp_options name injection into SQL identifier position.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_DB_Option_Name_Injection_Test
 *
 * get_wp_options_join() and get_wp_options_view() build their wp_options "views" by
 * splicing option names into the query as column and table aliases. escape() is
 * esc_sql(), which leaves backticks, commas and parentheses untouched, so a name
 * carrying its own alias terminator used to break out of `AS \`...\`` and add an
 * arbitrary SELECT expression to the projection. REST v2 ?custom_fields reached
 * those two builders through extra_view, and prepare_item_for_response() copies any
 * row property whose name matches a requested custom field into the JSON, so the
 * injected column could be read back.
 *
 * These tests pin both halves of the fix: those two builders drop names outside
 * [A-Za-z0-9_], and the v2 sites controller screens ?custom_fields before it ever
 * builds a query.
 *
 * Reuses MainWP_Abilities_Test_Case only for its generic MainWP-site helpers
 * (create_test_site / set_site_option / set_current_user_as_admin), which the
 * global test bootstrap loads for every suite.
 */
class MainWP_DB_Option_Name_Injection_Test extends MainWP_Abilities_Test_Case {

	/**
	 * An option name that closes the alias backtick and appends its own SELECT.
	 *
	 * @var string
	 */
	const PAYLOAD = 'a`, (SELECT user_pass FROM wp_users LIMIT 1) AS `leak';

	/**
	 * A legitimate option name paired with the payload in every sink call, so a
	 * passing test proves the filter is selective rather than dropping everything.
	 *
	 * @var string
	 */
	const LEGIT = 'favi_icon';

	/**
	 * Load the v2 REST controller classes, which are only included during REST
	 * setup rather than by the test bootstrap.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( '\MainWP_Rest_Sites_Controller' ) ) {
			include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-controller.php';
			include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-sites-controller.php';
		}
	}

	/**
	 * Assert generated SQL kept the legitimate name and carries no trace of the payload.
	 *
	 * @param string $sql     Generated SQL fragment.
	 * @param string $context Which sink produced it, for failure messages.
	 * @return void
	 */
	private function assertSqlRejectsPayload( $sql, $context ) {
		$this->assertIsString( $sql, $context . ' must return SQL.' );
		$this->assertStringContainsString( self::LEGIT, $sql, $context . ' dropped the legitimate option name.' );
		$this->assertStringNotContainsString( 'user_pass', $sql, $context . ' spliced the injected SELECT into the query.' );
		$this->assertStringNotContainsString( 'wp_users', $sql, $context . ' spliced the injected table into the query.' );
		$this->assertStringNotContainsString( 'leak', $sql, $context . ' kept the injected alias.' );
	}

	/**
	 * get_wp_options_join() puts the name in both the SELECT alias and the JOIN
	 * table alias, so both members of the return value need checking.
	 *
	 * @return void
	 */
	public function test_get_wp_options_join_rejects_injected_option_name() {
		$parts = \MainWP\Dashboard\MainWP_DB::instance()->get_wp_options_join(
			array( self::LEGIT, self::PAYLOAD ),
			'custom_view'
		);

		$this->assertIsArray( $parts );
		$this->assertSqlRejectsPayload( $parts['selects'], 'get_wp_options_join() selects' );
		$this->assertSqlRejectsPayload( $parts['joins'], 'get_wp_options_join() joins' );
	}

	/**
	 * get_wp_options_view() splices the name into a MAX(CASE ...) alias and into
	 * the name IN (...) list.
	 *
	 * @return void
	 */
	public function test_get_wp_options_view_rejects_injected_option_name() {
		$sql = \MainWP\Dashboard\MainWP_DB::instance()->get_wp_options_view(
			array( self::LEGIT, self::PAYLOAD ),
			'custom_view'
		);

		$this->assertSqlRejectsPayload( $sql, 'get_wp_options_view()' );
	}

	/**
	 * Backward-compat guard: real option names must still reach both guarded sinks. A
	 * filter that is too strict would silently drop columns the read paths depend on,
	 * which no injection assertion above would catch.
	 *
	 * @return void
	 */
	public function test_legitimate_option_names_survive_both_sinks() {
		$fields = array( 'favi_icon', 'site_info', 'health_site_status', 'recent_posts' );
		$db     = \MainWP\Dashboard\MainWP_DB::instance();

		$join = $db->get_wp_options_join( $fields, 'custom_view' );

		$fragments = array(
			'get_wp_options_view()'         => $db->get_wp_options_view( $fields, 'custom_view' ),
			'get_wp_options_join() selects' => $join['selects'],
			'get_wp_options_join() joins'   => $join['joins'],
		);

		foreach ( $fragments as $context => $sql ) {
			foreach ( $fields as $field ) {
				$this->assertStringContainsString( $field, $sql, $context . ' dropped the valid option name ' . $field . '.' );
			}
		}
	}

	/**
	 * Run the controller's protected ?custom_fields parser.
	 *
	 * @param mixed $value Raw custom_fields value, or null to omit the argument.
	 * @return array Sanitized names.
	 */
	private function parse_custom_fields( $value ) {
		$controller = new \MainWP_Rest_Sites_Controller();
		$request    = new \WP_REST_Request( 'GET', '/mainwp/v2/sites' );

		if ( null !== $value ) {
			$request->set_param( 'custom_fields', $value );
		}

		$method = new \ReflectionMethod( $controller, 'get_requested_custom_fields' );
		$method->setAccessible( true );

		return $method->invoke( $controller, $request );
	}

	/**
	 * Comma-separated valid names parse into a list, and duplicates collapse.
	 *
	 * @return void
	 */
	public function test_custom_fields_keeps_valid_names_and_dedupes() {
		$this->assertSame(
			array( 'favi_icon', 'site_info' ),
			$this->parse_custom_fields( 'favi_icon, site_info, favi_icon' )
		);
	}

	/**
	 * custom_fields[]=<payload> keeps the payload intact: wp_parse_list returns an
	 * array argument unsplit, so this is the form that reaches the DB sinks whole
	 * and the one the name filter actually has to stop.
	 *
	 * @return void
	 */
	public function test_custom_fields_drops_intact_injection_payload() {
		$this->assertSame(
			array( 'favi_icon' ),
			$this->parse_custom_fields( array( self::LEGIT, self::PAYLOAD ) )
		);
	}

	/**
	 * The same payload in the string form. wp_parse_list splits on whitespace as
	 * well as commas, so the payload shatters into bare words and only the pieces
	 * carrying a backtick or paren get dropped; the survivors are plain option
	 * names that match no stored row. What matters is the invariant: nothing that
	 * comes back can carry a character with meaning in identifier position.
	 *
	 * @return void
	 */
	public function test_custom_fields_string_payload_yields_only_safe_names() {
		$fields = $this->parse_custom_fields( self::LEGIT . ',' . self::PAYLOAD );

		$this->assertContains( self::LEGIT, $fields );
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$fields,
					function ( $name ) {
						return (bool) preg_match( '/[^A-Za-z0-9_]/', $name );
					}
				)
			),
			'Every accepted custom field must be safe to splice into identifier position.'
		);
	}

	/**
	 * health_site_status is reserved out of the response, and every read path adds
	 * it to the projection itself, so accepting it here would only duplicate work.
	 *
	 * @return void
	 */
	public function test_custom_fields_drops_reserved_health_site_status() {
		$this->assertSame( array(), $this->parse_custom_fields( 'health_site_status' ) );
	}

	/**
	 * An absent custom_fields argument yields no fields rather than a notice.
	 *
	 * @return void
	 */
	public function test_custom_fields_absent_yields_empty_list() {
		$this->assertSame( array(), $this->parse_custom_fields( null ) );
	}

	/**
	 * End to end: a list query carrying the payload in extra_view must run cleanly,
	 * must not project an injected column onto the row, and the prepared response
	 * must expose no key derived from it.
	 *
	 * @return void
	 */
	public function test_injected_custom_field_never_reaches_the_response() {
		global $wpdb;

		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		// full_data rows json_decode wp_upgrades; seed it so the query does not trip
		// an unrelated json_decode(null) deprecation on a fresh site.
		$this->set_site_option( $site_id, 'wp_upgrades', '' );

		$wpdb->last_error = '';

		$websites = \MainWP\Dashboard\MainWP_DB::instance()->get_websites_for_current_user(
			array(
				'full_data'  => true,
				'include'    => array( $site_id ),
				'extra_view' => array( self::LEGIT, self::PAYLOAD ),
				'fields'     => array( self::LEGIT, self::PAYLOAD ),
			)
		);

		$this->assertSame( '', $wpdb->last_error, 'The generated query must be valid SQL.' );
		$this->assertNotEmpty( $websites );

		$site = current( $websites );
		$this->assertFalse( property_exists( $site, 'leak' ), 'The injected alias must not become a row column.' );

		$controller = new \MainWP_Rest_Sites_Controller();
		$request    = new \WP_REST_Request( 'GET', '/mainwp/v2/sites' );
		$request->set_param( 'custom_fields', self::PAYLOAD );

		$data = $controller->prepare_item_for_response( $site, $request );

		$this->assertIsArray( $data );
		foreach ( array_keys( $data ) as $key ) {
			$this->assertStringNotContainsString( 'leak', $key, 'A payload-derived key reached the response.' );
			$this->assertStringNotContainsString( 'user_pass', $key, 'A payload-derived key reached the response.' );
		}
	}
}
