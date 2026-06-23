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
    // matching, never echoed, so the sniff that wants every throw argument
    // escaped does not apply.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Error code for an environment that cannot host a sandbox (no
     * CREATE DATABASE privilege, or a missing decompression extension).
     * The driver records this as 'unsupported' rather than a restore fail.
     */
    const ERR_UNSUPPORTED = 'mainwp_sandbox_unsupported';

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

        if ( ! wp_mkdir_p( $this->temp_dir ) ) {
            $this->tear_down();
            throw new MainWP_Exception( esc_html__( 'The test-restore sandbox could not create its working directory.', 'mainwp' ), 'mkdir: ' . $this->temp_dir, self::ERR_UNSUPPORTED );
        }
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

        try {
            $phar     = new \PharData( $path );
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

        try {
            $phar = new \PharData( $path );
            $phar->extractTo( untrailingslashit( $this->temp_dir ), null, true );
        } catch ( \Throwable $e ) {
            throw new MainWP_Exception( esc_html__( 'A backup archive could not be extracted.', 'mainwp' ), 'tar extract: ' . $e->getMessage(), self::ERR_APPLY );
        }
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
