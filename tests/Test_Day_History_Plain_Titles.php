<?php
/**
 * History modal titles are plain text.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Module\Log\Log_Changes_Logs_Helper;
use MainWP\Dashboard\Module\Log\Log_DB;
use MainWP\Dashboard\Module\Log\Log_DB_Driver_WPDB;

/**
 * The History modal escapes titles itself, so markup and entities from the list-table title must not reach it.
 */
class Test_Day_History_Plain_Titles extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * A rolled-back update and an ampersand name show as typed in Day History and the item History title.
	 */
	public function test_history_titles_are_plain_text(): void {
		$site_id = $this->create_test_site( array( 'name' => 'History Site' ) );
		$db      = new Log_DB( new Log_DB_Driver_WPDB() );
		$now     = time();

		$this->assertNotFalse(
			$db->insert(
				array(
					'site_id'    => $site_id,
					'user_id'    => 1,
					'user_login' => 'admin',
					'created'    => $now,
					'item'       => 'A &amp; B Plugin',
					'connector'  => 'installer',
					'context'    => 'plugin',
					'action'     => 'updated',
					'state'      => 1,
					'meta'       => array(
						'slug'       => 'a-b/a-b.php',
						'extra_info' => wp_json_encode(
							array(
								'slug'          => 'a-b/a-b.php',
								'name'          => 'A &amp; B',
								'rollback_info' => array(
									'name'        => 'A & B',
									'version'     => '2.0',
									'old_version' => '1.0',
								),
							)
						),
					),
				)
			)
		);
		$this->assertNotFalse(
			$db->insert(
				array(
					'site_id'     => $site_id,
					'user_id'     => 1,
					'user_login'  => 'admin',
					'created'     => $now + 1,
					'item'        => 'C & D',
					'connector'   => 'non-mainwp-changes',
					'context'     => 'plugin',
					'action'      => 'updated',
					'state'       => 1,
					'log_type_id' => 1970,
					'meta'        => array(
						'slug' => 'c-d/c-d.php',
						'name' => 'C & D',
					),
				)
			)
		);

		$day   = Log_Changes_Logs_Helper::instance()->get_history_changes(
			array(
				'type'        => 'plugin',
				'target_date' => wp_date( 'Y-m-d', $now ),
				'wpid'        => $site_id,
			)
		);
		$names = array();
		foreach ( $day['list'] as $records ) {
			foreach ( $records as $record ) {
				$names[] = $record['name'];
			}
		}
		sort( $names );
		$this->assertCount( 2, $names );
		$this->assertStringStartsWith( 'A & B', $names[0] );
		$this->assertStringStartsWith( 'C & D', $names[1] );
		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( '<', $name );
			$this->assertStringNotContainsString( '&amp;', $name );
		}

		$item = Log_Changes_Logs_Helper::instance()->get_history_changes(
			array(
				'type'        => 'plugin',
				'wpid'        => $site_id,
				'slug'        => 'a-b',
				'days_number' => 10,
				'from_date'   => wp_date( 'Y-m-d', $now ),
			)
		);
		$this->assertArrayHasKey( 'name_title', $item );
		$this->assertStringStartsWith( 'A & B', $item['name_title'] );
		$this->assertStringNotContainsString( '<', $item['name_title'] );
		$this->assertStringNotContainsString( '&amp;', $item['name_title'] );
	}
}
