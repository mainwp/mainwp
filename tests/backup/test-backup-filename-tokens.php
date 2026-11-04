<?php
/**
 * Saving a task keeps its %token% name templates; running it makes the expanded names safe.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Utility;

/** MWP-2086: sanitize_text_field() stripped "%da" from "%date%". */
class MainWP_Backup_Filename_Tokens_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	const TEMPLATE  = 'mwp-%sitename%-%date%-%time%-%type%';
	const SUBFOLDER = 'MainWP/%sitename%/%url%/%type%/%date%';

	/** Native backups on, as an administrator. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->set_up_private_root();
	}

	/** Remove the private root and posted fixtures. */
	public function tearDown(): void {
		$_POST = array();
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Create a task with the given name fields.
	 *
	 * @param array $fields Extra task fields.
	 * @return int Task ID.
	 */
	private function create( array $fields ): int {
		$task = MainWP_Backup_Operations::create_task(
			array_merge(
				array(
					'name'     => 'Token task',
					'type'     => 'full',
					'schedule' => 'daily',
					'site_ids' => array( $this->create_test_site() ),
				),
				$fields
			),
			'ability'
		);
		$this->assertIsArray( $task );
		return (int) $task['task_id'];
	}

	/**
	 * Stored task row.
	 *
	 * @param int $id Task ID.
	 * @return object
	 */
	private function stored( int $id ) {
		return MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
	}

	/** Create and update keep every advertised token exactly. */
	public function test_create_and_update_keep_tokens(): void {
		$id = $this->create(
			array(
				'filename'  => self::TEMPLATE,
				'subfolder' => self::SUBFOLDER,
			)
		);
		$this->assertSame( self::TEMPLATE, $this->stored( $id )->filename );
		$this->assertSame( self::SUBFOLDER, $this->stored( $id )->subfolder );

		$updated = MainWP_Backup_Operations::update_task(
			$id,
			array(
				'filename'  => 'site-%url%-%date%',
				'subfolder' => '%task%/%date%',
			),
			'ability'
		);
		$this->assertIsArray( $updated );
		$this->assertSame( 'site-%url%-%date%', $this->stored( $id )->filename );
		$this->assertSame( '%task%/%date%', $this->stored( $id )->subfolder );
	}

	/** A plain literal name is stored unchanged. */
	public function test_literal_name_unchanged(): void {
		$id = $this->create( array( 'filename' => 'My Site Backup_2026 (weekly)' ) );
		$this->assertSame( 'My Site Backup_2026 (weekly)', $this->stored( $id )->filename );
	}

	/** An unrelated edit neither damages a valid template nor rewrites an already-damaged one. */
	public function test_unrelated_edit_keeps_stored_templates(): void {
		$valid   = $this->create( array() );
		$damaged = $this->create( array() );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$valid,
			array(
				'filename'  => self::TEMPLATE,
				'subfolder' => self::SUBFOLDER,
			)
		);
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $damaged, array( 'filename' => 'mwp-%sitename%-te%-%time%' ) );

		foreach ( array( $valid, $damaged ) as $id ) {
			$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, array( 'name' => 'Renamed' ), 'ability' ) );
		}
		$this->assertSame( self::TEMPLATE, $this->stored( $valid )->filename );
		$this->assertSame( self::SUBFOLDER, $this->stored( $valid )->subfolder );
		$this->assertSame( 'mwp-%sitename%-te%-%time%', $this->stored( $damaged )->filename );
	}

	/** Tags, control characters and path separators leave the template; its tokens stay. */
	public function test_hostile_template_is_cleaned_on_save(): void {
		$id = $this->create(
			array(
				'filename'  => "<b>x</b>../..\\evil\x01-%date%",
				'subfolder' => "../<i>a</i>/./b\x07/%date%",
			)
		);
		$this->assertSame( 'x....evil-%date%', $this->stored( $id )->filename );
		$this->assertSame( 'a/b/%date%', $this->stored( $id )->subfolder );

		$rooted = $this->create( array( 'subfolder' => '/etc/%date%' ) );
		$this->assertSame( 'etc/%date%', $this->stored( $rooted )->subfolder );
	}

	/**
	 * Paths that would escape or reroot the destination folder.
	 *
	 * @return array
	 */
	public static function rooted_subfolders(): array {
		return array(
			'leading slash'      => array( '/etc/x', 'etc/x' ),
			'doubled slashes'    => array( '//a//b/', 'a/b' ),
			'blank segment'      => array( 'a/ /b', 'a/b' ),
			'UNC share'          => array( '\\\\srv\\share\\x', 'srv/share/x' ),
			'mixed separators'   => array( 'a\\b/c', 'a/b/c' ),
			'dot segments'       => array( '/./../x/', 'x' ),
			'sanitized to empty' => array( 'a/|/b', 'a/b' ),
		);
	}

	/**
	 * Empty segments are dropped so a sanitized path never starts at a root.
	 *
	 * @dataProvider rooted_subfolders
	 * @param string $in  Raw folder path.
	 * @param string $out Expected relative path.
	 */
	public function test_subfolder_path_stays_relative( string $in, string $out ): void {
		$this->assertSame( $out, MainWP_Backup_File_Manager::sanitize_subfolder_path( $in ) );
	}

	/** A stored template with a leading slash still expands to a relative folder. */
	public function test_rooted_subfolder_template_expands_relative(): void {
		$this->assertSame( 'Shop/db', MainWP_Backup_File_Manager::expand_subfolder( '/%sitename%/%type%', 'Shop', 'https://example.com', 'db' ) );
	}

	/** Expansion puts the run date and time into the archive name. */
	public function test_expansion_produces_real_date(): void {
		$name = MainWP_Backup_File_Manager::expand_archive_name( self::TEMPLATE, 'My Site', 'example.com', '10-30-2026', '9h05m03s', 'full' );
		$this->assertSame( 'mwp-My Site-10-30-2026-9h05m03s-full', $name );

		$folder = MainWP_Backup_File_Manager::expand_subfolder( self::SUBFOLDER, 'My Site', 'https://example.com/', 'db' );
		$this->assertSame( 'MainWP/My Site/example.com/db/' . MainWP_Utility::date( 'Ymd' ), $folder );
	}

	/** A hostile stored template (saved before this fix) still expands to one safe segment. */
	public function test_hostile_expansion_is_safe(): void {
		$name = MainWP_Backup_File_Manager::expand_archive_name( "../../etc/<x>\x00passwd%unknown%-%date%", 'a/b', 'example.com', '10-30-2026', '1h', 'full' );
		$this->assertSame( '....etcxpasswdunknown-10-30-2026', $name );

		$folder = MainWP_Backup_File_Manager::expand_subfolder( "../../%sitename%/./x\x1f/..", '../up', 'example.com', 'full' );
		$this->assertSame( '..up/x', $folder );
	}

	/** A finished run names the local archive and subfolder from the expanded templates. */
	public function test_check_pid_names_archive_from_template(): void {
		$site_id = $this->create_test_site( array( 'name' => 'Shop' ) );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $result, $website, $what ) {
				unset( $website );
				return 'backup_checkpid' === $what ? array(
					'status' => 'done',
					'file'   => 'child.zip',
					'size'   => 10,
				) : $result;
			},
			10,
			3
		);
		$result = MainWP_Backup_Monitor::backup_check_pid( $site_id, 1, 'full', '../%sitename%/%date%', 'mwp-%sitename%-%date%-%type%' )['result'];
		$this->assertSame( 'Shop/' . MainWP_Utility::date( 'Ymd' ), $result['subfolder'] );
		$date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
		$this->assertSame( 'mwp-Shop-' . $date . '-full.zip', basename( $result['local'] ) );
	}

	/** The task form has no subfolder input, so a UI save must not post an empty one over the stored value. */
	public function test_ui_task_fields_omit_subfolder(): void {
		$_POST  = array(
			'name'      => 'Renamed',
			'subfolder' => '',
			'filename'  => self::TEMPLATE,
		);
		$method = new \ReflectionMethod( MainWP_Manage_Backups_Handler::class, 'posted_task_fields' );
		$method->setAccessible( true );
		$fields = $method->invoke( null );
		$this->assertArrayNotHasKey( 'subfolder', $fields );
		$this->assertSame( self::TEMPLATE, $fields['filename'] );
	}

	/** Backup Now reads its templates with tokens intact and refuses non-string input. */
	public function test_backup_now_keeps_posted_tokens(): void {
		$_POST  = array(
			'filename'  => wp_slash( self::TEMPLATE ),
			'subfolder' => array( 'x' ),
		);
		$method = new \ReflectionMethod( MainWP_Post_Backup_Handler::class, 'posted_name_template' );
		$method->setAccessible( true );
		$this->assertSame( self::TEMPLATE, $method->invoke( null, 'filename' ) );
		$this->assertSame( '', $method->invoke( null, 'subfolder' ) );
		$this->assertSame( '', $method->invoke( null, 'missing' ) );
	}
}
