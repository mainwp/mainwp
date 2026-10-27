<?php
/**
 * Share the Dashboard test clock without loading another test class.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

/**
 * Use a fixture's fixed second, otherwise preserve the system clock.
 *
 * @return int Unix timestamp.
 */
function time() {
	if ( class_exists( Tests\Test_Exclusive_Credential_Key_Files::class, false ) && null !== Tests\Test_Exclusive_Credential_Key_Files::$fixed_time ) {
		return Tests\Test_Exclusive_Credential_Key_Files::$fixed_time;
	}
	if ( class_exists( Tests\MainWP_Backup_Incremental_Sender_Test::class, false ) && null !== Tests\MainWP_Backup_Incremental_Sender_Test::$fixed_time ) {
		return Tests\MainWP_Backup_Incremental_Sender_Test::$fixed_time;
	}
	return \time();
}
