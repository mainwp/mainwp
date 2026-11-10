<?php
/**
 * The site requirements note under Back Up Now and in the Add Task form.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_Backup_Task_Form;

/**
 * Class MainWP_Backup_Requirements_Note_Test
 */
class MainWP_Backup_Requirements_Note_Test extends \WP_UnitTestCase {

	use Backup_Cancel_Transport_Guard;
	use MainWP_Capability_Bypass_Guard;

	const SUMMARY = 'What a site needs before a backup can run';

	/**
	 * Every line of the note, in order.
	 *
	 * @return string[]
	 */
	private static function lines(): array {
		return array(
			'On the child site:',
			'A MainWP Child version that supports Built-In Backups, connected to this Dashboard. Disconnected and suspended sites cannot be backed up.',
			"A writable MainWP backup folder in the site's uploads folder (usually wp-content/uploads).",
			'Free disk space for the backup plus a reserve of a tenth of the disk, at least 100 MB and at most 1 GB.',
			'The wp-content and uploads folders inside the WordPress folder. File backups do not run yet on hosts that move them, such as Pressable, WordPress.com and Bedrock installs. Database backups still work on those sites.',
			'A single database server for backups that include the database. Sites that split the database across several servers (for example with HyperDB or LudicrousDB) can only run Files Only backups.',
			'On the Dashboard server:',
			'The cURL and OpenSSL PHP extensions.',
			'A private folder outside the web root that the web server can write to. The Dashboard creates a mainwp-private folder beside the WordPress folder. If that is not possible, set MAINWP_BACKUP_PRIVATE_DIR in the Dashboard wp-config.php.',
			'Free disk space for the downloaded backups.',
		);
	}

	/**
	 * Remove options set by a test.
	 */
	public function tear_down() {
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tear_down();
	}

	/**
	 * The Back Up Now page as an administrator with native backups on.
	 *
	 * @return string
	 */
	private function render_snapshots_view(): string {
		update_option( 'mainwp_enableNativeBackups', 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MainWP_Backup_Snapshots_View::render();
		return (string) ob_get_clean();
	}

	/**
	 * The Add Task details form.
	 *
	 * @return string
	 */
	private function render_task_form(): string {
		ob_start();
		MainWP_Backup_Task_Form::render_task_details( null, 'Tar GZip', 'site', false, true );
		return (string) ob_get_clean();
	}

	/**
	 * The note's disclosure markup, which must appear exactly once.
	 *
	 * @param string $output Rendered page.
	 * @return string
	 */
	private function note( string $output ): string {
		$count = preg_match_all( '#<details class="ui blue message mainwp-backup-requirements">(.*?)</details>#s', $output, $matches );
		$this->assertSame( 1, $count, 'The requirements note renders once as a native disclosure.' );
		return $matches[1][0];
	}

	/**
	 * Every line sits inside the disclosure, escaped, with the summary first.
	 *
	 * @param string $note Disclosure body.
	 */
	private function assert_complete( string $note ): void {
		$this->assertMatchesRegularExpression( '#^\s*<summary>' . preg_quote( self::SUMMARY, '#' ) . '</summary>#', $note );
		$offset = 0;
		foreach ( self::lines() as $line ) {
			$position = strpos( $note, esc_html( $line ), $offset );
			$this->assertNotFalse( $position, 'Missing or out of order: ' . $line );
			$offset = $position;
		}
		$this->assertSame( 8, substr_count( $note, '<li>' ), 'Each requirement is its own list item.' );
	}

	/**
	 * Back Up Now carries the note beside its destination notes.
	 */
	public function test_snapshots_view_renders_requirements_note(): void {
		$output = $this->render_snapshots_view();
		$this->assert_complete( $this->note( $output ) );
		$this->assertMatchesRegularExpression( '#<div id="mainwp-native-backup-now-note"[^>]*>\s*<div[^>]*>[^<]*</div>\s*<div[^>]*>[^<]*</div>\s*<details class="ui blue message mainwp-backup-requirements">#', $output, 'The note follows the destination notes inside the Back Up Now note container.' );
	}

	/**
	 * The Add Task form carries the note right after the files-only note.
	 */
	public function test_task_form_renders_requirements_note(): void {
		$output = $this->render_task_form();
		$this->assert_complete( $this->note( $output ) );
		$files = strpos( $output, 'id="mainwp-backup-files-note"' );
		$note  = strpos( $output, '<details class="ui blue message mainwp-backup-requirements">' );
		$this->assertNotFalse( $files );
		$this->assertGreaterThan( $files, $note );
		$this->assertDoesNotMatchRegularExpression( '#<(?:input|select|textarea)#', substr( $output, $files, $note - $files ), 'No form field sits between the files-only note and the requirements note.' );
	}

	/**
	 * Translated text is escaped at output.
	 */
	public function test_requirements_note_escapes_translations(): void {
		$translate = static function ( $translation, $text, $domain ) {
			if ( 'mainwp' !== $domain ) {
				return $translation;
			}
			if ( self::SUMMARY === $text ) {
				return '<img src=x onerror=alert(1)>';
			}
			return 'The cURL and OpenSSL PHP extensions.' === $text ? '<b>bold</b>' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		try {
			$output = $this->render_task_form();
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}
		$note = $this->note( $output );
		$this->assertStringContainsString( '<summary>&lt;img src=x onerror=alert(1)&gt;</summary>', $note );
		$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt;', $note );
		$this->assertStringNotContainsString( '<b>bold</b>', $note );
	}
}
