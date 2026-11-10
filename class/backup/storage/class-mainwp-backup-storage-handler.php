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
    const ALLOWED_PROVIDERS = array( 'local', 's3', 'gcs', 'dropbox', 'pcloud', 'sftp' );

    /**
     * Settings fields treated as secrets: never echoed, kept on blank edit.
     *
     * @var string[]
     */
    const SECRET_FIELDS = array( 'secret_key', 'app_secret', 'refresh_token', 'password', 'private_key', 'access_token', 'client_secret' );

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
        $this->add_action( 'mainwp_pcloud_connect', array( &$this, 'ajax_pcloud_connect' ) );

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
        $this->check_unrestricted_permission();
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

        $stored  = $id > 0 ? MainWP_Backup_Storage_Destinations::instance()->get_destination( $id ) : null;
        $minimum = filter_var(
            array_key_exists( 'minimum_remote_retention_days', $settings ) ? $settings['minimum_remote_retention_days'] : ( $stored->settings['minimum_remote_retention_days'] ?? 0 ),
            FILTER_VALIDATE_INT,
            array(
                'options' => array(
                    'min_range' => 0,
                    'max_range' => 36500,
                ),
            )
        );
        if ( false === $minimum ) {
            return array( 'error' => esc_html__( 'Minimum retention must be a whole number from 0 to 36500 days.', 'mainwp' ) );
        }
        $stash = array(
            'minimum_remote_retention_days' => $minimum,
            'user_id'                       => get_current_user_id(),
            'destination_id'                => $id,
            'name'                          => $name,
            'app_key'                       => $app_key,
            'app_secret'                    => $app_secret,
            'base_path'                     => isset( $settings['base_path'] ) ? (string) $settings['base_path'] : '',
            'is_default'                    => empty( $input['is_default'] ) ? 0 : 1,
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
     * Consume pending credentials even when authorization is declined.
     *
     * @param string $state State token from the callback.
     *
     * @return array Pending credentials, or {error} on failure.
     */
    private static function consume_dropbox_oauth_state( $state ) {
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

        return $stash;
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
        $stash = static::consume_dropbox_oauth_state( $state );
        if ( isset( $stash['error'] ) ) {
            return $stash;
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
                    'app_key'                       => (string) $stash['app_key'],
                    'app_secret'                    => (string) $stash['app_secret'],
                    'minimum_remote_retention_days' => (int) ( $stash['minimum_remote_retention_days'] ?? 0 ),
                    'refresh_token'                 => (string) $exchange['refresh_token'],
                    'base_path'                     => isset( $stash['base_path'] ) ? (string) $stash['base_path'] : '',
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

        $refusal = $this->check_unrestricted_permission( false );
        if ( null !== $refusal ) {
            $result = $refusal;
        } elseif ( isset( $_GET['error'] ) ) {
            static::consume_dropbox_oauth_state( $state );
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
     * Connect pCloud with a code the user pasted from pCloud's approval page.
     *
     * @return void
     */
    public function ajax_pcloud_connect() {
        $this->secure_request( 'mainwp_pcloud_connect' );
        $this->check_permission();
        $this->check_unrestricted_permission();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        $code = isset( $_POST['authorization_code'] ) && is_string( $_POST['authorization_code'] ) ? sanitize_text_field( wp_unslash( $_POST['authorization_code'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        $stash_key = isset( $_POST['stash_key'] ) && is_string( $_POST['stash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stash_key'] ) ) : '';
        die( wp_json_encode( static::connect_pcloud( $this->read_destination_input(), $code, $stash_key ) ) );
    }

    /**
     * Exchange a pasted code, then save the destination through the normal test-then-persist path.
     *
     * The code is single use, so the destination is validated before it is spent. The app, region and
     * token reach the save only through its server-side argument; the code itself is never stored.
     *
     * @param array  $input     Destination fields: id, name, is_default, settings{base_path, minimum_remote_retention_days}.
     * @param string $code      Authorization code shown by pCloud.
     * @param string $stash_key Single-use account-change confirmation key.
     *
     * @return array {id} on success, {error} on failure.
     */
    public static function connect_pcloud( array $input, string $code, string $stash_key = '' ) {
        if ( '' !== $stash_key ) {
            $stash = static::consume_pcloud_connection( $stash_key, (int) ( $input['id'] ?? 0 ) );
            if ( isset( $stash['error'] ) ) {
                return $stash;
            }
            $input    = $stash['input'];
            $oauth    = $stash['oauth'];
            $replaces = $stash['replaces'];
            // A switch leaves the old account's token working, so a failed save loses nothing.
            $reconnecting_id = 0;
        } else {
            $code = trim( $code );
            if ( '' === $code ) {
                return array( 'error' => esc_html__( 'Paste the authorization code from pCloud, then save.', 'mainwp' ) );
            }
            $input['provider'] = 'pcloud';
            $merged            = static::merge_candidate( $input );
            if ( isset( $merged['error'] ) ) {
                return $merged;
            }
            $credentials = MainWP_Backup_Storage_PCloud::app_credentials();
            if ( '' === $credentials['client_id'] || '' === $credentials['client_secret'] ) {
                return array( 'error' => esc_html__( 'pCloud connections are not available on this Dashboard yet.', 'mainwp' ) );
            }
            $connection = MainWP_Backup_Storage_PCloud::exchange_code_for_region( $credentials['client_id'], $credentials['client_secret'], $code );
            if ( isset( $connection['error'] ) ) {
                return array( 'error' => $connection['error'] );
            }
            $oauth = array(
                'client_id'    => $credentials['client_id'],
                'locationid'   => $connection['locationid'],
                'access_token' => $connection['access_token'],
            );
            if ( isset( $connection['account_email'] ) ) {
                $oauth['account_email'] = $connection['account_email'];
            }
            $oauth['userid'] = $connection['userid'];
            static::refresh_pcloud_siblings( $oauth, (int) $merged['id'] );
            $existing       = $merged['expected'];
            $identity_field = MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD;
            if ( null !== $existing && 'pcloud' === $existing->provider && ! empty( $existing->settings[ $identity_field ]['userid'] )
                && ( (string) $existing->settings[ $identity_field ]['userid'] !== $connection['userid']
                    || (string) ( $existing->settings['locationid'] ?? '' ) !== (string) $connection['locationid'] ) ) {
                return static::stash_pcloud_connection( $merged, $oauth );
            }
            $replaces = static::pcloud_connected_account( $existing );
            // pCloud revoked the stored token during the exchange, and a failed save keeps it.
            $reconnecting_id = null !== $existing && 'pcloud' === $existing->provider ? (int) $merged['id'] : 0;
        }

        $result = static::save_destination( $input, $oauth, $replaces );
        // Skip the warning if another admin replaced or deleted the destination mid-request.
        if ( 0 < $reconnecting_id && isset( $result['error'] )
            && static::pcloud_connected_account( MainWP_Backup_Storage_Destinations::instance()->get_destination( $reconnecting_id ) ) === $replaces ) {
            $result['error'] .= ' ' . esc_html__( "Using the code also ended this destination's earlier connection, so backups to it will fail until you click Connect pCloud and paste a new code.", 'mainwp' );
        }
        return $result;
    }

    /**
     * Keep pCloud's manual revocation instructions consistent across confirmations.
     *
     * @return string
     */
    public static function pcloud_revoke_guidance(): string {
        return esc_html__( 'To revoke it, open pCloud and remove MainWP Dashboard under Settings > Linked Accounts > Linked Apps.', 'mainwp' );
    }

    /**
     * The code is spent, so confirmation must retain its exchange without exposing the token.
     *
     * @param array $merged Validated destination candidate.
     * @param array $oauth  Server-issued connection.
     * @return array
     */
    private static function stash_pcloud_connection( array $merged, array $oauth ): array {
        $stash    = array(
            'user_id'        => get_current_user_id(),
            'destination_id' => $merged['id'],
            'replaces'       => static::pcloud_connected_account( $merged['expected'] ),
            'oauth'          => $oauth,
            'input'          => array(
                'id'         => $merged['id'],
                'provider'   => 'pcloud',
                'name'       => $merged['name'],
                'is_default' => $merged['is_default'],
                'settings'   => array_intersect_key(
                    $merged['settings'],
                    array(
                        'base_path'                     => true,
                        'minimum_remote_retention_days' => true,
                    )
                ),
            ),
        );
        $envelope = MainWP_Credential_Storage::encrypt_credential( (string) wp_json_encode( $stash ), 'storage_destination' );
        if ( ! is_string( $envelope ) || ! MainWP_Credential_Storage::is_credential_envelope( $envelope ) ) {
            return array( 'error' => esc_html__( 'The connection could not be prepared. Check that the dashboard can encrypt credentials.', 'mainwp' ) );
        }
        $key = bin2hex( random_bytes( 16 ) );
        set_transient( 'mainwp_pcloud_connect_' . $key, $envelope, 10 * MINUTE_IN_SECONDS );
        $old_email = (string) ( $merged['expected']->settings['account_email'] ?? esc_html__( 'unknown account', 'mainwp' ) );
        $new_email = (string) ( $oauth['account_email'] ?? esc_html__( 'unknown account', 'mainwp' ) );
        return array(
            'error'             => sprintf(
                /* translators: %1$s = current account email, %2$s = new account email. */
                esc_html__( "This code is for a different pCloud account (%2\$s). This destination is connected to %1\$s. Backups already in %1\$s stay there, and this destination can't restore or clean them up after the switch. Switch to %2\$s? Switching doesn't revoke this Dashboard's access to %1\$s in pCloud.", 'mainwp' ),
                esc_html( $old_email ),
                esc_html( $new_email )
            ) . ' ' . static::pcloud_revoke_guidance(),
            'stash_key'         => $key,
            'old_account_email' => $old_email,
            'new_account_email' => $new_email,
        );
    }

    /**
     * Strings only, so the value compares equal after the stash's JSON round trip.
     *
     * @param object|null $row Stored destination.
     * @return array {provider, userid, locationid}
     */
    private static function pcloud_connected_account( $row ): array {
        $settings = is_object( $row ) && is_array( $row->settings ?? null ) ? $row->settings : array();
        $identity = $settings[ MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD ] ?? array();
        return array(
            'provider'   => is_object( $row ) ? (string) ( $row->provider ?? '' ) : '',
            'userid'     => is_array( $identity ) ? (string) ( $identity['userid'] ?? '' ) : '',
            'locationid' => (string) ( $settings['locationid'] ?? '' ),
        );
    }

    /**
     * Confirmation cannot be replayed or transferred to another user or destination.
     *
     * @param string $stash_key      Single-use confirmation key.
     * @param int    $destination_id Submitted destination ID.
     * @return array
     */
    private static function consume_pcloud_connection( string $stash_key, int $destination_id ): array {
        $error = array( 'error' => esc_html__( 'This confirmation expired or was already used. Click Connect pCloud and paste a new code.', 'mainwp' ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $stash_key ) ) {
            return $error;
        }
        $key      = 'mainwp_pcloud_connect_' . $stash_key;
        $envelope = get_transient( $key );
        delete_transient( $key );
        if ( ! is_string( $envelope ) || ! MainWP_Credential_Storage::is_credential_envelope( $envelope ) ) {
            return $error;
        }
        $plaintext = MainWP_Credential_Storage::decrypt_credential( $envelope );
        $data      = json_decode( substr( $envelope, strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
        if ( is_array( $data ) && ! empty( $data['file_key'] ) ) {
            MainWP_Credential_Storage::retire_key_file_if_changed( (string) $data['file_key'], '' );
        }
        $stash = json_decode( (string) $plaintext, true );
        if ( ! is_array( $stash ) || (int) ( $stash['user_id'] ?? 0 ) !== get_current_user_id()
            || (int) ( $stash['destination_id'] ?? 0 ) !== $destination_id || empty( $stash['input'] ) || empty( $stash['oauth']['access_token'] ) ) {
            return $error;
        }
        if ( ! isset( $stash['replaces'] ) || ! is_array( $stash['replaces'] ) ) {
            return $error;
        }
        return $stash;
    }

    /**
     * Refresh sibling credentials only after re-proving their stored account and folder.
     *
     * @param array $oauth       Server-issued app, region, token and account ID.
     * @param int   $excluded_id Destination being edited, or zero for a new destination.
     * @return void
     */
    private static function refresh_pcloud_siblings( array $oauth, int $excluded_id ): void {
        $store = MainWP_Backup_Storage_Destinations::instance();
        $field = MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD;
        // pCloud revokes earlier tokens of the same app and account on every new authorization.
        foreach ( $store->list_destinations() as $row ) {
            if ( (int) $row->id === $excluded_id || 'pcloud' !== $row->provider ) {
                continue;
            }
            $sibling  = $store->get_destination( (int) $row->id );
            $settings = $sibling->settings ?? null;
            if ( ! is_array( $settings ) || empty( $settings[ $field ]['userid'] ) || ! isset( $settings[ $field ]['folderid'] )
                || (string) ( $settings['client_id'] ?? '' ) !== (string) $oauth['client_id']
                || (string) ( $settings['locationid'] ?? '' ) !== (string) $oauth['locationid']
                || (string) $settings[ $field ]['userid'] !== (string) $oauth['userid'] ) {
                continue;
            }
            $settings['access_token'] = $oauth['access_token'];
            try {
                $candidate           = clone $sibling;
                $candidate->settings = $settings;
                $provider            = $store->make_provider( $candidate );
                if ( ! $provider instanceof MainWP_Backup_Storage_PCloud ) {
                    continue;
                }
                $userid = $provider->account_userid();
                if ( $userid !== (string) $settings[ $field ]['userid'] ) {
                    continue;
                }
                $folderid = $provider->base_folder_id();
                if ( $folderid !== (string) $settings[ $field ]['folderid'] ) {
                    continue;
                }
                $settings = MainWP_Backup_Retention_Protection::with_pcloud_identity( $settings, $userid, $folderid );
                $store->update_destination( (int) $sibling->id, array( 'settings' => $settings ), $sibling );
            } catch ( \RuntimeException $e ) {
                // A stale sibling must not block saving the new connection or refreshing other siblings.
                continue;
            }
        }
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
     * Shared destinations can affect tasks outside a restricted user's scope.
     *
     * @param bool $ajax Whether refusal must end the AJAX response.
     * @return array|null Refusal for the OAuth notice, or null when allowed.
     */
    private function check_unrestricted_permission( bool $ajax = true ): ?array {
        // Pointing backups at a destination you control is a download, so it needs that right too.
        if ( ! is_wp_error( MainWP_Abilities_Util::check_unrestricted_access() ) && \mainwp_current_user_can( 'dashboard', 'download_backups' ) ) {
            return null;
        }
        $refusal = array( 'error' => esc_html__( 'Only WordPress administrators can change storage destinations.', 'mainwp' ) );
        if ( $ajax ) {
            die( wp_json_encode( $refusal ) );
        }
        return $refusal;
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
                $field = sanitize_key( $field );
                if ( MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD === $field ) {
                    continue;
                }
                // A PEM private key is multi-line; sanitize_text_field would strip its line breaks.
                if ( 'minimum_remote_retention_days' === $field ) {
                    $settings[ $field ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
                } elseif ( 'private_key' === $field ) {
                    $settings[ $field ] = sanitize_textarea_field( (string) $value );
                } elseif ( in_array( $field, static::SECRET_FIELDS, true ) ) {
                    $settings[ $field ] = (string) $value;
                } else {
                    $settings[ $field ] = sanitize_text_field( (string) $value );
                }
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
        $this->check_unrestricted_permission();
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
        $this->check_unrestricted_permission();
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
        $this->check_unrestricted_permission();
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
        $this->check_unrestricted_permission();
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
        unset( $settings[ MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD ] );

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
        $this->check_unrestricted_permission();
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request.
        die( wp_json_encode( static::set_default_destination( $id ) ) );
    }

    /**
     * Validate, connection-test, and persist a destination.
     *
     * @param array      $input          Destination fields: id, provider, name, is_default, settings.
     * @param array      $pcloud_oauth   Server-only app, region, token and optional account email from the pCloud code exchange.
     * @param array|null $pcloud_account Connected account the exchange was checked against, from pcloud_connected_account().
     *
     * @return array {id, notice?} on success, {error} on failure.
     */
    public static function save_destination( array $input, array $pcloud_oauth = array(), ?array $pcloud_account = null ) {
        $merged = static::merge_candidate( $input );
        if ( isset( $merged['error'] ) ) {
            return $merged;
        }
        // The exchange and sibling refresh take several requests; another admin may have reconnected this destination meanwhile.
        if ( null !== $pcloud_account && static::pcloud_connected_account( $merged['expected'] ) !== $pcloud_account ) {
            return array( 'error' => esc_html__( 'This destination changed before your connection was saved. Click Connect pCloud and paste a new code.', 'mainwp' ) );
        }

        // Browser saves never supply this argument; merge_candidate strips their region.
        if ( 'pcloud' === $merged['provider'] ) {
            if ( ! empty( $pcloud_oauth ) ) {
                // A reconnect may belong to a different account even when userinfo omits its email.
                unset( $merged['settings']['account_email'] );
            }
            $merged['settings'] = array_merge(
                $merged['settings'],
                array_intersect_key(
                    $pcloud_oauth,
                    array(
                        'client_id'     => true,
                        'locationid'    => true,
                        'access_token'  => true,
                        'account_email' => true,
                    )
                )
            );
            if ( ! in_array( $merged['settings']['locationid'] ?? null, array( 1, 2, '1', '2' ), true ) ) {
                if ( empty( $pcloud_oauth ) && ( null === $merged['expected'] || 'pcloud' !== $merged['expected']->provider ) ) {
                    return array( 'error' => esc_html__( 'Click Connect pCloud and paste the authorization code to finish adding this destination.', 'mainwp' ) );
                }
                // An undecryptable row has no stored region to copy; the fix is a reconnect, not a region problem.
                if ( null !== $merged['expected'] && ! empty( $merged['expected']->credentials_invalid ) ) {
                    return array( 'error' => esc_html__( 'Click Connect pCloud and paste the authorization code to reconnect this destination.', 'mainwp' ) );
                }
                return array( 'error' => esc_html__( 'Invalid pCloud storage region. Reconnect this destination.', 'mainwp' ) );
            }
            $merged['settings']['locationid'] = (int) $merged['settings']['locationid'];
        }

        $test_connection = true;
        $existing        = $merged['expected'];
        if ( 'dropbox' === $merged['provider'] && null !== $existing && 'dropbox' === $existing->provider
            && empty( $existing->credentials_invalid ) && is_array( $existing->settings ) ) {
            $test_connection = false;
            foreach ( array( 'app_key', 'app_secret', 'refresh_token', 'base_path' ) as $field ) {
                $candidate = (string) ( $merged['settings'][ $field ] ?? '' );
                $stored    = (string) ( $existing->settings[ $field ] ?? '' );
                if ( 'base_path' === $field ) {
                    $candidate = trim( $candidate, '/' );
                    $stored    = trim( $stored, '/' );
                }
                if ( $candidate !== $stored ) {
                    $test_connection = true;
                    break;
                }
            }
        }
        if ( 'pcloud' === $merged['provider'] && empty( $pcloud_oauth ) && null !== $existing && 'pcloud' === $existing->provider
            && empty( $existing->credentials_invalid ) && is_array( $existing->settings ) ) {
            // Sibling: Dropbox's metadata-only branch above also keeps proof without HTTP.
            $test_connection = false;
            foreach ( array( 'client_id', 'access_token', 'locationid', 'base_path' ) as $field ) {
                $default   = 'base_path' === $field ? 'MainWP Backups' : '';
                $candidate = (string) ( $merged['settings'][ $field ] ?? $default );
                $stored    = (string) ( $existing->settings[ $field ] ?? $default );
                $stored    = 'base_path' === $field ? trim( $stored, '/' ) : trim( $stored );
                if ( $candidate !== $stored ) {
                    $test_connection = true;
                    break;
                }
            }
        }
        $test = $test_connection ? static::run_connection_test( $merged ) : array( 'ok' => true );
        if ( empty( $test['ok'] ) ) {
            return array(
                'error' => sprintf(
                    /* translators: %s: connection test error detail. */
                    esc_html__( 'Connection test failed: %s', 'mainwp' ),
                    (string) $test['error']
                ),
            );
        }

        $identity_field = MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD;
        if ( $test_connection && 'dropbox' === $merged['provider'] && ! empty( $merged['settings'][ $identity_field ]['key'] )
            && ! MainWP_Backup_Retention_Protection::dropbox_identity_valid( $merged['settings'] ) ) {
            try {
                $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( (object) $merged );
                if ( ! $provider instanceof MainWP_Backup_Storage_Dropbox ) {
                    return array( 'error' => esc_html__( 'Dropbox storage identity could not be verified.', 'mainwp' ) );
                }
                $merged['settings'] = MainWP_Backup_Retention_Protection::with_dropbox_identity( $merged['settings'], $provider->retention_marker_id( $merged['settings'][ $identity_field ]['key'] ) );
            } catch ( \RuntimeException $e ) {
                return array( 'error' => $e->getMessage() );
            }
        }

        if ( $test_connection && 'pcloud' === $merged['provider'] ) {
            // Sibling: Dropbox's save re-proof above must also refuse an unverified target.
            try {
                $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( (object) $merged );
                if ( ! $provider instanceof MainWP_Backup_Storage_PCloud ) {
                    return array( 'error' => esc_html__( 'pCloud storage identity could not be verified.', 'mainwp' ) );
                }
                $merged['settings'] = MainWP_Backup_Retention_Protection::with_pcloud_identity( $merged['settings'], $provider->account_userid(), $provider->base_folder_id() );
            } catch ( \RuntimeException $e ) {
                return array( 'error' => $e->getMessage() );
            }
        }

        $store = MainWP_Backup_Storage_Destinations::instance();
        $row   = array(
            'provider'   => $merged['provider'],
            'name'       => $merged['name'],
            'settings'   => $merged['settings'],
            'is_default' => $merged['is_default'],
        );

        if ( $merged['id'] > 0 ) {
            if ( ! $store->update_destination( $merged['id'], $row, $merged['expected'] ) ) {
                return array( 'error' => esc_html__( 'Could not save the storage destination.', 'mainwp' ) );
            }
            $result = array( 'id' => $merged['id'] );
            if ( $merged['reset_sftp_trust'] ) {
                $result['notice'] = static::sftp_trust_reset_notice();
            }
            return $result;
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
     * Only SFTP-to-SFTP edits can enforce a surviving pin. A notice is
     * warranted only when readable settings prove the server changed.
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

        $id             = isset( $input['id'] ) ? (int) $input['id'] : 0;
        $submitted      = isset( $input['settings'] ) && is_array( $input['settings'] );
        $settings       = $submitted ? $input['settings'] : array();
        $identity_field = MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD;
        unset( $settings[ $identity_field ], $settings['authorization_code'] );
        if ( 'pcloud' === $provider ) {
            // The app, region, token and account email come only from the server-side code exchange.
            unset( $settings['client_id'], $settings['locationid'], $settings['access_token'], $settings['client_secret'], $settings['account_email'] );
        }

        if ( $id > 0 ) {
            $existing = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
            if ( null === $existing ) {
                return array( 'error' => esc_html__( 'The storage destination no longer exists.', 'mainwp' ) );
            }
            if ( $provider === (string) $existing->provider && is_array( $existing->settings ) ) {
                if ( 'pcloud' === $provider ) {
                    // Sibling: Dropbox's identity preservation below; app and region must precede the target comparison.
                    foreach ( array( 'client_id', 'locationid', 'account_email' ) as $field ) {
                        if ( isset( $existing->settings[ $field ] ) ) {
                            $settings[ $field ] = $existing->settings[ $field ];
                        }
                    }
                    if ( isset( $existing->settings[ $identity_field ] ) ) {
                        $settings[ $identity_field ] = $existing->settings[ $identity_field ];
                    }
                }
                if ( ! $submitted ) {
                    // No settings posted at all (the list row's Test): use
                    // the stored settings wholesale.
                    $settings = $existing->settings;
                } elseif ( self::same_credential_target( $provider, $settings, $existing->settings ) ) {
                    foreach ( static::SECRET_FIELDS as $field ) {
                        if ( ( ! isset( $settings[ $field ] ) || '' === $settings[ $field ] ) && isset( $existing->settings[ $field ] ) ) {
                            $settings[ $field ] = $existing->settings[ $field ];
                        }
                    }
                }
                if ( 'dropbox' === $provider && isset( $existing->settings[ $identity_field ] ) ) {
                    $settings[ $identity_field ] = $existing->settings[ $identity_field ];
                }
            }
        }

        if ( 'pcloud' === $provider ) {
            unset( $settings['client_secret'] );
            $settings['client_id']    = trim( sanitize_text_field( (string) ( $settings['client_id'] ?? '' ) ) );
            $settings['access_token'] = trim( (string) ( $settings['access_token'] ?? '' ) );
            $settings['base_path']    = trim( sanitize_text_field( (string) ( $settings['base_path'] ?? 'MainWP Backups' ) ), '/' );
        }

        // The form lists None as 'none' (Fomantic hides an empty-value
        // option); the S3 provider sends no encryption header for ''.
        if ( 's3' === $provider && isset( $settings['sse'] ) && 'none' === $settings['sse'] ) {
            $settings['sse'] = '';
        }

        $stored_minimum = isset( $existing ) && is_array( $existing->settings ) ? array_intersect_key( $existing->settings, array( 'minimum_remote_retention_days' => true ) ) : array();
        if ( array_key_exists( 'minimum_remote_retention_days', $settings ) || $stored_minimum ) {
            $minimum = array_key_exists( 'minimum_remote_retention_days', $settings ) ? $settings['minimum_remote_retention_days'] : $stored_minimum['minimum_remote_retention_days'];
            $minimum = filter_var(
                $minimum,
                FILTER_VALIDATE_INT,
                array(
                    'options' => array(
                        'min_range' => 0,
                        'max_range' => 36500,
                    ),
                )
            );
            if ( false === $minimum ) {
                return array( 'error' => esc_html__( 'Minimum retention must be a whole number from 0 to 36500 days.', 'mainwp' ) );
            }
            $settings['minimum_remote_retention_days'] = $minimum;
        }

        if ( 'local' === $provider && '' !== (string) ( $settings['base_path'] ?? '' ) ) {
            $reason = MainWP_Backup_Private_Root::served_tree_refusal( (string) $settings['base_path'] );
            if ( '' !== $reason ) {
                return array( 'error' => $reason );
            }
        }

        $existing_settings = ( $id > 0 && isset( $existing ) && is_array( $existing->settings ) ) ? $existing->settings : array();
        $decision          = static::sftp_pin_decision( $id, $id > 0 ? (string) $existing->provider : '', $provider, $settings, $existing_settings );

        return array(
            'id'               => $id,
            'provider'         => $provider,
            'name'             => $name,
            'is_default'       => empty( $input['is_default'] ) ? 0 : 1,
            'settings'         => $settings,
            'reset_sftp_trust' => $decision['notice'],
            'test_id'          => $decision['test_id'],
            'expected'         => $existing ?? null,
        );
    }

    /**
     * Check whether retained credentials would be sent to the same target.
     *
     * @param string $provider Provider key.
     * @param array  $settings Candidate settings.
     * @param array  $stored   Stored settings.
     *
     * @return bool
     */
    private static function same_credential_target( string $provider, array $settings, array $stored ): bool {
        $identity_fields = array(
            's3'      => array( 'endpoint', 'bucket', 'region', 'path_style', 'access_key' ),
            'gcs'     => array( 'bucket', 'region', 'access_key' ),
            'dropbox' => array( 'app_key' ),
            'pcloud'  => array( 'client_id', 'locationid' ),
            'sftp'    => array( 'username' ),
            'local'   => array(),
        );
        if ( ! isset( $identity_fields[ $provider ] ) ) {
            return false;
        }
        if ( 'sftp' === $provider && 0 === static::pin_enforcement_id( 1, $settings, $stored ) ) {
            return false;
        }
        if ( 'pcloud' === $provider ) {
            $settings['client_id'] = trim( (string) ( $settings['client_id'] ?? '' ) );
            $stored['client_id']   = trim( (string) ( $stored['client_id'] ?? '' ) );
        }
        foreach ( $identity_fields[ $provider ] as $field ) {
            if ( (string) ( $settings[ $field ] ?? '' ) !== (string) ( $stored[ $field ] ?? '' ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The destination id a connection test should enforce the host-key pin
     * under: the row id when editing a destination whose target server
     * (host + port) is unchanged or whose stored settings are empty or
     * unreadable. Recovery must enforce any surviving pin and learn under
     * the row id if none survives. A new destination or a known host/port
     * change returns zero. If recovery also changes the server, the admin
     * must enter its new fingerprint in the Host key fingerprint field.
     *
     * @param int   $id                Destination id (0 for a new destination).
     * @param array $new_settings      The settings being tested.
     * @param array $existing_settings The stored settings, empty when new or unreadable.
     *
     * @return int Destination id to enforce the pin under, or 0.
     */
    public static function pin_enforcement_id( int $id, array $new_settings, array $existing_settings ) {
        if ( $id <= 0 ) {
            return 0;
        }
        if ( empty( $existing_settings ) ) {
            return $id;
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
        // Mirror the provider's own port derivation EXACTLY
        // (class-mainwp-backup-storage-sftp.php default_connection):
        // `! empty( $port ) ? (int) $port : 22`. empty() treats "0" (and "")
        // as falsy, so both dial 22 - the normalizer must too, or a submitted
        // "0" against a stored "22" would look like a different port and skip
        // the pin while hitting the identical server.
        $norm_port = static function ( $port ) {
            return empty( $port ) ? 22 : (int) $port;
        };

        $same_host = $norm_host( $new_settings['host'] ?? '' ) === $norm_host( $existing_settings['host'] ?? '' );
        $same_port = $norm_port( $new_settings['port'] ?? '' ) === $norm_port( $existing_settings['port'] ?? '' );
        return ( $same_host && $same_port ) ? $id : 0;
    }

    /**
     * Keep a pin only for SFTP-to-SFTP edits on the same or an unknown server.
     *
     * The test_id value is the destination id the connection test runs under, so a
     * surviving pin is enforced BEFORE the credential is sent: a re-save under
     * interception would otherwise skip the pin and leak the password. That
     * is why unreadable settings count as the same server during credential
     * recovery, and only for an SFTP-to-SFTP edit. Entering or leaving SFTP
     * clears stale trust so a later switch back cannot hit a pin learned on
     * another host, and trust-on-first-use learns the new server. Only a
     * known SFTP host or port change warrants the server-change notice.
     *
     * @param int    $id                Destination id (0 for a new destination).
     * @param string $stored_provider   Stored provider.
     * @param string $new_provider      Candidate provider.
     * @param array  $new_settings      Candidate settings.
     * @param array  $existing_settings Stored settings, empty when new or unreadable.
     *
     * @return array {test_id: int, reset: bool, notice: bool}.
     */
    public static function sftp_pin_decision( int $id, string $stored_provider, string $new_provider, array $new_settings, array $existing_settings ): array {
        $decision = array(
            'test_id' => 0,
            'reset'   => false,
            'notice'  => false,
        );
        if ( $id <= 0 ) {
            return $decision;
        }

        if ( 'sftp' === $stored_provider && 'sftp' === $new_provider ) {
            $decision['test_id'] = static::pin_enforcement_id( $id, $new_settings, $existing_settings );
            $decision['reset']   = 0 === $decision['test_id'];
            $decision['notice']  = $decision['reset'];
        } elseif ( 'sftp' === $stored_provider || 'sftp' === $new_provider ) {
            $decision['reset'] = true;
        }

        return $decision;
    }

    /**
     * Explain why the next connection must establish trust again.
     *
     * @return string
     */
    public static function sftp_trust_reset_notice(): string {
        return __( 'The SFTP host key for this destination was reset because the host or port changed and will be learned again on the next connection.', 'mainwp' );
    }

    /**
     * Run a provider connection test against candidate settings.
     *
     * @param array $candidate Merged candidate fields.
     *
     * @return array {ok, error}.
     */
    private static function run_connection_test( array $candidate ) {
        $settings         = $candidate['settings'];
        $required_secrets = array(
            's3'      => array( 'secret_key' ),
            'gcs'     => array( 'secret_key' ),
            'dropbox' => array( 'app_secret', 'refresh_token' ),
            'pcloud'  => array( 'access_token' ),
            'sftp'    => '' === trim( (string) ( $settings['private_key'] ?? '' ) ) ? array( 'password' ) : array(),
            'local'   => array(),
        );
        if ( ! isset( $required_secrets[ $candidate['provider'] ] ) ) {
            return array(
                'ok'    => false,
                'error' => esc_html__( 'Unknown storage provider.', 'mainwp' ),
            );
        }
        foreach ( $required_secrets[ $candidate['provider'] ] as $field ) {
            if ( ! isset( $settings[ $field ] ) || '' === (string) $settings[ $field ] ) {
                // pCloud tokens come only from Connect pCloud; the form has no field that could supply one.
                if ( 'pcloud' === $candidate['provider'] ) {
                    $is_new = empty( $candidate['expected'] ) || 'pcloud' !== $candidate['expected']->provider;
                    return array(
                        'ok'    => false,
                        'error' => $is_new ? esc_html__( 'Click Connect pCloud and paste the authorization code to finish adding this destination.', 'mainwp' ) : esc_html__( 'Click Connect pCloud and paste the authorization code to reconnect this destination.', 'mainwp' ),
                    );
                }
                return array(
                    'ok'    => false,
                    'error' => esc_html__( 'Re-enter the required credentials for this storage destination.', 'mainwp' ),
                );
            }
        }
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
            return array( 'tasks' => array_values( array_map( array( static::class, 'task_preview_label' ), $tasks ) ) );
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
     * Name a task for the delete preview.
     *
     * Every Back Up Now task is stored as 'On-demand backup', so a hidden
     * task is named by the site it backs up. The stored name stays when the
     * site cannot be read.
     *
     * @param object $task Task row.
     *
     * @return string Task label.
     */
    private static function task_preview_label( $task ) {
        $site_id = empty( $task->template ) ? 0 : (int) ( $task->manual_site_id ?? 0 );
        $site    = $site_id > 0 ? MainWP_DB::instance()->get_website_by_id( $site_id ) : null;
        if ( empty( $site ) || '' === (string) $site->name ) {
            return (string) $task->name;
        }
        /* translators: %s: child site name. */
        return sprintf( __( 'Back Up Now for %s', 'mainwp' ), $site->name );
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
        $db    = MainWP_DB_Backup::instance();
        $tasks = array_merge( (array) $db->get_backup_tasks(), (array) $db->get_hidden_backup_tasks() );
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
            MainWP_Backup_Upload_Job_Store::instance()->fail_permanent( $job_id, __( 'Storage destination was deleted.', 'mainwp' ) );
        }
    }
}
