<?php
/**
 * Remote connector panel template, the "Connect directly" view of the MainWP MCP page.
 *
 * Receives $view from Remote_MCP_Admin::view(). Every value is escaped here, including names an
 * app registered for itself.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- template-local variables in a file included inside a method.

$mainwp_rmcp_form = function ( string $action, string $target, string $label, string $class_name = '', array $fields = array(), bool $disabled = false ) use ( $view ) {
    ?>
    <form method="post" action="<?php echo esc_url( $view['panel_url'] ); ?>" class="mainwp-remote-mcp-action" style="display:inline-block;margin:0 .25em .25em 0;">
        <input type="hidden" name="mainwp_remote_mcp_action" value="<?php echo esc_attr( $action ); ?>">
        <input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Remote_MCP_Admin::nonce_action( $action, $target ) ) ); ?>">
        <?php foreach ( $fields as $name => $value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
        <?php endforeach; ?>
        <button type="submit" class="ui mini <?php echo esc_attr( $class_name ); ?> button"<?php echo $disabled ? ' disabled' : ''; ?>><?php echo esc_html( $label ); ?></button>
    </form>
    <?php
};

$mainwp_rmcp_switch = function ( string $action, string $target, bool $on ) use ( $mainwp_rmcp_form, $view ) {
    if ( ! $view['full'] ) {
        echo '<span class="ui mini basic label">' . ( $on ? esc_html__( 'On', 'mainwp' ) : esc_html__( 'Off', 'mainwp' ) ) . '</span>';
        return;
    }
    $mainwp_rmcp_form( $action, $target, $on ? __( 'Turn off', 'mainwp' ) : __( 'Turn on', 'mainwp' ), $on ? 'basic' : 'green', array( 'value' => $on ? 'off' : 'on' ) );
};

$mainwp_rmcp_date = function ( int $time ): string {
    return $time > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : __( 'Never', 'mainwp' );
};

$mainwp_rmcp_lines = function ( array $lines ) {
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
                <td class="collapsing"><span class="ui mini <?php echo esc_attr( $colors[ $line['status'] ] ?? 'grey' ); ?> label"><?php echo esc_html( $line['status'] ); ?></span></td>
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
?>
<div id="mainwp-remote-mcp-panel">
    <?php if ( ! empty( $mainwp_rmcp_result ) && '' !== (string) ( $mainwp_rmcp_result['message'] ?? '' ) ) : ?>
        <div class="ui <?php echo ! empty( $mainwp_rmcp_result['ok'] ) ? 'green' : 'red'; ?> message"><?php echo esc_html( $mainwp_rmcp_result['message'] ); ?></div>
    <?php endif; ?>
    <?php if ( ! empty( $mainwp_rmcp_result['secret'] ) ) : ?>
        <div class="ui segment">
            <h4 class="ui header"><?php echo esc_html( $mainwp_rmcp_result['secret_label'] ?? __( 'Secret', 'mainwp' ) ); ?></h4>
            <?php if ( ! empty( $mainwp_rmcp_result['client_id'] ) ) : ?>
                <p><?php esc_html_e( 'Client ID', 'mainwp' ); ?>: <code><?php echo esc_html( $mainwp_rmcp_result['client_id'] ); ?></code></p>
            <?php endif; ?>
            <?php if ( empty( $mainwp_rmcp_result['snippets'] ) ) : ?>
                <p><code><?php echo esc_html( $mainwp_rmcp_result['secret'] ); ?></code></p>
            <?php else : ?>
                <?php foreach ( $mainwp_rmcp_result['snippets'] as $mainwp_rmcp_name => $mainwp_rmcp_snippet ) : ?>
                    <p><?php echo esc_html( $mainwp_rmcp_name ); ?></p>
                    <pre><?php echo esc_html( $mainwp_rmcp_snippet ); ?></pre>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ( ! empty( $mainwp_rmcp_result['reasons'] ) ) : ?>
        <div class="ui red message"><?php echo esc_html( implode( ', ', $mainwp_rmcp_result['reasons'] ) ); ?></div>
    <?php endif; ?>
    <?php if ( ! empty( $mainwp_rmcp_result['lines'] ) ) : ?>
        <?php $mainwp_rmcp_lines( $mainwp_rmcp_result['lines'] ); ?>
    <?php endif; ?>

    <?php if ( ! $view['requirements']['ok'] ) : ?>
        <div class="ui yellow message"><?php echo esc_html( $view['requirement'] ); ?></div>
</div>
        <?php
        return;
    endif;
    ?>

    <?php if ( $view['home_changed'] ) : ?>
        <div class="ui yellow message">
            <?php esc_html_e( 'Your site address changed. Connected AI apps need to reconnect.', 'mainwp' ); ?>
            <?php $mainwp_rmcp_form( 'dismiss_home_changed', '', __( 'Dismiss', 'mainwp' ), 'basic' ); ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $view['notices'] ) ) : ?>
        <?php
        $mainwp_rmcp_client_events = array(
            /* translators: 1: web app name, 2: date and time. */
            'client_added'           => __( 'Web app added: %1$s, on %2$s.', 'mainwp' ),
            /* translators: 1: web app name, 2: date and time. */
            'client_changed'         => __( 'Web app switched on or off: %1$s, on %2$s.', 'mainwp' ),
            /* translators: 1: web app name, 2: date and time. */
            'client_deleted'         => __( 'Web app deleted: %1$s, on %2$s.', 'mainwp' ),
            /* translators: 1: web app name, 2: date and time. */
            'client_secret_reissued' => __( 'Web app secret reissued: %1$s, on %2$s.', 'mainwp' ),
            /* translators: 1: app name, 2: date and time. */
            'loosened'               => __( 'Approval policy loosened: %1$s, on %2$s.', 'mainwp' ),
        );
        // Notices render for full administrators only, whose connections list holds every user's live grants.
        $mainwp_rmcp_live_ids = array_map( 'intval', array_column( $view['connections'], 'id' ) );
        ?>
        <div class="ui blue message">
            <ul class="list">
                <?php foreach ( $view['notices'] as $mainwp_rmcp_notice ) : ?>
                    <li>
                    <?php
                    if ( isset( $mainwp_rmcp_client_events[ $mainwp_rmcp_notice['why'] ?? '' ] ) ) {
                        printf(
                            esc_html( $mainwp_rmcp_client_events[ $mainwp_rmcp_notice['why'] ] ),
                            esc_html( $mainwp_rmcp_notice['app'] ),
                            esc_html( $mainwp_rmcp_date( (int) $mainwp_rmcp_notice['at'] ) )
                        );
                    } else {
                        printf(
                            /* translators: 1: app name, 2: date and time, 3: IP address. */
                            esc_html__( 'New connection: %1$s on %2$s from %3$s.', 'mainwp' ),
                            esc_html( $mainwp_rmcp_notice['app'] ),
                            esc_html( $mainwp_rmcp_date( (int) $mainwp_rmcp_notice['at'] ) ),
                            esc_html( '' !== (string) $mainwp_rmcp_notice['ip'] ? $mainwp_rmcp_notice['ip'] : __( 'an unknown address', 'mainwp' ) )
                        );
                    }
                    if ( (int) $mainwp_rmcp_notice['grant_id'] > 0 && ! in_array( (int) $mainwp_rmcp_notice['grant_id'], $mainwp_rmcp_live_ids, true ) ) {
                        echo ' ' . esc_html__( '(no longer connected)', 'mainwp' );
                    }
                    ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a href="#connections"><?php esc_html_e( 'Review connections', 'mainwp' ); ?></a>
        </div>
    <?php endif; ?>

    <h3 class="ui dividing header"><?php esc_html_e( 'Remote AI connections', 'mainwp' ); ?></h3>
    <p>
        <?php echo $view['active'] ? esc_html__( 'On. AI apps you approve can connect to this Dashboard over the internet.', 'mainwp' ) : esc_html__( 'Off. No AI app can connect to this Dashboard over the internet.', 'mainwp' ); ?>
    </p>
    <?php if ( '' !== $view['forced'] ) : ?>
        <div class="ui yellow message"><?php echo esc_html( $view['forced'] ); ?></div>
    <?php endif; ?>
    <?php if ( ! $view['secure'] ) : ?>
        <div class="ui yellow message"><?php esc_html_e( 'Your site address starts with http://. Remote AI connections need https://.', 'mainwp' ); ?></div>
    <?php endif; ?>
    <?php
    if ( $view['full'] ) {
        if ( $view['enabled'] && '' === $view['forced'] ) {
            $mainwp_rmcp_form( 'toggle', 'off', __( 'Turn off and end every connection', 'mainwp' ), 'red' );
        } else {
            $mainwp_rmcp_form( 'toggle', 'on', __( 'Turn on', 'mainwp' ), 'green', array(), '' !== $view['forced'] );
        }
    }
    $mainwp_rmcp_form( 'self_test', '', __( 'Run the self-test', 'mainwp' ), 'basic' );
    $mainwp_rmcp_form( 'external_check', '', __( 'Check from the internet', 'mainwp' ), 'basic' );
    ?>
    <p><small><?php esc_html_e( 'The check from the internet sends only this Dashboard\'s address to MainWP\'s checker, and nothing is stored.', 'mainwp' ); ?></small></p>

    <?php foreach ( $view['help'] as $mainwp_rmcp_help ) : ?>
        <div class="ui yellow message">
            <?php
            /* translators: %s: app name. */
            echo esc_html( sprintf( __( '%s hasn\'t reached this Dashboard yet.', 'mainwp' ), $mainwp_rmcp_help['label'] ) );
            ?>
            <a href="<?php echo esc_url( $mainwp_rmcp_help['help_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What to check on your host', 'mainwp' ); ?></a>
        </div>
    <?php endforeach; ?>

    <?php if ( $view['invalidation_failed'] ) : ?>
        <div class="ui red message"><?php esc_html_e( 'Could not end every affected AI connection. MainWP will retry on the next request.', 'mainwp' ); ?></div>
    <?php endif; ?>

    <h3 class="ui dividing header" id="connections"><?php esc_html_e( 'Connections', 'mainwp' ); ?></h3>
    <?php if ( empty( $view['connections'] ) ) : ?>
        <p><?php esc_html_e( 'No AI app is connected.', 'mainwp' ); ?></p>
    <?php else : ?>
        <table class="ui stackable table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'App', 'mainwp' ); ?></th>
                    <th><?php esc_html_e( 'User', 'mainwp' ); ?></th>
                    <th><?php esc_html_e( 'Access', 'mainwp' ); ?></th>
                    <th><?php esc_html_e( 'Approval policy', 'mainwp' ); ?></th>
                    <th><?php esc_html_e( 'Connected', 'mainwp' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $view['connections'] as $mainwp_rmcp_row ) : ?>
                <tr data-grant="<?php echo esc_attr( $mainwp_rmcp_row['id'] ); ?>"<?php echo $view['requested_revoke'] === $mainwp_rmcp_row['id'] ? ' class="warning"' : ''; ?>>
                    <td><?php echo esc_html( $mainwp_rmcp_row['label'] ); ?></td>
                    <td><?php echo esc_html( $mainwp_rmcp_row['user'] ); ?></td>
                    <td><?php echo $mainwp_rmcp_row['can_write'] ? esc_html__( 'View and make changes', 'mainwp' ) : esc_html__( 'View only', 'mainwp' ); ?></td>
                    <td><?php echo esc_html( Remote_MCP_Admin::policy_label( $mainwp_rmcp_row['policy'] ) ); ?></td>
                    <td><?php echo esc_html( $mainwp_rmcp_date( $mainwp_rmcp_row['created_at'] ) ); ?></td>
                    <td>
                        <?php
                        /* translators: %s: date and time. */
                        echo esc_html( sprintf( __( 'Last request: %s', 'mainwp' ), $mainwp_rmcp_date( $mainwp_rmcp_row['last_used'] ) ) );
                        ?>
                        <?php if ( '' !== $mainwp_rmcp_row['last_ip'] ) : ?>
                            <br><?php echo esc_html( $mainwp_rmcp_row['last_ip'] ); ?>
                        <?php endif; ?>
                        <?php if ( '' !== $mainwp_rmcp_row['failure'] ) : ?>
                            <br>
                            <?php
                            /* translators: 1: reason code, 2: date and time. */
                            echo esc_html( sprintf( __( 'Last failure: %1$s, %2$s', 'mainwp' ), $mainwp_rmcp_row['failure'], $mainwp_rmcp_date( $mainwp_rmcp_row['failure_at'] ) ) );
                            ?>
                        <?php endif; ?>
                    </td>
                    <td class="right aligned">
                        <?php if ( $mainwp_rmcp_row['own'] ) : ?>
                            <?php if ( $mainwp_rmcp_row['can_write'] ) : ?>
                                <?php $mainwp_rmcp_form( 'narrow_scope', (string) $mainwp_rmcp_row['id'], __( 'Change to view only', 'mainwp' ), 'basic' ); ?>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url( $view['panel_url'] ); ?>" class="ui mini form" style="display:inline-block;">
                                <input type="hidden" name="mainwp_remote_mcp_action" value="set_policy">
                                <input type="hidden" name="target" value="<?php echo esc_attr( $mainwp_rmcp_row['id'] ); ?>">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Remote_MCP_Admin::nonce_action( 'set_policy', (string) $mainwp_rmcp_row['id'] ) ) ); ?>">
                                <select name="policy" class="ui mini dropdown">
                                    <?php foreach ( $mainwp_rmcp_row['hands_off'] ? OAuth_Store::POLICIES : array( OAuth_Store::POLICY_STANDARD, OAuth_Store::POLICY_STRICT ) as $mainwp_rmcp_policy ) : ?>
                                        <option value="<?php echo esc_attr( $mainwp_rmcp_policy ); ?>"<?php selected( $mainwp_rmcp_policy, $mainwp_rmcp_row['policy'] ); ?>><?php echo esc_html( Remote_MCP_Admin::policy_label( $mainwp_rmcp_policy ) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="ui mini basic button"><?php esc_html_e( 'Save policy', 'mainwp' ); ?></button>
                            </form>
                        <?php endif; ?>
                        <?php $mainwp_rmcp_form( 'revoke', (string) $mainwp_rmcp_row['id'], __( 'Revoke', 'mainwp' ), 'red' ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ( ! empty( $view['connections'] ) ) : ?>
        <?php
        $mainwp_rmcp_form( 'disconnect_mine', '', __( 'Disconnect all my AI apps', 'mainwp' ), 'red' );
        if ( $view['full'] ) {
            $mainwp_rmcp_form( 'disconnect_all', '', __( 'Disconnect all AI apps', 'mainwp' ), 'red' );
        }
        ?>
        <p><small><?php esc_html_e( 'Hands-off lets the app make changes that would otherwise need your click on the Dashboard, with only a confirmation in the chat. A prompt hidden in site content could then make those changes too.', 'mainwp' ); ?></small></p>
    <?php endif; ?>

    <h3 class="ui dividing header"><?php esc_html_e( 'Pending approvals', 'mainwp' ); ?></h3>
    <?php if ( empty( $view['pending'] ) ) : ?>
        <p><?php esc_html_e( 'Nothing is waiting for your approval.', 'mainwp' ); ?></p>
    <?php else : ?>
        <div class="ui middle aligned divided list">
            <?php foreach ( $view['pending'] as $mainwp_rmcp_pending ) : ?>
                <div class="item">
                    <a href="<?php echo esc_url( $mainwp_rmcp_pending['url'] ); ?>"><?php echo esc_html( '' !== $mainwp_rmcp_pending['summary'] ? $mainwp_rmcp_pending['summary'] : $mainwp_rmcp_pending['ability'] ); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 class="ui dividing header"><?php esc_html_e( 'Connect with a token', 'mainwp' ); ?></h3>
    <p><?php esc_html_e( 'For apps that can send a header but can\'t sign in with OAuth. The token is shown once.', 'mainwp' ); ?></p>
    <form method="post" action="<?php echo esc_url( $view['panel_url'] ); ?>" class="ui mini form">
        <input type="hidden" name="mainwp_remote_mcp_action" value="mint_token">
        <input type="hidden" name="target" value="">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Remote_MCP_Admin::nonce_action( 'mint_token', '' ) ) ); ?>">
        <div class="fields">
            <div class="field"><label for="mainwp-rmcp-token-label"><?php esc_html_e( 'Name', 'mainwp' ); ?></label><input type="text" id="mainwp-rmcp-token-label" name="label" maxlength="64" required></div>
            <div class="field"><label for="mainwp-rmcp-token-access"><?php esc_html_e( 'Access', 'mainwp' ); ?></label>
                <select id="mainwp-rmcp-token-access" name="access" class="ui mini dropdown">
                    <option value="read" selected><?php esc_html_e( 'View only', 'mainwp' ); ?></option>
                    <option value="write"><?php esc_html_e( 'View and make changes', 'mainwp' ); ?></option>
                </select></div>
            <div class="field"><label for="mainwp-rmcp-token-policy"><?php esc_html_e( 'Approval policy', 'mainwp' ); ?></label>
                <select id="mainwp-rmcp-token-policy" name="policy" class="ui mini dropdown">
                    <?php foreach ( $view['policies'] as $mainwp_rmcp_policy ) : ?>
                        <option value="<?php echo esc_attr( $mainwp_rmcp_policy ); ?>"<?php selected( $mainwp_rmcp_policy, OAuth_Store::POLICY_STANDARD ); ?>><?php echo esc_html( Remote_MCP_Admin::policy_label( $mainwp_rmcp_policy ) ); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label for="mainwp-rmcp-token-expiry"><?php esc_html_e( 'Expires after', 'mainwp' ); ?></label>
                <select id="mainwp-rmcp-token-expiry" name="expiry" class="ui mini dropdown">
                <?php
                foreach ( $view['token_days'] as $mainwp_rmcp_days ) :
                    ?>
                    <option value="<?php echo esc_attr( $mainwp_rmcp_days ); ?>"<?php selected( $mainwp_rmcp_days, $view['token_default'] ); ?>>
                        <?php
                        /* translators: %s: number of days. */
                        echo esc_html( sprintf( __( '%s days', 'mainwp' ), $mainwp_rmcp_days ) );
                        ?>
                    </option><?php endforeach; ?></select></div>
        </div>
        <button type="submit" class="ui mini green button"<?php echo $view['token_count'] >= OAuth_Store::LIVE_GRANT_CAP ? ' disabled' : ''; ?>><?php esc_html_e( 'Create token', 'mainwp' ); ?></button>
        <?php if ( $view['token_count'] >= OAuth_Store::LIVE_GRANT_CAP ) : ?>
            <span>
            <?php
            /* translators: %d: most connection tokens one user can have. */
            echo esc_html( sprintf( __( 'You already have %d connection tokens. Revoke one first.', 'mainwp' ), OAuth_Store::LIVE_GRANT_CAP ) );
            ?>
            </span>
        <?php endif; ?>
    </form>

    <h3 class="ui dividing header"><?php esc_html_e( 'Connect an app', 'mainwp' ); ?></h3>
    <p><?php esc_html_e( 'Use these settings in your app. For a connection token, create one above and copy its settings when they appear.', 'mainwp' ); ?></p>
    <?php foreach ( $view['app_snippets'] as $mainwp_rmcp_app => $mainwp_rmcp_config ) : ?>
        <h4 class="ui header"><?php echo esc_html( $mainwp_rmcp_app ); ?></h4>
        <pre><?php echo esc_html( $mainwp_rmcp_config['connect'] ); ?></pre>
    <?php endforeach; ?>

    <h3 class="ui dividing header"><?php esc_html_e( 'Which apps can connect', 'mainwp' ); ?></h3>
    <table class="ui table">
        <tbody>
            <tr>
                <td><?php esc_html_e( 'Let apps on your own computer connect', 'mainwp' ); ?></td>
                <td class="right aligned"><?php $mainwp_rmcp_switch( 'registration_switch', '', $view['registration'] ); ?></td>
            </tr>
            <?php foreach ( $view['static_clients'] as $mainwp_rmcp_static ) : ?>
                <tr>
                    <td><?php echo esc_html( $mainwp_rmcp_static['label'] ); ?> (<code><?php echo esc_html( $mainwp_rmcp_static['client_id'] ); ?></code>)</td>
                    <td class="right aligned"><?php $mainwp_rmcp_switch( 'static_client_switch', $mainwp_rmcp_static['client_id'], $mainwp_rmcp_static['enabled'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3 class="ui dividing header"><?php esc_html_e( 'Apps on this computer', 'mainwp' ); ?></h3>
    <?php if ( empty( $view['registered'] ) ) : ?>
        <p><?php esc_html_e( 'No app has registered.', 'mainwp' ); ?></p>
    <?php else : ?>
        <table class="ui table">
            <tbody>
            <?php foreach ( $view['registered'] as $mainwp_rmcp_client ) : ?>
                <tr>
                    <td>
                        <?php echo esc_html( '' !== (string) $mainwp_rmcp_client['client_name'] ? $mainwp_rmcp_client['client_name'] : __( 'An app on this computer', 'mainwp' ) ); ?>
                        <span class="ui mini label"><?php esc_html_e( 'name not checked', 'mainwp' ); ?></span>
                    </td>
                    <td><?php echo esc_html( $mainwp_rmcp_date( (int) $mainwp_rmcp_client['created_at'] ) ); ?></td>
                    <td><?php echo esc_html( (string) $mainwp_rmcp_client['created_ip'] ); ?></td>
                    <td>
                        <?php
                        /* translators: %s: date and time. */
                        echo esc_html( sprintf( __( 'Last use: %s', 'mainwp' ), $mainwp_rmcp_date( (int) $mainwp_rmcp_client['last_used_at'] ) ) );
                        ?>
                    </td>
                    <td class="right aligned">
                        <?php
                        if ( $view['full'] ) {
                            $mainwp_rmcp_form( 'registration_remove', (string) $mainwp_rmcp_client['client_id'], __( 'Remove', 'mainwp' ), 'red' );
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ( $view['full'] ) : ?>
        <h3 class="ui dividing header"><?php esc_html_e( 'Web apps', 'mainwp' ); ?></h3>
        <?php if ( ! empty( $view['webapps'] ) ) : ?>
            <table class="ui table">
                <tbody>
                <?php foreach ( $view['webapps'] as $mainwp_rmcp_client ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $mainwp_rmcp_client['client_name'] ); ?></td>
                        <td><code><?php echo esc_html( (string) $mainwp_rmcp_client['client_id'] ); ?></code></td>
                        <td><?php echo esc_html( implode( ', ', (array) $mainwp_rmcp_client['redirect_uris'] ) ); ?></td>
                        <td class="right aligned">
                            <?php
                            $mainwp_rmcp_switch( 'webapp_switch', (string) $mainwp_rmcp_client['client_id'], 'active' === $mainwp_rmcp_client['status'] );
                            $mainwp_rmcp_form( 'webapp_reissue', (string) $mainwp_rmcp_client['client_id'], __( 'Reissue secret', 'mainwp' ), 'basic' );
                            $mainwp_rmcp_form( 'webapp_delete', (string) $mainwp_rmcp_client['client_id'], __( 'Delete', 'mainwp' ), 'red' );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( $view['panel_url'] ); ?>" class="ui mini form">
            <input type="hidden" name="mainwp_remote_mcp_action" value="webapp_add">
            <input type="hidden" name="target" value="">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Remote_MCP_Admin::nonce_action( 'webapp_add', '' ) ) ); ?>">
            <div class="fields">
                <div class="field"><label for="mainwp-rmcp-webapp-name"><?php esc_html_e( 'App name', 'mainwp' ); ?></label><input type="text" id="mainwp-rmcp-webapp-name" name="name" maxlength="64" required></div>
                <div class="field"><label for="mainwp-rmcp-webapp-redirect-uris"><?php esc_html_e( 'Redirect URIs, one per line (up to 3)', 'mainwp' ); ?></label><textarea id="mainwp-rmcp-webapp-redirect-uris" name="redirect_uris" rows="3" required></textarea></div>
            </div>
            <button type="submit" class="ui mini green button"><?php esc_html_e( 'Add web app', 'mainwp' ); ?></button>
        </form>
    <?php endif; ?>

    <h3 class="ui dividing header"><?php esc_html_e( 'Tools', 'mainwp' ); ?></h3>
    <?php foreach ( $view['tools']['available'] as $mainwp_rmcp_category => $mainwp_rmcp_rows ) : ?>
        <h4 class="ui header">
            <?php echo esc_html( $mainwp_rmcp_category ); ?>
            <?php $mainwp_rmcp_switch( 'category_switch', (string) $mainwp_rmcp_category, Remote_MCP_Settings::category_enabled( (string) $mainwp_rmcp_category ) ); ?>
        </h4>
        <table class="ui table">
            <tbody>
            <?php foreach ( $mainwp_rmcp_rows as $mainwp_rmcp_tool ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $mainwp_rmcp_tool['tool_name'] ); ?></code></td>
                    <td class="right aligned"><?php $mainwp_rmcp_switch( 'tool_switch', $mainwp_rmcp_tool['tool_name'], Remote_MCP_Settings::tool_enabled( $mainwp_rmcp_tool['tool_name'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <?php if ( ! empty( $view['tools']['not_reviewed'] ) ) : ?>
        <h4 class="ui header">
            <?php esc_html_e( 'Not reviewed by MainWP', 'mainwp' ); ?>
            <?php $mainwp_rmcp_switch( 'unaudited_switch', '', $view['unaudited'] ); ?>
        </h4>
        <p><?php esc_html_e( 'These extension tools make changes MainWP hasn\'t reviewed, so each call needs your approval on the Dashboard, or a confirmation in the chat under Hands-off. Work they queue isn\'t cancelled when a connection is revoked.', 'mainwp' ); ?></p>
        <table class="ui table">
            <tbody>
            <?php foreach ( $view['tools']['not_reviewed'] as $mainwp_rmcp_tool ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $mainwp_rmcp_tool['tool_name'] ); ?></code></td>
                    <td class="right aligned"><?php $mainwp_rmcp_switch( 'tool_switch', $mainwp_rmcp_tool['tool_name'], Remote_MCP_Settings::tool_enabled( $mainwp_rmcp_tool['tool_name'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    $mainwp_rmcp_unavailable = array(
        'not_available_yet' => __( 'Not available yet', 'mainwp' ),
        'not_available'     => __( 'Not available to AI apps', 'mainwp' ),
    );
    foreach ( $mainwp_rmcp_unavailable as $mainwp_rmcp_key => $mainwp_rmcp_title ) :
        if ( empty( $view['tools'][ $mainwp_rmcp_key ] ) ) {
            continue;
        }
        ?>
        <h4 class="ui header"><?php echo esc_html( $mainwp_rmcp_title ); ?></h4>
        <div class="ui list">
            <?php foreach ( $view['tools'][ $mainwp_rmcp_key ] as $mainwp_rmcp_tool ) : ?>
                <div class="item"><code><?php echo esc_html( $mainwp_rmcp_tool['tool_name'] ); ?></code></div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
