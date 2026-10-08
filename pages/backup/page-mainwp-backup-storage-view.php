<?php
/**
 * MainWP Backup Storage View.
 *
 * The storage destinations page: configured destinations with their
 * status, and the add/edit form that proves a connection before saving.
 * Secrets render as empty password fields; a blank secret on save keeps
 * the stored one, so credentials never travel back to the browser.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Render the storage destinations page.
     *
     * @return void
     */
    public static function render(): void {
        if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage backup storage', 'mainwp' ) );
            return;
        }

        MainWP_Backup_Page::render_header( 'Storage' );
        ?>
        <div class="ui alt segment" id="mainwp-backup-storage">
            <?php MainWP_Backup_Page::render_intro_message( 'mainwp-backup-storage-info-message', __( 'Storage destinations are where backup tasks copy their backups after each run. Choose them on each backup task.', 'mainwp' ) ); ?>
            <?php static::render_oauth_notice(); ?>
            <?php if ( ! empty( $_GET['sftp_trust_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only save notice. ?>
                <div class="ui yellow message"><?php echo esc_html( MainWP_Backup_Storage_Handler::sftp_trust_reset_notice() ); ?></div>
            <?php endif; ?>
            <div class="ui message" id="mainwp-storage-message-zone" style="display:none"></div>
            <?php static::render_list_table(); ?>
            <div class="ui hidden divider"></div>
            <?php static::render_form(); ?>
        </div>
        <?php
        self::render_scripts();
        MainWP_Backup_Page::render_footer( 'Storage' );
    }

    /**
     * Render the pending Connect Dropbox result once, then consume it.
     *
     * @return void
     */
    public static function render_oauth_notice(): void {
        $notice = MainWP_Backup_Storage_Handler::consume_dropbox_oauth_notice();
        if ( null === $notice ) {
            return;
        }
        if ( isset( $notice['error'] ) ) {
            ?>
            <div class="ui red message"><?php echo esc_html( $notice['error'] ); ?></div>
            <?php
            return;
        }
        ?>
        <div class="ui green message"><?php esc_html_e( 'Dropbox connected. The destination passed its connection test and was saved.', 'mainwp' ); ?></div>
        <?php
    }

    /**
     * Render the destinations table. Settings never load here, so no
     * secret can reach the page.
     *
     * @return void
     */
    public static function render_list_table(): void {
        $destinations = MainWP_Backup_Storage_Destinations::instance()->list_destinations();
        ?>
        <h3 class="ui header"><?php esc_html_e( 'Storage Destinations', 'mainwp' ); ?></h3>
        <table class="ui single line table" id="mainwp-storage-destinations-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Provider', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Default', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Actions', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $destinations ) ) : ?>
                <tr>
                    <td colspan="4"><?php esc_html_e( 'No storage destinations configured yet. Backups stay on this dashboard until a destination is added and selected on a backup task.', 'mainwp' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $destinations as $destination ) : ?>
                <tr data-destination-id="<?php echo intval( $destination->id ); ?>" data-provider="<?php echo esc_attr( $destination->provider ); ?>" data-name="<?php echo esc_attr( $destination->name ); ?>">
                    <td><?php echo esc_html( $destination->name ); ?></td>
                    <td><?php echo esc_html( $destination->provider ); ?></td>
                    <td>
                        <?php if ( ! empty( $destination->is_default ) ) : ?>
                            <span class="ui small green label"><?php esc_html_e( 'Default', 'mainwp' ); ?></span>
                        <?php else : ?>
                            <a href="#" class="mainwp-storage-make-default"><?php esc_html_e( 'Make default', 'mainwp' ); ?></a>
                        <?php endif; ?>
                    </td>
                    <td class="collapsing">
                        <button type="button" class="ui mini button mainwp-storage-edit"><?php esc_html_e( 'Edit', 'mainwp' ); ?></button>
                        <button type="button" class="ui mini button mainwp-storage-test"><?php esc_html_e( 'Test', 'mainwp' ); ?></button>
                        <button type="button" class="ui mini basic red button mainwp-storage-delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the add/edit destination form. The secret field always
     * renders empty; leaving it blank on an edit keeps the stored secret.
     *
     * @return void
     */
    public static function render_form(): void {
        ?>
        <div class="ui segment" id="mainwp-storage-form">
            <h3 class="ui header" id="mainwp-storage-form-title"><?php esc_html_e( 'Add Storage Destination', 'mainwp' ); ?></h3>
            <div class="ui form">
                <input type="hidden" id="mainwp-storage-id" value="0"/>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Destination name', 'mainwp' ); ?></label>
                    <div class="ten wide column">
                        <input type="text" id="mainwp-storage-name" value=""/>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Provider', 'mainwp' ); ?></label>
                    <div class="ten wide column">
                        <select id="mainwp-storage-provider" class="ui dropdown">
                            <option value="s3"><?php esc_html_e( 'S3 Compatible Storage', 'mainwp' ); ?></option>
                            <option value="gcs"><?php esc_html_e( 'Google Cloud Storage', 'mainwp' ); ?></option>
                            <option value="dropbox"><?php esc_html_e( 'Dropbox', 'mainwp' ); ?></option>
                            <option value="sftp"><?php esc_html_e( 'SFTP', 'mainwp' ); ?></option>
                            <option value="local"><?php esc_html_e( 'Local Path', 'mainwp' ); ?></option>
                        </select>
                    </div>
                </div>
                <div class="mainwp-storage-fields" data-provider="s3">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Endpoint', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-endpoint" value="" placeholder="s3.amazonaws.com"/>
                            <em><?php esc_html_e( 'Leave empty for Amazon S3. For S3 compatible services enter the service endpoint, for example https://accountid.r2.cloudflarestorage.com or http://127.0.0.1:9000.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Bucket', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-bucket" value=""/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Region', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-region" value="" placeholder="us-east-1"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Access key', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-access-key" value="" autocomplete="off"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Secret key', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="password" id="mainwp-storage-secret-key" value="" autocomplete="new-password"/>
                            <em><?php esc_html_e( 'When editing, leave this empty to keep the saved secret key.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Path style addressing', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox">
                            <input type="checkbox" id="mainwp-storage-path-style"/>
                            <label for="mainwp-storage-path-style"><?php esc_html_e( 'Required by MinIO and some self-hosted services.', 'mainwp' ); ?></label>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned" for="mainwp-storage-sse"><?php esc_html_e( 'Server-side encryption', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <select id="mainwp-storage-sse" class="ui dropdown">
                                <option value=""><?php esc_html_e( 'None', 'mainwp' ); ?></option>
                                <option value="AES256"><?php esc_html_e( 'SSE-S3 (AES-256)', 'mainwp' ); ?></option>
                                <option value="aws:kms"><?php esc_html_e( 'SSE-KMS (account default key)', 'mainwp' ); ?></option>
                            </select>
                            <em><?php esc_html_e( 'Ask the S3 service to encrypt objects at rest. Archives are already encrypted before they leave the dashboard, so this adds a second layer at the bucket. Leave it on None for MinIO and other S3 compatible services that reject the header.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                </div>
                <div class="mainwp-storage-fields" data-provider="gcs" style="display:none">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Bucket', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-gcs-bucket" value=""/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'HMAC access key', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-gcs-access-key" value="" autocomplete="off"/>
                            <em><?php esc_html_e( 'Create an HMAC key for a service account under Cloud Storage > Settings > Interoperability in the Google Cloud console.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'HMAC secret', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="password" id="mainwp-storage-gcs-secret-key" value="" autocomplete="new-password"/>
                            <em><?php esc_html_e( 'When editing, leave this empty to keep the saved secret.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                </div>
                <div class="mainwp-storage-fields" data-provider="dropbox" style="display:none">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'App key', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-dropbox-app-key" value="" autocomplete="off"/>
                            <em><?php esc_html_e( 'Create a scoped app with the files.content.write, files.content.read and files.metadata.read permissions in the Dropbox App Console.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'App secret', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="password" id="mainwp-storage-dropbox-app-secret" value="" autocomplete="new-password"/>
                            <em><?php esc_html_e( 'When editing, leave this empty to keep the saved secret.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Folder', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-dropbox-base-path" value="" placeholder="mainwp-backups"/>
                            <em><?php esc_html_e( 'Backups store under this folder. Leave empty to use the app folder root.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Redirect URI', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" readonly value="<?php echo esc_attr( MainWP_Backup_Storage_Handler::dropbox_redirect_uri() ); ?>" onfocus="this.select();"/>
                            <em><?php esc_html_e( 'Add this exact address to the Redirect URIs list on your app\'s settings page in the Dropbox App Console before connecting.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"></label>
                        <div class="ten wide column">
                            <button type="button" class="ui basic green button" id="mainwp-storage-dropbox-connect"><?php esc_html_e( 'Connect Dropbox', 'mainwp' ); ?></button>
                            <em><?php esc_html_e( 'Fill in the destination name, app key, and app secret, then connect. Dropbox asks for approval and the destination saves itself with a connection test.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Refresh token', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="password" id="mainwp-storage-dropbox-refresh-token" value="" autocomplete="new-password"/>
                            <em><?php esc_html_e( 'Advanced: paste a refresh token here to skip the Connect button, for example one issued on another dashboard. The dashboard mints short-lived access tokens from it automatically. When editing, leave this empty to keep the saved token.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                </div>
                <div class="mainwp-storage-fields" data-provider="sftp" style="display:none">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Host', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-sftp-host" value="" placeholder="sftp.example.com"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Port', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-sftp-port" value="" placeholder="22"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Username', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-sftp-username" value="" autocomplete="off"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="password" id="mainwp-storage-sftp-password" value="" autocomplete="new-password"/>
                            <em><?php esc_html_e( 'With a private key set, this is the key passphrase. When editing, leave this empty to keep the saved password.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Private key', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <textarea id="mainwp-storage-sftp-private-key" rows="4" autocomplete="off"></textarea>
                            <em><?php esc_html_e( 'Optional PEM private key for key-based login. When editing, leave this empty to keep the saved key.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Directory path', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-sftp-base-path" value="" placeholder="/backups"/>
                            <em><?php esc_html_e( 'An existing absolute path on the SFTP server. Backups store under it.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Host key fingerprint', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-sftp-host-fingerprint" value="" placeholder="SHA256:..."/>
                            <em><?php esc_html_e( 'Optional. The server\'s SHA256 host key fingerprint (ssh-keygen -lf). When empty, the key seen on the first connection is trusted and pinned automatically.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                </div>
                <div class="mainwp-storage-fields" data-provider="local" style="display:none">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Directory path', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" id="mainwp-storage-base-path" value=""/>
                            <em><?php esc_html_e( 'An absolute path on this dashboard server, for example a mounted network drive.', 'mainwp' ); ?></em>
                        </div>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Use as default destination', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-storage-is-default"/>
                    </div>
                </div>
                <div class="ui divider"></div>
                <?php // Inside .ui.form a message stays hidden until it also has the visible class, which feedback adds. ?>
                <div class="ui message" id="mainwp-storage-form-message-zone" style="display:none"></div>
                <button type="button" class="ui green button" id="mainwp-storage-save"><?php esc_html_e( 'Test Connection & Save', 'mainwp' ); ?></button>
                <button type="button" class="ui button" id="mainwp-storage-test-only"><?php esc_html_e( 'Test Connection', 'mainwp' ); ?></button>
                <button type="button" class="ui basic button" id="mainwp-storage-reset" style="display:none"><?php esc_html_e( 'Cancel Edit', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Render the page behavior script.
     *
     * @return void
     */
    private static function render_scripts(): void { // phpcs:ignore -- NOSONAR - page behavior script.
        ?>
        <script type="text/javascript">
        jQuery( function () {
            let feedbackZone = function ( message, color ) {
                jQuery( '#mainwp-storage-message-zone' ).attr( 'class', 'ui ' + color + ' message' ).text( message ).show();
            };

            // Results of the form's own buttons land next to those buttons; the
            // top zone sits a full page above them.
            let formFeedback = function ( message, color ) {
                jQuery( '#mainwp-storage-form-message-zone' ).attr( 'class', 'ui visible ' + color + ' message' ).text( message ).show();
            };

            let busy = function ( button, on ) {
                jQuery( button ).toggleClass( 'loading disabled', on );
            };

            let collectSettings = function () {
                if ( jQuery( '#mainwp-storage-provider' ).val() === 'local' ) {
                    return { base_path: jQuery( '#mainwp-storage-base-path' ).val() };
                }
                if ( jQuery( '#mainwp-storage-provider' ).val() === 'gcs' ) {
                    return {
                        bucket: jQuery( '#mainwp-storage-gcs-bucket' ).val(),
                        access_key: jQuery( '#mainwp-storage-gcs-access-key' ).val(),
                        secret_key: jQuery( '#mainwp-storage-gcs-secret-key' ).val()
                    };
                }
                if ( jQuery( '#mainwp-storage-provider' ).val() === 'dropbox' ) {
                    return {
                        app_key: jQuery( '#mainwp-storage-dropbox-app-key' ).val(),
                        app_secret: jQuery( '#mainwp-storage-dropbox-app-secret' ).val(),
                        refresh_token: jQuery( '#mainwp-storage-dropbox-refresh-token' ).val(),
                        base_path: jQuery( '#mainwp-storage-dropbox-base-path' ).val()
                    };
                }
                if ( jQuery( '#mainwp-storage-provider' ).val() === 'sftp' ) {
                    return {
                        host: jQuery( '#mainwp-storage-sftp-host' ).val(),
                        port: jQuery( '#mainwp-storage-sftp-port' ).val(),
                        username: jQuery( '#mainwp-storage-sftp-username' ).val(),
                        password: jQuery( '#mainwp-storage-sftp-password' ).val(),
                        private_key: jQuery( '#mainwp-storage-sftp-private-key' ).val(),
                        base_path: jQuery( '#mainwp-storage-sftp-base-path' ).val(),
                        host_fingerprint: jQuery( '#mainwp-storage-sftp-host-fingerprint' ).val()
                    };
                }
                return {
                    endpoint: jQuery( '#mainwp-storage-endpoint' ).val(),
                    bucket: jQuery( '#mainwp-storage-bucket' ).val(),
                    region: jQuery( '#mainwp-storage-region' ).val(),
                    access_key: jQuery( '#mainwp-storage-access-key' ).val(),
                    secret_key: jQuery( '#mainwp-storage-secret-key' ).val(),
                    path_style: jQuery( '#mainwp-storage-path-style' ).is( ':checked' ) ? 1 : 0,
                    sse: jQuery( '#mainwp-storage-sse' ).val()
                };
            };

            let destinationData = function ( action ) {
                let data = {
                    action: action,
                    id: jQuery( '#mainwp-storage-id' ).val(),
                    provider: jQuery( '#mainwp-storage-provider' ).val(),
                    name: jQuery( '#mainwp-storage-name' ).val(),
                    is_default: jQuery( '#mainwp-storage-is-default' ).is( ':checked' ) ? 1 : 0
                };
                let settings = collectSettings();
                for ( let key in settings ) {
                    data[ 'settings[' + key + ']' ] = settings[ key ];
                }
                return mainwp_secure_data( data );
            };

            jQuery( '#mainwp-storage-provider' ).on( 'change', function () {
                let provider = jQuery( this ).val();
                jQuery( '.mainwp-storage-fields' ).hide();
                jQuery( '.mainwp-storage-fields[data-provider="' + provider + '"]' ).show();
            } );

            let requestFailed = '<?php echo esc_js( __( 'The request did not reach the Dashboard. Check your connection and try again.', 'mainwp' ) ); ?>';

            jQuery( '#mainwp-storage-dropbox-connect' ).on( 'click', function () {
                let button = this;
                busy( button, true );
                formFeedback( '<?php echo esc_js( __( 'Connecting to Dropbox...', 'mainwp' ) ); ?>', 'green' );
                jQuery.post( ajaxurl, destinationData( 'mainwp_dropbox_oauth_start' ), function ( response ) {
                    if ( response && response.authorize_url ) {
                        location.href = response.authorize_url;
                    } else {
                        busy( button, false );
                        formFeedback( response && response.error ? response.error : '<?php echo esc_js( __( 'The Dropbox connection could not be started.', 'mainwp' ) ); ?>', 'red' );
                    }
                }, 'json' ).fail( function () {
                    busy( button, false );
                    formFeedback( requestFailed, 'red' );
                } );
            } );

            jQuery( '#mainwp-storage-save' ).on( 'click', function () {
                let button = this;
                busy( button, true );
                formFeedback( '<?php echo esc_js( __( 'Testing the connection and saving...', 'mainwp' ) ); ?>', 'green' );
                jQuery.post( ajaxurl, destinationData( 'mainwp_storage_save' ), function ( response ) {
                    if ( response && response.error ) {
                        busy( button, false );
                        formFeedback( response.error, 'red' );
                    } else {
                        location.href = location.pathname + location.search.replace( /&saved=1|&sftp_trust_reset=1/g, '' ) + '&saved=1' + ( response && response.notice ? '&sftp_trust_reset=1' : '' );
                    }
                }, 'json' ).fail( function () {
                    busy( button, false );
                    formFeedback( requestFailed, 'red' );
                } );
            } );

            jQuery( '#mainwp-storage-test-only' ).on( 'click', function () {
                let button = this;
                busy( button, true );
                formFeedback( '<?php echo esc_js( __( 'Testing the connection...', 'mainwp' ) ); ?>', 'green' );
                jQuery.post( ajaxurl, destinationData( 'mainwp_storage_test' ), function ( response ) {
                    if ( response && ( response.error || ! response.ok ) ) {
                        formFeedback( response.error || '<?php echo esc_js( __( 'Connection test failed.', 'mainwp' ) ); ?>', 'red' );
                    } else {
                        formFeedback( '<?php echo esc_js( __( 'Connection test passed.', 'mainwp' ) ); ?>', 'green' );
                    }
                }, 'json' ).fail( function () {
                    formFeedback( requestFailed, 'red' );
                } ).always( function () {
                    busy( button, false );
                } );
            } );

            jQuery( '#mainwp-storage-reset' ).on( 'click', function () {
                jQuery( '#mainwp-storage-id' ).val( 0 );
                jQuery( '#mainwp-storage-provider' ).dropdown( 'set selected', String( 's3' ) );
                jQuery( '#mainwp-storage-name, #mainwp-storage-endpoint, #mainwp-storage-bucket, #mainwp-storage-region, #mainwp-storage-access-key, #mainwp-storage-secret-key, #mainwp-storage-base-path, #mainwp-storage-gcs-bucket, #mainwp-storage-gcs-access-key, #mainwp-storage-gcs-secret-key, #mainwp-storage-dropbox-app-key, #mainwp-storage-dropbox-app-secret, #mainwp-storage-dropbox-refresh-token, #mainwp-storage-dropbox-base-path, #mainwp-storage-sftp-host, #mainwp-storage-sftp-port, #mainwp-storage-sftp-username, #mainwp-storage-sftp-password, #mainwp-storage-sftp-private-key, #mainwp-storage-sftp-base-path, #mainwp-storage-sftp-host-fingerprint' ).val( '' );
                jQuery( '#mainwp-storage-path-style, #mainwp-storage-is-default' ).prop( 'checked', false );
                // Fomantic owns this select's visible label, so clearing the
                // underlying value would leave the previous choice on screen.
                jQuery( '#mainwp-storage-sse' ).dropdown( 'restore defaults' );
                jQuery( '#mainwp-storage-form-title' ).text( '<?php echo esc_js( __( 'Add Storage Destination', 'mainwp' ) ); ?>' );
                jQuery( this ).hide();
            } );

            let settingsFieldMap = {
                s3: { endpoint: '#mainwp-storage-endpoint', bucket: '#mainwp-storage-bucket', region: '#mainwp-storage-region', access_key: '#mainwp-storage-access-key', sse: '#mainwp-storage-sse' },
                gcs: { bucket: '#mainwp-storage-gcs-bucket', access_key: '#mainwp-storage-gcs-access-key' },
                dropbox: { app_key: '#mainwp-storage-dropbox-app-key', base_path: '#mainwp-storage-dropbox-base-path' },
                sftp: { host: '#mainwp-storage-sftp-host', port: '#mainwp-storage-sftp-port', username: '#mainwp-storage-sftp-username', base_path: '#mainwp-storage-sftp-base-path', host_fingerprint: '#mainwp-storage-sftp-host-fingerprint' },
                local: { base_path: '#mainwp-storage-base-path' }
            };

            jQuery( '.mainwp-storage-edit' ).on( 'click', function () {
                let row = jQuery( this ).closest( 'tr' );
                let requestedId = row.data( 'destination-id' );
                jQuery( '#mainwp-storage-id' ).val( requestedId );
                jQuery( '#mainwp-storage-name' ).val( row.data( 'name' ) );
                jQuery( '#mainwp-storage-provider' ).dropdown( 'set selected', String( row.data( 'provider' ) ) );
                jQuery( '#mainwp-storage-form-title' ).text( '<?php echo esc_js( __( 'Edit Storage Destination', 'mainwp' ) ); ?>' );
                jQuery( '#mainwp-storage-reset' ).show();

                jQuery.post( ajaxurl, mainwp_secure_data( {
                    action: 'mainwp_storage_get',
                    id: requestedId
                } ), function ( response ) {
                    if ( String( jQuery( '#mainwp-storage-id' ).val() ) !== String( requestedId ) ) {
                        return; // A later Edit click owns the form; a stale response arriving out of order must not fill destination A's settings under destination B's id.
                    }
                    if ( ! response || response.error ) {
                        return; // The form still works; settings just are not pre-filled.
                    }
                    let map = settingsFieldMap[ response.provider ] || {};
                    let settings = {};
                    if ( response.settings && 'object' === typeof response.settings ) {
                        settings = response.settings;
                    }
                    for ( let key in map ) {
                        let value = typeof settings[ key ] !== 'undefined' ? settings[ key ] : '';
                        if ( '#mainwp-storage-sse' === map[ key ] ) {
                            // A Fomantic dropdown keeps its own label, so .val()
                            // would leave the previous destination's choice on
                            // screen. An empty setting means None, which
                            // 'set selected' will not match, so restore the
                            // default instead.
                            if ( '' === value ) {
                                jQuery( map[ key ] ).dropdown( 'restore defaults' );
                            } else {
                                jQuery( map[ key ] ).dropdown( 'set selected', value );
                            }
                            continue;
                        }
                        jQuery( map[ key ] ).val( value );
                    }
                    jQuery( '#mainwp-storage-path-style' ).prop( 'checked', !! parseInt( settings.path_style || 0 ) );
                    jQuery( '#mainwp-storage-is-default' ).prop( 'checked', !! response.is_default );
                    if ( response.credentials_invalid ) {
                        formFeedback( '<?php echo esc_js( __( 'The saved credentials can no longer be read. Re-enter every setting for this destination.', 'mainwp' ) ); ?>', 'yellow' );
                    }
                }, 'json' );

                formFeedback( '<?php echo esc_js( __( 'Secret fields are never displayed. Leave a secret field empty to keep its saved value.', 'mainwp' ) ); ?>', 'blue' );
            } );

            jQuery( '.mainwp-storage-test' ).on( 'click', function () {
                let button = this;
                let row = jQuery( this ).closest( 'tr' );
                busy( button, true );
                feedbackZone( '<?php echo esc_js( __( 'Testing the connection...', 'mainwp' ) ); ?>', 'green' );
                jQuery.post( ajaxurl, mainwp_secure_data( {
                    action: 'mainwp_storage_test',
                    id: row.data( 'destination-id' ),
                    provider: row.data( 'provider' ),
                    name: row.data( 'name' )
                } ), function ( response ) {
                    if ( response && ( response.error || ! response.ok ) ) {
                        feedbackZone( response.error || '<?php echo esc_js( __( 'Connection test failed.', 'mainwp' ) ); ?>', 'red' );
                    } else {
                        feedbackZone( '<?php echo esc_js( __( 'Connection test passed.', 'mainwp' ) ); ?>', 'green' );
                    }
                }, 'json' ).fail( function () {
                    feedbackZone( requestFailed, 'red' );
                } ).always( function () {
                    busy( button, false );
                } );
            } );

            let deleteDestination = function ( id, confirmed ) {
                jQuery.post( ajaxurl, mainwp_secure_data( {
                    action: 'mainwp_storage_delete',
                    id: id,
                    confirmed: confirmed ? 1 : 0
                } ), function ( response ) {
                    if ( response && response.tasks && response.tasks.length ) {
                        mainwp_confirm( '<?php echo esc_js( __( 'These backup tasks upload to this destination:', 'mainwp' ) ); ?>' + ' ' + jQuery( '<div>' ).text( response.tasks.join( ', ' ) ).html() + '. ' + '<?php echo esc_js( __( 'Deleting it removes the destination from those tasks and stops pending uploads. Delete anyway?', 'mainwp' ) ); ?>', function () {
                            deleteDestination( id, true );
                        } );
                    } else if ( response && response.error ) {
                        feedbackZone( response.error, 'red' );
                    } else {
                        location.reload();
                    }
                }, 'json' );
            };

            jQuery( '.mainwp-storage-delete' ).on( 'click', function () {
                let id = jQuery( this ).closest( 'tr' ).data( 'destination-id' );
                mainwp_confirm( '<?php echo esc_js( __( 'Delete this storage destination?', 'mainwp' ) ); ?>', function () {
                    deleteDestination( id, false );
                } );
            } );

            jQuery( '.mainwp-storage-make-default' ).on( 'click', function ( event ) {
                event.preventDefault();
                let id = jQuery( this ).closest( 'tr' ).data( 'destination-id' );
                jQuery.post( ajaxurl, mainwp_secure_data( {
                    action: 'mainwp_storage_default',
                    id: id
                } ), function ( response ) {
                    if ( response && response.error ) {
                        feedbackZone( response.error, 'red' );
                    } else {
                        location.reload();
                    }
                }, 'json' );
            } );
        } );
        </script>
        <?php
    }
}
