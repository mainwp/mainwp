<?php
/**
 * Add-on update batch pacing observation.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Record pacing without delaying the regression suite.
 *
 * @param int $seconds Requested pause.
 * @return int Remaining seconds.
 */
function sleep( $seconds ) {
	if ( isset( $GLOBALS['mwp2013_pauses'] ) ) {
		$GLOBALS['mwp2013_pauses'][] = $seconds;
		$GLOBALS['mwp2013_events'][] = 'sleep';
		return 0;
	}
	return \sleep( $seconds );
}
