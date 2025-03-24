<?php
/**
 * MainWP Utility Helper.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, Generic.Metrics.CyclomaticComplexity -- Using cURL functions.

/**
 * Class MainWP_Utility
 *
 * @package MainWP\Dashboard
 */
class MainWP_Utility { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Yoast SEO is enabled return true else return null.
     *
     * @static
     * @var boolean $enabled_wp_seo If Yoast SEO is enabled return true else return null.
     */
    public static $enabled_wp_seo = null;

    /**
     * Private static variable.
     *
     * @static
     *
     * @var mixed Default null
     */
    public static $last_deactivated_alerts = null;

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Store the disabled php functions.
     *
     * @static
     * @var string $disabled_functions disabled php functions.
     */
    public static $disabled_functions = null;

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Utility
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Method starts_with()
     *
     * Start of Stack Trace.
     *
     * @param mixed $haystack The full stack.
     * @param mixed $needle The function that is throwing the error.
     *
     * @return mixed Needle in the Haystack.
     */
    public static function starts_with( $haystack, $needle ) {
        return ! strncmp( $haystack, $needle, strlen( $needle ) );
    }

    /**
     * Method ends_with()
     *
     * End of Stack Trace.
     *
     * @param mixed $haystack Haystack parameter.
     * @param mixed $needle Needle parameter.
     *
     * @return boolean
     */
    public static function ends_with( $haystack, $needle ) {
        $length = strlen( $needle );
        if ( 0 === $length ) {
            return true;
        }

        return substr( $haystack, - $length ) === $needle;
    }

    /**
     * Method get_nice_url()
     *
     * Grab url.
     *
     * @param string $pUrl Website URL.
     * @param bool   $showHttp Show HTTP.
     *
     * @return string $url.
     */
    public static function get_nice_url( $pUrl, $showHttp = false ) {
        $url = $pUrl;

        if ( static::starts_with( $url, 'http://' ) ) {
            if ( ! $showHttp ) {
                $url = substr( $url, 7 );
            }
        } elseif ( static::starts_with( $pUrl, 'https://' ) ) {
            if ( ! $showHttp ) {
                $url = substr( $url, 8 );
            }
        } elseif ( $showHttp ) {
                $url = 'http://' . $url;
        }

        if ( static::ends_with( $url, '/' ) ) {
            if ( ! $showHttp ) {
                $url = substr( $url, 0, strlen( $url ) - 1 );
            }
        } else {
            $url = $url . '/';
        }

        return $url;
    }

    /**
     * Method is_domain_valid()
     *
     * Check $url against FILTER_VALIDATE_URL.
     *
     * @param mixed $url Domain to check.
     *
     * @return boolean True|False.
     */
    public static function is_domain_valid( $url ) {
        return filter_var( $url, FILTER_VALIDATE_URL );
    }

    /**
     * Method ctype_digit()
     *
     * Returns TRUE if every character in the string text is a decimal digit, FALSE otherwise.
     *
     * @param mixed $str String to check.
     *
     * @return boolean Returns TRUE if every character in the string text is a decimal digit, FALSE otherwise.
     */
    public static function ctype_digit( $str ) {
        return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
    }

    /**
     * Method sortmulti()
     *
     * Sort the given array, Acending, Decending or by Natural Order.
     *
     * @param mixed $arr Array to sort.
     * @param mixed $index Index of array.
     * @param mixed $order Acending or Decending order.
     * @param bool  $natsort Sort an array using a "natural order" algorithm. Default: false.
     * @param bool  $case_sensitive If case sensitive return true else return false. Default: false.
     *
     * @return array $sorted Return the sorted array.
     */
    public static function sortmulti( $arr, $index, $order, $natsort = false, $case_sensitive = false ) { // phpcs:ignore -- NOSONAR - complex.
        $sorted = array();
        if ( is_array( $arr ) && ! empty( $arr ) ) {
            foreach ( array_keys( $arr ) as $key ) {
                $temp[ $key ] = $arr[ $key ][ $index ];
            }
            if ( ! $natsort ) {
                if ( 'asc' === $order ) {
                    asort( $temp );
                } else {
                    arsort( $temp );
                }
            } else {
                if ( true === $case_sensitive ) {
                    natsort( $temp );
                } else {
                    natcasesort( $temp );
                }
                if ( 'asc' !== $order ) {
                    $temp = array_reverse( $temp, true );
                }
            }
            foreach ( array_keys( $temp ) as $key ) {
                if ( is_numeric( $key ) ) {
                    $sorted[] = $arr[ $key ];
                } else {
                    $sorted[ $key ] = $arr[ $key ];
                }
            }

            return $sorted;
        }

        return $sorted;
    }

    /**
     * Method get_sub_array_having()
     *
     * Get sub array.
     *
     * @param mixed $arr Array to traverse.
     * @param mixed $index Index of array.
     * @param mixed $value Array values.
     *
     * void array $output Sub array.
     */
    public static function get_sub_array_having( $arr, $index, $value ) {
        $output = array();
        if ( is_array( $arr ) && ! empty( $arr ) ) {
            foreach ( $arr as $arrvalue ) {
                $existed = isset( $arrvalue[ $index ] ) ? $arrvalue[ $index ] : null;
                if ( $existed === $value ) {
                    $output[] = $arrvalue;
                }
            }
        }

        return $output;
    }

    /**
     * Method get_sub_array_with_limit()
     *
     * Get sub array.
     *
     * @param mixed $arr Array to traverse.
     * @param mixed $start start index of array.
     * @param mixed $count count values.
     *
     * void array $output Sub array.
     */
    public static function get_sub_array_with_limit( $arr, $start, $count ) {
        $output = array();
        if ( is_array( $arr ) && ! empty( $arr ) ) {
            if ( $start > count( $arr ) ) {
                return array();
            }
            $i = 0;
            foreach ( $arr as $value ) {
                if ( $i >= $start && $i < $start + $count ) {
                    $output[] = $value;
                }
                ++$i;
                if ( $i > $start + $count ) {
                    break;
                }
            }
        }
        return $output;
    }


    /**
     * Method trim_slashes()
     *
     * Trim stashes from element.
     *
     * @param mixed $elem Element to trim.
     *
     * @return string Return string with no slashes.
     */
    public static function trim_slashes( $elem ) {
        return trim( $elem, '/' );
    }

    /**
     * Method sanitize()
     *
     * Sanitize given string.
     *
     * @param mixed $str String to sanitize.
     *
     * @return string Sanitized string.
     */
    public static function sanitize( $str ) {
        return preg_replace( '/[\\\\\/\:"\*\?\<\>\|]+/', '', $str );
    }

    /**
     * Method sanitize_alphanumeric()
     *
     * Sanitize given string.
     *
     * @param mixed $str String to sanitize.
     *
     * @return string Sanitized string.
     */
    public static function sanitize_attr_slug( $str ) {
        $str = strtolower( $str );
        $str = str_replace( array( '=', '?', '/' ), '-', $str );
        $str = preg_replace( '/[^A-Za-z0-9^\-]/', '', $str );
        return $str;
    }

    /**
     * Method end_session()
     *
     * End a session.
     *
     * @return void
     */
    public static function end_session() {

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }

        session_write_close();
        if ( 0 < ob_get_length() ) {
            ob_end_flush();
        }
    }

    /**
     * Method get_timestamp()
     *
     * Get time stamp in gmt_offset.
     *
     * @param mixed $timestamp Time stamp to convert.
     *
     * @return string Time stamp in general mountain time offset.
     */
    public static function get_timestamp( $timestamp = false ) {
        if ( false === $timestamp ) {
            $timestamp = time();
        }
        $gmtOffset = get_option( 'gmt_offset' );

        return $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp;
    }

    /**
     * Method date()
     *
     * Show date in given format.
     *
     * @param mixed $format Format to display date in.
     *
     * @return string Date.
     */
    public static function date( $format ) {
        // phpcs:ignore -- use local date function.
        return date( $format, static::get_timestamp() );
    }

    /**
     * Method format_timestamp()
     *
     * Format the given timestamp.
     *
     * @param mixed $timestamp Timestamp to format.
     *
     * @return string Formatted timestamp.
     */
    public static function format_timestamp( $timestamp ) {
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
    }

    /**
     * Method format_timestamp()
     *
     * Format the given timestamp.
     *
     * @param mixed $timestamp Timestamp to format.
     *
     * @return string Formatted timestamp.
     */
    public static function format_date( $timestamp ) {
        return date_i18n( get_option( 'date_format' ), $timestamp );
    }

    /**
     * Method format_time()
     *
     * Format the given timestamp.
     *
     * @param mixed $timestamp Timestamp to format.
     *
     * @return string Formatted timestamp.
     */
    public static function format_time( $timestamp ) {
        return date_i18n( get_option( 'time_format' ), $timestamp );
    }

    /**
     * Format duration time to show.
     *
     * @param  float $time timestamp.
     * @return mixed result.
     */
    public static function format_duration_time( $time ) {

        $original_sec  = absint( $time );
        $dura_sec      = $original_sec;
        $days          = floor( $dura_sec / 86400 );
        $dura_sec     -= $days * 86400;
        $dura_hour_sec = $dura_sec;
        $dura_hours    = floor( $dura_sec / 3600 );

        if ( $days > 0 ) {
            $formatted_dura = ( $days * 24 + $dura_hours ) . gmdate( 'i\m s\s', $dura_hour_sec );
        } else {
            $formatted_dura = gmdate( 'H\h i\m s\s', $original_sec );
        }
        return '<bdi>' . esc_html( $formatted_dura ) . '</bdi>';
    }


    /**
     * Method human_filesize()
     *
     * Convert to human readable file size format,
     * (B|kB|MB|GB|TB|PB|EB|ZB|YB).
     *
     * @param mixed   $bytes File in bytes.
     * @param integer $decimals Number of decimals to output.
     *
     * @return string Human readable file size.
     */
    public static function human_filesize( $bytes, $decimals = 2 ) {
        $size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
        $factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

        return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$size[ $factor ];
    }

    /**
     * Method map_fields()
     *
     * Map Site.
     *
     * @param mixed $data data to map.
     * @param mixed $keys Keys to map.
     * @param bool  $object_output Output format array|object.
     *
     * @return mixed Mapped data.
     */
    public static function map_fields( &$data, $keys, $object_output = true ) {
        return static::map_site( $data, $keys, $object_output );
    }

    /**
     * Method map_site()
     *
     * Map Site.
     *
     * @param mixed $website Website to map.
     * @param mixed $keys Keys to map.
     * @param bool  $object_output Output format array|object.
     *
     * @return mixed $outputSite Mapped site.
     */
    public static function map_site( &$website, $keys, $object_output = true ) { // phpcs:ignore -- NOSONAR - complex.
        if ( $object_output ) {
            $outputSite = new \stdClass();
            if ( ! empty( $website ) ) {
                if ( is_object( $website ) ) {
                    foreach ( $keys as $key ) {
                        if ( property_exists( $website, $key ) ) {
                            $outputSite->{$key} = $website->$key;
                        } else {
                            $outputSite->{$key} = '';
                        }
                    }
                } elseif ( is_array( $website ) ) {
                    foreach ( $keys as $key ) {
                        if ( isset( $website[ $key ] ) ) {
                            $outputSite->{$key} = $website[ $key ];
                        } else {
                            $outputSite->{$key} = '';
                        }
                    }
                }
            }
        } else {
            $outputSite = array();
            if ( ! empty( $website ) ) {
                if ( is_object( $website ) ) {
                    foreach ( $keys as $key ) {
                        if ( property_exists( $website, $key ) ) {
                            $outputSite[ $key ] = $website->$key;
                        } else {
                            $outputSite[ $key ] = '';
                        }
                    }
                } elseif ( is_array( $website ) ) {
                    foreach ( $keys as $key ) {
                        if ( isset( $website[ $key ] ) ) {
                            $outputSite[ $key ] = $website[ $key ];
                        } else {
                            $outputSite[ $key ] = '';
                        }
                    }
                }
            }
        }
        return $outputSite;
    }

    /**
     * Method array_merge()
     *
     * Merge two given arrays into one.
     *
     * @param mixed $arr1 First array.
     * @param mixed $arr2 Second array.
     *
     * @return array Merged Array.
     */
    public static function array_merge( $arr1, $arr2 ) {
        if ( ! is_array( $arr1 ) && ! is_array( $arr2 ) ) {
            return array();
        }
        if ( ! is_array( $arr1 ) ) {
            return $arr2;
        }
        if ( ! is_array( $arr2 ) ) {
            return $arr1;
        }

        $output = array();
        foreach ( $arr1 as $el ) {
            $output[] = $el;
        }
        foreach ( $arr2 as $el ) {
            $output[] = $el;
        }

        return $output;
    }

    /**
     * Method update_option()
     *
     * Update option.
     *
     * @param mixed $option_name Option name.
     * @param mixed $option_value Option value.
     *
     * @return (boolean) False if value was not updated and true if value was updated.
     */
    public static function update_option( $option_name, $option_value ) {
        $success = add_option( $option_name, $option_value, '', 'no' );

        if ( ! $success ) {
            $success = update_option( $option_name, $option_value );
        }

        return $success;
    }

    /**
     * Method update_user_option()
     *
     * Update option.
     *
     * @param mixed $option_name Option name.
     * @param mixed $option_value Option value.
     *
     * @return (boolean) False if value was not updated and true if value was updated.
     */
    public static function update_user_option( $option_name, $option_value ) {
        $user = wp_get_current_user();
        if ( $user ) {
            return update_user_option( $user->ID, $option_name, $option_value );
        }
        return false;
    }

    /**
     * Method remove_preslash_spaces()
     *
     * Remove spaces before slashes.
     *
     * @param string $text String to strip.
     *
     * @return string $text Cleaned string.
     */
    public static function remove_preslash_spaces( $text ) {
        while ( stristr( $text, ' /' ) ) {
            $text = str_replace( ' /', '/', $text );
        }

        return $text;
    }

    /**
     * Method remove_http_prefix()
     *
     * Remove http prefixes from given url.
     *
     * @param mixed $pUrl Given URL.
     * @param bool  $pTrimSlashes Whether or not to trim slashes. Default is false.
     *
     * @return string Trimmed URL.
     */
    public static function remove_http_prefix( $pUrl, $pTrimSlashes = false ) {
        return str_replace( array( 'http:' . ( $pTrimSlashes ? '//' : '' ), 'https:' . ( $pTrimSlashes ? '//' : '' ) ), array( '', '' ), $pUrl );
    }

    /**
     * Method remove_http_www_prefix()
     *
     * Remove 'www.' from given URL.
     *
     * @param mixed $pUrl Given URL.
     *
     * @return string Cleaned URL.
     */
    public static function remove_http_www_prefix( $pUrl ) {
        $pUrl = static::remove_http_prefix( $pUrl, true );
        if ( static::starts_with( strtolower( $pUrl ), 'www.' ) ) {
            $pUrl = substr( $pUrl, 4 );
        }
        return $pUrl;
    }

    /**
     * Method sanitize_file_name()
     *
     * Sanitize file names.
     *
     * @param mixed $filename File name to sanitize.
     *
     * @return string Sanitized filename.
     */
    public static function sanitize_file_name( $filename ) {
        $filename = str_replace( array( '|', '/', '\\', ' ', ':' ), array( '-', '-', '-', '-', '-' ), $filename );
        return sanitize_file_name( $filename );
    }



    /**
     * Method esc_content()
     *
     * Escape content,
     * allowed content (a,href,title,br,em,strong,p,hr,ul,ol,li,h1,h2 ... ).
     *
     * @param mixed  $content Content to escape.
     * @param string $type Type of content. Default = note.
     * @param mixed  $more_allowed input allowed tags - options.
     *
     * @return string Filtered content containing only the allowed HTML.
     */
    public static function esc_content( $content, $type = 'note', $more_allowed = array() ) {
        if ( ! is_string( $content ) ) {
            return $content;
        }

        if ( 'note' === $type ) {

            $allowed_html = array(
                'a'      => array(
                    'href'  => array(),
                    'title' => array(),
                ),
                'br'     => array(),
                'em'     => array(),
                'strong' => array(),
                'p'      => array(),
                'hr'     => array(),
                'ul'     => array(),
                'ol'     => array(),
                'li'     => array(),
                'h1'     => array(),
                'h2'     => array(),
            );

            if ( is_array( $more_allowed ) && ! empty( $more_allowed ) ) {
                $allowed_html = array_merge( $allowed_html, $more_allowed );
            }

            $content = wp_kses( $content, $allowed_html );

        } elseif ( 'mixed' === $type ) {

            $allowed_html = array(
                'a'      => array(
                    'href'    => array(),
                    'title'   => array(),
                    'class'   => array(),
                    'onclick' => array(),
                ),
                'img'    => array(
                    'src'     => array(),
                    'title'   => array(),
                    'class'   => array(),
                    'onclick' => array(),
                    'alt'     => array(),
                    'width'   => array(),
                    'height'  => array(),
                    'sizes'   => array(),
                    'srcset'  => array(),
                    'usemap'  => array(),
                ),
                'br'     => array(),
                'em'     => array(),
                'strong' => array(),
                'p'      => array(),
                'hr'     => array(),
                'ul'     => array(
                    'style' => array(),
                ),
                'ol'     => array(),
                'li'     => array(),
                'h1'     => array(),
                'h2'     => array(),
                'head'   => array(),
                'html'   => array(
                    'lang' => array(),
                ),
                'meta'   => array(
                    'name'       => array(),
                    'http-equiv' => array(),
                    'content'    => array(),
                    'charset'    => array(),
                ),
                'title'  => array(),
                'body'   => array(
                    'style' => array(),
                ),
                'span'   => array(
                    'id'    => array(),
                    'style' => array(),
                    'class' => array(),
                ),
                'form'   => array(
                    'id'       => array(),
                    'method'   => array(),
                    'action'   => array(),
                    'onsubmit' => array(),
                ),
                'table'  => array(
                    'class' => array(),
                ),
                'thead'  => array(
                    'class' => array(),
                ),
                'tbody'  => array(
                    'class' => array(),
                ),
                'tr'     => array(
                    'id' => array(),
                ),
                'td'     => array(
                    'class' => array(),
                ),
                'div'    => array(
                    'id'    => array(),
                    'style' => array(),
                    'class' => array(),
                ),
                'input'  => array(
                    'type'    => array(),
                    'name'    => array(),
                    'class'   => array(),
                    'value'   => array(),
                    'onclick' => array(),
                ),
                'button' => array(
                    'type'    => array(),
                    'name'    => array(),
                    'value'   => array(),
                    'class'   => array(),
                    'title'   => array(),
                    'onclick' => array(),
                ),
            );

            if ( is_array( $more_allowed ) && ! empty( $more_allowed ) ) {
                $allowed_html = array_merge( $allowed_html, $more_allowed );
            }

            $content = wp_kses( $content, $allowed_html );
        } else {
            $content = wp_kses_post( $content );
        }

        return $content;
    }

    /**
     * Method esc_mixed_content()
     *
     * Escape mixed content,
     * allowed content (a,href,title,br,em,strong,p,hr,ul,ol,li,h1,h2 ... ).
     *
     * @param mixed  $data data to escape.
     * @param string $depth Maximum depth to walk through $data. Must be greater than 0.
     * @param mixed  $more_allowed input allowed tags - options.
     *
     * @throws \MainWP_Exception Excetpion message.
     *
     * @return string Filtered content containing only the allowed HTML.
     */
    public static function esc_mixed_content( $data, $depth, $more_allowed = array() ) { // phpcs:ignore -- NOSONAR - complex.
        if ( $depth < 0 ) {
            throw new MainWP_Exception( 'Reached depth limit' );
        }

        if ( is_array( $data ) ) {
            $output = array();
            foreach ( $data as $id => $el ) {
                // Don't forget to sanitize the ID!
                if ( is_string( $id ) ) {
                    $clean_id = static::esc_content( $id, 'mixed', $more_allowed );
                } else {
                    $clean_id = $id;
                }

                // Check the element type, so that we're only recursing if we really have to.
                if ( is_array( $el ) || is_object( $el ) ) {
                    $output[ $clean_id ] = static::esc_mixed_content( $el, $depth - 1 );
                } elseif ( is_string( $el ) ) {
                    $output[ $clean_id ] = static::esc_content( $el, 'mixed', $more_allowed );
                } else {
                    $output[ $clean_id ] = $el;
                }
            }
        } elseif ( is_object( $data ) ) {
            $output = new stdClass();
            foreach ( $data as $id => $el ) {
                if ( is_string( $id ) ) {
                    $clean_id = static::esc_content( $id, 'mixed', $more_allowed );
                } else {
                    $clean_id = $id;
                }

                if ( is_array( $el ) || is_object( $el ) ) {
                    $output->$clean_id = static::esc_mixed_content( $el, $depth - 1, $more_allowed );
                } elseif ( is_string( $el ) ) {
                    $output->$clean_id = static::esc_content( $el, 'mixed', $more_allowed );
                } else {
                    $output->$clean_id = $el;
                }
            }
        } elseif ( is_string( $data ) ) {
            return static::esc_content( $data, 'mixed', $more_allowed );
        } else {
            return $data;
        }

        return $output;
    }

    /**
     * Method parse_html_error_message()
     *
     * @param string $error_msg Error message.
     *
     * @return mixed array|string.
     */
    public static function parse_html_error_message( $error_msg ) {
        // pasing error message that included link html.
        preg_match( '/([^\<]*)(<a[^\>]*>)([^\<]*)(<[^\>]*>)(.*)/', $error_msg, $output_array );
        if ( is_array( $output_array ) && 6 === count( $output_array ) ) {
            preg_match( '/<a href="([^\"]*)"(.*)/', $output_array[2], $link_array );
            $link = '';
            if ( is_array( $link_array ) && 3 === count( $link_array ) ) {
                $link = $link_array[1];
            }
            if ( ! empty( $link ) ) {
                return array(
                    'el_before' => esc_html( $output_array[1] ),
                    'el_link'   => esc_html( $link ),
                    'el_text'   => esc_html( $output_array[3] ),
                    'el_after'  => esc_html( $output_array[5] ),
                );
            }
        }
        return $error_msg;
    }

    /**
     * Method show_mainwp_message()
     *
     * Check whenther or not to show the MainWP Message.
     *
     * @param mixed $type Type of message.
     * @param mixed $notice_id Notice ID.
     *
     * @return boolean true|false.
     */
    public static function show_mainwp_message( $type, $notice_id ) {
        unset( $type );
        $status = get_user_option( 'mainwp_notice_saved_status' );
        if ( ! is_array( $status ) ) {
            $status = array();
        }
        if ( isset( $status[ $notice_id ] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Method get_hide_notice_status()
     *
     * Check whenther or not to show the MainWP Message.
     *
     * @param mixed $notice_id Notice ID.
     *
     * @return mixed true|false|time.
     */
    public static function get_hide_notice_status( $notice_id ) {
        $notices = get_user_option( 'mainwp_notice_saved_status' );
        if ( ! is_array( $notices ) ) {
            $notices = array();
        }
        if ( isset( $notices[ $notice_id ] ) ) {
            return $notices[ $notice_id ];
        }
        return false;
    }

    /**
     * Method get_flash_message()
     *
     * Get saved flash Message.
     *
     * @param mixed $message_id Notice ID.
     * @param bool  $delete True to delete the message after get it.
     *
     * @return boolean true|false.
     */
    public static function get_flash_message( $message_id, $delete = true ) {
        $flash_messages = get_user_option( 'mainwp_flash_messages' );
        if ( ! is_array( $flash_messages ) ) {
            $flash_messages = array();
        }
        if ( ! isset( $flash_messages[ $message_id ] ) ) {
            return false;
        }
        $content = $flash_messages[ $message_id ];
        if ( $delete ) {
            unset( $flash_messages[ $message_id ] );
            static::update_user_option( 'mainwp_flash_messages', $flash_messages );
        }
        return $content;
    }

    /**
     * Method update_flash_message()
     *
     * Check whenther or not to show the MainWP Message.
     *
     * @param mixed $message_id Notice ID.
     * @param mixed $content Content of message.
     *
     * @return boolean true|false.
     */
    public static function update_flash_message( $message_id, $content ) {
        $flash_messages = get_user_option( 'mainwp_flash_messages' );
        if ( ! is_array( $flash_messages ) ) {
            $flash_messages = array();
        }
        $current = isset( $flash_messages[ $message_id ] ) ? $flash_messages[ $message_id ] : '';
        if ( empty( $current ) ) {
            $current = $content;
        } else {
            $current .= '|' . $content;
        }
        $flash_messages[ $message_id ] = $current;
        return static::update_user_option( 'mainwp_flash_messages', $flash_messages );
    }

    /**
     * Method array_sort()
     *
     * Sort given array by given flags.
     *
     * @param mixed  $arr Array to sort.
     * @param mixed  $key Array key.
     * @param string $sort_flag Flags to sort by. Default = SORT_STRING.
     */
    public static function array_sort( &$arr, $key, $sort_flag = SORT_STRING ) {
        $sorter = array();
        $ret    = array();
        reset( $arr );
        foreach ( $arr as $ii => $val ) {
            if ( isset( $val[ $key ] ) ) {
                $sorter[ $ii ] = $val[ $key ];
            } elseif ( SORT_NUMERIC === $sort_flag ) {
                $sorter[ $ii ] = count( $sorter );
            }
        }
        asort( $sorter, $sort_flag );
        foreach ( $sorter as $ii => $val ) {
            $ret[ $ii ] = $arr[ $ii ];
        }
        $arr = $ret;
    }

    /**
     * Method array_sort_existed_keys()
     *
     * Sort given array by given flags.
     *
     * @param mixed  $arr Array to sort.
     * @param mixed  $key Array key.
     * @param string $sort_flag Flags to sort by. Default = SORT_STRING.
     */
    public static function array_sort_existed_keys( &$arr, $key, $sort_flag = SORT_STRING ) {
        $sorter = array();
        $ret    = array();
        reset( $arr );

        // get items with $key to sort.
        foreach ( $arr as $ii => $val ) {
            if ( isset( $val[ $key ] ) ) {
                $sorter[ $ii ] = $val[ $key ];
            }
        }
        asort( $sorter, $sort_flag );

        foreach ( $sorter as $ii => $val ) {
            $ret[ $ii ] = $arr[ $ii ];
        }

        // asign other items (without $keys).
        foreach ( $arr as $ii => $val ) {
            if ( ! isset( $val[ $key ] ) ) {
                $ret[ $ii ] = $val;
            }
        }

        $arr = $ret;
    }

    /**
     * Method numeric_filter()
     *
     * Filter given numeric.
     *
     * @param int $int_num Int number.
     * @return array $arr_ints Array filtered.
     */
    public static function numeric_filter( $int_num ) {
        return ( (string) (int) $int_num === (string) $int_num && 0 < $int_num ) ? $int_num : false;
    }

    /**
     * Method array_numeric_filter()
     *
     * Filter given numeric array.
     *
     * @param array $arr_ints Array to filter.
     * @return array $arr_ints Array filtered.
     */
    public static function array_numeric_filter( $arr_ints ) {
        $arr_ints = array_filter(
            $arr_ints,
            function ( $e ) {
                return ( (string) (int) $e === (string) $e && 0 < $e ) ? true : false;
            }
        );
        return $arr_ints;
    }

    /**
     * Method enabled_wp_seo()
     *
     * Check if Yoast SEO is enabled.
     *
     * @return boolean true|false.
     */
    public static function enabled_wp_seo() {
        if ( null === static::$enabled_wp_seo ) {
            static::$enabled_wp_seo = is_plugin_active( 'wordpress-seo-extension/wordpress-seo-extension.php' );
        }
        return static::$enabled_wp_seo;
    }

    /**
     * Method value_to_string()
     *
     * Value to string.
     *
     * @param mixed $var_value Value to convert to string.
     *
     * @return string Value that has been converted into a string.
     */
    public static function value_to_string( $var_value ) {
        if ( is_array( $var_value ) || is_object( $var_value ) ) {
            //phpcs:ignore -- for debug only
            return print_r( $var_value, true );
        } elseif ( is_string( $var_value ) ) {
            return $var_value;
        }
        return '';
    }

    /**
     * Get Health Site value.
     *
     * @param mixed $issue_counts Health site issues.
     *
     * @return array Health status value.
     */
    public static function get_site_health( $issue_counts ) {

        if ( empty( $issue_counts ) ) {
            $issue_counts = array(
                'good'        => 0,
                'recommended' => 0,
                'critical'    => 0,
            );
        }

        $totalTests  = intval( $issue_counts['good'] ) + intval( $issue_counts['recommended'] ) + intval( $issue_counts['critical'] ) * 1.5;
        $failedTests = intval( $issue_counts['recommended'] ) * 0.5 + $issue_counts['critical'] * 1.5;

        if ( empty( $totalTests ) ) {
                $val = 100;
        } else {
                $val = 100 - ceil( ( $failedTests / $totalTests ) * 100 );
        }

        if ( 0 > $val ) {
            $val = 0;
        }

        if ( 100 < $val ) {
            $val = 100;
        }

        return array(
            'val'      => $val,
            'critical' => $issue_counts['critical'],
        );
    }


    /**
     * Get HTTP code.
     *
     * @param int $code HTTP code.
     *
     * @return array $http_codes HTTP code.
     */
    public static function get_http_codes( $code = false ) {

        $http_codes = array(
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => '(Unused)',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
        );

        if ( false === $code ) {
            return $http_codes;
        }

        return isset( $http_codes[ $code ] ) ? $http_codes[ $code ] : '';
    }

    /**
     * Method valid_input_emails().
     *
     * @param string $emails Input emails string.
     *
     * @return string $valid_emails Valid emails string.
     */
    public static function valid_input_emails( $emails ) {

        if ( is_string( $emails ) ) {
            $emails = array_filter( explode( ',', $emails ) );
        }

        $valid_emails = array();
        if ( is_array( $emails ) ) {
            foreach ( $emails as $email ) {
                $email = esc_html( trim( $email ) );
                if ( ! empty( $email ) && ! in_array( $email, $valid_emails, true ) ) {
                    $valid_emails[] = $email;
                }
            }
        }
        $valid_emails = implode( ',', $valid_emails );
        return $valid_emails;
    }

    /**
     * Method check_image_file_name()
     *
     * Check if the file image.
     *
     * @param string $filename Contains image (file) name.
     *
     * @return true|false valid name or not.
     */
    public static function check_image_file_name( $filename ) {
        if ( validate_file( $filename ) ) {
            return false;
        }

        $allowed_files = array( 'jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico', 'webp', 'heic' );
        $file_ext      = array_values( array_slice( explode( '.', $filename ), -1 ) )[0];
        $file_ext      = strtolower( $file_ext );
        if ( ! in_array( $file_ext, $allowed_files ) ) {
            return false;
        }

        return true;
    }

    /**
     * Method check_abandoned()
     *
     * Get site's icon.
     *
     * @param mixed  $siteId site's id.
     * @param string $which to check plugin/theme.
     *
     * @return array result error or success
     * @throws \MainWP_Exception Error message.
     */
    public static function check_abandoned( $siteId = null, $which = '' ) { // phpcs:ignore -- NOSONAR - complex.
        if ( static::ctype_digit( $siteId ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( $siteId );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $error = '';
                try {
                    $information = MainWP_Connect::fetch_url_authed( $website, 'check_abandoned', array( 'which' => $which ) );
                    if ( is_array( $information ) && isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                        MainWP_Sync::sync_information_array( $website, $information['sync'] );
                        unset( $information['sync'] );
                    }
                } catch ( MainWP_Exception $e ) {
                    $error = $e->getMessage();
                }

                if ( '' !== $error ) {
                    return array( 'error' => $error );
                } elseif ( isset( $information['success'] ) && ! empty( $information['success'] ) ) {
                    return array( 'result' => 'success' );
                } else {
                    return array( 'undefined_error' => true );
                }
            }
        }
        return array( 'result' => 'NOSITE' );
    }

    /**
     * Get directory or slug of plugin.
     *
     * @param string $slug Plugin slug.
     *
     * @return string $value directory or slug of plugin.
     */
    public static function get_dir_slug( $slug ) {
        $value = '';
        if ( false === strpos( $slug, '/' ) ) {
            if ( false !== strpos( $slug, '.' ) ) {
                $value = substr( $slug, 0, strpos( $slug, '.' ) );
            }
        } else {
            $value = dirname( $slug );
        }
        if ( empty( $value ) ) {
            return $slug;
        }
        return $value;
    }

    /**
     * Metho get_siteview_mode().
     *
     * Get site view mode.
     *
     * @return string $viewmode Site view mode.
     */
    public static function get_siteview_mode() {
        $viewmode = get_user_option( 'mainwp_sitesviewmode' );
        if ( 'grid' !== $viewmode && 'table' !== $viewmode ) {
            $viewmode = 'table';
        }
        return $viewmode;
    }


    /**
     * Metho delete_file().
     *
     * Delete file.
     *
     * @param string $file_path File path.
     *
     * @return bool true|false.
     */
    public static function delete_file( $file_path ) {

        global $wp_filesystem;

        if ( ! empty( $file_path ) ) {
            if ( $wp_filesystem ) {
                if ( $wp_filesystem->exists( $file_path ) ) {
                    $wp_filesystem->delete( $file_path );
                }
            } elseif ( file_exists( $file_path ) ) {
                wp_delete_file( $file_path );
            }
            return true;
        }

        return false;
    }

    /**
     * Method get_disable_functions()
     *
     * Get disable functions.
     *
     * @return string
     */
    public function get_disable_functions() {
        if ( null === static::$disabled_functions ) {
            static::$disabled_functions = ini_get( 'disable_functions' );
        }
        return static::$disabled_functions;
    }

    /**
     * Method is_disable_functions()
     *
     * Check if it is disabled functions.
     *
     * @param string $func Function name to check.
     *
     * @return string
     */
    public function is_disabled_functions( $func ) {
        $dis_funcs = $this->get_disable_functions();

        if ( ! empty( $dis_funcs ) && ( false !== stristr( $dis_funcs, $func ) ) ) {
            return true;
        }
        return false;
    }

    /**
     * Method hook_verify_ping_nonce()
     *
     * Verify nonce without session and user id.
     *
     * @param bool   $input_value Boolean value, it should always be FALSE.
     * @param string $nonce Nonce to verify.
     * @param mixed  $siteid Site ID.
     *
     * @return mixed If verified return 1 or 2, if not return false.
     */
    public static function hook_verify_ping_nonce( $input_value, $nonce = '', $siteid = false ) {
        unset( $input_value );
        $action = 'pingnonce';
        return static::verify_site_nonce( $nonce, $action, $siteid );
    }

    /**
     * Method create_site_nonce()
     *
     * Create action nonce for site.
     *
     * @param mixed $action Action to perform.
     * @param mixed $siteid Site ID.
     *
     * @return string Custom nonce.
     */
    public static function create_site_nonce( $action = - 1, $siteid = false ) {
        if ( empty( $action ) || empty( $siteid || ! is_numeric( $siteid ) ) ) {
            return false;
        }
        return substr( wp_hash( 'site|' . $siteid . '|' . $action, 'nonce' ), - 12, 10 );
    }

    /**
     * Method verify_site_nonce()
     *
     * Verify nonce without session and user id.
     *
     * @param string $nonce Nonce to verify.
     * @param mixed  $action Action to perform.
     * @param mixed  $siteid Site ID.
     *
     * @return mixed If verified return 1 or 2, if not return false.
     */
    public static function verify_site_nonce( $nonce, $action = - 1, $siteid = 0 ) {
        $nonce = (string) $nonce;
        if ( empty( $nonce ) || empty( $siteid || ! is_numeric( $siteid ) ) ) {
            return false;
        }

        $expected = substr( wp_hash( 'site|' . $siteid . '|' . $action, 'nonce' ), - 12, 10 );
        if ( hash_equals( $expected, $nonce ) ) {
            return 1;
        }
        return false;
    }


    /**
     * Find for multi keywords.
     *
     * @param string $name_str string find on.
     * @param array  $words Array string input.
     * @return bool True|False.
     */
    public static function multi_find_keywords( $name_str, $words = array() ) {
        if ( ! is_array( $words ) ) {
            return false;
        }
        foreach ( $words as $word ) {
            if ( stristr( $name_str, $word ) ) {
                return true;

            }
        }
        return false;
    }

    /**
     * Merge values from right array to left array.
     *
     * @param  array $left_array left array.
     * @param  array $right_array right array.
     *
     * @return array $result result array.
     */
    public static function right_array_merge( $left_array, $right_array ) {
        if ( ! is_array( $left_array ) || ! is_array( $right_array ) ) {
            return array();
        }
        $result = array_intersect_key( $right_array, $left_array );
        return array_merge( $left_array, $result );
    }


    /**
     * Method get_set_deactivated_licenses_alerted().
     *
     * @param  string $slug Extension slug.
     * @param  bool   $time_value Time value.
     * @param  string $act get/set value.
     *
     * @return array $result result array.
     */
    public function get_set_deactivated_licenses_alerted( $slug, $time_value = false, $act = 'get' ) {
        if ( null === $this->last_deactivated_alerts ) {
            $this->last_deactivated_alerts = get_option( 'mainwp_cron_licenses_deactivated_alerted', array() );
            if ( ! is_array( $this->last_deactivated_alerts ) ) {
                $this->last_deactivated_alerts = array();
            }
        }
        if ( 'get' === $act ) {
            return isset( $this->last_deactivated_alerts[ $slug ] ) ? $this->last_deactivated_alerts[ $slug ] : 0;
        } elseif ( 'set' === $act ) {
            $this->last_deactivated_alerts[ $slug ] = intval( $time_value );
            get_option( 'mainwp_cron_licenses_deactivated_alerted', $this->last_deactivated_alerts );
        }
    }

    /**
     * Method get_remote_favicon().
     *
     * @param  string $url Url.
     * @param  string $favi favicon file name.
     * @param  int    $item_id item id.
     * @param  string $file_prefix favicon file prefix name.
     *
     * @return mixed result.
     */
    public static function get_remote_favicon( $url, $favi = '', $item_id = false, $file_prefix = '' ) { // phpcs:ignore -- NOSONAR - complex.

        if ( empty( $favi ) ) {
            $favi = 'favicon.ico';
        }

        if ( '/' !== substr( $url, - 1 ) ) {
            $url .= '/';
        }

        $favi_url = $url . $favi;

        $content = MainWP_Connect::get_file_content( $favi_url );

        if ( empty( $content ) && 'favicon.ico' === $favi ) {
            $favi_url = $url . 'favicon.png';
            $content  = MainWP_Connect::get_file_content( $favi_url ); // try other file.
        }

        if ( ! empty( $content ) ) {

            MainWP_System_Utility::get_wp_file_system();

            global $wp_filesystem;

            $dirs     = MainWP_System_Utility::get_mainwp_dir( 'icons', true );
            $iconsDir = $dirs[0];
            if ( $favi ) {

                $tmp = explode( '.', $favi );
                if ( 2 !== count( $tmp ) ) {
                    return false;
                }

                $favi_ext = $tmp[1];

                if ( empty( $item_id ) ) {
                    $item_id = time() . '-' . wp_rand( 100, 999 );
                }
                if ( ! empty( $file_prefix ) ) {
                    $filename = $file_prefix . $item_id . '.' . $favi_ext;
                } else {
                    $filename = 'favi-' . $item_id . '.' . $favi_ext;
                }

                $size     = $wp_filesystem->put_contents( $iconsDir . $filename, $content ); // phpcs:ignore --
                if ( $size ) {
                    MainWP_Logger::instance()->debug( 'Icon Cost Product size :: ' . $size );
                    return array(
                        'result' => 'success',
                        'file'   => $filename,
                        'dir'    => $iconsDir,
                    );
                } else {
                    return array( 'error' => 'Save icon file failed.' );
                }
            }
            return false;
        } else {
            return array( 'error' => esc_html__( 'Download icon file failed', 'mainwp' ) );
        }
    }

    /**
     * Method get_saved_favicon_url()
     *
     * @param string $favi Favicon file name.
     *
     * @return mixed $faviurl Favicon URL.
     */
    public static function get_saved_favicon_url( $favi ) {
        $faviurl = '';
        if ( ! empty( $favi ) ) {
            $dirs = MainWP_System_Utility::get_icons_dir();
            if ( file_exists( $dirs[0] . $favi ) ) {
                $faviurl = $dirs[1] . $favi;
            } else {
                $faviurl = '';
            }
        }
        return $faviurl;
    }

    /**
     * Method delete_saved_favicon()
     *
     * @param string $favi Favicon file name.
     *
     * @return bool Success result.
     */
    public static function delete_saved_favicon( $favi ) {
        if ( ! empty( $favi ) ) {
            $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();
            global $wp_filesystem;
            $dirs = MainWP_System_Utility::get_icons_dir();
            if ( $hasWPFileSystem && $wp_filesystem->exists( $dirs[0] . $favi ) ) {
                $wp_filesystem->delete( $dirs[0] . $favi );
                return true;
            }
        }
        return false;
    }

    /**
     * Delete icon file.
     *
     * @param string $sub_dir Sub dir file icon.
     * @param string $cost_icon file icon.
     */
    public function delete_uploaded_icon_file( $sub_dir, $cost_icon ) {
        $valid_file = 0 === validate_file( $cost_icon ) ? true : false;
        if ( $valid_file ) {
            $dirs = MainWP_System_Utility::get_mainwp_dir( $sub_dir, true );
            $f    = $dirs[0] . $cost_icon;
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
    }

    /**
     * Method get_table_orders().
     *
     * @param array $data table data.
     */
    public function get_table_orders( $data ) {

        $values = array(
            'orderby' => null,
            'order'   => null,
        );

        if ( isset( $data['order'] ) ) {
            $columns = isset( $data['columns'] ) ? wp_unslash( $data['columns'] ) : array();
            $ord_col = isset( $data['order'][0]['column'] ) ? sanitize_text_field( wp_unslash( $data['order'][0]['column'] ) ) : '';
            if ( isset( $columns[ $ord_col ] ) ) {
                $values = array(
                    'orderby' => isset( $columns[ $ord_col ]['data'] ) ? sanitize_text_field( wp_unslash( $columns[ $ord_col ]['data'] ) ) : '',
                    'order'   => isset( $data['order'][0]['dir'] ) ? sanitize_text_field( wp_unslash( $data['order'][0]['dir'] ) ) : '',
                );
            }
        }

        return $values;
    }

    /**
     * Method valid_file_check().
     *
     * @param string $path file path.
     * @param bool   $readable readable.
     *
     * @return bool is valid.
     */
    public static function valid_file_check( $path, $readable = true ) {
        $valid = is_string( $path ) && ! stristr( $path, '..' );
        if ( $valid && $readable ) {
            $valid = is_readable( $path );
        }
        return $valid;
    }


    /**
     * Handle sanitize POST data.
     *
     * @param array $data input data.
     *
     * @return array
     */
    public function sanitize_data( $data ) {
        if ( ! is_array( $data ) ) {
            return array();
        }

        // Sanitize all record values.
        return array_map(
            function ( $value ) {
                if ( ! is_array( $value ) ) {
                    return wp_strip_all_tags( $value );
                }

                return $value;
            },
            $data
        );
    }

    /**
     * String ends by.
     *
     * @param  mixed $str str.
     * @param  mixed $ends ends.
     * @return bool value value.
     */
    public static function string_ends_by( $str, $ends ) {
        if ( function_exists( '\str_ends_with' ) ) {
            return \str_ends_with( $str, $ends );
        } else {
            $ends_len = strlen( $ends );
            if ( $ends_len > strlen( $str ) ) {
                return false;
            }
            return substr( $str, -$ends_len ) === $ends;
        }
    }
    /**
     * Returns number in shorter format.
     *
     * @param  int $number str.
     * @return string $number Shorer number.
     */
    public static function short_number_format( $number ) {
        if ( $number > 999 && $number < 1000000 ) {
            // Anything between 1000 and 1000000.
            $number = number_format( $number / 1000, 1 ) . 'K';
        } elseif ( $number >= 1000000000 ) {
            // 1000000 or higher.
            $number = number_format( $number / 1000000, 2 ) . 'M';
        }
        return $number;
    }

    /**
     * Returns date in time ago format
     *
     * @param  mixed $ptime Date stamp.
     * @return string $string   Time elapsed string.
     */
    public static function time_elapsed_string( $ptime ) {
        $etime = time() - $ptime;

        if ( $etime < 1 ) {
            return '0 seconds';
        }

        $a        = array(
            365 * 24 * 60 * 60 => 'year',
            30 * 24 * 60 * 60  => 'month',
            24 * 60 * 60       => 'day',
            60 * 60            => 'hour',
            60                 => 'minute',
            1                  => 'second',
        );
        $a_plural = array(
            'year'   => 'years',
            'month'  => 'months',
            'day'    => 'days',
            'hour'   => 'hours',
            'minute' => 'minutes',
            'second' => 'seconds',
        );

        foreach ( $a as $secs => $str ) {
            $d = $etime / $secs;
            if ( $d >= 1 ) {
                $r = round( $d );
                return $r . ' ' . ( $r > 1 ? $a_plural[ $str ] : $str ) . ' ago';
            }
        }
    }

    /**
     * Returns language as flag.
     *
     * @param  string $language Language code.
     * @return void
     */
    public static function get_language_code_as_flag( $language ) {
        // Get the last 2 characters of the language code.
        $last_two_chars = ! empty( $language ) ? substr( $language, -2 ) : '';
        // Convert to lowercase.
        $lowercase_last_two_chars = strtolower( $last_two_chars );

        if ( function_exists( 'locale_get_display_name' ) ) {
            $language = locale_get_display_name( $language );
        }

        if ( 'et' === $lowercase_last_two_chars ) {
            $lowercase_last_two_chars = 'ee';
        }
        if ( 'sq' === $lowercase_last_two_chars ) {
            $lowercase_last_two_chars = 'al';
        }
        if ( 'ab' === $lowercase_last_two_chars ) {
            $lowercase_last_two_chars = 'dz';
        }

        echo '<span data-tooltip="' . esc_html__( 'Site Language: ', 'mainwp' ) . esc_attr( $language ) . '" data-position="left center" data-inverted=""><i class="small ' . esc_attr( $lowercase_last_two_chars ) . ' flag"></i></span>';
    }

    /**
     * Returns icon for the site indexability status.
     *
     * @param  int $status   Status, 1 or 0.
     * @return void.
     */
    public static function get_site_index_option_icon( $status ) {
        $icon    = '';
        $tooltip = '';
        if ( isset( $status ) && '' !== $status ) {
            if ( 1 === intval( $status ) ) {
                $icon    = 'green dot circle outline';
                $tooltip = 'Search engines can index this site.';
            } elseif ( 0 === intval( $status ) ) {
                $icon    = 'red ban';
                $tooltip = 'This site is blocking search engines.';
            }
        } else {
            $icon    = 'grey circle';
            $tooltip = 'Indexing status unknown. Resync the site or check manually in WordPress Settings > Reading.';
        }
        echo '<span data-tooltip="' . $tooltip . '" data-position="left center" data-inverted=""><i class="' . $icon . ' icon"></i></span>';  //phpcs:ignore -- ok.
    }
}
