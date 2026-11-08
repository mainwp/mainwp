<?php
/**
 * Designated file of a test slot: an implementing class loaded from where the registry expects it.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Remote_MCP;

// phpcs:disable

/**
 * A permissive gate: everything runs.
 */
class Remote_MCP_Test_Probe_Gate implements Approval_Gate {
	public function __construct( Remote_MCP_Services $services ) {
	}
	public function check( Remote_MCP_Context $context, string $ability, array $arguments ): array {
		return array( 'decision' => 'run', 'tier' => 'write', 'reason' => null, 'approval_id' => null, 'plan' => null );
	}
	public function deliver( Remote_MCP_Context $context, array $decision ): array {
		return array();
	}
	public function consume( Remote_MCP_Context $context, array $decision ): ?Call_Plan {
		return null;
	}
}
