<?php
/**
 * Available Updates nav counts honor the same ignore rules as the tables.
 *
 * @package MainWP\Tests
 */

use MainWP\Dashboard\MainWP_Cache_Helper;
use MainWP\Dashboard\MainWP_Common_Handler;

/**
 * Class Test_Updates_Nav_Count
 */
class Test_Updates_Nav_Count extends WP_UnitTestCase {

	/**
	 * Build a child-site row with one update of each type.
	 *
	 * @param array $overrides Property overrides.
	 *
	 * @return object
	 */
	private function site( $overrides = array() ) {
		$website = (object) array(
			'is_ignoreCoreUpdates'   => 0,
			'is_ignorePluginUpdates' => 0,
			'is_ignoreThemeUpdates'  => 0,
			'ignored_trans_updates'  => 0,
			'wp_upgrades'            => wp_json_encode(
				array(
					'current' => '6.4',
					'new'     => '6.5',
				)
			),
			'ignored_wp_upgrades'    => '',
			'plugin_upgrades'        => wp_json_encode(
				array(
					'hello-dolly/hello.php' => array(
						'Name'   => 'Hello Dolly',
						'update' => array(
							'new_version' => '1.8',
							'slug'        => 'hello-dolly',
						),
					),
					'akismet/akismet.php'   => array(
						'Name'   => 'Akismet',
						'update' => array(
							'new_version' => '5.3',
							'slug'        => 'akismet',
						),
					),
				)
			),
			'ignored_plugins'        => '',
			'theme_upgrades'         => wp_json_encode(
				array(
					'twentytwentyfour' => array(
						'Name'   => 'Twenty Twenty-Four',
						'update' => array(
							'new_version' => '1.2',
							'theme'       => 'twentytwentyfour',
						),
					),
				)
			),
			'ignored_themes'         => '',
			'translation_upgrades'   => wp_json_encode(
				array(
					array(
						'name'    => 'English',
						'slug'    => 'default',
						'version' => '6.5',
					),
					array(
						'name'    => 'Deutsch',
						'slug'    => 'de_DE',
						'version' => '6.5',
					),
				)
			),
		);

		foreach ( $overrides as $key => $value ) {
			$website->$key = $value;
		}

		return $website;
	}

	/**
	 * Build a user-extension row.
	 *
	 * @param array $overrides Property overrides.
	 *
	 * @return object
	 */
	private function user_extension( $overrides = array() ) {
		$extension = (object) array(
			'ignored_plugins'     => '',
			'ignored_themes'      => '',
			'ignored_wp_upgrades' => '',
		);

		foreach ( $overrides as $key => $value ) {
			$extension->$key = $value;
		}

		return $extension;
	}

	/**
	 * Tally one site.
	 *
	 * @param object $website              Child site row.
	 * @param object $user_extension       User extension row.
	 * @param array  $global_ignored_cores Global ignored core versions.
	 * @param array  $premium_upgrades     Premium upgrades.
	 *
	 * @return array
	 */
	private function tally( $website, $user_extension = null, $global_ignored_cores = array(), $premium_upgrades = array() ) {
		if ( null === $user_extension ) {
			$user_extension = $this->user_extension();
		}

		return MainWP_Common_Handler::tally_site_available_updates( $website, $user_extension, $global_ignored_cores, $premium_upgrades );
	}

	/**
	 * A site with no ignore settings counts every update type.
	 */
	public function test_no_ignore_counts_every_type() {
		$tally = $this->tally( $this->site() );

		$this->assertSame( 1, $tally['wp'] );
		$this->assertSame( 2, $tally['plugins'] );
		$this->assertSame( 1, $tally['themes'] );
		$this->assertSame( 2, $tally['translations'] );
		$this->assertCount( 2, $tally['plugin_upgrades'] );
		$this->assertCount( 1, $tally['theme_upgrades'] );
		$this->assertCount( 2, $tally['translation_upgrades'] );
	}

	/**
	 * Ignore plugin updates drops plugins from the plugin count.
	 */
	public function test_ignore_plugin_updates_toggle() {
		$tally = $this->tally(
			$this->site(
				array(
					'is_ignorePluginUpdates' => 1,
				)
			)
		);

		$this->assertSame( 0, $tally['plugins'] );
		$this->assertSame( array(), $tally['plugin_upgrades'] );
		$this->assertSame( 1, $tally['wp'] );
		$this->assertSame( 1, $tally['themes'] );
		$this->assertSame( 2, $tally['translations'] );
	}

	/**
	 * Ignore core updates drops the WordPress count.
	 */
	public function test_ignore_core_updates_toggle() {
		$tally = $this->tally(
			$this->site(
				array(
					'is_ignoreCoreUpdates' => 1,
				)
			)
		);

		$this->assertSame( 0, $tally['wp'] );
		$this->assertSame( 2, $tally['plugins'] );
	}

	/**
	 * Ignore theme updates drops the theme count.
	 */
	public function test_ignore_theme_updates_toggle() {
		$tally = $this->tally(
			$this->site(
				array(
					'is_ignoreThemeUpdates' => 1,
				)
			)
		);

		$this->assertSame( 0, $tally['themes'] );
		$this->assertSame( array(), $tally['theme_upgrades'] );
		$this->assertSame( 2, $tally['plugins'] );
	}

	/**
	 * Ignore translation updates drops the translation count.
	 */
	public function test_ignore_translation_updates_toggle() {
		$ignored = $this->tally(
			$this->site(
				array(
					'ignored_trans_updates' => '1',
				)
			)
		);
		$counted = $this->tally(
			$this->site(
				array(
					'ignored_trans_updates' => '0',
				)
			)
		);

		$this->assertSame( 0, $ignored['translations'] );
		$this->assertSame( array(), $ignored['translation_upgrades'] );
		$this->assertSame( 2, $counted['translations'] );
	}

	/**
	 * A per-site ignored plugin is removed from the plugin list and count.
	 */
	public function test_site_ignored_plugin() {
		$tally = $this->tally(
			$this->site(
				array(
					'ignored_plugins' => wp_json_encode(
						array(
							'hello-dolly/hello.php' => 'ignored',
						)
					),
				)
			)
		);

		$this->assertSame( 1, $tally['plugins'] );
		$this->assertArrayHasKey( 'akismet/akismet.php', $tally['plugin_upgrades'] );
		$this->assertArrayNotHasKey( 'hello-dolly/hello.php', $tally['plugin_upgrades'] );
	}

	/**
	 * A per-site ignored theme is removed from the theme list and count.
	 */
	public function test_site_ignored_theme() {
		$tally = $this->tally(
			$this->site(
				array(
					'ignored_themes' => wp_json_encode(
						array(
							'twentytwentyfour' => 'ignored',
						)
					),
				)
			)
		);

		$this->assertSame( 0, $tally['themes'] );
		$this->assertSame( array(), $tally['theme_upgrades'] );
	}

	/**
	 * A globally ignored plugin is removed the same way a per-site ignore is.
	 */
	public function test_global_ignored_plugin() {
		$tally = $this->tally(
			$this->site(),
			$this->user_extension(
				array(
					'ignored_plugins' => wp_json_encode(
						array(
							'akismet/akismet.php' => array(
								'ignored_versions' => array( 'all_versions' ),
							),
						)
					),
				)
			)
		);

		$this->assertSame( 1, $tally['plugins'] );
		$this->assertArrayNotHasKey( 'akismet/akismet.php', $tally['plugin_upgrades'] );
		$this->assertArrayHasKey( 'hello-dolly/hello.php', $tally['plugin_upgrades'] );
	}

	/**
	 * An ignored core version is not counted.
	 */
	public function test_ignored_core_version() {
		$site = $this->tally(
			$this->site(
				array(
					'ignored_wp_upgrades' => wp_json_encode(
						array(
							'ignored_versions' => array( '6.5' ),
						)
					),
				)
			)
		);
		$global = $this->tally(
			$this->site(),
			$this->user_extension(),
			array(
				'ignored_versions' => array( '6.5' ),
			)
		);

		$this->assertSame( 0, $site['wp'] );
		$this->assertSame( 0, $global['wp'] );
	}

	/**
	 * Premium plugin data merges only when that slug is already an update.
	 */
	public function test_premium_plugin_merge_rules() {
		$existing = $this->tally(
			$this->site(),
			null,
			array(),
			array(
				'akismet/akismet.php' => array(
					'type' => 'plugin',
					'Name' => 'Akismet',
				),
				'missing/missing.php' => array(
					'type' => 'plugin',
					'Name' => 'Missing',
				),
			)
		);

		$this->assertSame( 2, $existing['plugins'] );
		$this->assertArrayNotHasKey( 'missing/missing.php', $existing['plugin_upgrades'] );
		$this->assertTrue( $existing['plugin_upgrades']['akismet/akismet.php']['premium'] );

		$ignored = $this->tally(
			$this->site(
				array(
					'is_ignorePluginUpdates' => 1,
				)
			),
			null,
			array(),
			array(
				'akismet/akismet.php' => array(
					'type' => 'plugin',
					'Name' => 'Akismet',
				),
			)
		);

		$this->assertSame( 0, $ignored['plugins'] );
		$this->assertSame( array(), $ignored['plugin_upgrades'] );
	}

	/**
	 * A premium theme is added even when the slug is not already an update.
	 */
	public function test_premium_theme_is_added_unless_theme_updates_are_ignored() {
		$added = $this->tally(
			$this->site(),
			null,
			array(),
			array(
				'extra-theme' => array(
					'type' => 'theme',
					'Name' => 'Extra',
				),
			)
		);
		$ignored = $this->tally(
			$this->site(
				array(
					'is_ignoreThemeUpdates' => 1,
				)
			),
			null,
			array(),
			array(
				'extra-theme' => array(
					'type' => 'theme',
					'Name' => 'Extra',
				),
			)
		);

		$this->assertSame( 2, $added['themes'] );
		$this->assertArrayHasKey( 'extra-theme', $added['theme_upgrades'] );
		$this->assertSame( 0, $ignored['themes'] );
		$this->assertArrayNotHasKey( 'extra-theme', $ignored['theme_upgrades'] );
	}

	/**
	 * Ignore-field writes change the updates cache prefix. Other writes do not.
	 */
	public function test_ignore_field_writes_invalidate_updates_cache() {
		$before = MainWP_Cache_Helper::get_cache_prefix( MainWP_Cache_Helper::CGR_UPDATES );

		usleep( 1000 );
		MainWP_Cache_Helper::invalidate_updates_cache_if_ignore_fields( array( 'url' => 'https://example.com' ) );
		MainWP_Cache_Helper::invalidate_updates_cache_if_ignore_fields( array() );
		MainWP_Cache_Helper::invalidate_updates_cache_if_ignore_fields( 'ignored_plugins' );

		$this->assertSame( $before, MainWP_Cache_Helper::get_cache_prefix( MainWP_Cache_Helper::CGR_UPDATES ) );

		usleep( 1000 );
		MainWP_Cache_Helper::invalidate_updates_cache_if_ignore_fields(
			array(
				'is_ignorePluginUpdates' => 1,
			)
		);

		$this->assertNotSame( $before, MainWP_Cache_Helper::get_cache_prefix( MainWP_Cache_Helper::CGR_UPDATES ) );
	}
}
