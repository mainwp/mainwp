<?php
/**
 * MainWP Updates report manager.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Updates report manager.
 *
 * For storing update report data and sending notifications for auto updates.
 */
class MainWP_Updates_Report_Manager {

    /**
     * Option name.
     */
    const OPTION_NAME = 'mainwp_updates_report_storage';

    /**
     * Update types.
     */
    const TYPE_PLUGIN      = 'plugins';
    const TYPE_THEME       = 'themes';
    const TYPE_CORE        = 'core';
    const TYPE_TRANSLATION = 'translations';

    const MAPPED_TYPES = array(
        'plugin' => self::TYPE_PLUGIN,
        'theme'  => self::TYPE_THEME,
        'trans'  => self::TYPE_TRANSLATION,
        'core'   => self::TYPE_CORE,
    );

    /**
     * Valid types.
     *
     * @return array
     */
    protected static function get_types() {
        return array(
            self::TYPE_PLUGIN,
            self::TYPE_THEME,
            self::TYPE_CORE,
            self::TYPE_TRANSLATION,
        );
    }



    /**
     * Initialize storage.
     *
     * Optional: call once before updates begin.
     *
     * @return void
     */
    public static function init() {
        MainWP_Utility::update_option( 'mainwp_automatic_updates_ready_to_send_notification', 0 );
        MainWP_Utility::update_option(
            self::OPTION_NAME,
            array(
                self::TYPE_PLUGIN      => array(),
                self::TYPE_THEME       => array(),
                self::TYPE_CORE        => array(),
                self::TYPE_TRANSLATION => array(),
            ),
        );
    }


    /**
     * Save update data for a specific website.
     *
     * @param object $website Child site object.
     * @param array  $data Update data.
     * @param string $type Update type.
     *
     * @return bool
     */
    public static function save_update_info( $website, $data, $type ) {

        if ( empty( $website ) || empty( $website->id ) || ! is_array( $data ) ) {
            return false;
        }

        $type = isset( self::MAPPED_TYPES[ $type ] )
        ? self::MAPPED_TYPES[ $type ]
        : null;

        if ( empty( $type ) ) {
            return false;
        }

        $save_items = array();

        if ( in_array( $type, array( self::TYPE_PLUGIN, self::TYPE_THEME, self::TYPE_TRANSLATION ), true ) ) {

            $updated_data = isset( $data['updated_data'] ) ? $data['updated_data'] : array();

            if ( ! is_array( $updated_data ) ) {
                $updated_data = array();
            }

            foreach ( $updated_data as $item ) {
                $item = MainWP_Utility::instance()->sanitize_data( $item );
                if ( ! empty( $item ) && isset( $item['name'] ) ) {
                    $save_items[] = $item;
                }
            }

            if ( empty( $save_items ) ) {
                return false;
            }
        } elseif ( self::TYPE_CORE === $type ) {
            $item            = MainWP_Utility::instance()->sanitize_data( $data );
            $item['success'] = isset( $item['upgrade'] ) && 'SUCCESS' === $item['upgrade'] ? 1 : 0;
            $save_items[]    = $item;

        } else {
            return false;
        }

        $count_bulk = count( $save_items );

        $dura = MainWP_Execution_Helper::get_run_time();

        if ( $dura && $count_bulk > 0 ) {
            $dura /= $count_bulk;
        }

        foreach ( $save_items as $item ) {
            $item['site_id'] = $website->id;
            if ( ! empty( $dura ) ) {
                $item['duration'] = $dura;
            }
            if ( ! static::save( $type, $item ) ) {
                return false;
            }
        }
        return true;
    }


    /**
     * Save an update items.
     *
     * @param string $type Update type.
     * @param array  $item Update data.
     *
     * @return bool
     */
    public static function save( $type, array $item ) {

        if ( ! in_array( $type, self::get_types(), true ) ) {
            return false;
        }

        $data = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        if ( ! isset( $data[ $type ] ) || ! is_array( $data[ $type ] ) ) {
            $data[ $type ] = array();
        }

        // Append a single item.
        $data[ $type ][] = $item;

        return update_option( self::OPTION_NAME, $data, false );
    }


    /**
     * Get update data.
     *
     * @param string|null $type Optional update type.
     *
     * @return array
     */
    public static function get( $type = null ) {

        $data = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $data ) ) {
            return array();
        }

        // If no data for any type, return empty array.
        if ( empty( $data[ self::TYPE_PLUGIN ] ) && empty( $data[ self::TYPE_THEME ] ) && empty( $data[ self::TYPE_CORE ] ) && empty( $data[ self::TYPE_TRANSLATION ] ) ) {
            return array();
        }

        if ( null === $type ) {
            return $data;
        }

        if ( ! in_array( $type, self::get_types(), true ) ) {
            return array();
        }

        return isset( $data[ $type ] ) ? $data[ $type ] : array();
    }


    /**
     * Send update info.
     *
     * @return void
     */
    public static function send_auto_update_info() {

        $auto_updated_items = static::get_auto_update_notice_data_grouped_by_site();

        if ( empty( $auto_updated_items ) ) {
            return;
        }

        // dashboard admin email and default general email settings.
        $admin_email_settings            = MainWP_Notification_Settings::get_default_emails_fields( 'auto_updates', '', true ); // get default subject and heading only.
        $admin_email_settings['disable'] = 0;
        // general email and default admin email.
        $admin_email_settings['recipients'] = MainWP_Notification_Settings::get_general_email(); // sent to general notification email only.

        $heading = isset( $admin_email_settings['heading'] ) ? $admin_email_settings['heading'] : '';

        $mail_content = MainWP_Notification_Template::instance()->get_template_html(
            'emails/mainwp-auto-updates-notification-email.php',
            array(
                'auto_updated_items' => $auto_updated_items,
                'heading'            => $heading,
            )
        );

        $plain_text = get_option( 'mainwp_daily_digest_plain_text', false );

        $params = array(
            'plain_text'   => apply_filters( 'mainwp_send_email_text_format', $plain_text, 'auto_updates' ),
            'mail_content' => $mail_content,
        );

        // send all individual daily digest to admin in one email.
        MainWP_Notification::start_notification_auto_updates_info( $admin_email_settings, $params ); // will send email to general notification email.
    }

    /**
     * Get websites to notice auto update.
     *
     * @return array
     */
    public static function get_auto_update_notice_data_grouped_by_site() { // phpcs:ignore -- NOSONAR - complexity.

        $data = static::get();

        if ( empty( $data ) ) {
            return array();
        }

        $updated_data = array();
        $sites_ids    = array();
        foreach ( $data as $type => $items ) {
            if ( ! in_array( $type, self::get_types(), true ) ) {
                continue;
            }
            if ( empty( $items ) || ! is_array( $items ) ) {
                continue;
            }
            foreach ( $items as $item ) {
                if ( ! isset( $item['site_id'] ) || empty( $item['site_id'] ) ) {
                    continue;
                }
                if ( empty( $item['success'] ) ) {
                    continue;
                }
                if ( ! isset( $updated_data[ $item['site_id'] ] ) ) {
                    $updated_data[ $item['site_id'] ] = array();
                }
                if ( ! isset( $updated_data[ $item['site_id'] ][ $type ] ) ) {
                    $updated_data[ $item['site_id'] ][ $type ] = array();
                }
                // add item to the site and type.
                $updated_data[ $item['site_id'] ][ $type ][] = $item;
                $sites_ids[ $item['site_id'] ]               = $item['site_id'];
            }
        }

        if ( ! empty( $sites_ids ) ) {
            $websites = MainWP_DB::instance()->query(
                MainWP_DB::instance()->get_sql_websites_for_current_user_by_params(
                    array(
                        'include' => $sites_ids,
                        'view'    => 'base_view',
                    )
                )
            );
            if ( ! empty( $websites ) && is_array( $websites ) ) {
                foreach ( $websites as $website ) {
                    if ( isset( $updated_data[ $website->id ] ) ) {
                        $updated_data[ $website->id ]['site_name'] = $website->name;
                        $updated_data[ $website->id ]['site_url']  = $website->url;
                        $updated_data[ $website->id ]['site_id']   = $website->id;
                    }
                }
            }
        }

        // skip sites that don't have site_name (deleted or not accessible).
        foreach ( $updated_data as $site_id => $site_data ) {
            if ( ! isset( $site_data['site_name'] ) ) {
                unset( $updated_data[ $site_id ] );
            }
        }
        return $updated_data;
    }
}
