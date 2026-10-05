<?php
/**
 * Client and site knowledge on the client overview.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shares client permissions and the editor with the existing knowledge panel.
 */
class MainWP_Client_Overview_Knowledge {

    /**
     * Keep widget registration and rendering on the same client access check.
     *
     * @return bool Whether the current client knowledge is readable.
     */
    public static function can_read() {
        $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only page selection.
        return $client_id > 0 && true === MainWP_DB_Knowledge::instance()->can_read(
            (object) array(
                'scope_type' => 'client',
                'scope_id'   => $client_id,
            )
        );
    }

    /**
     * The widget shares the client editor while site records stay read-only here.
     *
     * @return void
     */
    public static function render() {
        if ( ! static::can_read() ) {
            return;
        }
        $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- can_read() validated the page selection.
        $db        = MainWP_DB_Knowledge::instance();
        $can_write = true === $db->can_write(
            (object) array(
                'scope_type' => 'client',
                'scope_id'   => $client_id,
            )
        );
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'Knowledge', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Context, skills, and memories that AI agents receive for this client and its sites.', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div class="mainwp-widget-client-card mainwp-scrolly-overflow">
            <?php if ( ! $can_write ) : ?>
            <div class="ui message"><?php esc_html_e( 'You can view these records. Editing client records needs client management rights and access to every site of this client.', 'mainwp' ); ?></div>
            <?php endif; ?>
            <?php MainWP_Knowledge_UI::render_panel( 'client', $client_id, false, false ); ?>
            <?php static::render_sites( $client_id ); ?>
        </div>
        <?php
    }

    /**
     * Names and counts are fetched together before rendering any site rows.
     *
     * @param int $client_id Client identifier.
     *
     * @return void
     */
    private static function render_sites( $client_id ) {
        global $wpdb;
        $db     = MainWP_DB_Knowledge::instance();
        $sites  = $db->guard_read(
            static function () use ( $wpdb, $client_id ) {
                return $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM %i WHERE client_id = %d ORDER BY name', $wpdb->prefix . 'mainwp_wp', $client_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- one roster query avoids loading a site separately for each row.
            }
        );
        $counts = is_wp_error( $sites ) ? $sites : $db->get_scope_counts( 'site', array_column( $sites, 'id' ) );
        ?>
        <div class="ui small header">
            <?php esc_html_e( 'Site knowledge', 'mainwp' ); ?>
            <div class="sub header"><?php esc_html_e( 'Records saved on each site of this client. Open a site to edit its records.', 'mainwp' ); ?></div>
        </div>
        <?php
        if ( is_wp_error( $counts ) ) {
            $strings = MainWP_Knowledge_UI::js_strings();
            echo '<div class="ui red message">' . esc_html( $strings['failed'] ) . '</div>';
            return;
        }
        if ( empty( $counts ) ) {
            echo '<p>' . esc_html__( 'No sites to show.', 'mainwp' ) . '</p>';
            return;
        }
        ?>
        <table class="ui compact table">
            <tbody class="ui accordion mainwp-knowledge-site-accordion">
                <?php foreach ( $sites as $site ) : ?>
                    <?php
                    $site_id = (int) $site->id;
                    if ( ! isset( $counts[ $site_id ] ) ) {
                        continue;
                    }
                    $count    = $counts[ $site_id ];
                    $site_url = admin_url( 'admin.php?page=managesites&knowledgeid=' . $site_id );
                    ?>
                <tr class="title" data-site-id="<?php echo esc_attr( $site_id ); ?>">
                    <td class="accordion-trigger collapsing"><i class="icon dropdown"></i></td>
                    <td><?php echo esc_html( $site->name ); ?></td>
                    <td>
                        <?php if ( $count['total'] ) : ?>
                        <span class="ui mini label"><?php /* translators: %d: record count */ printf( esc_html__( 'Context: %d', 'mainwp' ), (int) $count['context'] ); ?></span>
                        <span class="ui mini label"><?php /* translators: %d: record count */ printf( esc_html__( 'Skills: %d', 'mainwp' ), (int) $count['skill'] ); ?></span>
                        <span class="ui mini label"><?php /* translators: %d: record count */ printf( esc_html__( 'Memory: %d', 'mainwp' ), (int) $count['memory'] ); ?></span>
                        <?php else : ?>
                            <?php esc_html_e( 'No records', 'mainwp' ); ?>
                        <?php endif; ?>
                        <?php if ( $count['unverified'] ) : ?>
                        <span class="ui mini yellow label"><?php /* translators: %d: record count */ echo esc_html( sprintf( _n( '%d unverified', '%d unverified', $count['unverified'], 'mainwp' ), $count['unverified'] ) ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="right aligned collapsing"><a class="ui mini basic button" href="<?php echo esc_url( $site_url ); ?>"><?php esc_html_e( 'View on the site', 'mainwp' ); ?></a></td>
                </tr>
                <tr class="content">
                    <td colspan="4">
                        <div class="dimmable mainwp-knowledge-site-list" data-scope-type="site" data-scope-id="<?php echo esc_attr( $site_id ); ?>" data-can-write="0" data-site-url="<?php echo esc_url( $site_url ); ?>">
                            <div class="ui dimmer mainwp-knowledge-loader" aria-hidden="true">
                                <div class="ui loader" role="status" aria-label="<?php esc_attr_e( 'Loading...', 'mainwp' ); ?>"></div>
                            </div>
                            <div class="ui hidden message mainwp-knowledge-message"></div>
                            <table class="ui compact table">
                                <thead><tr><th><?php esc_html_e( 'Title', 'mainwp' ); ?></th><th class="collapsing"><?php esc_html_e( 'Type', 'mainwp' ); ?></th><th class="collapsing"><?php esc_html_e( 'Last edited by', 'mainwp' ); ?></th><th class="collapsing"></th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
