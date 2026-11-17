<?php
/**
 * Buffered Responses inference for a single request owner.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- AI Client interfaces require these method names.

/** Credentials are resolved again for every round-trip, including tool continuations. */
class MainWP_Assistant_ChatGPT_Model implements ModelInterface, TextGenerationModelInterface {
    /**
     * Request owner.
     *
     * @var int
     */
    private $user_id;
    /**
     * Account model.
     *
     * @var ModelMetadata
     */
    private $metadata;
    /**
     * Prompt configuration.
     *
     * @var ModelConfig
     */
    private $config;

    /**
     * Keeps account ownership explicit.
     *
     * @param int           $user_id Request owner.
     * @param ModelMetadata $metadata Account model.
     */
    public function __construct( $user_id, ModelMetadata $metadata ) {
        $this->user_id  = (int) $user_id;
        $this->metadata = $metadata;
        $this->config   = new ModelConfig();
    }

    /** {@inheritDoc} */
    public function metadata(): ModelMetadata {
        return $this->metadata;
    }

    /** {@inheritDoc} */
    public function providerMetadata(): ProviderMetadata {
        return MainWP_Assistant_ChatGPT_Provider::metadata();
    }

    /**
     * {@inheritDoc}
     *
     * @param ModelConfig $config Prompt configuration.
     */
    public function setConfig( ModelConfig $config ): void {
        $this->config = $config;
    }

    /** {@inheritDoc} */
    public function getConfig(): ModelConfig {
        return $this->config;
    }

    /**
     * {@inheritDoc}
     *
     * @param array $prompt Message DTOs.
     */
    public function generateTextResult( array $prompt ): GenerativeAiResult {
        $token = MainWP_Assistant_ChatGPT_Plan::get_access_token( $this->user_id );
        if ( is_wp_error( $token ) ) {
            $data = (array) $token->get_error_data();
            // Token failures without a status are transport or storage problems, not a bad request.
            self::fail( $this->user_id, $token->get_error_code(), (int) ( $data['http_status'] ?? 503 ), $data['request_id'] ?? '' );
        }
        $body  = array(
            'model'        => $this->metadata->getId(),
            'instructions' => $this->config->getSystemInstruction() ?? '',
            'input'        => $this->history( $prompt ),
            'store'        => false,
            'stream'       => true,
        );
        $tools = array();
        foreach ( $this->config->getFunctionDeclarations() ?? array() as $declaration ) {
            $tools[] = array(
                'type'        => 'function',
                'name'        => $declaration->getName(),
                'description' => $declaration->getDescription(),
                // Responses tools default to strict, which makes the model fill
                // every optional field. Abilities treat a filled optional field
                // as a filter or a target, so leave unused ones out.
                'strict'      => false,
                'parameters'  => $declaration->getParameters() ?? array(
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ),
            );
        }
        if ( ! empty( $tools ) ) {
            $body['tools'] = array(
                array(
                    'type'        => 'namespace',
                    'name'        => 'mainwp',
                    'description' => "MainWP Dashboard tools for the user's managed WordPress sites.",
                    'tools'       => $tools,
                ),
            );
        }
        $response = wp_safe_remote_post(
            MainWP_Assistant_ChatGPT_Plan::RESOURCE . '/responses',
            array(
                'timeout'     => 120,
                'sslverify'   => true,
                'redirection' => 0,
                'headers'     => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'        => wp_json_encode( $body ),
            )
        );
        if ( is_wp_error( $response ) ) {
            self::fail( $this->user_id, 'network_error', 503 );
        }
        $http       = (int) wp_remote_retrieve_response_code( $response );
        $request_id = wp_remote_retrieve_header( $response, 'x-request-id' );
        if ( '' === $request_id ) {
            $request_id = wp_remote_retrieve_header( $response, 'openai-request-id' );
        }
        $raw = wp_remote_retrieve_body( $response );
        if ( 200 !== $http ) {
            $error = json_decode( $raw, true );
            $error = is_array( $error['error'] ?? null ) ? $error['error'] : array();
            self::fail( $this->user_id, $error['code'] ?? 'http_error', $http, $request_id, $error['param'] ?? '' );
        }
        $completed = $this->parse_stream( $raw, $request_id );
        $parts     = array();
        foreach ( $completed['output'] ?? array() as $item ) {
            if ( 'message' === ( $item['type'] ?? '' ) ) {
                foreach ( $item['content'] ?? array() as $content ) {
                    if ( 'output_text' === ( $content['type'] ?? '' ) && is_string( $content['text'] ?? null ) ) {
                        $parts[] = new MessagePart( $content['text'] );
                    }
                }
            } elseif ( 'function_call' === ( $item['type'] ?? '' ) ) {
                $args = json_decode( $item['arguments'] ?? '' );
                if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $args ) || empty( $item['call_id'] ) || empty( $item['name'] ) ) {
                    self::fail( $this->user_id, 'invalid_response', 503, $request_id );
                }
                $parts[] = new MessagePart( new FunctionCall( $item['call_id'], $item['name'], $args ) );
            }
        }
        if ( empty( $parts ) ) {
            self::fail( $this->user_id, 'invalid_response', 503, $request_id );
        }
        $usage  = $completed['usage'] ?? array();
        $result = new GenerativeAiResult(
            (string) ( $completed['id'] ?? '' ),
            array( new Candidate( new ModelMessage( $parts ), FinishReasonEnum::stop() ) ),
            new TokenUsage( (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ), (int) ( $usage['total_tokens'] ?? 0 ), (int) ( $usage['output_tokens_details']['reasoning_tokens'] ?? 0 ) ),
            $this->providerMetadata(),
            $this->metadata
        );
        MainWP_Assistant_ChatGPT_Plan::record_error( $this->user_id, array( 'code' => '' ) );
        return $result;
    }

    /**
     * Stateless inference requires both sides of every tool exchange.
     *
     * @param array $prompt Message DTOs.
     * @return array
     */
    private function history( $prompt ) {
        $input = array();
        foreach ( $prompt as $message ) {
            foreach ( $message->getParts() as $part ) {
                if ( $part->getType()->isText() ) {
                    $input[] = array(
                        'role'    => $message->getRole()->isModel() ? 'assistant' : 'user',
                        'content' => $part->getText(),
                    );
                } elseif ( $part->getType()->isFunctionCall() ) {
                    $call    = $part->getFunctionCall();
                    $args    = $call->getArgs();
                    $input[] = array(
                        'type'      => 'function_call',
                        'call_id'   => $call->getId(),
                        'name'      => $call->getName(),
                        'arguments' => wp_json_encode( empty( $args ) ? new \stdClass() : $args ),
                    );
                } elseif ( $part->getType()->isFunctionResponse() ) {
                    $response = $part->getFunctionResponse();
                    $input[]  = array(
                        'type'    => 'function_call_output',
                        'call_id' => $response->getId(),
                        'output'  => wp_json_encode( $response->getResponse() ),
                    );
                }
            }
        }
        return $input;
    }

    /**
     * Deltas are provisional until the complete terminal response arrives.
     *
     * @param string $raw Buffered SSE body.
     * @param string $request_id Provider diagnostic identifier.
     * @return array
     */
    private function parse_stream( $raw, $request_id ) {
        $completed    = null;
        $output_items = array();
        foreach ( preg_split( '/\n\n/', str_replace( array( "\r\n", "\r" ), "\n", $raw ) ) as $frame ) {
            $event = '';
            $data  = array();
            foreach ( explode( "\n", $frame ) as $line ) {
                if ( 0 === strpos( $line, 'event:' ) ) {
                    $event = trim( substr( $line, 6 ) );
                } elseif ( 0 === strpos( $line, 'data:' ) ) {
                    $data[] = ltrim( substr( $line, 5 ), ' ' );
                }
            }
            if ( empty( $data ) || '[DONE]' === implode( "\n", $data ) ) {
                continue;
            }
            $data = json_decode( implode( "\n", $data ), true );
            if ( ! is_array( $data ) ) {
                self::fail( $this->user_id, 'invalid_response', 503, $request_id );
            }
            $event = '' !== $event ? $event : ( $data['type'] ?? '' );
            if ( in_array( $event, array( 'response.failed', 'response.incomplete', 'error' ), true ) ) {
                $error = $data['response']['error'] ?? $data['error'] ?? $data;
                // Stream events carry no HTTP status; an unknown code is a provider-side failure, not a bad request.
                self::fail( $this->user_id, $error['code'] ?? 'incomplete_response', 503, $request_id, $error['param'] ?? '' );
            }
            if ( 'response.output_item.done' === $event
                && is_int( $data['output_index'] ?? null )
                && 0 <= $data['output_index']
                && is_array( $data['item'] ?? null )
                && 'completed' === ( $data['item']['status'] ?? 'completed' )
            ) {
                $output_items[ $data['output_index'] ] = $data['item'];
            }
            if ( 'response.completed' === $event && is_array( $data['response'] ?? null ) ) {
                $completed = $data['response'];
            }
        }
        if ( null === $completed ) {
            self::fail( $this->user_id, 'interrupted_stream', 503, $request_id );
        }
        // The final response can have empty output after streaming finalized items.
        if ( array() === ( $completed['output'] ?? null ) && ! empty( $output_items ) ) {
            ksort( $output_items, SORT_NUMERIC );
            $completed['output'] = array_values( $output_items );
        }
        return $completed;
    }

    /**
     * Only machine codes cross the WP exception wrapper; provider messages can contain secrets.
     *
     * @param int    $user_id Request owner.
     * @param string $code Provider machine code.
     * @param int    $http HTTP status, or zero when the failure has none.
     * @param string $request_id Provider diagnostic identifier.
     * @param string $param Unsupported input field.
     * @return never
     * @throws ClientException On every failed inference.
     */
    public static function fail( $user_id, $code, $http = 0, $request_id = '', $param = '' ) {
        $code = is_string( $code ) ? sanitize_key( $code ) : 'unknown';
        MainWP_Assistant_ChatGPT_Plan::record_error(
            $user_id,
            array(
                'code'        => $code,
                'http_status' => $http,
                'request_id'  => $request_id,
            )
        );
        if ( 'subscription_sharing_unsupported_capability' === $code && is_string( $param ) && '' !== $param ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only the unsupported field name is logged, never provider text or credentials.
            error_log( 'MainWP Assistant: unsupported ChatGPT field ' . substr( preg_replace( '/[^A-Za-z0-9_.\[\]-]/', '', $param ), 0, 100 ) );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A sanitized machine code and HTTP integer are consumed by the WP error mapper.
        throw new ClientException( $code, $http > 0 ? $http : 400 );
    }
}
