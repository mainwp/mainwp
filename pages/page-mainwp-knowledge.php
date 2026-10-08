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
        if ( ! MainWP_System_Utility::is_admin() || true !== MainWP_Abilities_Util::check_rest_api_permission() || MainWP_Menu::is_disable_menu_item( 2, 'ai' ) ) {
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
                'title'                => esc_html__( 'Knowledge', 'mainwp' ),
                'parent_key'           => 'ai',
                'slug'                 => 'Knowledge',
                'href'                 => 'admin.php?page=Knowledge',
                'leftsub_order_level2' => 1,
            ),
            2
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
        self::render_navigation( $tab );
        ?>
        <div id="mainwp-knowledge-page" class="ui padded segment">
            <?php if ( 'agency' === $tab ) : ?>
                <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-knowledge-agency-info-message' ) ) : ?>
                    <div class="ui blue message">
                        <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-knowledge-agency-info-message"></i>
                        <?php esc_html_e( 'Agency records reach the AI agents working on every site in this Dashboard. Every Dashboard user can read them, so keep client-specific details on the client.', 'mainwp' ); ?>
                    </div>
                <?php endif; ?>
                <?php MainWP_Knowledge_UI::render_panel( 'agency', 0 ); ?>
            <?php else : ?>
                <?php self::render_review(); ?>
            <?php endif; ?>
        </div>
        <?php
        add_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) );
        // The shared header opens the page container, as on the Tags page.
        echo '</div>';
    }

    /**
     * Render the Knowledge page tabular navigation.
     *
     * @param string $active Active tab.
     *
     * @return void
     */
    private static function render_navigation( $active ) {
        ?>
        <div class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-knowledge-tabular-menu">
            <a class="item <?php echo esc_attr( 'review' === $active ? 'active' : '' ); ?>" data-tab="review" href="<?php echo esc_url( 'admin.php?page=Knowledge&tab=review' ); ?>">
                <i class="check circle icon"></i>
                <?php esc_html_e( 'Review', 'mainwp' ); ?>
            </a>
            <a class="item <?php echo esc_attr( 'agency' === $active ? 'active' : '' ); ?>" data-tab="agency" href="<?php echo esc_url( 'admin.php?page=Knowledge&tab=agency' ); ?>">
                <i class="building icon"></i>
                <?php esc_html_e( 'Agency', 'mainwp' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render the review filters, empty states, and grouped record accordions.
     *
     * @return void
     */
    private static function render_review() {
        $filters = array(
            'status' => array(
                'label'   => __( 'Status', 'mainwp' ),
                'options' => array(
                    'all'        => __( 'All', 'mainwp' ),
                    'unverified' => __( 'Unverified', 'mainwp' ),
                    'verified'   => __( 'Verified', 'mainwp' ),
                ),
            ),
            'level'  => array(
                'label'   => __( 'Level', 'mainwp' ),
                'options' => array(
                    'all'    => __( 'All levels', 'mainwp' ),
                    'agency' => __( 'Agency', 'mainwp' ),
                    'client' => __( 'Client', 'mainwp' ),
                    'site'   => __( 'Site', 'mainwp' ),
                ),
            ),
        );
        ?>
        <div class="mainwp-actions-bar" id="mainwp-knowledge-review-filters">
            <div class="ui mini form stackable grid">
                <div class="ui two column row">
                    <div class="left aligned middle aligned column">
                        <?php foreach ( $filters as $key => $filter ) : ?>
                        <select id="mainwp-knowledge-filter-<?php echo esc_attr( $key ); ?>" class="ui mini selection dropdown" aria-label="<?php echo esc_attr( $filter['label'] ); ?>">
                            <?php foreach ( $filter['options'] as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'status' === $key ? 'unverified' : 'all', $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endforeach; ?>
                    </div>
                    <div class="right aligned middle aligned column"></div>
                </div>
            </div>
        </div>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-knowledge-review-info-message' ) ) : ?>
            <div class="ui blue message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-knowledge-review-info-message"></i>
                <?php esc_html_e( 'Records saved by AI agents stay unverified until a person reviews them. Check each record, then verify, edit, or delete it.', 'mainwp' ); ?>
            </div>
        <?php endif; ?>
        <div class="ui hidden message" id="mainwp-knowledge-review-message"></div>
        <div class="hidden" id="mainwp-knowledge-review-empty" aria-hidden="true">
            <?php MainWP_UI::render_empty_page_placeholder( __( 'Nothing to review yet', 'mainwp' ), __( 'Knowledge records saved by AI agents will appear here until a person reviews them.', 'mainwp' ), '<em data-emoji=":bulb:" class="big"></em>' ); ?>
        </div>
        <div class="ui dimmable segment" id="mainwp-knowledge-review" style="padding:0!important;" data-empty-filtered="<?php esc_attr_e( 'No knowledge records match the selected filters.', 'mainwp' ); ?>">
            <div class="ui dimmer mainwp-knowledge-loader" aria-hidden="true">
                <div class="ui loader" role="status" aria-label="<?php esc_attr_e( 'Loading...', 'mainwp' ); ?>"></div>
            </div>
            <div class="hidden" id="mainwp-knowledge-review-filtered-empty" aria-hidden="true">
                <?php MainWP_UI::render_empty_element_placeholder( __( 'No matching records', 'mainwp' ), __( 'Try changing the status or level filters.', 'mainwp' ), '<em data-emoji=":mag:" class="medium"></em>' ); ?>
            </div>
            <?php
            MainWP_Knowledge_UI::render_sections(
                'review',
                array(
                    'container_class'   => 'mainwp-knowledge-review-sections',
                    'section_class'     => 'mainwp-knowledge-review-section',
                    'show_add'          => false,
                    'empty_title'       => __( 'No matching records', 'mainwp' ),
                    'empty_description' => __( 'No records of this type match the selected filters.', 'mainwp' ),
                )
            );
            ?>
        </div>
        <?php
    }
}
