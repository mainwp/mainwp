<?php
/**
 * MainWP_File_Uploader_Handle
 *
 * Handle file uploads via regular form post (uses the $_FILES array)
 *
 * @see dropzone.js
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_File_Uploader_Handle
 *
 * @package MainWP\Dashboard
 */
class MainWP_File_Uploader_Handle { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Save the file to the specified path.
     *
     * @param string $path Path to save file to.
     * @throws \Exception Exception object.
     *
     * @return boolean TRUE on success|false on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_base_dir()
     */
    public function save( $path ) {

        $moved    = false;
        $tmp_name = isset( $_FILES['qqfile']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['qqfile']['tmp_name'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify in caller.

		//phpcs:disable WordPress.WP.AlternativeFunctions -- custom process.
        if ( ! is_dir( dirname( $path ) ) ) {
            mkdir( dirname( $path ), 0777, true );
        }

        if ( empty( $_REQUEST['dzchunkbyteoffset'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified.
            if ( file_exists( $path ) ) {
                unlink( $path ); // first chuck, delete file if existed, to create new file.
            }
        }

        // to append to file, wp_filesystem put_contents does not support.
        $str = file_get_contents( $tmp_name );

        $moved = file_put_contents( $path, $str, FILE_APPEND );

        if ( ! file_exists( $path ) ) {
            throw new \Exception( 'Unable to save the file to the MainWP upload directory, please check your system configuration.' );
        }

		//phpcs:enable WordPress.WP.AlternativeFunctions

        if ( ! $moved ) {
            return false;
        }

        return true;
    }

    /** Get the File Name. */
    public function get_name() {
        return isset( $_FILES['qqfile']['name'] ) ? sanitize_text_field( wp_unslash( $_FILES['qqfile']['name'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify in caller.
    }

    /** Get the File Size. */
    public function get_size() {
        return isset( $_FILES['qqfile']['size'] ) ? sanitize_text_field( wp_unslash( $_FILES['qqfile']['size'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify in caller.
    }
}
