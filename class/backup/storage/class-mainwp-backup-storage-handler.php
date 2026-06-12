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

        return array(
            'id'         => $id,
            'provider'   => $provider,
            'name'       => $name,
            'is_default' => empty( $input['is_default'] ) ? 0 : 1,
            'settings'   => $settings,
        );
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
            MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'destinations' => wp_json_encode( $ids ) ) );
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
