<?php
/**
 * Task selection follows the synced Child version through upgrades.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Task_Form;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

/** Version gates apply only to newly selected explicit sites. */
class MainWP_Backup_Task_Child_Version_Test extends MainWP_Abilities_Test_Case {

	/** Enable native task writes for an authenticated owner. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Normalized task fields for one explicit site.
	 *
	 * @param int $site_id Site identity.
	 * @return array Task fields.
	 */
	private function fields( int $site_id ): array {
		return array(
			'name'     => 'Version gate',
			'type'     => 'full',
			'schedule' => 'daily',
			'site_ids' => array( $site_id ),
		);
	}

	/**
	 * A missing flag is unknown and a stale negative flag cannot override an upgrade.
	 *
	 * @dataProvider child_versions
	 * @param string      $version Synced version.
	 * @param string|null $flag Negotiated capability.
	 * @param bool        $allowed Expected admission.
	 */
	public function test_create_task_uses_synced_child_version( $version, $flag, $allowed ) {
		$site = $this->create_test_site( array( 'version' => $version ) );
		if ( null !== $flag ) {
			MainWP_DB::instance()->update_website_option( $site, 'task_spine_support', $flag );
		}
		$result = MainWP_Backup_Operations::create_task( $this->fields( $site ), 'ui' );
		if ( $allowed ) {
			$this->assertIsArray( $result );
			$this->assertGreaterThan( 0, $result['task_id'] );
		} else {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_backup_child_update_required', $result->get_error_code() );
		}
	}

	/** Synced versions and cached flags. */
	public static function child_versions() {
		return array(
			'old negative flag'            => array( '6.3', '0', false ),
			'old null flag'                => array( '6.3', null, false ),
			'current null flag'            => array( '6.4', null, true ),
			'first release candidate'      => array( '6.4-rc.1', null, true ),
			'development boundary'         => array( '6.4-dev', null, true ),
			'unknown version'              => array( '', null, true ),
			'placeholder version'          => array( '0', null, true ),
			'upgraded stale negative flag' => array( '6.4.1', '0', true ),
		);
	}

	/**
	 * Inspect the actual task form's site checkbox.
	 *
	 * @param int         $site_id Site to inspect.
	 * @param object|null $task Saved task or create form.
	 * @return \DOMElement Checkbox.
	 */
	private function form_checkbox( int $site_id, ?object $task = null ): \DOMElement {
		ob_start();
		MainWP_Backup_Task_Form::render_new_edit( $task );
		$html     = ob_get_clean();
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$nodes = ( new \DOMXPath( $document ) )->query( '//input[@name="selected_sites[]" and @value="' . $site_id . '"]' );
		$this->assertSame( 1, $nodes->length );
		return $nodes->item( 0 );
	}

	/** The next sync makes an upgraded site selectable and writable despite its cached negative flag. */
	public function test_synced_upgrade_enables_form_and_save() {
		global $wpdb;
		$site = $this->create_test_site( array( 'version' => '6.3' ) );
		MainWP_DB::instance()->update_website_option( $site, 'task_spine_support', '0' );
		$checkbox = $this->form_checkbox( $site );
		$this->assertTrue( $checkbox->hasAttribute( 'disabled' ) );
		$this->assertStringContainsString( 'Needs MainWP Child 6.4', $checkbox->parentNode->textContent );
		$this->assertWPError( MainWP_Backup_Operations::create_task( $this->fields( $site ), 'ui' ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'version' => '6.4-rc.1' ), array( 'wpid' => $site ) );
		$this->assertFalse( $this->form_checkbox( $site )->hasAttribute( 'disabled' ) );
		$this->assertSame( '0', MainWP_DB::instance()->get_website_option( $site, 'task_spine_support' ) );
		$this->assertIsArray( MainWP_Backup_Operations::create_task( $this->fields( $site ), 'ui' ) );
	}

	/** Existing unsupported sites can be kept or removed, while adding another is refused. */
	public function test_edit_keeps_old_sites_removable_and_rejects_new_old_sites() {
		$old      = $this->create_test_site( array( 'version' => '6.3' ) );
		$new      = $this->create_test_site( array( 'version' => '6.4' ) );
		$other    = $this->create_test_site( array( 'version' => '6.3' ) );
		$task     = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Saved task', 'daily', 'full', '', (string) $old, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 0, 150, 0 );
		$checkbox = $this->form_checkbox( $old, $task );
		$this->assertTrue( $checkbox->hasAttribute( 'checked' ) );
		$this->assertFalse( $checkbox->hasAttribute( 'disabled' ) );
		$this->assertStringContainsString( 'Needs MainWP Child 6.4', $checkbox->parentNode->textContent );
		$this->assertIsArray( MainWP_Backup_Operations::update_task( (int) $task->id, array( 'name' => 'Renamed' ), 'ui' ) );
		$refused = MainWP_Backup_Operations::update_task( (int) $task->id, array( 'site_ids' => array( $old, $other ) ), 'ui' );
		$this->assertWPError( $refused );
		$this->assertSame( 'mainwp_backup_child_update_required', $refused->get_error_code() );
		$this->assertIsArray( MainWP_Backup_Operations::update_task( (int) $task->id, array( 'site_ids' => array( $new ) ), 'ui' ) );
		$this->assertSame( (string) $new, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->sites );
	}

	/** Group membership retains runtime negotiation rather than blocking the whole task. */
	public function test_old_sites_selected_by_tag_stay_allowed() {
		global $wpdb;
		$site  = $this->create_test_site( array( 'version' => '6.3' ) );
		$group = 986432;
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'group' ),
			array(
				'id'     => $group,
				'name'   => 'Version gate tag',
				'userid' => get_current_user_id(),
			)
		);
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_group' ),
			array(
				'wpid'    => $site,
				'groupid' => $group,
			)
		);
		$fields              = $this->fields( $site );
		$fields['site_ids']  = array();
		$fields['group_ids'] = array( $group );
		$this->assertIsArray( MainWP_Backup_Operations::create_task( $fields, 'ui' ) );
	}

	/** Ability previews and confirmed writes use the same version gate as the form. */
	public function test_create_task_ability_refuses_old_child_and_accepts_release_candidate() {
		foreach ( array( '6.3', '6.4-rc.1' ) as $version ) {
			$site = $this->create_test_site( array( 'version' => $version ) );
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$result = MainWP_Abilities_Backups::execute_create_backup_task(
					array(
						'name'                => 'Ability version gate',
						'type'                => 'full',
						'schedule'            => 'daily',
						'site_ids_or_domains' => array( $site ),
						$mode                 => true,
					)
				);
				if ( '6.3' === $version ) {
					$this->assertWPError( $result );
					$this->assertSame( 'mainwp_backup_child_update_required', $result->get_error_code() );
				} else {
					$this->assertIsArray( $result );
				}
			}
		}
	}
	/** Agents read the refusal from the ability description before calling it. */
	public function test_task_write_abilities_list_the_child_update_error() {
		foreach ( array( 'create-backup-task-v1', 'update-backup-task-v1' ) as $name ) {
			$ability = wp_get_ability( 'mainwp/' . $name );
			$this->assertNotNull( $ability, $name );
			$this->assertMatchesRegularExpression( '/Possible errors: .*mainwp_backup_child_update_required \\([^)]*MainWP Child is older than 6\\.4\\)/', $ability->get_description(), $name );
		}
	}

	/** The task form's staging tab uses the same saved-site exception and synced version. */
	public function test_staging_version_gate_preserves_saved_sites_and_synced_upgrades() {
		global $wpdb;
		$plugins = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $plugins, array( 'mainwp-staging-extension/mainwp-staging-extension.php' ) ) );
		try {
			$site = $this->create_test_site(
				array(
					'is_staging' => 1,
					'version'    => '6.3',
				)
			);
			MainWP_DB::instance()->update_website_option( $site, 'task_spine_support', '0' );
			$checkbox = $this->form_checkbox( $site );
			$this->assertTrue( $checkbox->hasAttribute( 'disabled' ) );
			$this->assertFalse( $checkbox->hasAttribute( 'checked' ) );
			$this->assertStringContainsString( 'Needs MainWP Child 6.4', $checkbox->parentNode->textContent );
			$task     = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Saved staging task', 'daily', 'full', '', (string) $site, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 0, 150, 0 );
			$checkbox = $this->form_checkbox( $site, $task );
			$this->assertTrue( $checkbox->hasAttribute( 'checked' ) );
			$this->assertFalse( $checkbox->hasAttribute( 'disabled' ) );
			$this->assertStringContainsString( 'Needs MainWP Child 6.4', $checkbox->parentNode->textContent );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'version' => '6.4-rc.1' ), array( 'wpid' => $site ) );
			$checkbox = $this->form_checkbox( $site );
			$this->assertFalse( $checkbox->hasAttribute( 'disabled' ) );
			$this->assertStringNotContainsString( 'Needs MainWP Child 6.4', $checkbox->parentNode->textContent );
		} finally {
			update_option( 'active_plugins', $plugins );
		}
	}
}
