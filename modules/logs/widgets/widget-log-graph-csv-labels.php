<?php
/**
 * MainWP Logs Widget
 *
 * Prepares chart category names for CSV exports.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Log_Graph_Csv_Labels
 *
 * Quotes chart category names and neutralizes spreadsheet formulas.
 */
class Log_Graph_Csv_Labels {

    /**
     * Returns a quoted CSV field with formula prefixes neutralized.
     *
     * @param mixed $name Category name.
     * @return string
     */
    public static function get_csv_cell( $name ): string {
        $name = (string) $name;
        if ( '' !== $name && in_array( $name[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
            $name = "'" . $name;
        }

        return '"' . str_replace( '"', '""', $name ) . '"';
    }

    /**
     * Returns ordered CSV map entries, using stripped labels for ambiguous names.
     *
     * @param array $names Category names in chart order.
     * @return array
     */
    public static function get_csv_categories( array $names ): array {
        $categories     = array();
        $original_names = array();

        foreach ( $names as $name ) {
            $name = (string) $name;
            $key  = str_replace( ',', '', $name );
            if ( ! isset( $original_names[ $key ] ) ) {
                $original_names[ $key ] = $name;
                $categories[ $key ]     = array( $key, self::get_csv_cell( $name ) );
            } elseif ( $original_names[ $key ] !== $name ) {
                $categories[ $key ][1] = self::get_csv_cell( $key );
            }
        }

        return array_values( $categories );
    }
}
