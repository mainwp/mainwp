<?php
/**
 * Remote MCP fail-closed stubs.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter -- one anonymous class per slot; each method's contract is documented on its interface, and a stub ignores its inputs by design.

/**
 * The implementation every slot has until its implementing class loads. Each stub refuses
 * execution and every lookup denies, so a missing implementing class, or one that fails to load,
 * can never open a route, a client or a write.
 */
final class Remote_MCP_Stubs {

    /**
     * Reason code every stub refusal carries.
     *
     * @var string
     */
    const REASON = 'not_available';

    /**
     * Stub for a slot.
     *
     * @param string $slot Slot from Remote_MCP_Services::SLOTS.
     * @return object
     * @throws \InvalidArgumentException On an unknown slot.
     */
    public static function for_slot( string $slot ): object {
        if ( ! isset( Remote_MCP_Services::SLOTS[ $slot ] ) ) {
            throw new \InvalidArgumentException( 'Unknown remote MCP slot.' );
        }
        return self::for_interface( Remote_MCP_Services::SLOTS[ $slot ][0] );
    }

    /**
     * Stub for an interface.
     *
     * @param string $contract Interface name.
     * @return object
     * @throws \InvalidArgumentException On an interface without a stub.
     */
    public static function for_interface( string $contract ): object {
        switch ( $contract ) {
            case Route_Handler::class:
                return self::route();
            case Hook_Provider::class:
                return self::hooks();
            case Known_Clients::class:
                return new class() implements Known_Clients {
                    public function lookup( string $client_id, bool $fetch = false ): ?array {
                        return null;
                    }
                    public function redirect_allowed( array $client, string $redirect_uri ): bool {
                        return false;
                    }
                    public function validate_redirect( string $kind, string $redirect_uri ): bool {
                        return false;
                    }
                };
            case Scope_Policy::class:
                return new class() implements Scope_Policy {
                    public function effective_scopes( string $token_scope, string $grant_scope ): array {
                        return array();
                    }
                    public function effective_tier( Remote_MCP_Context $context, string $ability, array $arguments ): ?string {
                        return null;
                    }
                };
            case Tool_Executor::class:
                return new class() implements Tool_Executor {
                    public function list_tools( Remote_MCP_Context $context ): array {
                        return array();
                    }
                    public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array {
                        return Remote_MCP_Stubs::refusal_result();
                    }
                    public function catalog(): array {
                        return array();
                    }
                    public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
                        return array(
                            'ok'     => false,
                            'reason' => Remote_MCP_Stubs::REASON,
                            'result' => null,
                            'queued' => false,
                        );
                    }
                };
            case Control_Contract::class:
                return new class() implements Control_Contract {
                    public function contract_for( Remote_MCP_Context $context, string $ability ): ?array {
                        return null;
                    }
                    public function declared_contract( string $ability ): ?array {
                        return null;
                    }
                    public function refresh_native_token( Call_Plan $plan, int $index, int $user_id ): ?array {
                        return null;
                    }
                };
            case Tier_Registry::class:
                return new class() implements Tier_Registry {
                    public function entry( string $ability ): ?array {
                        return null;
                    }
                };
            case Target_Resolver::class:
                return new class() implements Target_Resolver {
                    public function resolve( Remote_MCP_Context $context, string $ability, array $arguments ): ?array {
                        return null;
                    }
                };
            case Execution_Adapter::class:
                return new class() implements Execution_Adapter {
                    public function build( Remote_MCP_Context $context, string $ability, array $arguments, ?array $targets, ?array $preview ): ?Call_Plan {
                        return null;
                    }
                    public function collect_preview( Remote_MCP_Context $context, string $ability, array $arguments, ?array $target_ids ): ?array {
                        return null;
                    }
                };
            case Approval_Gate::class:
                return new class() implements Approval_Gate {
                    public function check( Remote_MCP_Context $context, string $ability, array $arguments ): array {
                        return array(
                            'decision'    => 'refused',
                            'tier'        => null,
                            'reason'      => Remote_MCP_Stubs::REASON,
                            'approval_id' => null,
                            'plan'        => null,
                        );
                    }
                    public function deliver( Remote_MCP_Context $context, array $decision ): array {
                        // Every decision, `run` included: nothing is consumed, enqueued or executed.
                        return Remote_MCP_Stubs::refusal_result();
                    }
                    public function consume( Remote_MCP_Context $context, array $decision ): ?Call_Plan {
                        return null;
                    }
                };
            case Job_Runner::class:
                return new class() implements Job_Runner {
                    public function enqueue( Remote_MCP_Context $context, Call_Plan $plan, ?int $approval_id ): ?int {
                        return null;
                    }
                    public function tick( int $job_id ): bool {
                        return false;
                    }
                    public function cancel( int $job_id, string $reason ): bool {
                        return false;
                    }
                    public function status( int $job_id, int $user_id ): ?array {
                        return null;
                    }
                };
            case Enable_Check::class:
                return new class() implements Enable_Check {
                    public function blocking_reasons(): array {
                        return array( 'self_test_unavailable' );
                    }
                };
        }
        throw new \InvalidArgumentException( 'No stub for this interface.' );
    }

    /**
     * Tool result every refusing stub returns.
     *
     * @return array CallToolResult.
     */
    public static function refusal_result(): array {
        return array(
            'content' => array(
                array(
                    'type' => 'text',
                    'text' => 'This action is not available on this Dashboard yet.',
                ),
            ),
            'isError' => true,
        );
    }

    /**
     * Route stub: 503 with no-store, never a 2xx, never a challenge.
     *
     * @return Route_Handler
     */
    private static function route(): Route_Handler {
        return new class() implements Route_Handler {
            public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
                return Remote_MCP_Response::json( 503, array( 'error' => 'temporarily_unavailable' ) );
            }
        };
    }

    /**
     * Hook-provider stub: registers nothing.
     *
     * @return Hook_Provider
     */
    private static function hooks(): Hook_Provider {
        return new class() implements Hook_Provider {
            public function register_hooks( Remote_MCP_Services $services ): void {
            }
        };
    }
}
