<?php
/**
 * Update screen site-name attribute tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Security_Issues;
use MainWP\Dashboard\MainWP_Updates_Overview;
use MainWP\Dashboard\MainWP_Updates_Per_Group;
use MainWP\Dashboard\MainWP_Updates_Per_Item;
use MainWP\Dashboard\MainWP_Updates_Per_Site;

use const MainWP\Dashboard\MAINWP_VIEW_PER_SITE;

/**
 * Stored punctuation and entities must survive the attribute transport unchanged.
 */
class Test_Update_Screen_Site_Name_Escaping extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Exercise the page renderers so their attribute encodings cannot drift apart.
	 */
	public function test_site_name_attributes_encode_the_stored_name_once(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'updates-per-site', 'updates-per-group', 'updates-per-item', 'security-issues' ) as $page ) {
			require_once dirname( __DIR__ ) . '/pages/page-mainwp-' . $page . '.php';
		}
		require_once dirname( __DIR__ ) . '/widgets/widget-mainwp-updates-overview.php';

		$slug    = 'fixture/fixture.php';
		$plugin  = array(
			'Name'    => 'Fixture',
			'Version' => '1.0',
			'active'  => true,
			'update'  => array(
				'slug'        => 'fixture',
				'new_version' => '2.0',
			),
		);
		$options = (object) array(
			'site_view'       => MAINWP_VIEW_PER_SITE,
			'ignored_plugins' => '{}',
		);

		foreach ( array( '&lt;img src=x onerror=alert(1)&gt;', '100% uptime', 'O\'Brien "Q"', 'A &lt; B' ) as $name ) {
			$site_id = $this->create_test_site( array( 'name' => $name ) );
			$this->set_site_option( $site_id, 'plugin_upgrades', wp_json_encode( array( $slug => $plugin ) ) );
			$this->set_site_option( $site_id, 'ignored_plugins', '{}' );
			$db       = MainWP_DB::instance();
			$websites = $db->query(
				$db->get_sql_websites_for_current_user_by_params(
					array(
						'where'         => 'wp.id = ' . (int) $site_id,
						'with_clients'  => true,
						'others_fields' => array( 'plugin_upgrades', 'ignored_plugins', 'site_info', 'favi_icon' ),
					)
				)
			);
			$this->assertNotFalse( $websites );
			$website = $db->get_website_by_id( $site_id );
			$item    = array(
				'id'               => $site_id,
				'name'             => $website->name,
				'plugin_slug'      => 'fixture',
				'theme_slug'       => 'fixture',
				'translation_slug' => 'fixture',
			);

			$renderers = array(
				'site'     => static function () use ( $websites, $options ) {
					MainWP_Updates_Per_Site::render_plugins_updates( $websites, 1, $options, array() );
				},
				'group'    => static function () use ( $websites, $options, $site_id ) {
					MainWP_Updates_Per_Group::render_plugins_updates( $websites, 1, $options, array( 1 => array( $site_id ) ), array( 1 => 'Fixture' ), array( $site_id => 0 ), array() );
				},
				'item'     => static function () use ( $websites, $options, $slug ) {
					MainWP_Updates_Per_Item::render_plugins_updates(
						$websites,
						1,
						$options,
						array(
							$slug => array(
								'cnt'     => 1,
								'premium' => false,
							),
						),
						array(
							$slug => array(
								'slug'    => 'fixture',
								'name'    => 'Fixture',
								'premium' => false,
							),
						),
						array()
					);
				},
				'security' => static function () use ( $website ) {
					MainWP_Security_Issues::render( $website );
				},
				'overview' => static function () use ( $item ) {
					MainWP_Updates_Overview::render_global_update( 'all', true, 1, array( $item ), true, 1, array( $item ), true, 1, array( $item ), 1, true, 1, array( $item ) );
				},
			);
			$counts    = array(
				'site'     => 2,
				'group'    => 2,
				'item'     => 1,
				'security' => 1,
				'overview' => 4,
			);
			foreach ( $renderers as $screen => $render ) {
				ob_start();
				try {
					$render();
					$html = ob_get_contents();
				} finally {
					ob_end_clean();
				}
				preg_match_all( '/site_name="([^"]*)"/', $html, $matches );
				$this->assertCount( $counts[ $screen ], $matches[1], $screen );
				foreach ( $matches[1] as $attribute ) {
					$this->assertSame( rawurlencode( stripslashes( $website->name ) ), html_entity_decode( $attribute, ENT_QUOTES, 'UTF-8' ), $screen );
				}
				preg_match_all( '/<[^>]+site_name="[^>]+>/', $html, $tags );
				foreach ( $tags[0] as $tag ) {
					$this->assertSame( 1, substr_count( $tag, 'site_name=' ), $screen );
				}
			}
			MainWP_DB::free_result( $websites );
		}
	}
}
