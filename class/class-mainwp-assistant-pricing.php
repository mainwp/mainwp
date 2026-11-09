<?php
/**
 * Estimated per-model pricing for MainWP Assistant agent-mode usage.
 *
 * Turns provider-reported token usage into an estimated USD figure so the
 * chat UI can show what each exchange roughly cost. Prices are a bundled
 * snapshot of the providers' published per-token rates and can drift; the
 * table is filterable and every figure is presented as an estimate.
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Pricing
 *
 * Static helpers to price token usage against a per-model rate table.
 *
 * @package MainWP/Dashboard
 */
class MainWP_Assistant_Pricing {

    /**
     * Bundled price table snapshot: USD per 1M tokens, keyed by model id.
     *
     * Rates verified against provider pricing pages on 2026-07-07. Keys are
     * lowercase model ids as reported by the AI Client's model metadata.
     *
     * @return array<string, array{input: float, output: float}>
     */
    private static function default_table() {
        return array(
            // Anthropic.
            'claude-opus-4-8'       => array(
                'input'  => 5.00,
                'output' => 25.00,
            ),
            'claude-opus-4-7'       => array(
                'input'  => 5.00,
                'output' => 25.00,
            ),
            'claude-opus-4-6'       => array(
                'input'  => 5.00,
                'output' => 25.00,
            ),
            'claude-opus-4-5'       => array(
                'input'  => 5.00,
                'output' => 25.00,
            ),
            'claude-opus-4-1'       => array(
                'input'  => 15.00,
                'output' => 75.00,
            ),
            'claude-sonnet-5'       => array(
                'input'  => 3.00,
                'output' => 15.00,
            ),
            'claude-sonnet-4-6'     => array(
                'input'  => 3.00,
                'output' => 15.00,
            ),
            'claude-sonnet-4-5'     => array(
                'input'  => 3.00,
                'output' => 15.00,
            ),
            'claude-haiku-4-5'      => array(
                'input'  => 1.00,
                'output' => 5.00,
            ),
            // OpenAI.
            'gpt-5.5'               => array(
                'input'  => 5.00,
                'output' => 30.00,
            ),
            'gpt-5.4'               => array(
                'input'  => 2.50,
                'output' => 15.00,
            ),
            'gpt-5.4-mini'          => array(
                'input'  => 0.75,
                'output' => 4.50,
            ),
            'gpt-5.4-nano'          => array(
                'input'  => 0.20,
                'output' => 1.25,
            ),
            'gpt-5.1'               => array(
                'input'  => 1.25,
                'output' => 10.00,
            ),
            'gpt-5'                 => array(
                'input'  => 1.25,
                'output' => 10.00,
            ),
            'gpt-5-mini'            => array(
                'input'  => 0.25,
                'output' => 2.00,
            ),
            'gpt-5-nano'            => array(
                'input'  => 0.05,
                'output' => 0.40,
            ),
            'gpt-4.1'               => array(
                'input'  => 2.00,
                'output' => 8.00,
            ),
            'gpt-4.1-mini'          => array(
                'input'  => 0.40,
                'output' => 1.60,
            ),
            'gpt-4.1-nano'          => array(
                'input'  => 0.10,
                'output' => 0.40,
            ),
            'gpt-4o'                => array(
                'input'  => 2.50,
                'output' => 10.00,
            ),
            'gpt-4o-mini'           => array(
                'input'  => 0.15,
                'output' => 0.60,
            ),
            'o3'                    => array(
                'input'  => 2.00,
                'output' => 8.00,
            ),
            'o4-mini'               => array(
                'input'  => 1.10,
                'output' => 4.40,
            ),
            // Google.
            'gemini-3-pro'          => array(
                'input'  => 2.00,
                'output' => 12.00,
            ),
            // Google's introductory rate runs through 2026-12-31 and doubles
            // on 2027-01-01; a fixed intro rate would undercount the daily
            // spend cap by half after that date.
            'gemini-3.7-flash'      => array(
                'input'  => time() < 1798761600 ? 0.75 : 1.50,
                'output' => time() < 1798761600 ? 3.75 : 7.50,
            ),
            'gemini-3.6-flash'      => array(
                'input'  => time() < 1798761600 ? 0.75 : 1.50,
                'output' => time() < 1798761600 ? 3.75 : 7.50,
            ),
            'gemini-3.5-flash'      => array(
                'input'  => 1.50,
                'output' => 9.00,
            ),
            'gemini-3-flash'        => array(
                'input'  => 0.50,
                'output' => 3.00,
            ),
            'gemini-3.1-flash-lite' => array(
                'input'  => 0.25,
                'output' => 1.50,
            ),
            'gemini-2.5-pro'        => array(
                'input'  => 1.25,
                'output' => 10.00,
            ),
            'gemini-2.5-flash'      => array(
                'input'  => 0.30,
                'output' => 2.50,
            ),
            'gemini-2.5-flash-lite' => array(
                'input'  => 0.10,
                'output' => 0.40,
            ),
        );
    }

    /**
     * Get the price table with site overrides applied.
     *
     * @return array<string, array{input: float, output: float}>
     */
    public static function get_price_table() {
        /**
         * Filter the assistant model price table.
         *
         * Rates are USD per 1 million tokens, keyed by lowercase model id:
         * array( 'model-id' => array( 'input' => 1.25, 'output' => 10.00 ) ).
         *
         * @since 6.0.0
         *
         * @param array $table The bundled price table.
         */
        return apply_filters( 'mainwp_assistant_model_prices', self::default_table() );
    }

    /**
     * Estimate the USD cost of a usage array for a given model.
     *
     * Thought tokens are priced at the output rate, matching how providers
     * bill reasoning tokens. Unknown models degrade to a known:false result
     * so the UI can fall back to a tokens-only display.
     *
     * @param array  $usage       Usage array with prompt_tokens/completion_tokens (+ optional thought_tokens).
     * @param string $model_id    Model id as reported by the AI Client.
     * @param string $provider_id Optional provider id, carried through for display.
     *
     * @return array{usd: float|null, known: bool, estimated: bool, model: string, provider: string}
     */
    public static function estimate_cost( $usage, $model_id, $provider_id = '' ) {
        $model_id = strtolower( trim( (string) $model_id ) );
        $entry    = self::resolve_table_entry( $model_id );

        if ( null === $entry || empty( $usage ) || ! is_array( $usage ) ) {
            return array(
                'usd'       => null,
                'known'     => false,
                'estimated' => true,
                'model'     => $model_id,
                'provider'  => (string) $provider_id,
            );
        }

        $prompt     = (float) ( $usage['prompt_tokens'] ?? 0 );
        $completion = (float) ( $usage['completion_tokens'] ?? 0 ) + (float) ( $usage['thought_tokens'] ?? 0 );
        $usd        = ( $prompt / 1000000 * $entry['input'] ) + ( $completion / 1000000 * $entry['output'] );

        return array(
            'usd'       => round( $usd, 6 ),
            'known'     => true,
            'estimated' => true,
            'model'     => $model_id,
            'provider'  => (string) $provider_id,
        );
    }

    /**
     * Sum two cost arrays.
     *
     * A fold that mixes an unknown-model figure with a known one cannot
     * produce a trustworthy combined number, so unknown poisons the sum.
     *
     * @param array $a First cost array.
     * @param array $b Second cost array.
     *
     * @return array{usd: float|null, known: bool, estimated: bool}
     */
    public static function sum_cost( $a, $b ) {
        if ( empty( $a['known'] ) || empty( $b['known'] ) ) {
            return array(
                'usd'       => null,
                'known'     => false,
                'estimated' => true,
            );
        }

        return array(
            'usd'       => round( (float) $a['usd'] + (float) $b['usd'], 6 ),
            'known'     => true,
            'estimated' => true,
        );
    }

    /**
     * Resolve a model id to a table entry, tolerating dated snapshot ids.
     *
     * Tries an exact match, then the id with a trailing date suffix removed
     * (gpt-4o-2024-08-06, claude-haiku-4-5-20251001), then the longest table
     * key that prefixes the id at a dash boundary.
     *
     * @param string $model_id Lowercase model id.
     *
     * @return array{input: float, output: float}|null Table entry or null when unknown.
     */
    private static function resolve_table_entry( $model_id ) {
        if ( '' === $model_id ) {
            return null;
        }

        $table = self::get_price_table();

        if ( isset( $table[ $model_id ] ) ) {
            return $table[ $model_id ];
        }

        $stripped = preg_replace( '/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $model_id );
        if ( $stripped !== $model_id && isset( $table[ $stripped ] ) ) {
            return $table[ $stripped ];
        }

        $best_key = null;
        foreach ( array_keys( $table ) as $key ) {
            if ( 0 === strpos( $model_id, $key . '-' ) && ( null === $best_key || strlen( $key ) > strlen( $best_key ) ) ) {
                $best_key = $key;
            }
        }

        return null !== $best_key ? $table[ $best_key ] : null;
    }
}
