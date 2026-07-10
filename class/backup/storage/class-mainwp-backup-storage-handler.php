<?php
/**
 * MainWP Backup Storage Handler.
 *
 * AJAX endpoints and save/delete flows for storage destinations. Every
 * save proves the connection before anything persists, a blank secret on
 * edit keeps the stored one so secrets never round-trip to the browser,
 * and deleting a destination that tasks still reference reports those
 * tasks first, then prunes them and fails its pending upload jobs on a
 * confirmed delete.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_Handler extends MainWP_Post_Base_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Provider slugs the UI may save.
     *
     * @var string[]
     */
    const ALLOWED_PROVIDERS = array( 'local', 's3', 'gcs', 'dropbox', 'sftp' );

    /**
     * Settings fields treated as secrets: never echoed, kept on blank edit.
     *
     * @var string[]
     */
    const SECRET_FIELDS = array( 'secret_key', 'app_secret', 'refresh_token', 'password', 'private_key' );

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Storage_Handler or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Storage_Handler
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Register the storage AJAX actions.
     *
     * @return void
     */
    public function init() {
        $this->add_action( 'mainwp_storage_save', array( &$this, 'ajax_storage_save' ) );
        $this->add_action( 'mainwp_storage_test', array( &$this, 'ajax_storage_test' ) );
        $this->add_action( 'mainwp_storage_delete', array( &$this, 'ajax_storage_delete' ) );
        $this->add_action( 'mainwp_storage_default', array( &$this, 'ajax_storage_default' ) );
        $this->add_action( 'mainwp_storage_get', array( &$this, 'ajax_storage_get' ) );
        $this->add_action( 'mainwp_dropbox_oauth_start', array( &$this, 'ajax_dropbox_oauth_start' ) );

        // init() itself runs during admin_init (priority 20), so a later
        // admin_init registration would never fire - sniff directly.
        $this->handle_dropbox_oauth_callback();
    }

    /**
     * The exact redirect URI the Dropbox app must register and the
     * authorize/exchange steps both use.
     *
     * @return string
     */
    public static function dropbox_redirect_uri() {
        return admin_url( 'admin.php?page=ManageBackupsStorage&mainwp_dropbox_oauth=1' );
    }

    /**
     * Begin the Connect Dropbox flow: stash the pending destination
     * encrypted server-side and hand the browser the authorize URL.
     *
     * @return void
     */
    public function ajax_dropbox_oauth_start() {
        $this->secure_request( 'mainwp_dropbox_oauth_start' );
        $this->check_permission();
        die( wp_json_encode( static::start_dropbox_oauth( $this->read_destination_input() ) ) );
    }

    /**
     * Validate the pending Dropbox destination, stash it encrypted in a
     * single-use state transient, and build the authorize URL.
     *
     * The app secret never returns to the browser: it travels only into
     * the encrypted stash, which the OAuth callback consumes.
     *
     * @param array $input Destination fields: id, name, is_default, settings{app_key, app_secret, base_path}.
     *
     * @return array {authorize_url} | {error}.
     */
    public static function start_dropbox_oauth( array $input ) {
        $settings   = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
        $id         = isset( $input['id'] ) ? (int) $input['id'] : 0;
        $name       = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
        $app_key    = isset( $settings['app_key'] ) ? trim( (string) $settings['app_key'] ) : '';
        $app_secret = isset( $settings['app_secret'] ) ? trim( (string) $settings['app_secret'] ) : '';

        // Re-connecting an existing destination with a blank secret uses
        // the stored one, matching the save flow's blank-keeps-stored rule.
        if ( $id > 0 && '' === $app_secret ) {
            $existing = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
            if ( null !== $existing && is_array( $existing->settings ) && isset( $existing->settings['app_secret'] ) ) {
                $app_secret = (string) $existing->settings['app_secret'];
            }
        }

        if ( '' === $name || '' === $app_key || '' === $app_secret ) {
            return array( 'error' => esc_html__( 'A destination name, the app key, and the app secret are required before connecting.', 'mainwp' ) );
        }

        $stash = array(
            'user_id'        => get_current_user_id(),
            'destination_id' => $id,
            'name'           => $name,
            'app_key'        => $app_key,
            'app_secret'     => $app_secret,
            'base_path'      => isset( $settings['base_path'] ) ? (string) $settings['base_path'] : '',
            'is_default'     => empty( $input['is_default'] ) ? 0 : 1,
        );

        $envelope = MainWP_Credential_Storage::encrypt_credential( (string) wp_json_encode( $stash ), 'storage_destination' );
        if ( ! is_string( $envelope ) || ! MainWP_Credential_Storage::is_credential_envelope( $envelope ) ) {
            return array( 'error' => esc_html__( 'The connection could not be prepared. Check that the dashboard can encrypt credentials.', 'mainwp' ) );
        }

        $state = bin2hex( random_bytes( 16 ) );
        set_transient( 'mainwp_dropbox_oauth_' . $state, $envelope, 10 * MINUTE_IN_SECONDS );

        return array(
            'authorize_url' => 'https://www.dropbox.com/oauth2/authorize?' . http_build_query(
                array(
                    'client_id'         => $app_key,
                    'response_type'     => 'code',
                    'token_access_type' => 'offline',
                    'state'             => $state,
                    'redirect_uri'      => static::dropbox_redirect_uri(),
                )
            ),
        );
    }

    /**
     * Complete the Connect Dropbox flow: consume the single-use state
     * stash, exchange the code for a refresh token, and save the
     * destination through the normal test-then-persist path.
     *
     * @param string        $state     State token from the callback.
     * @param string        $code      Single-use authorization code.
     * @param callable|null $transport Exchange transport override for tests.
     *
     * @return array {id} on success, {error} on failure.
     */
    public static function complete_dropbox_oauth( $state, $code, $transport = null ) {
        if ( ! is_string( $state ) || ! preg_match( '/^[a-f0-9]{32}$/', $state ) ) {
            return array( 'error' => esc_html__( 'The Dropbox connection request is not valid. Start the connection again.', 'mainwp' ) );
        }

        // Consume first: whatever happens next, the state is single use.
        $key      = 'mainwp_dropbox_oauth_' . $state;
        $envelope = get_transient( $key );
        delete_transient( $key );

        if ( ! is_string( $envelope ) || '' === $envelope ) {
            return array( 'error' => esc_html__( 'The Dropbox connection expired or was already used. Start the connection again.', 'mainwp' ) );
        }

        $plaintext = MainWP_Credential_Storage::decrypt_credential( $envelope );
        $data      = json_decode( substr( $envelope, strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
        if ( is_array( $data ) && ! empty( $data['file_key'] ) ) {
            // The stash's key material is spent along with the stash.
            MainWP_Credential_Storage::retire_key_file_if_changed( (string) $data['file_key'], '' );
        }

        $stash = json_decode( (string) $plaintext, true );
        if ( ! is_array( $stash ) || empty( $stash['app_key'] ) || empty( $stash['app_secret'] ) ) {
            return array( 'error' => esc_html__( 'The Dropbox connection could not be read back. Start the connection again.', 'mainwp' ) );
        }

        if ( get_current_user_id() !== (int) $stash['user_id'] ) {
            return array( 'error' => esc_html__( 'This Dropbox connection was started by a different user. Start the connection again.', 'mainwp' ) );
        }

        $exchange = MainWP_Backup_Storage_Dropbox::exchange_authorization_code(
            (string) $stash['app_key'],
            (string) $stash['app_secret'],
            (string) $code,
            static::dropbox_redirect_uri(),
            $transport
        );
        if ( isset( $exchange['error'] ) ) {
            return array( 'error' => $exchange['error'] );
        }

        return static::save_destination(
            array(
                'id'         => isset( $stash['destination_id'] ) ? (int) $stash['destination_id'] : 0,
                'provider'   => 'dropbox',
                'name'       => (string) $stash['name'],
                'is_default' => empty( $stash['is_default'] ) ? 0 : 1,
                'settings'   => array(
                    'app_key'       => (string) $stash['app_key'],
                    'app_secret'    => (string) $stash['app_secret'],
                    'refresh_token' => (string) $exchange['refresh_token'],
                    'base_path'     => isset( $stash['base_path'] ) ? (string) $stash['base_path'] : '',
                ),
            )
        );
    }

    /**
     * The OAuth callback: Dropbox lands the browser back on the Storage
     * page with code and state. Completes the connection server-side,
     * stashes a notice, and reloads the page clean.
     *
     * @return void
     */
    public function handle_dropbox_oauth_callback() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the single-use server-stashed state token is the CSRF control; it is unguessable, user-bound, and consumed on first use.
        if ( ! isset( $_GET['mainwp_dropbox_oauth'], $_GET['state'] ) ) {
            return;
        }
        if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            return;
        }

        $state = strtolower( sanitize_key( wp_unslash( $_GET['state'] ) ) );
        $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

        if ( isset( $_GET['error'] ) ) {
            // The user declined on the Dropbox side; consume the stash anyway.
            static::complete_dropbox_oauth( $state, '' );
            $result = array( 'error' => esc_html__( 'The Dropbox authorization was declined. Nothing was saved.', 'mainwp' ) );
        } elseif ( '' === $code ) {
            $result = array( 'error' => esc_html__( 'Dropbox returned no access code. Start the connection again.', 'mainwp' ) );
        } else {
            $result = static::complete_dropbox_oauth( $state, $code );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        set_transient( 'mainwp_dropbox_oauth_notice_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=ManageBackupsStorage' ) );
        exit;
    }

    /**
     * Read and clear the pending OAuth result notice for the current
     * user, for the Storage page render.
     *
     * @return array|null {id}|{error}, or null when none is pending.
     */
    public static function consume_dropbox_oauth_notice() {
        $key    = 'mainwp_dropbox_oauth_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        delete_transient( $key );
        return is_array( $notice ) ? $notice : null;
    }

    /**
     * Permission gate shared by every storage endpoint.
     *
     * @return void
     */
    private function check_permission() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Insufficient permissions to manage backup storage.', 'mainwp' ) ) ) );
        }
    }

    /**
     * Read the submitted destination fields.
     *
     * @return array
     */
    private function read_destination_input() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        // Null when not submitted at all (the list row's Test button), so
        // stored settings can be used wholesale.
        $settings = null;
        if ( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) {
            $settings = array();
            foreach ( wp_unslash( $_POST['settings'] ) as $field => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
                // A PEM private key is multi-line; sanitize_text_field would strip its line breaks.
                $settings[ sanitize_key( $field ) ] = 'private_key' === $field ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
            }
        }

        return array(
            'id'         => isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0,
            'provider'   => isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '',
            'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'is_default' => isset( $_POST['is_default'] ) ? intval( $_POST['is_default'] ) : 0,
            'settings'   => $settings,
        );
        // phpcs:enable
    }

    /**
     * Save (test-then-persist) a destination.
     *
     * @return void
     */
    public function ajax_storage_save() {
        $this->secure_request( 'mainwp_storage_save' );
        $this->check_permission();
        die( wp_json_encode( static::save_destination( $this->read_destination_input() ) ) );
    }

    /**
     * Connection-test submitted or stored settings without saving.
     *
     * @return void
     */
    public function ajax_storage_test() {
        $this->secure_request( 'mainwp_storage_test' );
        $this->check_permission();
        die( wp_json_encode( static::test_saved_or_submitted( $this->read_destination_input() ) ) );
    }

    /**
     * Connection-test a destination from submitted settings, or from the
     * stored ones when none are submitted.
     *
     * @param array $input Destination fields; settings null means "use stored".
     *
     * @return array {ok, error} | {error}.
     */
    public static function test_saved_or_submitted( array $input ) {
        $merged = static::merge_candidate( $input );
        if ( isset( $merged['error'] ) ) {
            return $merged;
        }
        return static::run_connection_test( $merged );
    }

    /**
     * Delete a destination, two-step when tasks reference it.
     *
     * @return void
     */
    public function ajax_storage_delete() {
        $this->secure_request( 'mainwp_storage_delete' );
        $this->check_permission();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        $id        = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $confirmed = isset( $_POST['confirmed'] ) && '1' === $_POST['confirmed'];
        // phpcs:enable
        die( wp_json_encode( static::delete_destination_flow( $id, $confirmed ) ) );
    }

    /**
     * Return a destination's non-secret fields so the edit form can
     * pre-fill them. Secrets never leave the server.
     *
     * @return void
     */
    public function ajax_storage_get() {
        $this->secure_request( 'mainwp_storage_get' );
        $this->check_permission();
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        die( wp_json_encode( static::destination_for_edit( $id ) ) );
    }

    /**
     * A destination's edit-form view: name, provider, default flag, and
     * settings with every secret field stripped.
     *
     * @param int $id Destination id.
     *
     * @return array {id, name, provider, is_default, settings, credentials_invalid} | {error}.
     */
    public static function destination_for_edit( $id ) {
        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $id );
        if ( null === $destination ) {
            return array( 'error' => esc_html__( 'The storage destination no longer exists.', 'mainwp' ) );
        }

        $settings = is_array( $destination->settings ) ? $destination->settings : array();
        foreach ( static::SECRET_FIELDS as $field ) {
            unset( $settings[ $field ] );
        }

        return array(
            'id'                  => (int) $destination->id,
            'name'                => (string) $destination->name,
            'provider'            => (string) $destination->provider,
            'is_default'          => ! empty( $destination->is_default ),
            'settings'            => $settings,
            'credentials_invalid' => ! empty( $destination->credentials_invalid ),
        );
    }

    /**
     * Mark a destination as the default.
     *
     * @return void
     */
    public function ajax_storage_default() {
        $this->secure_request( 'mainwp_storage_default' );
        $this->check_permission();
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        die( wp_json_encode( static::set_default_destination( $id ) ) );
    }

    /**
     * Validate, connection-test, and persist a destination.
     *
     * @param array $input Destination fields: id, provider, name, is_default, settings.
     *
     * @return array {id} on success, {error} on failure.
     */
    public static function save_destination( array $input ) {
        $merged = static::merge_candidate( $input );
        if ( isset( $merged['error'] ) ) {
            return $merged;
        }

        $test = static::run_connection_test( $merged );
        if ( empty( $test['ok'] ) ) {
            return array(
                'error' => sprintf(
                    /* translators: %s: connection test error detail. */
                    esc_html__( 'Connection test failed: %s', 'mainwp' ),
                    esc_html( (string) $test['error'] )
                ),
            );
        }

        $store = MainWP_Backup_Storage_Destinations::instance();
        $row   = array(
            'provider'   => $merged['provider'],
            'name'       => $merged['name'],
            'settings'   => $merged['settings'],
            'is_default' => $merged['is_default'],
        );

        if ( $merged['id'] > 0 ) {
            if ( ! $store->update_destination( $merged['id'], $row ) ) {
                return array( 'error' => esc_html__( 'Could not save the storage destination.', 'mainwp' ) );
            }
            return array( 'id' => $merged['id'] );
        }

        $id = $store->add_destination( $row );
        if ( $id < 1 ) {
            return array( 'error' => esc_html__( 'Could not save the storage destination.', 'mainwp' ) );
        }

        return array( 'id' => $id );
    }

    /**
     * Validate input and merge retained secrets from the stored row.
     *
     * @param array $input Destination fields.
     *
     * @return array Merged candidate, or {error}.
     */
    private static function merge_candidate( array $input ) {
        $provider = isset( $input['provider'] ) ? (string) $input['provider'] : '';
        if ( ! in_array( $provider, static::ALLOWED_PROVIDERS, true ) ) {
            return array( 'error' => esc_html__( 'Unknown storage provider.', 'mainwp' ) );
        }

        $name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
        if ( '' === $name ) {
            return array( 'error' => esc_html__( 'A destination name is required.', 'mainwp' ) );
        }

        $id        = isset( $input['id'] ) ? (int) $input['id'] : 0;
        $submitted = isset( $input['settings'] ) && is_array( $input['settings'] );
        $settings  = $submitted ? $input['settings'] : array();

        if ( $id > 0 ) {
            $existing = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
            if ( null === $existing ) {
                return array( 'error' => esc_html__( 'The storage destination no longer exists.', 'mainwp' ) );
            }
            if ( is_array( $existing->settings ) ) {
                if ( ! $submitted ) {
                    // No settings posted at all (the list row's Test): use
                    // the stored settings wholesale.
                    $settings = $existing->settings;
                } else {
                    foreach ( static::SECRET_FIELDS as $field ) {
                        if ( ( ! isset( $settings[ $field ] ) || '' === $settings[ $field ] ) && isset( $existing->settings[ $field ] ) ) {
                            $settings[ $field ] = $existing->settings[ $field ];
                        }
                    }
                }
            }
        }

        $existing_settings = ( $id > 0 && isset( $existing ) && is_array( $existing->settings ) ) ? $existing->settings : array();

        return array(
            'id'         => $id,
            'provider'   => $provider,
            'name'       => $name,
            'is_default' => empty( $input['is_default'] ) ? 0 : 1,
            'settings'   => $settings,
            // The destination id to run the connection test under, so an
            // SFTP edit against an unchanged server enforces its learned
            // host-key pin BEFORE credentials are sent (a re-save under
            // interception would otherwise skip the pin and leak the
            // password). Zero for a new destination or one whose target
            // server changed - there the pin does not apply and TOFU
            // re-learns on the new server's first real use.
            'test_id'    => static::pin_enforcement_id( $id, $settings, $existing_settings ),
        );
    }

    /**
     * The destination id a connection test should enforce the host-key pin
     * under: the row id when editing a destination whose target server
     * (host + port) is unchanged, zero otherwise (a new destination, or an
     * edit that repoints at a different server).
     *
     * @param int   $id                Destination id (0 for a new destination).
     * @param array $new_settings      The settings being tested.
     * @param array $existing_settings The stored settings, empty for a new destination.
     *
     * @return int Destination id to enforce the pin under, or 0.
     */
    public static function pin_enforcement_id( int $id, array $new_settings, array $existing_settings ) {
        if ( $id <= 0 || empty( $existing_settings ) ) {
            return 0;
        }

        // Compare the way the provider actually dials the server, not by raw
        // string equality: the SFTP provider lowercases nothing but resolves
        // the host via DNS (case- and trailing-dot-insensitive) and casts the
        // port to int with a 22 default. A cosmetic difference ("022", " 22",
        // "SFTP.Example.com.") targets the identical real server, so it must
        // NOT drop pin enforcement and skip the host-key check.
        $norm_host = static function ( $host ) {
            return rtrim( strtolower( trim( (string) $host ) ), '.' );
        };
        $norm_port = static function ( $port ) {
            $port = trim( (string) $port );
            return '' === $port ? 22 : (int) $port;
        };

        $same_host = $norm_host( $new_settings['host'] ?? '' ) === $norm_host( $existing_settings['host'] ?? '' );
        $same_port = $norm_port( $new_settings['port'] ?? '' ) === $norm_port( $existing_settings['port'] ?? '' );
        return ( $same_host && $same_port ) ? $id : 0;
    }

    /**
     * Run a provider connection test against candidate settings.
     *
     * @param array $candidate Merged candidate fields.
     *
     * @return array {ok, error}.
     */
    private static function run_connection_test( array $candidate ) {
        try {
            $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider(
                (object) array(
                    'id'       => isset( $candidate['test_id'] ) ? (int) $candidate['test_id'] : 0,
                    'provider' => $candidate['provider'],
                    'settings' => $candidate['settings'],
                )
            );
            return $provider->connection_test();
        } catch ( \Throwable $e ) {
            return array(
                'ok'    => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Delete a destination. Without confirmation, referencing tasks are
     * reported instead; with it, those tasks are pruned, the
     * destination's pending upload jobs fail permanently, and the row is
     * removed.
     *
     * @param int  $id        Destination id.
     * @param bool $confirmed Whether the caller confirmed the delete.
     *
     * @return array {deleted} | {tasks} | {error}.
     */
    public static function delete_destination_flow( $id, $confirmed ) {
        $id = (int) $id;
        if ( null === MainWP_Backup_Storage_Destinations::instance()->get_destination( $id ) ) {
            return array( 'error' => esc_html__( 'The storage destination no longer exists.', 'mainwp' ) );
        }

        $tasks = static::tasks_using_destination( $id );

        if ( ! $confirmed && ! empty( $tasks ) ) {
            return array( 'tasks' => array_values( wp_list_pluck( $tasks, 'name' ) ) );
        }

        foreach ( $tasks as $task ) {
            $ids = json_decode( (string) $task->destinations, true );
            $ids = is_array( $ids ) ? array_values( array_diff( array_map( 'intval', $ids ), array( $id ) ) ) : array();
            if ( false === MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'destinations' => wp_json_encode( $ids ) ) ) ) {
                return array( 'error' => esc_html__( 'Could not detach this destination from a backup task. The destination was left in place.', 'mainwp' ) );
            }
        }

        static::fail_pending_jobs_for_destination( $id );

        if ( ! MainWP_Backup_Storage_Destinations::instance()->delete_destination( $id ) ) {
            return array( 'error' => esc_html__( 'Could not delete the storage destination.', 'mainwp' ) );
        }

        return array( 'deleted' => true );
    }

    /**
     * Mark a destination as the default.
     *
     * @param int $id Destination id.
     *
     * @return array {ok} | {error}.
     */
    public static function set_default_destination( $id ) {
        $id = (int) $id;
        if ( null === MainWP_Backup_Storage_Destinations::instance()->get_destination( $id ) ) {
            return array( 'error' => esc_html__( 'The storage destination no longer exists.', 'mainwp' ) );
        }

        if ( ! MainWP_Backup_Storage_Destinations::instance()->update_destination( $id, array( 'is_default' => 1 ) ) ) {
            return array( 'error' => esc_html__( 'Could not update the storage destination.', 'mainwp' ) );
        }

        return array( 'ok' => true );
    }

    /**
     * Persist a task's destination selection, keeping only ids that
     * exist.
     *
     * @param int   $task_id Task id.
     * @param array $raw_ids Submitted destination ids.
     *
     * @return void
     */
    public static function save_task_destinations( $task_id, array $raw_ids ) {
        $valid = array();
        $store = MainWP_Backup_Storage_Destinations::instance();

        foreach ( $raw_ids as $raw ) {
            $id = (int) $raw;
            if ( $id > 0 && null !== $store->get_destination( $id ) ) {
                $valid[] = $id;
            }
        }

        MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task_id, array( 'destinations' => wp_json_encode( array_values( array_unique( $valid ) ) ) ) );
    }

    /**
     * Backup tasks whose destinations include the given id.
     *
     * @param int $id Destination id.
     *
     * @return object[] Task rows.
     */
    public static function tasks_using_destination( $id ) {
        $id    = (int) $id;
        $tasks = MainWP_DB_Backup::instance()->get_backup_tasks();
        $using = array();

        foreach ( is_array( $tasks ) ? $tasks : array() as $task ) {
            $ids = json_decode( isset( $task->destinations ) ? (string) $task->destinations : '', true );
            if ( is_array( $ids ) && in_array( $id, array_map( 'intval', $ids ), true ) ) {
                $using[] = $task;
            }
        }

        return $using;
    }

    /**
     * Fail every non-terminal upload job aimed at a destination.
     *
     * @param int $id Destination id.
     *
     * @return void
     */
    private static function fail_pending_jobs_for_destination( $id ) {
        global $wpdb;

        $table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
        $jobs  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no store query covers cross-snapshot destination lookups.
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE destination_id = %d AND status NOT IN (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
                $id,
                MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE,
                MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT,
                MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID
            )
        );

        foreach ( array_map( 'intval', is_array( $jobs ) ? $jobs : array() ) as $job_id ) {
            MainWP_Backup_Upload_Job_Store::instance()->fail_permanent( $job_id, esc_html__( 'Storage destination was deleted.', 'mainwp' ) );
        }
    }
}
