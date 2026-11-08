<?php
/**
 * Remote MCP execution adapter.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns projected arguments, resolved targets and a preview into the frozen Call_Plan, and
 * collects the complete per-site updates preview the plan for adapter `updates` is
 * built from. The adapter type comes only from the tier registry entry.
 */
class MCP_Execution_Adapter implements Execution_Adapter {

    /**
     * The one ability every updates entry runs, whatever the approved ability was.
     *
     * @var string
     */
    const RUN_UPDATES = 'mainwp/run-updates-v1';

    /**
     * Preview item type => run-updates `types` value, in core's order.
     *
     * @var array
     */
    const UPDATE_TYPES = array(
        'core'        => 'core',
        'plugin'      => 'plugins',
        'theme'       => 'themes',
        'translation' => 'translations',
    );

    /**
     * The single-site update abilities and the one type each covers.
     *
     * @var array
     */
    const SITE_UPDATE_ABILITIES = array(
        'mainwp/update-site-core-v1'         => 'core',
        'mainwp/update-site-plugins-v1'      => 'plugins',
        'mainwp/update-site-themes-v1'       => 'themes',
        'mainwp/update-site-translations-v1' => 'translations',
    );

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
     * Build the plan.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Projected arguments.
     * @param array|null         $targets   Target_Resolver result.
     * @param array|null         $preview   Preview, for adapter `updates` the collect_preview() result.
     * @return Call_Plan|null
     */
    public function build( Remote_MCP_Context $context, string $ability, array $arguments, ?array $targets, ?array $preview ): ?Call_Plan {
        $entry = $this->services->get( 'tier_registry' )->entry( $ability );
        if ( ! is_array( $entry ) ) {
            return null;
        }
        $adapter  = $entry['adapter'] ?? null;
        $resolver = $entry['resolver'] ?? null;
        if ( ! in_array( $adapter, Remote_MCP_Plan_Types::ADAPTERS, true ) || ! in_array( $resolver, Remote_MCP_Plan_Types::RESOLVERS, true ) ) {
            return null;
        }
        $site_ids = self::site_ids( $targets );
        if ( false === $site_ids ) {
            return null;
        }
        // A native preview token belongs to one preview of one call, so its plan has one entry.
        $token_bound = is_string( $entry['contract']['execute']['native_token_field'] ?? null );
        if ( $token_bound && ! in_array( $adapter, array( 'direct', 'atomic' ), true ) ) {
            return null;
        }

        switch ( $adapter ) {
            case 'updates':
                return $this->updates_plan( $ability, $arguments, $resolver, $site_ids, $preview );
            case 'chunked_sites':
                return $this->chunked_plan( $ability, $arguments, $resolver, $site_ids );
            default:
                if ( in_array( $resolver, array( 'site', 'sites' ), true ) && empty( $site_ids ) ) {
                    return null;
                }
                $args = self::pin( $arguments, $resolver, (array) $site_ids );
                if ( 'client' === $resolver ) {
                    $args = self::pin_client( $args, $targets['entity'] ?? null );
                    if ( null === $args ) {
                        return null;
                    }
                }
                return Call_Plan::from_entries(
                    array(
                        array(
                            'ability'    => $ability,
                            'args'       => $args,
                            'target_ids' => $site_ids,
                        ),
                    )
                );
        }
    }

    /**
     * Complete per-site updates preview, merged from chunks under the core batch threshold.
     *
     * @param Remote_MCP_Context $context    Connection.
     * @param string             $ability    Updates ability.
     * @param array              $arguments  Projected arguments with the preview controls.
     * @param array|null         $target_ids Resolved site ids.
     * @return array|null
     */
    public function collect_preview( Remote_MCP_Context $context, string $ability, array $arguments, ?array $target_ids ): ?array {
        $selection = self::update_selection( $ability, $arguments );
        if ( null === $selection || empty( $target_ids ) || true !== ( $arguments['dry_run'] ?? null ) ) {
            return null;
        }
        $ids = self::site_ids(
            array(
                'site_ids' => $target_ids,
                'entity'   => null,
            )
        );
        if ( empty( $ids ) ) {
            return null;
        }
        if ( self::RUN_UPDATES === $ability ) {
            $base = $arguments;
        } else {
            $base = array(
                'dry_run'        => true,
                'types'          => $selection['types'],
                'specific_items' => $selection['specific_items'],
            );
        }

        $chunks = array();
        foreach ( array_chunk( $ids, Remote_MCP_Settings::chunk_size() ) as $chunk ) {
            $result = $this->run_preview( $context->user_id, array_merge( $base, array( 'site_ids_or_domains' => $chunk ) ) );
            if ( ! self::complete_preview( $result, $chunk ) ) {
                return null;
            }
            $chunks[] = $result;
        }
        return self::merge( $chunks );
    }

    /**
     * Run core's updates preview for one chunk as the user. Core plans from the Dashboard's
     * stored update data, so the preview makes no Child request.
     *
     * @param int   $user_id   User the preview runs as.
     * @param array $arguments Chunk arguments, `dry_run: true` included.
     * @return array|null Preview envelope, or null when the ability is missing or refuses.
     */
    protected function run_preview( int $user_id, array $arguments ): ?array {
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::RUN_UPDATES ) ) {
            return null;
        }
        $ability = wp_get_ability( self::RUN_UPDATES );
        if ( ! $ability ) {
            return null;
        }
        $result = MCP_Target_Resolver::as_user(
            $user_id,
            function () use ( $ability, $arguments ) {
                return $ability->execute( $arguments );
            }
        );
        return is_array( $result ) ? $result : null;
    }

    /**
     * The update types and items a call covers, in run-updates terms.
     *
     * @param string $ability   Ability.
     * @param array  $arguments Projected arguments.
     * @return array|null `types` (non-empty) and `specific_items`, or null when the call isn't an
     *                    update call this adapter knows.
     */
    private static function update_selection( string $ability, array $arguments ): ?array {
        $all = array_values( self::UPDATE_TYPES );
        if ( isset( self::SITE_UPDATE_ABILITIES[ $ability ] ) ) {
            $types = array( self::SITE_UPDATE_ABILITIES[ $ability ] );
            $items = 'core' === $types[0] ? array() : ( $arguments['slugs'] ?? array() );
        } elseif ( 'mainwp/update-all-v1' === $ability ) {
            // update-all treats an explicit empty `types` as nothing to do, where run-updates
            // reads it as every type.
            if ( array_key_exists( 'types', $arguments ) && array() === $arguments['types'] ) {
                return null;
            }
            $types = $arguments['types'] ?? $all;
            $items = array();
        } elseif ( self::RUN_UPDATES === $ability ) {
            $types = empty( $arguments['types'] ) ? $all : $arguments['types'];
            $items = $arguments['specific_items'] ?? array();
        } else {
            return null;
        }
        if ( ! is_array( $types ) || ! is_array( $items ) || array_diff( $types, $all ) ) {
            return null;
        }
        foreach ( $items as $item ) {
            if ( ! is_string( $item ) || '' === $item ) {
                return null;
            }
        }
        return array(
            'types'          => array_values( array_unique( $types ) ),
            'specific_items' => array_values( array_unique( $items ) ),
        );
    }

    /**
     * Updates plan: per type, sites with an identical approved slug set share an entry, chunked.
     *
     * @param string     $ability   Approved ability.
     * @param array      $arguments Projected arguments.
     * @param string     $resolver  Resolver type.
     * @param array|null $site_ids  Resolved site ids.
     * @param array|null $preview   Merged preview.
     * @return Call_Plan|null
     */
    private function updates_plan( string $ability, array $arguments, string $resolver, ?array $site_ids, ?array $preview ): ?Call_Plan {
        $selection = self::update_selection( $ability, $arguments );
        if ( null === $selection || ! in_array( $resolver, array( 'site', 'sites' ), true ) || empty( $site_ids ) || ! self::complete_preview( $preview, $site_ids ) ) {
            return null;
        }
        $groups = array();
        foreach ( $preview['would_affect']['sites'] as $record ) {
            $by_type = array();
            foreach ( $record['items'] as $item ) {
                $type = self::UPDATE_TYPES[ $item['type'] ] ?? null;
                // The preview must stay inside what was asked for; anything else is not approved.
                if ( null === $type || ! in_array( $type, $selection['types'], true )
                    || ( $selection['specific_items'] && ! in_array( $item['slug'], $selection['specific_items'], true ) ) ) {
                    return null;
                }
                $by_type[ $type ][ $item['slug'] ] = $item['slug'];
            }
            foreach ( $by_type as $type => $slugs ) {
                sort( $slugs, SORT_STRING );
                $key                                = wp_json_encode( array_values( $slugs ) );
                $groups[ $type ][ $key ]['slugs']   = array_values( $slugs );
                $groups[ $type ][ $key ]['sites'][] = (int) $record['site_id'];
            }
        }

        $entries = array();
        foreach ( self::UPDATE_TYPES as $type ) {
            if ( empty( $groups[ $type ] ) ) {
                continue;
            }
            $type_groups = array_values( $groups[ $type ] );
            usort(
                $type_groups,
                static function ( $a, $b ) {
                    return min( $a['sites'] ) <=> min( $b['sites'] );
                }
            );
            foreach ( $type_groups as $group ) {
                $sites = array_values( array_unique( $group['sites'] ) );
                sort( $sites );
                foreach ( array_chunk( $sites, Remote_MCP_Settings::chunk_size() ) as $chunk ) {
                    $entries[] = array(
                        'ability'    => self::RUN_UPDATES,
                        'args'       => array(
                            'site_ids_or_domains' => $chunk,
                            'types'               => array( $type ),
                            'specific_items'      => $group['slugs'],
                            'confirm'             => true,
                        ),
                        'target_ids' => $chunk,
                    );
                }
            }
        }
        return empty( $entries ) ? null : Call_Plan::from_entries( $entries );
    }

    /**
     * Chunkable site action: one entry per chunk, each on its explicit ids.
     *
     * @param string     $ability   Ability.
     * @param array      $arguments Projected arguments.
     * @param string     $resolver  Resolver type.
     * @param array|null $site_ids  Resolved site ids.
     * @return Call_Plan|null
     */
    private function chunked_plan( string $ability, array $arguments, string $resolver, ?array $site_ids ): ?Call_Plan {
        if ( empty( $site_ids ) || ! in_array( $resolver, array( 'site', 'sites' ), true ) ) {
            return null;
        }
        $entries = array();
        foreach ( array_chunk( $site_ids, Remote_MCP_Settings::chunk_size() ) as $chunk ) {
            $entries[] = array(
                'ability'    => $ability,
                'args'       => self::pin( $arguments, $resolver, $chunk ),
                'target_ids' => $chunk,
            );
        }
        return Call_Plan::from_entries( $entries );
    }

    /**
     * Replace the site selector with explicit ids, so a domain, or an empty list that core reads
     * as "every site", can't select different sites when the entry runs.
     *
     * @param array  $arguments Arguments.
     * @param string $resolver  Resolver type.
     * @param array  $site_ids  Ids for this entry.
     * @return array
     */
    private static function pin( array $arguments, string $resolver, array $site_ids ): array {
        if ( 'sites' === $resolver ) {
            $arguments['site_ids_or_domains'] = array_values( $site_ids );
            unset( $arguments['site_ids'], $arguments['exclude_ids'] );
        } elseif ( 'site' === $resolver && 1 === count( $site_ids ) ) {
            $key               = array_key_exists( 'site_id', $arguments ) && ! array_key_exists( 'site_id_or_domain', $arguments ) ? 'site_id' : 'site_id_or_domain';
            $arguments[ $key ] = (int) $site_ids[0];
        }
        return $arguments;
    }

    /**
     * Replace the client selector with the client id the resolver found. Core resolves an email
     * again when the entry runs, and a write-tier client update can move that email to another
     * client in between; a tag selector is already its numeric id.
     *
     * @param array      $arguments Arguments.
     * @param array|null $entity    Resolver entity.
     * @return array|null Null when the resolver named no client.
     */
    private static function pin_client( array $arguments, ?array $entity ): ?array {
        $id = $entity['id'] ?? null;
        if ( 'client' !== ( $entity['type'] ?? null ) || ! is_int( $id ) || $id <= 0 ) {
            return null;
        }
        $key               = array_key_exists( 'client_id', $arguments ) && ! array_key_exists( 'client_id_or_email', $arguments ) ? 'client_id' : 'client_id_or_email';
        $arguments[ $key ] = $id;
        return $arguments;
    }

    /**
     * Site ids of a resolver result.
     *
     * @param array|null $targets Targets.
     * @return int[]|null|false Sorted ids, null for none, false when malformed.
     */
    private static function site_ids( ?array $targets ) {
        if ( null === $targets || null === ( $targets['site_ids'] ?? null ) ) {
            return null;
        }
        if ( ! is_array( $targets['site_ids'] ) || ! array_is_list( $targets['site_ids'] ) ) {
            return false;
        }
        $ids = array();
        foreach ( $targets['site_ids'] as $id ) {
            if ( ! is_int( $id ) || $id <= 0 ) {
                return false;
            }
            $ids[ $id ] = $id;
        }
        sort( $ids );
        return array_values( $ids );
    }

    /**
     * Whether a preview's per-site records account for everything it reports, and cover no site
     * outside the targets. Core cuts the records at the batch threshold while its counts keep
     * covering every site, so counts the records don't add up to mean records are missing, and
     * per-site approval is never rebuilt from the aggregates.
     *
     * @param mixed $preview  Preview envelope.
     * @param array $site_ids Sites it may cover.
     * @return bool
     */
    private static function complete_preview( $preview, array $site_ids ): bool {
        if ( ! is_array( $preview ) || true !== ( $preview['dry_run'] ?? null ) || ! is_array( $preview['would_affect'] ?? null ) ) {
            return false;
        }
        $sites   = $preview['would_affect']['sites'] ?? null;
        $summary = $preview['would_affect']['summary'] ?? null;
        if ( ! is_array( $sites ) || ! array_is_list( $sites ) || ! is_array( $summary ) || false !== ( $summary['truncated'] ?? null ) || false !== ( $summary['queued'] ?? null ) ) {
            return false;
        }
        $counts = array(
            'site_count'    => 0,
            'item_count'    => 0,
            'skipped_count' => 0,
        );
        $seen   = array();
        foreach ( $sites as $record ) {
            if ( ! self::valid_record( $record ) || ! in_array( $record['site_id'], $site_ids, true ) || isset( $seen[ $record['site_id'] ] ) ) {
                return false;
            }
            $seen[ $record['site_id'] ] = true;
            $counts['site_count']      += $record['items'] ? 1 : 0;
            $counts['item_count']      += count( $record['items'] );
            $counts['skipped_count']   += count( $record['skipped'] );
        }
        foreach ( $counts as $name => $count ) {
            if ( ( $summary[ $name ] ?? null ) !== $count ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether one per-site record has core's shape.
     *
     * @param mixed $record Record.
     * @return bool
     */
    private static function valid_record( $record ): bool {
        if ( ! is_array( $record ) || ! is_int( $record['site_id'] ?? null ) || ! is_array( $record['items'] ?? null ) || ! is_array( $record['skipped'] ?? null ) ) {
            return false;
        }
        foreach ( array_merge( $record['items'], $record['skipped'] ) as $item ) {
            if ( ! is_array( $item ) || ! isset( self::UPDATE_TYPES[ $item['type'] ?? '' ] ) || ! is_string( $item['slug'] ?? null ) || '' === $item['slug'] ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Merge chunk previews into one envelope of core's shape, with the aggregates recomputed from
     * the merged records.
     *
     * @param array $chunks Chunk previews, in chunk order.
     * @return array
     */
    private static function merge( array $chunks ): array {
        $sites     = array();
        $errors    = array();
        $warnings  = array();
        $not_found = null;
        foreach ( $chunks as $chunk ) {
            $sites  = array_merge( $sites, $chunk['would_affect']['sites'] );
            $errors = array_merge( $errors, is_array( $chunk['errors'] ?? null ) ? array_values( $chunk['errors'] ) : array() );
            foreach ( is_array( $chunk['warnings'] ?? null ) ? $chunk['warnings'] : array() as $warning ) {
                // Core suggests an override that this connection never accepts.
                $warning = is_string( $warning ) ? trim( (string) preg_replace( '/[^.]*\bignore_backup_gate\b[^.]*\.?/', '', $warning ) ) : '';
                if ( '' !== $warning && ! in_array( $warning, $warnings, true ) ) {
                    $warnings[] = $warning;
                }
            }
            // A requested slug is missing only when no chunk found it.
            $missing   = is_array( $chunk['would_affect']['summary']['requested_not_found'] ?? null ) ? $chunk['would_affect']['summary']['requested_not_found'] : array();
            $not_found = null === $not_found ? array_values( $missing ) : array_values( array_intersect( $not_found, $missing ) );
        }

        $summary = array(
            'site_count'          => 0,
            'item_count'          => 0,
            'skipped_count'       => 0,
            'all_sites'           => false,
            'queued'              => false,
            'truncated'           => false,
            'has_core'            => false,
            'has_major'           => false,
            'has_unknown_version' => false,
            'requested_not_found' => (array) $not_found,
        );
        $by_item = array();
        $holders = array();
        foreach ( $sites as $record ) {
            $summary['site_count']    += $record['items'] ? 1 : 0;
            $summary['item_count']    += count( $record['items'] );
            $summary['skipped_count'] += count( $record['skipped'] );
            foreach ( $record['items'] as $item ) {
                $major                          = $item['flags']['major'] ?? null;
                $summary['has_core']            = $summary['has_core'] || 'core' === $item['type'];
                $summary['has_major']           = $summary['has_major'] || true === $major;
                $summary['has_unknown_version'] = $summary['has_unknown_version'] || null === $major;
                $key                            = $item['type'] . ':' . $item['slug'];
                if ( ! isset( $by_item[ $key ] ) ) {
                    $by_item[ $key ] = array(
                        'type'       => $item['type'],
                        'slug'       => $item['slug'],
                        'name'       => (string) ( $item['name'] ?? $item['slug'] ),
                        'to'         => array(),
                        'site_count' => 0,
                        'flags'      => array(
                            'core'  => 'core' === $item['type'],
                            'major' => $major,
                        ),
                    );
                } else {
                    $previous                          = $by_item[ $key ]['flags']['major'];
                    $by_item[ $key ]['flags']['major'] = true === $major || true === $previous ? true : ( null === $major || null === $previous ? null : false );
                }
                if ( ! in_array( $item['to'] ?? '', $by_item[ $key ]['to'], true ) ) {
                    $by_item[ $key ]['to'][] = $item['to'] ?? '';
                }
                $holders[ $key ][ $record['site_id'] ] = true;
                $by_item[ $key ]['site_count']         = count( $holders[ $key ] );
            }
        }
        $order   = array_flip( array_keys( self::UPDATE_TYPES ) );
        $by_item = array_values( $by_item );
        usort(
            $by_item,
            static function ( $a, $b ) use ( $order ) {
                $type = $order[ $a['type'] ] <=> $order[ $b['type'] ];
                return 0 !== $type ? $type : strcmp( $a['slug'], $b['slug'] );
            }
        );

        return array(
            'dry_run'      => true,
            'would_affect' => array(
                'sites'   => $sites,
                'by_item' => $by_item,
                'summary' => $summary,
            ),
            'count'        => $summary['item_count'],
            'warnings'     => $warnings,
            'errors'       => $errors,
        );
    }
}
