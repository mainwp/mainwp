<?php
/**
 * Agency knowledge and record review.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Knowledge page controller.
 */
class MainWP_Knowledge {

    /**
     * The Knowledge screen needs the Dashboard parent to load its assets.
     *
     * @return void
     */
    public static function init_menu() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            return;
        }
        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Knowledge', 'mainwp' ),
            '<span id="mainwp-knowledge">' . esc_html__( 'Knowledge', 'mainwp' ) . '</span>',
            'manage_options',
            'Knowledge',
            array( static::class, 'render' )
        );
        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Knowledge', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'Knowledge',
                'href'       => 'admin.php?page=Knowledge',
                'icon'       => '<i class="book icon"></i>',
                'nosubmenu'  => true,
            ),
            0
        );
    }

    /**
     * Dashboard page access is required even when the base permission passes.
     *
     * @return void
     */
    public static function render() {
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() ) {
            echo '<div class="ui message">' . esc_html__( 'You do not have permission to access this knowledge.', 'mainwp' ) . '</div>';
            return;
        }
        $tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) && 'agency' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'agency' : 'review'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation is read-only.
        MainWP_UI::render_top_header( array( 'title' => esc_html__( 'Knowledge', 'mainwp' ) ) );
        MainWP_UI::render_page_navigation(
            array(
                array(
                    'title'  => esc_html__( 'Review', 'mainwp' ),
                    'href'   => 'admin.php?page=Knowledge',
                    'active' => 'review' === $tab,
                ),
                array(
                    'title'  => esc_html__( 'Agency', 'mainwp' ),
                    'href'   => 'admin.php?page=Knowledge&tab=agency',
                    'active' => 'agency' === $tab,
                ),
            )
        );
        ?>
        <div id="mainwp-knowledge-page" class="mainwp-main-content ui padded segment">
            <?php if ( 'agency' === $tab ) : ?>
            <h2 class="ui header">
                <?php esc_html_e( 'Agency knowledge', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Agency records reach the AI agents working on every site in this Dashboard. Every Dashboard user can read them, so keep client-specific details on the client.', 'mainwp' ); ?></div>
            </h2>
                <?php MainWP_Knowledge_UI::render_panel( 'agency', 0 ); ?>
            <?php else : ?>
                <?php static::render_review(); ?>
            <?php endif; ?>
        </div>
        <?php
        add_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) );
        // The shared header opens the page container, as on the Tags page.
        echo '</div>';
    }

    /**
     * Review controls use mini sizing beside the table.
     *
     * @return void
     */
    private static function render_review() {
        $filters = array(
            'status' => array(
                'label'   => __( 'Status', 'mainwp' ),
                'options' => array(
                    'unverified' => __( 'Unverified', 'mainwp' ),
                    'verified'   => __( 'Verified', 'mainwp' ),
                    'all'        => __( 'All', 'mainwp' ),
                ),
            ),
            'type'   => array(
                'label'   => __( 'Type', 'mainwp' ),
                'options' => array( '' => __( 'All types', 'mainwp' ) ) + MainWP_Knowledge_UI::type_labels(),
            ),
            'level'  => array(
                'label'   => __( 'Level', 'mainwp' ),
                'options' => array(
                    ''       => __( 'All levels', 'mainwp' ),
                    'agency' => __( 'Agency', 'mainwp' ),
                    'client' => __( 'Client', 'mainwp' ),
                    'site'   => __( 'Site', 'mainwp' ),
                ),
            ),
        );
        ?>
        <p><?php esc_html_e( 'Records saved by AI agents stay unverified until a person reviews them. Open a record, check it, and save it to mark it as verified, or delete it.', 'mainwp' ); ?></p>
        <div class="ui mini form" id="mainwp-knowledge-review-filters">
            <div class="fields">
                <?php foreach ( $filters as $key => $filter ) : ?>
                <div class="field">
                    <label for="mainwp-knowledge-filter-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $filter['label'] ); ?></label>
                    <select id="mainwp-knowledge-filter-<?php echo esc_attr( $key ); ?>" class="ui selection mini dropdown">
                        <?php foreach ( $filter['options'] as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'status' === $key ? 'unverified' : '', $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ui hidden message" id="mainwp-knowledge-review-message"></div>
        <div class="ui dimmable segment" id="mainwp-knowledge-review">
            <div class="ui dimmer mainwp-knowledge-loader" aria-hidden="true">
                <div class="ui loader" role="status" aria-label="<?php esc_attr_e( 'Loading...', 'mainwp' ); ?>"></div>
            </div>
            <table class="ui table" id="mainwp-knowledge-review-table" data-empty-default="<?php esc_attr_e( 'Nothing to review. Records saved by AI agents appear here until a person reviews them.', 'mainwp' ); ?>" data-empty-filtered="<?php esc_attr_e( 'No records match these filters.', 'mainwp' ); ?>">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Level', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Title', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Last edited by', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Updated', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Open', 'mainwp' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <?php
    }
}
