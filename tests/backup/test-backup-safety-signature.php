<?php
/**
 * Safety archive requests bind their parameters to the existing signature.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;

/** Safety archive signing contract. */
class MainWP_Backup_Safety_Signature_Test extends \WP_UnitTestCase {

	/**
	 * The signed envelope covers both probe and deletion parameters.
	 *
	 * @dataProvider operations
	 * @param int $remove Whether deletion was requested.
	 */
	public function test_signature_covers_safety_binding_and_operation( $remove ) {
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
		$this->assertNotFalse( $key );
		$this->assertTrue( openssl_pkey_export( $key, $pem ) );
		$details = openssl_pkey_get_details( $key );
		$website = (object) array(
			'id'                    => 987654321,
			'url'                   => 'http://signature.example.test/',
			'adminname'             => 'admin',
			'privkey'               => base64_encode( $pem ),
			'verify_method'         => 1,
			'signature_algo'        => OPENSSL_ALGO_SHA256,
			'support_advanced_sign' => 1,
			'verify_certificate'    => 0,
			'http_user'             => '',
			'http_pass'             => '',
		);
		$binding = wp_json_encode(
			array(
				'task_id' => 'owned-safety',
				'file'    => 'backup.tar.gz',
			)
		);
		$query   = MainWP_Connect::get_post_data_authed(
			$website,
			'backup_safety_archive',
			array(
				'binding' => $binding,
				'remove'  => $remove,
			),
			array( 'verify_signature' => true )
		);
		parse_str( $query, $data );
		$signed = json_decode( $data['data_signature'], true );
		$this->assertSame( $binding, $signed['binding'] ?? null );
		$this->assertSame( (string) $remove, $signed['remove'] ?? null );
		$this->assertSame( 1, openssl_verify( $data['data_signature'], base64_decode( $data['mainwpsignature_adv'] ), $details['key'], OPENSSL_ALGO_SHA256 ) );
	}

	/**
	 * Probe and delete use the same signing format.
	 *
	 * @return array Operations.
	 */
	public static function operations() {
		return array(
			'probe'  => array( 0 ),
			'delete' => array( 1 ),
		);
	}
}
