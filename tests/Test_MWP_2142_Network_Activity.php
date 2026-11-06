<?php
/**
 * Network Activity object and action labels.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Module\Log\Log_Changes_Logs_Helper;
use MainWP\Dashboard\Module\Log\Log_Events_List_Table;

class Test_MWP_2142_Network_Activity extends \WP_UnitTestCase {

	public function test_object_titles_keep_one_type_label() {
		$table = ( new \ReflectionClass( Log_Events_List_Table::class ) )->newInstanceWithoutConstructor();
		foreach ( array( array( 'posts', 'post', 'Post: Hello' ), array( 'posts', 'page', 'Page: About' ), array( 'posts', 'book', 'Book: Example' ), array( 'users', 'users', 'User: editor' ) ) as $case ) {
			$record = (object) array( 'connector' => $case[0], 'context' => $case[1], 'item' => $case[2], 'log_type_id' => 0 );
			$this->assertSame( $case[2], $table->get_object_title( $record, false ) );
		}
		$this->assertSame( 'Example Plugin', $table->get_context_title( 'Example', 'plugin', 'installer' ) );
	}

	public function test_synced_install_action_and_event_agree() {
		$table  = ( new \ReflectionClass( Log_Events_List_Table::class ) )->newInstanceWithoutConstructor();
		$helper = Log_Changes_Logs_Helper::instance();
		$record = (object) array( 'action' => $helper->map_changes_action( 'installed' ), 'log_type_id' => 1000 );
		$this->assertSame( 'installed', $record->action );
		$this->assertSame( 'Installed', $table->get_action_title( $record ) );
		$this->assertStringContainsString( '>Installed<', $table->get_event_title( $record, 'action', true ) );
		$this->assertSame( 'activate', $helper->map_changes_action( 'activated' ) );
	}
}
