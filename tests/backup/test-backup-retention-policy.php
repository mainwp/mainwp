<?php
/**
 * MainWP Backup Retention Policy Tests
 *
 * The policy engine is pure: it receives restorable units (an incremental
 * chain or a standalone full/db snapshot) for one site and task pair and
 * returns what to prune. These tests pin the evaluation order (age, then
 * count, then storage cap), the chain-as-a-whole rule, and the guard that
 * never gives up the last restorable unit.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Retention_Policy;

/**
 * Class MainWP_Backup_Retention_Policy_Test
 */
class MainWP_Backup_Retention_Policy_Test extends \WP_UnitTestCase {

	/**
	 * A fixed evaluation instant.
	 *
	 * @var int
	 */
	private $now = 1770000000;

	/**
	 * Build a restorable unit.
	 *
	 * @param int[] $snapshot_ids   Snapshot ids in the unit, base first.
	 * @param int   $age_days       Days since the unit's newest snapshot.
	 * @param int   $size_bytes     Total artifact bytes.
	 * @param bool  $is_complete    Whether the unit is restorable.
	 *
	 * @return array
	 */
	private function make_unit( array $snapshot_ids, $age_days, $size_bytes = 1000, $is_complete = true ) {
		$newest = $this->now - $age_days * DAY_IN_SECONDS;
		return array(
			'snapshots'      => array_map(
				static function ( $id ) {
					return (object) array( 'id' => $id );
				},
				$snapshot_ids
			),
			'is_complete'    => $is_complete,
			'size_bytes'     => $size_bytes,
			'newest_created' => $newest,
			'oldest_created' => $newest - DAY_IN_SECONDS,
		);
	}

	/**
	 * Collect all snapshot ids named by a candidate list.
	 *
	 * @param array $candidates Candidates from evaluate().
	 *
	 * @return array
	 */
	private function pruned_ids( array $candidates ) {
		return array_merge( ...array_merge( array( array() ), array_column( $candidates, 'snapshot_ids' ) ) );
	}

	/**
	 * A task without any policy never prunes.
	 *
	 * @return void
	 */
	public function test_empty_policy_returns_no_candidates() {
		$policy = new MainWP_Backup_Retention_Policy( 0, 0, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 400 ),
			$this->make_unit( array( 2 ), 200 ),
		);

		$this->assertSame( array(), $policy->evaluate( $units, $this->now ) );
	}

	/**
	 * The count limit prunes the oldest units beyond the kept window.
	 *
	 * @return void
	 */
	public function test_count_prunes_oldest_beyond_limit() {
		$policy = new MainWP_Backup_Retention_Policy( 2, 0, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 30 ),
			$this->make_unit( array( 2 ), 20 ),
			$this->make_unit( array( 3 ), 10 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertCount( 1, $candidates );
		$this->assertSame( array( 1 ), $candidates[0]['snapshot_ids'] );
		$this->assertSame( 'count', $candidates[0]['reason'] );
	}

	/**
	 * The age limit prunes units whose newest snapshot is past the cutoff.
	 *
	 * @return void
	 */
	public function test_age_prunes_expired_units() {
		$policy = new MainWP_Backup_Retention_Policy( 0, 90, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 120 ),
			$this->make_unit( array( 2 ), 10 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertCount( 1, $candidates );
		$this->assertSame( array( 1 ), $candidates[0]['snapshot_ids'] );
		$this->assertSame( 'age', $candidates[0]['reason'] );
	}

	/**
	 * The storage cap prunes oldest-first until the total fits.
	 *
	 * @return void
	 */
	public function test_storage_cap_prunes_oldest_until_under_cap() {
		$policy = new MainWP_Backup_Retention_Policy( 0, 0, 250 );

		$units = array(
			$this->make_unit( array( 1 ), 30, 100 * MB_IN_BYTES ),
			$this->make_unit( array( 2 ), 20, 100 * MB_IN_BYTES ),
			$this->make_unit( array( 3 ), 10, 100 * MB_IN_BYTES ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertCount( 1, $candidates );
		$this->assertSame( array( 1 ), $candidates[0]['snapshot_ids'] );
		$this->assertSame( 'storage_cap', $candidates[0]['reason'] );
	}

	/**
	 * An incremental chain prunes only as a whole: the candidate carries
	 * every snapshot id in the chain.
	 *
	 * @return void
	 */
	public function test_chain_prunes_as_a_whole() {
		$policy = new MainWP_Backup_Retention_Policy( 1, 0, 0 );

		$units = array(
			$this->make_unit( array( 1, 2, 3 ), 30 ),
			$this->make_unit( array( 4 ), 10 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertCount( 1, $candidates );
		$this->assertSame( array( 1, 2, 3 ), $candidates[0]['snapshot_ids'], 'A base must never be pruned out from under its children.' );
	}

	/**
	 * The last restorable unit survives every policy.
	 *
	 * @return void
	 */
	public function test_last_unit_survives_age_policy() {
		$policy = new MainWP_Backup_Retention_Policy( 0, 30, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 400 ),
		);

		$this->assertSame( array(), $policy->evaluate( $units, $this->now ) );
	}

	/**
	 * When every unit violates the policy, the newest one survives.
	 *
	 * @return void
	 */
	public function test_newest_unit_survives_when_all_expired() {
		$policy = new MainWP_Backup_Retention_Policy( 0, 30, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 400 ),
			$this->make_unit( array( 2 ), 300 ),
			$this->make_unit( array( 3 ), 200 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertSame( array( 1, 2 ), $this->pruned_ids( $candidates ) );
		$this->assertNotContains( 3, $this->pruned_ids( $candidates ), 'The newest unit is the last restorable backup.' );
	}

	/**
	 * A count of zero with retention_count 1 against a single unit keeps it.
	 *
	 * @return void
	 */
	public function test_count_one_keeps_single_unit() {
		$policy = new MainWP_Backup_Retention_Policy( 1, 0, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 30 ),
		);

		$this->assertSame( array(), $policy->evaluate( $units, $this->now ) );
	}

	/**
	 * Incomplete units are never prune candidates and never count as the
	 * protected last unit.
	 *
	 * @return void
	 */
	public function test_incomplete_units_excluded_from_candidates_and_protection() {
		$policy = new MainWP_Backup_Retention_Policy( 0, 30, 0 );

		$units = array(
			$this->make_unit( array( 1, 2 ), 400, 1000, false ),
			$this->make_unit( array( 3 ), 400 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertSame( array(), $candidates, 'The only complete unit is protected; the broken chain is not a candidate.' );
	}

	/**
	 * Age marks before count: a unit expired by age does not also burn a
	 * slot in the count window, and is reported once.
	 *
	 * @return void
	 */
	public function test_age_runs_before_count_without_double_marking() {
		$policy = new MainWP_Backup_Retention_Policy( 2, 90, 0 );

		$units = array(
			$this->make_unit( array( 1 ), 120 ),
			$this->make_unit( array( 2 ), 20 ),
			$this->make_unit( array( 3 ), 10 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'age', $candidates[0]['reason'] );
		$this->assertSame( array( 1 ), $this->pruned_ids( $candidates ) );
	}

	/**
	 * The storage cap only counts surviving units: bytes already marked by
	 * age or count do not push newer units over the cap.
	 *
	 * @return void
	 */
	public function test_storage_cap_ignores_already_marked_units() {
		$policy = new MainWP_Backup_Retention_Policy( 2, 0, 250 );

		$units = array(
			$this->make_unit( array( 1 ), 30, 200 * MB_IN_BYTES ),
			$this->make_unit( array( 2 ), 20, 100 * MB_IN_BYTES ),
			$this->make_unit( array( 3 ), 10, 100 * MB_IN_BYTES ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		// Count=2 marks unit 1; the remaining 200MB fit the 250MB cap.
		$this->assertSame( array( 1 ), $this->pruned_ids( $candidates ) );
		$this->assertSame( 'count', $candidates[0]['reason'] );
	}

	/**
	 * Candidates come back oldest first so the executor prunes in a stable
	 * order under a budget.
	 *
	 * @return void
	 */
	public function test_candidates_ordered_oldest_first() {
		$policy = new MainWP_Backup_Retention_Policy( 1, 0, 0 );

		$units = array(
			$this->make_unit( array( 2 ), 20 ),
			$this->make_unit( array( 1 ), 30 ),
			$this->make_unit( array( 3 ), 10 ),
		);

		$candidates = $policy->evaluate( $units, $this->now );

		$this->assertSame( array( array( 1 ), array( 2 ) ), array_column( $candidates, 'snapshot_ids' ) );
	}
	/** Minimum protection survives count, age and storage pressure until its exact boundary. */
	public function test_minimum_protection_and_restorable_coverage() {
		$policy                 = new MainWP_Backup_Retention_Policy( 1, 1, 1 );
		$old                    = $this->make_unit( array( 1 ), 100, 10 * MB_IN_BYTES );
		$old['protected_until'] = $this->now + 1;
		$new                    = $this->make_unit( array( 2 ), 10, 10 * MB_IN_BYTES );
		$this->assertSame( array(), $policy->evaluate( array( $old, $new ), $this->now ) );
		$this->assertSame( array( 1 ), $this->pruned_ids( $policy->evaluate( array( $old, $new ), $this->now + 1 ) ) );
		$old['protected_until'] = 0;
		$new['is_restorable']   = false;
		$this->assertSame( array(), $policy->evaluate( array( $old, $new ), $this->now ), 'Newest complete and newest restorable units both survive.' );
	}
	/** Damaged units do not displace restorable coverage; all-damaged tasks stay bounded. */
	public function test_restorable_count_window_and_all_damaged_fallback() {
		$policy = new MainWP_Backup_Retention_Policy( 2, 0, 0 );
		$units  = array();
		foreach ( array( true, true, false, true, false ) as $index => $restorable ) {
			$unit                  = $this->make_unit( array( $index + 1 ), 50 - 10 * $index );
			$unit['is_restorable'] = $restorable;
			$units[]               = $unit;
		}
		$this->assertSame( array( 1 ), $this->pruned_ids( $policy->evaluate( $units, $this->now ) ) );
		foreach ( $units as &$unit ) {
			$unit['is_restorable'] = false;
		}
		unset( $unit );
		$this->assertSame( array( 1, 2, 3 ), $this->pruned_ids( $policy->evaluate( $units, $this->now ) ) );
	}
}
