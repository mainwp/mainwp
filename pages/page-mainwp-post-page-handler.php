<?php
/**
 * Post Page Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Page_Handler
 *
 * @uses MainWP_Bulk_Add
 */
class MainWP_Post_Page_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method add_meta()
     *
     * Add post meta data defined in $_POST superglobal for post with given ID.
     *
     * @since 1.2.0
     *
     * @param int $post_ID Post or Page ID.
     * @return mixed False or add_post_meta()
     */
    public static function add_meta( $post_ID ) {
        $post_ID = (int) $post_ID;

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $metakeyselect = isset( $_POST['metakeyselect'] ) ? sanitize_text_field( wp_unslash( $_POST['metakeyselect'] ) ) : '';
        $metakeyinput  = isset( $_POST['metakeyinput'] ) ? sanitize_text_field( wp_unslash( $_POST['metakeyinput'] ) ) : '';
        $metavalue     = isset( $_POST['metavalue'] ) ? sanitize_text_field( wp_unslash( $_POST['metavalue'] ) ) : '';
        if ( is_string( $metavalue ) ) {
            $metavalue = trim( $metavalue );
        }
        // phpcs:enable

        if ( ( ( '#NONE#' !== $metakeyselect ) && ! empty( $metakeyselect ) ) || ! empty( $metakeyinput ) ) {
            if ( '#NONE#' !== $metakeyselect ) {
                $metakey = $metakeyselect;
            }

            if ( $metakeyinput ) {
                $metakey = $metakeyinput;
            }

            if ( is_protected_meta( $metakey, 'post' ) || ! current_user_can( 'add_post_meta', $post_ID, $metakey ) ) {
                return false;
            }

            $metakey = wp_slash( $metakey );

            return add_post_meta( $post_ID, $metakey, $metavalue );
        }

        return false;
    }

    /**
     * Method ajax_add_meta()
     *
     * Ajax process to add post meta data.
     *
     * @uses \MainWP\Dashboard\MainWP_Post_Handler::secure_request()
     * @uses \MainWP\Dashboard\MainWP_Post::list_meta_row()
     */
    public static function ajax_add_meta() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        MainWP_Post_Handler::instance()->secure_request( 'mainwp_post_addmeta' );

        $c   = 0;
        $pid = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

        if ( isset( $_POST['metakeyselect'] ) || isset( $_POST['metakeyinput'] ) ) {
            if ( ! current_user_can( 'edit_post', $pid ) ) {
                wp_die( -1 );
            }
            if ( isset( $_POST['metakeyselect'] ) && '#NONE#' === $_POST['metakeyselect'] && empty( $_POST['metakeyinput'] ) ) {
                wp_die( 1 );
            }
            $mid = static::add_meta( $pid );
            if ( ! $mid ) {
                wp_send_json( array( 'error' => esc_html__( 'Please provide a custom field value.', 'mainwp' ) ) );
            }

            $meta = get_metadata_by_mid( 'post', $mid );
            $meta = get_object_vars( $meta );
            $data = MainWP_Post::list_meta_row( $meta, $c );

        } elseif ( isset( $_POST['delete_meta'] ) && 'yes' === $_POST['delete_meta'] ) {
            $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

            check_ajax_referer( "delete-meta_$id", 'meta_nonce' );
            $meta = get_metadata_by_mid( 'post', $id );
            if ( ! $meta ) {
                wp_send_json( array( 'ok' => 1 ) );
            }

            if ( is_protected_meta( $meta->meta_key, 'post' ) || ! current_user_can( 'delete_post_meta', $meta->post_id, $meta->meta_key ) ) {
                wp_die( -1 );
            }

            if ( delete_meta( $meta->meta_id ) ) {
                wp_send_json( array( 'ok' => 1 ) );
            }

            wp_die( 0 );

        } else {
            $mid   = isset( $_POST['meta'] ) ? (int) key( wp_unslash( $_POST['meta'] ) ) : 0; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $key   = isset( $_POST['meta'][ $mid ]['key'] ) ? sanitize_text_field( wp_unslash( $_POST['meta'][ $mid ]['key'] ) ) : '';
            $value = isset( $_POST['meta'][ $mid ]['value'] ) ? sanitize_text_field( wp_unslash( $_POST['meta'][ $mid ]['value'] ) ) : '';
            if ( '' === trim( $key ) ) {
                wp_send_json( array( 'error' => esc_html__( 'Please provide a custom field name.', 'mainwp' ) ) );
            }
            $meta = get_metadata_by_mid( 'post', $mid );
            if ( ! $meta ) {
                wp_die( 0 );
            }
            if ( is_protected_meta( $meta->meta_key, 'post' ) || is_protected_meta( $key, 'post' ) ||
                ! current_user_can( 'edit_post_meta', $meta->post_id, $meta->meta_key ) ||
                ! current_user_can( 'edit_post_meta', $meta->post_id, $key ) ) {
                wp_die( -1 );
            }
            if ( $meta->meta_value !== $value || $meta->meta_key !== $key ) {
                $u = update_metadata_by_mid( 'post', $mid, $value, $key );
                if ( ! $u ) {
                    wp_die( 0 );
                }
            }

            $data = MainWP_Post::list_meta_row(
                array(
                    'meta_key'   => $key, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- deprecated, compatible.
                    'meta_value' => $value, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- deprecated, compatible.
                    'meta_id'    => $mid,
                ),
                $c
            );
        }

        wp_send_json( array( 'result' => $data ) );
    }


    /**
     * Method ajax_handle_get_categories()
     *
     * Get categories.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_ids()
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_ids()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function ajax_handle_get_categories() { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $websites = array();
        if ( isset( $_REQUEST['sites'] ) && ( '' !== $_REQUEST['sites'] ) ) {
            $siteIds          = explode( ',', urldecode( wp_unslash( $_REQUEST['sites'] ) ) ); // do not sanitize encoded values.
            $siteIdsRequested = array();
            foreach ( $siteIds as $siteId ) {
                if ( ! MainWP_Utility::ctype_digit( $siteId ) ) {
                    continue;
                }
                $siteIdsRequested[] = $siteId;
            }

            $websites = MainWP_DB::instance()->get_websites_by_ids( $siteIdsRequested );
        } elseif ( isset( $_REQUEST['groups'] ) && ( '' !== $_REQUEST['groups'] ) ) {
            $groupIds          = explode( ',', sanitize_text_field( urldecode( wp_unslash( $_REQUEST['groups'] ) ) ) );  // sanitize ok.
            $groupIdsRequested = array();
            foreach ( $groupIds as $groupId ) {
                if ( ! MainWP_Utility::ctype_digit( $groupId ) ) {
                    continue;
                }
                $groupIdsRequested[] = $groupId;
            }

            $websites = MainWP_DB::instance()->get_websites_by_group_ids( $groupIdsRequested );
        } elseif ( isset( $_REQUEST['clients'] ) && ( '' !== $_REQUEST['clients'] ) ) {
            $clientIds          = explode( ',', sanitize_text_field( urldecode( wp_unslash( $_REQUEST['clients'] ) ) ) );  // sanitize ok.
            $clientIdsRequested = array();
            foreach ( $clientIds as $clientId ) {

                if ( ! MainWP_Utility::ctype_digit( $clientId ) ) {
                    continue;
                }
                $clientIdsRequested[] = $clientId;
            }

            $data_fields = array(
                'id',
                'url',
                'name',
                'categories',
                'sync_errors',
            );
            $websites    = MainWP_DB_Client::instance()->get_websites_by_client_ids(
                $clientIdsRequested,
                array(
                    'select_data' => $data_fields,
                )
            );
        }

        $selectedCategories = array();

        $is_cpt = isset( $_POST['custom_post_type'] ) && ! empty( $_POST['custom_post_type'] ) ? true : false;

        if ( isset( $_REQUEST['selected_categories'] ) && ( '' !== $_REQUEST['selected_categories'] ) ) {
            $selectedCategories = explode( ',', sanitize_text_field( urldecode( wp_unslash( $_REQUEST['selected_categories'] ) ) ) );
        }

        if ( ! is_array( $selectedCategories ) ) {
            $selectedCategories = array();
        }

        $allCategories_new_tree = array();
        $allCategories          = array( 'Uncategorized' );

        if ( ! empty( $websites ) ) {
            foreach ( $websites as $website ) {
                if ( ! $is_cpt ) {
                    $new_cats = json_decode( $website->categories, true );
                    if ( is_array( $new_cats ) && ! empty( $new_cats ) ) {
                        $current = current( $new_cats );
                        if ( is_array( $current ) && ! empty( $current ) ) { // new site's category format data.
                            static::arrange_categories_list( $new_cats, $allCategories_new_tree );
                        } elseif ( is_string( $current ) ) { // old format.
                            $allCategories = array_unique( array_merge( $allCategories, $new_cats ) );
                        }
                    }
                } else {
                    $custom_categories = apply_filters( 'mainwp_edit_post_get_categories', false, $website, $_REQUEST );
                    if ( is_array( $custom_categories ) && ! empty( $custom_categories ) ) {
                        static::arrange_categories_list( $custom_categories, $allCategories_new_tree );
                    }
                }
            }
        }

        $allCategories = array_unique( array_merge( $allCategories, $selectedCategories ) );

        ob_start();
        echo '<div class="item" data-value="Uncategorized" class="sitecategory-list">Uncategorized</div>';

        if ( ! empty( $allCategories ) || ! empty( $allCategories_new_tree ) ) {
            ?>
            <?php
            $check_printed_cats_names = array();

            if ( ! empty( $allCategories_new_tree ) ) {
                // print new casts list.
                static::print_catergories_tree( $allCategories_new_tree, $check_printed_cats_names );
            }

            if ( ! $is_cpt && ! empty( $allCategories ) ) {
                echo '<div class="ui horizontal divider"></div>';
                natcasesort( $allCategories );
                foreach ( $allCategories as $category ) {
                    if ( 'Uncategorized' === $category || isset( $check_printed_cats_names[ $category ] ) ) {
                        continue; // printed.
                    }
                    echo '<div class="item" data-value="' . esc_attr( $category ) . '" class="sitecategory-list">' . esc_html( $category ) . '</div>';
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $output = ob_get_clean();
        wp_die( wp_json_encode( array( 'content' => $output ) ) );
    }

    /**
     * Method print_catergories_tree()
     *
     * @param array $print_cats categories to print.
     * @param array $check_printed_cats_names check printed cats slugs.
     */
    public static function print_catergories_tree( $print_cats, &$check_printed_cats_names = array() ) { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace -- NOSONAR - complexity.
        foreach ( $print_cats as $item ) {

            $level   = isset( $item['level'] ) ? $item['level'] : 0;
            $term_pt = isset( $item['term_post_type'] ) && ! empty( $item['term_post_type'] ) ? sanitize_text_field( wp_unslash( $item['term_post_type'] ) ) : '';

            $print_slug = $item['slug'];

            if ( ! empty( $term_pt ) ) {
                $print_slug .= '_' . $term_pt;
            }

            if ( 'Uncategorized' !== $item['name'] && ! in_array( $print_slug, $check_printed_cats_names, true ) ) {
                $cls = 'category-select-item-sub' . ( ! empty( $level ) ? intval( $level ) : '' );

                $check_printed_cats_names[] = $print_slug;

                if ( ! empty( $term_pt ) ) {
                    $cat_val = wp_json_encode(
                        array(
                            'name'        => esc_html( $item['name'] ),
                            'slug'        => esc_html( $item['slug'] ),
                            'taxonomy'    => esc_html( $item['taxonomy'] ),
                            'parent'      => esc_html( $item['parent'] ),
                            'description' => esc_html( $item['description'] ),
                        )
                    );
                    $cat_val = ! empty( $cat_val ) ? '_custom_term_' . esc_attr( base64_encode( $cat_val ) ) : ''; //phpcs:ignore -- ok.
                } else {
                    $cat_val = esc_attr( $item['name'] );
                }

                $title = ! empty( $term_pt ) ? '<strong>' . esc_html( $item['name'] ) . '</strong>' : esc_html( $item['name'] );
                echo '<div class="item ' . esc_attr( $cls ) . '" data-value="' . $cat_val . '" data-slug="' . esc_attr( $item['slug'] ) . '" post-type="' . esc_attr( $term_pt ) . '"class="sitecategory-list">' . $title . '</div>'; //phpcs:ignore -- ok.
            }

            if ( ! empty( $item['children'] ) ) {
                static::print_catergories_tree( $item['children'], $check_printed_cats_names );
            }
        }
    }

    /**
     * Method arrange_categories_list()
     *
     * Tweaked John#105641 at StackOver#4284616.
     *
     * @param array $categories categories.
     * @param array $save_all_cats_tree all categories tree.
     */
    public static function arrange_categories_list( $categories, &$save_all_cats_tree ) { //phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $save_all_cats_tree ) ) {
            $save_all_cats_tree = array();
        }

        if ( ! is_array( $categories ) ) {
            return;
        }

        $tree_cats  = $save_all_cats_tree;
        $all_cats   = array();
        $child_cats = array();

        foreach ( $categories as $cat ) {

            if ( ! is_array( $cat ) || empty( $cat['name'] ) ) {
                continue;
            }

            $cat['children'] = array();
            $term_id         = $cat['term_id'];

            // If this is a top-level.
            if ( empty( $cat['parent'] ) ) {
                $cat['level']         = 0;
                $all_cats[ $term_id ] = $cat;
                $tree_cats[]          =& $all_cats[ $term_id ];
                // If this isn't a top-level.
            } else {
                $cat['level']           = isset( $all_cats[ $cat['parent'] ] ) && isset( $all_cats[ $cat['parent'] ]['level'] ) ? $all_cats[ $cat['parent'] ]['level'] + 1 : 1;
                $child_cats[ $term_id ] = $cat;
            }
        }

        $stop  = count( $categories );
        $limit = 0;
        $count = count( $child_cats );
        // Process child cats.
        while ( $count > 0 && $limit < $stop ) {
            foreach ( $child_cats as $cat ) {
                $term_id = $cat['term_id'];
                $pid     = isset( $cat['parent'] ) ? $cat['parent'] : -1;

                if ( isset( $all_cats[ $pid ] ) ) {
                    $cat['level']                   = isset( $all_cats[ $pid ] ) && isset( $all_cats[ $pid ]['level'] ) ? $all_cats[ $pid ]['level'] + 1 : 1;
                    $all_cats[ $term_id ]           = $cat;
                    $all_cats[ $pid ]['children'][] =& $all_cats[ $term_id ];
                    unset( $child_cats[ $cat['term_id'] ] );
                }
            }
            ++$limit;
        }
        $save_all_cats_tree = $tree_cats; // to prevent it deleted by reference.
    }

    /**
     * Method posting_bulk()
     *
     * Create bulk posts on sites.
     */
    public static function posting_bulk() {
        $p_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! isset( $_GET['posting_nonce'] ) || ( isset( $_GET['posting_nonce'] ) && ! wp_verify_nonce( sanitize_key( $_GET['posting_nonce'] ), 'posting_nonce_' . $p_id ) ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            wp_die( 'Invalid request!' );
        }

        $posting_bulk_sites = apply_filters( 'mainwp_posts_posting_bulk_sites', false );
        ?>
        <input type="hidden" name="bulk_posting_id" id="bulk_posting_id" value="<?php echo intval( $p_id ); ?>"/>
        <?php
        if ( ! $posting_bulk_sites ) {
            static::posting( $p_id );
        } else {
            static::posting_prepare( $p_id );
        }
    }

    /**
     * Method posting()
     *
     * Create bulk posts on sites.
     *
     * @param int $post_id Post or Page ID.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
     * @uses \MainWP\Dashboard\MainWP_Bulk_Add::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public static function posting( $post_id ) { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $p_id = $post_id;

        $edit_id = get_post_meta( $post_id, '_mainwp_edit_post_id', true );

        ?>
        <div class="ui modal" id="mainwp-posting-post-modal">
            <i class="close icon"></i>
            <div class="header"><?php $edit_id ? esc_html_e( 'Edit Post', 'mainwp' ) : esc_html_e( 'New Post', 'mainwp' ); ?></div>
            <div class="scrolling content">
                <?php
                /**
                 * Before Post post action
                 *
                 * Fires right before posting the 'bulkpost' to child sites.
                 *
                 * @param int $p_id Page ID.
                 *
                 * @since Unknown
                 */
                do_action( 'mainwp_bulkpost_before_post', $p_id );

                $skip_post = false;
                if ( $p_id && 'yes' === get_post_meta( $p_id, '_mainwp_skip_posting', true ) ) {
                    $skip_post = true;
                    wp_delete_post( $p_id, true );
                }

                if ( ! $skip_post ) {
                    if ( $p_id ) {
                        static::posting_posts( $p_id, 'posting' );
                    } else {
                        ?>
                    <div class="error">
                        <p>
                            <strong><?php esc_html_e( 'ERROR', 'mainwp' ); ?></strong>: <?php esc_html_e( 'An undefined error occured!', 'mainwp' ); ?>
                        </p>
                    </div>
                        <?php
                    }
                }
                ?>
        </div>
        <div class="actions">
            <?php do_action( 'mainwp_posts_posting_popup_actions', $post_id ); ?>
            <a href="admin.php?page=PostBulkAdd" class="ui green button new-bulk-post"><?php esc_html_e( 'New Post', 'mainwp' ); ?></a>
        </div>
    </div>
    <div class="ui active inverted dimmer" id="mainwp-posting-running">
    <div class="ui indeterminate large text loader"><?php esc_html_e( 'Running ...', 'mainwp' ); ?></div>
    </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( "#mainwp-posting-running" ).hide();
                jQuery( "#mainwp-posting-post-modal" ).modal( {
                    closable: true,
                    onHide: function() {
                        location.href = 'admin.php?page=PostBulkManage';
                    }
                } ).modal( 'show' );
            } );
        </script>
        <?php
    }

    /**
     * Method posting_prepare()
     *
     * Posting posts.
     *
     * @param int $post_id Post or Page ID.
     */
    public static function posting_prepare( $post_id ) {
        $edit_id = get_post_meta( $post_id, '_mainwp_edit_post_id', true );
        ?>
        <div class="ui modal" id="mainwp-posting-post-modal">
            <i class="close icon"></i>
            <div class="header"><?php $edit_id ? esc_html_e( 'Edit Post', 'mainwp' ) : esc_html_e( 'New Post', 'mainwp' ); ?></div>
            <div class="scrolling content">
                <?php
                if ( $post_id ) {
                    static::posting_posts( $post_id, 'preparing' );
                } else {
                    ?>
                    <div class="error">
                        <p>
                            <strong><?php esc_html_e( 'ERROR', 'mainwp' ); ?></strong>: <?php esc_html_e( 'An undefined error occured!', 'mainwp' ); ?>
                        </p>
                    </div>
                    <?php
                }
                ?>
            </div>
        <div class="actions">
            <a href="admin.php?page=PostBulkAdd" class="ui green button"><?php esc_html_e( 'New Post', 'mainwp' ); ?></a>
        </div>
    </div>
    <div class="ui active inverted dimmer" id="mainwp-posting-running">
    <div class="ui indeterminate large text loader"><?php esc_html_e( 'Running ...', 'mainwp' ); ?></div>
    </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( "#mainwp-posting-running" ).hide();
                jQuery( "#mainwp-posting-post-modal" ).modal( {
                    closable: true,
                    onHide: function() {
                        location.href = 'admin.php?page=PostBulkManage';
                    }
                } ).modal( 'show' );
                mainwp_post_posting_start_next( true );
            } );
        </script>
        <?php
    }


    /**
     * Method ajax_posting_posts()
     *
     * Ajax Posting posts.
     */
    public static function ajax_posting_posts() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_post_postingbulk' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : false;
        if ( $post_id ) {
            static::posting_posts( $post_id, 'ajax_posting' );
        }
         // phpcs:enable
        die();
    }

    /**
     * Method ajax_get_sites_of_groups()
     *
     * Ajax Get sites of groups.
     */
    public static function ajax_get_sites_of_groups() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_get_sites_of_groups' );
        $groups   = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : '';
        $websites = MainWP_DB::instance()->get_websites_by_group_ids( $groups );
         // phpcs:enable
        $site_Ids = array();
        if ( $websites ) {
            foreach ( $websites as $website ) {
                $site_Ids[] = $website->id;
            }
        }
        die( wp_json_encode( $site_Ids ) );
    }

    /**
     * Method posting_posts()
     *
     * Posting posts.
     *
     * @param int    $post_id Post or Page ID.
     * @param string $what What posting process.
     */
    public static function posting_posts( $post_id, $what ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        if ( empty( $post_id ) ) {
            return false;
        }

        $succes_message = '';
        $edit_id        = get_post_meta( $post_id, '_mainwp_edit_post_id', true );
        if ( $edit_id ) {
            $succes_message = esc_html__( 'Post has been updated successfully', 'mainwp' );
        } else {
            $succes_message = esc_html__( 'New post created', 'mainwp' );
        }

        $id    = $post_id;
        $_post = get_post( $id );

        if ( $_post ) {
            $selected_by      = 'site';
            $selected_groups  = array();
            $selected_sites   = array();
            $selected_clients = array();

            if ( 'posting' === $what || 'preparing' === $what ) {
                $selected_by      = get_post_meta( $id, '_selected_by', true );
                $val              = get_post_meta( $id, '_selected_sites', true );
                $selected_sites   = MainWP_System_Utility::maybe_unserialyze( $val );
                $val              = get_post_meta( $id, '_selected_groups', true );
                $selected_groups  = MainWP_System_Utility::maybe_unserialyze( $val );
                $selected_clients = get_post_meta( $id, '_selected_clients', true );
                $selected_by      = apply_filters( 'mainwp_posting_post_selected_by', $selected_by, $id );
            } elseif ( 'ajax_posting' === $what ) {
                $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( $site_id ) {
                    $selected_sites = array( $site_id );
                }
            }

            $selected_sites   = apply_filters( 'mainwp_posting_post_selected_sites', $selected_sites, $id );
            $selected_groups  = apply_filters( 'mainwp_posting_selected_groups', $selected_groups, $id );
            $selected_clients = apply_filters( 'mainwp_posting_selected_clients', $selected_clients, $id );

            if ( 'preparing' !== $what ) {
                $post_category = base64_decode( get_post_meta( $id, '_categories', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

                $post_tags   = base64_decode( get_post_meta( $id, '_tags', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                $post_slug   = base64_decode( get_post_meta( $id, '_slug', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                $post_custom = get_post_custom( $id );

                $galleries           = get_post_galleries( $id, false );
                $post_gallery_images = array();

                if ( is_array( $galleries ) ) {
                    foreach ( $galleries as $gallery ) {
                        if ( isset( $gallery['ids'] ) ) {
                            $attached_images = explode( ',', $gallery['ids'] );
                            foreach ( $attached_images as $attachment_id ) {
                                $attachment = get_post( $attachment_id );
                                if ( $attachment ) {
                                    $post_gallery_images[] = array(
                                        'id'          => $attachment_id,
                                        'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
                                        'caption'     => MainWP_Utility::esc_content( $attachment->post_excerpt, 'mixed' ),
                                        'description' => $attachment->post_content,
                                        'src'         => $attachment->guid,
                                        'image_url'   => wp_get_attachment_image_url( $attachment_id ), // to fix src/guid missing the file name.
                                        'title'       => htmlspecialchars( $attachment->post_title ),
                                    );
                                }
                            }
                        }
                    }
                }

                include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php'; // NOSONAR - WP compatible.
                $featured_image_id   = get_post_thumbnail_id( $id );
                $post_featured_image = null;
                $featured_image_data = null;
                $mainwp_upload_dir   = wp_upload_dir();

                // to fix.
                $post_status = $_post->post_status;
                if ( 'publish' === $post_status ) {
                    $post_status = get_post_meta( $id, '_edit_post_status', true );
                }

                /**
                 * Post status
                 *
                 * Sets post status when posting 'bulkpost' to child sites.
                 *
                 * @param int $id Post ID.
                 *
                 * @since Unknown
                 */
                $post_status = apply_filters( 'mainwp_posting_bulkpost_post_status', $post_status, $id );
                $new_post    = array(
                    'post_title'     => $_post->post_title,
                    'post_content'   => $_post->post_content,
                    'post_status'    => $post_status,
                    'post_date'      => $_post->post_date,
                    'post_date_gmt'  => $_post->post_date_gmt,
                    'post_tags'      => $post_tags,
                    'post_name'      => $post_slug,
                    'post_excerpt'   => MainWP_Utility::esc_content( $_post->post_excerpt, 'mixed' ),
                    'post_password'  => $_post->post_password,
                    'comment_status' => $_post->comment_status,
                    'ping_status'    => $_post->ping_status,
                    'mainwp_post_id' => $_post->ID,
                );

                if ( ! empty( $featured_image_id ) ) {
                    $img                 = wp_get_attachment_image_src( $featured_image_id, 'full' );
                    $post_featured_image = $img[0];
                    $attachment          = get_post( $featured_image_id );
                    $featured_image_data = array(
                        'alt'         => get_post_meta( $featured_image_id, '_wp_attachment_image_alt', true ),
                        'caption'     => MainWP_Utility::esc_content( $attachment->post_excerpt, 'mixed' ),
                        'description' => $attachment->post_content,
                        'title'       => htmlspecialchars( $attachment->post_title ),
                    );
                }
            }

            $data_fields = MainWP_System_Utility::get_default_map_site_fields();

            $dbwebsites = array();

            if ( 'site' === $selected_by ) {
                foreach ( $selected_sites as $k ) {
                    if ( MainWP_Utility::ctype_digit( $k ) ) {
                        $website = MainWP_DB::instance()->get_website_by_id( $k );
                        if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
                            $dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
                        }
                    }
                }
            } elseif ( 'client' === $selected_by ) {
                $websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
                    $selected_clients,
                    array(
                        'select_data' => $data_fields,
                    )
                );
                if ( $websites ) {
                    foreach ( $websites as $website ) {
                        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                            continue;
                        }
                        $dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
                    }
                }
            } elseif ( 'group' === $selected_by ) {
                foreach ( $selected_groups as $k ) {
                    if ( MainWP_Utility::ctype_digit( $k ) ) {
                        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
                        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                            if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                                continue;
                            }
                            $dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
                        }
                        MainWP_DB::free_result( $websites );
                    }
                }
            }

            if ( 'preparing' === $what ) {
                ?>
                <div class="ui relaxed list">
                <?php
                foreach ( $dbwebsites as $website ) {
                    ?>
                    <div class="item site-bulk-posting" site-id="<?php echo intval( $website->id ); ?>" status="queue"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
                    <div class="right floated content progress"><i class="clock outline icon"></i></div>
                    </div>
            <?php } ?>
            </div>
                <?php
            } else {

                $output         = new \stdClass();
                $output->ok     = array();
                $output->errors = array();

                if ( ! empty( $dbwebsites ) ) {

                    // prepare $post_custom values.
                    $new_post_custom = array();
                    foreach ( $post_custom as $meta_key => $meta_values ) {
                        $new_meta_values = array();
                        foreach ( $meta_values as $key_value => $meta_value ) {
                            if ( is_serialized( $meta_value ) ) {
                                $meta_value = unserialize( $meta_value ); // phpcs:ignore -- internal value safe.
                            }
                            $new_meta_values[ $key_value ] = $meta_value;
                        }
                        $new_post_custom[ $meta_key ] = $new_meta_values;
                    }
                    $post_data = array(
                        'new_post'            => base64_encode( wp_json_encode( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                        'post_custom'         => base64_encode( wp_json_encode( $new_post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                        'post_category'       => ! empty( $post_category ) ? base64_encode( $post_category ) : '', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                        'post_featured_image' => ( null !== $post_featured_image ) ? base64_encode( $post_featured_image ) : null, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                        'post_gallery_images' => base64_encode( wp_json_encode( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                        'mainwp_upload_dir'   => base64_encode( wp_json_encode( $mainwp_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                        'featured_image_data' => ( null !== $featured_image_data ) ? base64_encode( wp_json_encode( $featured_image_data ) ) : null, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                    );
                    MainWP_Connect::fetch_urls_authed(
                        $dbwebsites,
                        'newpost',
                        $post_data,
                        array(
                            MainWP_Bulk_Add::get_class_name(),
                            'posting_bulk_handler',
                        ),
                        $output
                    );
                }

                foreach ( $dbwebsites as $website ) {
                    if ( isset( $output->ok[ $website->id ] ) && ( 1 === (int) $output->ok[ $website->id ] ) && ( isset( $output->added_id[ $website->id ] ) ) ) {
                        $links = isset( $output->link[ $website->id ] ) ? $output->link[ $website->id ] : null;
                        do_action_deprecated( 'mainwp-post-posting-post', array( $website, $output->added_id[ $website->id ], $links ), '4.0.7.2', 'mainwp_post_posting_post' ); // @deprecated Use 'mainwp_post_posting_page' instead. NOSONAR - not IP.
                        do_action_deprecated( 'mainwp-bulkposting-done', array( $_post, $website, $output ), '4.0.7.2', 'mainwp_bulkposting_done' ); // @deprecated Use 'mainwp_bulkposting_done' instead. NOSONAR - not IP.

                        /**
                         * Posting post
                         *
                         * Fires while posting post.
                         *
                         * @param object $website                          Object containing child site data.
                         * @param int    $output->added_id[ $website->id ] Child site ID.
                         * @param array  $links                            Links.
                         *
                         * @since Unknown
                         */
                        do_action( 'mainwp_post_posting_post', $website, $output->added_id[ $website->id ], $links );

                        /**
                         * Posting post completed
                         *
                         * Fires after the post posting process is completed.
                         *
                         * @param array  $_post   Array containing the post data.
                         * @param object $website Object containing child site data.
                         * @param array  $output  Output data.
                         *
                         * @since Unknown
                         */
                        do_action( 'mainwp_bulkposting_done', $_post, $website, $output );
                    }
                }

                /**
                 * After posting a new post
                *
                * Sets data after the posting process to show the process feedback.
                *
                * @param array $_post      Array containing the post data.
                * @param array $dbwebsites Array containing processed sites.
                * @param array $output     Output data.
                *
                * @since Unknown
                */
                $newExtensions = apply_filters_deprecated( 'mainwp-after-posting-bulkpost-result', array( false, $_post, $dbwebsites, $output ), '4.0.7.2', 'mainwp_after_posting_bulkpost_result' ); // NOSONAR - not IP.

                $after_posting = false;
                if ( 'posting' === $what ) {
                    // supported for bulk posting, not for ajax posting.
                    $after_posting = apply_filters( 'mainwp_after_posting_bulkpost_result', $newExtensions, $_post, $dbwebsites, $output );
                }

                $posting_succeed = false;

                if ( false === $after_posting ) {
                    if ( 'posting' === $what ) {
                        ?>
                    <div class="ui relaxed list">
                        <?php
                        foreach ( $dbwebsites as $website ) {
                            ?>
                            <div class="item"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
                            :
                            <?php
                            if ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ) {
                                echo esc_html( $succes_message ) . ' <a href="' . esc_html( $output->link[ $website->id ] ) . '" class="mainwp-may-hide-referrer" target="_blank">View Post</a>';
                                $posting_succeed = true;
                            } else {
                                echo $output->errors[ $website->id ]; // phpcs:ignore WordPress.Security.EscapeOutput
                            }
                            ?>
                            </div>
                    <?php } ?>
                    </div>
                <?php } ?>
                    <?php
                } else {
                    $posting_succeed = true;
                }

                $ajax_result = '';
                if ( 'ajax_posting' === $what ) {
                    if ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ) {
                        $ajax_result     = esc_html( $succes_message ) . ' <a href="' . esc_html( $output->link[ $website->id ] ) . '" class="mainwp-may-hide-referrer" target="_blank">View Post</a>';
                        $posting_succeed = true;
                    } else {
                        $ajax_result = $output->errors[ $website->id ];
                    }
                }

                $delete_bulk_post = apply_filters( 'mainwp_after_posting_delete_bulk_post', true, $posting_succeed );
                $do_not_del       = get_post_meta( $id, '_bulkpost_do_not_del', true );

                $last_ajax_posting = false;
                if ( 'ajax_posting' === $what ) {
                    // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $delete_bulkpost = isset( $_POST['delete_bulkpost'] ) && ! empty( $_POST['delete_bulkpost'] ) ? true : false;
                    // phpcs:enable
                    if ( $delete_bulkpost ) {
                        $last_ajax_posting = true;
                    }
                }

                $deleted_bulk_post = false;
                if ( 'yes' !== $do_not_del && $delete_bulk_post && ( 'posting' === $what || $last_ajax_posting ) ) {
                    wp_delete_post( $id, true );
                    $deleted_bulk_post = true;
                }

                $edit_link = '';
                if ( ! $deleted_bulk_post ) {
                    if ( 'posting' === $what ) {
                        ?>
                        <div class="item">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $id ) ); ?>"><?php esc_html_e( 'Edit Post', 'mainwp' ); ?></a>
                        </div>
                        <?php
                    } elseif ( $last_ajax_posting ) {
                        $edit_link = '<div class="item"><a href="' . esc_url( admin_url( 'admin.php?page=PostBulkEdit&post_id=' . $id ) ) . '">' . esc_html__( 'Edit Post', 'mainwp' ) . '</a></div>';
                    }
                }

                if ( 'ajax_posting' === $what ) {
                    die(
                        wp_json_encode(
                            array(
                                'result'    => $ajax_result,
                                'edit_link' => $edit_link,
                            )
                        )
                    );
                }
            }
        }
    }

    /**
     * Method get_post()
     *
     * Get post from child site to edit.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_id()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function get_post() { //phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $postId        = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : false;
        $postType      = isset( $_POST['postType'] ) ? sanitize_text_field( wp_unslash( $_POST['postType'] ) ) : '';
        $websiteId     = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;
        $replaceadvImg = isset( $_POST['replace_advance_img'] ) && ! empty( $_POST['replace_advance_img'] ) ? true : false;
        // phpcs:enable
        if ( empty( $postId ) || empty( $websiteId ) ) {
            die( wp_json_encode( array( 'error' => 'Post ID or site ID not found. Please, reload the page and try again.' ) ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $websiteId );
        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            die( wp_json_encode( array( 'error' => 'You can not edit this website!' ) ) );
        }

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'post_action',
                array(
                    'action'    => 'get_edit',
                    'id'        => $postId,
                    'post_type' => $postType,
                )
            );

        } catch ( MainWP_Exception $e ) {
            die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
        }

        if ( is_array( $information ) && isset( $information['error'] ) ) {
            die( wp_json_encode( array( 'error' => esc_html( $information['error'] ) ) ) );
        }

        if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
            die( wp_json_encode( array( 'error' => 'Unexpected error.' ) ) );
        } else {
            $ret = static::new_post( $information['my_post'], $replaceadvImg, $website );
            if ( is_array( $ret ) && isset( $ret['id'] ) ) {
                // to support edit post.
                update_post_meta( $ret['id'], '_selected_sites', array( $websiteId ) );
                update_post_meta( $ret['id'], '_mainwp_edit_post_site_id', $websiteId );
            }
            $ret = apply_filters( 'mainwp_manageposts_get_post_result', $ret, $information['my_post'], $websiteId );
            wp_send_json( $ret );
        }
    }

    /**
     * Method new_post()
     *
     * Create new post.
     *
     * @param array $post_data Array of post data.
     * @param bool  $replaceadvImg replace advanced images of post or not.
     * @param mixed $website The website object.
     *
     * @return array result
     */
    public static function new_post( $post_data = array(), $replaceadvImg = false, $website = false ) {
        $new_post            = json_decode( base64_decode( $post_data['new_post'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        $post_custom         = json_decode( base64_decode( $post_data['post_custom'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        $post_category       = isset( $post_data['post_category'] ) ? rawurldecode( base64_decode( $post_data['post_category'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        $post_tags           = isset( $new_post['post_tags'] ) ? rawurldecode( $new_post['post_tags'] ) : '';
        $post_featured_image = base64_decode( $post_data['post_featured_image'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        $upload_dir          = json_decode( base64_decode( $post_data['child_upload_dir'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        $post_gallery_images = base64_decode( $post_data['post_gallery_images'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        return static::create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $post_gallery_images, $replaceadvImg, $website );
    }

    /**
     * Method create_post()
     *
     * Create post.
     *
     * @param mixed $new_post Post type.
     * @param mixed $post_custom Custom Post.
     * @param mixed $post_category Post Category.
     * @param mixed $post_featured_image Post Featured Image.
     * @param mixed $upload_dir Child Site upload directory.
     * @param mixed $post_tags Post tags.
     * @param mixed $post_gallery_images Post Gallery Images.
     * @param bool  $replaceadvImg replace advanced images of post or not.
     * @param mixed $website The website object.
     *
     * @return array result
     */
    public static function create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $post_gallery_images, $replaceadvImg = false, $website = false ) { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        if ( ! isset( $new_post['edit_id'] ) ) {
            return array( 'error' => 'Empty post id' );
        }

        $post_author             = $current_user->ID;
        $new_post['post_author'] = $post_author;
        $post_type               = isset( $new_post['post_type'] ) ? $new_post['post_type'] : '';
        $new_post['post_type']   = 'page' === $post_type ? 'bulkpage' : 'bulkpost';

        $foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER );
        if ( 0 < $foundMatches ) {
            foreach ( $matches as $match ) {
                $hrefLink = $match[2];
                $imgUrl   = $match[4];

                if ( ! isset( $upload_dir['baseurl'] ) || ( false === strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) { // url of image is not in child site.
                    continue;
                }

                if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
                    $search         = $imgMatches[0];
                    $replace        = '.' . $match[6];
                    $originalImgUrl = str_replace( $search, $replace, $imgUrl );
                } else {
                    $originalImgUrl = $imgUrl;
                }

                try {
                    $downloadfile = static::upload_image( $originalImgUrl );
                    $localUrl     = $downloadfile['url'];

                    $linkToReplaceWith = dirname( $localUrl );
                    if ( '' !== $hrefLink ) {
                        $server     = $website->url;
                        $serverHost = wp_parse_url( $server, PHP_URL_HOST );
                        if ( ! empty( $serverHost ) && false !== strpos( $hrefLink, $serverHost ) ) {
                            $serverHref               = 'href="' . $serverHost;
                            $replaceServerHref        = 'href="' . wp_parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . wp_parse_url( $localUrl, PHP_URL_HOST );
                            $new_post['post_content'] = str_replace( $serverHref, $replaceServerHref, $new_post['post_content'] );
                        }
                    }
                    $lnkToReplace = dirname( $imgUrl );
                    if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
                        $new_post['post_content'] = str_replace( $imgUrl, $localUrl, $new_post['post_content'] ); // replace src image.
                        $new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
                    }
                } catch ( \Exception $e ) {
                    // ok.
                }
            }
        }

        if ( has_shortcode( $new_post['post_content'], 'gallery' ) && preg_match_all( '/\[gallery[^\]]+ids=\"(.*?)\"[^\]]*\]/ix', $new_post['post_content'], $matches, PREG_SET_ORDER ) ) {
            $replaceAttachedIds = array();
            if ( is_array( $post_gallery_images ) ) {
                foreach ( $post_gallery_images as $gallery ) {
                    if ( isset( $gallery['src'] ) ) {
                        try {
                            $upload = static::upload_image( $gallery['src'], $gallery, true );
                            if ( null !== $upload ) {
                                $replaceAttachedIds[ $gallery['id'] ] = $upload['id'];
                            }
                        } catch ( \Exception $e ) {
                            // ok.
                        }
                    }
                }
            }
            if ( ! empty( $replaceAttachedIds ) ) {
                foreach ( $matches as $match ) {
                    $idsToReplace     = $match[1];
                    $idsToReplaceWith = '';
                    $originalIds      = explode( ',', $idsToReplace );
                    foreach ( $originalIds as $attached_id ) {
                        if ( ! empty( $originalIds ) && isset( $replaceAttachedIds[ $attached_id ] ) ) {
                            $idsToReplaceWith .= $replaceAttachedIds[ $attached_id ] . ',';
                        }
                    }
                    $idsToReplaceWith = rtrim( $idsToReplaceWith, ',' );
                    if ( ! empty( $idsToReplaceWith ) ) {
                        $new_post['post_content'] = str_replace( '"' . $idsToReplace . '"', '"' . $idsToReplaceWith . '"', $new_post['post_content'] );
                    }
                }
            }
        }

        if ( $replaceadvImg && $website ) {
            $new_post['post_content'] = static::replace_advanced_image( $new_post['post_content'], $upload_dir, $website );
            $new_post['post_content'] = static::replace_advanced_image( $new_post['post_content'], $upload_dir, $website, true ); // to fix images url with slashes.
        }

        $is_sticky = false;
        if ( isset( $new_post['is_sticky'] ) ) {
            $is_sticky = ! empty( $new_post['is_sticky'] ) ? true : false;
            unset( $new_post['is_sticky'] );
        }
        $edit_id = $new_post['edit_id'];
        unset( $new_post['edit_id'] );

        if ( isset( $new_post['post_title'] ) ) {
            $new_post['post_title'] = MainWP_Utility::esc_content( $new_post['post_title'], 'mixed' );
        }

        $wp_error = null;
        remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
        $post_status             = $new_post['post_status'];
        $new_post['post_status'] = 'auto-draft';
        $new_post_id             = wp_insert_post( $new_post, $wp_error );

        if ( is_wp_error( $wp_error ) ) {
            return array( 'error' => $wp_error->get_error_message() );
        }

        if ( empty( $new_post_id ) ) {
            return array( 'error' => 'Undefined error' );
        }

        wp_update_post(
            array(
                'ID'          => $new_post_id,
                'post_status' => $post_status,
            )
        );

        foreach ( $post_custom as $meta_key => $meta_values ) {
            foreach ( $meta_values as $meta_value ) {
                    update_post_meta( $new_post_id, $meta_key, $meta_value );
            }
        }

        update_post_meta( $new_post_id, '_mainwp_edit_post_id', $edit_id );
        update_post_meta( $new_post_id, '_slug', base64_encode( $new_post['post_name'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        if ( isset( $post_category ) && '' !== $post_category ) {
            update_post_meta( $new_post_id, '_categories', base64_encode( $post_category ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        }

        if ( isset( $post_tags ) && '' !== $post_tags ) {
            update_post_meta( $new_post_id, '_tags', base64_encode( $post_tags ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        }
        if ( $is_sticky ) {
            update_post_meta( $new_post_id, '_sticky', base64_encode( 'sticky' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        }

        if ( ! empty( $post_featured_image ) ) {
            try {
                $upload = static::upload_image( $post_featured_image );

                if ( null !== $upload ) {
                    update_post_meta( $new_post_id, '_thumbnail_id', $upload['id'] );
                }
            } catch ( \Exception $e ) {
                // ok.
                error_log($e->getMessage()); //phpcs:ignore -- NOSONAR - debugging.
            }
        }

        $ret            = array();
        $ret['success'] = true;
        $ret['id']      = $new_post_id;
        return $ret;
    }

    /**
     * Method replace_advanced_image()
     *
     * Handle upload advanced image.
     *
     * @param array $content post content data.
     * @param array $upload_dir upload directory info.
     * @param mixed $website The website.
     * @param bool  $withslashes to use preg pattern with slashes.
     *
     * @return mixed array of result.
     */
    public static function replace_advanced_image( $content, $upload_dir, $website, $withslashes = false ) { //phpcs:ignore -- NOSONAR - complex.

        if ( empty( $upload_dir ) || ! isset( $upload_dir['baseurl'] ) ) {
            return $content;
        }

        $dashboard_url   = get_site_url();
        $site_url_source = $website->url;

        // to fix url with slashes.
        if ( $withslashes ) {
            $site_url_source = str_replace( '/', '\/', $site_url_source );
            $dashboard_url   = str_replace( '/', '\/', $dashboard_url );
        }

        $foundMatches = preg_match_all( '#(' . preg_quote( $site_url_source, null ) . ')[^\.]*(\.(png|gif|jpg|jpeg))#ix', $content, $matches, PREG_SET_ORDER ); // phpcs:ignore -- NOSONAR -Current complexity.

        if ( 0 < $foundMatches ) {

            $matches_checked = array();
            $check_double    = array();
            foreach ( $matches as $match ) {
                // to avoid double images.
                if ( ! in_array( $match[0], $check_double ) ) {
                    $check_double[]    = $match[0];
                    $matches_checked[] = $match;
                }
            }
            foreach ( $matches_checked as $match ) {

                $imgUrl = $match[0];
                if ( false === strripos( wp_unslash( $imgUrl ), $upload_dir['baseurl'] ) ) {
                    continue;
                }

                if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
                    $search         = $imgMatches[0];
                    $replace        = '.' . $match[3];
                    $originalImgUrl = str_replace( $search, $replace, $imgUrl );
                } else {
                    $originalImgUrl = $imgUrl;
                }

                try {
                    $downloadfile      = static::upload_image( wp_unslash( $originalImgUrl ) );
                    $localUrl          = $downloadfile['url'];
                    $linkToReplaceWith = dirname( $localUrl );
                    $lnkToReplace      = dirname( $imgUrl );
                    if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
                        $content = str_replace( $imgUrl, $localUrl, $content ); // replace src image.
                        $content = str_replace( $lnkToReplace, $linkToReplaceWith, $content );
                    }
                } catch ( \Exception $e ) {
                    // ok.
                }
            }
            if ( false === strripos( $site_url_source, $dashboard_url ) ) {
                // replace other images src outside upload folder.
                $content = str_replace( $site_url_source, $dashboard_url, $content );
            }
        }
        return $content;
    }

    /**
     * Method upload_image()
     *
     * Handle upload image.
     *
     * @throws \MainWP_Exception Error upload file.
     *
     * @param string $img_url URL for the image.
     * @param array  $img_data Array of image data.
     *
     * @return mixed array of result or null.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     */
    public static function upload_image( $img_url, $img_data = array() ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $img_data ) ) {
            $img_data = array();
        }
        include_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR - WP compatible.
        $temporary_file = download_url( $img_url );

        if ( is_wp_error( $temporary_file ) ) {
            throw new MainWP_Exception( 'Error: ' . $temporary_file->get_error_message() );  //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } else {
            $upload_dir     = wp_upload_dir();
            $local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url );
            $local_img_url  = $upload_dir['url'] . '/' . basename( $img_url );
            $moved          = false;
            if ( MainWP_Utility::check_image_file_name( $local_img_path ) ) {
                global $wp_filesystem;
                if ( $wp_filesystem ) {
                    $moved = $wp_filesystem->move( $temporary_file, $local_img_path, true );
                }
            }
            if ( $moved ) {
                $wp_filetype = wp_check_filetype( basename( $img_url ), null );
                $attachment  = array(
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title'     => isset( $img_data['title'] ) && ! empty( $img_data['title'] ) ? $img_data['title'] : preg_replace( '/\.[^.]+$/', '', basename( $img_url ) ),
                    'post_content'   => isset( $img_data['description'] ) && ! empty( $img_data['description'] ) ? $img_data['description'] : '',
                    'post_excerpt'   => isset( $img_data['caption'] ) && ! empty( $img_data['caption'] ) ? MainWP_Utility::esc_content( $img_data['caption'] ) : '',
                    'post_status'    => 'inherit',
                );
                $attach_id   = wp_insert_attachment( $attachment, $local_img_path );
                require_once ABSPATH . 'wp-admin/includes/image.php'; // NOSONAR - WP compatible.
                $attach_data = wp_generate_attachment_metadata( $attach_id, $local_img_path );
                wp_update_attachment_metadata( $attach_id, $attach_data );
                if ( isset( $img_data['alt'] ) && ! empty( $img_data['alt'] ) ) {
                    update_post_meta( $attach_id, '_wp_attachment_image_alt', $img_data['alt'] );
                }
                return array(
                    'id'  => $attach_id,
                    'url' => $local_img_url,
                );
            }
        }

        MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        if ( $wp_filesystem->exists( $temporary_file ) ) {
            $wp_filesystem->delete( $temporary_file );
        }

        return null;
    }

    /**
     * Method add_sticky_handle()
     *
     * Add post meta.
     *
     * @param mixed $post_id Post ID.
     *
     * @return int $post_id Post ID.
     */
    public static function add_sticky_handle( $post_id ) {
        $_post = get_post( $post_id );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( 'bulkpost' === $_post->post_type && isset( $_POST['sticky'] ) ) {
            update_post_meta( $post_id, '_sticky', base64_encode( sanitize_text_field( wp_unslash( $_POST['sticky'] ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
            return base64_encode( sanitize_text_field( wp_unslash( $_POST['sticky'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
        }
        // phpcs:enable
        return $post_id;
    }


    /**
     * Method add_status_handle()
     *
     * Add edit post status handle.
     *
     * @param int $post_id Post ID.
     *
     * @return int $post_id Post id with status handle added to it.
     */
    public static function add_status_handle( $post_id ) {
        $_post = get_post( $post_id );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ( 'bulkpage' === $_post->post_type || 'bulkpost' === $_post->post_type ) && isset( $_POST['mainwp_edit_post_status'] ) ) {
            update_post_meta( $post_id, '_edit_post_status', sanitize_text_field( wp_unslash( $_POST['mainwp_edit_post_status'] ) ) );
        }
        // phpcs:enable
        return $post_id;
    }
}
