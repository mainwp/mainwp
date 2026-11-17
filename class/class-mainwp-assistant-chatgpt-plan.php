<?php
/**
 * Per-user ChatGPT plan credentials.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use phpseclib3\Crypt\RSA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps each registration and its rotating credentials together.
 */
class MainWP_Assistant_ChatGPT_Plan extends MainWP_Post_Base_Handler {
    const REDIRECT_URI = 'http://127.0.0.1:1456/auth/callback';
    const RESOURCE     = 'https://api.openai.com/v1';
    const AUTHORIZE    = 'https://auth.openai.com/api/accounts/authorize';
    const TOKEN        = 'https://auth.openai.com/api/accounts/oauth/token';
    const REVOKE       = 'https://auth.openai.com/api/accounts/oauth/revoke';
    const JWKS         = 'https://auth.openai.com/.well-known/jwks.json';
    const SCOPES       = 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct';
    const META         = 'mainwp_chatgpt_plan';
    const PENDING      = 'mainwp_chatgpt_plan_pending';
    const JWKS_CACHE   = 'mainwp_chatgpt_plan_jwks';
    const LOCK_PREFIX  = 'mainwp_chatgpt_plan_refresh_lock_';

    /**
     * Shared nonce registration must use the same handler instance.
     *
     * @var self|null
     */
    private static $instance;

    /**
     * Shares nonce registration with the other Assistant handlers.
     *
     * @return self
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    /**
     * Deletion cleanup must remain available when the Assistant is disabled.
     *
     * @return void
     */
    protected function init() {
        foreach ( array( 'start', 'complete', 'disconnect', 'status', 'set_model', 'welcome_seen' ) as $action ) {
            $this->add_action( 'mainwp_assistant_chatgpt_' . $action, array( $this, 'handle_ajax' ) );
        }
        add_action( 'delete_user', array( __CLASS__, 'delete_user' ) );
    }

    /**
     * The request owner always comes from the authenticated session.
     *
     * @return void
     */
    public function handle_ajax() {
        $action = substr( current_action(), strlen( 'wp_ajax_' ) );
        check_ajax_referer( $action, 'security' );
        if ( ! MainWP_Assistant::current_user_can_use_assistant() ) {
            wp_send_json_error( array( 'error_code' => 'forbidden' ), 403 );
        }
        $user_id = get_current_user_id();
        switch ( $action ) {
            case 'mainwp_assistant_chatgpt_start':
                $url    = self::start_connect( $user_id, isset( $_POST['new_registration'] ) && is_scalar( $_POST['new_registration'] ) && wp_validate_boolean( sanitize_text_field( wp_unslash( $_POST['new_registration'] ) ) ) );
                $result = is_wp_error( $url ) ? $url : array_merge( self::get_status( $user_id ), array( 'authorize_url' => $url ) );
                break;
            case 'mainwp_assistant_chatgpt_complete':
                // Text sanitization removes percent-encoded bytes from opaque authorization codes.
                $pasted = isset( $_POST['callback'] ) && is_string( $_POST['callback'] ) ? wp_unslash( $_POST['callback'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- callback_query validates the URI and state before any exchange.
                $result = self::complete_connect( $user_id, $pasted );
                break;
            case 'mainwp_assistant_chatgpt_disconnect':
                $result = self::disconnect( $user_id );
                break;
            case 'mainwp_assistant_chatgpt_set_model':
                $slug   = isset( $_POST['model'] ) && is_string( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
                $result = self::set_model( $user_id, $slug );
                break;
            case 'mainwp_assistant_chatgpt_welcome_seen':
                $result = self::mark_welcome_shown( $user_id );
                break;
            default:
                self::list_models( $user_id );
                $result = self::get_status( $user_id );
        }
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array_merge( self::get_status( $user_id ), array( 'error_code' => $result->get_error_code() ) ) );
        }
        wp_send_json_success( $result );
    }

    /**
     * Binds each attempt to the current login session.
     *
     * @param int  $user_id Owning user.
     * @param bool $new_registration Revoke the previous account or workspace registration.
     * @return string|\WP_Error
     */
    public static function start_connect( $user_id, $new_registration = false ) {
        return self::locked(
            $user_id,
            static function () use ( $user_id, $new_registration ) {
                $record  = self::read( $user_id );
                $revoked = true;
                if ( $new_registration && ! empty( $record ) ) {
                    // A workspace change can retain the same subject, so retire the registration before replacing its ID.
                    $revoked = self::revoke( $record );
                    if ( ! self::save( $user_id, array() ) ) {
                        return self::error( 'storage_failed' );
                    }
                    self::retire_key( $record );
                    $record = array();
                    if ( ! $revoked ) {
                        self::record_error_locked( $user_id, self::error( 'revoke_unconfirmed' ) );
                    }
                }
                $pending = array(
                    'state'     => self::random(),
                    'nonce'     => self::random(),
                    'verifier'  => self::random(),
                    'client_id' => $record['client_id'] ?? 'dynamic_agent_client',
                    'created'   => time(),
                    'session'   => hash( 'sha256', wp_get_session_token() ),
                );
                if ( ! $revoked ) {
                    $pending['revoke_unconfirmed'] = true;
                }
                if ( ! update_user_meta( $user_id, self::PENDING, $pending ) ) {
                    return self::error( 'storage_failed' );
                }
                $host = get_option( 'mainwp_chatgpt_plan_host_id' );
                if ( ! $host ) {
                    add_option( 'mainwp_chatgpt_plan_host_id', 'urn:uuid:' . wp_generate_uuid4(), '', false );
                    $host = get_option( 'mainwp_chatgpt_plan_host_id' );
                }
                if ( ! $host ) {
                    return self::error( 'storage_failed' );
                }
                $params = array(
                    'client_id'             => $pending['client_id'],
                    'ext_agent_host_id'     => $host,
                    'response_type'         => 'code',
                    'redirect_uri'          => self::REDIRECT_URI,
                    'scope'                 => self::SCOPES,
                    'resource'              => self::RESOURCE,
                    'state'                 => $pending['state'],
                    'nonce'                 => $pending['nonce'],
                    'code_challenge_method' => 'S256',
                    'code_challenge'        => self::base64url( hash( 'sha256', $pending['verifier'], true ) ),
                );
                if ( 'dynamic_agent_client' === $pending['client_id'] ) {
                    $params['agent_name_hint'] = 'MainWP Dashboard';
                } elseif ( ! empty( $record['email'] ) ) {
                    $params['login_hint'] = $record['email'];
                }
                if ( 'plan_disabled' === ( $record['status'] ?? '' ) ) {
                    $params['prompt'] = 'consent';
                }
                return self::AUTHORIZE . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
            }
        );
    }

    /**
     * Rejects stale or replayed callbacks before exchanging a code.
     *
     * @param int    $user_id Owning user.
     * @param string $pasted Callback URL or query.
     * @return array|\WP_Error
     */
    public static function complete_connect( $user_id, $pasted ) {
        return self::locked(
            $user_id,
            static function () use ( $user_id, $pasted ) {
                $pending = get_user_meta( $user_id, self::PENDING, true );
                $query   = self::callback_query( $pasted );
                if ( is_wp_error( $query ) ) {
                    return $query;
                }
                if ( ! is_array( $pending ) || empty( $pending['state'] ) || ! isset( $query['state'] ) || ! is_string( $query['state'] ) || ! hash_equals( $pending['state'], $query['state'] ) || ! hash_equals( $pending['session'], hash( 'sha256', wp_get_session_token() ) ) || $pending['created'] > time() || time() - $pending['created'] >= 15 * MINUTE_IN_SECONDS ) {
                    return self::error( 'invalid_callback' );
                }
                // Consume before any exchange, including callbacks that report a denied grant.
                if ( ! delete_user_meta( $user_id, self::PENDING, $pending ) ) {
                    return self::error( 'invalid_callback' );
                }
                if ( isset( $query['error'] ) ) {
                    return self::error( 'access_denied' === $query['error'] ? 'access_denied' : 'authorization_failed' );
                }
                $client_id = $pending['client_id'];
                if ( 'dynamic_agent_client' === $client_id ) {
                    if ( empty( $query['client_id'] ) || ! is_string( $query['client_id'] ) || 'dynamic_agent_client' === $query['client_id'] || ! preg_match( '/^[A-Za-z0-9_.:-]+$/D', $query['client_id'] ) ) {
                        return self::error( 'invalid_client' );
                    }
                    $client_id = $query['client_id'];
                } elseif ( isset( $query['client_id'] ) && $query['client_id'] !== $client_id ) {
                    return self::error( 'invalid_client' );
                }
                if ( empty( $query['code'] ) || ! is_string( $query['code'] ) ) {
                    return self::error( 'invalid_callback' );
                }
                // OpenAI registers the client before redirecting, so retain it through grant and transport failures.
                if ( 'dynamic_agent_client' === $pending['client_id'] ) {
                    $record              = self::read( $user_id );
                    $record['client_id'] = $client_id;
                    if ( ! self::save( $user_id, $record ) ) {
                        return self::error( 'storage_failed' );
                    }
                }
                $tokens = self::request(
                    self::TOKEN,
                    array(
                        'grant_type'    => 'authorization_code',
                        'client_id'     => $client_id,
                        'code'          => $query['code'],
                        'code_verifier' => $pending['verifier'],
                        'redirect_uri'  => self::REDIRECT_URI,
                        'resource'      => self::RESOURCE,
                    )
                );
                if ( is_wp_error( $tokens ) ) {
                    $data = (array) $tokens->get_error_data();
                    if ( 'dynamic_agent_client' === $pending['client_id'] && 'invalid_client' === $tokens->get_error_code() && 400 <= ( $data['http_status'] ?? 0 ) && 500 > $data['http_status'] ) {
                        // A rejected pasted ID cannot be reused; ambiguous failures still retain the issued registration.
                        $record = self::read( $user_id );
                        unset( $record['client_id'] );
                        if ( ! self::save( $user_id, $record ) ) {
                            return self::error( 'storage_failed' );
                        }
                    }
                    self::record_error_locked( $user_id, $tokens );
                    return $tokens;
                }
                if ( ! self::valid_tokens( $tokens ) ) {
                    $error = self::error( 'invalid_token_response' );
                    self::record_error_locked( $user_id, $error );
                    return $error;
                }
                $claims = self::verify_id_token( $tokens['id_token'] ?? '', $client_id, $pending['nonce'] );
                if ( is_wp_error( $claims ) ) {
                    self::record_error_locked( $user_id, $claims );
                    return $claims;
                }
                $old     = self::read( $user_id );
                $revoked = empty( $pending['revoke_unconfirmed'] );
                if ( ! empty( $old['sub'] ) && $old['sub'] !== $claims['sub'] ) {
                    $revoked = self::revoke( $old ) && $revoked;
                    if ( ! self::clear_tokens( $user_id, $old ) ) {
                        return self::error( 'storage_failed' );
                    }
                    $old = self::read( $user_id );
                }
                $record = array(
                    'email'         => isset( $claims['email'] ) && is_string( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '',
                    'sub'           => $claims['sub'],
                    'client_id'     => $client_id,
                    'models'        => array(),
                    'model'         => '',
                    'welcome_shown' => ! empty( $old['welcome_shown'] ) && ( $old['sub'] ?? '' ) === $claims['sub'],
                    'connected_at'  => time(),
                    'last_error'    => array(),
                );
                $saved  = self::save_tokens( $user_id, $record, $tokens, $old );
                if ( is_wp_error( $saved ) ) {
                    self::record_error_locked( $user_id, $saved );
                    return $saved;
                }
                if ( 'connected' === $saved['status'] ) {
                    self::refresh_models_locked( $user_id, $saved );
                }
                return array_merge(
                    self::get_status( $user_id ),
                    array(
                        'remote_revoke_confirmed' => $revoked,
                        'notice'                  => $revoked ? '' : __( 'Remote revocation was not confirmed. Remove this app in ChatGPT Settings.', 'mainwp' ),
                    )
                );
            }
        );
    }

    /**
     * The callback must match the exact URI used for authorization.
     *
     * @param string $pasted Callback URL or query.
     * @return array|\WP_Error
     */
    private static function callback_query( $pasted ) {
        // Copying from an address bar picks up line breaks, quotes, a missing
        // http:// or a trailing slash. None of that changes which
        // sign-in the address belongs to; the state check in complete_connect() does.
        $pasted = trim( (string) preg_replace( '/\s+/', '', (string) $pasted ), '"\'<>' );
        if ( '' === $pasted || strlen( $pasted ) > 16384 ) {
            return self::error( 'invalid_callback' );
        }
        if ( preg_match( '#^127\.0\.0\.1:1456/#', $pasted ) ) {
            $pasted = 'http://' . $pasted;
        }
        if ( false !== strpos( $pasted, '://' ) ) {
            $url  = wp_parse_url( $pasted );
            $host = is_array( $url ) ? strtolower( (string) ( $url['host'] ?? '' ) ) : '';
            if ( preg_match( '/(?:^|\.)(?:openai|chatgpt)\.com$/', $host ) ) {
                return self::error( 'signin_address' );
            }
            if ( ! is_array( $url ) || 'http' !== strtolower( (string) ( $url['scheme'] ?? '' ) ) || '127.0.0.1' !== $host || '/auth/callback' !== rtrim( (string) ( $url['path'] ?? '' ), '/' ) || 1456 !== ( $url['port'] ?? 0 ) || isset( $url['user'] ) || isset( $url['pass'] ) ) {
                return self::error( 'invalid_callback' );
            }
            $pasted = $url['query'] ?? '';
        } elseif ( false !== strpos( $pasted, '#' ) || false !== strpos( $pasted, '/' ) ) {
            return self::error( 'invalid_callback' );
        }
        parse_str( ltrim( $pasted, '?' ), $query );
        return $query;
    }

    /**
     * Remote failures must not retain locally usable credentials.
     *
     * @param int $user_id Owning user.
     * @return array|\WP_Error
     */
    public static function disconnect( $user_id ) {
        return self::locked(
            $user_id,
            static function () use ( $user_id ) {
                $record  = self::read( $user_id );
                $revoked = self::revoke( $record );
                if ( ! self::clear_tokens( $user_id, $record ) ) {
                    return self::error( 'storage_failed' );
                }
                delete_user_meta( $user_id, self::PENDING );
                return array_merge(
                    self::get_status( $user_id ),
                    array(
                        'remote_revoke_confirmed' => $revoked,
                        'notice'                  => $revoked ? '' : __( 'Remote revocation was not confirmed. Remove this app in ChatGPT Settings.', 'mainwp' ),
                    )
                );
            }
        );
    }

    /**
     * Only explicitly allowed metadata may leave the server.
     *
     * @param int $user_id Owning user.
     * @return array
     */
    public static function get_status( $user_id ) {
        $record = self::owns( $user_id ) ? self::read( $user_id ) : array();
        $status = array_intersect_key( $record, array_flip( array( 'email', 'sub', 'client_id', 'scopes', 'expires_at', 'status', 'models', 'model', 'welcome_shown', 'last_error', 'connected_at' ) ) );
        return array_merge(
            array(
                'status' => 'disconnected',
                'models' => array(),
                'model'  => '',
            ),
            $status,
            array(
                'connected'        => 'connected' === ( $record['status'] ?? '' ),
                'welcome_pending'  => 'connected' === ( $record['status'] ?? '' ) && empty( $record['welcome_shown'] ),
                'manage_usage_url' => 'https://chatgpt.com/settings/usage',
            )
        );
    }

    /**
     * Fresh credentials avoid a rotating-token exchange.
     *
     * @param int $user_id Owning user.
     * @return string|\WP_Error
     */
    public static function get_access_token( $user_id ) {
        if ( ! self::owns( $user_id ) ) {
            return self::error( 'forbidden' );
        }
        $record = self::read( $user_id );
        if ( 'connected' === ( $record['status'] ?? '' ) && ( $record['expires_at'] ?? 0 ) > time() + 120 ) {
            $secrets = self::secrets( $record );
            if ( ! empty( $secrets['access_token'] ) ) {
                return $secrets['access_token'];
            }
        }
        return self::locked(
            $user_id,
            static function () use ( $user_id ) {
                return self::access_token_locked( $user_id );
            }
        );
    }

    /**
     * Re-reads credentials after waiting for a competing request.
     *
     * @param int $user_id Owning user.
     * @return string|\WP_Error
     */
    private static function access_token_locked( $user_id ) {
        $record = self::read( $user_id );
        if ( 'connected' !== ( $record['status'] ?? '' ) ) {
            return self::error( 'plan_disabled' === ( $record['status'] ?? '' ) ? 'plan_disabled' : 'needs_reconnect' );
        }
        $secrets = self::secrets( $record );
        if ( empty( $secrets['refresh_token'] ) || empty( $secrets['access_token'] ) ) {
            // Keep the unreadable envelope so a later disconnect reports the revocation as unconfirmed.
            $record['status'] = 'needs_reconnect';
            self::save( $user_id, $record );
            return self::error( 'needs_reconnect' );
        }
        if ( $record['expires_at'] > time() + 120 ) {
            return $secrets['access_token'];
        }
        $tokens = self::request(
            self::TOKEN,
            array(
                'grant_type'    => 'refresh_token',
                'client_id'     => $record['client_id'],
                'refresh_token' => $secrets['refresh_token'],
                'resource'      => self::RESOURCE,
            )
        );
        if ( is_wp_error( $tokens ) ) {
            $data = $tokens->get_error_data();
            if ( 500 > ( $data['http_status'] ?? 0 ) && in_array( $tokens->get_error_code(), array( 'invalid_grant', 'invalid_refresh_token', 'token_expired', 'refresh_token_expired', 'refresh_token_invalidated', 'refresh_token_reused' ), true ) ) {
                self::clear_tokens( $user_id, $record, 'needs_reconnect' );
            }
            self::record_error_locked( $user_id, $tokens );
            return $tokens;
        }
        // A refresh response may omit scope when the grant is unchanged (RFC 6749 section 5.1).
        if ( is_array( $tokens ) && ! isset( $tokens['scope'] ) && ! empty( $record['scopes'] ) && is_array( $record['scopes'] ) ) {
            $tokens['scope'] = implode( ' ', $record['scopes'] );
        }
        $saved = self::save_tokens( $user_id, $record, $tokens, $record );
        if ( is_wp_error( $saved ) ) {
            self::record_error_locked( $user_id, $saved );
            return $saved;
        }
        return 'connected' === $saved['status'] ? $tokens['access_token'] : self::error( 'plan_disabled' );
    }

    /**
     * Model choices belong to the authenticated user.
     *
     * @param int $user_id Owning user.
     * @return array|\WP_Error
     */
    public static function list_models( $user_id ) {
        $status = self::get_status( $user_id );
        // Discovery at connect time can fail after the tokens are saved, so retry before reporting no models.
        if ( $status['connected'] && empty( $status['models'] ) ) {
            return self::refresh_models( $user_id );
        }
        return $status['models'];
    }

    /**
     * Model discovery must use the latest access token.
     *
     * @param int $user_id Owning user.
     * @return array|\WP_Error
     */
    public static function refresh_models( $user_id ) {
        return self::locked(
            $user_id,
            static function () use ( $user_id ) {
                $token = self::access_token_locked( $user_id );
                return is_wp_error( $token ) ? $token : self::refresh_models_locked( $user_id, self::read( $user_id ) );
            }
        );
    }

    /**
     * Preserves provider order and rejects hidden choices.
     *
     * @param int   $user_id Owning user.
     * @param array $record Current registration.
     * @return array|\WP_Error
     */
    private static function refresh_models_locked( $user_id, $record ) {
        $secrets  = self::secrets( $record );
        $response = self::request( self::RESOURCE . '/models', null, $secrets['access_token'] ?? '' );
        if ( is_wp_error( $response ) ) {
            self::record_error_locked( $user_id, $response );
            return $response;
        }
        if ( ! isset( $response['models'] ) || ! is_array( $response['models'] ) ) {
            $error = self::error( 'invalid_models' );
            self::record_error_locked( $user_id, $error );
            return $error;
        }
        $record['models'] = array();
        foreach ( $response['models'] as $model ) {
            if ( is_array( $model ) && 'list' === ( $model['visibility'] ?? '' ) && ! empty( $model['slug'] ) && is_string( $model['slug'] ) && isset( $model['display_name'] ) && is_string( $model['display_name'] ) ) {
                $record['models'][] = array(
                    'slug'         => $model['slug'],
                    'display_name' => $model['display_name'],
                );
            }
        }
        if ( ! in_array( $record['model'], array_column( $record['models'], 'slug' ), true ) ) {
            $record['model'] = $record['models'][0]['slug'] ?? '';
        }
        $record['last_error'] = array();
        return self::save( $user_id, $record ) ? $record['models'] : self::error( 'storage_failed' );
    }

    /**
     * Only a model visible to this account may be selected.
     *
     * @param int    $user_id Owning user.
     * @param string $slug Available model.
     * @return array|\WP_Error
     */
    public static function set_model( $user_id, $slug ) {
        return self::locked(
            $user_id,
            static function () use ( $user_id, $slug ) {
                $record = self::read( $user_id );
                if ( ! in_array( $slug, array_column( $record['models'] ?? array(), 'slug' ), true ) ) {
                    return self::error( 'invalid_model' );
                }
                $record['model'] = $slug;
                return self::save( $user_id, $record ) ? self::get_status( $user_id ) : self::error( 'storage_failed' );
            }
        );
    }

    /**
     * Acknowledgment belongs to the connected user.
     *
     * @param int $user_id Owning user.
     * @return array|\WP_Error
     */
    public static function mark_welcome_shown( $user_id ) {
        return self::locked(
            $user_id,
            static function () use ( $user_id ) {
                $record                  = self::read( $user_id );
                $record['welcome_shown'] = true;
                return self::save( $user_id, $record ) ? self::get_status( $user_id ) : self::error( 'storage_failed' );
            }
        );
    }

    /**
     * Provider messages may contain credentials, so only diagnostics are retained.
     *
     * @param int             $user_id Owning user.
     * @param \WP_Error|array $error Error metadata; messages are never retained.
     * @return bool
     */
    public static function record_error( $user_id, $error ) {
        $result = self::locked(
            $user_id,
            static function () use ( $user_id, $error ) {
                return self::record_error_locked( $user_id, $error );
            }
        );
        return true === $result;
    }

    /**
     * Keeps diagnostics from overwriting a concurrently rotated record.
     *
     * @param int             $user_id Owning user.
     * @param \WP_Error|array $error Safe error metadata.
     * @return bool
     */
    private static function record_error_locked( $user_id, $error ) {
        if ( ! self::owns( $user_id ) ) {
            return false;
        }
        $data                 = is_wp_error( $error ) ? (array) $error->get_error_data() : $error;
        $code                 = is_wp_error( $error ) ? $error->get_error_code() : ( $data['code'] ?? 'unknown' );
        $record               = self::read( $user_id );
        $record['last_error'] = array(
            'code'        => substr( sanitize_key( $code ), 0, 100 ),
            'http_status' => (int) ( $data['http_status'] ?? 0 ),
            'request_id'  => substr( sanitize_text_field( $data['request_id'] ?? '' ), 0, 200 ),
            'at'          => time(),
        );
        if ( '' === $code ) {
            $record['last_error'] = array();
        } elseif ( 'connected' === ( $record['status'] ?? '' ) && 'chatpass_v2_scope_not_authorized' === $code ) {
            $record['status'] = 'plan_disabled';
        } elseif ( 'connected' === ( $record['status'] ?? '' ) && ( 401 === (int) ( $data['http_status'] ?? 0 ) || in_array( $code, array( 'needs_reconnect', 'subscription_sharing_invalid_user', 'chatpass_v2_invalid_authorization_context' ), true ) ) ) {
            $record['status'] = 'needs_reconnect';
        }
        return self::save( $user_id, $record );
    }

    /**
     * User deletion must not leave a credential key behind.
     *
     * @param int $user_id Deleted user.
     * @return void
     */
    public static function delete_user( $user_id ) {
        $record = self::read( $user_id );
        delete_user_meta( $user_id, self::META );
        delete_user_meta( $user_id, self::PENDING );
        self::retire_key( $record );
    }

    /**
     * The refresh token must stay paired with its issued client ID.
     *
     * @param array $record Registration to clear.
     * @return bool
     */
    private static function revoke( $record ) {
        $secrets = self::secrets( $record );
        if ( empty( $secrets['refresh_token'] ) ) {
            // An unreadable envelope still holds a live refresh token at OpenAI.
            return empty( $record['tokens'] );
        }
        return ! is_wp_error(
            self::request(
                self::REVOKE,
                array(
                    'token'           => $secrets['refresh_token'],
                    'token_type_hint' => 'refresh_token',
                    'client_id'       => $record['client_id'],
                )
            )
        );
    }

    /**
     * Account identity and the welcome acknowledgment survive local sign-out.
     *
     * @param int    $user_id Owning user.
     * @param array  $record Previous registration.
     * @param string $status Optional reconnect state.
     * @return bool
     */
    private static function clear_tokens( $user_id, $record, $status = '' ) {
        $cleared = array_intersect_key( $record, array_flip( array( 'client_id', 'sub', 'email', 'welcome_shown' ) ) );
        if ( '' !== $status ) {
            $cleared['status'] = $status;
        }
        if ( ! self::save( $user_id, $cleared ) ) {
            return false;
        }
        self::retire_key( $record );
        return true;
    }

    /**
     * A single metadata write keeps rotating token fields together.
     *
     * @param int   $user_id Owning user.
     * @param array $record Metadata for the replacement.
     * @param array $tokens Token response.
     * @param array $old Previous registration.
     * @return array|\WP_Error
     */
    private static function save_tokens( $user_id, $record, $tokens, $old ) {
        if ( ! self::valid_tokens( $tokens ) ) {
            return self::error( 'invalid_token_response' );
        }
        $record['scopes']     = preg_split( '/\s+/', trim( $tokens['scope'] ), -1, PREG_SPLIT_NO_EMPTY );
        $record['expires_at'] = time() + (int) $tokens['expires_in'];
        $record['status']     = in_array( 'chatgpt.tokens.use.direct', $record['scopes'], true ) ? 'connected' : 'plan_disabled';
        $record['last_error'] = array();
        // A new key leaves the previous envelope readable if the database write fails.
        $envelope = MainWP_Keys_Manager::instance()->encrypt_keys_data(
            wp_json_encode(
                array(
                    'access_token'  => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                )
            ),
            'chatgpt_' . $user_id . '_' . wp_generate_uuid4()
        );
        if ( ! is_array( $envelope ) ) {
            return self::error( 'storage_failed' );
        }
        $record['tokens'] = $envelope;
        if ( ! self::save( $user_id, $record ) ) {
            self::retire_key( $record );
            return self::error( 'storage_failed' );
        }
        self::retire_key( $old );
        return $record;
    }

    /**
     * Invalid replacements must leave the current registration usable.
     *
     * @param array $tokens Provider response.
     * @return bool
     */
    private static function valid_tokens( $tokens ) {
        return ! ( empty( $tokens['access_token'] ) || ! is_string( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) || ! is_string( $tokens['refresh_token'] ) || ! isset( $tokens['expires_in'], $tokens['scope'] ) || ! is_numeric( $tokens['expires_in'] ) || (int) $tokens['expires_in'] <= 0 || ! is_string( $tokens['scope'] ) || ! isset( $tokens['token_type'] ) || ! is_string( $tokens['token_type'] ) || 'bearer' !== strtolower( $tokens['token_type'] ) );
    }

    /**
     * ID tokens are discarded before any credentials reach storage.
     *
     * @param array $record Registration.
     * @return array
     */
    private static function secrets( $record ) {
        $plain = MainWP_Keys_Manager::instance()->decrypt_keys_data( $record['tokens'] ?? array(), '' );
        $data  = is_string( $plain ) ? json_decode( $plain, true ) : null;
        return is_array( $data ) ? $data : array();
    }

    /**
     * Old keys remain usable until the replacement record is saved.
     *
     * @param array $record Retired registration.
     * @return void
     */
    private static function retire_key( $record ) {
        if ( ! empty( $record['tokens']['file_key'] ) ) {
            MainWP_Keys_Manager::instance()->delete_key_file( $record['tokens']['file_key'] );
        }
    }

    /**
     * Identity is trusted only after signature and claim validation.
     *
     * @param string $jwt Signed ID token.
     * @param string $client_id Selected registration.
     * @param string $nonce Pending nonce.
     * @return array|\WP_Error
     */
    private static function verify_id_token( $jwt, $client_id, $nonce ) {
        if ( ! is_string( $jwt ) || 3 !== count( explode( '.', $jwt ) ) ) {
            return self::error( 'invalid_id_token' );
        }
        list( $head, $body, $signature ) = explode( '.', $jwt );
        $header                          = json_decode( self::decode( $head ), true );
        $claims                          = json_decode( self::decode( $body ), true );
        if ( ! is_array( $header ) || 'RS256' !== ( $header['alg'] ?? '' ) || empty( $header['kid'] ) || ! is_string( $header['kid'] ) || ! is_array( $claims ) ) {
            return self::error( 'invalid_id_token' );
        }
        $keys = get_transient( self::JWKS_CACHE );
        for ( $attempt = 0; $attempt < 2; ++$attempt ) {
            if ( ! is_array( $keys ) || 0 < $attempt ) {
                $keys = self::request( self::JWKS );
                if ( is_wp_error( $keys ) ) {
                    return $keys;
                }
                if ( ! isset( $keys['keys'] ) || ! is_array( $keys['keys'] ) ) {
                    return self::error( 'invalid_jwks' );
                }
                set_transient( self::JWKS_CACHE, $keys, HOUR_IN_SECONDS );
            }
            foreach ( $keys['keys'] ?? array() as $jwk ) {
                if ( is_array( $jwk ) && ( $jwk['kid'] ?? '' ) === $header['kid'] && 'RSA' === ( $jwk['kty'] ?? '' ) && 'sig' === ( $jwk['use'] ?? 'sig' ) && 'RS256' === ( $jwk['alg'] ?? 'RS256' ) ) {
                    try {
                        MainWP_Keys_Manager::auto_load_files();
                        $key = RSA::loadFormat( 'JWK', wp_json_encode( array_intersect_key( $jwk, array_flip( array( 'kty', 'n', 'e' ) ) ) ) );
                        if ( ! $key->withHash( 'sha256' )->withPadding( RSA::SIGNATURE_PKCS1 )->verify( $head . '.' . $body, self::decode( $signature ) ) ) {
                            return self::error( 'invalid_id_token' );
                        }
                    } catch ( \Exception $exception ) {
                        return self::error( 'invalid_id_token' );
                    }
                    if ( 'https://auth.openai.com' !== ( $claims['iss'] ?? '' ) || ! in_array( $client_id, (array) ( $claims['aud'] ?? array() ), true ) || ! isset( $claims['exp'], $claims['iat'] ) || ! is_numeric( $claims['exp'] ) || ! is_numeric( $claims['iat'] ) || $claims['exp'] <= time() - 5 || $claims['iat'] > time() + 5 || ! isset( $claims['nonce'] ) || ! is_string( $claims['nonce'] ) || ! hash_equals( $nonce, $claims['nonce'] ) || empty( $claims['sub'] ) || ! is_string( $claims['sub'] ) || ( isset( $claims['sid'] ) && ( ! is_string( $claims['sid'] ) || '' === $claims['sid'] ) ) ) {
                        return self::error( 'invalid_id_token' );
                    }
                    return $claims;
                }
            }
        }
        return self::error( 'unknown_kid' );
    }

    /**
     * Redirects must never forward credentials to another endpoint.
     *
     * @param string     $url Fixed provider endpoint.
     * @param array|null $body Form fields, or null for GET.
     * @param string     $bearer Access token for model discovery.
     * @return array|\WP_Error
     */
    private static function request( $url, $body = null, $bearer = '' ) {
        $args = array(
            'timeout'             => 5,
            'redirection'         => 0,
            'sslverify'           => true,
            'limit_response_size' => 1048576,
            'headers'             => array( 'Accept' => 'application/json' ),
        );
        if ( '' !== $bearer ) {
            $args['headers']['Authorization'] = 'Bearer ' . $bearer;
        }
        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body']                    = http_build_query( $body, '', '&', PHP_QUERY_RFC3986 );
            $response                        = wp_safe_remote_post( $url, $args );
        } else {
            $response = wp_safe_remote_get( $url, $args );
        }
        if ( is_wp_error( $response ) ) {
            return self::error( 'network_error' );
        }
        $http       = (int) wp_remote_retrieve_response_code( $response );
        $data       = json_decode( wp_remote_retrieve_body( $response ), true );
        $request_id = wp_remote_retrieve_header( $response, 'openai-request-id' );
        if ( '' === $request_id ) {
            $request_id = wp_remote_retrieve_header( $response, 'x-request-id' );
        }
        if ( 200 !== $http ) {
            $code = $data['error'] ?? 'http_error';
            $code = is_array( $code ) ? ( $code['code'] ?? 'http_error' ) : $code;
            return self::error(
                is_string( $code ) ? sanitize_key( $code ) : 'http_error',
                array(
                    'http_status' => $http,
                    'request_id'  => $request_id,
                )
            );
        }
        if ( self::REVOKE === $url ) {
            return array();
        }
        return is_array( $data ) ? $data : self::error( 'invalid_response', array( 'http_status' => $http ) );
    }

    /**
     * Credential mutations must serialize with refresh.
     *
     * @param int      $user_id Owning user.
     * @param callable $callback Operation under the credential lock.
     * @return mixed
     */
    private static function locked( $user_id, $callback ) {
        if ( ! self::owns( $user_id ) ) {
            return self::error( 'forbidden' );
        }
        $deadline = microtime( true ) + 10;
        do {
            $token = self::acquire_lock( $user_id );
            if ( null !== $token ) {
                try {
                    return $callback();
                } finally {
                    self::release_lock( $user_id, $token );
                }
            }
            usleep( 100000 );
        } while ( microtime( true ) < $deadline );
        return self::error( 'connection_busy' );
    }

    /**
     * Stale takeover must not remove a competing owner.
     *
     * @param int $user_id Owning user.
     * @return string|null
     */
    private static function acquire_lock( $user_id ) {
        global $wpdb;
        $name  = self::LOCK_PREFIX . $user_id;
        $token = time() . ':' . self::random();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached option upserts cannot serialize rotating refresh tokens.
        $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) );
        if ( 1 === (int) $wpdb->rows_affected ) {
            return $token;
        }
        $held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( ! is_string( $held ) || (int) strtok( $held, ':' ) > time() - 30 ) {
            return null;
        }
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        return 1 === (int) $wpdb->rows_affected ? $token : null;
    }

    /**
     * An expired owner cannot release its successor.
     *
     * @param int    $user_id Owning user.
     * @param string $token Lock owner.
     * @return void
     */
    private static function release_lock( $user_id, $token ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A previous owner must not release a successor's lock.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_PREFIX . $user_id, $token ) );
    }

    /**
     * Public operations cannot select another user.
     *
     * @param int $user_id Requested owner.
     * @return bool
     */
    private static function owns( $user_id ) {
        return 0 < $user_id && get_current_user_id() === (int) $user_id;
    }

    /**
     * A waiter cannot trust its earlier metadata cache.
     *
     * @param int $user_id Owning user.
     * @return array
     */
    private static function read( $user_id ) {
        // The previous request may have rotated credentials while this request waited.
        wp_cache_delete( $user_id, 'user_meta' );
        $record = get_user_meta( $user_id, self::META, true );
        return is_array( $record ) ? $record : array();
    }

    /**
     * WordPress also returns false when the value is unchanged.
     *
     * @param int   $user_id Owning user.
     * @param array $record Complete registration.
     * @return bool
     */
    private static function save( $user_id, $record ) {
        return false !== update_user_meta( $user_id, self::META, $record ) || self::read( $user_id ) === $record;
    }

    /**
     * Raw provider messages must not reach responses or storage.
     *
     * @param string $code Machine-readable failure.
     * @param array  $data Safe diagnostics.
     * @return \WP_Error
     */
    private static function error( $code, $data = array() ) {
        return new \WP_Error( $code, __( 'The ChatGPT connection could not be completed. Try again.', 'mainwp' ), $data );
    }

    /**
     * Each authorization attempt requires independent random values.
     *
     * @return string
     */
    private static function random() {
        return self::base64url( random_bytes( 32 ) );
    }

    /**
     * PKCE and JWT values require unpadded base64url.
     *
     * @param string $value Bytes.
     * @return string
     */
    private static function base64url( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth requires base64url encoding.
    }

    /**
     * Malformed JWT segments must fail signature validation.
     *
     * @param string $value Base64url bytes.
     * @return string
     */
    private static function decode( $value ) {
        $decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT segments use base64url encoding.
        return false === $decoded ? '' : $decoded;
    }
}
