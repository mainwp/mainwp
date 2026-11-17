<?php
/**
 * Remote MCP redaction.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps credentials out of everything the module stores, shows or returns:
 * approval summaries, previews, tool results and the approval page. The audit log never sees
 * argument values at all; Remote_MCP_Events keeps only typed fields.
 */
final class MCP_Redactor {

    /**
     * Schema nodes one credential walk may visit. Schemas come from installed abilities, so this
     * only bounds the work an oversized one can cause; reaching it fails closed.
     */
    const WALK_BUDGET = 2000;

    /**
     * Credential-field marker for root alternatives too large even to list. The gate refuses
     * such a call: nothing can say which of its arguments is safe to store or show.
     */
    const UNWALKABLE = '*';

    /**
     * Substrings that make a property name a credential.
     *
     * @var array
     */
    const NAME_RULE = array( 'pass', 'secret', 'token', 'key' );

    /**
     * Exact names that are credentials without matching the substring rule. `http_user` is here
     * because update-site's HTTP credentials travel as a pair and both are
     * retry-only and never stored.
     *
     * @var array
     */
    const NAMED = array( 'adminpassword', 'http_pass', 'http_user', 'uniqueid' );

    /**
     * Custom Dashboard's snippet replace, whose summary shows the code's language, size and
     * SHA-256 instead of "supplied" so the user can match it against the snippet they wrote.
     *
     * @var string
     */
    const SNIPPET_ABILITY = 'mainwp/replace-custom-dashboard-snippet-v1';

    /**
     * How a credential shows in a summary.
     *
     * @var string
     */
    const SUPPLIED = 'supplied';

    /**
     * How a credential shows in a preview or result.
     *
     * @var string
     */
    const REDACTED = '[redacted]';

    /**
     * Longest value shown for one argument.
     *
     * @var int
     */
    const MAX_VALUE = 300;

    /**
     * Whether a property name is a credential by name alone.
     *
     * @param string $name Property name.
     * @return bool
     */
    public static function is_credential_name( string $name ): bool {
        $lower = strtolower( $name );
        if ( in_array( $lower, self::NAMED, true ) ) {
            return true;
        }
        foreach ( self::NAME_RULE as $part ) {
            if ( false !== strpos( $lower, $part ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Credential properties of an input schema: the name rule, `writeOnly: true`, and whatever an
     * audited entry or declared contract marks sensitive.
     *
     * @param array $properties   Schema properties.
     * @param array $sensitive    Sensitive field names.
     * @param array $alternatives Root `anyOf`, `oneOf` and `allOf` subschemas, which can declare
     *                            properties of their own.
     * @return array Names.
     */
    public static function credential_fields( array $properties, array $sensitive, array $alternatives = array() ): array {
        $fields = array();
        foreach ( $properties as $name => $schema ) {
            $name = (string) $name;
            if ( self::is_credential_name( $name ) || in_array( $name, $sensitive, true ) ) {
                $fields[] = $name;
            }
            // Each argument gets its own budget, so one oversized schema can't mark the others.
            $budget = self::WALK_BUDGET;
            self::collect_write_only( $name, $schema, $sensitive, $fields, 0, $budget );
        }
        if ( array() !== $alternatives ) {
            // The root has no name; its alternatives' named properties are what get marked.
            $budget = self::WALK_BUDGET;
            $root   = array();
            self::collect_write_only( '', array( 'anyOf' => $alternatives ), $sensitive, $root, 0, $budget );
            if ( in_array( '', $root, true ) ) {
                // The root walk gave up, so any argument the alternatives could describe is treated
                // as a credential: every declared property and every one the alternatives name.
                $root = array_merge( $root, array_map( 'strval', array_keys( $properties ) ), self::alternative_property_names( $alternatives ) );
            }
            $fields = array_merge( $fields, array_diff( $root, array( '' ) ) );
        }
        foreach ( $sensitive as $name ) {
            if ( is_string( $name ) && ! in_array( $name, $fields, true ) ) {
                $fields[] = $name;
            }
        }
        return array_values( array_unique( $fields ) );
    }

    /**
     * Property names declared anywhere in root alternatives, for the fail-closed fallback. Bounded
     * by the same budget; past it, no further names are added (the declared properties still are).
     *
     * @param array $alternatives Root alternatives.
     * @return array Names.
     */
    private static function alternative_property_names( array $alternatives ): array {
        $names  = array();
        $budget = self::WALK_BUDGET;
        $queue  = $alternatives;
        while ( array() !== $queue && --$budget >= 0 ) {
            $node = array_shift( $queue );
            $node = $node instanceof \stdClass ? (array) $node : $node;
            if ( ! is_array( $node ) ) {
                continue;
            }
            $props = $node['properties'] ?? null;
            if ( is_array( $props ) || $props instanceof \stdClass ) {
                $names = array_merge( $names, array_map( 'strval', array_keys( (array) $props ) ) );
            }
            foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $key ) {
                if ( isset( $node[ $key ] ) && ( is_array( $node[ $key ] ) || $node[ $key ] instanceof \stdClass ) ) {
                    $queue = array_merge( $queue, array_values( (array) $node[ $key ] ) );
                }
            }
        }
        if ( array() !== $queue ) {
            $names[] = self::UNWALKABLE;
        }
        return $names;
    }

    /**
     * Walk one property's schema for `writeOnly`, wherever JSON Schema can put it.
     *
     * Named children (`properties`) are walked under their own names. Every other subschema
     * describes this property's own value or its dynamic members (alternatives, `items`,
     * `additionalProperties`, `patternProperties`), so a `writeOnly` there makes this whole
     * property a credential. Schemas are remote input: objects are cast and depth is capped.
     *
     * @param string $name      Property name.
     * @param mixed  $schema    Its schema.
     * @param array  $sensitive Sensitive field names.
     * @param array  $fields    Credential names found so far.
     * @param int    $depth     Nesting depth.
     * @param int    $budget    Schema nodes left to visit in this walk.
     */
    private static function collect_write_only( string $name, $schema, array $sensitive, array &$fields, int $depth, int &$budget ): void {
        if ( $schema instanceof \stdClass ) {
            $schema = (array) $schema;
        }
        if ( ! is_array( $schema ) ) {
            return;
        }
        // A schema the walk can't finish, too deep or too large, fails closed: its argument is
        // treated as a credential rather than assumed to hold none.
        if ( $depth > 32 || --$budget < 0 ) {
            $fields[] = $name;
            return;
        }
        if ( true === ( $schema['writeOnly'] ?? null ) ) {
            $fields[] = $name;
        }
        $children = $schema['properties'] ?? null;
        foreach ( ( is_array( $children ) || $children instanceof \stdClass ) ? (array) $children : array() as $child => $sub ) {
            $child = (string) $child;
            if ( self::is_credential_name( $child ) || in_array( $child, $sensitive, true ) ) {
                $fields[] = $child;
            }
            self::collect_write_only( $child, $sub, $sensitive, $fields, $depth + 1, $budget );
        }
        $same = array();
        foreach ( array( 'anyOf', 'oneOf', 'allOf', 'prefixItems', 'patternProperties' ) as $key ) {
            $list = $schema[ $key ] ?? null;
            if ( is_array( $list ) || $list instanceof \stdClass ) {
                $same = array_merge( $same, array_values( (array) $list ) );
            }
        }
        foreach ( array( 'additionalProperties', 'additionalItems' ) as $key ) {
            $same[] = $schema[ $key ] ?? null;
        }
        $items = $schema['items'] ?? null;
        // A list of schemas is the tuple form; anything else is the schema for every item.
        if ( is_array( $items ) && array_is_list( $items ) && array() !== $items ) {
            $same = array_merge( $same, $items );
        } else {
            $same[] = $items;
        }
        foreach ( $same as $sub ) {
            self::collect_write_only( $name, $sub, $sensitive, $fields, $depth + 1, $budget );
        }
    }

    /**
     * Supplied credentials at any depth, including schema-sensitive object and array fields.
     *
     * @param mixed $value  Supplied arguments.
     * @param array $fields Credential field names.
     * @return array Supplied values; containers and null still make a call plan-less.
     */
    public static function credential_values( $value, array $fields ): array {
        $values = array();
        if ( ! is_array( $value ) && ! $value instanceof \stdClass ) {
            return $values;
        }
        foreach ( (array) $value as $key => $item ) {
            if ( is_string( $key ) && ( self::is_credential_name( $key ) || in_array( $key, $fields, true ) ) ) {
                $values[] = $item;
            } else {
                $values = array_merge( $values, self::credential_values( $item, $fields ) );
            }
        }
        return $values;
    }

    /**
     * Whether a string is a URL carrying `user:pass@` or `user@`.
     *
     * @param string $value Value.
     * @return bool
     */
    public static function url_has_userinfo( string $value ): bool {
        return 1 === preg_match( '#^\s*[a-z][a-z0-9+.\-]*://[^/?\#]*@#i', $value );
    }

    /**
     * Whether any string in a value, at any depth, is a URL with userinfo.
     *
     * @param mixed $value Value.
     * @return bool
     */
    public static function contains_userinfo_url( $value ): bool {
        if ( is_string( $value ) ) {
            return self::url_has_userinfo( $value );
        }
        if ( is_array( $value ) || is_object( $value ) ) {
            foreach ( (array) $value as $item ) {
                if ( self::contains_userinfo_url( $item ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * A URL without its userinfo, which stored site URLs can carry too.
     *
     * @param string $url URL.
     * @return string
     */
    public static function strip_userinfo( string $url ): string {
        // Up to the last @ in the token: a raw password can itself hold @ or /. Over-stripping a
        // path that contains @ only hides more, which is the safe direction for redaction.
        return (string) preg_replace( '#([a-z][a-z0-9+.\-]*://)\S*@#i', '$1', $url );
    }

    /**
     * A preview or result with credentials removed: credential keys at any depth, any string
     * holding a credential value the call supplied (abilities echo their input), and URL userinfo.
     *
     * @param mixed $value   Value.
     * @param array $fields  Credential field names of the ability.
     * @param array $secrets Credential values from the call.
     * @return mixed
     */
    public static function redact( $value, array $fields = array(), array $secrets = array() ) {
        if ( is_string( $value ) ) {
            // A short credential can alter the scheme, so remove URL userinfo first.
            $value = self::strip_userinfo( $value );
            $flat  = self::flat_secrets( $secrets );
            // Longest first: a shorter secret inside a longer one would otherwise leave its rest.
            usort(
                $flat,
                function ( $a, $b ) {
                    return strlen( $b ) - strlen( $a );
                }
            );
            foreach ( $flat as $secret ) {
                $value = str_replace( $secret, self::REDACTED, $value );
            }
            return $value;
        }
        if ( $value instanceof \WP_Error ) {
            return new \WP_Error( $value->get_error_code(), self::redact( $value->get_error_message(), $fields, $secrets ) );
        }
        if ( ! is_array( $value ) && ! $value instanceof \stdClass ) {
            return is_object( $value ) ? null : $value;
        }
        $object = $value instanceof \stdClass;
        $copy   = array();
        foreach ( (array) $value as $key => $item ) {
            // A boolean holds no secret, and status flags such as recovery_passphrase_set match the
            // name rule. The schema can still mark one sensitive.
            $by_name = self::is_credential_name( (string) $key ) && ! is_bool( $item );
            if ( is_string( $key ) && ( $by_name || in_array( $key, $fields, true ) ) ) {
                $copy[ $key ] = null === $item ? null : self::REDACTED;
                continue;
            }
            $copy[ $key ] = self::redact( $item, $fields, $secrets );
        }
        return $object ? (object) $copy : $copy;
    }

    /**
     * Labeled, display-safe lines for a call's arguments. Credentials show as "supplied"; the
     * snippet replace's code shows its language, size and hash.
     *
     * @param string $ability Ability name.
     * @param array  $args    Projected arguments.
     * @param array  $fields  Credential field names.
     * @param array  $labels  Schema titles by property name.
     * @return array List of `label` and `value`.
     */
    public static function argument_lines( string $ability, array $args, array $fields, array $labels = array() ): array {
        $lines   = array();
        $secrets = self::credential_values( $args, $fields );
        foreach ( $args as $name => $value ) {
            $name  = (string) $name;
            $label = isset( $labels[ $name ] ) && is_string( $labels[ $name ] ) && '' !== $labels[ $name ] ? $labels[ $name ] : ucfirst( str_replace( '_', ' ', $name ) );
            if ( self::SNIPPET_ABILITY === $ability && 'code' === $name && is_string( $value ) ) {
                $language = isset( $args['type'] ) && is_string( $args['type'] ) ? $args['type'] : 'unknown';
                $shown    = sprintf( '%1$s, %2$d bytes, SHA-256 %3$s', $language, strlen( $value ), hash( 'sha256', $value ) );
            } elseif ( in_array( $name, $fields, true ) || self::is_credential_name( $name ) ) {
                $shown = self::SUPPLIED;
            } else {
                $shown = self::display( self::redact( $value, $fields, $secrets ) );
            }
            $lines[] = array(
                'label' => self::cut( $label ),
                'value' => $shown,
            );
        }
        return $lines;
    }

    /**
     * One value as short text.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function display( $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        if ( null === $value ) {
            return 'none';
        }
        if ( is_scalar( $value ) ) {
            return self::cut( (string) $value );
        }
        return self::cut( (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Cut text to the display limit.
     *
     * @param string $text Text.
     * @return string
     */
    private static function cut( string $text ): string {
        return mb_strlen( $text, 'UTF-8' ) > self::MAX_VALUE ? mb_substr( $text, 0, self::MAX_VALUE, 'UTF-8' ) . '...' : $text;
    }

    /**
     * Every non-empty string among the call's credential values, at any depth.
     *
     * @param array $secrets Credential values from the call.
     * @return array
     */
    private static function flat_secrets( array $secrets ): array {
        $flat = array();
        foreach ( $secrets as $secret ) {
            if ( is_array( $secret ) || $secret instanceof \stdClass ) {
                $flat = array_merge( $flat, self::flat_secrets( (array) $secret ) );
            } elseif ( is_string( $secret ) && '' !== $secret ) {
                $flat[] = $secret;
            }
        }
        return array_values( array_unique( $flat ) );
    }
}
