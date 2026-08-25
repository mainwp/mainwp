<?php
/**
 * Result-bearing key-file deletion contract tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Hooks;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Minimal filesystem double for a checked deletion failure.
 */
class MainWP_Key_File_Result_Filesystem {

    /** @var bool */
    public $present;

    /** @var bool */
    private $delete_succeeds;

    /**
     * Configure the isolated file state.
     *
     * @param bool $present         Whether the fixture exists.
     * @param bool $delete_succeeds Whether deletion succeeds.
     */
    public function __construct( $present, $delete_succeeds ) {
        $this->present         = $present;
        $this->delete_succeeds = $delete_succeeds;
    }

    /**
     * Report that the requested file remains present.
     *
     * @param string $path File path.
     * @return bool
     */
    public function exists( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filesystem contract requires the path.
        return 0 === strpos( basename( $path ), 'fathom_result_test_' ) ? $this->present : true;
    }

    /**
     * Refuse the deletion.
     *
     * @param string $path File path.
     * @return bool
     */
    public function delete( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filesystem contract requires the path.
        if ( $this->delete_succeeds ) {
            $this->present = false;
            return true;
        }
        return false;
    }

    /** @return bool */
    public function mkdir() {
        return true;
    }

    /** @return bool */
    public function touch() {
        return true;
    }

    /** @return bool */
    public function put_contents() {
        return true;
    }
}

/**
 * Verify the public result filter returns only observed deletion truth.
 */
class Test_Key_File_Deletion_Result extends \WP_UnitTestCase {

    /** @var mixed */
    private $prior_filesystem;

    /** @var string */
    private $file_key = '';

    /**
     * Preserve the shared filesystem and install one isolated filesystem.
     */
    public function setUp(): void {
        parent::setUp();

        global $wp_filesystem;

        $this->prior_filesystem = $wp_filesystem;
        $wp_filesystem          = new MainWP_Key_File_Result_Filesystem( true, true );
        $this->file_key         = 'fathom_result_test_' . wp_generate_uuid4();
    }

    /**
     * Restore the shared filesystem.
     */
    public function tearDown(): void {
        global $wp_filesystem;

        $wp_filesystem = $this->prior_filesystem;

        parent::tearDown();
    }

    /**
     * The Dashboard publishes the result-bearing compatibility filter.
     */
    public function test_result_filter_is_registered() {
        $this->assertNotFalse(
            has_filter( 'mainwp_delete_key_file_result', array( MainWP_Hooks::get_instance(), 'hook_delete_key_file_result' ) )
        );
    }

    /**
     * Successful deletion and exact absence are both idempotent successes.
     */
    public function test_filter_deletes_and_read_verifies_exact_file() {
        global $wp_filesystem;

        $this->assertTrue( $wp_filesystem->present );
        $this->assertTrue( apply_filters( 'mainwp_delete_key_file_result', null, $this->file_key ) );
        $this->assertFalse( $wp_filesystem->present );
        $this->assertTrue( apply_filters( 'mainwp_delete_key_file_result', null, $this->file_key ) );
    }

    /**
     * Unsafe names never escape the private key directory.
     */
    public function test_filter_rejects_noncanonical_key_names() {
        $this->assertFalse( apply_filters( 'mainwp_delete_key_file_result', null, '../' . $this->file_key ) );
        $this->assertFalse( apply_filters( 'mainwp_delete_key_file_result', null, '' ) );
    }

    /**
     * A failed delete with a surviving file returns false.
     */
    public function test_filter_fails_when_absence_cannot_be_proved() {
        global $wp_filesystem;

        $wp_filesystem = new MainWP_Key_File_Result_Filesystem( true, false );

        $this->assertFalse( apply_filters( 'mainwp_delete_key_file_result', null, $this->file_key ) );
    }

    /**
     * A dangling symlink fails exists() yet still occupies the key path; it must be removed, not reported absent.
     */
    public function test_filter_removes_dangling_symlink_entry() {
        global $wp_filesystem;

        $wp_filesystem = null;

        $name = 'probe_dangling_' . wp_generate_password( 8, false );
        $dir  = MainWP_Keys_Manager::get_keys_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $path = $dir . $name;
        if ( ! @symlink( $dir . 'missing_' . $name, $path ) ) {
            $this->markTestSkipped( 'Filesystem does not allow symlinks in the keys dir.' );
        }

        $this->assertTrue( is_link( $path ) );
        $this->assertFalse( file_exists( $path ) );

        $this->assertTrue( apply_filters( 'mainwp_delete_key_file_result', null, $name ) );
        clearstatcache( true, $path );
        $this->assertFalse( is_link( $path ) );
    }
}
