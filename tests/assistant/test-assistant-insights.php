<?php
/**
 * Tests for MainWP Assistant Insights.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Insights;
use MainWP\Dashboard\MainWP_Assistant_Insights_Helper;

/**
 * Class Test_Assistant_Insights
 *
 * Tests for the Assistant Insights page and helper methods.
 */
class Test_Assistant_Insights extends MainWP_Assistant_Test_Case {

	/**
	 * Test get_summary_metrics returns expected keys and no estimated_cost.
	 *
	 * Sets the wpdb logs table property so the helper can check for the table
	 * without error. The table won't exist, so the early-return path fires.
	 */
	public function test_get_summary_metrics_returns_expected_keys() {
		global $wpdb;

		// Register the logs table property so logs_table_exists() doesn't error.
		// The table doesn't actually exist, so the method returns the empty-metrics array.
		if ( ! isset( $wpdb->mainwp_tbl_logs ) ) {
			$wpdb->mainwp_tbl_logs = $wpdb->prefix . 'mainwp_tbl_logs';
		}

		// Reset the static cache inside logs_table_exists() so our property takes effect.
		$helper    = MainWP_Assistant_Insights_Helper::instance();
		$ref_class = new \ReflectionClass( $helper );
		$method    = $ref_class->getMethod( 'logs_table_exists' );
		$method->setAccessible( true );

		// Invoke to populate the static — it will find the table doesn't exist.
		// The static variable is function-scoped; we can't reset it, but since
		// the table doesn't exist in the test DB, it'll return false.
		$metrics = $helper->get_summary_metrics( get_current_user_id(), strtotime( '-30 days' ), time() );

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'total_executions', $metrics );
		$this->assertArrayHasKey( 'failed_count', $metrics );
		$this->assertArrayHasKey( 'confirmed_count', $metrics );
		$this->assertArrayHasKey( 'conversation_count', $metrics );
		$this->assertArrayNotHasKey( 'estimated_cost', $metrics, 'estimated_cost placeholder should be removed from insights metrics' );
	}

	/**
	 * Test get_summary_metrics with empty user_id array returns consistent schema.
	 *
	 * The empty-array early return must match the same four keys as the normal
	 * path and must not include the deprecated estimated_cost field.
	 */
	public function test_get_summary_metrics_empty_user_array_returns_expected_keys() {
		global $wpdb;

		if ( ! isset( $wpdb->mainwp_tbl_logs ) ) {
			$wpdb->mainwp_tbl_logs = $wpdb->prefix . 'mainwp_tbl_logs';
		}

		$helper  = MainWP_Assistant_Insights_Helper::instance();
		$metrics = $helper->get_summary_metrics( array(), strtotime( '-30 days' ), time() );

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'total_executions', $metrics );
		$this->assertArrayHasKey( 'failed_count', $metrics );
		$this->assertArrayHasKey( 'confirmed_count', $metrics );
		$this->assertArrayHasKey( 'conversation_count', $metrics );
		$this->assertArrayNotHasKey( 'estimated_cost', $metrics, 'estimated_cost should not appear in any get_summary_metrics return path' );
	}

	/**
	 * Test that "thisweek" date range does not span 14 days on a Monday.
	 *
	 * Before the fix, strtotime('last monday') on a Monday would return the
	 * previous Monday, creating a 14-day range. strtotime('monday this week')
	 * correctly returns the current Monday.
	 */
	public function test_thisweek_range_does_not_span_14_days() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_date_range' );
		$method->setAccessible( true );

		update_option( 'start_of_week', 1 );
		$_POST['range'] = 'thisweek';

		$result = $method->invoke( null );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		list( $from, $to ) = $result;

		// The range should never exceed 7 days.
		$span_days = ( $to - $from ) / DAY_IN_SECONDS;
		$this->assertLessThanOrEqual( 7, $span_days, 'thisweek range should not span more than 7 days' );

		// $from should be a Monday (day 1 in PHP date).
		$this->assertEquals( '1', gmdate( 'N', $from ), 'thisweek range should start on Monday' );

		delete_option( 'start_of_week' );
		unset( $_POST['range'] );
	}

	/**
	 * Test thisweek always starts on Monday even if WordPress uses Sunday as week start.
	 */
	public function test_thisweek_range_starts_on_monday_even_with_sunday_start_of_week() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_date_range' );
		$method->setAccessible( true );

		update_option( 'start_of_week', 0 );
		$_POST['range'] = 'thisweek';

		$result = $method->invoke( null );
		list( $from ) = $result;

		$this->assertSame( '1', gmdate( 'N', $from ), 'thisweek range should start on Monday even when start_of_week is 0.' );

		delete_option( 'start_of_week' );
		unset( $_POST['range'] );
	}

	/**
	 * Test search normalization accepts DataTables nested payloads.
	 */
	public function test_normalize_search_input_handles_datatables_payload() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'normalize_search_input' );
		$method->setAccessible( true );

		$search = $method->invoke( null, array( 'value' => '  ability name  ' ) );

		$this->assertSame( 'ability name', $search );
	}

	/**
	 * Test search normalization accepts a flat string payload.
	 */
	public function test_normalize_search_input_handles_flat_string_payload() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'normalize_search_input' );
		$method->setAccessible( true );

		$search = $method->invoke( null, '  provider  ' );

		$this->assertSame( 'provider', $search );
	}

	/**
	 * Test custom range with explicit Monday start date (shared filter bar path).
	 *
	 * When the shared InsightsOverview filter bar selects "This week", JS populates
	 * explicit date inputs and sends range=custom with date_from_str/date_to_str.
	 * The preset must use 'monday this week' (not 'last monday') so that on Mondays
	 * the start date is today, not the previous Monday.
	 */
	public function test_custom_range_with_monday_start_date_resolves_correctly() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_date_range' );
		$method->setAccessible( true );

		// Simulate what the shared filter bar sends: range=custom with explicit dates.
		// Use the same calculation as the (now fixed) preset: 'monday this week'.
		$monday_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
		$today        = gmdate( 'Y-m-d' );

		$_POST['range']         = 'custom';
		$_POST['date_from_str'] = $monday_start;
		$_POST['date_to_str']   = $today;

		$result = $method->invoke( null );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		list( $from, $to ) = $result;

		// The start should be a Monday.
		$this->assertEquals( '1', gmdate( 'N', $from ), 'Custom range start should be Monday' );

		// Range should not exceed 7 days.
		$span_days = ( $to - $from ) / DAY_IN_SECONDS;
		$this->assertLessThanOrEqual( 7, $span_days, 'Custom range from shared filter bar should not span more than 7 days for thisweek' );

		unset( $_POST['range'], $_POST['date_from_str'], $_POST['date_to_str'] );
	}

	/**
	 * Test lastyear range includes the full final day.
	 */
	public function test_lastyear_range_includes_full_final_day() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_date_range' );
		$method->setAccessible( true );

		$_POST['range'] = 'lastyear';

		$result = $method->invoke( null );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		list( $from, $to ) = $result;

		$this->assertSame( '01-01 00:00:00', gmdate( 'm-d H:i:s', $from ) );
		$this->assertSame( '12-31 23:59:59', gmdate( 'm-d H:i:s', $to ) );

		unset( $_POST['range'] );
	}

	/**
	 * Test resolve_user_filter returns current user when input is empty.
	 */
	public function test_resolve_user_filter_returns_current_user_for_empty_input() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_user_filter' );
		$method->setAccessible( true );

		unset( $_POST['user_ids'], $_POST['all_users'] );

		$result = $method->invoke( null );

		$this->assertEquals( get_current_user_id(), $result );
	}

	/**
	 * Test resolve_user_filter handles single ID after array_filter correctly.
	 *
	 * array_filter preserves keys, so after filtering [0, 5] becomes [1 => 5].
	 * Using $ids[0] would fail; reset($ids) gets the first value safely.
	 */
	public function test_resolve_user_filter_single_id_after_filter() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_user_filter' );
		$method->setAccessible( true );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Simulate a single valid user ID with a leading zero that will be filtered out.
		$_POST['user_ids'] = '0,' . $admin_user;

		$result = $method->invoke( null );

		// Should return the single ID as an integer, not an array.
		$this->assertIsInt( $result );
		$this->assertEquals( $admin_user, $result );

		unset( $_POST['user_ids'] );
	}

	/**
	 * Test resolve_user_filter returns multiple IDs as array.
	 */
	public function test_resolve_user_filter_multiple_ids() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_user_filter' );
		$method->setAccessible( true );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$other_user        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$_POST['user_ids'] = $admin_user . ',' . $other_user;

		$result = $method->invoke( null );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		unset( $_POST['user_ids'] );
	}

	/**
	 * Test resolve_user_filter returns null for allusers when user has capability.
	 *
	 * In the test environment, mainwp_current_user_can() returns true for all users
	 * (the full rights system isn't loaded). This verifies the allusers code path
	 * returns null (meaning "all users") when the capability check passes.
	 */
	public function test_resolve_user_filter_allusers_returns_null() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'resolve_user_filter' );
		$method->setAccessible( true );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$_POST['user_ids'] = 'allusers';

		$result = $method->invoke( null );

		// With capability (always true in test env), allusers should return null.
		$this->assertNull( $result, 'allusers with capability should return null' );

		unset( $_POST['user_ids'] );
	}

	/**
	 * Test admin read detection treats the allusers sentinel as an admin read.
	 */
	public function test_is_admin_read_request_treats_null_as_admin_read() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'is_admin_read_request' );
		$method->setAccessible( true );

		$current_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $current_user );

		$this->assertTrue( $method->invoke( null, null ) );
		$this->assertTrue( $method->invoke( null, array( 1, 2 ) ) );
		$this->assertFalse( $method->invoke( null, $current_user ) );
		$this->assertTrue( $method->invoke( null, $current_user + 1 ) );
	}

	/**
	 * Test cross-user conversation visibility requires the privileged admin capability.
	 */
	public function test_can_view_activity_conversation_details_requires_privileged_capability_for_other_users() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'can_view_activity_conversation_details' );
		$method->setAccessible( true );

		$admin_user = $this->set_current_user_as_admin();

		$this->assertTrue( $method->invoke( null, $admin_user ) );
		$this->assertTrue( $method->invoke( null, $admin_user + 1 ) );

		$subscriber_user = $this->set_current_user_as_subscriber();

		$this->assertTrue( $method->invoke( null, $subscriber_user ) );
		$this->assertFalse( $method->invoke( null, $admin_user ) );
	}

	/**
	 * Test CSV sanitization checks the first non-whitespace character.
	 */
	public function test_sanitize_csv_field_prefixes_formula_with_leading_space() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'sanitize_csv_field' );
		$method->setAccessible( true );

		$value = "  =HYPERLINK(\"https://example.com\")";

		$this->assertSame( "'" . $value, $method->invoke( null, $value ) );
	}

	/**
	 * Test CSV sanitization strips a UTF-8 BOM before checking formula triggers.
	 */
	public function test_sanitize_csv_field_prefixes_formula_after_bom() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'sanitize_csv_field' );
		$method->setAccessible( true );

		$value = "\xEF\xBB\xBF\t=1+1";

		$this->assertSame( "'" . $value, $method->invoke( null, $value ) );
	}

	/**
	 * Test format_ability_name produces expected output.
	 */
	public function test_format_ability_name() {
		$helper = MainWP_Assistant_Insights_Helper::instance();

		$this->assertEquals( 'List Sites', $helper->format_ability_name( 'mainwp/list-sites-v1' ) );
		$this->assertEquals( 'Get Site Updates', $helper->format_ability_name( 'mainwp/get-site-updates-v2' ) );
		$this->assertEquals( 'Unknown', $helper->format_ability_name( '' ) );
	}

	/**
	 * Content endpoints require both an enabled assistant and an active consent grant.
	 *
	 * Revoking consent keeps the logged history intact and the assistant can be
	 * re-enabled without re-granting consent, so the conversation-read and CSV-export
	 * handlers must fail closed while consent is absent.
	 */
	public function test_insights_content_access_allowed_requires_consent() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'insights_content_access_allowed' );
		$method->setAccessible( true );

		$this->set_assistant_enabled( true );

		$this->set_assistant_consent( true );
		$this->assertTrue( $method->invoke( null ), 'Enabled assistant with consent should allow content access.' );

		$this->set_assistant_consent( false );
		$this->assertFalse( $method->invoke( null ), 'Enabled assistant without consent must fail closed.' );

		$this->set_assistant_enabled( false );
		$this->set_assistant_consent( true );
		$this->assertFalse( $method->invoke( null ), 'Disabled assistant must fail closed even with consent.' );
	}

	/**
	 * The activity table's title fallback is content, not metadata: a
	 * conversation-start row's title is the first prompt characters, so it
	 * must only surface while content access (enabled + consent) holds.
	 */
	public function test_activity_ability_label_gates_title_fallback_on_content_access() {
		$reflection = new \ReflectionClass( MainWP_Assistant_Insights::class );
		$method     = $reflection->getMethod( 'activity_ability_label' );
		$method->setAccessible( true );

		$started_item = (object) array(
			'action' => 'started',
			'meta'   => array( 'title' => 'Secret prompt words' ),
		);

		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->assertSame( 'Secret prompt words', $method->invoke( null, $started_item ), 'With content access the logged title is shown.' );

		$this->set_assistant_consent( false );
		$this->assertSame( 'Conversation', $method->invoke( null, $started_item ), 'Without content access the prompt-derived title must not surface.' );

		// Ability rows resolve through format_ability_name() regardless of the gate.
		$ability_item = (object) array(
			'action' => 'confirmed',
			'meta'   => array( 'ability' => 'mainwp/list-sites-v1' ),
		);
		$this->assertSame(
			MainWP_Assistant_Insights_Helper::instance()->format_ability_name( 'mainwp/list-sites-v1' ),
			$method->invoke( null, $ability_item )
		);
	}

	/**
	 * Without content access the activity search must not match the logged
	 * message or the conversation title, or the filtered count would reveal
	 * prompt text the table hides. User and ability matches stay searchable.
	 */
	public function test_activity_search_skips_content_without_content_access() {
		global $wpdb;
		$had_meta = isset( $wpdb->mainwp_tbl_logs_meta );
		if ( ! $had_meta ) {
			$wpdb->mainwp_tbl_logs_meta = $wpdb->prefix . 'mainwp_tbl_logs_meta';
		}

		$method = new \ReflectionMethod( MainWP_Assistant_Insights_Helper::class, 'build_search_where_clause' );
		$method->setAccessible( true );
		$gated   = $method->invoke( MainWP_Assistant_Insights_Helper::instance(), 'secret', false );
		$content = $method->invoke( MainWP_Assistant_Insights_Helper::instance(), 'secret', true );

		if ( ! $had_meta ) {
			unset( $wpdb->mainwp_tbl_logs_meta );
		}

		$this->assertStringNotContainsString( 'lg.item', $gated['clause'] );
		$this->assertStringNotContainsString( "'title'", $gated['clause'] );
		$this->assertStringContainsString( 'u.display_name LIKE %s', $gated['clause'] );
		$this->assertStringContainsString( "meta_key IN ('ability')", $gated['clause'] );
		$this->assertCount( substr_count( $gated['clause'], '%s' ), $gated['args'] );

		$this->assertStringContainsString( 'lg.item LIKE %s', $content['clause'] );
		$this->assertStringContainsString( "'title'", $content['clause'] );
		$this->assertCount( substr_count( $content['clause'], '%s' ), $content['args'] );
		$this->assertSame( '%secret%', $content['args'][0] );
	}

	/**
	 * M2 regression: the activity endpoint must never leak raw message content.
	 *
	 * ajax_get_activity_data() is a metadata endpoint, not gated by the consent
	 * check its content-bearing siblings (conversation reads, CSV export)
	 * enforce, so it must never surface the message body a row carries.
	 *
	 * A live AJAX round trip isn't reachable in this harness: verify_insights_ajax_request()
	 * (the endpoint's first line) calls is_logs_module_available(), and
	 * tests/bootstrap.php hard-defines MAINWP_MODULE_LOG_ENABLED to false — a
	 * deliberate choice other suites rely on (see test-assistant-tool-prioritization.php).
	 * The constant can't be redefined mid-process, and the underlying
	 * mainwp_tbl_logs table isn't created in the phpunit schema either, so
	 * even bypassing the module gate would hit an empty, vacuous dataset.
	 * Swapping MainWP_Assistant_Insights_Helper::instance() for a stub (as
	 * other export tests in this suite do) still can't reach the row builder,
	 * since the module check runs before query_activity() is ever called.
	 *
	 * Instead, this asserts on the method's own source: it must never read
	 * $item->message, the exact leak vector M2 fixed (a future legitimate
	 * 'message' key — e.g. an error field — shouldn't false-fail this test,
	 * so the check targets the property read, not the broader string
	 * `'message'`). This fails the moment someone reintroduces
	 * `'message' => $item->message,` — verified by temporarily reintroducing
	 * that line and confirming this assertion catches it — while passing on
	 * any other edit to the method.
	 */
	public function test_activity_endpoint_row_builder_omits_message_key() {
		$reflection = new \ReflectionMethod( MainWP_Assistant_Insights::class, 'ajax_get_activity_data' );
		$file_lines = file( $reflection->getFileName() );

		$start = $reflection->getStartLine() - 1;
		$end   = $reflection->getEndLine();
		$body  = implode( '', array_slice( $file_lines, $start, $end - $start ) );

		$this->assertStringNotContainsString(
			'$item->message',
			$body,
			'ajax_get_activity_data() must never read $item->message into its per-row output; that leaks raw logged message content on an endpoint with no consent gate.'
		);

		// Sanity check the slice actually captured the row-building block, so
		// a future refactor that moves this code out of the method can't turn
		// the assertion above into a silent no-op.
		$this->assertStringContainsString( "'ability_label'", $body, 'Sanity check: the reflected method body should contain the known row-building block.' );
	}
}
