<?php
/**
 * Database schema checker normalization tests (MWP-1791).
 *
 * Lighthouse declares nullable columns with DEFAULT NULL. The checker used
 * to turn that into a bare DEFAULT and report those columns as invalid.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Database_Schema_Checker;

// Load the checker when the WordPress test harness is not bootstrapped.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( MainWP_Database_Schema_Checker::class, false ) ) {
	require_once dirname( __DIR__ ) . '/class/class-mainwp-database-schema-checker.php';
}

/**
 * Exposes protected schema comparison helpers.
 */
class Schema_Checker_Access extends MainWP_Database_Schema_Checker {

	/**
	 * Normalize a column definition.
	 *
	 * @param string $definition Column definition.
	 * @return string
	 */
	public static function normalize( $definition ) {
		return self::normalize_definition( $definition );
	}

	/**
	 * Compare expected definitions with SHOW column metadata.
	 *
	 * @param string $table_name Table name.
	 * @param array  $columns    Expected definitions keyed by column name.
	 * @param array  $actual     SHOW FULL COLUMNS rows keyed by field name.
	 * @return array
	 */
	public static function compare( $table_name, $columns, $actual ) {
		return self::detect_invalid_column_definitions( $table_name, $columns, $actual );
	}
}

/**
 * Class Test_Database_Schema_Checker
 */
class Test_Database_Schema_Checker extends \PHPUnit\Framework\TestCase {

	/**
	 * Nullable forms that must compare as the same definition.
	 *
	 * @return void
	 */
	public function test_equivalent_nullable_forms_normalize_identically(): void {
		$int_forms = array(
			'int(10)',
			'int(10) NULL',
			'int(10) DEFAULT NULL',
			'int(10) NULL DEFAULT NULL',
			'INT DEFAULT null',
			'int(10)  default   null',
		);

		foreach ( $int_forms as $form ) {
			$this->assertSame( 'INT', Schema_Checker_Access::normalize( $form ), $form );
		}

		$this->assertSame( 'VARCHAR(200)', Schema_Checker_Access::normalize( 'varchar(200)' ) );
		$this->assertSame( 'VARCHAR(200)', Schema_Checker_Access::normalize( 'varchar(200) NULL' ) );
		$this->assertSame( 'VARCHAR(200)', Schema_Checker_Access::normalize( 'varchar(200) DEFAULT NULL' ) );
		$this->assertSame( 'VARCHAR(200)', Schema_Checker_Access::normalize( 'varchar(200) NULL default null' ) );
		$this->assertSame( 'DATETIME', Schema_Checker_Access::normalize( 'datetime NULL default null' ) );
		$this->assertSame( 'BIGINT UNSIGNED', Schema_Checker_Access::normalize( 'bigint(20) unsigned NULL DEFAULT NULL' ) );
	}

	/**
	 * Real differences in nullability, defaults, and types stay visible.
	 *
	 * @return void
	 */
	public function test_nullability_defaults_and_types_stay_distinct(): void {
		$this->assertSame( 'INT NOT NULL', Schema_Checker_Access::normalize( 'int(11) NOT NULL' ) );
		$this->assertSame( 'INT NOT NULL', Schema_Checker_Access::normalize( 'int(11) NOT NULL DEFAULT NULL' ) );
		$this->assertSame( 'INT NOT NULL DEFAULT 0', Schema_Checker_Access::normalize( 'int(11) NOT NULL DEFAULT 0' ) );
		$this->assertSame( 'INT DEFAULT 0', Schema_Checker_Access::normalize( 'int(10) DEFAULT 0' ) );
		$this->assertSame( 'INT NOT NULL AUTO_INCREMENT', Schema_Checker_Access::normalize( 'int(11) NOT NULL auto_increment' ) );
		$this->assertSame( 'VARCHAR(255) NOT NULL DEFAULT ""', Schema_Checker_Access::normalize( 'varchar(255) NOT NULL DEFAULT ""' ) );
		$this->assertSame( 'TEXT NOT NULL', Schema_Checker_Access::normalize( "text NOT NULL DEFAULT ''" ) );
		$this->assertSame( "VARCHAR(200) NOT NULL DEFAULT 'NULL'", Schema_Checker_Access::normalize( "varchar(200) NOT NULL DEFAULT 'NULL'" ) );
		$this->assertSame(
			"VARCHAR(255) DEFAULT 'X DEFAULT NULL'",
			Schema_Checker_Access::normalize( "varchar(255) DEFAULT 'X DEFAULT NULL'" )
		);
		$this->assertNotSame(
			Schema_Checker_Access::normalize( "varchar(255) DEFAULT 'X DEFAULT NULL'" ),
			Schema_Checker_Access::normalize( "varchar(255) DEFAULT 'X'" )
		);
		$this->assertSame(
			'VARCHAR(255) DEFAULT "X DEFAULT NULL"',
			Schema_Checker_Access::normalize( 'varchar(255) DEFAULT "X DEFAULT NULL"' )
		);
		$this->assertNotSame(
			Schema_Checker_Access::normalize( 'varchar(255) DEFAULT "X DEFAULT NULL"' ),
			Schema_Checker_Access::normalize( 'varchar(255) DEFAULT "X"' )
		);
		$this->assertSame(
			'VARCHAR(255) DEFAULT \'X"" DEFAULT NULL\'',
			Schema_Checker_Access::normalize( "varchar(255) DEFAULT 'X'' DEFAULT NULL'" )
		);

		$this->assertNotSame(
			Schema_Checker_Access::normalize( 'int(10) NOT NULL' ),
			Schema_Checker_Access::normalize( 'int(10) DEFAULT NULL' )
		);
		$this->assertNotSame(
			Schema_Checker_Access::normalize( 'int(10) DEFAULT 0' ),
			Schema_Checker_Access::normalize( 'int(10) DEFAULT NULL' )
		);
		$this->assertNotSame(
			Schema_Checker_Access::normalize( 'varchar(200) DEFAULT NULL' ),
			Schema_Checker_Access::normalize( 'int(10) DEFAULT NULL' )
		);
	}

	/**
	 * The ten Lighthouse columns from the Server Information warning are valid.
	 *
	 * response_code and the eight score columns are int(10) DEFAULT NULL.
	 * type is varchar(200) DEFAULT NULL. MySQL reports those defaults as
	 * omitted (PHP null), including when the integer display width is absent.
	 *
	 * @return void
	 */
	public function test_lighthouse_default_null_columns_are_not_flagged(): void {
		$definitions = $this->lighthouse_column_definitions();
		$actual      = array();

		foreach ( array_keys( $definitions ) as $name ) {
			if ( 'type' === $name ) {
				$mysql_type = 'varchar(200)';
			} elseif ( 'response_code' === $name ) {
				$mysql_type = 'int(10)';
			} else {
				$mysql_type = 'int';
			}
			$actual[ $name ] = $this->show_column( $mysql_type, 'YES', null );
		}

		$this->assertCount( 10, $definitions );
		$this->assertSame(
			array(),
			Schema_Checker_Access::compare( 'wp_mainwp_lighthouse', $definitions, $actual )
		);
	}

	/**
	 * Genuine column mismatches are still reported.
	 *
	 * @return void
	 */
	public function test_real_column_differences_are_still_reported(): void {
		$table = 'wp_mainwp_lighthouse';

		$issues = Schema_Checker_Access::compare(
			$table,
			array( 'response_code' => 'int(10) NOT NULL' ),
			array( 'response_code' => $this->show_column( 'int', 'YES', null ) )
		);
		$this->assertSame(
			array(
				array(
					'table_name' => $table,
					'column'     => 'response_code',
					'expected'   => 'INT NOT NULL',
					'actual'     => 'INT',
				),
			),
			$issues
		);

		$issues = Schema_Checker_Access::compare(
			$table,
			array( 'response_code' => 'int(10) DEFAULT 0' ),
			array( 'response_code' => $this->show_column( 'int', 'YES', null ) )
		);
		$this->assertSame(
			array(
				array(
					'table_name' => $table,
					'column'     => 'response_code',
					'expected'   => 'INT DEFAULT 0',
					'actual'     => 'INT',
				),
			),
			$issues
		);

		$issues = Schema_Checker_Access::compare(
			$table,
			array( 'response_code' => 'int(10) DEFAULT NULL' ),
			array( 'response_code' => $this->show_column( 'int', 'YES', '0' ) )
		);
		$this->assertSame(
			array(
				array(
					'table_name' => $table,
					'column'     => 'response_code',
					'expected'   => 'INT',
					'actual'     => 'INT DEFAULT 0',
				),
			),
			$issues
		);

		$issues = Schema_Checker_Access::compare(
			$table,
			array( 'type' => 'varchar(200) DEFAULT NULL' ),
			array( 'type' => $this->show_column( 'int', 'YES', null ) )
		);
		$this->assertSame(
			array(
				array(
					'table_name' => $table,
					'column'     => 'type',
					'expected'   => 'VARCHAR(200)',
					'actual'     => 'INT',
				),
			),
			$issues
		);

		$issues = Schema_Checker_Access::compare(
			$table,
			array( 'type' => 'varchar(200) DEFAULT NULL' ),
			array( 'type' => $this->show_column( 'varchar(200)', 'YES', 'NULL' ) )
		);
		$this->assertSame(
			array(
				array(
					'table_name' => $table,
					'column'     => 'type',
					'expected'   => 'VARCHAR(200)',
					'actual'     => "VARCHAR(200) DEFAULT 'NULL'",
				),
			),
			$issues
		);

		$issues = Schema_Checker_Access::compare(
			$table,
			array( 'response_code' => 'int(11) NOT NULL DEFAULT 0' ),
			array( 'response_code' => $this->show_column( 'int(11)', 'NO', '0' ) )
		);
		$this->assertSame( array(), $issues );

		$issues = Schema_Checker_Access::compare(
			'wp_mainwp_api_keys',
			array( 'last_access' => 'datetime NULL default null' ),
			array( 'last_access' => $this->show_column( 'datetime', 'YES', null ) )
		);
		$this->assertSame( array(), $issues );

		$definitions = $this->lighthouse_column_definitions();
		$actual      = array();
		foreach ( array_keys( $definitions ) as $name ) {
			if ( 'type' === $name ) {
				$mysql_type = 'varchar(200)';
			} else {
				$mysql_type = 'int';
			}
			$actual[ $name ] = $this->show_column( $mysql_type, 'YES', null );
		}
		$definitions['response_code'] = 'int(10) NOT NULL';

		$issues = Schema_Checker_Access::compare( $table, $definitions, $actual );
		$this->assertSame(
			array(
				array(
					'table_name' => $table,
					'column'     => 'response_code',
					'expected'   => 'INT NOT NULL',
					'actual'     => 'INT',
				),
			),
			$issues
		);
	}

	/**
	 * Column definitions the checker compared for the Lighthouse table.
	 *
	 * @return array<string,string>
	 */
	private function lighthouse_column_definitions() {
		$definitions = array(
			'response_code' => 'int(10) DEFAULT NULL',
		);

		$score_columns = array(
			'desktop_performance',
			'desktop_accessibility',
			'desktop_bestpractices',
			'desktop_seo',
			'mobile_performance',
			'mobile_accessibility',
			'mobile_bestpractices',
			'mobile_seo',
		);

		foreach ( $score_columns as $column ) {
			$definitions[ $column ] = 'int(10) DEFAULT NULL';
		}

		$definitions['type'] = 'varchar(200) DEFAULT NULL';

		return $definitions;
	}

	/**
	 * One SHOW FULL COLUMNS row.
	 *
	 * @param string      $type    MySQL column type.
	 * @param string      $null    YES or NO.
	 * @param string|null $default Default value, or null when MySQL omits it.
	 * @return array
	 */
	private function show_column( $type, $null, $default ) {
		return array(
			'Type'    => $type,
			'Null'    => $null,
			'Default' => $default,
			'Extra'   => '',
		);
	}
}
