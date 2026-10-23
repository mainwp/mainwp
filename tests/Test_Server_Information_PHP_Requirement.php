<?php
/**
 * Dashboard PHP requirement rows.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Server_Information;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Exercises both table callers and their real row comparisons.
 */
class Test_Server_Information_PHP_Requirement extends \WP_UnitTestCase {

    /**
     * Both screens must display the declared minimum and classify detected versions.
     *
     * @dataProvider php_versions
     * @param string $method   Table renderer.
     * @param string $detected Detected PHP version.
     * @param bool   $passes   Whether the requirement is met.
     */
    public function test_php_requirement_rows( $method, $detected, $passes ) {
        $getter = static function () use ( $detected ) {
            return $detected;
        };
        add_filter( 'pre_option_mainwp_plugin_version', $getter );
        try {
            ob_start();
            PHP_Requirement_Renderer::$method();
            $html = ob_get_clean();
        } finally {
            remove_filter( 'pre_option_mainwp_plugin_version', $getter );
        }

        $document = new \DOMDocument();
        $document->loadHTML( '<html><body><table>' . $html . '</table></body></html>' );
        $xpath = new \DOMXPath( $document );
        $rows  = $xpath->query( '//tr[td[1][normalize-space(.)="PHP Version"]]' );
        $this->assertSame( 1, $rows->length, $method );
        $cells = $rows->item( 0 )->getElementsByTagName( 'td' );
        $this->assertSame( '>=8.3', preg_replace( '/\s+/', '', $cells->item( 1 )->textContent ), $method );
        $this->assertSame( $detected, trim( $cells->item( 2 )->textContent ), $method );
        $status = trim( $cells->item( 3 )->textContent );
        $this->assertSame( $passes ? 'Pass' : ( 'render_php_check_tbody' === $method ? 'Fail' : 'Warning' ), $status, $method . ': ' . $detected );
    }

    /**
     * Supplies versions on either side of the minimum for each screen.
     *
     * @return array Test cases.
     */
    public static function php_versions() {
        $versions = array(
            'old minimum' => array( '7.4.33', false ),
            'PHP 8.0'     => array( '8.0.30', false ),
            'below'       => array( '8.2.99', false ),
            'minimum'     => array( '8.3.0', true ),
            'point'       => array( '8.3.35', true ),
            'later'       => array( '8.4.0', true ),
        );
        $cases    = array();
        foreach ( array( 'render_php_check_tbody', 'render_quick_setup_system_check' ) as $method ) {
            foreach ( $versions as $name => $version ) {
                $cases[ $method . ': ' . $name ] = array( $method, $version[0], $version[1] );
            }
        }
        return $cases;
    }
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The fixture only serves the test above.

/**
 * Uses an option-backed detected version without changing the runtime or comparison.
 */
class PHP_Requirement_Renderer extends MainWP_Server_Information {

    /**
     * Renders only the PHP row using the controlled detected version.
     *
     * @param string $config       Row label.
     * @param string $compare      Comparison operator.
     * @param mixed  $version      Required value.
     * @param string $getter       Detected-value getter.
     * @param string $extraText    Units.
     * @param string $extraCompare Alternative comparison.
     * @param mixed  $extraVersion Alternative value.
     * @param string $whatType     Comparison type.
     * @param int    $errorType    Failure severity.
     */
    public static function render_row( $config, $compare, $version, $getter, $extraText = '', $extraCompare = null, $extraVersion = null, $whatType = null, $errorType = self::WARNING ) {
        if ( 'get_php_version' === $getter ) {
            parent::render_row( $config, $compare, $version, 'get_current_version', $extraText, $extraCompare, $extraVersion, $whatType, $errorType );
        }
    }

    /**
     * Renders only the PHP row using the controlled detected version.
     *
     * @param string $config       Row label.
     * @param string $compare      Comparison operator.
     * @param mixed  $version      Required value.
     * @param string $getter       Detected-value getter.
     * @param string $extraText    Units.
     * @param string $extraCompare Alternative comparison.
     * @param mixed  $extraVersion Alternative value.
     * @param string $whatType     Comparison type.
     * @param int    $errorType    Failure severity.
     */
    public static function render_row_with_description( $config, $compare, $version, $getter, $extraText = '', $extraCompare = null, $extraVersion = null, $whatType = null, $errorType = self::WARNING ) {
        if ( 'get_php_version' === $getter ) {
            parent::render_row_with_description( $config, $compare, $version, 'get_current_version', $extraText, $extraCompare, $extraVersion, $whatType, $errorType );
        }
    }
}
