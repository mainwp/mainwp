<?php
/**
 * MainWP Notes Widget
 *
 * Display current Child Site Notes.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Notes
 *
 * Grab Child Site Notes & Build Notes Widget.
 */
class MainWP_Notes { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class Name
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     *
     * Grab Child Site Notes & Render Widget.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
     */
    public static function render() {
        $current_wpid = MainWP_System_Utility::get_current_wpid();

        if ( ! MainWP_Utility::ctype_digit( $current_wpid ) ) {
            return;
        }

        $website  = MainWP_DB::instance()->get_website_by_id( $current_wpid, true );
        $note     = html_entity_decode( $website->note );
        $esc_note = MainWP_Utility::esc_content( $note );
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_notes_widget_title
                 *
                 * Filters the Notes widget title text.
                 *
                 * @param object $website Object containing the child site info.
                 *
                 * @since 4.1
                 */
                echo esc_html( apply_filters( 'mainwp_notes_widget_title', esc_html__( 'Notes', 'mainwp' ), $website ) );
                ?>
                <div class="sub header"><?php esc_html_e( 'Child site notes', 'mainwp' ); ?></div>
            </h2>
        </div>

        <div class="mainwp-scrolly-overflow">
            <?php
            /**
             * Action: mainwp_notes_widget_top
             *
             * Fires at the top of the Notes widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.1
             */
            do_action( 'mainwp_notes_widget_top', $website );

            if ( empty( $website->note ) ) {
                MainWP_UI::render_empty_element_placeholder();
            } else {
                ?>
                <div class="content">
                <?php
                echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput
                ?>
                </div>
                <?php
            }
            ?>
        <span style="display: none" id="mainwp-notes-<?php echo intval( $current_wpid ); ?>-note"><?php echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
        <?php
        /**
         * Action: mainwp_notes_widget_bottom
         *
         * Fires at the bottom of the Notes widget on the Individual site overview page.
         *
         * @param object $website Object containing the child site info.
         *
         * @since 4.1
         */
        do_action( 'mainwp_notes_widget_bottom', $website );
        ?>
        </div>
        <div class="ui one column grid mainwp-widget-footer">
            <div class="column">
                <a href="javascript:void(0)" class="ui basic mini button mainwp-edit-site-note" id="mainwp-notes-<?php echo intval( $website->id ); ?>" <?php echo '' !== $website->note ? '' : 'add-new="1"'; ?>><?php echo '' !== $website->note ? esc_html__( 'Edit Notes', 'mainwp' ) : esc_html__( 'Add Notes', 'mainwp' ); ?></a>
                <?php MainWP_UI::render_modal_edit_notes(); ?>
            </div>
        </div>
        <?php
    }
}
