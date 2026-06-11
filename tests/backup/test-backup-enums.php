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
	 * The enums live in class/backup/ and must resolve through the
	 * plugin autoloader without an explicit require.
	 *
	 * @return void
	 */
	public function test_enums_autoload_from_backup_subdirectory() {
		$this->assertTrue( enum_exists( MainWP_Backup_Type::class ) );
		$this->assertTrue( enum_exists( MainWP_Archive_Format::class ) );
		$this->assertTrue( enum_exists( MainWP_Backup_Task_Phase::class ) );
	}

	/**
	 * Backup type wire values map onto enum cases.
	 *
	 * @return void
	 */
	public function test_backup_type_maps_wire_values() {
		$this->assertSame( MainWP_Backup_Type::Full, MainWP_Backup_Type::from( 'full' ) );
		$this->assertSame( MainWP_Backup_Type::Database, MainWP_Backup_Type::from( 'db' ) );
		$this->assertSame( MainWP_Backup_Type::Incremental, MainWP_Backup_Type::from( 'incremental' ) );
		$this->assertSame( MainWP_Backup_Type::Files, MainWP_Backup_Type::from( 'files' ) );
		$this->assertNull( MainWP_Backup_Type::tryFrom( 'bogus' ) );
	}

	/**
	 * Archive format wire values map onto enum cases.
	 *
	 * @return void
	 */
	public function test_archive_format_maps_wire_values() {
		$this->assertSame( MainWP_Archive_Format::Zip, MainWP_Archive_Format::from( 'zip' ) );
		$this->assertSame( MainWP_Archive_Format::TarGz, MainWP_Archive_Format::from( 'tar.gz' ) );
		$this->assertSame( MainWP_Archive_Format::TarBz2, MainWP_Archive_Format::from( 'tar.bz2' ) );
		$this->assertSame( MainWP_Archive_Format::Tar, MainWP_Archive_Format::from( 'tar' ) );
		$this->assertNull( MainWP_Archive_Format::tryFrom( 'rar' ) );
	}

	/**
	 * Task phase wire values map onto enum cases, and unknown phase
	 * strings stay null so callers treat them as still-working.
	 *
	 * @return void
	 */
	public function test_task_phase_maps_wire_values() {
		$this->assertSame( MainWP_Backup_Task_Phase::Queued, MainWP_Backup_Task_Phase::from( 'queued' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::DumpingDb, MainWP_Backup_Task_Phase::from( 'dumping_db' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::ArchivingFiles, MainWP_Backup_Task_Phase::from( 'archiving_files' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::Uploading, MainWP_Backup_Task_Phase::from( 'uploading' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::Submitting, MainWP_Backup_Task_Phase::from( 'submitting' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::Completed, MainWP_Backup_Task_Phase::from( 'completed' ) );
		$this->assertSame( MainWP_Backup_Task_Phase::Failed, MainWP_Backup_Task_Phase::from( 'failed' ) );
		$this->assertNull( MainWP_Backup_Task_Phase::tryFrom( 'some_future_phase' ) );
	}

	/**
	 * Only the completed and failed phases are terminal.
	 *
	 * @return void
	 */
	public function test_task_phase_terminal_states() {
		$this->assertTrue( MainWP_Backup_Task_Phase::Completed->is_terminal() );
		$this->assertTrue( MainWP_Backup_Task_Phase::Failed->is_terminal() );
		$this->assertFalse( MainWP_Backup_Task_Phase::Queued->is_terminal() );
		$this->assertFalse( MainWP_Backup_Task_Phase::DumpingDb->is_terminal() );
		$this->assertFalse( MainWP_Backup_Task_Phase::ArchivingFiles->is_terminal() );
		$this->assertFalse( MainWP_Backup_Task_Phase::Uploading->is_terminal() );
		$this->assertFalse( MainWP_Backup_Task_Phase::Submitting->is_terminal() );
	}

	/**
	 * Every phase carries a non-empty human label distinct from its wire
	 * value, so the UI never shows raw machine phases.
	 *
	 * @return void
	 */
	public function test_task_phase_labels() {
		foreach ( MainWP_Backup_Task_Phase::cases() as $phase ) {
			$this->assertNotSame( '', $phase->label(), $phase->value . ' must have a label.' );
		}
		$this->assertSame( 'Exporting database', MainWP_Backup_Task_Phase::DumpingDb->label() );
		$this->assertSame( 'Archiving files', MainWP_Backup_Task_Phase::ArchivingFiles->label() );
	}
}
