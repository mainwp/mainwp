<?php
/**
 * MainWP Backup Test-Restore Sandbox - the isolation primitive for a
 * Level-5 verified restore.
 *
 * A sandbox is a throwaway target the restore engine can apply staged
 * artifacts into without ever touching the live site: a uniquely named
 * scratch database opened over a dedicated connection (never the live
 * $wpdb), and a temp directory under the uploads folder. A SQL dump is
 * decompressed and replayed into the scratch database; file archives are
 * extracted into the temp directory; cheap assertions read back the result;
 * and tear-down drops the database and removes the directory. Tear-down is
 * idempotent and safe to call from a finally block on any outcome.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_TestRestore_Sandbox
 */
class MainWP_Backup_TestRestore_Sandbox { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // Every exception below carries an esc_html__()-wrapped user message plus a
    // debug detail and a stable error code; the latter two are for logging and
    // matching, so the sniff that wants every throw argument escaped does not
    // apply at the throw. probe_capability() is the one place a detail reaches an
    // operator, and the template that renders its reason escapes it there.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Error code for an environment that cannot host a sandbox (no
     * CREATE DATABASE privilege, or a missing decompression extension).
     * The driver records this as 'unsupported' rather than a restore fail.
     */
    const ERR_UNSUPPORTED = 'mainwp_sandbox_unsupported';

    /**
     * Bytes of a protection file a deny rule has to appear within. The read takes
     * two bytes more: the first tells a file that ends at the cap from one the
     * cap cut in half, the second is the LF of a CRLF whose CR lands on byte
     * 8193, without which a complete rule ending exactly at the cap is dropped.
     * A deny rule sits near the start of an .htaccess anyone writes; the cap is
     * what keeps a multi-megabyte file from the OOM fatal that leaks a scratch
     * database.
     */
    private const PROTECTION_READ_BYTES = 8192;

    /**
     * Error code for a backup whose bytes will not reconstitute (a dump
     * that fails to decompress or import, an unreadable archive).
     */
    const ERR_APPLY = 'mainwp_sandbox_apply_failed';

    /**
     * Scratch database name (mainwp_tr_<token>).
     *
     * @var string
     */
    private $db_name = '';

    /**
     * Sandbox temp directory (absolute, trailing slash).
     *
     * @var string
     */
    private $temp_dir = '';

    /**
     * Dedicated database connection, or null before bring-up / after tear-down.
     *
     * @var \mysqli|null
     */
    private $mysqli = null;

    /**
     * Whether the scratch database has been created (and so must be dropped).
     *
     * @var bool
     */
    private $created_db = false;

    /**
     * Mint the scratch database name and temp dir path. No connection or
     * filesystem work happens until bring_up().
     */
    public function __construct() {
        $token          = strtolower( preg_replace( '/[^a-z0-9]/i', '', wp_generate_password( 16, false ) ) );
        $token          = '' === $token ? 'x' . substr( (string) md5( uniqid( '', true ) ), 0, 12 ) : substr( $token, 0, 16 );
        $this->db_name  = 'mainwp_tr_' . $token;
        $upload         = wp_upload_dir();
        $this->temp_dir = trailingslashit( $upload['basedir'] ) . 'mainwp/test-restore/' . $token . '/';
    }

    /**
     * Scratch database name.
     *
     * @return string
     */
    public function db_name(): string {
        return $this->db_name;
    }

    /**
     * Sandbox temp directory (absolute, trailing slash).
     *
     * @return string
     */
    public function temp_dir(): string {
        return $this->temp_dir;
    }

    /**
     * Open the dedicated connection, create the scratch database, and create
     * the temp directory.
     *
     * @throws MainWP_Exception When the environment cannot host a sandbox.
     *
     * @return void
     */
    public function bring_up(): void {
        if ( ! class_exists( '\mysqli' ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the mysqli extension, which is not available.', 'mainwp' ), 'mysqli missing', self::ERR_UNSUPPORTED );
        }

        $this->mysqli = $this->connect();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.RestrictedClasses.mysql__mysqli_query -- self-generated alnum database name; low-level sandbox connection, not $wpdb.
        $created = @$this->mysqli->query( 'CREATE DATABASE `' . $this->db_name . '` CHARACTER SET utf8mb4' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- privilege probe; failure is handled below.
        if ( false === $created ) {
            $error = $this->mysqli->error;
            $this->close_connection();
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not create a scratch database. The dashboard database user needs the CREATE privilege.', 'mainwp' ), 'create database: ' . $error, self::ERR_UNSUPPORTED );
        }
        $this->created_db = true;
        $this->mysqli->select_db( $this->db_name );

        $this->protect_parent();

        if ( ! wp_mkdir_p( $this->temp_dir ) ) {
            $this->tear_down();
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not create its working directory.', 'mainwp' ), 'mkdir: ' . $this->temp_dir, self::ERR_UNSUPPORTED );
        }
    }

    /**
     * Keep extracted files and sibling archive aliases under the same deny rule.
     *
     * Four outcomes, one per matrix row in the sandbox test: write a missing or
     * permissive file the plugin owns, accept a path that already carries a deny
     * rule, skip an unreadable file or an existing index.php, or refuse a path the
     * plugin does not own (symlink, hard link, non-regular file) that does not deny.
     *
     * Refusal also makes probe_capability(), via bring_up(), report the host
     * incapable: the scheduler's ensure_capable() caches that for CAPABILITY_TTL,
     * so an unwritable uploads parent stops verified restores until expiry,
     * rather than marking just one snapshot as unable to test.
     *
     * @throws MainWP_Exception When the parent cannot deny access to backup data.
     *
     * @return void
     */
    private function protect_parent(): void {
        $parent = dirname( untrailingslashit( $this->temp_dir ) );
        wp_mkdir_p( $parent );
        $protection = array(
            '.htaccess' => 'deny from all',
            'index.php' => '',
        );
        foreach ( $protection as $name => $contents ) {
            $file    = $parent . '/' . $name;
            $is_link = is_link( $file );
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing protection file has no stat data.
            $stat    = @stat( $file );
            $exists  = file_exists( $file );
            $regular = is_file( $file );
            $why     = '';
            if ( $is_link ) {
                $why = 'is a symlink';
            } elseif ( $regular && false !== $stat && $stat['nlink'] > 1 ) {
                $why = 'has multiple hard links';
            } elseif ( $exists && ! $regular ) {
                $why = 'is not a regular file';
            }

            if ( 'index.php' === $name && ( $exists || $is_link ) ) {
                continue;
            }
            // The web server enforces protection; unreadability does not prove the rule is missing.
            if ( '.htaccess' === $name && $regular && ! is_readable( $file ) ) {
                continue;
            }
            // Accept foreign paths that already deny: refusal disables the whole lane for CAPABILITY_TTL.
            // Snapshot-style backups give even a legitimate .htaccess a link count above one.
            if ( '.htaccess' === $name && $this->has_protection_rule( $file ) ) {
                continue;
            }
            if ( '' !== $why ) {
                $this->tear_down();
                throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not protect its working directory.', 'mainwp' ), 'sandbox protection: ' . $file . ' ' . $why, self::ERR_UNSUPPORTED );
            }

            error_clear_last();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- verify deny-rule persistence below; index.php remains best effort.
            @file_put_contents( $file, $contents );
            $write_error = error_get_last();
            if ( '.htaccess' === $name && ! $this->has_protection_rule( $file ) ) {
                $error  = $write_error ?? error_get_last();
                $detail = 'sandbox protection: ' . $parent;
                if ( ! empty( $error['message'] ) ) {
                    $detail .= ': ' . $error['message'];
                }
                $this->tear_down();
                throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not protect its working directory.', 'mainwp' ), $detail, self::ERR_UNSUPPORTED );
            }
        }
    }

    /**
     * Whether a readable regular file carries a deny rule in its first 8 KB.
     *
     * The is_file() gate and the read cap are both load-bearing, for different
     * shapes: the gate is what keeps a FIFO from blocking the request in open(),
     * and the cap is what bounds a device or a fat regular file. Neither failure
     * is catchable, and both strand the scratch database bring_up() has already
     * created.
     *
     * A match has to be a complete logical line, read the way Apache reads
     * one. The read goes two bytes past the cap so a line the cap cut in half
     * can be dropped: `$` matches end of subject as well as end of line, so a
     * severed "Deny from all-granted" would otherwise read as a whole
     * "Deny from all". One extra byte was not enough: a complete rule whose
     * content ends at byte 8192 has its CRLF at 8193-8194, and a read that
     * stops at the CR finds no newline and drops a valid line.
     *
     * Apache joins a physical line ending in a backslash to the next one, and
     * the join inserts nothing: the backslash, any spaces or tabs after it and
     * the line ending disappear, whitespace on either side stays. Measured with
     * discriminators rather than inferred: `Order \\` + `Deny,Allow` reports
     * "unknown order" for the single token `\Deny,Allow` where an inserted
     * space would report "takes one argument", so a double backslash still
     * continues and leaves one literal; `Deny from all\` + `example.com` is
     * the host `allexample.com`, and `Deny from al\` + `l` denies everything.
     * A continued comment or `Allow` line swallows the deny that follows it,
     * which is what a hand-edit leaves behind, so the text is unfolded before
     * anything else and the sever trim runs on the unfolded text: a logical
     * line whose partner lies outside the window has no newline after it and
     * is dropped whatever its first half says. A backslash on the last line of
     * the file continues nothing and stays a literal token, which the unfold
     * pattern reproduces because it needs a line ending after the backslash.
     *
     * Apache dequotes every token, the directive name included, so
     * `Deny from "all"`, `'Deny' from all` and `Require "all" denied` are the
     * rules they look like (`Require "bogus" denied` fails on the provider
     * `bogus`, which only happens if the quotes are gone before the lookup).
     * Recognised tokens are separated by spaces or tabs and are either bare or
     * wholly quoted. A bare token keeps its quote characters (`al"l"` is a
     * host), a `#` anywhere in the arguments is "No comments are allowed here"
     * even inside quotes, and a quoted token glued to the next word or left
     * unterminated is not matched: Apache reads both, the conservative outcome
     * here is a rewrite of an owned file or a refusal of a foreign one.
     *
     * "Deny from all" takes further host arguments and still denies everything,
     * while a first argument of "all-granted" or "all.example.com" is a host
     * restriction that denies nothing. Apache 2.4's "Require all denied" is
     * accepted too, so the repair does not flatten an operator's 2.4 directive
     * into a 2.2 one.
     *
     * @param string $file Protection path.
     *
     * @return bool
     */
    private function has_protection_rule( string $file ): bool {
        if ( ! is_file( $file ) || ! is_readable( $file ) ) {
            return false;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local read; failure cannot establish a deny rule.
        $contents = @file_get_contents( $file, false, null, 0, self::PROTECTION_READ_BYTES + 2 );
        if ( ! is_string( $contents ) ) {
            return false;
        }
        $over     = strlen( $contents ) > self::PROTECTION_READ_BYTES;
        $contents = preg_replace( '/\\\\[ \t]*\r?\n/', '', $contents );
        if ( null === $contents ) {
            return false;
        }
        if ( $over ) {
            $last     = strrpos( $contents, "\n" );
            $contents = false === $last ? '' : substr( $contents, 0, $last );
        }
        return 1 === preg_match( '/^[ \t]*(?:(?:deny|"deny"|\'deny\')[ \t]+(?:from|"from"|\'from\')[ \t]+(?:all|"all"|\'all\')(?:[ \t]++[^#\s]++)*+|(?:require|"require"|\'require\')[ \t]+(?:all|"all"|\'all\')[ \t]+(?:denied|"denied"|\'denied\'))[ \t]*\r?$/mi', $contents );
    }

    /**
     * Decompress and replay a SQL dump into the scratch database.
     *
     * @param string $dump_path Staged dump artifact (raw .sql or compressed).
     *
     * @throws MainWP_Exception On a missing extension (unsupported) or a dump
     *                          that will not decompress or import (apply).
     *
     * @return void
     */
    public function import_database( string $dump_path ): void {
        $sql = $this->read_sql_dump( $dump_path );
        if ( '' === trim( $sql ) ) {
            throw new MainWP_Exception( esc_html__( 'The backup database dump was empty after decompression.', 'mainwp' ), 'empty dump: ' . basename( $dump_path ), self::ERR_APPLY );
        }

        try {
            // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli_multi_query, WordPress.DB.PreparedSQL.NotPrepared -- replaying a backup dump into the isolated scratch database, not $wpdb.
            $started = $this->mysqli->multi_query( $sql );
            if ( $started ) {
                do {
                    $result = $this->mysqli->store_result();
                    if ( $result instanceof \mysqli_result ) {
                        $result->free();
                    }
                } while ( $this->mysqli->more_results() && $this->mysqli->next_result() );
            }
            if ( false === $started || 0 !== $this->mysqli->errno ) {
                throw new MainWP_Exception( esc_html__( 'The backup database dump did not import cleanly.', 'mainwp' ), 'import: ' . $this->mysqli->error, self::ERR_APPLY );
            }
        } catch ( \mysqli_sql_exception $e ) {
            throw new MainWP_Exception( esc_html__( 'The backup database dump did not import cleanly.', 'mainwp' ), 'import: ' . $e->getMessage(), self::ERR_APPLY );
        }
    }

    /**
     * Extract a file archive into the sandbox temp directory.
     *
     * @param string $archive_path Staged file archive (tar/zip family or a
     *                             single gzipped file).
     *
     * @throws MainWP_Exception On a missing extractor (unsupported) or an
     *                          unreadable archive (apply).
     *
     * @return void
     */
    public function extract_files( string $archive_path ): void {
        $ext = MainWP_Backup_File_Manager::get_real_extension( $archive_path );

        if ( '.zip' === $ext || '.sql.zip' === $ext ) {
            $this->extract_zip( $archive_path );
            return;
        }

        if ( '.gz' === $ext || '.bz2' === $ext ) {
            // A lone compressed file rather than a tar container.
            $bytes = $this->decompress_bytes( $archive_path, $ext );
            $name  = preg_replace( '/\.(gz|bz2)$/', '', basename( $archive_path ) );
            $this->write_temp_file( $name, $bytes );
            return;
        }

        $this->extract_tar( $archive_path );
    }

    /**
     * Number of tables in the scratch database.
     *
     * @return int
     */
    public function table_count(): int {
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli_query, WordPress.DB.PreparedSQL.NotPrepared -- scratch-database introspection, not $wpdb.
        $result = $this->mysqli->query( 'SHOW TABLES' );
        if ( ! ( $result instanceof \mysqli_result ) ) {
            return 0;
        }
        $count = (int) $result->num_rows;
        $result->free();
        return $count;
    }

    /**
     * Whether at least one table in the scratch database holds a row. Stops at
     * the first non-empty table so a large import stays cheap.
     *
     * @return bool
     */
    public function has_table_rows(): bool {
        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli_query, WordPress.DB.PreparedSQL.NotPrepared -- scratch-database introspection, not $wpdb.
        $tables = $this->mysqli->query( 'SHOW TABLES' );
        if ( ! ( $tables instanceof \mysqli_result ) ) {
            return false;
        }

        $found = false;
        while ( $row = $tables->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- standard mysqli row walk.
            // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli_query, WordPress.DB.PreparedSQL.NotPrepared -- table name from SHOW TABLES on the scratch database.
            $count = $this->mysqli->query( 'SELECT COUNT(*) FROM `' . $row[0] . '`' );
            if ( $count instanceof \mysqli_result ) {
                $value = $count->fetch_row();
                $count->free();
                if ( null !== $value && (int) $value[0] > 0 ) {
                    $found = true;
                    break;
                }
            }
        }
        $tables->free();
        return $found;
    }

    /**
     * Number of regular files extracted into the temp directory.
     *
     * @return int
     */
    public function temp_file_count(): int {
        if ( ! is_dir( $this->temp_dir ) ) {
            return 0;
        }
        $count    = 0;
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->temp_dir, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Checksum of a sentinel file extracted into the temp directory.
     *
     * @param string $relative Path relative to the temp directory.
     *
     * @return string sha256 hex, or '' when the file is absent.
     */
    public function sentinel_sha256( string $relative ): string {
        $path = $this->temp_dir . ltrim( $relative, '/' );
        if ( ! is_file( $path ) ) {
            return '';
        }
        return (string) hash_file( 'sha256', $path );
    }

    /**
     * Drop the scratch database and remove the temp directory. Idempotent.
     *
     * @return void
     */
    public function tear_down(): void {
        if ( $this->mysqli instanceof \mysqli ) {
            if ( $this->created_db ) {
                // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli_query, WordPress.DB.PreparedSQL.NotPrepared, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort drop of the self-named scratch database.
                @$this->mysqli->query( 'DROP DATABASE IF EXISTS `' . $this->db_name . '`' );
                $this->created_db = false;
            }
            $this->close_connection();
        }

        if ( '' !== $this->temp_dir && is_dir( $this->temp_dir ) ) {
            $this->remove_dir( $this->temp_dir );
        }
        $alias_dir = untrailingslashit( $this->temp_dir ) . '-phar/';
        if ( '' !== $this->temp_dir && is_dir( $alias_dir ) ) {
            $this->remove_dir( $alias_dir );
        }
    }

    /**
     * Probe whether this host can host a sandbox at all.
     *
     * Brings up and immediately tears down a throwaway sandbox, so the verdict
     * reflects the exact CREATE-database privilege (and decompression
     * extension) checks a real run hits rather than a guess. The sampler caches
     * this so a host the dashboard database user cannot create on - common on
     * managed hosting - is skipped once instead of retried on every snapshot.
     *
     * @return array{capable:bool,reason:string} Whether a sandbox can be hosted,
     *                                            and the operator-facing reason
     *                                            when it cannot.
     */
    public static function probe_capability(): array {
        $sandbox = new self();
        try {
            $sandbox->bring_up();
            return array(
                'capable' => true,
                'reason'  => '',
            );
        } catch ( \Throwable $e ) {
            // The reason is cached for CAPABILITY_TTL and shown to the operator, so it
            // carries the debug detail: without it a month-long lockout says only that
            // the working directory could not be protected, naming neither the path nor
            // which shape refused. The one surfacing template escapes what it renders.
            $detail = $e instanceof MainWP_Exception ? trim( (string) $e->get_message_extra( false ) ) : '';
            return array(
                'capable' => false,
                'reason'  => '' === $detail ? $e->getMessage() : $e->getMessage() . ' (' . $detail . ')',
            );
        } finally {
            $sandbox->tear_down();
        }
    }

    /**
     * Open the dedicated connection to the same server $wpdb uses.
     *
     * @throws MainWP_Exception When the connection cannot be opened.
     *
     * @return \mysqli
     */
    private function connect(): \mysqli {
        list( $host, $port, $socket ) = $this->parse_host( (string) ( defined( 'DB_HOST' ) ? DB_HOST : 'localhost' ) );

        $mysqli = mysqli_init(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- dedicated sandbox connection, never the live $wpdb.
        if ( ! ( $mysqli instanceof \mysqli ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not initialise a database connection.', 'mainwp' ), 'mysqli_init failed', self::ERR_UNSUPPORTED );
        }

        try {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- sandbox connection; failure is handled via the return value below.
            $ok = @mysqli_real_connect( $mysqli, $host, defined( 'DB_USER' ) ? DB_USER : '', defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '', '', $port, (string) $socket );
        } catch ( \mysqli_sql_exception $e ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not connect to the database server.', 'mainwp' ), 'connect: ' . $e->getMessage(), self::ERR_UNSUPPORTED );
        }

        if ( false === $ok ) {
            $detail = mysqli_connect_error(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- reading the sandbox connection error string for the log.
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not connect to the database server.', 'mainwp' ), 'connect: ' . $detail, self::ERR_UNSUPPORTED );
        }

        return $mysqli;
    }

    /**
     * Split a WordPress DB_HOST into host, port, and socket. Handles the
     * common forms: host, host:port, host:/socket, :/socket, host:port:/socket.
     * The socket path may contain spaces, so only the first colon is split.
     *
     * @param string $db_host DB_HOST value.
     *
     * @return array{0:string,1:int,2:string|null}
     */
    private function parse_host( string $db_host ): array {
        $host   = $db_host;
        $port   = 0;
        $socket = null;

        $colon = strpos( $db_host, ':' );
        if ( false !== $colon ) {
            $host = substr( $db_host, 0, $colon );
            $rest = substr( $db_host, $colon + 1 );

            if ( '' !== $rest && '/' === $rest[0] ) {
                $socket = $rest;
            } elseif ( ctype_digit( $rest ) ) {
                $port = (int) $rest;
            } else {
                $inner = strpos( $rest, ':' );
                if ( false !== $inner ) {
                    $maybe_port   = substr( $rest, 0, $inner );
                    $maybe_socket = substr( $rest, $inner + 1 );
                    if ( ctype_digit( $maybe_port ) ) {
                        $port = (int) $maybe_port;
                    }
                    if ( '' !== $maybe_socket && '/' === $maybe_socket[0] ) {
                        $socket = $maybe_socket;
                    }
                }
            }
        }

        if ( '' === $host ) {
            $host = 'localhost';
        }

        return array( $host, $port, $socket );
    }

    /**
     * Decompress and return the SQL body of a dump artifact.
     *
     * @param string $path Dump path.
     *
     * @throws MainWP_Exception On a missing extension or an unreadable dump.
     *
     * @return string
     */
    private function read_sql_dump( string $path ): string {
        $ext = MainWP_Backup_File_Manager::get_real_extension( $path );

        if ( '.sql' === $ext ) {
            return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local staged artifact.
        }

        if ( '.gz' === $ext || '.bz2' === $ext ) {
            return $this->decompress_bytes( $path, $ext );
        }

        if ( '.zip' === $ext || '.sql.zip' === $ext ) {
            return $this->read_member_from_zip( $path, '.sql' );
        }

        return $this->read_member_from_tar( $path, '.sql' );
    }

    /**
     * Measure the decompressed byte size of the SQL body read_sql_dump() would
     * load into memory, without buffering the whole dump. import_database()
     * replays the entire decompressed dump from a single in-memory string, so
     * the size that matters for the memory cap is the decompressed size, per
     * format:
     *
     * - .sql   : the file itself (already plain).
     * - .gz    : the stream is decompressed in bounded chunks and counted,
     *            stopping the moment the running total passes the ceiling
     *            (never the whole body at once). The 32-bit ISIZE trailer is
     *            not trusted: it wraps modulo 2^32 for bodies over 4 GB and
     *            would read far too small for the largest, most compressible
     *            dumps this cap exists to refuse.
     * - .bz2   : bzip2 has no size field, so the stream is decompressed in
     *            bounded chunks and counted, stopping the moment the running
     *            total passes the ceiling (never the whole body at once).
     * - zip    : the central directory's uncompressed-size field (no decode).
     * - tar    : the member header's size field after compressed tars are
     *            decompressed to disk and long names are aliased there; the
     *            extra cost is disk space, not whole-dump memory.
     *
     * @param string $path    Staged dump artifact.
     * @param int    $ceiling Size above which the caller will refuse the dump;
     *                        streaming formats stop counting once past it.
     *
     * @throws MainWP_Exception When archive decompression or alias preparation fails.
     *
     * @return int Decompressed size in bytes, or a value above $ceiling when it
     *             cannot be trusted or streaming stopped early.
     */
    public function decompressed_sql_dump_bytes( string $path, int $ceiling ): int {
        $ext = MainWP_Backup_File_Manager::get_real_extension( $path );

        if ( '.sql' === $ext ) {
            return (int) filesize( $path );
        }
        if ( '.gz' === $ext ) {
            return $this->gzip_decompressed_size( $path, $ceiling );
        }
        if ( '.bz2' === $ext ) {
            return $this->bz2_decompressed_size( $path, $ceiling );
        }
        if ( '.zip' === $ext || '.sql.zip' === $ext ) {
            return $this->archive_member_size_zip( $path, '.sql' );
        }
        return $this->archive_member_size_tar( $path, '.sql' );
    }

    /**
     * Count a lone gzip file's decompressed size in bounded chunks, stopping
     * the moment the running total passes the ceiling.
     *
     * The 32-bit ISIZE trailer is deliberately not used: it wraps modulo 2^32
     * once the decompressed body reaches 4 GB, so a highly compressible dump
     * that inflates past 4 GB while its compressed footprint stays in the
     * megabytes reports a small ISIZE and would slip under the cap - exactly
     * the out-of-memory case the cap exists to refuse. Counting the stream is
     * immune to that. A gzip whose compressed bytes already exceed the ceiling
     * is refused up front, since read_sql_dump() loads the compressed file
     * whole before gzdecode() and that alone busts the budget.
     *
     * @param string $path    Gzip path.
     * @param int    $ceiling Refusal ceiling; counting stops once past it.
     *
     * @return int Decompressed bytes counted, or a value above $ceiling when
     *             the compressed footprint alone already exceeds it, or zero
     *             when zlib is unavailable (read_sql_dump then raises
     *             ERR_UNSUPPORTED before import).
     */
    private function gzip_decompressed_size( string $path, int $ceiling ): int {
        // Loading even the compressed bytes into memory (read_sql_dump does,
        // before gzdecode) already busts the budget, so refuse on that alone.
        if ( (int) filesize( $path ) > $ceiling ) {
            return $ceiling + 1;
        }

        if ( ! function_exists( 'gzopen' ) ) {
            return 0;
        }
        $gz = gzopen( $path, 'rb' );
        if ( false === $gz ) {
            return 0;
        }

        $total = 0;
        while ( true ) {
            $chunk = gzread( $gz, 1048576 );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            $total += strlen( $chunk );
            if ( $total > $ceiling ) {
                break;
            }
        }
        gzclose( $gz );
        return $total;
    }

    /**
     * Count a bzip2 stream's decompressed size in bounded chunks, stopping the
     * moment the running total passes the ceiling.
     *
     * @param string $path    Bzip2 path.
     * @param int    $ceiling Refusal ceiling; counting stops once past it.
     *
     * @return int Decompressed bytes counted, or zero when bzip2 is unavailable
     *             (read_sql_dump then raises ERR_UNSUPPORTED before import).
     */
    private function bz2_decompressed_size( string $path, int $ceiling ): int {
        if ( ! function_exists( 'bzopen' ) ) {
            return 0;
        }
        $bz = bzopen( $path, 'r' );
        if ( ! is_resource( $bz ) ) {
            return 0;
        }

        $total = 0;
        while ( true ) {
            $chunk = bzread( $bz, 1048576 );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            $total += strlen( $chunk );
            if ( $total > $ceiling ) {
                break;
            }
        }
        bzclose( $bz );
        return $total;
    }

    /**
     * Read the uncompressed size of the first matching zip member from the
     * central directory, without decompressing it.
     *
     * @param string $path   Zip path.
     * @param string $suffix Member suffix to match (e.g. '.sql').
     *
     * @return int Uncompressed size, or zero when unreadable (read_sql_dump
     *             surfaces the real error at import time).
     */
    private function archive_member_size_zip( string $path, string $suffix ): int {
        if ( ! class_exists( '\ZipArchive' ) ) {
            return 0;
        }
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return 0;
        }

        $size = 0;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( MainWP_Utility::ends_with( $name, $suffix ) ) {
                $stat = $zip->statIndex( $i );
                $size = ( is_array( $stat ) && isset( $stat['size'] ) ) ? (int) $stat['size'] : 0;
                break;
            }
        }
        $zip->close();
        return $size;
    }

    /**
     * Read the uncompressed size of the first matching tar member from its
     * header, decompressing compressed tars to disk first.
     *
     * @param string $path   Tar path (tar/tar.gz/tar.bz2).
     * @param string $suffix Member suffix to match (e.g. '.sql').
     *
     * @throws MainWP_Exception When the host cannot prepare a readable alias.
     *
     * @return int Member size, or zero for an unreadable archive (read_sql_dump
     *             surfaces the archive error at import time).
     */
    private function archive_member_size_tar( string $path, string $suffix ): int {
        if ( ! class_exists( '\PharData' ) ) {
            return 0;
        }
        $readable = $this->phar_readable_path( $path );
        try {
            $phar     = new \PharData( $readable );
            $iterator = new \RecursiveIteratorIterator( $phar );
            foreach ( $iterator as $file ) {
                if ( MainWP_Utility::ends_with( $file->getFilename(), $suffix ) ) {
                    return (int) $file->getSize();
                }
            }
        } catch ( \Throwable $e ) {
            return 0;
        }
        return 0;
    }

    /**
     * Decompress a lone gzip or bzip2 file into memory.
     *
     * @param string $path Path.
     * @param string $ext  Real extension ('.gz' or '.bz2').
     *
     * @throws MainWP_Exception On a missing extension or corrupt stream.
     *
     * @return string
     */
    private function decompress_bytes( string $path, string $ext ): string {
        $raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local staged artifact.

        if ( '.gz' === $ext ) {
            if ( ! function_exists( 'gzdecode' ) ) {
                throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the zlib extension to read this backup.', 'mainwp' ), 'gzdecode missing', self::ERR_UNSUPPORTED );
            }
            $out = gzdecode( $raw );
        } else {
            if ( ! function_exists( 'bzdecompress' ) ) {
                throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the bzip2 extension to read this backup.', 'mainwp' ), 'bzdecompress missing', self::ERR_UNSUPPORTED );
            }
            $out = bzdecompress( $raw );
        }

        if ( ! is_string( $out ) ) {
            throw new MainWP_Exception( esc_html__( 'A backup file could not be decompressed.', 'mainwp' ), 'decompress failed: ' . basename( $path ), self::ERR_APPLY );
        }
        return $out;
    }

    /**
     * Read the first member matching a suffix from a zip archive.
     *
     * @param string $path   Zip path.
     * @param string $suffix File suffix to match (e.g. '.sql').
     *
     * @throws MainWP_Exception On a missing extension or unreadable archive.
     *
     * @return string
     */
    private function read_member_from_zip( string $path, string $suffix ): string {
        if ( ! class_exists( '\ZipArchive' ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the zip extension to read this backup.', 'mainwp' ), 'ZipArchive missing', self::ERR_UNSUPPORTED );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            throw new MainWP_Exception( esc_html__( 'A backup archive could not be opened.', 'mainwp' ), 'zip open: ' . basename( $path ), self::ERR_APPLY );
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( MainWP_Utility::ends_with( $name, $suffix ) ) {
                $body = $zip->getFromIndex( $i );
                $zip->close();
                if ( false === $body ) {
                    throw new MainWP_Exception( esc_html__( 'A backup archive member could not be read.', 'mainwp' ), 'zip read: ' . $name, self::ERR_APPLY );
                }
                return (string) $body;
            }
        }

        $zip->close();
        throw new MainWP_Exception( esc_html__( 'The backup archive did not contain a database dump.', 'mainwp' ), 'no ' . $suffix . ' in ' . basename( $path ), self::ERR_APPLY );
    }

    /**
     * Read the first member matching a suffix from a tar archive.
     *
     * @param string $path   Tar path (tar/tar.gz/tar.bz2).
     * @param string $suffix File suffix to match (e.g. '.sql').
     *
     * @throws MainWP_Exception On a missing extension or unreadable archive.
     *
     * @return string
     */
    private function read_member_from_tar( string $path, string $suffix ): string {
        if ( ! class_exists( '\PharData' ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the phar extension to read this backup.', 'mainwp' ), 'PharData missing', self::ERR_UNSUPPORTED );
        }

        $readable = $this->phar_readable_path( $path );
        try {
            $phar     = new \PharData( $readable );
            $iterator = new \RecursiveIteratorIterator( $phar );
            foreach ( $iterator as $file ) {
                if ( MainWP_Utility::ends_with( $file->getFilename(), $suffix ) ) {
                    return (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a phar stream member.
                }
            }
        } catch ( \Throwable $e ) {
            throw new MainWP_Exception( esc_html__( 'A backup archive could not be opened.', 'mainwp' ), 'tar open: ' . $e->getMessage(), self::ERR_APPLY );
        }

        throw new MainWP_Exception( esc_html__( 'The backup archive did not contain a database dump.', 'mainwp' ), 'no ' . $suffix . ' in ' . basename( $path ), self::ERR_APPLY );
    }

    /**
     * Extract a zip archive into the temp directory.
     *
     * @param string $path Zip path.
     *
     * @throws MainWP_Exception On a missing extension or unreadable archive.
     *
     * @return void
     */
    private function extract_zip( string $path ): void {
        if ( ! class_exists( '\ZipArchive' ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the zip extension to read this backup.', 'mainwp' ), 'ZipArchive missing', self::ERR_UNSUPPORTED );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            throw new MainWP_Exception( esc_html__( 'A backup archive could not be opened.', 'mainwp' ), 'zip open: ' . basename( $path ), self::ERR_APPLY );
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( $this->has_unsafe_entry_name( $name ) ) {
                $zip->close();
                throw new MainWP_Exception( esc_html__( 'A backup archive contains an unsafe file path and cannot be extracted.', 'mainwp' ), 'zip traversal entry: ' . $name, self::ERR_APPLY );
            }
        }

        $zip->extractTo( untrailingslashit( $this->temp_dir ) );
        $zip->close();
    }

    /**
     * Extract a tar archive into the temp directory.
     *
     * @param string $path Tar path (tar/tar.gz/tar.bz2).
     *
     * @throws MainWP_Exception On a missing extension or unreadable archive.
     *
     * @return void
     */
    private function extract_tar( string $path ): void {
        if ( ! class_exists( '\PharData' ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox needs the phar extension to read this backup.', 'mainwp' ), 'PharData missing', self::ERR_UNSUPPORTED );
        }

        $readable = $this->phar_readable_path( $path );
        try {
            $phar     = new \PharData( $readable );
            $iterator = new \RecursiveIteratorIterator( $phar );
            foreach ( $iterator as $file ) {
                $name = $iterator->getSubPathname();
                if ( $this->has_unsafe_entry_name( $name ) ) {
                    throw new MainWP_Exception( esc_html__( 'A backup archive contains an unsafe file path and cannot be extracted.', 'mainwp' ), 'tar traversal entry: ' . $name, self::ERR_APPLY );
                }
            }
            $phar->extractTo( untrailingslashit( $this->temp_dir ), null, true );
        } catch ( \Throwable $e ) {
            throw new MainWP_Exception( esc_html__( 'A backup archive could not be extracted.', 'mainwp' ), 'tar extract: ' . $e->getMessage(), self::ERR_APPLY );
        }
    }

    /**
     * Give PharData a short, uncompressed tar path it can read reliably.
     *
     * @param string $path Staged archive path.
     *
     * @throws MainWP_Exception When the host cannot prepare a readable alias.
     *
     * @return string Readable archive path.
     */
    private function phar_readable_path( string $path ): string {
        $basename = basename( $path );
        $gzip     = MainWP_Utility::ends_with( strtolower( $basename ), '.tar.gz' );
        $bzip     = MainWP_Utility::ends_with( strtolower( $basename ), '.tar.bz2' );
        $dot      = strpos( $basename, '.' );

        // Phar scans from the first dot and refuses a tail of 50 or more bytes.
        if ( ! $gzip && ! $bzip && ( ! MainWP_Utility::ends_with( strtolower( $basename ), '.tar' ) || false === $dot || strlen( $basename ) - $dot < 50 ) ) {
            return $path;
        }

        // An alias inside temp_dir would count as a successfully extracted file.
        $alias_dir     = untrailingslashit( $this->temp_dir ) . '-phar/';
        $resolved_path = realpath( $path );
        $alias_key     = wp_normalize_path( false === $resolved_path ? $path : $resolved_path );
        $alias         = $alias_dir . substr( sha1( $alias_key ), 0, 12 ) . '.tar';
        if ( is_file( $alias ) && filesize( $alias ) > 0 ) {
            return $alias;
        }
        $this->protect_parent();
        if ( ! wp_mkdir_p( $alias_dir ) ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not create its archive directory.', 'mainwp' ), 'tar alias mkdir: ' . $basename, self::ERR_UNSUPPORTED );
        }

        // Budget each slab separately so decompression leaves the reserve for other uploads.
        $space_reserve = 64 * MB_IN_BYTES;
        $space_slab    = 64 * MB_IN_BYTES;
        if ( ! $gzip && ! $bzip ) {
            if ( @link( $path, $alias ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesystems without hard links use the copy fallback.
                return $alias;
            }
            $free = function_exists( 'disk_free_space' ) ? disk_free_space( $alias_dir ) : false;
            if ( is_float( $free ) && $free < filesize( $path ) + $space_reserve ) {
                throw new MainWP_Exception( esc_html__( 'There is not enough free space to test this backup here.', 'mainwp' ), 'tar alias free space: ' . $basename, self::ERR_UNSUPPORTED );
            }
            if ( copy( $path, $alias ) ) {
                return $alias;
            }
            wp_delete_file( $alias );
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not copy this archive.', 'mainwp' ), 'tar alias copy: ' . $basename, self::ERR_UNSUPPORTED );
        }

        // PHP 8.4 can return empty entry streams from compressed PharData tars.
        $open  = $gzip ? 'gzopen' : 'bzopen';
        $read  = $gzip ? 'gzread' : 'bzread';
        $close = $gzip ? 'gzclose' : 'bzclose';
        if ( ! function_exists( $open ) || ! function_exists( $read ) || ! function_exists( $close ) ) {
            throw new MainWP_Exception( $gzip ? esc_html__( 'The test-restore sandbox needs the zlib extension to read this backup.', 'mainwp' ) : esc_html__( 'The test-restore sandbox needs the bzip2 extension to read this backup.', 'mainwp' ), 'tar decompression extension missing: ' . $basename, self::ERR_UNSUPPORTED );
        }
        $free = function_exists( 'disk_free_space' ) ? disk_free_space( $alias_dir ) : false;
        if ( is_float( $free ) && $free < $space_reserve + $space_slab ) {
            throw new MainWP_Exception( esc_html__( 'There is not enough free space to test this backup here.', 'mainwp' ), 'tar alias free space: ' . $basename, self::ERR_UNSUPPORTED );
        }
        $in = $open( $path, 'r' );
        if ( false === $in ) {
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not read this compressed archive.', 'mainwp' ), 'tar alias read: ' . $basename, self::ERR_UNSUPPORTED );
        }
        $out = fopen( $alias . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming decompression keeps archive bytes out of memory.
        if ( false === $out ) {
            $close( $in );
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not write its decompressed archive.', 'mainwp' ), 'tar alias write: ' . $basename, self::ERR_UNSUPPORTED );
        }

        $complete         = false;
        $written          = 0;
        $next_space_check = $space_slab;
        try {
            while ( true ) {
                // Cap the read to the slab remainder so no checkpoint can be stepped over.
                $chunk = $read( $in, min( 262144, $next_space_check - $written ) );
                if ( false === $chunk ) {
                    throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not decompress this archive.', 'mainwp' ), 'tar alias decompress: ' . $basename, self::ERR_APPLY );
                }
                if ( '' === $chunk ) {
                    break;
                }
                if ( strlen( $chunk ) !== fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming decompression.
                    throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not write its decompressed archive.', 'mainwp' ), 'tar alias write: ' . $basename, self::ERR_UNSUPPORTED );
                }
                $written += strlen( $chunk );
                if ( $written >= $next_space_check ) {
                    $free = function_exists( 'disk_free_space' ) ? disk_free_space( $alias_dir ) : false;
                    if ( is_float( $free ) && $free < $space_reserve + $space_slab ) {
                        throw new MainWP_Exception( esc_html__( 'There is not enough free space to test this backup here.', 'mainwp' ), 'tar alias free space: ' . $basename, self::ERR_UNSUPPORTED );
                    }
                    $next_space_check += $space_slab;
                }
            }
            $complete = true;
        } finally {
            $close( $in );
            fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with the decompression output stream.
            if ( ! $complete ) {
                wp_delete_file( $alias . '.tmp' );
            }
        }

        if ( ! rename( $alias . '.tmp', $alias ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- only complete decompression results may be reused.
            wp_delete_file( $alias . '.tmp' );
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not finish preparing this archive.', 'mainwp' ), 'tar alias rename: ' . $basename, self::ERR_UNSUPPORTED );
        }
        return $alias;
    }

    /**
     * Whether an archive entry name is unsafe to extract: a parent-directory
     * traversal segment, an absolute path, or a stream wrapper. PHP's own
     * ZipArchive/PharData extractors already neutralise these on 7.4+; this
     * is a cheap defense-in-depth check at the name level, refusing the
     * whole archive rather than silently skipping the entry.
     *
     * @param string $name Archive entry name.
     *
     * @return bool
     */
    private function has_unsafe_entry_name( string $name ): bool {
        if ( '' === $name ) {
            return false;
        }
        if ( false !== strpos( $name, '..' ) ) {
            return true;
        }
        if ( false !== strpos( $name, '://' ) ) {
            return true;
        }
        $normalized = str_replace( '\\', '/', $name );
        if ( '/' === $normalized[0] ) {
            return true;
        }
        // Windows drive letter (e.g. C:/...).
        if ( 1 === preg_match( '#^[a-zA-Z]:#', $normalized ) ) {
            return true;
        }
        return false;
    }

    /**
     * Write a single decompressed file into the temp directory.
     *
     * @param string $name  Basename.
     * @param string $bytes Contents.
     *
     * @return void
     */
    private function write_temp_file( string $name, string $bytes ): void {
        $target = $this->temp_dir . sanitize_file_name( $name );
        file_put_contents( $target, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing into the sandbox temp directory.
    }

    /**
     * Close the dedicated connection.
     *
     * @return void
     */
    private function close_connection(): void {
        if ( $this->mysqli instanceof \mysqli ) {
            @$this->mysqli->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- closing a possibly-broken connection.
            $this->mysqli = null;
        }
    }

    /**
     * Recursively remove a directory tree.
     *
     * @param string $dir Directory.
     *
     * @return void
     */
    private function remove_dir( string $dir ): void {
        $entries = scandir( $dir );
        if ( false === $entries ) {
            return;
        }
        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $path = $dir . $entry;
            if ( is_dir( $path ) ) {
                $this->remove_dir( trailingslashit( $path ) );
            } else {
                wp_delete_file( $path );
            }
        }
        @rmdir( untrailingslashit( $dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort removal of the now-empty sandbox temp directory.
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
