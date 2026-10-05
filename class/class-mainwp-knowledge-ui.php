<?php
/**
 * Knowledge panel: AJAX handlers and the reusable Context / Skills / Memory UI.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Knowledge_UI
 *
 * The panel is scope-agnostic: a client page and a site page mount the same
 * markup with a different scope, and the four handlers below serve both.
 */
class MainWP_Knowledge_UI { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - multi methods.

    /**
     * Singleton.
     *
     * @var MainWP_Knowledge_UI|null
     */
    private static $instance = null;

    /**
     * AJAX action names, in registration order.
     *
     * @var string[]
     */
    const ACTIONS = array(
        'mainwp_knowledge_list',
        'mainwp_knowledge_get',
        'mainwp_knowledge_save',
        'mainwp_knowledge_delete',
        'mainwp_knowledge_review',
    );

    /**
     * Instance accessor.
     *
     * @return MainWP_Knowledge_UI
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Registers the handlers. Must run before admin_init priority 20 so the
     * actions get a nonce in the security_nonces list.
     *
     * @return void
     */
    public static function init() {
        $handler = MainWP_Post_Handler::instance();
        $self    = static::instance();
        $handler->add_action( 'mainwp_knowledge_list', array( $self, 'ajax_list' ) );
        $handler->add_action( 'mainwp_knowledge_get', array( $self, 'ajax_get' ) );
        $handler->add_action( 'mainwp_knowledge_save', array( $self, 'ajax_save' ) );
        $handler->add_action( 'mainwp_knowledge_delete', array( $self, 'ajax_delete' ) );
        $handler->add_action( 'mainwp_knowledge_review', array( $self, 'ajax_review' ) );
    }

    /**
     * Record type labels, in section order.
     *
     * @return array<string, string>
     */
    public static function type_labels() {
        return array(
            'context' => __( 'Context', 'mainwp' ),
            'skill'   => __( 'Skill', 'mainwp' ),
            'memory'  => __( 'Memory', 'mainwp' ),
        );
    }

    /**
     * Strings the panel script needs.
     *
     * @return array<string, string>
     */
    public static function js_strings() {
        return array(
            'empty'          => __( 'Nothing recorded yet.', 'mainwp' ),
            'noAccess'       => __( 'You do not have permission to access this knowledge.', 'mainwp' ),
            'edit'           => __( 'Edit', 'mainwp' ),
            'view'           => __( 'View', 'mainwp' ),
            'remove'         => __( 'Delete', 'mainwp' ),
            'unverified'     => __( 'Unverified', 'mainwp' ),
            'required'       => __( 'Required', 'mainwp' ),
            'unverifiedHint' => __( 'Saved by an AI agent and not yet reviewed by a person. AI agents treat it as information only until you review and save it.', 'mainwp' ),
            'confirmDelete'  => __( 'Delete this knowledge record? Connected AI agents lose it immediately.', 'mainwp' ),
            'conflict'       => __( 'Someone else saved this record while you were editing it.', 'mainwp' ),
            'reload'         => __( 'Reload record', 'mainwp' ),
            'reloaded'       => __( 'The latest version is loaded. Your unsaved text was replaced.', 'mainwp' ),
            'failed'         => __( 'The request failed. Reload the page and try again.', 'mainwp' ),
            /* translators: %s: client name. */
            'inheritedFrom'  => __( 'Inherited from client: %s', 'mainwp' ),
            'inheritedEmpty' => __( 'This client has no knowledge records.', 'mainwp' ),
            'viewOnSite'     => __( 'View on the site', 'mainwp' ),
            'noRecords'      => __( 'No records', 'mainwp' ),
        );
    }

    /**
     * Lists the records of one scope the current user can read.
     *
     * @return void
     */
    public function ajax_list() {
        $this->guard( 'mainwp_knowledge_list' );

        $scope     = $this->read_scope();
        $db        = MainWP_DB_Knowledge::instance();
        $read_only = $this->read_int( 'read_only' );
        if ( ! in_array( $read_only, array( 0, 1 ), true ) ) {
            $this->send_invalid( 'read_only' );
        }

        // List rows carry only a short body preview; the editor loads the full body per record.
        $records = $db->get_scope_records(
            $scope->scope_type,
            $scope->scope_id,
            array(
                'with_body'         => false,
                'with_body_preview' => true,
            )
        );
        if ( is_wp_error( $records ) ) {
            $this->send_error( $records, 'mainwp_knowledge_db_error' === $records->get_error_code() ? null : __( 'You cannot view knowledge for this item.', 'mainwp' ) );
        }

        // The whole panel fails on a client read error: showing the site block alone would read as a client with no knowledge.
        $inherited = 'site' === $scope->scope_type && ! $read_only ? $this->inherited_client( $scope->scope_id ) : null;
        if ( is_wp_error( $inherited ) ) {
            $this->send_error( $inherited );
        }

        wp_send_json_success(
            array(
                'records'   => array_map( array( $this, 'record_summary' ), $records ),
                'can_write' => ! $read_only && true === $db->can_write( $scope ),
                'inherited' => $inherited,
            )
        );
    }

    /**
     * Review rows and totals share the same SQL visibility rules as ability lists.
     *
     * @return void
     */
    public function ajax_review() {
        $this->guard( 'mainwp_knowledge_review' );
        $options = array(
            'status' => array( 'unverified', 'verified', 'all' ),
            'type'   => array( '', 'context', 'skill', 'memory' ),
            'level'  => array_merge( array( '' ), MainWP_DB_Knowledge::SCOPE_TYPES ),
        );
        $filters = array();
        foreach ( $options as $key => $allowed ) {
            $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $allowed[0]; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verifies the nonce; the fixed allowlist below validates each value.
            if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
                $this->send_invalid( $key );
            }
            $filters[ $key ] = $value;
        }
        $draw   = $this->read_int( 'draw' );
        $offset = $this->read_int( 'start' );
        $limit  = array_key_exists( 'length', $_POST ) ? $this->read_int( 'length' ) : 25; // phpcs:ignore WordPress.Security.NonceVerification -- guard() verifies the nonce.
        foreach ( array(
            'draw'   => $draw,
            'start'  => $offset,
            'length' => $limit,
        ) as $key => $value ) {
            if ( $value < 0 || ( 'length' === $key && ( 0 === $value || $value > 100 ) ) ) {
                $this->send_invalid( $key );
            }
        }
        $args = array(
            'type'       => $filters['type'],
            'scope_type' => $filters['level'],
            'with_body'  => false,
            'limit'      => $limit,
            'offset'     => $offset,
        );
        if ( 'all' !== $filters['status'] ) {
            $args['verified'] = 'verified' === $filters['status'];
        }
        $db   = MainWP_DB_Knowledge::instance();
        $page = $db->get_records_page( $args );
        if ( is_wp_error( $page ) ) {
            $this->send_error( $page );
        }
        $rows = array();
        foreach ( $page['items'] as $record ) {
            $row = $this->review_row( $record );
            if ( is_wp_error( $row ) ) {
                $this->send_error( $row );
            }
            $rows[] = $row;
        }
        wp_send_json(
            array(
                'draw'            => $draw,
                'recordsTotal'    => $page['total'],
                'recordsFiltered' => $page['total'],
                'data'            => $rows,
            )
        );
    }

    /**
     * Escaped cells for the server-side table; bodies stay in the modal endpoint.
     *
     * @param object $record Visible record.
     *
     * @return array|\WP_Error Cells, or a scope lookup error.
     */
    private function review_row( $record ) {
        $level = $this->record_level_label( $record );
        if ( is_wp_error( $level ) ) {
            return $level;
        }
        $summary = $this->record_summary( $record );
        $editor  = $summary['updated_by_name'];
        $title   = esc_html( $record->title );
        if ( $record->required ) {
            $title .= ' <span class="ui mini label">' . esc_html__( 'Required', 'mainwp' ) . '</span>';
        }
        if ( 'skill' === $record->type ) {
            $title .= '<div class="ui small text">' . esc_html( $record->description ) . '</div>';
        }
        return array(
            'id'      => (int) $record->id,
            'level'   => '<span class="ui mini label">' . esc_html( $level ) . '</span>',
            'type'    => esc_html( $summary['type_label'] ),
            'title'   => $title,
            'status'  => '<span class="ui mini ' . ( $record->verified ? 'green' : 'yellow' ) . ' label">' . ( $record->verified ? esc_html__( 'Verified', 'mainwp' ) : esc_html__( 'Unverified', 'mainwp' ) ) . '</span>',
            'editor'  => esc_html( $editor ),
            'updated' => esc_html( $summary['updated_ago'] ),
            'actions' => '<button type="button" class="ui mini basic button mainwp-knowledge-open" data-id="' . esc_attr( $record->id ) . '">' . esc_html__( 'Open', 'mainwp' ) . '</button>',
        );
    }

    /**
     * The review list and modal need the same scope name and lookup error handling.
     *
     * @param object $record Visible record.
     *
     * @return string|\WP_Error Level label, or a scope lookup error.
     */
    private function record_level_label( $record ) {
        $db    = MainWP_DB_Knowledge::instance();
        $level = __( 'Agency', 'mainwp' );
        if ( 'agency' !== $record->scope_type ) {
            $scope = $db->guard_read(
                static function () use ( $record ) {
                    return 'site' === $record->scope_type
                        ? MainWP_DB::instance()->get_website_by_id( $record->scope_id )
                        : MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $record->scope_id );
                }
            );
            if ( is_wp_error( $scope ) ) {
                return $scope;
            }
            /* translators: %s: site or client name. */
            $level = sprintf( 'site' === $record->scope_type ? __( 'Site: %s', 'mainwp' ) : __( 'Client: %s', 'mainwp' ), $scope->name ?? '' );
        }
        return $level;
    }

    /**
     * Client records a site inherits, for the read-only block of the site panel.
     *
     * @param int $site_id Site the caller already passed can_read() for.
     *
     * @return array|\WP_Error|null Null when the site has no client or the caller cannot read that client; WP_Error when a database read fails.
     */
    private function inherited_client( $site_id ) {
        $db   = MainWP_DB_Knowledge::instance();
        $site = $db->guard_read(
            static function () use ( $site_id ) {
                return MainWP_DB::instance()->get_website_by_id( $site_id );
            }
        );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        if ( empty( $site->client_id ) ) {
            return null;
        }

        $client_id = (int) $site->client_id;
        // Site access alone does not grant access to the client's knowledge.
        $records = $db->get_scope_records(
            'client',
            $client_id,
            array(
                'with_body'         => false,
                'with_body_preview' => true,
            )
        );
        if ( is_wp_error( $records ) ) {
            return 'mainwp_knowledge_db_error' === $records->get_error_code() ? $records : null;
        }

        // The block needs a client name; an empty record list does not prove the client exists.
        $client = $db->guard_read(
            static function () use ( $client_id ) {
                return MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
            }
        );
        if ( is_wp_error( $client ) ) {
            return $client;
        }
        if ( ! is_object( $client ) || ! isset( $client->name ) ) {
            return null;
        }

        return array(
            'client_id'   => $client_id,
            'client_name' => (string) $client->name,
            'client_url'  => admin_url( 'admin.php?page=ClientAddNew&client_id=' . $client_id ),
            'records'     => array_map( array( $this, 'record_summary' ), $records ),
        );
    }

    /**
     * Returns one record with its body, for the modal. The read right is
     * enough: a user who cannot write opens the record read-only.
     *
     * @return void
     */
    public function ajax_get() {
        $this->guard( 'mainwp_knowledge_get' );

        $record = MainWP_DB_Knowledge::instance()->get_record( $this->read_id() );
        if ( is_wp_error( $record ) ) {
            $this->send_error( $record );
        }

        $level = $this->record_level_label( $record );
        if ( is_wp_error( $level ) ) {
            $this->send_error( $level );
        }
        $out                = $this->record_full( $record );
        $out['level_label'] = $level;

        wp_send_json_success(
            array(
                'record'    => $out,
                'can_write' => true === MainWP_DB_Knowledge::instance()->can_write( $record ),
            )
        );
    }

    /**
     * Creates or updates a record. An update carries the id and the revision
     * the editor loaded; a stale revision answers the conflict code with the
     * stored revision so the UI can offer a reload.
     *
     * @return void
     */
    public function ajax_save() {
        $this->guard( 'mainwp_knowledge_save' );

        // A nonce can belong to an API-key principal; only a matching browser session can vouch for a person's review.
        $user_id = get_current_user_id();
        if ( 0 === $user_id || wp_validate_auth_cookie( '', 'logged_in' ) !== $user_id ) {
            $this->send_error(
                new \WP_Error(
                    'mainwp_knowledge_browser_session_required',
                    __( 'Your sign-in could not be confirmed, so the record was not saved. Sign in again and retry.', 'mainwp' ),
                    array( 'status' => 403 )
                )
            );
        }

        $scope = $this->read_scope();
        $input = array(
            'scope_type' => $scope->scope_type,
            'scope_id'   => $scope->scope_id,
            'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            'title'      => isset( $_POST['title'] ) && is_string( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; validated in save().
            // The body is plain text kept verbatim (block 1 contract); save() enforces UTF-8 and length, and every renderer escapes it on output.
            'body'       => isset( $_POST['body'] ) && is_string( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; validated in save().
        );

        if ( 'agency' === $scope->scope_type && ! array_key_exists( 'scope_id', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            unset( $input['scope_id'] );
        }
        if ( array_key_exists( 'required', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            $required = $this->read_int( 'required' );
            if ( ! in_array( $required, array( 0, 1 ), true ) ) {
                $this->send_invalid( 'required' );
            }
            $input['required'] = (bool) $required;
        }

        if ( array_key_exists( 'description', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            if ( ! is_string( $_POST['description'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
                $this->send_invalid( 'description' );
            }
            $input['description'] = wp_unslash( $_POST['description'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; save() validates the plain text and every renderer escapes it.
        }

        $expected_revision = null;
        $id                = $this->read_id();
        if ( $id > 0 ) {
            $input['id']       = $id;
            $expected_revision = $this->read_int( 'revision' );
            if ( -1 === $expected_revision ) {
                $this->send_invalid( 'revision' );
            }
            if ( $expected_revision < 1 ) {
                $expected_revision = null;
            }
        }

        $record = MainWP_DB_Knowledge::instance()->save( $input, MainWP_DB_Knowledge::ORIGIN_UI, $expected_revision );
        if ( is_wp_error( $record ) ) {
            $this->send_error( $record );
        }

        wp_send_json_success( array( 'record' => $this->record_full( $record ) ) );
    }

    /**
     * Deletes one record. The revision the list showed is required, so a
     * stale list answers the conflict code instead of removing a newer edit.
     *
     * @return void
     */
    public function ajax_delete() {
        $this->guard( 'mainwp_knowledge_delete' );

        $id       = $this->read_id();
        $revision = $this->read_int( 'revision' );
        if ( $revision < 1 ) {
            $this->send_invalid( 'revision' );
        }
        // A matching browser session is the only trusted UI origin; the DB rechecks Required against its own row.
        $user_id = get_current_user_id();
        $origin  = $user_id > 0 && wp_validate_auth_cookie( '', 'logged_in' ) === $user_id ? MainWP_DB_Knowledge::ORIGIN_UI : MainWP_DB_Knowledge::ORIGIN_ABILITY;
        $result  = MainWP_DB_Knowledge::instance()->delete( $id, $revision, $origin );
        if ( is_wp_error( $result ) ) {
            $this->send_error( $result );
        }

        wp_send_json_success( array( 'id' => $id ) );
    }

    /**
     * Nonce first, then the base permission. Both failures answer JSON, never
     * a bare die(): the base check before any record lookup is what keeps a
     * missing id and a forbidden id indistinguishable to a non-admin.
     *
     * @param string $action AJAX action name, also the nonce action.
     *
     * @return void
     */
    private function guard( $action ) {
        if ( ! MainWP_Post_Handler::instance()->check_security( $action, 'security', false ) ) {
            $this->send_error(
                new \WP_Error(
                    'mainwp_knowledge_insecure',
                    __( 'This request could not be verified. Reload the page and try again.', 'mainwp' ),
                    array( 'status' => 403 )
                )
            );
        }

        $permission = MainWP_Abilities_Util::check_rest_api_permission();
        if ( is_wp_error( $permission ) ) {
            $this->send_error( $permission );
        }
    }

    /**
     * Scope stub from the request.
     *
     * @return object Stub with scope_type and scope_id.
     */
    private function read_scope() {
        $scope_id = $this->read_int( 'scope_id' );
        if ( -1 === $scope_id ) {
            $this->send_invalid( 'scope_id' );
        }

        return (object) array(
            'scope_type' => isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            'scope_id'   => $scope_id,
        );
    }

    /**
     * Record id from the request.
     *
     * @return int
     */
    private function read_id() {
        $id = $this->read_int( 'id' );
        if ( -1 === $id ) {
            $this->send_invalid( 'id' );
        }
        return $id;
    }

    /**
     * Read an unsigned integer without coercing malformed request values.
     *
     * @param string $key Request field.
     *
     * @return int Zero when absent or empty, -1 when invalid.
     */
    private function read_int( string $key ) {
        if ( ! array_key_exists( $key, $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            return 0;
        }
        $value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; shape validated below.
        if ( '' === $value ) {
            return 0;
        }
        if ( ( is_string( $value ) || is_int( $value ) ) && preg_match( '/^\d{1,19}$/D', (string) $value ) ) {
            return (int) $value;
        }
        return -1;
    }

    /**
     * Sends a WP_Error as a JSON error and ends the request.
     *
     * @param \WP_Error   $error   Error.
     * @param string|null $message Replacement message for the UI.
     *
     * @return void
     */
    private function send_error( \WP_Error $error, $message = null ) {
        $data = (array) $error->get_error_data();
        $out  = array(
            'code'    => $error->get_error_code(),
            'message' => null === $message ? $error->get_error_message() : $message,
            'status'  => isset( $data['status'] ) ? (int) $data['status'] : 400,
        );
        if ( isset( $data['revision'] ) ) {
            $out['revision'] = (int) $data['revision'];
        }
        wp_send_json_error( $out );
    }

    /**
     * Answers 400 for a malformed request field, in the shape save() uses for its own field errors.
     *
     * @param string $field Request field name.
     *
     * @return void
     */
    private function send_invalid( $field ) {
        $this->send_error(
            new \WP_Error(
                'mainwp_knowledge_invalid',
                /* translators: %s: field name */
                sprintf( __( 'Invalid knowledge record field: %s.', 'mainwp' ), $field ),
                array( 'status' => 400 )
            )
        );
    }

    /**
     * List row fields: everything except the body.
     *
     * @param object $record Record.
     *
     * @return array
     */
    private function record_summary( $record ) {
        $editor      = get_userdata( (int) $record->updated_by );
        $editor_name = $editor ? ( '' !== $editor->display_name ? $editor->display_name : $editor->user_login ) : __( 'Unknown user', 'mainwp' );
        if ( $editor && MainWP_DB_Knowledge::ORIGIN_ABILITY === $record->source ) {
            /* translators: %s: Dashboard user whose credentials the agent used. */
            $editor_name = sprintf( __( 'AI agent via %s', 'mainwp' ), $editor_name );
        }
        $labels      = static::type_labels();
        $preview_raw = isset( $record->body_preview )
            ? (string) $record->body_preview
            : (string) ( $record->body ?? '' );
        if ( 'skill' === $record->type ) {
            $preview_raw = (string) ( $record->description ?? '' );
        }
        $preview = mb_substr( $preview_raw, 0, MainWP_DB_Knowledge::BODY_PREVIEW_LENGTH );
        if ( mb_strlen( $preview_raw ) > MainWP_DB_Knowledge::BODY_PREVIEW_LENGTH ) {
            $preview .= '…';
        }

        return array(
            'id'              => (int) $record->id,
            'scope_type'      => $record->scope_type,
            'scope_id'        => (int) $record->scope_id,
            'type'            => $record->type,
            'type_label'      => isset( $labels[ $record->type ] ) ? $labels[ $record->type ] : $record->type,
            'title'           => $record->title,
            'description'     => $record->description ?? '',
            'body_preview'    => $preview,
            'revision'        => (int) $record->revision,
            'source'          => $record->source,
            'verified'        => (int) $record->verified,
            'required'        => (int) $record->required,
            'updated_at'      => (int) $record->updated_at,
            /* translators: %s: human readable time difference. */
            'updated_ago'     => sprintf( __( '%s ago', 'mainwp' ), human_time_diff( (int) $record->updated_at ) ),
            'updated_by'      => (int) $record->updated_by,
            'updated_by_name' => $editor_name,
        );
    }

    /**
     * Full record for the editor.
     *
     * @param object $record Record.
     *
     * @return array
     */
    private function record_full( $record ) {
        $out         = $this->record_summary( $record );
        $out['body'] = $record->body;
        return $out;
    }

    /**
     * Renders the panel for one scope. The list itself loads over AJAX so the
     * same permission path serves the first paint and every refresh.
     *
     * @param string $scope_type   Agency, site or client.
     * @param int    $scope_id     Site or client id.
     * @param bool   $as_accordion Whether to use the edit-page accordion layout.
     * @param bool   $show_header  Whether the panel supplies its own heading.
     *
     * @return void
     */
    public static function render_panel( $scope_type, $scope_id, $as_accordion = false, $show_header = true ) {
        $scope_id = (int) $scope_id;
        if ( ( 'agency' === $scope_type ? 0 !== $scope_id : $scope_id < 1 ) || ! in_array( $scope_type, MainWP_DB_Knowledge::SCOPE_TYPES, true ) ) {
            return;
        }

        // A widget already supplies its heading and does not need a second accordion shell.
        $as_accordion      = $as_accordion && $show_header;
        $can_write         = true === MainWP_DB_Knowledge::instance()->can_write(
            (object) array(
                'scope_type' => $scope_type,
                'scope_id'   => $scope_id,
            )
        );
        $client_link_label = __( 'Edit on the client profile', 'mainwp' );
        if ( 'site' === $scope_type ) {
            $db   = MainWP_DB_Knowledge::instance();
            $site = $db->guard_read(
                static function () use ( $scope_id ) {
                    return MainWP_DB::instance()->get_website_by_id( $scope_id );
                }
            );
            if ( is_wp_error( $site ) || empty( $site->client_id ) || true !== $db->can_write(
                (object) array(
                    'scope_type' => 'client',
                    'scope_id'   => (int) $site->client_id,
                )
            ) ) {
                $client_link_label = __( 'View on the client profile', 'mainwp' );
            }
        }

        $sections = array(
            'context' => array(
                'title' => __( 'Context', 'mainwp' ),
                'add'   => __( 'Add Context', 'mainwp' ),
                'help'  => 'client' === $scope_type
                    ? __( 'Facts an AI agent should know before it acts for this client: hosting, agreements, preferences, constraints.', 'mainwp' )
                    : __( 'Facts an AI agent should know before it acts on this site: hosting, stack, constraints, known quirks.', 'mainwp' ),
            ),
            'skill'   => array(
                'title' => __( 'Skills', 'mainwp' ),
                'add'   => __( 'Add Skill', 'mainwp' ),
                'help'  => __( 'Procedures and rules to follow when working here: how to deploy, what to check first, what never to touch.', 'mainwp' ),
            ),
            'memory'  => array(
                'title' => __( 'Memory', 'mainwp' ),
                'add'   => __( 'Add Memory', 'mainwp' ),
                'help'  => __( 'Outcomes and observations recorded over time. Read them as history, not as the current state.', 'mainwp' ),
            ),
        );
        if ( 'agency' === $scope_type ) {
            $sections['context']['help'] = __( 'Facts an AI agent should know before it acts on any site: your agency\'s standards, tools, contacts and policies.', 'mainwp' );
            $sections['skill']['help']   = __( 'Procedures and rules to follow on any site: how to deploy, what to check first, what never to touch.', 'mainwp' );
        }
        ?>
        <?php if ( $as_accordion ) : ?>
        <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-knowledge-accordion">
            <h2 class="ui dividing header active title">
                <i class="right dropdown icon"></i>
                <?php esc_html_e( 'Knowledge', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Add context, skills, and memories that connected AI agents can use when working for this client.', 'mainwp' ); ?></div>
            </h2>
            <div class="content active">
        <?php endif; ?>
        <div class="<?php echo $as_accordion ? 'ui basic padded segment dimmable mainwp-knowledge-panel mainwp-knowledge-accordion-content' : ( 'agency' === $scope_type ? 'ui basic segment dimmable mainwp-knowledge-panel' : ( $show_header ? 'ui padded segment dimmable mainwp-knowledge-panel' : 'dimmable mainwp-knowledge-panel' ) ); ?>" data-scope-type="<?php echo esc_attr( $scope_type ); ?>" data-scope-id="<?php echo esc_attr( $scope_id ); ?>" data-can-write="<?php echo esc_attr( $can_write ? '1' : '0' ); ?>">
            <div class="ui dimmer mainwp-knowledge-loader" aria-hidden="true">
                <div class="ui loader" role="status" aria-label="<?php esc_attr_e( 'Loading...', 'mainwp' ); ?>"></div>
            </div>
            <?php if ( 'agency' === $scope_type ) : ?>
                <?php if ( ! $can_write ) : ?>
            <div class="ui message"><?php esc_html_e( 'Only users with client management rights and access to every site can change agency records.', 'mainwp' ); ?></div>
            <?php endif; ?>
            <?php elseif ( $show_header ) : ?>
            <div class="ui info message">
                <p><?php esc_html_e( 'Records saved here reach any AI agent connected to this Dashboard through MCP. There is no export in this release.', 'mainwp' ); ?></p>
                <p>
                <?php if ( 'client' === $scope_type ) : ?>
                    <?php esc_html_e( 'Client records apply to every site assigned to this client. Site records belong to the site and stay with it if the site is moved to another client.', 'mainwp' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Site records stay with this site if it is moved to another client. Client records are managed on the client profile.', 'mainwp' ); ?>
                <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            <div class="ui hidden message mainwp-knowledge-message"></div>
            <?php foreach ( $sections as $type => $section ) : ?>
            <div class="mainwp-knowledge-section" data-type="<?php echo esc_attr( $type ); ?>">
                <div class="ui small header">
                    <?php echo esc_html( $section['title'] ); ?>
                    <div class="sub header"><?php echo esc_html( $section['help'] ); ?></div>
                </div>
                <table class="ui table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Title', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-editor-cell collapsing"><?php esc_html_e( 'Last edited by', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-actions-cell no-sort collapsing"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <?php if ( $can_write ) : ?>
                <button type="button" class="ui mini basic green button mainwp-knowledge-add" data-type="<?php echo esc_attr( $type ); ?>" style="display:none">
                    <?php
                    echo esc_html( $section['add'] );
                    ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if ( 'site' === $scope_type ) : ?>
            <div class="mainwp-knowledge-inherited" style="display:none">
                <div class="ui small header">
                    <span class="mainwp-knowledge-inherited-title"></span>
                    <div class="sub header">
                        <?php esc_html_e( 'Client records apply to every site assigned to the client, and AI agents receive them together with the records of this site. They are read-only here.', 'mainwp' ); ?>
                        <a href="#" class="mainwp-knowledge-inherited-link"><?php echo esc_html( $client_link_label ); ?></a>
                    </div>
                </div>
                <table class="ui table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Title', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-type-cell"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-editor-cell collapsing"><?php esc_html_e( 'Last edited by', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-actions-cell no-sort collapsing"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php if ( $as_accordion ) : ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // WordPress deduplicates this callback when several panels share the page.
        add_action( 'admin_footer', array( static::class, 'render_modal' ) );
    }

    /**
     * The add / edit / view modal, printed once per page.
     *
     * @return void
     */
    public static function render_modal() {
        $strings          = static::js_strings();
        $can_write_agency = true === MainWP_DB_Knowledge::instance()->can_write(
            (object) array(
                'scope_type' => 'agency',
                'scope_id'   => 0,
            )
        );
        ?>
        <div class="ui small modal" id="mainwp-knowledge-modal">
            <i class="close icon"></i>
            <div class="header" data-add="<?php esc_attr_e( 'Add knowledge record', 'mainwp' ); ?>" data-edit="<?php esc_attr_e( 'Edit knowledge record', 'mainwp' ); ?>" data-view="<?php esc_attr_e( 'View knowledge record', 'mainwp' ); ?>">
                <span id="mainwp-knowledge-modal-title"><?php esc_html_e( 'Add knowledge record', 'mainwp' ); ?></span>
                <span class="ui mini label" id="mainwp-knowledge-modal-level" style="display:none"></span>
                <span class="ui mini yellow label" id="mainwp-knowledge-modal-unverified" style="display:none" data-tooltip="<?php echo esc_attr( $strings['unverifiedHint'] ); ?>" data-inverted=""><?php esc_html_e( 'Unverified', 'mainwp' ); ?></span>
            </div>
            <div class="content">
                <div class="ui hidden message" id="mainwp-knowledge-modal-message"></div>
                <div class="ui form" id="mainwp-knowledge-form">
                    <div class="field">
                        <label for="mainwp-knowledge-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></label>
                        <select id="mainwp-knowledge-type" class="ui selection dropdown">
                            <?php foreach ( static::type_labels() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php // The view mode shows the type as text: a Fomantic dropdown has no read-only state the keyboard respects. ?>
                    <div class="field" style="display:none">
                        <label for="mainwp-knowledge-type-view"><?php esc_html_e( 'Type', 'mainwp' ); ?></label>
                        <input type="text" id="mainwp-knowledge-type-view" readonly>
                    </div>
                    <div class="field">
                        <label for="mainwp-knowledge-title"><?php esc_html_e( 'Title', 'mainwp' ); ?></label>
                        <input type="text" id="mainwp-knowledge-title" maxlength="<?php echo (int) MainWP_DB_Knowledge::TITLE_MAX_LENGTH; ?>" placeholder="<?php esc_attr_e( 'Short name for this record', 'mainwp' ); ?>">
                    </div>
                    <div class="field" style="display:none">
                        <label for="mainwp-knowledge-description"><?php esc_html_e( 'Description', 'mainwp' ); ?></label>
                        <textarea id="mainwp-knowledge-description" rows="2" maxlength="<?php echo (int) MainWP_DB_Knowledge::DESCRIPTION_MAX_LENGTH; ?>" aria-describedby="mainwp-knowledge-description-help" placeholder="<?php esc_attr_e( 'When to use this skill, for example: updating WooCommerce on this site.', 'mainwp' ); ?>"></textarea>
                        <p id="mainwp-knowledge-description-help"><?php esc_html_e( 'AI agents read this to decide whether to open the skill. Say when it applies.', 'mainwp' ); ?></p>
                    </div>
                    <div class="field">
                        <label for="mainwp-knowledge-body"><?php esc_html_e( 'Body', 'mainwp' ); ?></label>
                        <textarea id="mainwp-knowledge-body" rows="10" maxlength="<?php echo (int) MainWP_DB_Knowledge::BODY_MAX_LENGTH; ?>" placeholder="<?php esc_attr_e( 'Plain text. Write it the way you would brief a new team member.', 'mainwp' ); ?>"></textarea>
                    </div>
                    <?php if ( $can_write_agency ) : ?>
                    <div class="field" id="mainwp-knowledge-required-field" style="display:none">
                        <div class="ui checkbox">
                            <input type="checkbox" id="mainwp-knowledge-required" aria-describedby="mainwp-knowledge-required-help">
                            <label for="mainwp-knowledge-required"><?php esc_html_e( 'Required', 'mainwp' ); ?></label>
                        </div>
                        <p id="mainwp-knowledge-required-help"><?php esc_html_e( 'Records at a more specific level cannot override a required record. Only a Dashboard user can edit or delete it.', 'mainwp' ); ?></p>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" id="mainwp-knowledge-id" value="0">
                    <input type="hidden" id="mainwp-knowledge-revision" value="0">
                </div>
            </div>
            <div class="actions">
                <button type="button" class="ui cancel button" data-cancel="<?php esc_attr_e( 'Cancel', 'mainwp' ); ?>" data-close="<?php esc_attr_e( 'Close', 'mainwp' ); ?>"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                <button type="button" class="ui red button" id="mainwp-knowledge-remove" style="display:none"><?php esc_html_e( 'Delete', 'mainwp' ); ?></button>
                <button type="button" class="ui green button" id="mainwp-knowledge-save"><?php esc_html_e( 'Save', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
    }
}
