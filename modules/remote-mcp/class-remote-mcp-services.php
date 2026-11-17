<?php
/**
 * Remote MCP service registry.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one mechanism that replaces a slot's stub with its implementing class.
 *
 * Each slot names an interface and the class that implements it. The class is picked up from its
 * designated file, `class-<name>.php` in the module directory. It must implement the slot's
 * interface and take this registry as its only constructor argument. Until the file exists, or
 * when it doesn't implement the interface or throws while constructing, the slot holds its
 * fail-closed stub.
 *
 * The class is used only when PHP says it was declared in that file. Another plugin could declare
 * or alias a correctly typed class under the implementing class's name before the first lookup,
 * and a name check alone would hand it the approval gate.
 *
 * There is deliberately no WordPress filter here either: a filter would let any plugin swap the
 * approval gate or the client lookup. A new slot, or an implementing class under another name,
 * needs a change to SLOTS. The class is not final only so the tests can add probe slots.
 */
class Remote_MCP_Services {

    /**
     * Slot => [ interface, implementing class (short name in this namespace) ].
     *
     * @var array
     */
    const SLOTS = array(
        'known_clients'     => array( Known_Clients::class, 'OAuth_Known_Clients' ),
        'scope_policy'      => array( Scope_Policy::class, 'MCP_Scope_Policy' ),
        'tool_executor'     => array( Tool_Executor::class, 'MCP_Tool_Executor' ),
        'tier_registry'     => array( Tier_Registry::class, 'MCP_Tier_Registry' ),
        'control_contract'  => array( Control_Contract::class, 'MCP_Control_Contracts' ),
        'approval_gate'     => array( Approval_Gate::class, 'MCP_Approval_Gate' ),
        'target_resolver'   => array( Target_Resolver::class, 'MCP_Target_Resolver' ),
        'execution_adapter' => array( Execution_Adapter::class, 'MCP_Execution_Adapter' ),
        'job_runner'        => array( Job_Runner::class, 'MCP_Job_Runner' ),
        'enable_check'      => array( Enable_Check::class, 'Remote_MCP_Self_Test' ),
        'route_as_metadata' => array( Route_Handler::class, 'OAuth_Well_Known' ),
        'route_openid'      => array( Route_Handler::class, 'OAuth_Openid_Configuration' ),
        'route_prm'         => array( Route_Handler::class, 'OAuth_Protected_Resource' ),
        'route_authorize'   => array( Route_Handler::class, 'OAuth_Authorize_Endpoint' ),
        'route_token'       => array( Route_Handler::class, 'OAuth_Token_Endpoint' ),
        'route_register'    => array( Route_Handler::class, 'OAuth_Registration_Endpoint' ),
        'route_mcp'         => array( Route_Handler::class, 'MCP_Endpoint' ),
        'route_approve'     => array( Route_Handler::class, 'Remote_MCP_Approval_Page' ),
        'hooks_audit'       => array( Hook_Provider::class, 'Remote_MCP_Audit' ),
        'hooks_jobs'        => array( Hook_Provider::class, 'MCP_Job_Cron' ),
        'hooks_admin'       => array( Hook_Provider::class, 'Remote_MCP_Admin' ),
        'hooks_revocation'  => array( Hook_Provider::class, 'OAuth_Revocation' ),
    );

    /**
     * Slots the front controller dispatches to.
     *
     * @var array
     */
    const ROUTE_SLOTS = array( 'route_as_metadata', 'route_openid', 'route_prm', 'route_authorize', 'route_approve', 'route_token', 'route_register', 'route_mcp' );

    /**
     * Slots whose register_hooks() the wiring calls at boot.
     *
     * @var array
     */
    const HOOK_SLOTS = array( 'hooks_audit', 'hooks_jobs', 'hooks_admin', 'hooks_revocation' );

    /**
     * Resolved implementations.
     *
     * @var array
     */
    private $resolved = array();

    /**
     * Slots holding their stub.
     *
     * @var array
     */
    private $stubbed = array();

    /**
     * Implementations supplied by the caller, by slot. Tests use this; the wiring passes none.
     *
     * @var array
     */
    private $overrides;

    /**
     * URLs for this request.
     *
     * @var Remote_MCP_Urls
     */
    private $urls;

    /**
     * Store, built on first use.
     *
     * @var OAuth_Store|null
     */
    private $store;

    /**
     * Store for installed-module maintenance, independent of the serving store.
     *
     * @var OAuth_Store|null
     */
    private $installed_store;

    /**
     * Constructor.
     *
     * @param array                $overrides Slot => implementation; one that doesn't implement the slot's interface is ignored.
     * @param Remote_MCP_Urls|null $urls      URLs, read from the stored home when null.
     */
    public function __construct( array $overrides = array(), ?Remote_MCP_Urls $urls = null ) {
        $this->overrides = $overrides;
        $this->urls      = $urls ? $urls : new Remote_MCP_Urls();
    }

    /**
     * The implementation of a slot.
     *
     * @param string $slot Slot.
     * @return object An instance of the slot's interface.
     * @throws \InvalidArgumentException On an unknown slot.
     */
    public function get( string $slot ): object {
        if ( ! isset( static::SLOTS[ $slot ] ) ) {
            throw new \InvalidArgumentException( 'Unknown remote MCP slot.' );
        }
        if ( ! isset( $this->resolved[ $slot ] ) ) {
            $this->resolved[ $slot ] = $this->resolve( $slot );
        }
        return $this->resolved[ $slot ];
    }

    /**
     * Whether a slot holds its stub.
     *
     * @param string $slot Slot.
     * @return bool
     */
    public function is_stub( string $slot ): bool {
        $this->get( $slot );
        return isset( $this->stubbed[ $slot ] );
    }

    /**
     * URLs.
     *
     * @return Remote_MCP_Urls
     */
    public function urls(): Remote_MCP_Urls {
        return $this->urls;
    }

    /**
     * The store, bound to the canonical MCP resource. Null on an insecure or unusable home, where
     * no token can be minted or checked.
     *
     * @return OAuth_Store|null
     */
    public function store(): ?OAuth_Store {
        if ( null === $this->store ) {
            $resource = $this->urls->mcp_url();
            if ( null === $resource ) {
                return null;
            }
            $this->store = new OAuth_Store( new OAuth_Token_Hasher(), $resource );
        }
        return $this->store;
    }

    /**
     * Store for invalidation, revocation and panel listings whenever the db-version marker exists,
     * even when HTTP or connector availability prevents serving MCP. Password changes, home changes
     * and user removal must still invalidate existing connections in that state.
     *
     * Must never be used to serve or authorize a request. An unusable home binds this separate
     * instance to an empty resource; invalidation and listings do not depend on resource hashes.
     *
     * @return OAuth_Store|null Null before installation or after its marker is removed.
     */
    public function installed_store(): ?OAuth_Store {
        if ( ! Remote_MCP_Install::is_installed() ) {
            return null;
        }
        if ( null === $this->installed_store ) {
            $this->installed_store = new OAuth_Store( new OAuth_Token_Hasher(), $this->urls->mcp_url() ?? '' );
        }
        return $this->installed_store;
    }

    /**
     * Resolve a slot: a valid override, else the implementing class, else the stub.
     *
     * @param string $slot Slot.
     * @return object
     */
    private function resolve( string $slot ): object {
        list( $interface, $short ) = static::SLOTS[ $slot ];
        if ( array_key_exists( $slot, $this->overrides ) ) {
            if ( $this->overrides[ $slot ] instanceof $interface ) {
                return $this->overrides[ $slot ];
            }
            return $this->stub( $slot );
        }
        $class = __NAMESPACE__ . '\\' . $short;
        $file  = realpath( $this->slot_file( $short ) );
        if ( false === $file ) {
            return $this->stub( $slot );
        }
        // Load the designated file only when nothing holds the name yet: requiring it over a class
        // that already exists would be a fatal redeclaration, and that class is refused below anyway.
        if ( ! class_exists( $class, false ) && ! interface_exists( $class, false ) ) {
            require_once $file;
        }
        if ( ! class_exists( $class, false ) ) {
            return $this->stub( $slot );
        }
        $reflection = new \ReflectionClass( $class );
        // An alias resolves to its target, whose name and file differ.
        if ( 0 !== strcasecmp( $reflection->getName(), $class ) || realpath( (string) $reflection->getFileName() ) !== $file ) {
            return $this->stub( $slot );
        }
        if ( ! $reflection->implementsInterface( $interface ) || ! $reflection->isInstantiable() ) {
            return $this->stub( $slot );
        }
        try {
            return new $class( $this );
        } catch ( \Throwable $e ) {
            return $this->stub( $slot );
        }
    }

    /**
     * Designated file of an implementing class: the module autoloader's mapping.
     *
     * @param string $short Implementing class short name.
     * @return string
     */
    protected function slot_file( string $short ): string {
        return __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
    }

    /**
     * Record and return a slot's stub.
     *
     * @param string $slot Slot.
     * @return object
     */
    private function stub( string $slot ): object {
        $this->stubbed[ $slot ] = true;
        return Remote_MCP_Stubs::for_interface( static::SLOTS[ $slot ][0] );
    }
}
