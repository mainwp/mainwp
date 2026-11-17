<?php
/**
 * Module Assistant connector class.
 *
 * Logs AI Assistant actions including ability execution, failures,
 * confirmations, and conversation starts.
 *
 * @package MainWP\Dashboard
 * @version 6.0.0
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Assistant
 *
 * Handles logging for the AI Assistant feature.
 */
class Connector_Assistant extends Log_Connector {

    /**
     * Connector slug
     *
     * @var string
     */
    public $name = 'assistant';

    /**
     * Actions registered for this connector
     *
     * @var array
     */
    public $actions = array(
        'mainwp_assistant_ability_executed',
        'mainwp_assistant_ability_failed',
        'mainwp_assistant_ability_confirmed',
        'mainwp_assistant_conversation_started',
        'mainwp_assistant_chat_failed',
    );

    /**
     * Return translated connector label
     *
     * @return string Translated connector label
     */
    public function get_label() {
        return esc_html__( 'AI Assistant', 'mainwp' );
    }

    /**
     * Return translated action term labels
     *
     * @return array Action terms label translation
     */
    public function get_action_labels() {
        return array(
            'executed'  => esc_html__( 'Executed', 'mainwp' ),
            'failed'    => esc_html__( 'Failed', 'mainwp' ),
            'confirmed' => esc_html__( 'Confirmed', 'mainwp' ),
            'started'   => esc_html__( 'Started', 'mainwp' ),
        );
    }

    /**
     * Return translated context labels
     *
     * @return array Context label translations
     */
    public function get_context_labels() {
        return array(
            'abilities' => esc_html__( 'Abilities', 'mainwp' ),
            'chat'      => esc_html__( 'Chat', 'mainwp' ),
        );
    }

    /**
     * Build the base log args shared by executed, failed, and confirmed callbacks.
     *
     * @param string     $ability_name    Ability name.
     * @param int        $conversation_id Conversation ID (0 for stateless).
     * @param float|null $duration        Execution duration in seconds.
     * @param string     $provider_type   AI provider type.
     * @param string     $model           AI model name.
     *
     * @return array Base args array.
     */
    private function build_base_log_args( $ability_name, $conversation_id, $duration, $provider_type, $model ) {
        $args = array(
            'ability'       => $ability_name,
            'provider_type' => sanitize_text_field( $provider_type ),
            'model'         => sanitize_text_field( $model ),
        );

        // Only store conversation_id when it represents a real conversation.
        // Stateless calls pass 0, and omitting the meta key lets the provider
        // usage query distinguish stateless rows via IS NULL.
        if ( absint( $conversation_id ) > 0 ) {
            $args['conversation_id'] = absint( $conversation_id );
        }

        if ( null !== $duration ) {
            $args['duration'] = floatval( $duration );
        }

        return $args;
    }

    /**
     * Log ability execution.
     *
     * @action mainwp_assistant_ability_executed
     *
     * @param string     $ability_name    Ability name.
     * @param array      $params          Ability parameters (already redacted).
     * @param array      $result          Ability result (already redacted).
     * @param int        $conversation_id Conversation ID.
     * @param int        $user_id         User ID.
     * @param float|null $duration      Execution duration in seconds (optional).
     * @param string     $provider_type   AI provider type (e.g., 'openai', 'anthropic').
     * @param string     $model           AI model name.
     * @param string     $result_summary  Brief summary of the result.
     * @param int        $site_id         Target site when the ability addressed exactly one (0 = not site-specific).
     */
    public function callback_mainwp_assistant_ability_executed( $ability_name, $params, $result, $conversation_id, $user_id, $duration = null, $provider_type = '', $model = '', $result_summary = '', $site_id = 0 ) {
        if ( empty( $ability_name ) ) {
            return;
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        if ( ! is_array( $result ) ) {
            $result = array();
        }

        // translators: %s: ability name.
        $message = sprintf( esc_html__( 'Executed ability: %s', 'mainwp' ), $ability_name );
        $can_log = $this->can_log_conversational_content( $user_id );

        $args = array_merge(
            $this->build_base_log_args( $ability_name, $conversation_id, $duration, $provider_type, $model ),
            array(
                'params'         => $can_log ? $this->encode_log_payload( $params ) : '[redacted]',
                'result'         => $can_log ? $this->encode_log_payload( $result ) : '[redacted]',
                'result_summary' => $can_log ? sanitize_textarea_field( $result_summary ) : '[redacted]',
            )
        );

        $this->log(
            $message,
            $args,
            absint( $site_id ),
            'abilities',
            'executed',
            1,
            absint( $user_id )
        );
    }

    /**
     * Log ability failure.
     *
     * @action mainwp_assistant_ability_failed
     *
     * @param string          $ability_name    Ability name.
     * @param array           $params          Ability parameters (already redacted).
     * @param \WP_Error|array $error           Error object or array.
     * @param int             $conversation_id Conversation ID.
     * @param int             $user_id         User ID.
     * @param float|null      $duration        Execution duration in seconds (optional).
     * @param string          $provider_type   AI provider type (e.g., 'openai', 'anthropic').
     * @param string          $model           AI model name.
     * @param int             $site_id         Target site when the ability addressed exactly one (0 = not site-specific).
     */
    public function callback_mainwp_assistant_ability_failed( $ability_name, $params, $error, $conversation_id, $user_id, $duration = null, $provider_type = '', $model = '', $site_id = 0 ) {
        if ( empty( $ability_name ) ) {
            return;
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        // Provider SDKs and child-site responses put request URLs, bearer tokens, and
        // response-body fragments in error text, and this string reaches the activity log
        // and the log UI, so it is sanitized, scrubbed, and capped below.
        if ( is_wp_error( $error ) ) {
            $error_code    = sanitize_key( $error->get_error_code() );
            $error_message = sanitize_text_field( $error->get_error_message() );
        } elseif ( is_array( $error ) ) {
            $error_code    = sanitize_key( $error['error'] ?? 'unknown' );
            $error_message = sanitize_text_field( $error['message'] ?? 'Unknown error' );
        } else {
            $error_code    = 'unknown';
            $error_message = 'Unknown error';
        }

        $error_summary = mb_substr( $this->scrub_error_text( $error_message ), 0, 160 );
        $can_log       = $this->can_log_conversational_content( $user_id );

        // translators: %1$s: ability name, %2$s: error message.
        $message = $can_log
            ? sprintf( esc_html__( 'Failed to execute ability %1$s. %2$s', 'mainwp' ), $ability_name, $error_summary )
            : sprintf( esc_html__( 'Failed to execute ability %s.', 'mainwp' ), $ability_name );

        $args = array_merge(
            $this->build_base_log_args( $ability_name, $conversation_id, $duration, $provider_type, $model ),
            array(
                'params'         => $can_log ? $this->encode_log_payload( $params ) : '[redacted]',
                'error_code'     => $error_code,
                'error_message'  => $can_log ? $error_summary : '[redacted]',
                'result_summary' => $can_log ? 'Failed: ' . $error_summary : '[redacted]',
            )
        );

        $this->log(
            $message,
            $args,
            absint( $site_id ),
            'abilities',
            'failed',
            0,
            absint( $user_id )
        );
    }

    /**
     * Log ability confirmation.
     *
     * @action mainwp_assistant_ability_confirmed
     *
     * @param string     $ability_name    Ability name.
     * @param array      $params          Ability parameters (already redacted).
     * @param array      $result          Ability result (already redacted).
     * @param int        $conversation_id Conversation ID.
     * @param int        $user_id         User ID.
     * @param bool       $is_destructive  Whether the ability is destructive.
     * @param float|null $duration      Execution duration in seconds (optional).
     * @param string     $provider_type   AI provider type (e.g., 'openai', 'anthropic').
     * @param string     $model           AI model name.
     * @param string     $result_summary  Brief summary of the result.
     * @param int        $site_id         Target site when the ability addressed exactly one (0 = not site-specific).
     */
    public function callback_mainwp_assistant_ability_confirmed( $ability_name, $params, $result, $conversation_id, $user_id, $is_destructive, $duration = null, $provider_type = '', $model = '', $result_summary = '', $site_id = 0 ) {
        if ( empty( $ability_name ) ) {
            return;
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        if ( ! is_array( $result ) ) {
            $result = array();
        }

        $ability_type_label = $is_destructive
            ? esc_html__( 'destructive', 'mainwp' )
            : esc_html__( 'mutating', 'mainwp' );

        /* translators: %1$s: destructive or mutating, %2$s: ability name. */
        $message = sprintf(
            esc_html__( 'Confirmed %1$s ability: %2$s', 'mainwp' ),
            $ability_type_label,
            $ability_name
        );
        $can_log = $this->can_log_conversational_content( $user_id );

        $args = array_merge(
            $this->build_base_log_args( $ability_name, $conversation_id, $duration, $provider_type, $model ),
            array(
                'params'         => $can_log ? $this->encode_log_payload( $params ) : '[redacted]',
                'result'         => $can_log ? $this->encode_log_payload( $result ) : '[redacted]',
                'is_destructive' => $is_destructive ? 1 : 0,
                'result_summary' => $can_log ? sanitize_textarea_field( $result_summary ) : '[redacted]',
            )
        );

        $this->log(
            $message,
            $args,
            absint( $site_id ),
            'abilities',
            'confirmed',
            1,
            absint( $user_id )
        );
    }

    /**
     * Log conversation start.
     *
     * @action mainwp_assistant_conversation_started
     *
     * @param int    $conversation_id Conversation ID.
     * @param int    $user_id         User ID.
     * @param string $provider_type   Provider type (e.g., 'openai', 'anthropic').
     * @param string $model           Model name.
     * @param string $title           Conversation title.
     */
    public function callback_mainwp_assistant_conversation_started( $conversation_id, $user_id, $provider_type, $model, $title ) {
        if ( empty( $conversation_id ) || empty( $user_id ) ) {
            return;
        }

        $can_log = $this->can_log_conversational_content( $user_id );
        $title   = sanitize_text_field( $title );
        $message = $can_log
            ? sprintf( esc_html__( 'Started conversation: %s', 'mainwp' ), $title )
            : esc_html__( 'Started conversation', 'mainwp' );

        $args = array(
            'conversation_id' => absint( $conversation_id ),
            'provider_type'   => sanitize_text_field( $provider_type ),
            'model'           => sanitize_text_field( $model ),
            'title'           => $can_log ? $title : '[redacted]',
        );

        $this->log(
            $message,
            $args,
            0, // site_id: conversations are not site-specific.
            'chat',
            'started',
            1,
            absint( $user_id )
        );
    }

    /**
     * Record a failed chat request without retaining prompts or provider text.
     *
     * @param int    $conversation_id Conversation ID (0 for stateless).
     * @param int    $user_id         Request owner.
     * @param string $error_code      Failure code.
     * @param string $mode            Request route.
     */
    public function callback_mainwp_assistant_chat_failed( $conversation_id, $user_id, $error_code, $mode ) {
        $args = array(
            'error_code' => sanitize_key( $error_code ),
            'route'      => 'agent' === $mode ? 'agent' : 'ask',
        );
        if ( $conversation_id ) {
            $args['conversation_id'] = absint( $conversation_id );
        }
        $this->log( esc_html__( 'Chat request failed', 'mainwp' ), $args, 0, 'chat', 'failed', 0, absint( $user_id ) );
    }

    /**
     * Check whether conversational content may be persisted to activity logs.
     *
     * @param int $user_id User ID.
     *
     * @return bool True when consented history logging is allowed.
     */
    private function can_log_conversational_content( $user_id ) {
        if ( ! class_exists( '\MainWP\Dashboard\MainWP_Assistant_Policies' ) ) {
            return false;
        }

        if ( ! \MainWP\Dashboard\MainWP_Assistant_Policies::instance()->has_consent() ) {
            return false;
        }

        return ! (bool) get_user_meta( absint( $user_id ), 'mainwp_assistant_history_opt_out', true );
    }

    /**
     * Strip the secret-shaped fragments out of an error message.
     *
     * The message is the one free-text field this connector writes; everything
     * else is reduced to types and lengths. The chat error bubble carries the
     * same provider text, so both share one scrub.
     *
     * @param string $text Sanitized error text.
     *
     * @return string Scrubbed error text.
     */
    private function scrub_error_text( $text ) {
        return \MainWP\Dashboard\MainWP_Assistant::redact_secret_shapes( $text );
    }

    /**
     * Encode assistant log payloads as a shape summary, never the values.
     *
     * Ability params and results routinely carry site credentials, API keys, and user
     * data. Summarizing before the write keeps them out of the log table entirely.
     *
     * @param array $payload Payload to summarize for logging.
     *
     * @return string JSON-encoded summarized payload.
     */
    private function encode_log_payload( $payload ) {
        if ( ! is_array( $payload ) ) {
            return wp_json_encode( array() );
        }

        return wp_json_encode( $this->summarize_log_payload( $payload ) );
    }

    /**
     * Recursively summarize payloads stored in activity logs.
     *
     * Scalars are reduced to their type and length and arrays to their type and count.
     * No payload value is ever returned, and that is the privacy guarantee the whole
     * connector rests on: a change that put values back into this return would write
     * credentials and site data into the activity log.
     *
     * The depth and item caps below bound the row size for the deep result arrays some
     * abilities return.
     *
     * @param mixed $value Payload value.
     * @param int   $depth Current recursion depth.
     *
     * @return mixed Sanitized summarized value.
     */
    private function summarize_log_payload( $value, $depth = 0 ) {
        if ( $depth >= 3 ) {
            return array(
                'type'      => sanitize_key( gettype( $value ) ),
                'truncated' => true,
            );
        }

        if ( is_array( $value ) ) {
            $summary = array(
                'type'  => 'array',
                'count' => count( $value ),
                'items' => array(),
            );
            $items   = array_slice( $value, 0, 10, true );

            foreach ( $items as $key => $item ) {
                $summary_key = sanitize_key( (string) $key );
                if ( '' === $summary_key ) {
                    $summary_key = 'item_' . count( $summary['items'] );
                }

                $summary['items'][ $summary_key ] = $this->summarize_log_payload( $item, $depth + 1 );
            }

            if ( count( $value ) > 10 ) {
                $summary['truncated'] = true;
            }

            return $summary;
        }

        if ( is_bool( $value ) ) {
            return array( 'type' => 'boolean' );
        }

        if ( is_int( $value ) ) {
            return array( 'type' => 'integer' );
        }

        if ( is_float( $value ) ) {
            return array( 'type' => 'float' );
        }

        if ( null === $value ) {
            return array( 'type' => 'null' );
        }

        if ( is_scalar( $value ) ) {
            return array(
                'type'   => 'string',
                'length' => strlen( (string) $value ),
            );
        }

        return array( 'type' => sanitize_key( gettype( $value ) ) );
    }
}
