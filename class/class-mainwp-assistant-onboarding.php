<?php
/**
 * MainWP Assistant Onboarding.
 *
 * Server side of the "Connect free AI" flow: registers the vendored Google
 * provider with the WordPress AI Client, reads the core Connectors contract for
 * the `google` connector, and exposes the two AJAX endpoints the wizard uses to
 * read state and store/validate an API key.
 *
 * ## Provider registration
 *
 * MainWP ships `libs/ai-provider-for-google/` in Composer-package mode and
 * registers it on `init` priority 6 - after the official provider plugin family
 * registers at priority 5, and before core wires stored keys into the AI Client
 * at `init` priority 20. Registration is skipped when the registry already holds
 * a `google` provider, so a site running the real ai-provider-for-google plugin
 * always wins and MainWP takes over again the request after it is deactivated.
 *
 * ## Key handling
 *
 * The raw key never leaves this class: responses and log lines carry only the
 * masked last four characters, and any provider error text is scrubbed of the
 * submitted key (raw, urlencoded and JSON-escaped forms) before it is truncated.
 *
 * ## Deliberate divergence from core
 *
 * Core's Connectors screen reverts a stored key whenever validation fails for
 * any reason. This class reverts only when the credential itself was rejected
 * (HTTP 400/401/403). A network blip, a shared-IP 429, or a provider outage
 * leaves the key in place and reports "saved, not verified" instead: deleting a
 * good key because Google was briefly unreachable is worse than an unverified
 * save. The revert is a compare-and-swap so a concurrent administrator's newer
 * key is never clobbered by a stale rollback.
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Assistant_Onboarding
 *
 * @since 6.0.0
 */
class MainWP_Assistant_Onboarding { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * AJAX action for the state probe.
     *
     * @since 6.0.0
     * @var string
     */
    const AJAX_STATUS = 'mainwp_assistant_onboarding_status';

    /**
     * AJAX action for storing and validating a key.
     *
     * @since 6.0.0
     * @var string
     */
    const AJAX_SAVE_KEY = 'mainwp_assistant_onboarding_save_key';

    /**
     * Nonce action shared by both endpoints.
     *
     * @since 6.0.0
     * @var string
     */
    const NONCE_ACTION = 'mainwp_assistant_onboarding';

    /**
     * Core connector id this class drives.
     *
     * @since 6.0.0
     * @var string
     */
    const CONNECTOR_ID = 'google';

    /**
     * Model pinned when the dashboard has no explicit preference yet.
     *
     * Free Gemini keys serve the Flash family. Google retires Flash models
     * for new keys while still listing them in models.list (gemini-2.5-flash
     * 404s for new keys with a message naming gemini-3.6-flash as the
     * replacement, observed 2026-09-01), so the pin follows Google's own
     * migration target rather than the listing.
     *
     * @since 6.0.0
     * @var string
     */
    const DEFAULT_MODEL = 'gemini-3.6-flash';

    /**
     * Site-wide model preference option, shared with the settings page.
     *
     * @since 6.0.0
     * @var string
     */
    const MODEL_OPTION = 'mainwp_assistant_model_preference';

    /**
     * Provenance of the stored model preference.
     *
     * Holds the model id pin_default_model() wrote, or 'user' once the
     * settings page saves an explicit choice, so a retired auto-pin can be
     * migrated forward without ever touching a value a person picked.
     *
     * @since 6.0.0
     * @var string
     */
    const MODEL_PIN_SOURCE_OPTION = 'mainwp_assistant_model_autopin';

    /**
     * Transient holding the model id => provider ids allowlist.
     *
     * @since 6.0.0
     * @var string
     */
    const ALLOWLIST_TRANSIENT = 'mainwp_assistant_model_allowlist';

    /**
     * Attempts allowed per user inside the throttle window.
     *
     * @since 6.0.0
     * @var int
     */
    const THROTTLE_LIMIT = 10;

    /**
     * Throttle window in seconds.
     *
     * @since 6.0.0
     * @var int
     */
    const THROTTLE_WINDOW = 600;

    /**
     * Where users get a free key when the connector supplies no URL.
     *
     * @since 6.0.0
     * @var string
     */
    const CREDENTIALS_URL = 'https://aistudio.google.com/apikey';

    /**
     * Longest key accepted before the request is rejected pre-HTTP.
     *
     * @since 6.0.0
     * @var int
     */
    const MAX_KEY_LENGTH = 200;

    /**
     * Characters of provider error text relayed to the browser.
     *
     * @since 6.0.0
     * @var int
     */
    const PROVIDER_MESSAGE_LENGTH = 300;

    /**
     * Register the AJAX endpoints and the provider registration hook.
     *
     * Must be called before `init` runs: the provider has to be registered at
     * priority 6 so core's `init` priority 20 key wiring sees it.
     *
     * @since 6.0.0
     *
     * @return void
     */
    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_STATUS, array( __CLASS__, 'handle_status' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_KEY, array( __CLASS__, 'handle_save_key' ) );
        add_action( 'init', array( __CLASS__, 'register_google_provider' ), 6 );
    }

    /**
     * Register the vendored Google provider with the WordPress AI Client.
     *
     * Silent no-op below WordPress 7.0 (no AI Client) and whenever another
     * plugin already registered a `google` provider.
     *
     * @since 6.0.0
     *
     * @return void
     */
    public static function register_google_provider() {
        if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
            return;
        }

        $autoload = MAINWP_PLUGIN_DIR . 'libs' . DIRECTORY_SEPARATOR . 'ai-provider-for-google' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'autoload.php';

        if ( ! file_exists( $autoload ) ) {
            return;
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();

            if ( $registry->hasProvider( self::CONNECTOR_ID ) ) {
                return;
            }

            require_once $autoload; // NOSONAR - vendored library, PSR-4 autoloader.

            if ( ! class_exists( '\WordPress\GoogleAiProvider\Provider\GoogleProvider' ) ) {
                return;
            }

            $registry->registerProvider( \WordPress\GoogleAiProvider\Provider\GoogleProvider::class );
        } catch ( \Throwable $e ) {
            // A registry that cannot take our provider leaves the site exactly as
            // it was; the wizard reports provider_active:false and links out.
            return;
        }
    }

    /**
     * Read the core Connectors contract for the Google connector.
     *
     * The option name is never hardcoded here - it comes from the connector's
     * own `setting_name` so a core rename degrades to a contract mismatch
     * instead of writing to a dead option.
     *
     * @since 6.0.0
     *
     * @return array|\WP_Error Contract parts, or WP_Error with code
     *                         `wp_version_unsupported`, `ai_unsupported` or
     *                         `connector_contract_mismatch`.
     */
    public static function google_connector_contract() {
        if ( ! static::ai_client_available() ) {
            return new \WP_Error( 'wp_version_unsupported', esc_html__( 'Sites mode needs WordPress 7.0 or newer.', 'mainwp' ) );
        }

        // Core registers no connectors at all when AI support is off, so this
        // state has to be reported before the shape is inspected or every
        // AI-disabled site looks like a broken contract.
        if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
            return new \WP_Error( 'ai_unsupported', esc_html__( 'AI features are disabled on this site.', 'mainwp' ) );
        }

        $mismatch = new \WP_Error( 'connector_contract_mismatch', esc_html__( 'This WordPress version describes AI connectors differently than expected.', 'mainwp' ) );

        $connectors = wp_get_connectors();
        $connector  = is_array( $connectors ) && isset( $connectors[ self::CONNECTOR_ID ] ) ? $connectors[ self::CONNECTOR_ID ] : null;

        if ( ! is_array( $connector ) ) {
            return $mismatch;
        }

        $auth = isset( $connector['authentication'] ) && is_array( $connector['authentication'] ) ? $connector['authentication'] : array();

        if ( 'api_key' !== ( $auth['method'] ?? '' ) ) {
            return $mismatch;
        }

        $setting_name = (string) ( $auth['setting_name'] ?? '' );

        if ( '' === $setting_name ) {
            return $mismatch;
        }

        $is_active = isset( $connector['plugin']['is_active'] ) ? $connector['plugin']['is_active'] : null;

        if ( ! is_callable( $is_active ) ) {
            return $mismatch;
        }

        $credentials_url = (string) ( $auth['credentials_url'] ?? '' );

        return array(
            'name'            => (string) ( $connector['name'] ?? 'Google' ),
            'setting_name'    => $setting_name,
            'env_var_name'    => (string) ( $auth['env_var_name'] ?? '' ),
            'constant_name'   => (string) ( $auth['constant_name'] ?? '' ),
            'credentials_url' => '' !== $credentials_url ? $credentials_url : self::CREDENTIALS_URL,
            'is_active'       => $is_active,
        );
    }

    /**
     * Resolve where the connector's key comes from.
     *
     * Mirrors core's runtime precedence: environment variable, then constant,
     * then the stored option. A database write is pointless while either of the
     * first two is set.
     *
     * @since 6.0.0
     *
     * @param array $contract Contract array from google_connector_contract().
     *
     * @return string One of `env`, `constant`, `database`, `none`.
     */
    public static function key_source( $contract ) {
        $contract = is_array( $contract ) ? $contract : array();

        $env_var = (string) ( $contract['env_var_name'] ?? '' );
        if ( '' !== $env_var ) {
            $env_value = getenv( $env_var );
            if ( false !== $env_value && '' !== $env_value ) {
                return 'env';
            }
        }

        $constant = (string) ( $contract['constant_name'] ?? '' );
        if ( '' !== $constant && defined( $constant ) ) {
            $const_value = constant( $constant );
            if ( is_string( $const_value ) && '' !== $const_value ) {
                return 'constant';
            }
        }

        $setting = (string) ( $contract['setting_name'] ?? '' );
        if ( '' !== $setting && '' !== (string) get_option( $setting, '' ) ) {
            return 'database';
        }

        return 'none';
    }

    /**
     * Build the display form of a stored key.
     *
     * @since 6.0.0
     *
     * @param string $key Raw API key.
     *
     * @return string Bullets plus the last four characters, or bullets only.
     */
    public static function mask_key( $key ) {
        $key = (string) $key;

        if ( strlen( $key ) <= 4 ) {
            return str_repeat( "\xe2\x80\xa2", 4 );
        }

        return str_repeat( "\xe2\x80\xa2", 4 ) . substr( $key, -4 );
    }

    /**
     * AJAX: report onboarding state.
     *
     * Pure lookups, no HTTP, so the panel can call it on open and after each
     * wizard step. Flags are evaluated in order and each false one short
     * circuits the rest, which keeps "AI disabled" from being reported as a
     * contract mismatch. The response is always a success envelope: the flags
     * carry the state, and the browser needs `fallback_url` most in exactly the
     * degraded cases.
     *
     * @since 6.0.0
     *
     * @return void Outputs JSON response.
     */
    public static function handle_status() {
        static::check_request();

        $response = array(
            'wp_version_ok'   => false,
            'ai_supported'    => false,
            'contract_ok'     => false,
            'provider_active' => false,
            'key_source'      => 'none',
            'key_masked'      => '',
            'agent_available' => false,
            'effective_model' => (string) get_option( self::MODEL_OPTION, '' ),
            'default_model'   => static::default_model(),
            'credentials_url' => self::CREDENTIALS_URL,
            'fallback_url'    => static::connectors_url(),
            'error_code'      => '',
        );

        $contract = static::google_connector_contract();

        if ( is_wp_error( $contract ) ) {
            $error_code = $contract->get_error_code();

            $response['error_code'] = $error_code;

            // Everything past the failing flag stays false: the contract reader
            // stops at the first unmet precondition, so the values behind it are
            // unknown rather than negative.
            if ( 'ai_unsupported' === $error_code ) {
                $response['wp_version_ok'] = true;
            } elseif ( 'connector_contract_mismatch' === $error_code ) {
                $response['wp_version_ok'] = true;
                $response['ai_supported']  = true;
            }

            wp_send_json_success( $response );
        }

        $response['wp_version_ok']   = true;
        $response['ai_supported']    = true;
        $response['contract_ok']     = true;
        $response['credentials_url'] = $contract['credentials_url'];
        $response['provider_active'] = static::provider_active( $contract );
        $response['key_source']      = static::key_source( $contract );

        if ( 'database' === $response['key_source'] ) {
            $response['key_masked'] = static::mask_key( (string) get_option( $contract['setting_name'], '' ) );
        }

        $response['agent_available'] = MainWP_Assistant::agent_mode_available();

        wp_send_json_success( $response );
    }

    /**
     * AJAX: store an API key and validate it against the provider.
     *
     * @since 6.0.0
     *
     * @return void Outputs JSON response.
     */
    public static function handle_save_key() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.NestingLevel.TooHigh -- NOSONAR - linear validation ladder.
        static::check_request();

        if ( ! static::record_attempt() ) {
            static::send_failure( 'too_many_attempts', esc_html__( 'Too many attempts. Wait a few minutes before trying another key.', 'mainwp' ), array(), 429 );
        }

        $submitted = isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by check_request(); the sentinel test needs the raw bytes, sanitized below.

        if ( ! is_string( $submitted ) ) {
            $submitted = '';
        }

        // The sentinel test must see the raw multi-byte bullets, so it runs
        // before any byte-altering filter.
        if ( MainWP_Credential_Render::is_sentinel( $submitted ) ) {
            static::send_failure( 'unchanged', esc_html__( 'The stored key was left unchanged.', 'mainwp' ) );
        }

        $key = trim( sanitize_text_field( $submitted ) );

        if ( '' === $key ) {
            static::send_failure( 'empty_key', esc_html__( 'Paste the API key you copied from Google AI Studio.', 'mainwp' ) );
        }

        if ( strlen( $key ) > self::MAX_KEY_LENGTH || preg_match( '/\s/', $key ) ) {
            static::send_failure( 'malformed_key', esc_html__( 'That does not look like an API key. Copy the whole key with no spaces or line breaks.', 'mainwp' ) );
        }

        // OpenAI keys are the common paste mistake and Google would reject them
        // anyway, so stop before spending an HTTP request on it.
        if ( 0 === strpos( $key, 'sk-' ) ) {
            static::send_failure( 'wrong_provider_key', esc_html__( 'That is an OpenAI key. This step needs a Google AI Studio key.', 'mainwp' ) );
        }

        $contract = static::google_connector_contract();

        if ( is_wp_error( $contract ) ) {
            static::send_failure(
                $contract->get_error_code(),
                $contract->get_error_message(),
                array( 'fallback_url' => static::connectors_url() )
            );
        }

        // The vendored provider registration swallows a missing or broken
        // autoloader on purpose, so a readable contract still leaves the
        // connector inactive and nothing would ever use a key stored here.
        if ( ! static::provider_active( $contract ) ) {
            static::send_failure(
                'provider_inactive',
                esc_html__( 'This site has no active Google AI provider, so a key stored here would not be used. Add the key on the WordPress Connectors screen instead.', 'mainwp' ),
                array( 'fallback_url' => static::connectors_url() )
            );
        }

        $source = static::key_source( $contract );

        // Env and constant outrank the option at runtime, so writing the key
        // would look like it worked while the old credential kept serving.
        if ( 'env' === $source || 'constant' === $source ) {
            static::send_failure(
                'key_source_override',
                esc_html__( 'This site supplies a Google API key through its server configuration. That key takes priority, so it has to be changed where it is defined.', 'mainwp' ),
                array( 'key_source' => $source )
            );
        }

        $setting_name = $contract['setting_name'];
        $previous     = (string) get_option( $setting_name, '' );

        update_option( $setting_name, $key );

        // update_option() returns false both for "no change" and for a failed
        // write, so reading the value back is the only honest confirmation.
        if ( (string) get_option( $setting_name, '' ) !== $key ) {
            static::send_failure( 'save_failed', esc_html__( 'The key could not be saved. Check that this site can write options, then try again.', 'mainwp' ) );
        }

        static::apply_key_to_registry( $key );
        static::invalidate_model_cache( self::CONNECTOR_ID );

        $failure = null;

        try {
            static::list_provider_models( self::CONNECTOR_ID );
        } catch ( \WordPress\AiClient\Providers\Http\Exception\NetworkException $e ) {
            $failure = array( 'validation_network', esc_html__( 'Key saved, but this site could not reach Google to verify it.', 'mainwp' ), $e, false );
        } catch ( \WordPress\AiClient\Providers\Http\Exception\ServerException $e ) {
            $failure = array( 'validation_provider_down', esc_html__( 'Key saved, but Google reported a temporary problem. Try verifying again shortly.', 'mainwp' ), $e, false );
        } catch ( \WordPress\AiClient\Providers\Http\Exception\ClientException $e ) {
            $status = (int) $e->getCode();

            if ( 429 === $status ) {
                $failure = array( 'validation_quota', esc_html__( 'Key saved, but Google is rate limiting this key right now. Try verifying again in a moment.', 'mainwp' ), $e, false );
            } elseif ( in_array( $status, array( 400, 401, 403 ), true ) ) {
                $failure = array( 'invalid_key', esc_html__( 'Google rejected that key.', 'mainwp' ), $e, true );
            } else {
                $failure = array( 'validation_unknown', esc_html__( 'Key saved, but it could not be verified.', 'mainwp' ), $e, false );
            }
        } catch ( \Throwable $e ) {
            $failure = array( 'validation_unknown', esc_html__( 'Key saved, but it could not be verified.', 'mainwp' ), $e, false );
        }

        if ( null !== $failure ) {
            list( $error_code, $message, $exception, $revert ) = $failure;

            if ( $revert ) {
                static::revert_key( $setting_name, $key, $previous );
            }

            // The rollback is conditional and, on the re-verify path, deletes
            // rather than restores, so only the row itself can say whether this
            // request's key is still the stored credential.
            $key_kept = ( (string) get_option( $setting_name, '' ) === $key );

            static::send_failure(
                $error_code,
                $message,
                array(
                    'verified'         => false,
                    'key_kept'         => $key_kept,
                    'provider_message' => static::redact_provider_message( $exception->getMessage(), $key ),
                    'fallback_url'     => static::connectors_url(),
                )
            );
        }

        // A newly valid key can change the model list, so the cached allowlist
        // the settings page validates against is no longer trustworthy.
        delete_transient( self::ALLOWLIST_TRANSIENT );

        $pinned = static::pin_default_model();

        wp_send_json_success(
            array(
                'verified'         => true,
                'key_source'       => 'database',
                'key_masked'       => static::mask_key( $key ),
                'agent_available'  => MainWP_Assistant::agent_mode_available(),
                'effective_model'  => (string) get_option( self::MODEL_OPTION, '' ),
                'default_model'    => static::default_model(),
                'model_pinned'     => $pinned,
                'suggested_prompt' => static::suggested_prompt(),
                'message'          => esc_html__( 'Key verified.', 'mainwp' ),
            )
        );
    }

    /**
     * Whether this dashboard has a Google key the free-tier terms apply to.
     *
     * Gates the Gemini data-terms line in the consent modal, so an OpenAI or
     * Anthropic dashboard never reads Google copy. Safe below WordPress 7.0:
     * the contract reader returns a WP_Error there.
     *
     * @since 6.0.0
     *
     * @return bool True when a Google key is set from any source.
     */
    public static function google_free_key_configured() {
        $contract = static::google_connector_contract();

        if ( is_wp_error( $contract ) ) {
            return false;
        }

        return 'none' !== static::key_source( $contract );
    }

    /**
     * Whether the current user may run the wizard.
     *
     * Connecting a provider writes a site option, so this is the settings
     * right rather than the weaker chat gate. Kept identical to the endpoint
     * gate so the UI can never offer what the endpoints would refuse.
     *
     * @since 6.0.0
     *
     * @return bool True when the wizard may be rendered and opened.
     */
    public static function user_can_run_wizard() {
        return current_user_can( 'manage_options' ) && \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' );
    }

    /**
     * Strings and the nonce the wizard script reads.
     *
     * Merged into both localized objects (the chat panel's and the settings
     * page's) so the same script works on either enqueue path.
     *
     * @since 6.0.0
     *
     * @return array Localization payload.
     */
    public static function wizard_l10n() {
        return array(
            'onboarding_nonce'            => wp_create_nonce( self::NONCE_ACTION ),
            'onboarding_verifying'        => esc_html__( 'Checking the key with Google...', 'mainwp' ),
            /* translators: %s: model identifier, for example gemini-3.6-flash. */
            'onboarding_connected'        => esc_html__( 'Connected. Sites mode is on, using %s.', 'mainwp' ),
            'onboarding_connected_plain'  => esc_html__( 'Connected. Sites mode is on.', 'mainwp' ),
            'onboarding_connected_reload' => esc_html__( 'Key verified. Sites mode becomes available after you reload this page.', 'mainwp' ),
            'onboarding_request_failed'   => esc_html__( 'The request did not reach this dashboard. Check your connection and try again.', 'mainwp' ),
            'onboarding_show_key'         => esc_html__( 'Show key', 'mainwp' ),
            'onboarding_hide_key'         => esc_html__( 'Hide key', 'mainwp' ),
        );
    }

    /**
     * One-line data-terms sentence, reused by the card, the wizard and the tab.
     *
     * Returns unescaped text: the PHP callers escape at the echo, and the chat
     * card hands it to a JavaScript .text() setter, which wants plain text.
     * Escaping here would double-encode entities in a translated string.
     *
     * @since 6.0.0
     *
     * @return string Disclosure sentence, unescaped.
     */
    public static function free_tier_disclosure() {
        return __( 'Google may use free-tier requests, including human review, to improve its products. Paid keys and users in the EEA, UK, and Switzerland are excluded.', 'mainwp' );
    }

    /**
     * Render the onboarding wizard modal.
     *
     * Both the assistant panel and the settings page call this, so the static
     * guard keeps a page that shows both from emitting the id twice.
     *
     * Every step, sub-state and outcome block is rendered here and toggled by
     * assets/js/mainwp-assistant-onboarding.js. Keeping the copy in PHP means
     * the wizard needs almost no localized JavaScript strings, and the two
     * override explanations can name the real environment variable and
     * constant from the connector contract.
     *
     * @since 6.0.0
     *
     * @return void Outputs the modal HTML.
     */
    public static function render_wizard_modal() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- NOSONAR - one template, no branching logic.
        static $rendered = false;

        if ( $rendered || ! static::user_can_run_wizard() ) {
            return;
        }

        $rendered = true;

        $contract        = static::google_connector_contract();
        $credentials_url = self::CREDENTIALS_URL;
        $env_var_name    = '';
        $constant_name   = '';

        if ( ! is_wp_error( $contract ) ) {
            $credentials_url = $contract['credentials_url'];
            $env_var_name    = $contract['env_var_name'];
            $constant_name   = $contract['constant_name'];
        }

        $connectors_url = static::connectors_url();
        ?>
        <div class="ui small modal" id="mainwp-assistant-onboarding-modal" role="dialog" aria-modal="true" aria-labelledby="mainwp-assistant-onboarding-title">
            <div class="header" id="mainwp-assistant-onboarding-title">
                <i class="google icon"></i>
                <?php esc_html_e( 'Connect free AI', 'mainwp' ); ?>
            </div>
            <div class="content">
                <div class="ui tiny three steps mainwp-assistant-onboarding-steps">
                    <div class="step" data-step="get_key">
                        <div class="content"><div class="title"><?php esc_html_e( 'Get key', 'mainwp' ); ?></div></div>
                    </div>
                    <div class="step" data-step="paste">
                        <div class="content"><div class="title"><?php esc_html_e( 'Paste', 'mainwp' ); ?></div></div>
                    </div>
                    <div class="step" data-step="verify">
                        <div class="content"><div class="title"><?php esc_html_e( 'Verify', 'mainwp' ); ?></div></div>
                    </div>
                </div>

                <div class="mainwp-assistant-onboarding-loading" data-panel="loading">
                    <div class="ui active inline loader"></div>
                    <span><?php esc_html_e( 'Reading this dashboard\'s AI connection...', 'mainwp' ); ?></span>
                </div>

                <div class="mainwp-assistant-onboarding-panel" data-panel="get_key" hidden>
                    <p><?php esc_html_e( 'A Google AI Studio key takes about two minutes and needs no credit card.', 'mainwp' ); ?></p>
                    <ol class="mainwp-assistant-onboarding-instructions">
                        <li><?php esc_html_e( 'Sign in to Google AI Studio.', 'mainwp' ); ?></li>
                        <li><?php esc_html_e( 'Click Get API key, then Create API key.', 'mainwp' ); ?></li>
                    </ol>
                    <?php static::render_two_click_illustration(); ?>
                    <p><?php esc_html_e( 'Copy the key, then come back to this window.', 'mainwp' ); ?></p>
                    <div class="ui small message mainwp-assistant-onboarding-disclosure">
                        <?php esc_html_e( 'Google\'s free Gemini tier is free because Google may use these requests to improve its products, including review by human reviewers. Don\'t send anything you wouldn\'t want reviewed. This doesn\'t apply to users in the EEA, UK, or Switzerland, and it doesn\'t apply to paid Google API keys.', 'mainwp' ); ?>
                    </div>
                </div>

                <div class="mainwp-assistant-onboarding-panel" data-panel="paste" hidden>
                    <div class="ui form">
                        <div class="field">
                            <label for="mainwp-assistant-onboarding-key"><?php esc_html_e( 'Google API key', 'mainwp' ); ?></label>
                            <div class="ui fluid action input">
                                <input type="password"
                                    id="mainwp-assistant-onboarding-key"
                                    autocomplete="off"
                                    spellcheck="false"
                                    autocapitalize="off"
                                    placeholder="<?php esc_attr_e( 'Paste the key here', 'mainwp' ); ?>">
                                <button type="button" class="ui icon button" id="mainwp-assistant-onboarding-toggle-key" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show key', 'mainwp' ); ?>">
                                    <i class="eye icon"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mainwp-assistant-onboarding-hint" data-hint="prefix" hidden>
                        <?php esc_html_e( 'Google AI Studio keys usually start with AIza. Check you copied the whole key.', 'mainwp' ); ?>
                    </div>
                    <div class="ui small message mainwp-assistant-onboarding-disclosure">
                        <?php echo esc_html( static::free_tier_disclosure() ); ?>
                    </div>
                </div>

                <div class="mainwp-assistant-onboarding-panel" data-panel="override" hidden>
                    <div class="ui yellow message">
                        <p data-override="env" hidden>
                            <?php
                            printf(
                                /* translators: %s: name of the environment variable that supplies the key. */
                                esc_html__( 'This site supplies a Google API key through the %s environment variable. That key takes priority over anything stored here, so change it where it is defined.', 'mainwp' ),
                                '<code>' . esc_html( '' !== $env_var_name ? $env_var_name : 'GOOGLE_API_KEY' ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts escaped above.
                            );
                            ?>
                        </p>
                        <p data-override="constant" hidden>
                            <?php
                            printf(
                                /* translators: %s: name of the PHP constant that supplies the key. */
                                esc_html__( 'This site supplies a Google API key through the %s constant, usually set in wp-config.php. That key takes priority over anything stored here, so change it where it is defined.', 'mainwp' ),
                                '<code>' . esc_html( '' !== $constant_name ? $constant_name : 'GOOGLE_API_KEY' ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts escaped above.
                            );
                            ?>
                        </p>
                    </div>
                </div>

                <div class="mainwp-assistant-onboarding-panel" data-panel="verify" hidden>
                    <div class="mainwp-assistant-onboarding-working" data-outcome="working" hidden>
                        <div class="ui active inline loader"></div>
                        <span data-field="working-text"></span>
                    </div>
                    <div class="mainwp-assistant-onboarding-success" data-outcome="success" hidden>
                        <p>
                            <i class="green check circle icon"></i>
                            <span data-field="success-text"></span>
                        </p>
                        <p class="mainwp-assistant-onboarding-suggestion" data-field="suggestion" hidden></p>
                    </div>
                    <div class="ui small message" data-outcome="failure" hidden>
                        <div data-field="failure-text"></div>
                        <div class="mainwp-assistant-onboarding-provider-message" data-field="provider-message" hidden></div>
                    </div>
                </div>

                <div class="mainwp-assistant-onboarding-panel" data-panel="blocked" hidden>
                    <div class="ui yellow message">
                        <div data-blocked="wp_version_unsupported" hidden>
                            <?php esc_html_e( 'Sites mode needs WordPress 7.0 or newer. Docs mode works on this version.', 'mainwp' ); ?>
                        </div>
                        <div data-blocked="ai_unsupported" hidden>
                            <?php esc_html_e( 'AI features are turned off on this site, so no AI connector is available.', 'mainwp' ); ?>
                        </div>
                        <div data-blocked="connector_contract_mismatch" hidden>
                            <?php esc_html_e( 'This WordPress version describes AI connectors differently than this wizard expects, so it cannot store the key for you.', 'mainwp' ); ?>
                        </div>
                        <div data-blocked="provider_inactive" hidden>
                            <?php esc_html_e( 'This site has no active Google AI provider, so this wizard cannot store the key for you.', 'mainwp' ); ?>
                        </div>
                        <div data-blocked="generic" data-field="blocked-text" hidden></div>
                    </div>
                    <p><?php esc_html_e( 'You can still add a key on the WordPress Connectors screen.', 'mainwp' ); ?></p>
                </div>
            </div>
            <div class="actions">
                <a class="ui green button" data-action="open-google" href="<?php echo esc_url( $credentials_url ); ?>" target="_blank" rel="noopener noreferrer" hidden>
                    <i class="external alternate icon"></i> <?php esc_html_e( 'Open Google AI Studio', 'mainwp' ); ?>
                </a>
                <button type="button" class="ui button" data-action="goto-paste" hidden><?php esc_html_e( 'I have my key', 'mainwp' ); ?></button>
                <button type="button" class="ui green button" data-action="submit" hidden><?php esc_html_e( 'Connect', 'mainwp' ); ?></button>
                <button type="button" class="ui button" data-action="retry-key" hidden><?php esc_html_e( 'Try another key', 'mainwp' ); ?></button>
                <button type="button" class="ui button" data-action="retry-verify" hidden><?php esc_html_e( 'Verify again', 'mainwp' ); ?></button>
                <button type="button" class="ui green button" data-action="try-it" hidden><?php esc_html_e( 'Try it', 'mainwp' ); ?></button>
                <a class="ui basic button" data-action="connectors" href="<?php echo esc_url( $connectors_url ); ?>" hidden>
                    <i class="plug icon"></i> <?php esc_html_e( 'Manage AI Connectors', 'mainwp' ); ?>
                </a>
                <button type="button" class="ui cancel button" data-action="close"><?php esc_html_e( 'Close', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Draw the two clicks the Google AI Studio page needs.
     *
     * Decorative: the ordered list beside it carries the same instructions, so
     * the drawing is hidden from assistive technology. Strokes and fills use
     * theme variables because the modal renders in six themes plus dark.
     *
     * @since 6.0.0
     *
     * @return void Outputs inline SVG.
     */
    protected static function render_two_click_illustration() {
        ?>
        <svg class="mainwp-assistant-onboarding-illustration" viewBox="0 0 320 120" role="presentation" aria-hidden="true" focusable="false">
            <rect x="1" y="1" width="318" height="118" rx="8" fill="none" stroke="var(--mainwp-secondary-border-color, #cfcfd6)" stroke-width="1.5"/>
            <line x1="1" y1="26" x2="319" y2="26" stroke="var(--mainwp-secondary-border-color, #e8e8eb)" stroke-width="1.5"/>
            <circle cx="14" cy="13.5" r="3.5" fill="var(--mainwp-secondary-border-color, #dedee3)"/>
            <circle cx="26" cy="13.5" r="3.5" fill="var(--mainwp-secondary-border-color, #dedee3)"/>
            <circle cx="38" cy="13.5" r="3.5" fill="var(--mainwp-secondary-border-color, #dedee3)"/>
            <rect x="212" y="42" width="86" height="24" rx="12" fill="none" stroke="var(--mainwp-accent-color, #7fb100)" stroke-width="2"/>
            <circle cx="204" cy="54" r="11" fill="var(--mainwp-accent-color, #7fb100)"/>
            <text x="204" y="59" text-anchor="middle" font-size="13" font-weight="700" fill="#ffffff">1</text>
            <rect x="212" y="82" width="86" height="24" rx="6" fill="none" stroke="var(--mainwp-accent-color, #7fb100)" stroke-width="2"/>
            <circle cx="204" cy="94" r="11" fill="var(--mainwp-accent-color, #7fb100)"/>
            <text x="204" y="99" text-anchor="middle" font-size="13" font-weight="700" fill="#ffffff">2</text>
            <rect x="22" y="44" width="150" height="8" rx="4" fill="var(--mainwp-secondary-border-color, #dedee3)"/>
            <rect x="22" y="62" width="120" height="8" rx="4" fill="var(--mainwp-secondary-border-color, #e8e8eb)"/>
            <rect x="22" y="80" width="140" height="8" rx="4" fill="var(--mainwp-secondary-border-color, #e8e8eb)"/>
        </svg>
        <?php
    }

    /**
     * Validate a model id against the live provider allowlist.
     *
     * An empty id is valid and means "let WordPress choose". An unbuildable
     * allowlist (no providers, or every provider unreachable) never blocks a
     * save - it returns valid with known:false.
     *
     * @since 6.0.0
     *
     * @param string $model_id Model id to check.
     *
     * @return array {
     *     @type bool   $valid     Whether the id may be stored.
     *     @type bool   $known     Whether a configured provider offers it.
     *     @type array  $providers Provider ids offering it.
     *     @type array  $available A few ids that are offered, for the error copy.
     *     @type string $reason    '' when valid, else `syntax` or `unknown`.
     * }
     */
    public static function validate_model_id( $model_id ) {
        $model_id = is_string( $model_id ) ? $model_id : '';

        $result = array(
            'valid'     => true,
            'known'     => false,
            'providers' => array(),
            'available' => array(),
            'reason'    => '',
        );

        if ( '' === $model_id ) {
            return $result;
        }

        if ( strlen( $model_id ) > 100 || ! preg_match( '#^[A-Za-z0-9._:/@-]+$#', $model_id ) ) {
            $result['valid']  = false;
            $result['reason'] = 'syntax';
            return $result;
        }

        $allowlist = static::model_allowlist();

        if ( empty( $allowlist ) ) {
            return $result;
        }

        if ( isset( $allowlist[ $model_id ] ) ) {
            $result['known']     = true;
            $result['providers'] = array_values( (array) $allowlist[ $model_id ] );
            return $result;
        }

        $result['valid']     = false;
        $result['reason']    = 'unknown';
        $result['available'] = array_slice( array_keys( $allowlist ), 0, 5 );

        return $result;
    }

    /**
     * List the model ids a registered provider offers.
     *
     * Test seam: the only place this class performs provider HTTP. Subclasses
     * override it to exercise the validation taxonomy without a network.
     *
     * @since 6.0.0
     *
     * @param string $provider_id Registered provider id.
     *
     * @return array List of model id strings.
     *
     * @throws \Throwable Provider transport and API errors propagate to the caller.
     */
    protected static function list_provider_models( $provider_id ) {
        $registry   = \WordPress\AiClient\AiClient::defaultRegistry();
        $class_name = $registry->getProviderClassName( (string) $provider_id );
        $directory  = $class_name::modelMetadataDirectory();

        $ids = array();

        foreach ( $directory->listModelMetadata() as $metadata ) {
            $ids[] = (string) $metadata->getId();
        }

        return $ids;
    }

    /**
     * Drop the provider's cached model metadata before a validation call.
     *
     * Stock WordPress registers no PSR-16 cache, so the SDK's 24 hour TTL rides
     * a per-request in-memory cache and a fresh AJAX request is already live.
     * Sites that do register a cache need this. The interface returns void, so
     * nothing here can claim the cache was actually flushed.
     *
     * Test seam: subclasses override it to record the attempt.
     *
     * @since 6.0.0
     *
     * @param string $provider_id Registered provider id.
     *
     * @return void
     */
    protected static function invalidate_model_cache( $provider_id ) {
        try {
            $registry   = \WordPress\AiClient\AiClient::defaultRegistry();
            $class_name = $registry->getProviderClassName( (string) $provider_id );
            $directory  = $class_name::modelMetadataDirectory();

            if ( $directory instanceof \WordPress\AiClient\Common\Contracts\CachesDataInterface ) {
                $directory->invalidateCaches();
            }
        } catch ( \Throwable $e ) {
            return;
        }
    }

    /**
     * Wire the submitted key into the AI Client for this request.
     *
     * Core only passes stored keys to the registry at `init` priority 20, which
     * has already run by the time an AJAX handler writes the option.
     *
     * @since 6.0.0
     *
     * @param string $key Raw API key.
     *
     * @return void
     */
    protected static function apply_key_to_registry( $key ) {
        try {
            \WordPress\AiClient\AiClient::defaultRegistry()->setProviderRequestAuthentication(
                self::CONNECTOR_ID,
                new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( (string) $key )
            );
        } catch ( \Throwable $e ) {
            return;
        }
    }

    /**
     * Roll this request's key back after the provider rejected the credential.
     *
     * What the option ends up holding, for a key Google refused:
     *
     * | Value before the write | Outcome                     |
     * |------------------------|-----------------------------|
     * | none                   | delete the row              |
     * | the same key           | delete the row              |
     * | a different key        | write the previous key back |
     *
     * The same-key row is the "Verify again" path: an earlier attempt kept the
     * key because Google could not be reached, the user resubmits it unchanged,
     * and this time it is rejected. Restoring it would leave the rejected
     * credential stored and agent_mode_available() still true, so Sites mode
     * would look configured with a dead key. That row deletes like the empty
     * one.
     *
     * The compare and the write are one statement, because an administrator on
     * another request can store a newer key between a read and a write and the
     * rollback would then destroy it. The options API cannot express the
     * condition, so this goes to the row directly.
     *
     * @since 6.0.0
     *
     * @param string $setting_name Connector option name.
     * @param string $key          Key this request wrote.
     * @param string $previous     Value that was stored before the write.
     *
     * @return bool True when the revert was applied.
     */
    protected static function revert_key( $setting_name, $key, $previous ) {
        global $wpdb;

        $setting_name = (string) $setting_name;
        $key          = (string) $key;
        $restore      = (string) $previous === $key ? '' : (string) $previous;

        if ( '' === $restore ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap; the options API offers no compare-and-set.
            $changed = (int) $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
                    $wpdb->options,
                    $setting_name,
                    $key
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap; the options API offers no compare-and-set.
            $changed = (int) $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
                    $wpdb->options,
                    $restore,
                    $setting_name,
                    $key
                )
            );
        }

        if ( $changed < 1 ) {
            return false;
        }

        // The direct write bypassed the eviction update_option()/delete_option()
        // would have done, so later reads in this request would see the key
        // that is no longer in the row.
        wp_cache_delete( $setting_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );

        return true;
    }

    /**
     * Scrub a provider error message for display.
     *
     * Redaction runs before truncation: cutting first can leave a boundary
     * sliced fragment of the key that no str_replace would match afterwards.
     * The urlencoded and JSON-escaped forms are covered because provider error
     * bodies echo request URLs and JSON payloads back.
     *
     * @since 6.0.0
     *
     * @param string $message Provider error text.
     * @param string $key     Key submitted in this request.
     *
     * @return string Scrubbed, tag-free, truncated message.
     */
    protected static function redact_provider_message( $message, $key ) {
        $message = (string) $message;
        $key     = (string) $key;

        if ( '' !== $key ) {
            $needles = array(
                $key,
                rawurlencode( $key ),
                trim( (string) wp_json_encode( $key ), '"' ),
            );

            $message = str_replace( array_unique( $needles ), '[redacted]', $message );
        }

        $message = wp_strip_all_tags( $message );

        return function_exists( 'mb_substr' )
            ? mb_substr( $message, 0, self::PROVIDER_MESSAGE_LENGTH )
            : substr( $message, 0, self::PROVIDER_MESSAGE_LENGTH );
    }

    /**
     * Store the default model when the dashboard has no preference yet.
     *
     * An explicit choice is never clobbered. A retired former default is the
     * one exception: Google 404s it for newly connected keys, so it follows
     * the default forward - but only when the provenance marker says the value
     * came from this pin ('user' means the settings page saved it). A retired
     * value with no marker predates the marker and migrates once: on those
     * installs the auto-pin and an explicit save of the then-default are the
     * same stored string, and nothing can tell them apart.
     *
     * @since 6.0.0
     *
     * @return bool True when the pin was written.
     */
    protected static function pin_default_model() {
        $stored  = (string) get_option( self::MODEL_OPTION, '' );
        $source  = (string) get_option( self::MODEL_PIN_SOURCE_OPTION, '' );
        $retired = array( 'gemini-2.5-flash' );

        // 'user' covers the empty value too: an explicitly cleared preference
        // means "let WordPress choose" and is as much a choice as a model id.
        if ( 'user' === $source ) {
            return false;
        }

        if ( '' !== $stored ) {
            $migratable = in_array( $stored, $retired, true )
                && 'user' !== $source
                && ( '' === $source || $source === $stored );

            if ( ! $migratable ) {
                return false;
            }
        }

        update_option( self::MODEL_OPTION, static::default_model() );
        update_option( self::MODEL_PIN_SOURCE_OPTION, static::default_model() );

        return true;
    }

    /**
     * Model id pinned for a fresh free-tier key.
     *
     * @since 6.0.0
     *
     * @return string Model id.
     */
    public static function default_model() {
        /**
         * Filter the Gemini model pinned when a free key is connected.
         *
         * @since 6.0.0
         *
         * @param string $model_id Default model id.
         */
        return (string) apply_filters( 'mainwp_assistant_gemini_default_model', self::DEFAULT_MODEL );
    }

    /**
     * Build the model id => provider ids map used for validation.
     *
     * @since 6.0.0
     *
     * @return array Map of model id to provider ids.
     */
    protected static function model_allowlist() {
        $cached = get_transient( self::ALLOWLIST_TRANSIENT );

        if ( is_array( $cached ) ) {
            $map = $cached;
        } else {
            $map = static::build_model_allowlist();
            set_transient( self::ALLOWLIST_TRANSIENT, $map, HOUR_IN_SECONDS );
        }

        /**
         * Filter the model allowlist the assistant validates preferences against.
         *
         * @since 6.0.0
         *
         * @param array $map Map of model id to the provider ids offering it.
         */
        return (array) apply_filters( 'mainwp_assistant_model_allowlist', $map );
    }

    /**
     * Ask every registered provider what it offers.
     *
     * One unreachable provider must not hide the models the others reported,
     * so failures are per provider.
     *
     * @since 6.0.0
     *
     * @return array Map of model id to provider ids.
     */
    protected static function build_model_allowlist() {
        $map = array();

        if ( ! static::ai_client_available() ) {
            return $map;
        }

        try {
            $provider_ids = \WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds();
        } catch ( \Throwable $e ) {
            return $map;
        }

        foreach ( array_filter( (array) $provider_ids ) as $provider_id ) {
            $provider_id = (string) $provider_id;

            try {
                $model_ids = static::list_provider_models( $provider_id );
            } catch ( \Throwable $e ) {
                continue;
            }

            foreach ( (array) $model_ids as $model_id ) {
                $model_id = (string) $model_id;

                if ( '' === $model_id ) {
                    continue;
                }

                if ( ! isset( $map[ $model_id ] ) ) {
                    $map[ $model_id ] = array();
                }

                if ( ! in_array( $provider_id, $map[ $model_id ], true ) ) {
                    $map[ $model_id ][] = $provider_id;
                }
            }
        }

        return $map;
    }

    /**
     * First prompt offered after a successful connection.
     *
     * @since 6.0.0
     *
     * @return string Prompt text.
     */
    protected static function suggested_prompt() {
        return __( 'Which of my sites have updates available?', 'mainwp' );
    }

    /**
     * Whether the AI Client engine exists on this WordPress version.
     *
     * @since 6.0.0
     *
     * @return bool True on WordPress 7.0 and newer.
     */
    protected static function ai_client_available() {
        return class_exists( '\WordPress\AiClient\AiClient' ) && function_exists( 'wp_get_connectors' );
    }

    /**
     * Run the connector's own is_active closure.
     *
     * @since 6.0.0
     *
     * @param array $contract Contract array from google_connector_contract().
     *
     * @return bool True when core considers the connector active.
     */
    protected static function provider_active( $contract ) {
        try {
            return (bool) call_user_func( $contract['is_active'] );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Deep link to the core Connectors screen, used whenever the wizard degrades.
     *
     * @since 6.0.0
     *
     * @return string URL.
     */
    protected static function connectors_url() {
        return (string) apply_filters( 'mainwp_assistant_connectors_url', admin_url( 'options-connectors.php' ) );
    }

    /**
     * Verify the nonce and the rights both endpoints require.
     *
     * Matches the settings page gate exactly, so a chat-only user can never be
     * offered a wizard the endpoints would refuse.
     *
     * @since 6.0.0
     *
     * @return void
     */
    protected static function check_request() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            static::send_failure( 'forbidden', esc_html__( 'Permission denied.', 'mainwp' ), array(), 403 );
        }
    }

    /**
     * Count one save attempt for the current user.
     *
     * The window slides on every attempt, which is stricter than a fixed
     * window and keeps a single transient per user.
     *
     * @since 6.0.0
     *
     * @return bool False once the user is over the limit.
     */
    protected static function record_attempt() {
        $transient = 'mainwp_assistant_onboarding_attempts_' . get_current_user_id();
        $count     = (int) get_transient( $transient );

        if ( $count >= self::THROTTLE_LIMIT ) {
            return false;
        }

        set_transient( $transient, $count + 1, self::THROTTLE_WINDOW );

        return true;
    }

    /**
     * Emit a JSON failure envelope and stop.
     *
     * @since 6.0.0
     *
     * @param string $error_code Machine readable code the wizard branches on.
     * @param string $message    Human readable message.
     * @param array  $extra      Extra response keys.
     * @param int    $status     HTTP status code.
     *
     * @return void Outputs JSON response.
     */
    protected static function send_failure( $error_code, $message, $extra = array(), $status = 400 ) {
        wp_send_json_error(
            array_merge(
                array(
                    'error_code' => $error_code,
                    'message'    => $message,
                ),
                $extra
            ),
            $status
        );
    }
}
