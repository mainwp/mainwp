<?php
/**
 * MainWP Database Assistant.
 *
 * This file handles the database operations for the AI Assistant feature,
 * including conversation and message storage.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Database class using proper sanitization and prepare().

/**
 * Class MainWP_DB_Assistant
 *
 * Handles database operations for the AI Assistant feature.
 */
class MainWP_DB_Assistant extends MainWP_DB_Base {

    /**
     * Option flagging that a spend-accrual insert failed.
     *
     * Accrual runs after the provider call, so a failed insert cannot block
     * its own turn; the budget gates read this flag and fail closed on the
     * next call instead of letting the site-wide total sit at $0 forever.
     * Stores the failure timestamp. Cleared by a later successful accrual
     * and by the spend migration once the schema postcondition verifies.
     *
     * @var string
     */
    const OPTION_SPEND_ACCOUNTING_BROKEN = 'mainwp_assistant_spend_accounting_broken';

    /**
     * Timestamp of the last orphaned-message sweep.
     *
     * @var string
     */
    const OPTION_ORPHAN_SWEEP = 'mainwp_assistant_orphan_sweep_last';

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var MainWP_DB_Assistant|null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create or return the single instance of the class.
     *
     * @return MainWP_DB_Assistant The single instance of the class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct();
        add_filter( 'mainwp_db_install_tables', array( $this, 'hook_db_install_tables' ), 10, 3 );
        add_action( 'mainwp_db_after_update', array( $this, 'hook_db_after_update' ), 10, 2 );
    }

    /**
     * Method hook_db_install_tables()
     *
     * Get the query to install assistant database tables.
     *
     * @param array  $sql            Input filter array of SQL queries.
     * @param string $currentVersion Current database version.
     * @param string $charset_collate Charset collate.
     *
     * @return array $sql Modified queries array.
     */
    public function hook_db_install_tables( $sql, $currentVersion, $charset_collate ) {
        // Table: mainwp_assistant_conversations.
        $tbl   = 'CREATE TABLE ' . $this->table_name( 'assistant_conversations' ) . " (
            id int(11) NOT NULL auto_increment,
            user_id bigint(20) NOT NULL,
            title varchar(255) NOT NULL default '',
            created_at int(11) NOT NULL default 0,
            updated_at int(11) NOT NULL default 0,
            metadata longtext NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at),
            KEY idx_user_updated (user_id, updated_at)";
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        // Table: mainwp_assistant_messages.
        $tbl   = 'CREATE TABLE ' . $this->table_name( 'assistant_messages' ) . " (
            id bigint(20) NOT NULL auto_increment,
            conversation_id int(11) NOT NULL,
            role enum('user','assistant','system') NOT NULL,
            content longtext NOT NULL,
            created_at int(11) NOT NULL default 0,
            metadata longtext NOT NULL,
            PRIMARY KEY (id),
            KEY idx_conversation_id (conversation_id),
            KEY idx_created_at (created_at)";
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        // Table: mainwp_assistant_spend (daily AI spend accounting, one row
        // per (site-timezone day, user)). Deliberately a real table, not a
        // transient: the daily totals back hard spend caps and must survive
        // object-cache eviction. The user_id 0 bucket holds rows accrued
        // before the per-user grain existed.
        $tbl   = 'CREATE TABLE ' . $this->table_name( 'assistant_spend' ) . ' (
            spend_day date NOT NULL,
            user_id bigint(20) NOT NULL default 0,
            cost_usd decimal(14,6) NOT NULL default 0,
            prompt_tokens bigint(20) NOT NULL default 0,
            completion_tokens bigint(20) NOT NULL default 0,
            unpriced_turns int(11) NOT NULL default 0,
            turns int(11) NOT NULL default 0,
            updated_at int(11) NOT NULL default 0,
            PRIMARY KEY (spend_day, user_id)';
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        return $sql;
    }

    /**
     * Method hook_db_after_update()
     *
     * Handle database updates for existing installations.
     * Creates assistant tables if they don't exist.
     *
     * @param string $old_version Old database version.
     * @param string $new_version New database version.
     */
    public function hook_db_after_update( $old_version, $new_version ) {
        // Skip only if every assistant table is present.
        // The dbDelta() pass in MainWP_Install::update() suppresses errors, so
        // this existence check is the real safety net for a swallowed CREATE.
        $conversations_table = $this->table_name( 'assistant_conversations' );
        $messages_table      = $this->table_name( 'assistant_messages' );
        $spend_table         = $this->table_name( 'assistant_spend' );
        $conversations_like  = $this->wpdb->esc_like( $conversations_table );
        $messages_like       = $this->wpdb->esc_like( $messages_table );
        $spend_like          = $this->wpdb->esc_like( $spend_table );

        $conversations_exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $conversations_like ) );
        $messages_exists      = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $messages_like ) );
        $spend_exists         = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $spend_like ) );

        if ( ! $conversations_exists || ! $messages_exists || ! $spend_exists ) {
            // Create missing tables for existing installs (dbDelta is idempotent).
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = array();
            $sql = $this->hook_db_install_tables( $sql, '', $charset_collate );

            $suppress = $this->wpdb->suppress_errors();
            foreach ( $sql as $query ) {
                dbDelta( $query );
            }
            $this->wpdb->suppress_errors( $suppress );
        }

        // Add idx_user_updated index if missing (for cleanup query optimization).
        if ( $conversations_exists ) {
            $this->maybe_add_user_updated_index( $conversations_table );
        }

        // Widen the spend table to the per-user grain if it predates it.
        if ( $spend_exists ) {
            $this->maybe_migrate_spend_user_id( $spend_table );
        }

        $this->maybe_cleanup_provider_meta();
    }

    /**
     * Method maybe_migrate_spend_user_id()
     *
     * Widens assistant_spend from one row per day to one row per (day, user):
     * adds the user_id column and swaps the primary key to (spend_day,
     * user_id). Existing rows keep the column default 0, the global bucket,
     * so no data backfill is needed. Column and key are checked separately
     * because dbDelta's pass in MainWP_Install::update() may have added the
     * column from the new CREATE without touching the primary key; each ALTER
     * applies only the missing piece, so re-runs are no-ops.
     *
     * @param string $table Spend table name.
     */
    private function maybe_migrate_spend_user_id( $table ) {
        $has_column = $this->wpdb->get_var(
            $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'user_id' )
        );

        $suppress = $this->wpdb->suppress_errors();

        if ( ! $has_column ) {
            // The ALTER runs suppressed, so verify it actually landed and
            // retry once: a silently missing column makes every accrual
            // INSERT fail from then on, which is what the broken-accounting
            // flag guards against.
            for ( $attempt = 0; $attempt < 2 && ! $has_column; $attempt++ ) {
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        'ALTER TABLE %i ADD COLUMN user_id bigint(20) NOT NULL DEFAULT 0 AFTER spend_day',
                        $table
                    )
                );

                $has_column = $this->wpdb->get_var(
                    $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'user_id' )
                );
            }

            if ( ! $has_column ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberately loud: spend accrual cannot work against this schema.
                error_log( 'MainWP Assistant: could not add the user_id column to ' . $table . '; spend accounting will fail until the schema is repaired.' );
            }
        }

        // SHOW INDEX, not information_schema.statistics: the latter serves
        // cached values under MySQL 8's information_schema_stats_expiry and
        // can report a just-changed key wrong.
        $pk_columns = $this->spend_primary_key_column_count( $table );

        if ( 1 === $pk_columns ) {
            // Same postcondition discipline as the column: a swallowed PK
            // failure is the silent grain breakage. With spend_day alone as
            // the key, ON DUPLICATE KEY UPDATE collapses every user's
            // accrual into one row while inserts "succeed", so nothing else
            // ever surfaces it.
            for ( $attempt = 0; $attempt < 2 && 1 === $pk_columns; $attempt++ ) {
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        'ALTER TABLE %i DROP PRIMARY KEY, ADD PRIMARY KEY (spend_day, user_id)',
                        $table
                    )
                );

                $pk_columns = $this->spend_primary_key_column_count( $table );
            }

            if ( 1 === $pk_columns ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberately loud: per-user spend rows collapse under a single-column key.
                error_log( 'MainWP Assistant: could not widen the primary key on ' . $table . ' to (spend_day, user_id); per-user spend accounting is unreliable until the schema is repaired.' );
            }
        }

        $this->wpdb->suppress_errors( $suppress );

        if ( $has_column && 2 === $pk_columns && get_option( self::OPTION_SPEND_ACCOUNTING_BROKEN ) ) {
            // The fully verified schema (column AND composite key) is the
            // upgrade-time recovery path for a wedged cap: if inserts still
            // fail for another reason, the first accrual sets the flag
            // right back.
            delete_option( self::OPTION_SPEND_ACCOUNTING_BROKEN );
        }
    }

    /**
     * Count the columns in the spend table's PRIMARY KEY.
     *
     * @param string $table Spend table name.
     *
     * @return int Column count (2 = the composite (spend_day, user_id) key).
     */
    private function spend_primary_key_column_count( $table ) {
        $pk_rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'PRIMARY'", $table )
        );

        return is_array( $pk_rows ) ? count( $pk_rows ) : 0;
    }

    /**
     * Remove per-user provider records left behind by the pre-AI-Client provider layer.
     *
     * The custom provider system stored encrypted API keys in usermeta; with
     * credentials now managed by WordPress core Connectors, that data has no
     * remaining reader and is purged once.
     *
     * @return void
     */
    private function maybe_cleanup_provider_meta() {
        if ( get_option( 'mainwp_assistant_provider_meta_cleaned' ) ) {
            return;
        }

        foreach ( array( 'mainwp_assistant_providers', 'mainwp_assistant_active_provider' ) as $meta_key ) {
            delete_metadata( 'user', 0, $meta_key, '', true );
        }

        delete_option( 'mainwp_assistant_legacy_key_migrated' );
        update_option( 'mainwp_assistant_provider_meta_cleaned', 1, false );
    }

    /**
     * Method maybe_add_user_updated_index()
     *
     * Adds the idx_user_updated composite index if it doesn't exist.
     * This index optimizes the cleanup_old_conversations() query.
     *
     * Unlike the spend migration above, the ALTER here is fired and forgotten with
     * no postcondition check: a missing index only makes the cleanup query slower,
     * so a failure is not worth an upgrade-time error or a retry.
     *
     * @param string $table Table name.
     */
    private function maybe_add_user_updated_index( $table ) {
        $index_exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW INDEX FROM %i WHERE Key_name = 'idx_user_updated'",
                $table
            )
        );

        if ( $index_exists ) {
            return;
        }

        $suppress = $this->wpdb->suppress_errors();
        $this->wpdb->query(
            $this->wpdb->prepare(
                'ALTER TABLE %i ADD INDEX idx_user_updated (user_id, updated_at)',
                $table
            )
        );
        $this->wpdb->suppress_errors( $suppress );
    }

    // =========================================================================
    // CONVERSATION CRUD METHODS
    // =========================================================================

    /**
     * Sanitize a conversation title for storage.
     *
     * @param string $title Raw title.
     *
     * @return string
     */
    private function sanitize_conversation_title( $title ) {
        $title = sanitize_text_field( $title );

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $title, 0, 255 );
        }

        return substr( $title, 0, 255 );
    }

    /**
     * Create a new conversation.
     *
     * @param int    $user_id  User ID who owns the conversation.
     * @param string $title    Optional conversation title.
     * @param array  $metadata Optional metadata array.
     *
     * @return int|\WP_Error Conversation ID on success, WP_Error on failure.
     */
    public function create_conversation( $user_id, $title = '', $metadata = array() ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return new \WP_Error( 'invalid_user', __( 'Invalid user ID.', 'mainwp' ) );
        }

        $now    = time();
        $result = $this->wpdb->insert(
            $this->table_name( 'assistant_conversations' ),
            array(
                'user_id'    => $user_id,
                'title'      => $this->sanitize_conversation_title( $title ),
                'created_at' => $now,
                'updated_at' => $now,
                'metadata'   => wp_json_encode( $metadata ),
            ),
            array( '%d', '%s', '%d', '%d', '%s' )
        );

        if ( false === $result ) {
            return new \WP_Error( 'db_error', __( 'Failed to create conversation.', 'mainwp' ) );
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Get a conversation by ID.
     *
     * @param int      $conversation_id Conversation ID.
     * @param int|null $user_id         Owning user; null is rejected, not a skip.
     *
     * @return object|null|\WP_Error Conversation object, null if not found, WP_Error on access denied.
     */
    public function get_conversation( $conversation_id, $user_id = null ) {
        $conversation_id = absint( $conversation_id );
        if ( ! $conversation_id ) {
            return null;
        }

        $conversation = $this->get_conversation_unchecked( $conversation_id );
        if ( ! $conversation ) {
            return null;
        }

        if ( null === $user_id ) {
            return new \WP_Error( 'missing_user', __( 'User context is required.', 'mainwp' ) );
        }

        if ( absint( $user_id ) !== (int) $conversation->user_id ) {
            return new \WP_Error( 'access_denied', __( 'You do not have access to this conversation.', 'mainwp' ) );
        }

        return $conversation;
    }

    /**
     * Get a conversation by ID for an explicitly privileged admin flow.
     *
     * @param int $conversation_id Conversation ID.
     *
     * @return object|null|\WP_Error Conversation object, null if not found, WP_Error on access denied.
     */
    public function get_conversation_as_admin( $conversation_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'access_denied', __( 'You do not have permission to access this conversation.', 'mainwp' ) );
        }

        $conversation_id = absint( $conversation_id );
        if ( ! $conversation_id ) {
            return null;
        }

        return $this->get_conversation_unchecked( $conversation_id );
    }

    /**
     * Get a conversation without ownership checks.
     *
     * @param int $conversation_id Conversation ID.
     *
     * @return object|null Conversation object, null if not found.
     */
    private function get_conversation_unchecked( $conversation_id ) {
        $conversation = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name( 'assistant_conversations' ) ) . ' WHERE id = %d',
                $conversation_id
            )
        );

        if ( ! $conversation ) {
            return null;
        }

        if ( ! empty( $conversation->metadata ) ) {
            $conversation->metadata = json_decode( $conversation->metadata, true );
        } else {
            $conversation->metadata = array();
        }

        return $conversation;
    }

    /**
     * Get conversations for a user with pagination.
     *
     * Includes message_count for each conversation via SQL COUNT() join,
     * avoiding the need for separate queries per conversation.
     *
     * @param int  $user_id       User ID.
     * @param int  $page          Page number (1-based).
     * @param int  $per_page      Items per page.
     * @param bool $include_empty Include conversations that have no messages.
     *
     * @return array Array with 'conversations' and 'total' keys.
     */
    public function get_user_conversations( $user_id, $page = 1, $per_page = 20, $include_empty = false ) {
        $user_id  = absint( $user_id );
        $page     = max( 1, absint( $page ) );
        $per_page = max( 1, min( 100, absint( $per_page ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $conversations_table = esc_sql( $this->table_name( 'assistant_conversations' ) );
        $messages_table      = esc_sql( $this->table_name( 'assistant_messages' ) );

        // A failed Sites request leaves a conversation with no messages. Lists hide
        // those, but the history export keeps every stored conversation.
        $total_filter   = $include_empty ? '' : "AND EXISTS (SELECT 1 FROM {$messages_table} m WHERE m.conversation_id = c.id)";
        $listed_filter  = $include_empty ? '' : "AND EXISTS (SELECT 1 FROM {$messages_table} visible_message WHERE visible_message.conversation_id = listed.id)";
        $counted_filter = $include_empty ? '' : "AND EXISTS (SELECT 1 FROM {$messages_table} counted_message WHERE counted_message.conversation_id = counted.id)";

        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$conversations_table} c WHERE user_id = %d
                    {$total_filter}",
                $user_id
            )
        );

        // Limit message counting to the conversations on the current page to avoid
        // aggregating the entire messages table before applying pagination.
        // The snippet subquery returns the latest user message's first 160
        // characters so list previews can show real content, not counts.
        $conversations = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.*, COALESCE(msg_counts.message_count, 0) AS message_count,
                    (
                        SELECT SUBSTRING(m2.content, 1, 160)
                        FROM {$messages_table} m2
                        WHERE m2.conversation_id = c.id AND m2.role = 'user'
                        ORDER BY m2.id DESC
                        LIMIT 1
                    ) AS snippet
                 FROM {$conversations_table} c
                 INNER JOIN (
                     SELECT id
                     FROM {$conversations_table} listed
                     WHERE user_id = %d
                       {$listed_filter}
                     ORDER BY updated_at DESC, id DESC
                     LIMIT %d OFFSET %d
                 ) paged_conversations ON c.id = paged_conversations.id
                 LEFT JOIN (
                     SELECT m.conversation_id, COUNT(*) AS message_count
                     FROM {$messages_table} m
                     INNER JOIN (
                         SELECT id
                         FROM {$conversations_table} counted
                         WHERE user_id = %d
                           {$counted_filter}
                         ORDER BY updated_at DESC, id DESC
                         LIMIT %d OFFSET %d
                     ) paged_message_ids ON m.conversation_id = paged_message_ids.id
                     GROUP BY m.conversation_id
                 ) msg_counts ON c.id = msg_counts.conversation_id
                 ORDER BY c.updated_at DESC, c.id DESC
                ",
                $user_id,
                $per_page,
                $offset,
                $user_id,
                $per_page,
                $offset
            )
        );

        if ( ! is_array( $conversations ) || empty( $conversations ) ) {
            $conversations = array();
        }

        foreach ( $conversations as $conversation ) {
            if ( ! empty( $conversation->metadata ) ) {
                $conversation->metadata = json_decode( $conversation->metadata, true );
            } else {
                $conversation->metadata = array();
            }
            $conversation->message_count = (int) ( $conversation->message_count ?? 0 );
        }

        return array(
            'conversations' => $conversations,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => ceil( $total / $per_page ),
        );
    }

    /**
     * Get count of conversations for a user.
     *
     * Lightweight method for checking if user has any conversations
     * without loading the full conversation list.
     *
     * @param int $user_id User ID.
     *
     * @return int Number of conversations.
     */
    public function get_conversation_count( $user_id ) {
        $user_id = absint( $user_id );

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*) FROM ' . esc_sql( $this->table_name( 'assistant_conversations' ) ) . ' WHERE user_id = %d',
                $user_id
            )
        );
    }

    /**
     * Update a conversation.
     *
     * @param int   $conversation_id Conversation ID.
     * @param int   $user_id         User ID for ownership verification.
     * @param array $data            Data to update (title, metadata).
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function update_conversation( $conversation_id, $user_id, $data ) {
        $conversation_id = absint( $conversation_id );
        $user_id         = absint( $user_id );

        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'not_found', __( 'Conversation not found.', 'mainwp' ) );
        }

        $update_data   = array( 'updated_at' => time() );
        $update_format = array( '%d' );

        if ( isset( $data['title'] ) ) {
            $update_data['title'] = $this->sanitize_conversation_title( $data['title'] );
            $update_format[]      = '%s';
        }

        if ( isset( $data['metadata'] ) ) {
            $update_data['metadata'] = wp_json_encode( $data['metadata'] );
            $update_format[]         = '%s';
        }

        $result = $this->wpdb->update(
            $this->table_name( 'assistant_conversations' ),
            $update_data,
            array( 'id' => $conversation_id ),
            $update_format,
            array( '%d' )
        );

        if ( false === $result ) {
            return new \WP_Error( 'db_error', __( 'Failed to update conversation.', 'mainwp' ) );
        }

        return true;
    }

    /**
     * Delete a conversation and its messages.
     *
     * @param int $conversation_id Conversation ID.
     * @param int $user_id         User ID for ownership verification.
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function delete_conversation( $conversation_id, $user_id ) {
        $conversation_id = absint( $conversation_id );
        $user_id         = absint( $user_id );

        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'not_found', __( 'Conversation not found.', 'mainwp' ) );
        }

        // Delete messages first (foreign key constraint style).
        $messages_deleted = $this->wpdb->delete(
            $this->table_name( 'assistant_messages' ),
            array( 'conversation_id' => $conversation_id ),
            array( '%d' )
        );

        if ( false === $messages_deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversation messages.', 'mainwp' ) );
        }

        $result = $this->wpdb->delete(
            $this->table_name( 'assistant_conversations' ),
            array( 'id' => $conversation_id ),
            array( '%d' )
        );

        if ( false === $result ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversation.', 'mainwp' ) );
        }

        // The conversation-start log row keeps the first prompt characters as
        // its title, so deleting the thread has to take that copy too. Scoped
        // to this conversation: the user's other threads keep their titles.
        $this->scrub_conversation_log_titles( $user_id, array( $conversation_id ) );

        return true;
    }

    /**
     * Delete all conversations and messages across all users.
     *
     * Used when revoking assistant consent to permanently remove
     * all conversation data from the database.
     *
     * @return int|\WP_Error Number of conversations deleted.
     */
    public function delete_all_conversations() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'access_denied', __( 'You do not have permission to delete all conversations.', 'mainwp' ) );
        }

        $conversations_table = $this->table_name( 'assistant_conversations' );
        $messages_table      = $this->table_name( 'assistant_messages' );

        $messages_deleted = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i', $messages_table ) );
        if ( false === $messages_deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversation messages.', 'mainwp' ) );
        }

        $deleted = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i', $conversations_table ) );

        if ( false === $deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversations.', 'mainwp' ) );
        }

        $this->scrub_conversation_log_titles( 0 );

        return (int) $deleted;
    }

    /**
     * Scrub prompt-derived conversation titles from the activity log.
     *
     * Conversation-start log rows carry the first prompt characters in their
     * item text and their title meta. Clearing history (and opting out, which
     * clears first) promises the prompts are gone, so the logged copies go
     * too, in the live tables and in the archive pair. Best-effort: the
     * logs module may never have been enabled, so missing tables are skipped
     * and query failures are ignored.
     *
     * Passing conversation ids narrows the scrub to the log rows those
     * conversations started, so deleting one thread does not blank the log
     * titles of the threads the user kept. The narrowing needs the meta table
     * (the conversation id lives in a `conversation_id` meta row), so a log
     * schema without one is skipped rather than scrubbed wholesale.
     *
     * @param int   $user_id          User whose titles to scrub, 0 for every user.
     * @param array $conversation_ids Conversations to limit the scrub to; empty scrubs all of them.
     *
     * @return void
     */
    public function scrub_conversation_log_titles( $user_id, $conversation_ids = array() ) {
        $user_id          = absint( $user_id );
        $conversation_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $conversation_ids ) ) ) );

        $pairs = array(
            array( $this->table_name( 'wp_logs' ), $this->table_name( 'wp_logs_meta' ) ),
            array( $this->table_name( 'wp_logs_archive' ), $this->table_name( 'wp_logs_meta_archive' ) ),
        );

        // The entire scrub is best-effort: the logs module may be disabled, a
        // table may be absent, or (seen live on legacy sites) the archive pair
        // may predate the `connector` column the live tables carry. Suppress
        // DB errors across every query so a schema mismatch is skipped
        // silently instead of surfacing a raw wpdb error on history clear.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Log-table scrub on history clear; table names come from table_name().
        $suppress = $this->wpdb->suppress_errors( true );

        try {
            foreach ( $pairs as $pair ) {
                list( $logs_table, $meta_table ) = $pair;

                // Existence + shape probe instead of SHOW TABLES: the phpunit
                // harness creates tables as TEMPORARY, which SHOW TABLES cannot
                // see. Selecting the columns the scrub needs also rejects a
                // legacy archive table that lacks `connector`, so the mutating
                // queries below never run against an incompatible schema.
                $logs_ok = false !== $this->wpdb->query( $this->wpdb->prepare( 'SELECT connector, action, item, user_id FROM %i LIMIT 1', $logs_table ) );
                $meta_ok = false !== $this->wpdb->query( $this->wpdb->prepare( 'SELECT meta_log_id, meta_key FROM %i LIMIT 1', $meta_table ) );

                if ( ! $logs_ok ) {
                    continue;
                }

                $user_clause = $user_id ? $this->wpdb->prepare( ' AND l.user_id = %d', $user_id ) : '';

                // Resolve the conversation-scoped rows up front instead of
                // joining the meta table a second time inside the mutating
                // queries: the phpunit harness creates these as TEMPORARY
                // tables, which MySQL cannot reference twice in one statement.
                $scope_clause = '';
                if ( $conversation_ids ) {
                    if ( ! $meta_ok ) {
                        continue;
                    }

                    $log_ids = $this->conversation_log_ids( $logs_table, $meta_table, $conversation_ids, $user_clause );
                    if ( empty( $log_ids ) ) {
                        continue;
                    }

                    $scope_clause = ' AND l.log_id IN ( ' . implode( ',', $log_ids ) . ' )';
                }

                if ( $meta_ok ) {
                    $this->wpdb->query(
                        $this->wpdb->prepare(
                            'DELETE m FROM %i AS m
                             INNER JOIN %i AS l ON m.meta_log_id = l.log_id
                             WHERE l.connector = %s AND l.action = %s AND m.meta_key = %s',
                            $meta_table,
                            $logs_table,
                            'assistant',
                            'started',
                            'title'
                        ) . $user_clause . $scope_clause // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Clauses prepared above.
                    );
                }

                $this->wpdb->query(
                    $this->wpdb->prepare(
                        'UPDATE %i AS l SET l.item = %s WHERE l.connector = %s AND l.action = %s',
                        $logs_table,
                        __( 'Started conversation', 'mainwp' ),
                        'assistant',
                        'started'
                    ) . $user_clause . $scope_clause // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Clauses prepared above.
                );
            }
        } finally {
            $this->wpdb->suppress_errors( $suppress );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Find the conversation-start log rows belonging to given conversations.
     *
     * Runs inside the scrub's error suppression, so a missing or mismatched
     * log schema returns nothing and the caller skips that table pair.
     *
     * @param string $logs_table       Log table name.
     * @param string $meta_table       Log meta table name.
     * @param array  $conversation_ids Conversation ids to match.
     * @param string $user_clause      Prepared ` AND l.user_id = %d` clause, or ''.
     *
     * @return array Log row ids.
     */
    private function conversation_log_ids( $logs_table, $meta_table, array $conversation_ids, $user_clause ) {
        $placeholders = implode( ', ', array_fill( 0, count( $conversation_ids ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Log-table scrub on history clear; table names come from table_name(), and the replacements arrive as one array the sniff cannot count.
        $log_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT DISTINCT l.log_id FROM %i AS l
                 INNER JOIN %i AS m ON m.meta_log_id = l.log_id
                 WHERE m.meta_key = %s AND m.meta_value IN ( ' . $placeholders . ' )
                   AND l.connector = %s AND l.action = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list built from a counted array.
                array_merge(
                    array( $logs_table, $meta_table, 'conversation_id' ),
                    array_map( 'strval', $conversation_ids ),
                    array( 'assistant', 'started' )
                )
            ) . $user_clause // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Clause prepared by the caller.
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return array_map( 'absint', (array) $log_ids );
    }

    /**
     * Delete all conversations for a user.
     *
     * @param int $user_id User ID.
     *
     * @return int|\WP_Error Number of conversations deleted.
     */
    public function delete_user_conversations( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        if ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'access_denied', __( 'You do not have permission to delete this user\'s conversations.', 'mainwp' ) );
        }

        $conversations_table = $this->table_name( 'assistant_conversations' );
        $messages_table      = $this->table_name( 'assistant_messages' );

        // Delete messages for all user's conversations using a join.
        $messages_deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE m FROM %i AS m
                 INNER JOIN %i AS c ON m.conversation_id = c.id
                 WHERE c.user_id = %d',
                $messages_table,
                $conversations_table,
                $user_id
            )
        );
        if ( false === $messages_deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversation messages.', 'mainwp' ) );
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE user_id = %d',
                $conversations_table,
                $user_id
            )
        );

        if ( false === $deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversations.', 'mainwp' ) );
        }

        $this->scrub_conversation_log_titles( $user_id );

        return (int) $deleted;
    }

    // =========================================================================
    // MESSAGE CRUD METHODS
    // =========================================================================

    /**
     * Add a message to a conversation.
     *
     * @param int        $conversation_id Conversation ID.
     * @param string     $role            Message role (user, assistant, system).
     * @param string     $content         Message content.
     * @param int|null   $user_id         Owning user; null is rejected, not a skip.
     * @param array|null $metadata        Optional per-message metadata (e.g. docs
     *                                    answer sources), stored as JSON.
     *
     * @return int|\WP_Error Message ID on success, WP_Error on failure.
     */
    public function add_message( $conversation_id, $role, $content, $user_id = null, $metadata = null ) {
        if ( null === $user_id ) {
            return new \WP_Error( 'missing_user', __( 'User context is required.', 'mainwp' ) );
        }

        $conversation_id = absint( $conversation_id );
        if ( ! $conversation_id ) {
            return new \WP_Error( 'invalid_conversation', __( 'Invalid conversation ID.', 'mainwp' ) );
        }

        $user_id      = absint( $user_id );
        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $valid_roles = array( 'user', 'assistant', 'system' );
        if ( ! in_array( $role, $valid_roles, true ) ) {
            return new \WP_Error( 'invalid_role', __( 'Invalid message role.', 'mainwp' ) );
        }

        // The ownership check above and the insert are separate round trips, so
        // a delete landing between them used to leave the message behind: no
        // conversation row to reach it through, yet the prompt text still on
        // disk after the user deleted the thread. Selecting the owning row as
        // part of the INSERT makes the check and the write one statement, so
        // zero affected rows means the conversation went away and nothing was
        // written.
        // Keep wpdb from printing the prompt in a SQL error ahead of the AJAX JSON.
        $suppress = $this->wpdb->suppress_errors();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MainWP's own table; the insert must stay conditional on the owning row.
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                'INSERT INTO %i ( conversation_id, role, content, created_at, metadata )
                 SELECT %d, %s, %s, %d, %s FROM %i WHERE id = %d AND user_id = %d',
                $this->table_name( 'assistant_messages' ),
                $conversation_id,
                $role,
                $content,
                time(),
                ! empty( $metadata ) && is_array( $metadata ) ? wp_json_encode( $metadata ) : '',
                $this->table_name( 'assistant_conversations' ),
                $conversation_id,
                $user_id
            )
        );
        $this->wpdb->suppress_errors( $suppress );

        if ( false === $result ) {
            return new \WP_Error( 'db_error', __( 'Failed to add message.', 'mainwp' ) );
        }

        if ( 0 === (int) $result ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $updated = $this->wpdb->update(
            $this->table_name( 'assistant_conversations' ),
            array( 'updated_at' => time() ),
            array( 'id' => $conversation_id ),
            array( '%d' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Best-effort follow-up update should be logged without failing the committed insert.
            error_log(
                sprintf(
                    'MainWP Assistant DB: failed to update conversation timestamp for %d after message insert %d. %s',
                    $conversation_id,
                    (int) $this->wpdb->insert_id,
                    $this->wpdb->last_error
                )
            );
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Delete a message within an owned conversation.
     *
     * @param int $conversation_id Conversation ID.
     * @param int $message_id      Message ID.
     * @param int $user_id         Owner ID.
     * @return bool|\WP_Error True on deletion, or an error.
     */
    public function delete_message( $conversation_id, $message_id, $user_id ) {
        $conversation_id = absint( $conversation_id );
        $conversation    = $this->get_conversation( $conversation_id, absint( $user_id ) );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'not_found', __( 'Conversation not found.', 'mainwp' ) );
        }

        $result = $this->wpdb->delete(
            $this->table_name( 'assistant_messages' ),
            array(
                'conversation_id' => $conversation_id,
                'id'              => absint( $message_id ),
            ),
            array( '%d', '%d' )
        );
        if ( false === $result ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete message.', 'mainwp' ) );
        }
        if ( 0 === $result ) {
            return new \WP_Error( 'not_found', __( 'Message not found.', 'mainwp' ) );
        }
        return true;
    }

    /**
     * Read a saved answer only within an owned conversation.
     *
     * @param int $conversation_id Conversation ID.
     * @param int $message_id      Message ID.
     * @param int $user_id         Owner ID.
     * @return object|\WP_Error
     */
    public function get_feedback_message( $conversation_id, $message_id, $user_id ) {
        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }
        $table = esc_sql( $this->table_name( 'assistant_messages' ) );
        $row   = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id, conversation_id, role, metadata FROM {$table} WHERE id = %d AND conversation_id = %d AND role = %s", $message_id, $conversation_id, 'assistant' ) );
        if ( ! $row ) {
            return new \WP_Error( 'feedback_unavailable', __( 'Feedback is unavailable for this answer.', 'mainwp' ) );
        }
        return $row;
    }

    /**
     * Save a rating without replacing other answer metadata.
     *
     * @param int         $conversation_id              Conversation ID.
     * @param int         $message_id                   Message ID.
     * @param int         $user_id                      Owner ID.
     * @param string|null $rating                       Positive, negative, or cleared.
     * @param string      $expected_feedback_message_id Answer identity sent to the Bridge.
     * @return true|\WP_Error
     */
    public function save_message_feedback( $conversation_id, $message_id, $user_id, $rating, $expected_feedback_message_id ) {
        $row = $this->get_feedback_message( $conversation_id, $message_id, $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        $metadata = json_decode( $row->metadata ?? '', true );
        if ( ! is_array( $metadata ) || empty( $metadata['feedback_message_id'] ) || $expected_feedback_message_id !== $metadata['feedback_message_id'] || ! in_array( $rating, array( 'positive', 'negative', null ), true ) ) {
            return new \WP_Error( 'feedback_unavailable', __( 'Feedback is unavailable for this answer.', 'mainwp' ) );
        }
        // The same rating twice changes no row; the stored state already matches.
        if ( array_key_exists( 'feedback_rating', $metadata ) && $metadata['feedback_rating'] === $rating ) {
            return true;
        }
        $metadata['feedback_rating'] = $rating;
        $updated                     = $this->wpdb->update(
            $this->table_name( 'assistant_messages' ),
            array( 'metadata' => wp_json_encode( $metadata ) ),
            array(
                'id'              => $message_id,
                'conversation_id' => $conversation_id,
                'metadata'        => $row->metadata,
            ),
            array( '%s' ),
            array( '%d', '%d', '%s' )
        );
        if ( false === $updated ) {
            return new \WP_Error( 'feedback_save_failed', __( 'Could not save feedback. Please try again.', 'mainwp' ) );
        }
        if ( 0 === (int) $updated ) {
            return new \WP_Error( 'feedback_unavailable', __( 'Feedback is unavailable for this answer.', 'mainwp' ) );
        }
        return true;
    }

    /**
     * Get messages for a conversation.
     *
     * @param int      $conversation_id Conversation ID.
     * @param int|null $user_id         Owning user; null is rejected, not a skip.
     * @param int      $limit           Maximum number of messages.
     * @param int      $offset          Offset for pagination.
     * @param string   $order           Sort order: 'ASC' (oldest first) or 'DESC' (newest first). Default 'ASC'.
     *
     * @return array|\WP_Error Array of message objects on success, WP_Error on failure.
     */
    public function get_messages( $conversation_id, $user_id = null, $limit = 50, $offset = 0, $order = 'ASC' ) {
        $conversation_id = absint( $conversation_id );
        $limit           = max( 1, min( 500, absint( $limit ) ) );
        $offset          = max( 0, absint( $offset ) );
        $order           = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';

        if ( null !== $user_id ) {
            $user_id = absint( $user_id );
        }

        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $messages_table = esc_sql( $this->table_name( 'assistant_messages' ) );
        $query          = 'DESC' === $order
            ? "SELECT * FROM {$messages_table} WHERE conversation_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d"
            : "SELECT * FROM {$messages_table} WHERE conversation_id = %d ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d";

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                $query,
                $conversation_id,
                $limit,
                $offset
            )
        );
    }

    /**
     * Get messages for a conversation for an explicitly privileged admin flow.
     *
     * @param int    $conversation_id Conversation ID.
     * @param int    $limit           Maximum number of messages.
     * @param int    $offset          Offset for pagination.
     * @param string $order           Sort order.
     *
     * @return array|\WP_Error Array of message objects on success, WP_Error on failure.
     */
    public function get_messages_as_admin( $conversation_id, $limit = 50, $offset = 0, $order = 'ASC' ) {
        $conversation_id = absint( $conversation_id );
        $limit           = max( 1, min( 500, absint( $limit ) ) );
        $offset          = max( 0, absint( $offset ) );
        $order           = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';

        $conversation = $this->get_conversation_as_admin( $conversation_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $messages_table = esc_sql( $this->table_name( 'assistant_messages' ) );
        $query          = 'DESC' === $order
            ? "SELECT * FROM {$messages_table} WHERE conversation_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d"
            : "SELECT * FROM {$messages_table} WHERE conversation_id = %d ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d";

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                $query,
                $conversation_id,
                $limit,
                $offset
            )
        );
    }

    /**
     * Delete old messages from a conversation.
     *
     * @param int      $conversation_id   Conversation ID.
     * @param int      $before_timestamp  Delete messages before this Unix timestamp.
     * @param int|null $user_id           Owning user; null is rejected, not a skip.
     *
     * @return int|\WP_Error Number of messages deleted on success, WP_Error on failure.
     */
    public function delete_old_messages( $conversation_id, $before_timestamp, $user_id = null ) {
        if ( null === $user_id ) {
            return new \WP_Error( 'missing_user', __( 'User context is required.', 'mainwp' ) );
        }

        $conversation_id  = absint( $conversation_id );
        $before_timestamp = absint( $before_timestamp );

        $user_id      = absint( $user_id );
        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE conversation_id = %d AND created_at < %d',
                $this->table_name( 'assistant_messages' ),
                $conversation_id,
                $before_timestamp
            )
        );
        if ( false === $deleted ) {
            return new \WP_Error(
                'db_error',
                __( 'Failed to delete old messages.', 'mainwp' ),
                array(
                    'conversation_id'  => $conversation_id,
                    'before_timestamp' => $before_timestamp,
                    'db_error'         => $this->wpdb->last_error,
                )
            );
        }

        return (int) $deleted;
    }

    /**
     * Replace a bound answer without risking its loss if persistence fails.
     *
     * @param int         $conversation_id Conversation ID.
     * @param int         $message_id      Assistant message ID.
     * @param string      $content         Replacement content.
     * @param int         $user_id         User ID for ownership verification.
     * @param array|null  $metadata        Replacement metadata.
     * @param string|null $expected_content Answer text captured when the target was bound; a changed row is stale.
     * @param string|null $expected_metadata Stored metadata JSON captured with the text; both must still match byte for byte.
     *
     * @return int|\WP_Error Message ID on success, WP_Error on failure.
     */
    public function replace_assistant_message( $conversation_id, $message_id, $content, $user_id, $metadata = null, $expected_content = null, $expected_metadata = null ) {
        $conversation_id = absint( $conversation_id );
        $message_id      = absint( $message_id );
        $user_id         = absint( $user_id );

        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE %i SET content = %s, metadata = %s WHERE id = %d AND conversation_id = %d AND role = %s AND id = (SELECT x.latest_id FROM (SELECT MAX(id) AS latest_id FROM %i WHERE conversation_id = %d) x) AND ( %d = 0 OR ( BINARY content = BINARY %s AND BINARY metadata = BINARY %s ) )',
                $this->table_name( 'assistant_messages' ),
                $content,
                ! empty( $metadata ) && is_array( $metadata ) ? wp_json_encode( $metadata ) : '',
                $message_id,
                $conversation_id,
                'assistant',
                $this->table_name( 'assistant_messages' ),
                $conversation_id,
                null === $expected_content ? 0 : 1,
                (string) $expected_content,
                (string) $expected_metadata
            )
        );
        if ( false === $updated ) {
            return new \WP_Error( 'db_error', __( 'Failed to replace assistant message.', 'mainwp' ) );
        }
        if ( 0 === (int) $updated ) {
            // Zero rows also means "identical values": MySQL does not count an
            // unchanged row. Only a target that is no longer the newest row is stale.
            $existing_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT id FROM %i WHERE id = %d AND conversation_id = %d AND role = %s',
                    $this->table_name( 'assistant_messages' ),
                    $message_id,
                    $conversation_id,
                    'assistant'
                )
            );
            if ( ! $existing_id ) {
                return new \WP_Error( 'db_error', __( 'Failed to replace assistant message.', 'mainwp' ) );
            }
            $latest_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT MAX(id) FROM %i WHERE conversation_id = %d',
                    $this->table_name( 'assistant_messages' ),
                    $conversation_id
                )
            );
            if ( (int) $latest_id !== $message_id ) {
                return new \WP_Error( 'regenerate_target_stale', __( 'The conversation changed while regenerating. Reload and try again.', 'mainwp' ) );
            }
            if ( null !== $expected_content ) {
                // Two tabs regenerating the same answer both bound this row; the
                // one that lost the race must not overwrite the winner's answer.
                $current = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        'SELECT content, metadata FROM %i WHERE id = %d',
                        $this->table_name( 'assistant_messages' ),
                        $message_id
                    )
                );
                if ( ! $current || (string) $current->content !== (string) $expected_content || (string) $current->metadata !== (string) $expected_metadata ) {
                    return new \WP_Error( 'regenerate_target_stale', __( 'The conversation changed while regenerating. Reload and try again.', 'mainwp' ) );
                }
            }
        }

        $updated = $this->wpdb->update(
            $this->table_name( 'assistant_conversations' ),
            array( 'updated_at' => time() ),
            array( 'id' => $conversation_id ),
            array( '%d' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Best-effort follow-up update must not fail a successful replacement.
            error_log(
                sprintf(
                    'MainWP Assistant DB: failed to update conversation timestamp for %d after message replacement %d. %s',
                    $conversation_id,
                    $message_id,
                    $this->wpdb->last_error
                )
            );
        }

        return $message_id;
    }

    /**
     * Give a regenerated confirmation a new ID so an older card cannot approve it.
     *
     * @param int        $conversation_id  Conversation ID.
     * @param int        $message_id       Bound assistant message ID.
     * @param string     $content          Replacement content.
     * @param int        $user_id          User ID for ownership verification.
     * @param array|null $metadata         Replacement metadata.
     * @param string     $expected_content Bound answer text.
     * @param string     $expected_metadata Bound metadata JSON.
     *
     * @return int|\WP_Error New message ID on success, WP_Error on failure.
     */
    public function swap_assistant_message( $conversation_id, $message_id, $content, $user_id, $metadata, $expected_content, $expected_metadata ) {
        $conversation_id = absint( $conversation_id );
        $message_id      = absint( $message_id );
        $user_id         = absint( $user_id );

        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( is_wp_error( $conversation ) ) {
            return $conversation;
        }
        if ( ! $conversation ) {
            return new \WP_Error( 'access_denied', __( 'Conversation not found or access denied.', 'mainwp' ) );
        }

        $new_id = $this->add_message( $conversation_id, 'assistant', $content, $user_id, $metadata );
        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE id = %d AND conversation_id = %d AND role = %s AND BINARY content = BINARY %s AND BINARY metadata = BINARY %s AND id = (SELECT x.latest_id FROM (SELECT MAX(id) AS latest_id FROM %i WHERE conversation_id = %d AND id <> %d) x)',
                $this->table_name( 'assistant_messages' ),
                $message_id,
                $conversation_id,
                'assistant',
                $expected_content,
                $expected_metadata,
                $this->table_name( 'assistant_messages' ),
                $conversation_id,
                $new_id
            )
        );
        if ( false === $deleted || 0 === (int) $deleted ) {
            // A failed comparison must leave only the answer the user already saw.
            $rollback = $this->wpdb->delete(
                $this->table_name( 'assistant_messages' ),
                array(
                    'id'              => $new_id,
                    'conversation_id' => $conversation_id,
                ),
                array( '%d', '%d' )
            );
            if ( false === $deleted || false === $rollback ) {
                return new \WP_Error( 'db_error', __( 'Failed to replace assistant message.', 'mainwp' ) );
            }
            return new \WP_Error( 'regenerate_target_stale', __( 'The conversation changed while regenerating. Reload and try again.', 'mainwp' ) );
        }

        return (int) $new_id;
    }

    /**
     * Atomically claim an unresolved confirmation-request message.
     *
     * Confirm and cancel can race across tabs; both call this before acting so
     * exactly one of them settles the request. The claim is a compare-and-swap
     * on the raw metadata string: the row is re-read here and that exact string
     * is the UPDATE's compare value (a re-encode of decoded data would never
     * match byte-for-byte). Winning flips requires_confirmation off in place,
     * which every last-row pending reader already treats as resolved.
     *
     * @param int $message_id      Confirmation-request message ID.
     * @param int $conversation_id Conversation the message must belong to.
     * @param int $user_id         User ID for ownership verification.
     *
     * @return bool True when this caller won the claim, false otherwise.
     */
    public function settle_confirmation_request( $message_id, $conversation_id, $user_id ) {
        $message_id      = absint( $message_id );
        $conversation_id = absint( $conversation_id );
        $user_id         = absint( $user_id );

        if ( ! $message_id || ! $conversation_id || ! $user_id ) {
            return false;
        }

        $conversation = $this->get_conversation( $conversation_id, $user_id );
        if ( ! $conversation || is_wp_error( $conversation ) ) {
            return false;
        }

        $messages_table = $this->table_name( 'assistant_messages' );

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT metadata FROM %i WHERE id = %d AND conversation_id = %d',
                $messages_table,
                $message_id,
                $conversation_id
            )
        );

        if ( ! $row || ! is_string( $row->metadata ) || '' === $row->metadata ) {
            return false;
        }

        $decoded = json_decode( $row->metadata, true );
        if ( ! is_array( $decoded ) || empty( $decoded['requires_confirmation'] ) ) {
            return false;
        }

        $decoded['requires_confirmation'] = false;
        $decoded['confirmation_settled']  = true;

        // The reviewed params, and the tool-call/tool-result copies that embed
        // them, can carry raw credentials (http_pass, adminpassword).
        // They only need to survive until settlement (the confirm handler
        // captures them from its pre-claim peek), so the winning claim scrubs
        // them from the persisted row. The ability id stays for the cancel
        // race path and audit.
        if ( isset( $decoded['pending_confirmation'] ) && is_array( $decoded['pending_confirmation'] ) ) {
            unset(
                $decoded['pending_confirmation']['params'],
                $decoded['pending_confirmation']['tool_calls'],
                $decoded['pending_confirmation']['tool_results']
            );
        }

        // The MAX(id) anchor makes the claim conditional on the request still
        // being the conversation's last message at update time: a turn
        // appended between the caller's read and this statement abandons the
        // request positionally, and a stale resolver must not claim it. The
        // derived table sidesteps MySQL's same-table-in-UPDATE restriction.
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE %i SET metadata = %s
                 WHERE id = %d AND conversation_id = %d AND metadata = %s
                   AND id = ( SELECT newest.max_id FROM ( SELECT MAX(id) AS max_id FROM %i WHERE conversation_id = %d ) AS newest )',
                $messages_table,
                wp_json_encode( $decoded ),
                $message_id,
                $conversation_id,
                $row->metadata,
                $messages_table,
                $conversation_id
            )
        );

        return 1 === (int) $updated;
    }

    // =========================================================================
    // DAILY SPEND ACCOUNTING
    // =========================================================================

    /**
     * Accrue one model round-trip into today's site-wide spend row.
     *
     * A single atomic INSERT ... ON DUPLICATE KEY UPDATE keyed on the
     * site-timezone day, so concurrent turns cannot lose updates the way an
     * option read-modify-write would. The increments are bound as literals on
     * both sides of the statement instead of the deprecated VALUES() function
     * or the MySQL-8.0.20-only row-alias form, so the query runs unchanged on
     * MariaDB.
     *
     * @param float $cost_usd Estimated USD cost of the round-trip.
     * @param array $usage    Usage array with prompt_tokens/completion_tokens (+ optional thought_tokens).
     * @param bool  $priced   Whether the cost came from the price table (false = fallback rate).
     * @param int   $user_id  Acting user whose bucket accrues the spend (0 = global bucket).
     *
     * @return bool True on success, false on query failure.
     */
    public function add_spend( $cost_usd, $usage = array(), $priced = true, $user_id = 0 ) {
        $usage      = is_array( $usage ) ? $usage : array();
        $cost_usd   = max( 0.0, (float) $cost_usd );
        $user_id    = absint( $user_id );
        $prompt     = isset( $usage['prompt_tokens'] ) ? absint( $usage['prompt_tokens'] ) : 0;
        $completion = ( isset( $usage['completion_tokens'] ) ? absint( $usage['completion_tokens'] ) : 0 )
            + ( isset( $usage['thought_tokens'] ) ? absint( $usage['thought_tokens'] ) : 0 );
        $unpriced   = $priced ? 0 : 1;
        $now        = time();

        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                'INSERT INTO %i (spend_day, user_id, cost_usd, prompt_tokens, completion_tokens, unpriced_turns, turns, updated_at)
                 VALUES (%s, %d, %f, %d, %d, %d, 1, %d)
                 ON DUPLICATE KEY UPDATE
                    cost_usd = cost_usd + %f,
                    prompt_tokens = prompt_tokens + %d,
                    completion_tokens = completion_tokens + %d,
                    unpriced_turns = unpriced_turns + %d,
                    turns = turns + 1,
                    updated_at = %d',
                $this->table_name( 'assistant_spend' ),
                current_time( 'Y-m-d' ),
                $user_id,
                $cost_usd,
                $prompt,
                $completion,
                $unpriced,
                $now,
                $cost_usd,
                $prompt,
                $completion,
                $unpriced,
                $now
            )
        );

        if ( false === $result ) {
            // The turn already ran (accrual is post-call), so the only safe
            // reaction is flagging the breakage for the pre-call gates.
            update_option( self::OPTION_SPEND_ACCOUNTING_BROKEN, time(), false );

            return false;
        }

        if ( get_option( self::OPTION_SPEND_ACCOUNTING_BROKEN ) ) {
            // Inserts provably work again; a stale flag must not keep the
            // budget gates wedged shut.
            delete_option( self::OPTION_SPEND_ACCOUNTING_BROKEN );
        }

        return true;
    }

    /**
     * Get today's accrued site-wide spend, aggregated across all user buckets.
     *
     * Fail-closed contract: returns null when the read itself fails (missing
     * table, query error) so the budget gate can tell "no spend yet" apart
     * from "unknown" and block instead of treating an error as $0.00.
     *
     * @return array|null Row with cost_usd/prompt_tokens/completion_tokens/unpriced_turns/turns
     *                    (all zeros when nothing accrued today), or null on read failure.
     */
    public function get_spend_today() {
        $suppress = $this->wpdb->suppress_errors();
        $row      = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT SUM(cost_usd) AS cost_usd, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens, SUM(unpriced_turns) AS unpriced_turns, SUM(turns) AS turns FROM %i WHERE spend_day = %s',
                $this->table_name( 'assistant_spend' ),
                current_time( 'Y-m-d' )
            ),
            ARRAY_A
        );
        $this->wpdb->suppress_errors( $suppress );

        if ( ( null === $row || null === ( $row['cost_usd'] ?? null ) ) && '' !== $this->wpdb->last_error ) {
            return null;
        }

        return $this->normalize_spend_row( $row );
    }

    /**
     * Get today's accrued spend for one user's bucket.
     *
     * Same fail-closed contract as get_spend_today(): null means the read
     * failed and a per-user cap must block rather than assume $0.00.
     *
     * @param int $user_id User whose bucket to read.
     *
     * @return array|null Row with cost_usd/prompt_tokens/completion_tokens/unpriced_turns/turns
     *                    (all zeros when nothing accrued today), or null on read failure.
     */
    public function get_user_spend_today( $user_id ) {
        $suppress = $this->wpdb->suppress_errors();
        $row      = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT cost_usd, prompt_tokens, completion_tokens, unpriced_turns, turns FROM %i WHERE spend_day = %s AND user_id = %d',
                $this->table_name( 'assistant_spend' ),
                current_time( 'Y-m-d' ),
                absint( $user_id )
            ),
            ARRAY_A
        );
        $this->wpdb->suppress_errors( $suppress );

        if ( null === $row && '' !== $this->wpdb->last_error ) {
            return null;
        }

        return $this->normalize_spend_row( $row );
    }

    /**
     * Normalize a spend row (or a no-rows result) into the zeros-filled shape.
     *
     * @param array|null $row Raw row from the spend read.
     *
     * @return array Row with cost_usd/prompt_tokens/completion_tokens/unpriced_turns/turns.
     */
    private function normalize_spend_row( $row ) {
        return array(
            'cost_usd'          => isset( $row['cost_usd'] ) ? (float) $row['cost_usd'] : 0.0,
            'prompt_tokens'     => isset( $row['prompt_tokens'] ) ? (int) $row['prompt_tokens'] : 0,
            'completion_tokens' => isset( $row['completion_tokens'] ) ? (int) $row['completion_tokens'] : 0,
            'unpriced_turns'    => isset( $row['unpriced_turns'] ) ? (int) $row['unpriced_turns'] : 0,
            'turns'             => isset( $row['turns'] ) ? (int) $row['turns'] : 0,
        );
    }

    // =========================================================================
    // CLEANUP METHODS
    // =========================================================================

    /**
     * Clean up old conversations based on retention period.
     *
     * @param int      $older_than_days Days to retain conversations.
     * @param int|null $user_id         User ID to clean up for.
     *
     * @return int|\WP_Error Number of conversations deleted.
     */
    public function cleanup_old_conversations( $older_than_days, $user_id = null ) {
        if ( null === $user_id ) {
            return new \WP_Error( 'missing_user', __( 'User context is required.', 'mainwp' ) );
        }

        $older_than_days = absint( $older_than_days );
        if ( $older_than_days <= 0 ) {
            return 0;
        }

        $cutoff  = time() - ( $older_than_days * DAY_IN_SECONDS );
        $user_id = absint( $user_id );

        $conversations_table = $this->table_name( 'assistant_conversations' );
        $messages_table      = $this->table_name( 'assistant_messages' );

        // Read the ids before the delete: the log scrub below needs to know
        // which conversations aged out, and after the delete there is nothing
        // left to ask.
        $expired_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT id FROM %i WHERE updated_at < %d AND user_id = %d',
                $conversations_table,
                $cutoff,
                $user_id
            )
        );

        // Delete messages for old conversations using a join.
        $messages_deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE m FROM %i AS m
                 INNER JOIN %i AS c ON m.conversation_id = c.id
                 WHERE c.updated_at < %d AND c.user_id = %d',
                $messages_table,
                $conversations_table,
                $cutoff,
                $user_id
            )
        );
        if ( false === $messages_deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversation messages.', 'mainwp' ) );
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE updated_at < %d AND user_id = %d',
                $conversations_table,
                $cutoff,
                $user_id
            )
        );

        if ( false === $deleted ) {
            return new \WP_Error( 'db_error', __( 'Failed to delete conversations.', 'mainwp' ) );
        }

        // Retention has to reach the prompt-derived copies too: the
        // conversation-start log rows carry the first prompt characters as
        // their title, and expiring the thread while the log keeps the title
        // is the same leak clearing history closes. Only run the scrub when
        // something actually expired: scrub_conversation_log_titles() treats
        // an empty id list as "scrub every title for this user", which would
        // blank the kept conversations' titles on a run with nothing expired.
        if ( $expired_ids ) {
            $this->scrub_conversation_log_titles( $user_id, $expired_ids );
        }

        $this->delete_orphaned_messages();

        return (int) $deleted;
    }

    /**
     * Delete messages whose conversation row is gone.
     *
     * Rows written before add_message() made its insert conditional (and any
     * left by a partially failed delete) are unreachable through
     * every reader while still holding the conversation text, so retention
     * sweeps them. The sweep is not per-user work and the retention cron
     * calls its caller once per user, so it is rate-limited to once a day.
     *
     * @return void
     */
    private function delete_orphaned_messages() {
        if ( (int) get_option( self::OPTION_ORPHAN_SWEEP, 0 ) > time() - DAY_IN_SECONDS ) {
            return;
        }
        update_option( self::OPTION_ORPHAN_SWEEP, time(), false );

        $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE m FROM %i AS m
                 LEFT JOIN %i AS c ON m.conversation_id = c.id
                 WHERE c.id IS NULL',
                $this->table_name( 'assistant_messages' ),
                $this->table_name( 'assistant_conversations' )
            )
        );
    }
}
