<?php
/**
 * Tests for assistant cron behavior.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Test class for assistant cron cleanup logic.
 */
class Test_Assistant_Cron extends MainWP_Assistant_Test_Case {

	/**
	 * Make a conversation old enough for cleanup by adjusting updated_at directly.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $updated_at      Unix timestamp to set.
	 *
	 * @return void
	 */
	private function age_conversation( int $conversation_id, int $updated_at ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_assistant_conversations',
			array( 'updated_at' => $updated_at ),
			array( 'id' => $conversation_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Test that only -1 disables cleanup.
	 */
	public function test_cron_assistant_cleanup_skips_only_negative_one() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Keep Forever' );

		$this->age_conversation( $conversation_id, time() - ( 90 * DAY_IN_SECONDS ) );
		update_user_meta( $user_id, 'mainwp_assistant_history_retention', -1 );

		MainWP_System_Cron_Jobs::instance()->cron_assistant_cleanup();

		$this->assertEquals(
			1,
			MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ),
			'Retention value -1 should skip cleanup.'
		);
	}

	/**
	 * Test malformed negative retention falls back to the default cleanup window.
	 */
	public function test_cron_assistant_cleanup_normalizes_invalid_negative_retention() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Invalid Negative Retention' );

		$this->age_conversation( $conversation_id, time() - ( 90 * DAY_IN_SECONDS ) );
		update_user_meta( $user_id, 'mainwp_assistant_history_retention', -30 );

		MainWP_System_Cron_Jobs::instance()->cron_assistant_cleanup();

		$this->assertEquals(
			0,
			MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ),
			'Malformed negative retention values should fall back to default cleanup.'
		);
	}
}
