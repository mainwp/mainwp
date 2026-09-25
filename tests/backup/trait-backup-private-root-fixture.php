<?php
/**
 * Isolated native backup storage for filesystem tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Private_Root;

/**
 * Own and remove the private root used by a test.
 */
trait Backup_Private_Root_Fixture {

	/**
	 * Test-owned root.
	 *
	 * @var string
	 */
	private $private_root;

	/**
	 * Request document root restored after each test.
	 *
	 * @var mixed
	 */
	private $private_document_root;

	/**
	 * Redirect native writes before exercising a consumer.
	 *
	 * @return void
	 */
	private function set_up_private_root() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the original request value for teardown.
		$this->private_document_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$this->private_root = realpath( sys_get_temp_dir() ) . '/mainwp-private-test-' . uniqid();
		add_filter( 'mainwp_backup_private_root', array( $this, 'filter_private_root' ) );
		MainWP_Backup_Private_Root::reset();
	}

	/**
	 * Supply the test root.
	 *
	 * @return string
	 */
	public function filter_private_root() {
		return $this->private_root;
	}

	/**
	 * Remove only the tree this test owns.
	 *
	 * @return void
	 */
	private function tear_down_private_root() {
		if ( null === $this->private_document_root ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->private_document_root;
		}
		remove_filter( 'mainwp_backup_private_root', array( $this, 'filter_private_root' ) );
		MainWP_Backup_Private_Root::reset();
		$this->remove_private_fixture( $this->private_root );
	}

	/**
	 * Remove fixtures without following symlinks.
	 *
	 * @param string $path Fixture path.
	 * @return void
	 */
	private function remove_private_fixture( $path ) {
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
			unlink( $path );
		} elseif ( is_dir( $path ) ) {
			chmod( $path, 0700 );
			foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $name ) {
				$this->remove_private_fixture( $path . '/' . $name );
			}
			rmdir( $path );
		}
	}
}
