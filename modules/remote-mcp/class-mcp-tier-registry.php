<?php
/**
 * Remote MCP tier registry.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one accessor over CORE_TIERS, EXTENSION_TIERS and declared contracts, the `tier_registry`
 * slot.
 *
 * Precedence: the core table; an audited extension entry with an explicit tier, which no
 * declared contract overrides; an audited `uncontracted` entry, which a declared contract
 * replaces; a declared contract, never `write`; valid read annotations (`source: annotations`);
 * valid write annotations (`source: uncontracted`, tier `dashboard`); otherwise null.
 */
class MCP_Tier_Registry implements Tier_Registry {

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Installed versions by release key, read once per request.
     *
     * @var array
     */
    private $versions = array();

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Entry for an ability.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    public function entry( string $ability ): ?array {
        $entry = $this->resolve( $ability );
        if ( null !== $entry && 'refused' !== $entry['source'] && in_array( $ability, MCP_Tool_Catalog::AUTHORIZATION_ABILITIES, true ) ) {
            // Nothing that changes who may do what runs on a chat confirmation, except under
            // Hands-off, which the scope policy applies afterwards.
            $entry['tier']        = 'dashboard';
            $entry['conditional'] = null;
            if ( 'annotations' === $entry['source'] ) {
                $entry['source']  = 'uncontracted';
                $entry['adapter'] = 'atomic';
            }
        }
        return $entry;
    }

    /**
     * Resolve by precedence.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    private function resolve( string $ability ): ?array {
        $core = MCP_Tool_Catalog::core_entry( $ability );
        if ( null !== $core ) {
            return $core;
        }

        $audit = MCP_Tool_Catalog::extension_entry( $ability );
        if ( null !== $audit ) {
            return $this->release_checked( $ability, $this->audited( $ability, $audit ) );
        }

        $live = $this->live( $ability );
        if ( null === $live ) {
            return null;
        }
        $annotations = MCP_Tool_Catalog::annotations( $live );
        if ( 0 === strpos( $ability, MCP_Tool_Catalog::NAMESPACE_PREFIX ) && in_array( (string) $live->get_category(), MCP_Tool_Catalog::CORE_CATEGORIES, true ) ) {
            // A core ability missing from the table, never tiered by its own annotations.
            return MCP_Tool_Catalog::entry( 'core', 'dashboard', 'none', 'direct', 'none', null, array(), array(), 'untiered_core' );
        }
        $declared = $this->declared( $ability );
        if ( null !== $declared ) {
            return $declared;
        }
        if ( MCP_Tool_Catalog::is_valid_read( $annotations ) ) {
            return MCP_Tool_Catalog::entry( 'annotations', 'read', 'none', 'direct', 'none', null, array(), array(), 'valid_read_annotations' );
        }
        if ( MCP_Tool_Catalog::is_valid_write( $annotations ) ) {
            return $this->uncontracted( array(), array(), 'none', 'uncontracted' );
        }
        return null;
    }

    /**
     * An audited extension entry after a declared contract and the live annotations had their say.
     *
     * @param string $ability Ability name.
     * @param array  $audit   Audited table entry.
     * @return array|null
     */
    private function audited( string $ability, array $audit ): ?array {
        if ( 'uncontracted' !== $audit['source'] ) {
            return $audit;
        }
        $declared = $this->declared( $ability );
        if ( null !== $declared ) {
            return $declared;
        }
        // An `uncontracted` table outcome only opens the uncontracted path, which still needs
        // valid write annotations.
        $live = $this->live( $ability );
        if ( null === $live || ! MCP_Tool_Catalog::is_valid_write( MCP_Tool_Catalog::annotations( $live ) ) ) {
            return null;
        }
        $entry                  = $this->uncontracted( $audit['sensitive'], $audit['gate_supplied'], $audit['resolver'], (string) $audit['reason'] );
        $entry['adapter']       = $audit['adapter'];
        $entry['min_version']   = $audit['min_version'];
        $entry['gate_supplied'] = $audit['gate_supplied'];
        return $entry;
    }

    /**
     * The uncontracted path: `dashboard`, inline, plan-less.
     *
     * @param array  $sensitive     Sensitive fields.
     * @param array  $gate_supplied Gate-supplied fields.
     * @param string $resolver      Resolver type.
     * @param string $reason        Reason code.
     * @return array
     */
    private function uncontracted( array $sensitive, array $gate_supplied, string $resolver, string $reason ): array {
        $entry             = MCP_Tool_Catalog::entry( 'uncontracted', 'dashboard', $resolver, 'atomic', 'none', null, $sensitive, $gate_supplied, $reason );
        $entry['contract'] = null;
        return $entry;
    }

    /**
     * A declared contract as an entry, or null when there is none or it claims a tier a declared
     * contract can't have.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    private function declared( string $ability ): ?array {
        $contract = $this->services->get( 'control_contract' )->declared_contract( $ability );
        if ( ! is_array( $contract ) || ! in_array( $contract['tier'] ?? null, array( OAuth_Store::TIER_CONFIRM, OAuth_Store::TIER_DASHBOARD ), true ) ) {
            return null;
        }
        $list = function ( $value ): array {
            return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
        };
        return array(
            'source'             => 'declared',
            'tier'               => $contract['tier'],
            'conditional'        => null,
            'contract'           => $contract,
            'resolver'           => in_array( $contract['resolver'] ?? null, Remote_MCP_Plan_Types::RESOLVERS, true ) ? $contract['resolver'] : 'none',
            'adapter'            => in_array( $contract['adapter'] ?? null, Remote_MCP_Plan_Types::ADAPTERS, true ) ? $contract['adapter'] : 'atomic',
            'sensitive'          => $list( $contract['sensitive'] ?? array() ),
            'deferred'           => true === ( $contract['deferred'] ?? false ),
            'credential_bearing' => true === ( $contract['credential_bearing'] ?? false ),
            'gate_supplied'      => $list( $contract['gate_supplied'] ?? array() ),
            'compare_fields'     => $list( $contract['compare_fields'] ?? array() ),
            'min_version'        => null,
            'inert'              => false,
            'reason'             => 'declared_contract',
        );
    }

    /**
     * Mark an entry inert until its extension's first release with these abilities is installed.
     *
     * @param string     $ability Ability name.
     * @param array|null $entry   Entry.
     * @return array|null
     */
    private function release_checked( string $ability, ?array $entry ): ?array {
        $release = MCP_Tool_Catalog::extension_release( $ability );
        if ( null === $entry || null === $release ) {
            return $entry;
        }
        list( $plugin, $minimum ) = MCP_Tool_Catalog::EXTENSION_RELEASES[ $release ];
        if ( null === $minimum ) {
            $entry['inert']  = true;
            $entry['reason'] = 'no_release';
            return $entry;
        }
        if ( ! isset( $this->versions[ $release ] ) ) {
            $this->versions[ $release ] = (string) $this->extension_version( $plugin );
        }
        $installed = $this->versions[ $release ];
        if ( '' === $installed || version_compare( $installed, $minimum, '<' ) ) {
            $entry['inert']  = true;
            $entry['reason'] = 'min_version';
        }
        return $entry;
    }

    /**
     * Installed version of an extension, from its plugin header.
     *
     * @param string $plugin Plugin file relative to the plugins directory.
     * @return string|null
     */
    protected function extension_version( string $plugin ): ?string {
        $file = WP_PLUGIN_DIR . '/' . $plugin;
        if ( ! is_readable( $file ) ) {
            return null;
        }
        $data = get_file_data( $file, array( 'Version' => 'Version' ) );
        return isset( $data['Version'] ) && '' !== $data['Version'] ? (string) $data['Version'] : null;
    }

    /**
     * The registered ability, or null.
     *
     * @param string $ability Ability name.
     * @return \WP_Ability|null
     */
    private function live( string $ability ) {
        // wp_get_ability() reports an unknown name as incorrect usage, so ask first.
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability ) ) {
            return null;
        }
        $live = wp_get_ability( $ability );
        return $live instanceof \WP_Ability ? $live : null;
    }
}
