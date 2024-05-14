<?php
/**
 * This file handles all of the Bulk Installation Methods
 * for Plugins & Themes.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Install_Bulk
 *
 * @used-by MainWP_Plugins::InstallPlugins
 * @used-by MainWP_Themes::InstallThemes
 */
class MainWP_Install_Bulk { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     *
     * @uses static::init()
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method init()
     *
     * Instantiate the main page
     *
     * Has to be called in System constructor,
     * adds handling for the main page.
     */
    public static function init() {
        add_action( 'admin_init', array( static::get_class_name(), 'admin_init' ) );
    }

    /**
     * Method admin_init()
     *
     * Handles the uploading of a file.
     *
     * @uses \MainWP\Dashboard\MainWP_QQ2_File_Uploader
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     */
    public static function admin_init() {
        if ( isset( $_REQUEST['mainwp_do'] ) && isset( $_REQUEST['qq_nonce'] ) && 'MainWP_Install_Bulk-uploadfile' === $_REQUEST['mainwp_do'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            do_action( 'mainwp_secure_request', 'qq_nonce', 'qq_nonce' );
            $allowedExtensions = array( 'zip' ); // Only zip allowed.
            // max file size in bytes.
            $sizeLimit = 2 * 1024 * 1024; // 2MB = max allowed.

            $uploader = new MainWP_QQ2_File_Uploader( $allowedExtensions, $sizeLimit );
            $path     = MainWP_System_Utility::get_mainwp_specific_dir( 'bulk' );

            $result = $uploader->handle_upload( $path, true );
            // to pass data through iframe you will need to encode all html tags.
            die( htmlspecialchars( wp_json_encode( $result ), ENT_NOQUOTES ) ); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }


    /**
     * Method render_upload()
     *
     * Renders the upload sub part.
     *
     * @param string $type Plugin|Theme Type of upload.
     */
    public static function render_upload( $type ) {
        $title             = ( 'plugin' === $type ) ? 'Plugins' : 'Themes';
        $favorites_enabled = is_plugin_active( 'mainwp-favorites-extension/mainwp-favorites-extension.php' );
        $cls               = $favorites_enabled ? 'favorites-extension-enabled ' : '';
        $cls              .= ( 'plugin' === $type ) ? 'qq-upload-plugins' : '';

        $disabled_upload    = false;
        $disabled_functions = ini_get( 'disable_functions' );
        if ( ! empty( $disabled_functions ) ) {
            $disabled_functions_array = explode( ',', $disabled_functions );
            if ( is_array( $disabled_functions_array ) && in_array( 'tmpfile', $disabled_functions_array ) ) {
                $disabled_upload = true;
            }
        }

        if ( $disabled_upload ) {
            ?>
                <div class="ui red message">
                    <div><?php esc_html_e( 'MainWP has detected that the tmpfile() PHP function is disabled on your server. This function is essential for uploading and installing zip files for plugins and themes. Please enable the tmpfile() function on your server to proceed. Contact your hosting provider for assistance if needed.', 'mainwp' ); ?></div>
                </div>

                <div class="ui secondary center aligned padded segment">
                    <h2 class="ui icon header">
                        <i class="file archive outline icon" style="color: #ddd"></i>
                        <div class="content" style="color: #ddd">
                            <?php esc_html_e( 'Upload .zip File', 'mainwp' ); ?>
                            <div class="sub header" style="color: #ddd"><?php esc_html_e( 'If you have', 'mainwp' ); ?> <?php echo esc_html( strtolower( $title ) ); ?> <?php esc_html_e( 'in a .zip format, you may install it by uploading it here.', 'mainwp' ); ?></div>
                        </div>
                    </h2>

                    <div class="ui hidden divider"></div>

                    <div class="ui center aligned segment">
                        <div class="ui labeled icon massive green button disabled">
                            <i class="upload icon"></i>
                            <?php esc_html_e( 'Upload Now', 'mainwp' ); ?>
                        </div>
                    </div>

                </div>
                <?php
        } else {
            ?>
                <div class="ui secondary center aligned padded segment">
                    <h2 class="ui icon header">
                        <i class="file archive outline icon"></i>
                        <div class="content">
                        <?php esc_html_e( 'Upload .zip File', 'mainwp' ); ?>
                            <div class="sub header"><?php esc_html_e( 'If you have', 'mainwp' ); ?> <?php echo esc_html( strtolower( $title ) ); ?> <?php esc_html_e( 'in a .zip format, you may install it by uploading it here.', 'mainwp' ); ?></div>
                        </div>
                    </h2>

                    <div class="ui hidden divider"></div>

                    <div id="mainwp-file-uploader" class="<?php echo esc_attr( $cls ); ?>" >
                        <noscript>
                        <div class="ui message red"><?php esc_html_e( 'Please enable JavaScript to use file uploader.', 'mainwp' ); ?></div>
                        </noscript>

                        <div class="dropzone" id="mainwp-dropzone-upload" >
                            <div class="dz-clickable-area">
                                <div class="ui labeled icon massive green button qq-upload-button" style="position: relative; overflow: hidden; direction: ltr;">
                                    <i class="upload icon"></i> <?php esc_html_e( 'Upload Now', 'mainwp' ); ?>
                                    <div style="position: absolute; right: 0px; top: 0px; font-family: Arial; font-size: 118px; margin: 0px; padding: 0px; cursor: pointer; opacity: 0;"></div>
                                </div>
                                <div class="qq-upload-drop-area" id="qq-upload-drop-area">
                                    <div class="dz-message">
                                        <?php esc_html_e( 'Drop files here to upload', 'mainwp' ); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="ui hidden divider"></div>
                        </div>

                    </div>

                    <div id="mainwp-dropzone-preview-tpl" style="display: none">
                        <div class="item file-uploaded-item" style="padding:0!important;">
                            <div class="ui grid" style="margin:0!important;">
                                <div class="four column row">
                                    <div class="left aligned middle aligned column dz-filename"><span class="qq-upload-file" filename="" data-dz-name=""></span></div>
                                    <div class="middle aligned column">
                                        <span class="qq-upload-percent"></span>
                                        <span class="qq-upload-size dz-size" data-dz-size=""></span>
                                    </div>
                                    <div class="middle aligned column ">
                                        <div class="dz-error-message"><span data-dz-errormessage=""></span></div>
                                        <span class="qq-upload-processing">
                                            <i class="notched circle loading icon"></i> Uploading...
                                        </span>
                                    </div>
                                    <div class="right aligned middle aligned column">
                                        <a class="ui mini button basic red qq-upload-cancel" href="#">Cancel Upload</a>
                                    <?php echo $favorites_enabled ? '<span class="qq-upload-add-to-favorites" style="display:none;"><a class="ui mini button basic" href="#">Add to Favorites</a></span>' : ''; ?>
                                        <a class="ui mini button basic red qq-upload-cancel-install" style="display:none;" href="#">Remove Item</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php

                    $chunksize_upload = apply_filters( 'mainwp_file_uploader_chunk_size', 1000000 ); // to support custom chunk size upload.
                    if ( empty( $chunksize_upload ) ) {
                        $chunksize_upload = 1000000; // bytes.
                    }

                    ?>
                    <script type="text/javascript">
                        document.addEventListener("DOMContentLoaded", (event) => {
                            Dropzone.autoDiscover = false;
                            let dropzone = new Dropzone('#mainwp-dropzone-upload', {
                                url: function(){
                                    return 'admin.php?page=<?php echo isset( $_GET['page'] ) ? esc_js( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified. ?>';
                                },
                                paramName: 'qqfile', // file input name.
                                autoProcessQueue : true,
                                clickable: '.dz-clickable-area',
                                previewTemplate: document.querySelector('#mainwp-dropzone-preview-tpl').innerHTML,
                                parallelUploads: 3,
                                chunking: true,
                                chunkSize: <?php echo intval( $chunksize_upload ); ?>,
                                uploadMultiple: false, // 1: to compatible with server side process.
                                acceptedFiles: '.zip',
                                createImageThumbnails: 0,
                                maxFilesize: 150,
                                filesizeBase: 1000,
                                init: function() {
                                    let self = this;
                                    this.on("addedfile", file => {
                                        jQuery(file.previewElement).find('.qq-upload-cancel').on('click',function(){
                                            self.removeFile(file); // Remove the specific file from Dropzone
                                            return false;
                                        });
                                        jQuery(file.previewElement).find('.qq-upload-file').attr('filename', file.name );
                                    });

                                    this.on("error", function(file, errorMessage) {
                                        jQuery(file.previewElement).find('.qq-upload-processing').hide();
                                    });
                                    this.on("sending", function(file, xhr, formData) {
                                        // Add custom parameters to the FormData object
                                        formData.append("mainwp_do", "MainWP_Install_Bulk-uploadfile");
                                        formData.append("qq_nonce", "<?php echo esc_js( wp_create_nonce( 'qq_nonce' ) ); ?>");
                                    });
                                    this.on("uploadprogress", function( file, progress, bytesSent ) {
                                        progress = parseInt(progress);
                                        jQuery(file.previewElement).find('.qq-upload-percent').html( progress + '% from ');
                                    });
                                    this.on("success", function( file, result ) {
                                        let obj = false;
                                        try{
                                            obj =JSON.parse(result);
                                        } catch( err ){
                                            //error parse response.
                                            obj = false;
                                        }

                                        file.previewElement.classList.add('qq-upload-completed');
                                        jQuery(file.previewElement).find('.qq-upload-cancel-install').show();
                                        jQuery(file.previewElement).find('.qq-upload-cancel-install').on('click',function(){
                                            self.removeFile(file); // Remove the specific file from Dropzone
                                            return false;
                                        });

                                        let unerror = true;

                                        if( obj ){
                                            if(obj.success){
                                                file.previewElement.classList.add('qq-upload-success');
                                                jQuery(file.previewElement).find('.qq-upload-processing').html('Upload completed.');
                                                unerror = false;
                                            } else if(obj.error){
                                                jQuery(file.previewElement).find('.qq-upload-processing').html('<span data-tooltip="' + obj.error + '" data-inverted="" data-position="top left"><i class="red times icon"></i></span>' );
                                                unerror = false;
                                            }
                                        }

                                        if(unerror){
                                            jQuery(file.previewElement).find('.qq-upload-processing').html('<span data-tooltip="Upload failed. Please try again." data-inverted="" data-position="top left"><i class="red times icon"></i></span>' );
                                        }
                                    });
                                }
                            });
                        });
                        (function(){

                            let dropzoneArea = jQuery('.qq-upload-drop-area');
                            let dropzoneId = 'qq-upload-drop-area';

                            window.addEventListener("dragover", function(e) {
                                if (e.target.id != dropzoneId) {
                                    e.preventDefault();
                                    e.dataTransfer.effectAllowed = "none";
                                    e.dataTransfer.dropEffect = "none";
                                }
                            }, false);

                            window.addEventListener("dragenter", function(e) {
                                if (e.target.id != dropzoneId) {
                                    e.preventDefault();
                                    e.dataTransfer.effectAllowed = "none";
                                    e.dataTransfer.dropEffect = "none";
                                }
                            }, false);

                            window.addEventListener("drop", function(e) {
                                if (e.target.id != dropzoneId) {
                                    e.preventDefault();
                                    e.dataTransfer.effectAllowed = "none";
                                    e.dataTransfer.dropEffect = "none";
                                }
                            });

                            jQuery('.dz-clickable-area').on("dragenter", function(event){
                                event.preventDefault();
                                dropzoneArea.show();
                            }).on("dragover", function(event){
                                event.preventDefault();
                                dropzoneArea.show();
                            }).on("drop", function(event){
                                event.preventDefault();
                                dropzoneArea.hide();
                            });
                        })();
                    </script>
                </div>
                <?php
        }
    }

    /**
     * Method prepare_install()
     *
     * Prepare for the installation.
     *
     * Grab all the necessary data to make the upload and prepare json response.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses  \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public static function prepare_install() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        include_once ABSPATH . '/wp-admin/includes/plugin-install.php'; // NOSONAR - WP compatible.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $type = 'theme';
        if ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ) {
            $type = 'plugin';
        }
        if ( ! isset( $_POST['url'] ) ) {
            $api = MainWP_System_Utility::get_plugin_theme_info(
                $type,
                array(
                    'slug'   => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
                    'fields' => array( 'sections' => false ),
                )
            ); // Save on a bit of bandwidth.

            $url = $api->download_link;
            $url = apply_filters( 'mainwp_prepare_install_download_url', $url, $_POST );
        } else {
            $url = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '';

            $mwpDir = MainWP_System_Utility::get_mainwp_dir();
            $mwpUrl = $mwpDir[1];
            if ( stristr( $url, $mwpUrl ) ) {
                $fullFile = $mwpDir[0] . str_replace( $mwpUrl, '', $url );
                $url      = admin_url( '?sig=' . MainWP_System_Utility::get_download_sig( $fullFile ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir[0], '', $fullFile ) ) );
            }
        }

        $output          = array();
        $output['url']   = $url;
        $output['slug']  = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $output['name']  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $output['sites'] = array();

        static::get_selected_sites( $output );

        /**
        * Filter: mainwp_bulk_prepare_install_result
        *
        * Fires after plugin/theme prepare install.
        *
        * @since 4.6
        */
        $output = apply_filters( 'mainwp_bulk_prepare_install_result', $output, $type );
        mainwp_send_json_output( $output );
    }


    /**
     * Method addition_post_data()
     *
     * Grab Post addition data.
     *
     * @param array $post_data Data for post.
     *
     * @return mixed $post_data Bulk post addition data.
     */
    public static function addition_post_data( &$post_data = array() ) {

        /**
         * Clean and Lock extension options
         *
         * Adds additional options related to Clean and Lock options in order to avoid conflicts when HTTP Basic auth is set.
         *
         * @since Unknown
         */
        $clear_and_lock_opts = apply_filters( 'mainwp_clear_and_lock_options', array() );
        $mwpdl               = isset( $post_data['url'] ) && false !== strpos( $post_data['url'], 'mwpdl' ) && false !== strpos( $post_data['url'], 'sig' );
        if ( $mwpdl && is_array( $clear_and_lock_opts ) && isset( $clear_and_lock_opts['wpadmin_user'] ) && ! empty( $clear_and_lock_opts['wpadmin_user'] ) && isset( $clear_and_lock_opts['wpadmin_passwd'] ) && ! empty( $clear_and_lock_opts['wpadmin_passwd'] ) ) {
            $post_data['wpadmin_user']   = $clear_and_lock_opts['wpadmin_user'];
            $post_data['wpadmin_passwd'] = $clear_and_lock_opts['wpadmin_passwd'];
        }
        return $post_data;
    }

    /**
     * Perform Install.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses  \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function perform_install() {
        MainWP_Utility::end_session();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Fetch info.
        $type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $post_data = array(
            'type' => $type,
        );
        if ( isset( $_POST['activatePlugin'] ) && 'true' === $_POST['activatePlugin'] ) {
            $post_data['activatePlugin'] = 'yes';
        }
        if ( isset( $_POST['overwrite'] ) && 'true' === $_POST['overwrite'] ) {
            $post_data['overwrite'] = true;
        }

        /**
         * Addition Post Data.
         *
         * @param $post_data The post data.
         * @deprecated From.
         * @since 3.5.6.
         */
        static::addition_post_data( $post_data );

        $site_id = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : 0;
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );

        // to support demo data.
        if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
            return MainWP_Demo_Handle::get_instance()->handle_action_demo( $website, 'perform_install' );
        }

        $websites = array( $website );

        /**
         * Perform insatallation additional data
         *
         * Adds support for additional data such as HTTP User and HTTP Password.
         *
         * @param array $post_data Array containg the post data.
         *
         * @since Unknown
         */
        $post_data = apply_filters( 'mainwp_perform_install_data', $post_data );

        $post_data['url'] = isset( $_POST['url'] ) ? wp_json_encode( wp_unslash( $_POST['url'] ) ) : '';

        $output             = new \stdClass();
        $output->ok         = array();
        $output->errors     = array();
        $output->results    = array();
        $output->other_data = array();

        // phpcs:enable
        /**
        * Action: mainwp_before_plugin_theme_install
        *
        * Fires before plugin/theme install.
        *
        * @since 4.1
        */
        do_action( 'mainwp_before_plugin_theme_install', $post_data, $websites );

        MainWP_Connect::fetch_urls_authed(
            $websites,
            'installplugintheme',
            $post_data,
            array(
                static::get_class_name(),
                'install_plugin_theme_handler',
            ),
            $output,
            null,
            array( 'upgrade' => true )
        );

        $output_obj = $output;

        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $websites, 'install', $output_obj, $type, $post_data );

        /**
        * Action: mainwp_after_plugin_theme_install
        *
        * Fires after plugin/theme install.
        *
        * @since 4.1
        */
        do_action( 'mainwp_after_plugin_theme_install', $output, $post_data, $websites );

        wp_send_json( $output );
    }

    /**
     * Method prepare_upload()
     *
     * Prepare the upload.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_download_url()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses  \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public static function prepare_upload() { // phpcs:ignore -- NOSONAR - comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        include_once ABSPATH . '/wp-admin/includes/plugin-install.php'; // NOSONAR - WP compatible.

        $output          = array();
        $output['sites'] = array();

        static::get_selected_sites( $output );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $output['urls']  = array();
        $output['files'] = array();
        $files           = isset( $_POST['files'] ) && is_array( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : array();
        foreach ( $files as $file ) {
            $output['urls'][]  = MainWP_System_Utility::get_download_url( 'bulk', $file );
            $output['files'][] = esc_html( $file );
        }
        $output['urls'] = implode( '||', $output['urls'] );
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        /**
         * Prepare upload
         *
         * Prepares upload URLs for the bulk install process.
         *
         * @since Unknown
         */
        $output['urls'] = apply_filters( 'mainwp_installbulk_prepareupload', $output['urls'] );

        wp_send_json( $output );
    }

    /**
     * Method prepare_upload()
     *
     * @param array $output selected sites output.
     */
    public static function get_selected_sites( &$output ) {  //phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace  -- NOSONAR - complexity.

        $data_fields = array(
            'id',
            'url',
            'name',
            'sync_errors',
        );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['selected_by'] ) && 'site' === $_POST['selected_by'] ) {
            $selected_sites = isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array();
            // Get sites.
            foreach ( $selected_sites as $enc_id ) {
                $websiteid = $enc_id;
                if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
                    $website                         = MainWP_DB::instance()->get_website_by_id( $websiteid );
                    $output['sites'][ $website->id ] = MainWP_Utility::map_site(
                        $website,
                        array(
                            'id',
                            'url',
                            'name',
                        )
                    );
                }
            }
        } elseif ( isset( $_POST['selected_by'] ) && 'client' === $_POST['selected_by'] ) {
            $selected_clients = isset( $_POST['selected_clients'] ) && is_array( $_POST['selected_clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_clients'] ) ) : array();
            $websites         = MainWP_DB_Client::instance()->get_websites_by_client_ids(
                $selected_clients,
                array(
                    'select_data' => $data_fields,
                )
            );
            // Get sites.
            if ( $websites ) {
                foreach ( $websites as $website ) {
                    if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                        continue;
                    }
                    $output['sites'][ $website->id ] = MainWP_Utility::map_site(
                        $website,
                        array(
                            'id',
                            'url',
                            'name',
                        )
                    );
                }
            }
        } else {
            $selected_groups = ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_groups'] ) ) : array();
            // Get sites from group.
            foreach ( $selected_groups as $enc_id ) {
                $groupid = $enc_id;
                if ( MainWP_Utility::ctype_digit( $groupid ) ) {
                    $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $groupid ) );
                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                            continue;
                        }
                        $output['sites'][ $website->id ] = MainWP_Utility::map_site(
                            $website,
                            array(
                                'id',
                                'url',
                                'name',
                            )
                        );
                    }
                    MainWP_DB::free_result( $websites );
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    /**
     * Method perform_upload()
     *
     * Perform the upload.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses  \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function perform_upload() {
        MainWP_Utility::end_session();
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        // Fetch info.
        $post_data = array(
            'type' => $type,
        );
        if ( isset( $_POST['activatePlugin'] ) && 'true' === $_POST['activatePlugin'] ) {
            $post_data['activatePlugin'] = 'yes';
        }
        if ( isset( $_POST['overwrite'] ) && 'true' === $_POST['overwrite'] ) {
            $post_data['overwrite'] = true;
        }

        // deprecated from 3.5.6.
        static::addition_post_data( $post_data );

        /** This filter is documented in pages/page-mainwp-install-bulk.php */
        $post_data = apply_filters( 'mainwp_perform_install_data', $post_data );

        $urls             = isset( $_POST['urls'] ) ? esc_url_raw( wp_unslash( $_POST['urls'] ) ) : '';
        $post_data['url'] = wp_json_encode( explode( '||', $urls ) );
        $site_id          = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : 0;
        $website          = MainWP_DB::instance()->get_website_by_id( $site_id );

        // phpcs:enable

        // to support demo data.
        if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
            return MainWP_Demo_Handle::get_instance()->handle_action_demo( $website, 'perform_upload' );
        }

        $output             = new \stdClass();
        $output->ok         = array();
        $output->errors     = array();
        $output->results    = array();
        $output->other_data = array();
        $websites           = array( $website );

        /**
        * Action: mainwp_before_plugin_theme_install
        *
        * Fires before plugin/theme install.
        *
        * @since 4.1
        */
        do_action( 'mainwp_before_plugin_theme_install', $post_data, $websites );

        MainWP_Connect::fetch_urls_authed(
            $websites,
            'installplugintheme',
            $post_data,
            array(
                static::get_class_name(),
                'install_plugin_theme_handler',
            ),
            $output,
            null,
            array( 'upgrade' => true )
        );
        $output_obj = $output;
        mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $websites, 'install', $output_obj, $type, $post_data, true );

        /**
        * Action: mainwp_after_plugin_theme_install
        *
        * Fires after plugin/theme install.
        *
        * @since 4.1
        */
        do_action( 'mainwp_after_plugin_theme_install', $output, $post_data, $websites, $type );

        /**
        * Filter: mainwp_bulk_upload_install_result
        *
        * Fires after plugin/theme install.
        *
        * @since 4.6
        */
        $output = apply_filters( 'mainwp_bulk_upload_install_result', $output, $type, $post_data, $websites );

        wp_send_json( $output );
    }

    /**
     * Clean the upload
     *
     * Do file structure maintenance and tmp file removals.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     */
    public static function clean_upload() {
        MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        $path = MainWP_System_Utility::get_mainwp_specific_dir( 'bulk' );
        if ( $wp_filesystem->exists( $path ) ) {
            $dh = opendir( $path );
            if ( $dh ) {
                while ( false !== ( $file = readdir( $dh ) ) ) {
                    if ( '.' !== $file && '..' !== $file ) {
                        $wp_filesystem->delete( $path . $file );
                    }
                }
                closedir( $dh );
            }
        }

        die( wp_json_encode( array( 'ok' => true ) ) );
    }

    /**
     * Plugin & Theme upload handler.
     *
     * @param mixed  $data Processing data.
     * @param object $website The website object.
     * @param mixed  $output Function output.
     * @param mixed  $post_data Post data.
     *
     * @return mixed $output->ok[ $website->id ] = array( $website->name )|Error,
     *  Already installed,
     *  Undefined error! Please reinstall the MainWP Child plugin on the child site,
     *  Error while installing.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
     */
    public static function install_plugin_theme_handler( $data, $website, &$output, $post_data = array() ) { // phpcs:ignore -- NOSONAR - complex.
        if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
            return;
        }
        if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {

            $result = $results[1];
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
            $information = MainWP_System_Utility::get_child_response( base64_decode( $result ) );
            if ( is_array( $information ) && isset( $information['other_data']['install_items'] ) ) {
                $output->other_data[ $website->id ] = $information['other_data']; // content: install_items themes/plugins.
            }

            if ( isset( $information['installation'] ) && 'SUCCESS' === $information['installation'] ) {
                $output->ok[ $website->id ]      = array( $website->name );
                $output->results[ $website->id ] = isset( $information['install_results'] ) ? $information['install_results'] : array();
            } elseif ( isset( $information['error'] ) ) {
                $error = esc_html( $information['error'] );
                if ( isset( $information['error_code'] ) && 'folder_exists' === $information['error_code'] ) {
                    $error = esc_html__( 'Already installed', 'mainwp' );
                }

                if ( 'not found' === strtolower( $error ) && is_array( $post_data ) && isset( $post_data['type'] ) ) {
                    if ( 'plugin' === $post_data['type'] ) {
                        $error = esc_html__( 'Plugin file not found. Make sure security plugins or server-side security rules are not blocking requests from your child sites.', 'mainwp' );
                    } elseif ( 'theme' === $post_data['type'] ) {
                        $error = esc_html__( 'Theme file not found. Make sure security plugins or server-side security rules are not blocking requests from your child sites.', 'mainwp' );
                    }
                }

                $output->errors[ $website->id ] = array( $website->name, $error );
            } else {
                $output->errors[ $website->id ] = array(
                    $website->name,
                    __( 'Undefined error! Please reinstall the MainWP Child plugin on the child site', 'mainwp' ),
                );
            }
        } else {
            $output->errors[ $website->id ] = array( $website->name, 'Error while installing' );
        }
    }
}
