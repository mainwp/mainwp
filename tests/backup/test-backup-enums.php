<?php
/**
 * MainWP Backup Enum Tests
 *
 * Covers the backed enums that describe backup types, archive formats,
 * and child task phases, plus the autoloader's ability to resolve
 * classes that live in the class/backup/ subdirectory.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Archive_Format;
use MainWP\Dashboard\MainWP_Backup_Task_Phase;
use MainWP\Dashboard\MainWP_Backup_Type;

/**
 * Class MainWP_Backup_Enums_Test
 */
class MainWP_Backup_Enums_Test extends MainWP_Abilities_Test_Case {

	/**
	 * The enum-turned-classes live in class/backup/ and must resolve
	 * through the plugin autoloader without an explicit require.
	 *
	 * @return void
	 */
	public function test_enums_autoload_from_backup_subdirectory() {
		$this->assertTrue( class_exists( MainWP_Backup_Type::class ) );
		$this->assertTrue( class_exists( MainWP_Archive_Format::class ) );
		$this->assertTrue( class_exists( MainWP_Backup_Task_Phase::class ) );
	}

	/**
	 * Backup type wire values map onto their case constants.
	 *
	 * @return void
	 */
	public function test_backup_type_maps_wire_values() {
		$this->assertSame( 'full', MainWP_Backup_Type::FULL );
		$this->assertSame( 'db', MainWP_Backup_Type::DATABASE );
		$this->assertSame( 'incremental', MainWP_Backup_Type::INCREMENTAL );
		$this->assertSame( 'files', MainWP_Backup_Type::FILES );

		$this->assertSame( MainWP_Backup_Type::FULL, MainWP_Backup_Type::from( 'full' ) );
		$this->assertSame( MainWP_Backup_Type::DATABASE, MainWP_Backup_Type::from( 'db' ) );
		$this->assertSame( MainWP_Backup_Type::INCREMENTAL, MainWP_Backup_Type::from( 'incremental' ) );
		$this->assertSame( MainWP_Backup_Type::FILES, MainWP_Backup_Type::from( 'files' ) );
		$this->assertNull( MainWP_Backup_Type::tryFrom( 'bogus' ) );
	}

	/**
	 * from() throws on an unknown value (InvalidArgumentException; the native enum's ValueError is PHP 8.0+).
	 *
	 * @return void
	 */
	public function test_backup_type_from_throws_on_unknown_value() {
		$this->expectException( \InvalidArgumentException::class );
		MainWP_Backup_Type::from( 'bogus' );
	}

	/**
	 * Archive format wire values map onto their case constants.
	 *
	 * @return void
	 */
	public function test_archive_format_maps_wire_values() {
		$this->assertSame( 'zip', MainWP_Archive_Format::ZIP );
		$this->assertSame( 'tar.gz', MainWP_Archive_Format::TAR_GZ );
		$this->assertSame( 'tar.bz2', MainWP_Archive_Format::TAR_BZ2 );
		$this->assertSame( 'tar', MainWP_Archive_Format::TAR );

		$this->assertSame( MainWP_Archive_Format::ZIP, MainWP_Archive_Format::from( 'zip' ) );
		$this->assertSame( MainWP_Archive_Format::TAR_GZ, MainWP_Archive_Format::from( 'tar.gz' ) );
		$this->assertSame( MainWP_Archive_Format::TAR_BZ2, MainWP_Archive_Format::from( 'tar.bz2' ) );
		$this->assertSame( MainWP_Archive_Format::TAR, MainWP_Archive_Format::from( 'tar' ) );
		$this->assertNull( MainWP_Archive_Format::tryFrom( 'rar' ) );
	}

	/**
	 * from() throws on an unknown value (InvalidArgumentException; the native enum's ValueError is PHP 8.0+).
	 *
	 * @return void
	 */
	public function test_archive_format_from_throws_on_unknown_value() {
		$this->expectException( \InvalidArgumentException::class );
		MainWP_Archive_Format::from( 'rar' );
	}

	/**
	 * Task phase wire values map onto their case constants, and unknown
	 * phase strings stay null so callers treat them as still-working.
	 *
	 * @return void
	 */
	public function test_task_phase_maps_wire_values() {
		$this->assertSame( 'queued', MainWP_Backup_Task_Phase::QUEUED );
		$this->assertSame( 'dumping_db', MainWP_Backup_Task_Phase::DUMPING_DB );
		$this->assertSame( 'archiving_files', MainWP_Backup_Task_Phase::ARCHIVING_FILES );
		$this->assertSame( 'uploading', MainWP_Backup_Task_Phase::UPLOADING );
		$this->assertSame( 'submitting', MainWP_Backup_Task_Phase::SUBMITTING );
		$this->assertSame( 'completed', MainWP_Backup_Task_Phase::COMPLETED );
		$this->assertSame( 'failed', MainWP_Backup_Task_Phase::FAILED );

		$this->assertSame( MainWP_Backup_Task_Phase::QUEUED, MainWP_Backup_Task_Phase::from( 'queued' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::DUMPING_DB, MainWP_Backup_Task_Phase::from( 'dumping_db' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::ARCHIVING_FILES, MainWP_Backup_Task_Phase::from( 'archiving_files' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::UPLOADING, MainWP_Backup_Task_Phase::from( 'uploading' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::SUBMITTING, MainWP_Backup_Task_Phase::from( 'submitting' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::COMPLETED, MainWP_Backup_Task_Phase::from( 'completed' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::FAILED, MainWP_Backup_Task_Phase::from( 'failed' ) );
		$this->assertNull( MainWP_Backup_Task_Phase::tryFrom( 'some_future_phase' ) );
	}

	/**
	 * from() throws on an unknown value (InvalidArgumentException; the native enum's ValueError is PHP 8.0+).
	 *
	 * @return void
	 */
	public function test_task_phase_from_throws_on_unknown_value() {
		$this->expectException( \InvalidArgumentException::class );
		MainWP_Backup_Task_Phase::from( 'some_future_phase' );
	}

	/**
	 * Only the completed and failed phases are terminal.
	 *
	 * @return void
	 */
	public function test_task_phase_terminal_states() {
		$this->assertTrue( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::COMPLETED ) );
		$this->assertTrue( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::FAILED ) );
		$this->assertFalse( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::QUEUED ) );
		$this->assertFalse( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::DUMPING_DB ) );
		$this->assertFalse( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::ARCHIVING_FILES ) );
		$this->assertFalse( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::UPLOADING ) );
		$this->assertFalse( MainWP_Backup_Task_Phase::is_terminal( MainWP_Backup_Task_Phase::SUBMITTING ) );
	}

	/**
	 * Every phase carries a non-empty human label distinct from its wire
	 * value, so the UI never shows raw machine phases.
	 *
	 * @return void
	 */
	public function test_task_phase_labels() {
		foreach ( MainWP_Backup_Task_Phase::cases() as $phase ) {
			$this->assertNotSame( '', MainWP_Backup_Task_Phase::label( $phase ), $phase . ' must have a label.' );
		}
		$this->assertSame( 'Exporting database', MainWP_Backup_Task_Phase::label( MainWP_Backup_Task_Phase::DUMPING_DB ) );
		$this->assertSame( 'Archiving files', MainWP_Backup_Task_Phase::label( MainWP_Backup_Task_Phase::ARCHIVING_FILES ) );
	}
}
