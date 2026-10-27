<?php
/**
 * Translation holds through registered update abilities.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Cron;
use MainWP\Dashboard\MainWP_DB;

class MainWP_Translation_Hold_Abilities_Test extends MainWP_Abilities_Test_Case {

	private $calls = array();

	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->calls = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $site, $what, $params ) {
				$this->calls[] = array(
					'site_id' => (int) $site->id,
					'what'    => $what,
					'type'    => $params['type'] ?? '',
					'list'    => $params['list'] ?? '',
				);
				$slugs = empty( $params['list'] ) ? array() : explode( ',', $params['list'] );
				return array( 'upgrades' => array_fill_keys( $slugs, true ) );
			},
			10,
			4
		);
	}

	private function create_translation_site( bool $held ): int {
		$site_id = $this->create_test_site();
		$this->set_site_translation_upgrades(
			$site_id,
			array(
				array( 'slug' => 'akismet', 'name' => 'Akismet', 'language' => 'fr_FR', 'version' => '5.0', 'new_version' => '5.1' ),
				array( 'slug' => 'wordpress', 'language' => 'fr_FR', 'version' => '7.1' ),
			)
		);
		$this->set_site_option( $site_id, 'ignored_trans_updates', $held ? '1' : '0' );
		$site = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( $held ? '1' : '0', MainWP_DB::instance()->get_website_option( $site, 'ignored_trans_updates' ) );
		return $site_id;
	}

	public static function list_cases(): array {
		$cases = array();
		foreach ( array( 'selected', 'all', 'get' ) as $path ) {
			foreach ( array( false, true ) as $held ) {
				$cases[ $path . ( $held ? '-held' : '-released' ) ] = array( $path, $held );
			}
		}
		return $cases;
	}

	/** @dataProvider list_cases */
	public function test_lists_read_the_saved_translation_hold( string $path, bool $held ): void {
		$site_id = $this->create_translation_site( $held );
		$input   = array( 'types' => array( 'translations' ) );
		$ability = 'mainwp/list-updates-v1';
		if ( 'selected' === $path ) {
			$input['site_ids_or_domains'] = array( $site_id );
		} elseif ( 'get' === $path ) {
			$ability                    = 'mainwp/get-site-updates-v1';
			$input['site_id_or_domain'] = $site_id;
		}
		$this->assertNotNull( wp_get_ability( $ability ) );
		$result = wp_get_ability( $ability )->execute( $input );
		$this->assertNotWPError( $result );
		$this->assertSame( $held ? 0 : 2, $result['summary']['translations'] );
		$this->assertSame( $held ? 0 : 2, $result['summary']['total'] );
		$this->assertSame( $held ? array() : array( 'akismet', 'wordpress' ), array_column( $result['updates'], 'slug' ) );
		if ( ! $held ) {
			$this->assertSame( '5.0', $result['updates'][0]['current_version'] );
			$this->assertSame( '5.1', $result['updates'][0]['new_version'] );
			$this->assertSame( '7.1', $result['updates'][1]['current_version'] );
			$this->assertSame( '', $result['updates'][1]['new_version'] );
		}
		$this->assertSame( array(), $this->calls );
	}

	public static function execution_cases(): array {
		$cases = array();
		foreach ( array( 'mainwp/update-site-translations-v1', 'mainwp/run-updates-v1', 'mainwp/update-all-v1' ) as $ability ) {
			foreach ( array( false, true ) as $held ) {
				foreach ( array( false, true ) as $explicit ) {
					if ( $explicit && 'mainwp/update-all-v1' === $ability ) {
						continue;
					}
					foreach ( array( false, true ) as $preview ) {
						$key           = $ability . ( $held ? '-held' : '-released' ) . ( $explicit ? '-slug' : '-all' ) . ( $preview ? '-preview' : '-confirm' );
						$cases[ $key ] = array( $ability, $held, $explicit, $preview );
					}
				}
			}
		}
		return $cases;
	}

	/** @dataProvider execution_cases */
	public function test_translation_execution_obeys_the_saved_hold( string $ability, bool $held, bool $explicit, bool $preview ): void {
		$site_id = $this->create_translation_site( $held );
		$input   = array( $preview ? 'dry_run' : 'confirm' => true );
		if ( 'mainwp/update-site-translations-v1' === $ability ) {
			$input['site_id_or_domain'] = $site_id;
			if ( $explicit ) {
				$input['slugs'] = array( 'akismet' );
			}
		} else {
			$input['site_ids_or_domains'] = array( $site_id );
			$input['types']               = array( 'translations' );
			if ( $explicit ) {
				$input['specific_items'] = array( 'akismet' );
			}
		}
		$this->assertNotNull( wp_get_ability( $ability ) );
		$result = wp_get_ability( $ability )->execute( $input );
		$this->assertNotWPError( $result );
		$this->assertSame( array(), $result['errors'] );
		$slugs = $explicit ? array( 'akismet' ) : array( 'akismet', 'wordpress' );
		if ( $preview ) {
			$this->assertSame( $held ? 0 : count( $slugs ), $result['count'] );
			$this->assertSame( $held ? count( $slugs ) : 0, $result['would_affect']['summary']['skipped_count'] );
			$site = $result['would_affect']['sites'][0];
			$this->assertSame( $held ? array() : $slugs, array_column( $site['items'], 'slug' ) );
			$skipped = $site['skipped'];
		} else {
			$this->assertSame( $held ? array() : $slugs, array_column( $result['updated'], 'slug' ) );
			$this->assertSame( $held ? 0 : count( $slugs ), $result['summary']['total_updated'] );
			$skipped = $result['skipped'];
			if ( ! $held ) {
				$this->assertSame( '5.0', $result['updated'][0]['old_version'] );
				$this->assertSame( '5.1', $result['updated'][0]['new_version'] );
				if ( ! $explicit ) {
					$this->assertSame( '', $result['updated'][1]['old_version'] );
					$this->assertSame( '7.1', $result['updated'][1]['new_version'] );
				}
			}
		}
		$this->assertSame( $held ? $slugs : array(), array_column( $skipped, 'slug' ) );
		$this->assertSame( $held ? array_fill( 0, count( $slugs ), 'ignored_site_flag' ) : array(), array_column( $skipped, 'reason' ) );
		if ( $held && $explicit ) {
			$this->assertTrue( $skipped[0]['requested'] );
		}
		$this->assertSame(
			$held || $preview ? array() : array( array( 'site_id' => $site_id, 'what' => 'upgradetranslation', 'type' => 'translation', 'list' => implode( ',', $slugs ) ) ),
			$this->calls
		);
	}

	public static function queued_cases(): array {
		$cases = array();
		foreach ( array( 'held-before-queue', 'held-after-queue', 'released' ) as $when ) {
			foreach ( array( false, true ) as $explicit ) {
				$cases[ $when . ( $explicit ? '-slug' : '-all' ) ] = array( $when, $explicit );
			}
		}
		return $cases;
	}

	/** @dataProvider queued_cases */
	public function test_queued_translation_job_reads_the_current_hold( string $when, bool $explicit ): void {
		$site_id = $this->create_translation_site( 'held-before-queue' === $when );
		$input   = array( 'site_ids_or_domains' => array( $site_id ), 'types' => array( 'translations' ), 'confirm' => true );
		if ( $explicit ) {
			$input['specific_items'] = array( 'akismet' );
		}
		$threshold = static function () { return 0; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = wp_get_ability( 'mainwp/run-updates-v1' )->execute( $input );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['queued'] );
		$job_id = $result['job_id'];
		$this->track_update_job( $job_id );
		$this->assertSame( array(), $this->calls );
		if ( 'held-after-queue' === $when ) {
			$this->set_site_option( $site_id, 'ignored_trans_updates', '1' );
		}
		wp_clear_scheduled_hook( 'mainwp_process_update_job', array( $job_id ) );
		MainWP_Abilities_Cron::instance()->process_update_job( $job_id );
		$job   = get_transient( 'mainwp_update_job_' . $job_id );
		$held  = 'released' !== $when;
		$slugs = $explicit ? array( 'akismet' ) : array( 'akismet', 'wordpress' );
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( array(), $job['errors'] );
		$this->assertSame( $held ? array() : array( $site_id ), $job['updated'] );
		$this->assertSame( $held ? array( $site_id ) : array(), $job['unchanged'] );
		$this->assertSame( $held ? $slugs : array(), array_column( $job['skipped'], 'slug' ) );
		$this->assertSame( $held ? array_fill( 0, count( $slugs ), 'ignored_site_flag' ) : array(), array_column( $job['skipped'], 'reason' ) );
		$this->assertSame(
			$held ? array() : array( array( 'site_id' => $site_id, 'what' => 'upgradetranslation', 'type' => 'translation', 'list' => implode( ',', $slugs ) ) ),
			$this->calls
		);
	}
}
