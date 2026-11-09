<?php
/**
 * Remote MCP target resolver.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the entity site sets are read fresh on every call; a cached set would let a site that joined after approval slip into a job.

/**
 * Turns projected arguments into explicit site ids under the connection user's rights. The
 * resolver type comes only from the tier registry entry.
 *
 * Anything that can't be resolved completely is refused rather than narrowed: a domain that
 * matches no accessible site, a client or tag the user can't reach, or an empty fleet. A plan
 * built from a partial resolution would run on a different set of sites than the model named.
 */
class MCP_Target_Resolver implements Target_Resolver {

    /**
     * Module services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Resolve targets.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Projected arguments.
     * @return array|null `site_ids` and `entity`, or null when refused.
     */
    public function resolve( Remote_MCP_Context $context, string $ability, array $arguments ): ?array {
        $entry = $this->services->get( 'tier_registry' )->entry( $ability );
        $type  = is_array( $entry ) ? ( $entry['resolver'] ?? null ) : null;
        if ( ! is_string( $type ) || ! in_array( $type, Remote_MCP_Plan_Types::RESOLVERS, true ) ) {
            return null;
        }
        if ( 'none' === $type ) {
            return array(
                'site_ids' => null,
                'entity'   => null,
            );
        }
        return self::as_user(
            $context->user_id,
            function () use ( $type, $ability, $arguments ) {
                switch ( $type ) {
                    case 'site':
                        return $this->site( $arguments );
                    case 'sites':
                        return $this->sites( $arguments, self::requires_listed_sites( $ability ) );
                    case 'client':
                        return $this->client( $arguments );
                    default:
                        return $this->tag( $arguments );
                }
            }
        );
    }

    /**
     * Whether the current user can reach one site that still exists.
     *
     * @param int|string $identifier Site id or domain.
     * @return int|null Site id, or null.
     */
    public static function accessible_site( $identifier ): ?int {
        if ( ! is_int( $identifier ) && ! ( is_string( $identifier ) && '' !== trim( $identifier ) ) ) {
            return null;
        }
        $site = MainWP_Abilities_Util::resolve_site( $identifier );
        if ( is_wp_error( $site ) || ! is_object( $site ) || true !== MainWP_Abilities_Util::check_site_access( $site ) ) {
            return null;
        }
        $id = (int) $site->id;
        return $id > 0 ? $id : null;
    }

    /**
     * Run a callback with a user as the current user, restoring the previous one.
     *
     * @param int      $user_id  User.
     * @param callable $callback Callback.
     * @return mixed
     */
    public static function as_user( int $user_id, callable $callback ) {
        $previous = get_current_user_id();
        if ( $previous !== $user_id ) {
            wp_set_current_user( $user_id );
        }
        try {
            return $callback();
        } finally {
            if ( $previous !== $user_id ) {
                wp_set_current_user( $previous );
            }
        }
    }

    /**
     * One site.
     *
     * @param array $arguments Arguments.
     * @return array|null
     */
    private function site( array $arguments ): ?array {
        $identifier = $arguments['site_id_or_domain'] ?? ( $arguments['site_id'] ?? null );
        $id         = self::accessible_site( $identifier );
        return null === $id ? null : self::targets( array( $id ), null );
    }

    /**
     * A site list, where empty means every site the user can reach.
     * It reads the same inputs the core site-list abilities do: `site_ids_or_domains`, then
     * `site_ids`, then every site, minus `exclude_ids`. Reading fewer would widen the call, because
     * the adapter pins the resolved ids into `site_ids_or_domains`.
     *
     * An ability that refuses an empty list (disconnect-sites, suspend-sites) is refused here too:
     * expanding it to the fleet and pinning those ids would run the select-all the ability rejects.
     *
     * @param array $arguments   Arguments.
     * @param bool  $listed_only The ability refuses an empty list.
     * @return array|null
     */
    private function sites( array $arguments, bool $listed_only = false ): ?array {
        foreach ( array( 'site_ids_or_domains', 'site_ids', 'exclude_ids' ) as $key ) {
            if ( isset( $arguments[ $key ] ) && ! is_array( $arguments[ $key ] ) ) {
                return null;
            }
        }
        $identifiers = $arguments['site_ids_or_domains'] ?? array();
        if ( empty( $identifiers ) ) {
            $identifiers = $arguments['site_ids'] ?? array();
        }
        if ( empty( $identifiers ) ) {
            if ( $listed_only ) {
                return null;
            }
            $identifiers = $this->fleet();
        }
        $excluded = array_map( 'absint', $arguments['exclude_ids'] ?? array() );
        $ids      = array();
        foreach ( $identifiers as $identifier ) {
            $id = self::accessible_site( $identifier );
            if ( null === $id ) {
                return null;
            }
            if ( ! in_array( $id, $excluded, true ) ) {
                $ids[] = $id;
            }
        }
        return empty( $ids ) ? null : self::targets( $ids, null );
    }

    /**
     * Whether an ability's input schema requires at least one entry in `site_ids_or_domains`.
     *
     * @param string $ability Ability name.
     * @return bool
     */
    private static function requires_listed_sites( string $ability ): bool {
        $schema = MCP_Control_Contracts::input_schema( $ability );
        $list   = $schema['properties']['site_ids_or_domains'] ?? null;
        return is_array( $list ) && (int) ( $list['minItems'] ?? 0 ) > 0;
    }

    /**
     * Every site the current user can reach, read the way core's own "empty means all" does.
     *
     * @return int[]
     */
    private function fleet(): array {
        $sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );
        if ( ! is_array( $sites ) ) {
            return array();
        }
        $ids = array();
        foreach ( $sites as $site ) {
            if ( is_object( $site ) && isset( $site->id ) ) {
                $ids[] = (int) $site->id;
            }
        }
        // The DB layer skips scoping under cron and REST, so the scope filter is applied here too.
        $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
        return 'all' === $allowed ? $ids : array_values( array_intersect( $ids, (array) $allowed ) );
    }

    /**
     * One client and every site it holds now: an entity action acts on all of them, so the
     * approval shows all of them and the job checks the same set before it runs.
     *
     * @param array $arguments Arguments.
     * @return array|null
     */
    private function client( array $arguments ): ?array {
        global $wpdb;
        $identifier = $arguments['client_id_or_email'] ?? ( $arguments['client_id'] ?? null );
        if ( ! is_int( $identifier ) && ! ( is_string( $identifier ) && '' !== $identifier ) ) {
            return null;
        }
        $client = MainWP_Abilities_Util::resolve_client( $identifier );
        if ( is_wp_error( $client ) || ! is_object( $client ) || true !== MainWP_Abilities_Util::check_client_access( $client ) ) {
            return null;
        }
        $client_id = (int) $client->client_id;
        $ids       = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE client_id = %d', $wpdb->prefix . 'mainwp_wp', $client_id ) );
        if ( '' !== $wpdb->last_error || ! is_array( $ids ) ) {
            return null;
        }
        // Entity actions affect every member, including any the approval page would disclose.
        $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
        if ( 'all' !== $allowed && array_diff( $ids, (array) $allowed ) ) {
            return null;
        }
        return self::targets(
            $ids,
            array(
                'type' => 'client',
                'id'   => $client_id,
            )
        );
    }

    /**
     * One tag and every site carrying it now.
     *
     * @param array $arguments Arguments.
     * @return array|null
     */
    private function tag( array $arguments ): ?array {
        global $wpdb;
        $tag_id = $arguments['tag_id'] ?? null;
        if ( ! is_int( $tag_id ) || $tag_id <= 0 ) {
            return null;
        }
        $tag = MainWP_Abilities_Util::resolve_tag( $tag_id );
        if ( is_wp_error( $tag ) || true !== MainWP_Abilities_Util::check_tag_access( $tag ) ) {
            return null;
        }
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT wp.id FROM %i wp INNER JOIN %i wg ON wg.wpid = wp.id WHERE wg.groupid = %d',
                $wpdb->prefix . 'mainwp_wp',
                $wpdb->prefix . 'mainwp_wp_group',
                $tag_id
            )
        );
        if ( '' !== $wpdb->last_error || ! is_array( $ids ) ) {
            return null;
        }
        $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
        if ( 'all' !== $allowed && array_diff( $ids, (array) $allowed ) ) {
            return null;
        }
        return self::targets(
            $ids,
            array(
                'type' => 'tag',
                'id'   => $tag_id,
            )
        );
    }

    /**
     * Normalized targets: positive, unique, sorted ids.
     *
     * @param array      $ids    Site ids.
     * @param array|null $entity Entity.
     * @return array
     */
    private static function targets( array $ids, ?array $entity ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );
        sort( $ids );
        return array(
            'site_ids' => $ids,
            'entity'   => $entity,
        );
    }
}
