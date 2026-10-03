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
    }

    /**
     * Record type labels, in section order.
     *
     * @return array<string, string>
     */
    public static function type_labels() {
        return array(
            'context' => __( 'Context', 'mainwp' ),
            'skill'   => __( 'Skills', 'mainwp' ),
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
            'unverifiedHint' => __( 'Saved by an AI client and not yet reviewed by a person. Edit and save the entry to mark it as verified.', 'mainwp' ),
            'confirmDelete'  => __( 'Delete this knowledge record? Connected AI clients lose it immediately.', 'mainwp' ),
            'conflict'       => __( 'Someone else saved this record while you were editing it.', 'mainwp' ),
            'reload'         => __( 'Reload record', 'mainwp' ),
            'reloaded'       => __( 'The latest version is loaded. Your unsaved text was replaced.', 'mainwp' ),
            'failed'         => __( 'The request failed. Reload the page and try again.', 'mainwp' ),
            /* translators: %s: client name. */
            'inheritedFrom'  => __( 'Inherited from client: %s', 'mainwp' ),
            'inheritedEmpty' => __( 'This client has no knowledge records.', 'mainwp' ),
        );
    }

    /**
     * Lists the records of one scope the current user can read.
     *
     * @return void
     */
    public function ajax_list() {
        $this->guard( 'mainwp_knowledge_list' );

        $scope = $this->read_scope();
        $db    = MainWP_DB_Knowledge::instance();

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
        $inherited = 'site' === $scope->scope_type ? $this->inherited_client( $scope->scope_id ) : null;
        if ( is_wp_error( $inherited ) ) {
            $this->send_error( $inherited );
        }

        wp_send_json_success(
            array(
                'records'   => array_map( array( $this, 'record_summary' ), $records ),
                'can_write' => true === $db->can_write( $scope ),
                'inherited' => $inherited,
            )
        );
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

        wp_send_json_success( array( 'record' => $this->record_full( $record ) ) );
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

        $scope = $this->read_scope();
        $input = array(
            'scope_type' => $scope->scope_type,
            'scope_id'   => $scope->scope_id,
            'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified the nonce.
            'title'      => isset( $_POST['title'] ) && is_string( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; validated in save().
            // The body is plain text kept verbatim (block 1 contract); save() enforces UTF-8 and length, and every renderer escapes it on output.
            'body'       => isset( $_POST['body'] ) && is_string( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; validated in save().
        );

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
        $result = MainWP_DB_Knowledge::instance()->delete( $id, $revision );
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
        $labels      = static::type_labels();
        $preview_raw = isset( $record->body_preview )
            ? (string) $record->body_preview
            : (string) ( $record->body ?? '' );
        $preview     = mb_substr( $preview_raw, 0, MainWP_DB_Knowledge::BODY_PREVIEW_LENGTH );
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
            'body_preview'    => $preview,
            'revision'        => (int) $record->revision,
            'source'          => $record->source,
            'verified'        => (int) $record->verified,
            'updated_at'      => (int) $record->updated_at,
            /* translators: %s: human readable time difference. */
            'updated_ago'     => sprintf( __( '%s ago', 'mainwp' ), human_time_diff( (int) $record->updated_at ) ),
            'updated_by'      => (int) $record->updated_by,
            'updated_by_name' => $editor ? ( '' !== $editor->display_name ? $editor->display_name : $editor->user_login ) : __( 'Unknown user', 'mainwp' ),
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
     * @param string $scope_type   'site' or 'client'.
     * @param int    $scope_id     Site or client id.
     * @param bool   $as_accordion Whether to use the edit-page accordion layout.
     *
     * @return void
     */
    public static function render_panel( $scope_type, $scope_id, $as_accordion = false ) {
        $scope_id = (int) $scope_id;
        if ( $scope_id < 1 || ! in_array( $scope_type, MainWP_DB_Knowledge::SCOPE_TYPES, true ) ) {
            return;
        }

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
                'help'  => 'client' === $scope_type
                    ? __( 'Facts an AI client should know before it acts for this client: hosting, agreements, preferences, constraints.', 'mainwp' )
                    : __( 'Facts an AI client should know before it acts on this site: hosting, stack, constraints, known quirks.', 'mainwp' ),
            ),
            'skill'   => array(
                'title' => __( 'Skills', 'mainwp' ),
                'help'  => __( 'Procedures and rules to follow when working here: how to deploy, what to check first, what never to touch.', 'mainwp' ),
            ),
            'memory'  => array(
                'title' => __( 'Memory', 'mainwp' ),
                'help'  => __( 'Outcomes and observations recorded over time. Read them as history, not as the current state.', 'mainwp' ),
            ),
        );
        ?>
        <?php if ( $as_accordion ) : ?>
        <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-knowledge-accordion">
            <h2 class="ui dividing header active title">
                <i class="right dropdown icon"></i>
                <?php esc_html_e( 'Knowledge', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Add context, skills, and memories that connected AI clients can use when working for this client.', 'mainwp' ); ?></div>
            </h2>
            <div class="content active">
        <?php endif; ?>
        <div class="<?php echo $as_accordion ? 'ui basic padded segment dimmable mainwp-knowledge-accordion-content' : 'ui padded segment dimmable'; ?>" id="mainwp-knowledge-panel" data-scope-type="<?php echo esc_attr( $scope_type ); ?>" data-scope-id="<?php echo esc_attr( $scope_id ); ?>">
            <div class="ui dimmer mainwp-knowledge-loader" aria-hidden="true">
                <div class="ui loader" role="status" aria-label="<?php esc_attr_e( 'Loading...', 'mainwp' ); ?>"></div>
            </div>
            <div class="ui info message">
                <p><?php esc_html_e( 'Records saved here reach any AI client connected to this Dashboard through MCP. There is no export in this release.', 'mainwp' ); ?></p>
                <p>
                <?php if ( 'client' === $scope_type ) : ?>
                    <?php esc_html_e( 'Client records apply to every site assigned to this client. Site records belong to the site and stay with it if the site is moved to another client.', 'mainwp' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Site records stay with this site if it is moved to another client. Client records are managed on the client profile.', 'mainwp' ); ?>
                <?php endif; ?>
                </p>
            </div>
            <div class="ui hidden message" id="mainwp-knowledge-message"></div>
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
                            <th class="mainwp-knowledge-editor-cell"><?php esc_html_e( 'Last edited by', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-actions-cell no-sort"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="ui mini basic green button mainwp-knowledge-add" data-type="<?php echo esc_attr( $type ); ?>" style="display:none">
                    <?php
                    /* translators: %s: section name (Context, Skills or Memory). */
                    echo esc_html( sprintf( __( 'Add %s', 'mainwp' ), $section['title'] ) );
                    ?>
                </button>
            </div>
            <?php endforeach; ?>
            <?php if ( 'site' === $scope_type ) : ?>
            <div id="mainwp-knowledge-inherited" style="display:none">
                <div class="ui small header">
                    <span class="mainwp-knowledge-inherited-title"></span>
                    <div class="sub header">
                        <?php esc_html_e( 'Client records apply to every site assigned to the client, and AI clients receive them together with the records of this site. They are read-only here.', 'mainwp' ); ?>
                        <a href="#" class="mainwp-knowledge-inherited-link"><?php echo esc_html( $client_link_label ); ?></a>
                    </div>
                </div>
                <table class="ui table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Title', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-type-cell"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-editor-cell"><?php esc_html_e( 'Last edited by', 'mainwp' ); ?></th>
                            <th class="mainwp-knowledge-actions-cell no-sort"></th>
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
        static::render_modal();
    }

    /**
     * The add / edit / view modal, printed once per page.
     *
     * @return void
     */
    private static function render_modal() {
        ?>
        <div class="ui small modal" id="mainwp-knowledge-modal">
            <i class="close icon"></i>
            <div class="header" data-add="<?php esc_attr_e( 'Add knowledge record', 'mainwp' ); ?>" data-edit="<?php esc_attr_e( 'Edit knowledge record', 'mainwp' ); ?>" data-view="<?php esc_attr_e( 'View knowledge record', 'mainwp' ); ?>"><?php esc_html_e( 'Add knowledge record', 'mainwp' ); ?></div>
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
                    <div class="field">
                        <label for="mainwp-knowledge-body"><?php esc_html_e( 'Body', 'mainwp' ); ?></label>
                        <textarea id="mainwp-knowledge-body" rows="10" maxlength="<?php echo (int) MainWP_DB_Knowledge::BODY_MAX_LENGTH; ?>" placeholder="<?php esc_attr_e( 'Plain text. Write it the way you would brief a new team member.', 'mainwp' ); ?>"></textarea>
                    </div>
                    <input type="hidden" id="mainwp-knowledge-id" value="0">
                    <input type="hidden" id="mainwp-knowledge-revision" value="0">
                </div>
            </div>
            <div class="actions">
                <button type="button" class="ui cancel button" data-cancel="<?php esc_attr_e( 'Cancel', 'mainwp' ); ?>" data-close="<?php esc_attr_e( 'Close', 'mainwp' ); ?>"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                <button type="button" class="ui green button" id="mainwp-knowledge-save"><?php esc_html_e( 'Save', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
    }
}
