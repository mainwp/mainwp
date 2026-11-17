<?php
/**
 * Remote MCP call plan.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The frozen list of ability calls an approval covers. Execution runs
 * the entries in order and nothing else, so every entry names its explicit targets.
 *
 * Each entry is `{ability, args, target_ids}`: a versioned ability name, a schema-valid projected
 * argument object with explicit targets, and the sorted site ids that entry acts on (null when the
 * ability has no site target). An entry may also carry `preview_baseline`: 64 lowercase hex of
 * the preview output the approval saw, which a re-captured native preview is compared against.
 * Anything malformed is refused at construction, never repaired.
 *
 * The plan holds a value snapshot as canonical JSON, never references to what the caller passed,
 * so nothing outside can change it after it was validated. JSON objects stay objects and lists
 * stay lists through storage: `{"selector":{}}` and `{"selector":[]}` are different arguments.
 * In PHP input, an associative array or a stdClass is an object, a list is a list, and an empty
 * array is an empty list (pass a stdClass for an empty object); top-level `args` is always an object.
 *
 * A pending plan is what a `dashboard` row holds between the first call and
 * the page click: one entry, the click's preview input, never something to run. Its flag is part
 * of the stored form and of equals(), so storage can neither drop it nor add it to a plan built by
 * from_entries().
 */
final class Call_Plan {

    /**
     * Largest plan accepted. A plan over this is a build error, not a long job.
     *
     * @var int
     */
    const MAX_ENTRIES = 1000;

    /**
     * Deepest argument nesting accepted.
     *
     * @var int
     */
    const MAX_DEPTH = 32;

    /**
     * Versioned ability name, `namespace/name-vN`.
     *
     * @var string
     */
    const ABILITY_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/D';

    /**
     * Canonical JSON of the entries.
     *
     * @var string
     */
    private $json;

    /**
     * Whether the plan is a pending, non-executable plan.
     *
     * @var bool
     */
    private $pending;

    /**
     * Use from_entries(), pending() or from_json().
     *
     * @param string $json    Canonical JSON of the entries.
     * @param bool   $pending Pending flag.
     */
    private function __construct( string $json, bool $pending = false ) {
        $this->json    = $json;
        $this->pending = $pending;
    }

    /**
     * Build a pending plan: exactly one entry of `ability`, `args` and `target_ids`, validated like
     * any entry. A baseline belongs to an approved preview, which a pending plan never has.
     *
     * @param array $entry One entry.
     * @return self|null Null for anything but one well-formed entry without `preview_baseline`.
     */
    public static function pending( array $entry ): ?self {
        if ( array( 'ability', 'args', 'target_ids' ) !== self::sorted_keys( $entry ) ) {
            return null;
        }
        $plan = self::from_entries( array( $entry ) );
        return null === $plan ? null : new self( $plan->json, true );
    }

    /**
     * Whether this is a pending plan, which nothing may execute.
     *
     * @return bool
     */
    public function is_pending(): bool {
        return $this->pending;
    }

    /**
     * Build a plan.
     *
     * @param array $entries List of `ability` (string), `args` (array or stdClass, an object) and
     *                       `target_ids` (list of positive ints, or null).
     * @return self|null Null when any entry is malformed or holds a value JSON can't carry.
     */
    public static function from_entries( array $entries ): ?self {
        if ( empty( $entries ) || count( $entries ) > self::MAX_ENTRIES || ! array_is_list( $entries ) ) {
            return null;
        }
        $normalized = array();
        try {
            foreach ( $entries as $entry ) {
                if ( ! is_array( $entry ) ) {
                    return null;
                }
                $keys = self::sorted_keys( $entry );
                if ( array( 'ability', 'args', 'target_ids' ) !== $keys && array( 'ability', 'args', 'preview_baseline', 'target_ids' ) !== $keys ) {
                    return null;
                }
                $baseline = $entry['preview_baseline'] ?? null;
                if ( null !== $baseline && ( ! is_string( $baseline ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $baseline ) ) ) {
                    return null;
                }
                if ( ! is_string( $entry['ability'] ) || 1 !== preg_match( self::ABILITY_PATTERN, $entry['ability'] ) ) {
                    return null;
                }
                $args = $entry['args'];
                if ( is_array( $args ) && ( empty( $args ) || ! array_is_list( $args ) ) ) {
                    $args = (object) $args;
                }
                if ( ! $args instanceof \stdClass ) {
                    return null;
                }
                $copy = array(
                    'ability'    => $entry['ability'],
                    'args'       => self::snapshot( $args, 0 ),
                    'target_ids' => self::targets( $entry['target_ids'] ),
                );
                // Absent and null are the same entry, so plans stored before the field keep their bytes.
                if ( null !== $baseline ) {
                    $copy['preview_baseline'] = $baseline;
                }
                $normalized[] = $copy;
            }
        } catch ( \InvalidArgumentException $e ) {
            return null;
        }
        $json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        return is_string( $json ) ? new self( $json ) : null;
    }

    /**
     * Rebuild a plan from to_json() output, keeping JSON objects as objects.
     *
     * @param string $json JSON.
     * @return self|null
     */
    public static function from_json( string $json ): ?self {
        $decoded = json_decode( $json, false, self::MAX_DEPTH + 4 );
        if ( $decoded instanceof \stdClass ) {
            $parts = get_object_vars( $decoded );
            if ( array( 'entries', 'pending' ) !== self::sorted_keys( $parts ) || true !== $parts['pending']
                || ! is_array( $parts['entries'] ) || 1 !== count( $parts['entries'] ) || ! $parts['entries'][0] instanceof \stdClass ) {
                return null;
            }
            return self::pending( get_object_vars( $parts['entries'][0] ) );
        }
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        $entries = array();
        foreach ( $decoded as $entry ) {
            if ( ! $entry instanceof \stdClass ) {
                return null;
            }
            $entries[] = get_object_vars( $entry );
        }
        return self::from_entries( $entries );
    }

    /**
     * Entries in execution order, as fresh PHP arrays (JSON objects become associative arrays,
     * the shape WordPress hands an ability). Every entry names `preview_baseline`, null when it
     * has none. Changing the result changes nothing in the plan.
     *
     * @return array
     */
    public function entries(): array {
        $entries = array();
        foreach ( (array) json_decode( $this->json, true ) as $entry ) {
            $entry['preview_baseline'] = $entry['preview_baseline'] ?? null;
            $entries[]                 = $entry;
        }
        return $entries;
    }

    /**
     * One entry's arguments with JSON objects as stdClass, a fresh copy, for a caller that needs
     * to tell `{}` from `[]`.
     *
     * @param int $index Entry index.
     * @return \stdClass|null
     */
    public function args_object( int $index ): ?\stdClass {
        $decoded = json_decode( $this->json, false );
        return isset( $decoded[ $index ] ) ? $decoded[ $index ]->args : null;
    }

    /**
     * Number of entries.
     *
     * @return int
     */
    public function count(): int {
        return count( $this->entries() );
    }

    /**
     * Every site id the plan touches, sorted.
     *
     * @return int[]
     */
    public function target_ids(): array {
        $ids = array();
        foreach ( $this->entries() as $entry ) {
            foreach ( (array) $entry['target_ids'] as $id ) {
                $ids[ $id ] = $id;
            }
        }
        sort( $ids );
        return array_values( $ids );
    }

    /**
     * Canonical JSON: object keys sorted at every depth, lists kept in order. Two plans that would
     * run the same calls have the same bytes, which is what freshness checks and digests compare.
     * A pending plan is `{"entries":[...],"pending":true}`; an executable one is the bare list, so
     * plans stored before the flag keep their bytes.
     *
     * @return string
     */
    public function to_json(): string {
        return $this->pending ? '{"entries":' . $this->json . ',"pending":true}' : $this->json;
    }

    /**
     * Whether another plan runs exactly the same calls, pending flag included.
     *
     * @param Call_Plan $other Plan.
     * @return bool
     */
    public function equals( Call_Plan $other ): bool {
        return hash_equals( $this->to_json(), $other->to_json() );
    }

    /**
     * Canonical JSON of any value under the same rules as a plan's arguments.
     *
     * @param mixed $value Value.
     * @return string|null Null when the value holds something JSON can't carry.
     */
    public static function canonical_json( $value ): ?string {
        try {
            $json = wp_json_encode( self::snapshot( $value, 0 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        } catch ( \InvalidArgumentException $e ) {
            return null;
        }
        return is_string( $json ) ? $json : null;
    }

    /**
     * A detached copy of a value with object keys sorted: stdClass and associative arrays become
     * stdClass, lists stay arrays, scalars are copied.
     *
     * @param mixed $value Value.
     * @param int   $depth Current depth.
     * @return mixed
     * @throws \InvalidArgumentException On a value JSON can't carry, or nesting too deep.
     */
    private static function snapshot( $value, int $depth ) {
        if ( $depth > self::MAX_DEPTH ) {
            throw new \InvalidArgumentException( 'Too deep.' );
        }
        if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
            return $value;
        }
        if ( is_float( $value ) ) {
            if ( ! is_finite( $value ) ) {
                throw new \InvalidArgumentException( 'Not a JSON number.' );
            }
            return $value;
        }
        $is_object = $value instanceof \stdClass;
        if ( ! $is_object && ! is_array( $value ) ) {
            throw new \InvalidArgumentException( 'Not a JSON value.' );
        }
        $items = $is_object ? get_object_vars( $value ) : $value;
        if ( ! $is_object && array_is_list( $items ) ) {
            $copy = array();
            foreach ( $items as $item ) {
                $copy[] = self::snapshot( $item, $depth + 1 );
            }
            return $copy;
        }
        ksort( $items, SORT_STRING );
        $copy = new \stdClass();
        foreach ( $items as $key => $item ) {
            $copy->{ (string) $key} = self::snapshot( $item, $depth + 1 );
        }
        return $copy;
    }

    /**
     * Normalized target ids.
     *
     * @param mixed $targets Target ids, or null.
     * @return int[]|null
     * @throws \InvalidArgumentException On anything but a list of positive ints.
     */
    private static function targets( $targets ): ?array {
        if ( null === $targets ) {
            return null;
        }
        if ( ! is_array( $targets ) || ! array_is_list( $targets ) ) {
            throw new \InvalidArgumentException( 'Bad targets.' );
        }
        foreach ( $targets as $id ) {
            if ( ! is_int( $id ) || $id <= 0 ) {
                throw new \InvalidArgumentException( 'Bad target.' );
            }
        }
        $targets = array_values( array_unique( $targets ) );
        sort( $targets );
        return $targets;
    }

    /**
     * Keys of an array, sorted.
     *
     * @param array $entry Entry.
     * @return array
     */
    private static function sorted_keys( array $entry ): array {
        $keys = array_keys( $entry );
        sort( $keys, SORT_STRING );
        return $keys;
    }
}
