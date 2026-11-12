<?php
/**
 * Remote connector panel template, the "Connect an app" view of the MainWP MCP page.
 *
 * Receives $view from Remote_MCP_Admin::view(). Every value is escaped here, including names an
 * app registered for itself. Every control is a real form posting to the panel; admin-panel.js
 * adds the app picker, the tabs, the confirmations and saving on change on top of them.
 * The optional $render_header callback prints the page heading after the message zone.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- template-local variables in a file included inside a method.

$mainwp_rmcp_url = function ( string $anchor = '' ) use ( $view ): string {
    // Section forms return to the message zone so feedback stays visible after a change.
    return $view['panel_url'] . ( '' === $anchor ? '' : '#mainwp-mcp-message-zone' );
};

$mainwp_rmcp_fields = function ( string $action, string $target, array $fields = array() ) {
    ?>
    <input type="hidden" name="mainwp_remote_mcp_action" value="<?php echo esc_attr( $action ); ?>">
    <input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Remote_MCP_Admin::nonce_action( $action, $target ) ) ); ?>">
    <?php foreach ( $fields as $name => $value ) : ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
    <?php endforeach; ?>
    <?php
};

$mainwp_rmcp_form = function ( string $action, string $target, string $label, string $class_name = '', array $fields = array(), bool $disabled = false, string $anchor = '' ) use ( $mainwp_rmcp_url, $mainwp_rmcp_fields ) {
    $confirm = in_array( $action, array( 'narrow_scope', 'revoke' ), true ) ? '#mainwp-rmcp-confirm-' . $action : '';
    ?>
    <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( $anchor ) ); ?>" class="mainwp-remote-mcp-action"<?php echo '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : ''; ?> style="display:inline-block;margin:0 .25em .25em 0;">
        <?php $mainwp_rmcp_fields( $action, $target, $fields ); ?>
        <button type="submit" class="ui mini <?php echo esc_attr( $class_name ); ?> button"<?php echo $disabled ? ' disabled' : ''; ?>><?php echo esc_html( $label ); ?></button>
    </form>
    <?php
};

// The checkbox has no name: the hidden `value` field already says which way the switch flips.
$mainwp_rmcp_switch = function ( string $action, string $target, bool $on, string $anchor, string $label ) use ( $mainwp_rmcp_url, $mainwp_rmcp_fields, $view ) {
    $checkbox = '<input type="checkbox" aria-label="' . esc_attr( $label ) . '"' . ( $on ? ' checked' : '' ) . ( $view['full'] ? '' : ' disabled' ) . '><label></label>';
    if ( ! $view['full'] ) {
        echo '<div class="ui toggle checkbox disabled not-auto-init" data-tooltip="' . esc_attr__( 'Only administrators without Team Control limits can change this.', 'mainwp' ) . '" data-inverted="" data-position="left center">' . $checkbox . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
        return;
    }
    ?>
    <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( $anchor ) ); ?>" class="mainwp-remote-mcp-action mainwp-rmcp-switch" style="display:inline-block;margin:0;">
        <?php $mainwp_rmcp_fields( $action, $target, array( 'value' => $on ? 'off' : 'on' ) ); ?>
        <div class="ui toggle checkbox not-auto-init"><?php echo $checkbox; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
    </form>
    <?php
};

// These controls belong to the single Allowed Tools form; they never submit on change.
$mainwp_rmcp_bulk_switch = function ( string $name, bool $on, string $label ) use ( $view ) {
    $id = 'mainwp-rmcp-option-' . md5( $name );
    ?>
    <?php
    if ( $view['full'] ) :
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="off"><?php endif; ?>
    <div class="ui toggle checkbox not-auto-init<?php echo $view['full'] ? '' : ' disabled'; ?>">
        <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="on" aria-label="<?php echo esc_attr( $label ); ?>"<?php echo $on ? ' checked' : ''; ?><?php echo $view['full'] ? '' : ' disabled'; ?>>
        <label for="<?php echo esc_attr( $id ); ?>"></label>
    </div>
    <?php
};

$mainwp_rmcp_date = function ( int $time ): string {
    return $time > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : __( 'Never', 'mainwp' );
};

$mainwp_rmcp_ago = function ( int $time ): string {
    /* translators: %s: time span, such as "3 minutes". */
    return sprintf( __( '%s ago', 'mainwp' ), human_time_diff( $time ) );
};

// Code sits in its own scrolling box so a long line never widens the page on a phone.
$mainwp_rmcp_code = function ( string $id, string $text ) {
    ?>
    <div class="mainwp-rmcp-code">
        <div style="overflow-x:auto;"><pre id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $text ); ?></pre></div>
        <button type="button" class="ui mini basic button mainwp-code-copy-button" data-clipboard-target="#<?php echo esc_attr( $id ); ?>" data-copy-success="<?php esc_attr_e( 'Copied', 'mainwp' ); ?>" data-copy-error="<?php esc_attr_e( 'Copy failed', 'mainwp' ); ?>" aria-label="<?php esc_attr_e( 'Copy to clipboard', 'mainwp' ); ?>"><i class="copy outline icon"></i><?php esc_html_e( 'Copy', 'mainwp' ); ?></button>
    </div>
    <?php
};

$mainwp_rmcp_step = function ( int $number, callable $content ) {
    ?>
    <div class="item">
        <div class="image"><span class="ui circular label"><?php echo (int) $number; ?></span></div>
        <div class="content"><?php $content(); ?></div>
    </div>
    <?php
};

$mainwp_rmcp_lines = function ( array $lines ) {
    $labels = array(
        'pass'    => __( 'Pass', 'mainwp' ),
        'warn'    => __( 'Warn', 'mainwp' ),
        'fail'    => __( 'Fail', 'mainwp' ),
        'info'    => __( 'Info', 'mainwp' ),
        'skipped' => __( 'Skipped', 'mainwp' ),
    );
    $colors = array(
        'pass'    => 'green',
        'warn'    => 'yellow',
        'fail'    => 'red',
        'info'    => 'blue',
        'skipped' => 'grey',
    );
    ?>
    <table class="ui table">
        <tbody>
        <?php foreach ( $lines as $line ) : ?>
            <tr>
                <td class="collapsing"><span class="ui mini <?php echo esc_attr( $colors[ $line['status'] ] ?? 'grey' ); ?> label"><?php echo esc_html( $labels[ $line['status'] ] ?? ucfirst( $line['status'] ) ); ?></span></td>
                <td>
                    <?php echo esc_html( $line['message'] ); ?>
                    <?php if ( ! empty( $line['fix'] ) ) : ?>
                        <div><code><?php echo esc_html( $line['fix'] ); ?></code></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $line['help'] ) ) : ?>
                        <div><a href="<?php echo esc_url( $line['help'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What to check', 'mainwp' ); ?></a></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};

$mainwp_rmcp_result = $view['result'];
$mainwp_rmcp_action = (string) ( $mainwp_rmcp_result['action'] ?? '' );

// Token secrets appear only in the creation response; web app secrets stay with their section.
$mainwp_rmcp_secret_in = 'top';
if ( 'mint_token' === $mainwp_rmcp_action ) {
    $mainwp_rmcp_secret_in = 'tokens';
} elseif ( in_array( $mainwp_rmcp_action, array( 'webapp_add', 'webapp_reissue' ), true ) ) {
    $mainwp_rmcp_secret_in = 'apps';
}

$mainwp_rmcp_secret = function ( string $section, bool $show_heading = true ) use ( $mainwp_rmcp_result, $mainwp_rmcp_secret_in, $mainwp_rmcp_code ) {
    if ( empty( $mainwp_rmcp_result['secret'] ) || $section !== $mainwp_rmcp_secret_in ) {
        return;
    }
    ?>
    <div class="ui secondary segment mainwp-rmcp-secret">
        <?php if ( $show_heading ) : ?>
            <h4 class="ui dividing header"><?php echo esc_html( $mainwp_rmcp_result['secret_label'] ?? __( 'Secret', 'mainwp' ) ); ?></h4>
        <?php endif; ?>
        <div class="ui form">
            <?php if ( ! empty( $mainwp_rmcp_result['client_id'] ) ) : ?>
                <div class="ui grid field">
                    <label class="six wide column"><?php esc_html_e( 'Client ID', 'mainwp' ); ?></label>
                    <div class="ten wide column"><?php $mainwp_rmcp_code( 'mainwp-rmcp-secret-client-id', (string) $mainwp_rmcp_result['client_id'] ); ?></div>
                </div>
            <?php endif; ?>
            <div class="ui grid field">
                <label class="six wide column"><?php echo 'tokens' === $section ? esc_html__( 'Token', 'mainwp' ) : esc_html__( 'Client secret', 'mainwp' ); ?></label>
                <div class="ten wide column"><?php $mainwp_rmcp_code( 'mainwp-rmcp-secret', (string) $mainwp_rmcp_result['secret'] ); ?></div>
            </div>
            <?php if ( 'tokens' === $section ) : ?>
                <div class="ui grid field">
                    <label class="six wide column"><?php esc_html_e( 'Authorization header', 'mainwp' ); ?></label>
                    <div class="ten wide column">
                        <?php $mainwp_rmcp_code( 'mainwp-rmcp-secret-header', 'Authorization: Bearer ' . (string) $mainwp_rmcp_result['secret'] ); ?>
                        <p class="ui small text"><?php esc_html_e( 'Paste this header into your app, or pick the app below for a ready-made block.', 'mainwp' ); ?></p>
                    </div>
                </div>
                <?php
                $mainwp_rmcp_snippets = (array) ( $mainwp_rmcp_result['snippets'] ?? array() );
                uksort( $mainwp_rmcp_snippets, 'strnatcasecmp' );
                ?>
                <?php if ( ! empty( $mainwp_rmcp_snippets ) ) : ?>
                    <div class="ui grid field">
                        <label class="six wide column" for="mainwp-rmcp-token-picker"><?php esc_html_e( 'App settings', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <select id="mainwp-rmcp-token-picker" class="ui fluid selection dropdown not-auto-init" data-rmcp-picker="mainwp-rmcp-token-app">
                                <option value="" selected><?php esc_html_e( 'Pick your app', 'mainwp' ); ?></option>
                                <?php foreach ( array_keys( $mainwp_rmcp_snippets ) as $mainwp_rmcp_name ) : ?>
                                    <option value="<?php echo esc_attr( sanitize_title( $mainwp_rmcp_name ) ); ?>"><?php echo esc_html( $mainwp_rmcp_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php
                            $mainwp_rmcp_index = 0;
                            foreach ( $mainwp_rmcp_snippets as $mainwp_rmcp_name => $mainwp_rmcp_snippet ) :
                                ?>
                                <div class="mainwp-rmcp-token-app" data-app="<?php echo esc_attr( sanitize_title( $mainwp_rmcp_name ) ); ?>" hidden>
                                    <?php $mainwp_rmcp_code( 'mainwp-rmcp-secret-snippet-' . ( ++$mainwp_rmcp_index ), (string) $mainwp_rmcp_snippet ); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>
<div id="mainwp-remote-mcp-panel">
    <div id="mainwp-mcp-message-zone" aria-live="polite">
        <?php if ( ! empty( $mainwp_rmcp_result ) && '' !== (string) ( $mainwp_rmcp_result['message'] ?? '' ) ) : ?>
            <div class="ui <?php echo ! empty( $mainwp_rmcp_result['ok'] ) ? 'green' : 'red'; ?> message"><?php echo esc_html( $mainwp_rmcp_result['message'] ); ?></div>
        <?php endif; ?>
        <?php if ( ! empty( $mainwp_rmcp_result['reasons'] ) ) : ?>
            <div class="ui red message"><?php echo esc_html( implode( ', ', $mainwp_rmcp_result['reasons'] ) ); ?></div>
        <?php endif; ?>
        <?php if ( ! $view['requirements']['ok'] ) : ?>
            <div class="ui yellow message"><?php echo esc_html( $view['requirement'] ); ?></div>
        <?php else : ?>
            <?php if ( '' !== $view['forced'] ) : ?>
                <div class="ui yellow message"><?php echo esc_html( $view['forced'] ); ?></div>
            <?php endif; ?>
            <?php if ( ! $view['secure'] ) : ?>
                <div class="ui yellow message"><?php esc_html_e( 'Your site address starts with http://. Remote AI connections need https://.', 'mainwp' ); ?></div>
            <?php endif; ?>
            <?php if ( $view['home_changed'] ) : ?>
                <div class="ui yellow message">
                    <?php esc_html_e( 'Your site address changed. Connected AI apps need to reconnect.', 'mainwp' ); ?>
                    <?php $mainwp_rmcp_form( 'dismiss_home_changed', '', __( 'Dismiss', 'mainwp' ), 'basic' ); ?>
                </div>
            <?php endif; ?>
            <?php if ( $view['invalidation_failed'] ) : ?>
                <div class="ui red message"><?php esc_html_e( 'Could not end every affected AI connection. MainWP will retry on the next request.', 'mainwp' ); ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $view['notices'] ) ) : ?>
                <?php
                $mainwp_rmcp_events = array(
                    /* translators: 1: web app name, 2: when, such as "3 minutes ago". */
                    'client_added'           => __( 'Web app added: %1$s, %2$s.', 'mainwp' ),
                    /* translators: 1: web app name, 2: when, such as "3 minutes ago". */
                    'client_changed'         => __( 'Web app switched on or off: %1$s, %2$s.', 'mainwp' ),
                    /* translators: 1: web app name, 2: when, such as "3 minutes ago". */
                    'client_deleted'         => __( 'Web app deleted: %1$s, %2$s.', 'mainwp' ),
                    /* translators: 1: web app name, 2: when, such as "3 minutes ago". */
                    'client_secret_reissued' => __( 'Web app secret reissued: %1$s, %2$s.', 'mainwp' ),
                    /* translators: 1: app name, 2: when, such as "3 minutes ago". */
                    'loosened'               => __( '%1$s now asks you less often (policy changed %2$s).', 'mainwp' ),
                    /* translators: 1: connection token name, 2: when, such as "3 minutes ago", 3: IP address. */
                    'token'                  => __( '%1$s created %2$s from %3$s.', 'mainwp' ),
                    /* translators: 1: app name, 2: when, such as "3 minutes ago", 3: IP address. */
                    'new'                    => __( '%1$s connected %2$s from %3$s.', 'mainwp' ),
                );
                $mainwp_rmcp_count  = count( $view['notices'] );
                // Notices render for full administrators only, whose connections list holds every user's live grants.
                $mainwp_rmcp_live_ids = array_map( 'intval', array_column( $view['connections'], 'id' ) );
                ?>
                <div class="ui blue message" id="mainwp-rmcp-notices">
                    <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url() ); ?>" class="mainwp-remote-mcp-action mainwp-rmcp-notice-dismiss-form">
                        <?php $mainwp_rmcp_fields( 'dismiss_notices', '', array( 'upto' => (string) max( array_map( 'intval', array_column( $view['notices'], 'at' ) ) ) ) ); ?>
                        <button type="submit" class="mainwp-rmcp-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'mainwp' ); ?>"><i class="times icon" aria-hidden="true"></i></button>
                    </form>
                    <div class="header">
                        <?php
                        /* translators: %d: number of new events. */
                        echo esc_html( sprintf( _n( '%d change since you last checked', '%d changes since you last checked', $mainwp_rmcp_count, 'mainwp' ), $mainwp_rmcp_count ) );
                        ?>
                    </div>
                    <ul class="list">
                        <?php foreach ( array_slice( $view['notices'], 0, 5 ) as $mainwp_rmcp_notice ) : ?>
                            <li>
                            <?php
                            printf(
                                esc_html( $mainwp_rmcp_events[ $mainwp_rmcp_notice['why'] ?? '' ] ?? $mainwp_rmcp_events['new'] ),
                                esc_html( $mainwp_rmcp_notice['app'] ),
                                esc_html( $mainwp_rmcp_ago( (int) $mainwp_rmcp_notice['at'] ) ),
                                esc_html( '' !== (string) $mainwp_rmcp_notice['ip'] ? $mainwp_rmcp_notice['ip'] : __( 'an unknown address', 'mainwp' ) )
                            );
                            if ( (int) $mainwp_rmcp_notice['grant_id'] > 0 && ! in_array( (int) $mainwp_rmcp_notice['grant_id'], $mainwp_rmcp_live_ids, true ) ) {
                                echo ' ' . esc_html__( '(no longer connected)', 'mainwp' );
                            }
                            ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ( $mainwp_rmcp_count > 5 ) : ?>
                        <p>
                            <?php
                            /* translators: %d: number of events not listed. */
                            echo esc_html( sprintf( _n( 'And %d more in the activity log.', 'And %d more in the activity log.', $mainwp_rmcp_count - 5, 'mainwp' ), $mainwp_rmcp_count - 5 ) );
                            ?>
                        </p>
                    <?php endif; ?>
                    <a href="#connections"><?php esc_html_e( 'Review', 'mainwp' ); ?></a>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $view['pending'] ) ) : ?>
                <div class="ui yellow message" id="approvals">
                    <div class="header">
                        <?php
                        /* translators: %d: number of actions waiting. */
                        echo esc_html( sprintf( _n( '%d action is waiting for your approval', '%d actions are waiting for your approval', count( $view['pending'] ), 'mainwp' ), count( $view['pending'] ) ) );
                        ?>
                    </div>
                    <div class="ui middle aligned divided list">
                        <?php foreach ( $view['pending'] as $mainwp_rmcp_pending ) : ?>
                            <div class="item">
                                <div class="right floated content"><a class="ui mini button" href="<?php echo esc_url( $mainwp_rmcp_pending['url'] ); ?>"><?php esc_html_e( 'Review', 'mainwp' ); ?></a></div>
                                <div class="content">
                                    <?php
                                    $mainwp_rmcp_when = $mainwp_rmcp_ago( $mainwp_rmcp_pending['created_at'] );
                                    if ( '' !== $mainwp_rmcp_pending['site'] ) {
                                        $mainwp_rmcp_more  = $mainwp_rmcp_pending['site_count'] - 1;
                                        $mainwp_rmcp_sites = $mainwp_rmcp_pending['site'];
                                        if ( $mainwp_rmcp_more > 0 ) {
                                            /* translators: 1: site name, 2: number of other sites. */
                                            $mainwp_rmcp_sites = sprintf( _n( '%1$s and %2$d more site', '%1$s and %2$d more sites', $mainwp_rmcp_more, 'mainwp' ), $mainwp_rmcp_sites, $mainwp_rmcp_more );
                                        }
                                        /* translators: 1: action, 2: sites, 3: app name, 4: when, such as "3 minutes ago". */
                                        echo esc_html( sprintf( __( '%1$s on %2$s. Asked by %3$s, %4$s.', 'mainwp' ), $mainwp_rmcp_pending['action'], $mainwp_rmcp_sites, $mainwp_rmcp_pending['app'], $mainwp_rmcp_when ) );
                                    } else {
                                        /* translators: 1: action, 2: app name, 3: when, such as "3 minutes ago". */
                                        echo esc_html( sprintf( __( '%1$s. Asked by %2$s, %3$s.', 'mainwp' ), $mainwp_rmcp_pending['action'], $mainwp_rmcp_pending['app'], $mainwp_rmcp_when ) );
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
    $mainwp_rmcp_secret( 'top' );
    if ( ! $view['requirements']['ok'] ) {
        if ( null !== $render_header ) {
            call_user_func( $render_header );
        }
        echo '</div>';
        return;
    }
    $mainwp_rmcp_on          = $view['enabled'] && '' === $view['forced'];
    $mainwp_rmcp_connectable = $view['active'] && $view['secure'];
    ?>

    <?php if ( null !== $render_header ) : ?>
        <div class="ui basic accordion mainwp-blank-accordion mainwp-rmcp-section" id="mainwp-rmcp-connection-accordion">
            <?php call_user_func( $render_header, true ); ?>
            <div class="content active mainwp-rmcp-section-content" id="mainwp-rmcp-connection-content">
    <?php endif; ?>
    <div class="ui form" id="mainwp-rmcp-status">
        <div class="ui grid field">
            <label class="six wide column middle aligned" for="mainwp-rmcp-toggle"><?php esc_html_e( 'Enable remote AI connections', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <?php if ( $view['full'] ) : ?>
                    <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url() ); ?>" class="mainwp-remote-mcp-action" id="mainwp-rmcp-toggle-form"<?php echo $mainwp_rmcp_on ? ' data-confirm="#mainwp-rmcp-confirm-toggle-off"' : ''; ?>>
                        <?php $mainwp_rmcp_fields( 'toggle', $mainwp_rmcp_on ? 'off' : 'on' ); ?>
                        <div class="ui toggle checkbox not-auto-init<?php echo '' !== $view['forced'] ? ' disabled' : ''; ?>">
                            <input type="checkbox" id="mainwp-rmcp-toggle"<?php echo $mainwp_rmcp_on ? ' checked' : ''; ?><?php echo '' !== $view['forced'] ? ' disabled' : ''; ?>>
                            <label for="mainwp-rmcp-toggle"><?php echo $view['active'] ? esc_html__( 'Apps you approve can reach this Dashboard over the internet.', 'mainwp' ) : esc_html__( 'Off. No AI app can reach this Dashboard over the internet.', 'mainwp' ); ?></label>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="ui toggle checkbox disabled not-auto-init" data-tooltip="<?php esc_attr_e( 'Only administrators without Team Control limits can change this.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <input type="checkbox" id="mainwp-rmcp-toggle"<?php echo $mainwp_rmcp_on ? ' checked' : ''; ?> disabled>
                        <label for="mainwp-rmcp-toggle"><?php echo $view['active'] ? esc_html__( 'Apps you approve can reach this Dashboard over the internet.', 'mainwp' ) : esc_html__( 'Off. No AI app can reach this Dashboard over the internet.', 'mainwp' ); ?></label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ( null !== $render_header ) : ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ui basic accordion mainwp-blank-accordion mainwp-rmcp-section" id="mainwp-rmcp-connect-accordion">
    <h3 class="ui dividing header title active mainwp-rmcp-section-title" id="connect" role="button" tabindex="0" aria-expanded="true" aria-controls="mainwp-rmcp-connect-content">
        <i class="right dropdown icon"></i>
        <?php esc_html_e( 'Which app are you connecting?', 'mainwp' ); ?>
        <div class="sub header">
            <?php
            if ( $mainwp_rmcp_connectable ) {
                esc_html_e( 'Choose your app below and follow the connection steps.', 'mainwp' );
            } elseif ( ! $view['secure'] ) {
                esc_html_e( 'Apps can connect once your site address starts with https://.', 'mainwp' );
            } elseif ( '' !== $view['forced'] ) {
                esc_html_e( 'No app can connect while remote AI connections are turned off on this site.', 'mainwp' );
            } else {
                esc_html_e( 'Turn on remote AI connections to connect an app.', 'mainwp' );
            }
            ?>
        </div>
    </h3>
    <div class="content active mainwp-rmcp-section-content" id="mainwp-rmcp-connect-content">
    <?php if ( $mainwp_rmcp_connectable ) : ?>
        <?php
        $mainwp_rmcp_popular = array( 'Claude', 'ChatGPT', 'Claude Code', 'Cursor', 'VS Code', 'Codex CLI', 'Gemini CLI', 'Zed' );
        $mainwp_rmcp_names   = array_keys( $view['app_snippets'] );
        $mainwp_rmcp_rest    = array_values( array_diff( $mainwp_rmcp_names, $mainwp_rmcp_popular ) );
        usort( $mainwp_rmcp_rest, 'strnatcasecmp' );
        $mainwp_rmcp_order   = array_merge( array_values( array_intersect( $mainwp_rmcp_popular, $mainwp_rmcp_names ) ), $mainwp_rmcp_rest );
        $mainwp_rmcp_another = __( 'Another app', 'mainwp' );
        $mainwp_rmcp_approve = __( 'Approve the request that opens in your browser. The app then shows under Connected apps.', 'mainwp' );
        ?>
        <div class="ui form">
            <div class="ui grid field" id="mainwp-rmcp-app-field">
                <label class="six wide column" for="mainwp-rmcp-app-picker"><?php esc_html_e( 'Select an app', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <div class="mainwp-rmcp-app-setup">
                        <div class="mainwp-rmcp-app-select">
                            <select id="mainwp-rmcp-app-picker" class="ui fluid selection dropdown not-auto-init" data-rmcp-picker="mainwp-rmcp-app">
                                <?php foreach ( $mainwp_rmcp_order as $mainwp_rmcp_index => $mainwp_rmcp_app ) : ?>
                                    <option value="<?php echo esc_attr( sanitize_title( $mainwp_rmcp_app ) ); ?>"<?php echo 0 === $mainwp_rmcp_index ? ' selected' : ''; ?>><?php echo esc_html( $mainwp_rmcp_app ); ?></option>
                                <?php endforeach; ?>
                                <option value="<?php echo esc_attr( sanitize_title( 'Another app' ) ); ?>"><?php echo esc_html( $mainwp_rmcp_another ); ?></option>
                            </select>
                        </div>
                        <div class="mainwp-rmcp-app-instructions">
                            <?php foreach ( $mainwp_rmcp_order as $mainwp_rmcp_index => $mainwp_rmcp_app ) : ?>
                                <?php
                                $mainwp_rmcp_config = $view['app_snippets'][ $mainwp_rmcp_app ];
                                $mainwp_rmcp_slug   = sanitize_title( $mainwp_rmcp_app );
                                ?>
                                <div class="ui segment mainwp-rmcp-app" data-app="<?php echo esc_attr( $mainwp_rmcp_slug ); ?>" id="mainwp-rmcp-app-<?php echo esc_attr( $mainwp_rmcp_slug ); ?>"<?php echo 0 === $mainwp_rmcp_index ? '' : ' hidden'; ?>>
                                    <div class="ui relaxed list">
                                        <?php if ( false !== strpos( $mainwp_rmcp_config['connect'], 'Bearer ' ) ) : ?>
                                            <?php
                                            $mainwp_rmcp_step(
                                                1,
                                                function () {
                                                    printf(
                                                        /* translators: %s: link to the Connection tokens section. */
                                                        esc_html__( 'Create a connection token under %s.', 'mainwp' ),
                                                        '<a href="#tokens">' . esc_html__( 'Connection tokens', 'mainwp' ) . '</a>'
                                                    );
                                                }
                                            );
                                            $mainwp_rmcp_step(
                                                2,
                                                function () use ( $mainwp_rmcp_app, $mainwp_rmcp_config, $mainwp_rmcp_slug, $mainwp_rmcp_code ) {
                                                    /* translators: %s: app name. */
                                                    echo '<p>' . esc_html( sprintf( __( 'Copy the %s block that appears with the new token, or use this one and put your token in place of mwpct_...', 'mainwp' ), $mainwp_rmcp_app ) ) . '</p>';
                                                    $mainwp_rmcp_code( 'mainwp-rmcp-app-' . $mainwp_rmcp_slug . '-code', $mainwp_rmcp_config['connect'] );
                                                }
                                            );
                                            $mainwp_rmcp_step(
                                                3,
                                                function () use ( $mainwp_rmcp_app, $mainwp_rmcp_config ) {
                                                    /* translators: %s: app name. */
                                                    echo esc_html( $mainwp_rmcp_config['where'] ?? sprintf( __( 'Paste it into the MCP settings in %s.', 'mainwp' ), $mainwp_rmcp_app ) );
                                                }
                                            );
                                            ?>
                                        <?php else : ?>
                                            <?php
                                            $mainwp_rmcp_step(
                                                1,
                                                function () use ( $view, $mainwp_rmcp_config, $mainwp_rmcp_slug, $mainwp_rmcp_code ) {
                                                    $copy = $mainwp_rmcp_config['copy'] ?? 'settings';
                                                    if ( 'address' === $copy ) {
                                                        echo '<p>' . esc_html__( 'Copy this address', 'mainwp' ) . '</p>';
                                                    } elseif ( 'command' === $copy ) {
                                                        echo '<p>' . esc_html__( 'Copy this command', 'mainwp' ) . '</p>';
                                                    } else {
                                                        echo '<p>' . esc_html__( 'Copy these settings', 'mainwp' ) . '</p>';
                                                    }
                                                    $mainwp_rmcp_code( 'mainwp-rmcp-app-' . $mainwp_rmcp_slug . '-code', 'address' === $copy ? $view['mcp_url'] : $mainwp_rmcp_config['connect'] );
                                                }
                                            );
                                            $mainwp_rmcp_step(
                                                2,
                                                function () use ( $mainwp_rmcp_config ) {
                                                    echo esc_html( $mainwp_rmcp_config['where'] ?? '' );
                                                }
                                            );
                                            $mainwp_rmcp_step(
                                                3,
                                                function () use ( $mainwp_rmcp_approve ) {
                                                    echo esc_html( $mainwp_rmcp_approve );
                                                }
                                            );
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="ui segment mainwp-rmcp-app" data-app="<?php echo esc_attr( sanitize_title( 'Another app' ) ); ?>" id="mainwp-rmcp-app-<?php echo esc_attr( sanitize_title( 'Another app' ) ); ?>" hidden>
                                <?php if ( $view['registration'] ) : ?>
                                    <div class="ui relaxed list">
                                        <?php
                                        $mainwp_rmcp_step(
                                            1,
                                            function () use ( $view, $mainwp_rmcp_code ) {
                                                echo '<p>' . esc_html__( 'Copy this address', 'mainwp' ) . '</p>';
                                                $mainwp_rmcp_code( 'mainwp-rmcp-app-another-app-code', $view['mcp_url'] );
                                            }
                                        );
                                        $mainwp_rmcp_step(
                                            2,
                                            function () {
                                                esc_html_e( 'Add it to the app as a remote MCP server and sign in when it asks.', 'mainwp' );
                                            }
                                        );
                                        $mainwp_rmcp_step(
                                            3,
                                            function () use ( $mainwp_rmcp_approve ) {
                                                echo esc_html( $mainwp_rmcp_approve );
                                            }
                                        );
                                        ?>
                                    </div>
                                <?php else : ?>
                                    <p>
                                        <?php
                                        printf(
                                            /* translators: 1: the switch label, 2: link to the Which apps may connect section. */
                                            esc_html__( 'Turn on %1$s under %2$s first.', 'mainwp' ),
                                            '<strong>' . esc_html__( 'Let other apps connect', 'mainwp' ) . '</strong>',
                                            '<a href="#apps">' . esc_html__( 'Which apps may connect', 'mainwp' ) . '</a>'
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    </div>
    </div>
    <div class="ui basic accordion mainwp-blank-accordion mainwp-rmcp-section" id="mainwp-rmcp-connections-accordion">
    <h3 class="ui dividing header title active mainwp-rmcp-section-title" id="connections" role="button" tabindex="0" aria-expanded="true" aria-controls="mainwp-rmcp-connections-content">
        <i class="right dropdown icon"></i>
        <?php
        /* translators: %d: number of connected apps. */
        echo esc_html( sprintf( __( 'Connected apps (%d)', 'mainwp' ), count( $view['connections'] ) ) );
        ?>
        <div class="sub header">
            <?php
            if ( empty( $view['connections'] ) ) {
                echo $mainwp_rmcp_connectable ? esc_html__( 'No AI app is connected yet. Pick your app above to connect one.', 'mainwp' ) : esc_html__( 'No AI app is connected yet.', 'mainwp' );
            } else {
                esc_html_e( 'Manage connected AI apps, their access, and approval policies.', 'mainwp' );
            }
            ?>
        </div>
    </h3>
    <div class="content active mainwp-rmcp-section-content" id="mainwp-rmcp-connections-content">
    <?php if ( ! empty( $view['connections'] ) ) : ?>
        <?php
        $mainwp_rmcp_help = array();
        foreach ( $view['help'] as $mainwp_rmcp_entry ) {
            $mainwp_rmcp_help[ (int) $mainwp_rmcp_entry['grant_id'] ] = $mainwp_rmcp_entry['help_url'];
        }
        ?>
        <div style="text-align:right;margin-bottom:.5em;">
            <div class="ui mini basic dropdown button not-auto-init" id="mainwp-rmcp-disconnect" tabindex="0">
                <span class="text"><?php esc_html_e( 'Disconnect…', 'mainwp' ); ?></span>
                <i class="dropdown icon"></i>
                <div class="menu">
                    <div class="item" data-rmcp-form="mainwp-rmcp-disconnect-mine"><?php esc_html_e( 'My connections', 'mainwp' ); ?></div>
                    <?php if ( $view['full'] ) : ?>
                        <div class="item" data-rmcp-form="mainwp-rmcp-disconnect-all"><?php esc_html_e( 'Everyone\'s connections', 'mainwp' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( 'connections' ) ); ?>" class="mainwp-remote-mcp-action" id="mainwp-rmcp-disconnect-mine" data-confirm="#mainwp-rmcp-confirm-disconnect-mine" hidden>
            <?php $mainwp_rmcp_fields( 'disconnect_mine', '' ); ?>
        </form>
        <?php if ( $view['full'] ) : ?>
            <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( 'connections' ) ); ?>" class="mainwp-remote-mcp-action" id="mainwp-rmcp-disconnect-all" data-confirm="#mainwp-rmcp-confirm-disconnect-all" hidden>
                <?php $mainwp_rmcp_fields( 'disconnect_all', '' ); ?>
            </form>
        <?php endif; ?>
        <div style="overflow-x:auto;">
            <table class="ui unstackable table" id="mainwp-rmcp-connections">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'App', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'User', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Access', 'mainwp' ); ?></th>
                        <th data-tooltip="<?php esc_attr_e( 'How often MainWP asks you before the app changes something. Standard: ordinary changes run right away, some are confirmed with you in the app, and the riskiest wait for your approval on this Dashboard. Strict: every change waits for your approval on this Dashboard. Hands-off: changes that would wait for your approval here are confirmed in the chat instead, except adding sites, restoring or deleting backups or backup tasks, changing backup retention, changing a site\'s URL or SSL settings, and replacing the custom dashboard snippet, which always wait for your approval here. Instructions hidden in a site\'s content could then trigger changes.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><?php esc_html_e( 'Approval policy', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Last used', 'mainwp' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $view['connections'] as $mainwp_rmcp_row ) : ?>
                    <tr data-grant="<?php echo esc_attr( $mainwp_rmcp_row['id'] ); ?>"<?php echo $view['requested_revoke'] === $mainwp_rmcp_row['id'] ? ' class="warning"' : ''; ?>>
                        <?php // Hosts of apps MainWP doesn't know can be long; let them break, but keep the column wide enough on a phone that the table scrolls instead of breaking every few letters. ?>
                        <td style="min-width:12em;">
                            <span style="overflow-wrap:anywhere;"><?php echo esc_html( $mainwp_rmcp_row['name'] ); ?></span>
                            <div>
                                <small class="ui grey text"><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'connected %s', 'mainwp' ), wp_date( get_option( 'date_format' ), $mainwp_rmcp_row['created_at'] ) ) ); ?></small>
                                <?php if ( 'self_named' === $mainwp_rmcp_row['chip'] ) : ?>
                                    <span class="ui mini label" data-tooltip="<?php esc_attr_e( 'This app named itself when it registered. MainWP hasn\'t checked the name.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><?php esc_html_e( 'name from the app', 'mainwp' ); ?></span>
                                <?php elseif ( 'unchecked' === $mainwp_rmcp_row['chip'] ) : ?>
                                    <span class="ui mini label" data-tooltip="<?php esc_attr_e( 'MainWP doesn\'t know this app. It is named by the address its details came from.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><?php esc_html_e( 'not checked by MainWP', 'mainwp' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html( $mainwp_rmcp_row['user'] ); ?></td>
                        <td><?php echo $mainwp_rmcp_row['can_write'] ? esc_html__( 'View and change', 'mainwp' ) : esc_html__( 'View only', 'mainwp' ); ?></td>
                        <td>
                            <?php if ( $mainwp_rmcp_row['own'] ) : ?>
                                <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( 'connections' ) ); ?>" class="mainwp-remote-mcp-action">
                                    <?php $mainwp_rmcp_fields( 'set_policy', (string) $mainwp_rmcp_row['id'] ); ?>
                                    <select name="policy" class="ui mini selection dropdown not-auto-init mainwp-rmcp-submit-on-change" aria-label="<?php esc_attr_e( 'Approval policy', 'mainwp' ); ?>">
                                        <?php foreach ( $mainwp_rmcp_row['hands_off'] ? OAuth_Store::POLICIES : array( OAuth_Store::POLICY_STANDARD, OAuth_Store::POLICY_STRICT ) as $mainwp_rmcp_policy ) : ?>
                                            <option value="<?php echo esc_attr( $mainwp_rmcp_policy ); ?>"<?php selected( $mainwp_rmcp_policy, $mainwp_rmcp_row['policy'] ); ?>><?php echo esc_html( Remote_MCP_Admin::policy_label( $mainwp_rmcp_policy ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else : ?>
                                <?php echo esc_html( Remote_MCP_Admin::policy_label( $mainwp_rmcp_row['policy'] ) ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $mainwp_rmcp_row['last_used'] > 0 ) : ?>
                                <?php echo esc_html( $mainwp_rmcp_ago( $mainwp_rmcp_row['last_used'] ) ); ?>
                            <?php elseif ( isset( $mainwp_rmcp_help[ $mainwp_rmcp_row['id'] ] ) ) : ?>
                                <span data-tooltip="<?php echo esc_attr( sprintf( /* translators: %s: app name. */ __( '%s was approved but hasn\'t made a request yet.', 'mainwp' ), $mainwp_rmcp_row['label'] ) ); ?>" data-inverted="" data-position="top left"><?php esc_html_e( 'Not yet', 'mainwp' ); ?></span>
                                <br><a href="<?php echo esc_url( $mainwp_rmcp_help[ $mainwp_rmcp_row['id'] ] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What to check on your host', 'mainwp' ); ?></a>
                            <?php else : ?>
                                <?php esc_html_e( 'Not yet', 'mainwp' ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( '' !== $mainwp_rmcp_row['failure'] && $mainwp_rmcp_row['failure_at'] >= $mainwp_rmcp_row['last_used'] ) : ?>
                                <?php
                                /* translators: 1: reason code, 2: when, such as "3 minutes ago". */
                                $mainwp_rmcp_tip = sprintf( __( 'Last failure %2$s: %1$s', 'mainwp' ), $mainwp_rmcp_row['failure'], $mainwp_rmcp_ago( $mainwp_rmcp_row['failure_at'] ) );
                                if ( '' !== $mainwp_rmcp_row['last_ip'] ) {
                                    /* translators: %s: IP address. */
                                    $mainwp_rmcp_tip .= '. ' . sprintf( __( 'Last request from %s.', 'mainwp' ), $mainwp_rmcp_row['last_ip'] );
                                }
                                ?>
                                <span class="ui mini red label" data-tooltip="<?php echo esc_attr( $mainwp_rmcp_tip ); ?>" data-inverted="" data-position="top left"><?php esc_html_e( 'Failing', 'mainwp' ); ?></span>
                            <?php elseif ( $mainwp_rmcp_row['last_used'] > 0 ) : ?>
                                <span class="ui mini green label"<?php echo '' !== $mainwp_rmcp_row['last_ip'] ? ' data-tooltip="' . esc_attr( sprintf( /* translators: %s: IP address. */ __( 'Last request from %s.', 'mainwp' ), $mainwp_rmcp_row['last_ip'] ) ) . '" data-inverted="" data-position="top left"' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></span>
                            <?php else : ?>
                                <span class="ui mini grey label"><?php esc_html_e( 'No requests yet', 'mainwp' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="right aligned collapsing" style="white-space:nowrap;">
                            <?php
                            if ( $mainwp_rmcp_row['own'] && $mainwp_rmcp_row['can_write'] ) {
                                $mainwp_rmcp_form( 'narrow_scope', (string) $mainwp_rmcp_row['id'], __( 'View only', 'mainwp' ), 'basic', array(), false, 'connections' );
                            }
                            $mainwp_rmcp_form( 'revoke', (string) $mainwp_rmcp_row['id'], __( 'Revoke', 'mainwp' ), 'red', array(), false, 'connections' );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    </div>
    </div>
    <?php
    // Keep the relevant Advanced tab selected after a form posts back to the message zone.
    $mainwp_rmcp_tabs_by_action = array(
        'mint_token'           => 'tokens',
        'webapp_add'           => 'apps',
        'webapp_delete'        => 'apps',
        'webapp_reissue'       => 'apps',
        'webapp_switch'        => 'apps',
        'registration_remove'  => 'apps',
        'registration_switch'  => 'apps',
        'static_client_switch' => 'apps',
        'tool_switch'          => 'tools',
        'category_switch'      => 'tools',
        'unaudited_switch'     => 'tools',
        'save_tools'           => 'tools',
        'self_test'            => 'checks',
        'external_check'       => 'checks',
    );
    $mainwp_rmcp_active_tab     = ! empty( $mainwp_rmcp_result['reasons'] ) ? 'checks' : ( $mainwp_rmcp_tabs_by_action[ $mainwp_rmcp_action ] ?? 'tokens' );
    $mainwp_rmcp_advanced_open  = isset( $mainwp_rmcp_tabs_by_action[ $mainwp_rmcp_action ] ) || ! empty( $mainwp_rmcp_result['reasons'] );
    $mainwp_rmcp_content        = function ( string $id ) use ( $mainwp_rmcp_active_tab ): string {
        return 'ui tab' . ( $id === $mainwp_rmcp_active_tab ? ' active' : '' );
    };

    $mainwp_rmcp_app_parts = array();
    foreach ( $view['static_clients'] as $mainwp_rmcp_static ) {
        /* translators: %s: app name. */
        $mainwp_rmcp_app_parts[] = sprintf( $mainwp_rmcp_static['enabled'] ? __( '%s on', 'mainwp' ) : __( '%s off', 'mainwp' ), $mainwp_rmcp_static['label'] );
    }
    $mainwp_rmcp_app_parts[] = $view['registration'] ? __( 'other apps on', 'mainwp' ) : __( 'other apps off', 'mainwp' );
    if ( ! empty( $view['webapps'] ) ) {
        /* translators: %d: number of web apps. */
        $mainwp_rmcp_app_parts[] = sprintf( _n( '%d web app', '%d web apps', count( $view['webapps'] ), 'mainwp' ), count( $view['webapps'] ) );
    }

    $mainwp_rmcp_tools_total = 0;
    $mainwp_rmcp_tools_on    = 0;
    foreach ( $view['tools']['available'] as $mainwp_rmcp_category => $mainwp_rmcp_rows ) {
        $mainwp_rmcp_category_on = Remote_MCP_Settings::category_enabled( (string) $mainwp_rmcp_category );
        foreach ( $mainwp_rmcp_rows as $mainwp_rmcp_tool ) {
            ++$mainwp_rmcp_tools_total;
            $mainwp_rmcp_tools_on += $mainwp_rmcp_category_on && Remote_MCP_Settings::tool_enabled( $mainwp_rmcp_tool['tool_name'] ) ? 1 : 0;
        }
    }
    foreach ( $view['tools']['not_reviewed'] as $mainwp_rmcp_tool ) {
        ++$mainwp_rmcp_tools_total;
        $mainwp_rmcp_tools_on += $view['unaudited'] && Remote_MCP_Settings::tool_enabled( $mainwp_rmcp_tool['tool_name'] ) ? 1 : 0;
    }
    /* translators: 1: tools switched on, 2: all tools. */
    $mainwp_rmcp_tools_summary = sprintf( __( '%1$d of %2$d on.', 'mainwp' ), $mainwp_rmcp_tools_on, $mainwp_rmcp_tools_total );
    if ( ! empty( $view['tools']['not_reviewed'] ) ) {
        /* translators: %d: number of tools MainWP hasn't reviewed. */
        $mainwp_rmcp_tools_summary .= ' ' . sprintf( _n( '%d not reviewed by MainWP.', '%d not reviewed by MainWP.', count( $view['tools']['not_reviewed'] ), 'mainwp' ), count( $view['tools']['not_reviewed'] ) );
    }

    $mainwp_rmcp_checks_summary = __( 'Run the self-test or check from the internet.', 'mainwp' );
    if ( ! empty( $mainwp_rmcp_result['lines'] ) ) {
        $mainwp_rmcp_failed = count( wp_list_filter( $mainwp_rmcp_result['lines'], array( 'status' => 'fail' ) ) );
        /* translators: %d: number of failed checks. */
        $mainwp_rmcp_checks_summary = 0 === $mainwp_rmcp_failed ? __( 'Just ran. Nothing failed.', 'mainwp' ) : sprintf( _n( 'Just ran. %d check failed.', 'Just ran. %d checks failed.', $mainwp_rmcp_failed, 'mainwp' ), $mainwp_rmcp_failed );
    }

    $mainwp_rmcp_tabs = array(
        'tokens' => __( 'Connection Tokens', 'mainwp' ),
        'apps'   => __( 'Allowed Apps', 'mainwp' ),
        'tools'  => __( 'Allowed Tools', 'mainwp' ),
        'checks' => __( 'Connection Checks', 'mainwp' ),
    );

    $mainwp_rmcp_tool_rows = function ( array $rows ) use ( $mainwp_rmcp_bulk_switch ) {
        foreach ( $rows as $mainwp_rmcp_tool ) {
            $name = 'tools[' . $mainwp_rmcp_tool['tool_name'] . ']';
            ?>
            <div class="ui grid field">
                <label class="six wide column middle aligned" for="<?php echo esc_attr( 'mainwp-rmcp-option-' . md5( $name ) ); ?>"><?php echo esc_html( $mainwp_rmcp_tool['label'] ); ?></label>
                <div class="ten wide column">
                    <?php $mainwp_rmcp_bulk_switch( $name, Remote_MCP_Settings::tool_enabled( $mainwp_rmcp_tool['tool_name'] ), $mainwp_rmcp_tool['label'] ); ?>
                    <span class="ui small grey text"><code><?php echo esc_html( $mainwp_rmcp_tool['tool_name'] ); ?></code></span>
                </div>
            </div>
            <?php
        }
    };
    ?>

    <div class="ui basic accordion mainwp-blank-accordion mainwp-rmcp-section" id="mainwp-rmcp-advanced-accordion">
        <h3 class="ui dividing header title mainwp-rmcp-section-title<?php echo $mainwp_rmcp_advanced_open ? ' active' : ''; ?>" id="mainwp-rmcp-advanced-title" role="button" tabindex="0" aria-expanded="<?php echo $mainwp_rmcp_advanced_open ? 'true' : 'false'; ?>" aria-controls="mainwp-rmcp-advanced">
            <i class="right dropdown icon"></i>
            <?php esc_html_e( 'Advanced', 'mainwp' ); ?>
            <div class="sub header"><?php esc_html_e( 'Manage connection tokens, allowed apps, tools, and connection checks.', 'mainwp' ); ?></div>
        </h3>
        <div class="content mainwp-rmcp-section-content<?php echo $mainwp_rmcp_advanced_open ? ' active' : ''; ?>" id="mainwp-rmcp-advanced">
            <div class="ui secondary pointing fluid menu" id="mainwp-rmcp-advanced-menu" role="tablist" aria-label="<?php esc_attr_e( 'Advanced', 'mainwp' ); ?>">
                <?php foreach ( $mainwp_rmcp_tabs as $mainwp_rmcp_tab => $mainwp_rmcp_label ) : ?>
                    <a class="item<?php echo $mainwp_rmcp_tab === $mainwp_rmcp_active_tab ? ' active' : ''; ?>" id="mainwp-rmcp-tab-<?php echo esc_attr( $mainwp_rmcp_tab ); ?>" href="#<?php echo esc_attr( $mainwp_rmcp_tab ); ?>" role="tab" data-tab="mainwp-rmcp-<?php echo esc_attr( $mainwp_rmcp_tab ); ?>" aria-controls="<?php echo esc_attr( $mainwp_rmcp_tab ); ?>" aria-selected="<?php echo $mainwp_rmcp_tab === $mainwp_rmcp_active_tab ? 'true' : 'false'; ?>" tabindex="<?php echo $mainwp_rmcp_tab === $mainwp_rmcp_active_tab ? '0' : '-1'; ?>"><?php echo esc_html( $mainwp_rmcp_label ); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="<?php echo esc_attr( $mainwp_rmcp_content( 'tokens' ) ); ?>" id="tokens" data-tab="mainwp-rmcp-tokens" role="tabpanel" aria-labelledby="mainwp-rmcp-tab-tokens" tabindex="0">
                <div class="ui message">
                    <p>
                        <?php
                        /* translators: %d: number of connection tokens the user has. */
                        echo esc_html( sprintf( _n( 'For apps that can\'t sign in. You have %d token.', 'For apps that can\'t sign in. You have %d tokens.', $view['token_count'], 'mainwp' ), $view['token_count'] ) );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'For apps that can\'t sign in with your Dashboard account but can send a fixed key. The token is shown once and expires on the date you pick.', 'mainwp' ); ?></p>
                </div>
                <h4 class="ui dividing header"><?php esc_html_e( 'Create a Connection Token', 'mainwp' ); ?></h4>
                <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( 'tokens' ) ); ?>" class="ui form">
                    <?php $mainwp_rmcp_fields( 'mint_token', '' ); ?>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned" for="mainwp-rmcp-token-label"><?php esc_html_e( 'Name', 'mainwp' ); ?></label>
                        <div class="ten wide column"><input type="text" id="mainwp-rmcp-token-label" name="label" maxlength="64" required></div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned" for="mainwp-rmcp-token-access"><?php esc_html_e( 'Access', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <select id="mainwp-rmcp-token-access" name="access" class="ui fluid dropdown">
                                <option value="read" selected><?php esc_html_e( 'View only', 'mainwp' ); ?></option>
                                <option value="write"><?php esc_html_e( 'View and change', 'mainwp' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned" for="mainwp-rmcp-token-policy"><?php esc_html_e( 'Approval policy', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <select id="mainwp-rmcp-token-policy" name="policy" class="ui fluid dropdown">
                                <?php foreach ( $view['policies'] as $mainwp_rmcp_policy ) : ?>
                                    <option value="<?php echo esc_attr( $mainwp_rmcp_policy ); ?>"<?php selected( $mainwp_rmcp_policy, OAuth_Store::POLICY_STANDARD ); ?>><?php echo esc_html( Remote_MCP_Admin::policy_label( $mainwp_rmcp_policy ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned" for="mainwp-rmcp-token-expiry"><?php esc_html_e( 'Expires in', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <select id="mainwp-rmcp-token-expiry" name="expiry" class="ui fluid dropdown">
                                <?php foreach ( $view['token_days'] as $mainwp_rmcp_days ) : ?>
                                    <option value="<?php echo esc_attr( $mainwp_rmcp_days ); ?>"<?php selected( $mainwp_rmcp_days, $view['token_default'] ); ?>><?php echo esc_html( sprintf( /* translators: %s: number of days. */ __( '%s days', 'mainwp' ), $mainwp_rmcp_days ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <div class="six wide column"></div>
                        <div class="ten wide column">
                            <button type="submit" class="ui green button"><?php esc_html_e( 'Create Token', 'mainwp' ); ?></button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="<?php echo esc_attr( $mainwp_rmcp_content( 'apps' ) ); ?>" id="apps" data-tab="mainwp-rmcp-apps" role="tabpanel" aria-labelledby="mainwp-rmcp-tab-apps" tabindex="0">
                <div class="ui message"><?php echo esc_html( implode( ', ', $mainwp_rmcp_app_parts ) ); ?></div>
                <h4 class="ui dividing header"><?php esc_html_e( 'Known apps', 'mainwp' ); ?></h4>
                <div class="ui form">
                    <?php foreach ( $view['static_clients'] as $mainwp_rmcp_static ) : ?>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php echo esc_html( $mainwp_rmcp_static['label'] ); ?></label>
                            <div class="ten wide column"><?php $mainwp_rmcp_switch( 'static_client_switch', $mainwp_rmcp_static['client_id'], $mainwp_rmcp_static['enabled'], 'apps', $mainwp_rmcp_static['label'] ); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Let other apps connect', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <?php $mainwp_rmcp_switch( 'registration_switch', '', $view['registration'], 'apps', __( 'Let other apps connect', 'mainwp' ) ); ?>
                            <p class="ui small text"><?php esc_html_e( 'Apps that aren\'t listed can connect once you approve them: apps on your computer that register themselves, and apps that publish their details at an https:// address. MainWP hasn\'t checked these apps.', 'mainwp' ); ?></p>
                        </div>
                    </div>
                </div>
                <h4 class="ui dividing header"><?php esc_html_e( 'Apps that registered themselves', 'mainwp' ); ?></h4>
                <?php if ( empty( $view['registered'] ) ) : ?>
                    <div class="ui message"><?php esc_html_e( 'No apps have registered yet.', 'mainwp' ); ?></div>
                <?php else : ?>
                    <div class="ui form">
                        <?php foreach ( $view['registered'] as $mainwp_rmcp_client ) : ?>
                            <div class="ui grid field">
                                <label class="six wide column">
                                    <?php echo esc_html( '' !== (string) $mainwp_rmcp_client['client_name'] ? $mainwp_rmcp_client['client_name'] : __( 'An app on this computer', 'mainwp' ) ); ?>
                                    <span class="ui mini label" data-tooltip="<?php esc_attr_e( 'This app named itself when it registered. MainWP hasn\'t checked the name.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><?php esc_html_e( 'name from the app', 'mainwp' ); ?></span>
                                </label>
                                <div class="ten wide column mainwp-rmcp-app-details">
                                    <p><?php echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'Registered %s', 'mainwp' ), $mainwp_rmcp_date( (int) $mainwp_rmcp_client['created_at'] ) ) ); ?></p>
                                    <p><?php echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'Last used %s', 'mainwp' ), $mainwp_rmcp_date( (int) $mainwp_rmcp_client['last_used_at'] ) ) ); ?></p>
                                    <p><?php esc_html_e( 'Registration IP', 'mainwp' ); ?>: <code><?php echo esc_html( (string) $mainwp_rmcp_client['created_ip'] ); ?></code></p>
                                    <?php
                                    if ( $view['full'] ) :
                                        ?>
                                        <?php $mainwp_rmcp_form( 'registration_remove', (string) $mainwp_rmcp_client['client_id'], __( 'Remove', 'mainwp' ), 'red', array(), false, 'apps' ); ?><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ( $view['full'] ) : ?>
                    <h4 class="ui dividing header"><?php esc_html_e( 'Web apps you added', 'mainwp' ); ?></h4>
                    <?php $mainwp_rmcp_secret( 'apps' ); ?>
                    <?php if ( ! empty( $view['webapps'] ) ) : ?>
                        <div class="ui form" id="mainwp-rmcp-webapps">
                            <?php foreach ( $view['webapps'] as $mainwp_rmcp_client ) : ?>
                                <div class="ui grid field">
                                    <label class="six wide column"><?php echo esc_html( (string) $mainwp_rmcp_client['client_name'] ); ?></label>
                                    <div class="ten wide column mainwp-rmcp-app-details">
                                        <p><?php esc_html_e( 'Client ID', 'mainwp' ); ?>: <code><?php echo esc_html( (string) $mainwp_rmcp_client['client_id'] ); ?></code></p>
                                        <p><?php esc_html_e( 'Return addresses', 'mainwp' ); ?>: <?php echo esc_html( implode( ', ', (array) $mainwp_rmcp_client['redirect_uris'] ) ); ?></p>
                                        <div class="mainwp-rmcp-app-actions">
                                            <?php
                                            $mainwp_rmcp_switch( 'webapp_switch', (string) $mainwp_rmcp_client['client_id'], 'active' === $mainwp_rmcp_client['status'], 'apps', (string) $mainwp_rmcp_client['client_name'] );
                                            $mainwp_rmcp_form( 'webapp_reissue', (string) $mainwp_rmcp_client['client_id'], __( 'New secret', 'mainwp' ), 'basic', array(), false, 'apps' );
                                            $mainwp_rmcp_form( 'webapp_delete', (string) $mainwp_rmcp_client['client_id'], __( 'Delete', 'mainwp' ), 'red', array(), false, 'apps' );
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="ui message"><?php esc_html_e( 'No custom web apps have been added yet.', 'mainwp' ); ?></div>
                    <?php endif; ?>
                    <h4 class="ui dividing header"><?php esc_html_e( 'Add a Custom App', 'mainwp' ); ?></h4>
                    <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( 'apps' ) ); ?>" class="ui form">
                        <?php $mainwp_rmcp_fields( 'webapp_add', '' ); ?>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned" for="mainwp-rmcp-webapp-name"><?php esc_html_e( 'App name', 'mainwp' ); ?></label>
                            <div class="ten wide column"><input type="text" id="mainwp-rmcp-webapp-name" name="name" maxlength="64" required></div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column" for="mainwp-rmcp-webapp-redirect-uris"><?php esc_html_e( 'Return addresses', 'mainwp' ); ?></label>
                            <div class="ten wide column">
                                <textarea id="mainwp-rmcp-webapp-redirect-uris" name="redirect_uris" rows="3" required aria-describedby="mainwp-rmcp-webapp-redirect-uris-help"></textarea>
                                <p id="mainwp-rmcp-webapp-redirect-uris-help" class="ui small text"><?php esc_html_e( 'Enter the HTTPS addresses supplied by the app developer, one per line (up to 3).', 'mainwp' ); ?></p>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <div class="six wide column"></div>
                            <div class="ten wide column"><button type="submit" class="ui green button"><?php esc_html_e( 'Add Custom App', 'mainwp' ); ?></button></div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="<?php echo esc_attr( $mainwp_rmcp_content( 'tools' ) ); ?>" id="tools" data-tab="mainwp-rmcp-tools" role="tabpanel" aria-labelledby="mainwp-rmcp-tab-tools" tabindex="0">
                <div class="ui message">
                    <p><?php echo esc_html( $mainwp_rmcp_tools_summary ); ?></p>
                    <p><?php esc_html_e( 'Choose which tools AI apps may use, then select Save Settings to apply your changes.', 'mainwp' ); ?></p>
                    <?php
                    if ( ! $view['full'] ) :
                        ?>
                        <p><?php esc_html_e( 'Only administrators without Team Control limits can change these settings.', 'mainwp' ); ?></p><?php endif; ?>
                </div>
                <form method="post" action="<?php echo esc_url( $mainwp_rmcp_url( 'tools' ) ); ?>" class="ui form" id="mainwp-rmcp-tools-form">
                    <?php
                    if ( $view['full'] ) :
                        ?>
                        <?php $mainwp_rmcp_fields( 'save_tools', '' ); ?><?php endif; ?>
                    <div class="ui styled fluid accordion" id="mainwp-rmcp-tool-categories">
                        <?php foreach ( $view['tools']['available'] as $mainwp_rmcp_category => $mainwp_rmcp_rows ) : ?>
                            <?php
                            $mainwp_rmcp_label       = OAuth_Consent_Page::category_label( (string) $mainwp_rmcp_category );
                            $mainwp_rmcp_category_on = Remote_MCP_Settings::category_enabled( (string) $mainwp_rmcp_category );
                            $mainwp_rmcp_count       = count( $mainwp_rmcp_rows );
                            $mainwp_rmcp_off         = 0;
                            foreach ( $mainwp_rmcp_rows as $mainwp_rmcp_tool ) {
                                $mainwp_rmcp_off += Remote_MCP_Settings::tool_enabled( $mainwp_rmcp_tool['tool_name'] ) ? 0 : 1;
                            }
                            if ( ! $mainwp_rmcp_category_on ) {
                                $mainwp_rmcp_state = sprintf( _n( '%d tool, off', '%d tools, all off', $mainwp_rmcp_count, 'mainwp' ), $mainwp_rmcp_count );
                            } elseif ( 0 === $mainwp_rmcp_off ) {
                                $mainwp_rmcp_state = sprintf( _n( '%d tool, on', '%d tools, all on', $mainwp_rmcp_count, 'mainwp' ), $mainwp_rmcp_count );
                            } else {
                                $mainwp_rmcp_state = sprintf( _n( '%1$d tool, %2$d off', '%1$d tools, %2$d off', $mainwp_rmcp_count, 'mainwp' ), $mainwp_rmcp_count, $mainwp_rmcp_off );
                            }
                            $mainwp_rmcp_category_name = 'categories[' . $mainwp_rmcp_category . ']';
                            ?>
                            <div class="title"><i class="dropdown icon"></i><?php echo esc_html( $mainwp_rmcp_label ); ?><small class="ui grey text mainwp-rmcp-category-summary"><?php echo esc_html( $mainwp_rmcp_state ); ?></small></div>
                            <div class="content">
                                <div class="ui grid field">
                                    <label class="six wide column middle aligned" for="<?php echo esc_attr( 'mainwp-rmcp-option-' . md5( $mainwp_rmcp_category_name ) ); ?>"><?php esc_html_e( 'Enable this category', 'mainwp' ); ?></label>
                                    <div class="ten wide column"><?php $mainwp_rmcp_bulk_switch( $mainwp_rmcp_category_name, $mainwp_rmcp_category_on, $mainwp_rmcp_label ); ?></div>
                                </div>
                                <div class="ui divider"></div>
                                <?php $mainwp_rmcp_tool_rows( $mainwp_rmcp_rows ); ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ( ! empty( $view['tools']['not_reviewed'] ) ) : ?>
                            <div class="title"><i class="dropdown icon"></i><?php esc_html_e( 'Not reviewed by MainWP', 'mainwp' ); ?></div>
                            <div class="content">
                                <div class="ui message"><?php esc_html_e( 'These add-on tools make changes MainWP hasn\'t reviewed. Each use waits for your approval here (or a confirmation in the chat under Hands-off). Work they have already queued keeps running after you revoke a connection.', 'mainwp' ); ?></div>
                                <div class="ui grid field">
                                    <label class="six wide column middle aligned" for="<?php echo esc_attr( 'mainwp-rmcp-option-' . md5( 'unaudited' ) ); ?>"><?php esc_html_e( 'Allow unreviewed tools', 'mainwp' ); ?></label>
                                    <div class="ten wide column"><?php $mainwp_rmcp_bulk_switch( 'unaudited', $view['unaudited'], __( 'Not reviewed by MainWP', 'mainwp' ) ); ?></div>
                                </div>
                                <div class="ui divider"></div>
                                <?php $mainwp_rmcp_tool_rows( $view['tools']['not_reviewed'] ); ?>
                            </div>
                        <?php endif; ?>
                        <?php
                        $mainwp_rmcp_unavailable = array(
                            'not_available_yet' => __( 'Not available yet', 'mainwp' ),
                            'not_available'     => __( 'Never available to AI apps', 'mainwp' ),
                        );
                        foreach ( $mainwp_rmcp_unavailable as $mainwp_rmcp_key => $mainwp_rmcp_heading ) :
                            if ( empty( $view['tools'][ $mainwp_rmcp_key ] ) ) {
                                continue;
                            }
                            ?>
                            <div class="title"><i class="dropdown icon"></i><?php echo esc_html( $mainwp_rmcp_heading ); ?></div>
                            <div class="content">
                                <?php foreach ( $view['tools'][ $mainwp_rmcp_key ] as $mainwp_rmcp_tool ) : ?>
                                    <div class="ui grid field">
                                        <label class="six wide column middle aligned"><?php echo esc_html( $mainwp_rmcp_tool['label'] ); ?></label>
                                        <div class="ten wide column"><code><?php echo esc_html( $mainwp_rmcp_tool['tool_name'] ); ?></code></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( $view['full'] ) : ?>
                        <input type="hidden" name="tools_settings_complete" value="1">
                        <div class="ui divider"></div>
                        <button type="submit" class="ui green button"><?php esc_html_e( 'Save Settings', 'mainwp' ); ?></button>
                    <?php endif; ?>
                </form>
            </div>

            <div class="<?php echo esc_attr( $mainwp_rmcp_content( 'checks' ) ); ?>" id="checks" data-tab="mainwp-rmcp-checks" role="tabpanel" aria-labelledby="mainwp-rmcp-tab-checks" tabindex="0">
                <div class="ui message"><?php echo esc_html( $mainwp_rmcp_checks_summary ); ?></div>
                <?php
                $mainwp_rmcp_form( 'self_test', '', __( 'Run the self-test', 'mainwp' ), 'basic', array(), false, 'checks' );
                $mainwp_rmcp_form( 'external_check', '', __( 'Check from the internet', 'mainwp' ), 'basic', array(), false, 'checks' );
                ?>
                <p><small><?php esc_html_e( 'The internet check sends only this Dashboard\'s address to MainWP\'s checker. Nothing is stored.', 'mainwp' ); ?></small></p>
                <?php if ( ! empty( $mainwp_rmcp_result['lines'] ) ) : ?>
                    <h4 class="ui header">
                        <?php
                        /* translators: %s: time. */
                        echo esc_html( sprintf( __( 'Checks run at %s', 'mainwp' ), wp_date( get_option( 'time_format' ) ) ) );
                        ?>
                    </h4>
                    <?php $mainwp_rmcp_lines( $mainwp_rmcp_result['lines'] ); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ( 'tokens' === $mainwp_rmcp_secret_in && ! empty( $mainwp_rmcp_result['secret'] ) ) : ?>
        <div class="ui large modal" id="mainwp-rmcp-token-modal" data-return-url="<?php echo esc_url( $view['panel_url'] . '#tokens' ); ?>" role="dialog" aria-modal="true" aria-labelledby="mainwp-rmcp-token-modal-title">
            <i class="close icon"></i>
            <div class="header" id="mainwp-rmcp-token-modal-title"><?php echo esc_html( $mainwp_rmcp_result['secret_label'] ?? __( 'Connection token', 'mainwp' ) ); ?></div>
            <div class="scrolling content">
                <div class="ui message"><?php esc_html_e( 'Copy the token and settings now. They will not be shown again after you close this window.', 'mainwp' ); ?></div>
                <?php $mainwp_rmcp_secret( 'tokens', false ); ?>
            </div>
            <div class="actions"><button type="button" class="ui green cancel button"><?php esc_html_e( 'Done', 'mainwp' ); ?></button></div>
        </div>
    <?php endif; ?>
    <?php
    $mainwp_rmcp_confirms = array();
    if ( $view['full'] && $mainwp_rmcp_on ) {
        $mainwp_rmcp_confirms['toggle-off'] = array( __( 'Turn off remote AI connections?', 'mainwp' ), __( 'This ends every AI connection on this Dashboard. Apps will have to be approved again.', 'mainwp' ), __( 'Turn off', 'mainwp' ) );
    }
    if ( ! empty( $view['connections'] ) ) {
        $mainwp_rmcp_confirms['revoke']          = array( __( 'Revoke this connection?', 'mainwp' ), __( 'This ends access for this connection. The app will have to connect and be approved again.', 'mainwp' ), __( 'Revoke', 'mainwp' ) );
        $mainwp_rmcp_confirms['narrow_scope']    = array( __( 'Change this connection to view only?', 'mainwp' ), __( 'This permanently removes permission to make changes from this connection. You cannot switch it back to View and change. To restore that access, create or approve a new connection.', 'mainwp' ), __( 'Change to View only', 'mainwp' ) );
        $mainwp_rmcp_confirms['disconnect-mine'] = array( __( 'End your connections?', 'mainwp' ), __( 'This ends every AI app connection you made on this Dashboard. Apps will have to be approved again.', 'mainwp' ), __( 'End my connections', 'mainwp' ) );
        if ( $view['full'] ) {
            $mainwp_rmcp_confirms['disconnect-all'] = array( __( 'End everyone\'s connections?', 'mainwp' ), __( 'This ends every AI connection on this Dashboard, for every user.', 'mainwp' ), __( 'End every connection', 'mainwp' ) );
        }
    }
    foreach ( $mainwp_rmcp_confirms as $mainwp_rmcp_key => list( $mainwp_rmcp_title, $mainwp_rmcp_text, $mainwp_rmcp_button ) ) :
        ?>
        <div class="ui mini modal mainwp-rmcp-confirm" id="mainwp-rmcp-confirm-<?php echo esc_attr( $mainwp_rmcp_key ); ?>">
            <div class="header"><?php echo esc_html( $mainwp_rmcp_title ); ?></div>
            <div class="content"><p><?php echo esc_html( $mainwp_rmcp_text ); ?></p></div>
            <div class="actions">
                <button type="button" class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                <button type="button" class="ui red approve button"><?php echo esc_html( $mainwp_rmcp_button ); ?></button>
            </div>
        </div>
    <?php endforeach; ?>
</div>
