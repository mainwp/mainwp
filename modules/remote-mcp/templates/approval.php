<?php
/**
 * Remote MCP approval page template. Standalone: no theme, no wp_head(), only the module's own
 * stylesheet. Every value is escaped, and model-supplied values only appear as labeled argument
 * values, never as headings or markup. Once the row is decided, the caution goes, and so do the
 * arguments and the preview unless the page still offers to run the action: the outcome is what
 * the user needs then.
 *
 * @package MainWP\Dashboard
 *
 * @var array $page `view` (page_view() result or null), `notice`, `action_url`, `css_url`, `site`, `nonces`.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$mainwp_rmcp_view    = $page['view'];
$mainwp_rmcp_summary = is_array( $mainwp_rmcp_view ) ? (array) $mainwp_rmcp_view['summary'] : array();
$mainwp_rmcp_app     = is_array( $mainwp_rmcp_view ) && '' !== (string) $mainwp_rmcp_view['app_label'] ? (string) $mainwp_rmcp_view['app_label'] : __( 'your AI app', 'mainwp' );
$mainwp_rmcp_open    = is_array( $mainwp_rmcp_view ) && $mainwp_rmcp_view['can_decide'];
// "Run it from the Dashboard instead" runs what the details describe, so they stay while it is offered.
$mainwp_rmcp_details = $mainwp_rmcp_open || ( is_array( $mainwp_rmcp_view ) && $mainwp_rmcp_view['can_run'] );
$mainwp_rmcp_title   = is_array( $mainwp_rmcp_view )
    /* translators: %s: AI app name. */
    ? sprintf( __( '%s wants to run an action', 'mainwp' ), $mainwp_rmcp_app )
    : __( 'Approve an action from an AI app', 'mainwp' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>
<?php
echo esc_html(
    is_array( $mainwp_rmcp_view )
        /* translators: %s: AI app name. */
        ? sprintf( __( 'Approve an action from %s', 'mainwp' ), $mainwp_rmcp_app )
        : __( 'Approve an action from an AI app', 'mainwp' )
);
?>
</title>
<link rel="stylesheet" href="<?php echo esc_url( $page['css_url'] ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page with no wp_head(), so nothing enqueued would print, and no other stylesheet may load here. ?>
</head>
<body class="mainwp-oauth" data-page="approval">
<main class="mainwp-oauth-card mainwp-approval">
<p class="mainwp-oauth-eyebrow">
<?php
/* translators: %s: this Dashboard's host */
echo esc_html( sprintf( __( 'MainWP Dashboard · %s', 'mainwp' ), $page['site'] ) );
?>
</p>
<h1><?php echo esc_html( $mainwp_rmcp_title ); ?></h1>
<?php if ( is_array( $mainwp_rmcp_view ) ) : ?>
<p class="mainwp-approval-app">
    <?php
    echo esc_html(
        sprintf(
            /* translators: 1: date the connection was made, 2: user's display name. */
            __( 'Connected on %1$s as %2$s.', 'mainwp' ),
            $mainwp_rmcp_view['connected_at'] > 0 ? wp_date( get_option( 'date_format' ), $mainwp_rmcp_view['connected_at'] ) : __( 'an unknown date', 'mainwp' ),
            wp_get_current_user()->display_name
        )
    );
    ?>
</p>
<?php endif; ?>
<?php if ( null !== $page['notice'] ) : ?>
<p class="mainwp-approval-notice"><?php echo esc_html( $page['notice'] ); ?></p>
<?php endif; ?>
<?php if ( is_array( $mainwp_rmcp_view ) ) : ?>
<h2><?php echo esc_html( $mainwp_rmcp_view['action_label'] ); ?></h2>
    <?php if ( ! empty( $mainwp_rmcp_summary['heading'] ) ) : ?>
<p class="mainwp-approval-warning"><?php echo esc_html( $mainwp_rmcp_summary['heading'] ); ?></p>
    <?php endif; ?>
    <?php if ( ! empty( $mainwp_rmcp_summary['sites'] ) ) : ?>
<h3>
        <?php
        /* translators: %d: number of sites. */
        echo esc_html( sprintf( __( 'Sites (%d)', 'mainwp' ), max( (int) ( $mainwp_rmcp_summary['site_count'] ?? 0 ), count( (array) $mainwp_rmcp_summary['sites'] ) ) ) );
        ?>
</h3>
<ul class="mainwp-approval-sites">
        <?php foreach ( (array) $mainwp_rmcp_summary['sites'] as $mainwp_rmcp_site ) : ?>
<li><?php echo esc_html( trim( (string) ( $mainwp_rmcp_site['name'] ?? '' ) . ' ' . (string) ( $mainwp_rmcp_site['url'] ?? '' ) ) ); ?></li>
        <?php endforeach; ?>
</ul>
        <?php if ( (int) ( $mainwp_rmcp_summary['site_count'] ?? 0 ) > count( (array) $mainwp_rmcp_summary['sites'] ) ) : ?>
<p>
            <?php
            $mainwp_rmcp_more = (int) $mainwp_rmcp_summary['site_count'] - count( (array) $mainwp_rmcp_summary['sites'] );
            /* translators: %d: number of sites not listed. */
            echo esc_html( sprintf( _n( 'and %d more site', 'and %d more sites', $mainwp_rmcp_more, 'mainwp' ), $mainwp_rmcp_more ) );
            ?>
</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ( $mainwp_rmcp_details && ! empty( $mainwp_rmcp_summary['arguments'] ) ) : ?>
<h3><?php echo esc_html__( 'Details', 'mainwp' ); ?></h3>
<dl class="mainwp-approval-arguments">
        <?php foreach ( (array) $mainwp_rmcp_summary['arguments'] as $mainwp_rmcp_line ) : ?>
<dt><?php echo esc_html( (string) ( $mainwp_rmcp_line['label'] ?? '' ) ); ?></dt>
<dd><?php echo esc_html( (string) ( $mainwp_rmcp_line['value'] ?? '' ) ); ?></dd>
        <?php endforeach; ?>
</dl>
    <?php endif; ?>
    <?php if ( $mainwp_rmcp_details && isset( $mainwp_rmcp_summary['preview'] ) && null !== $mainwp_rmcp_summary['preview'] ) : ?>
<details class="mainwp-approval-details">
<summary><?php echo esc_html__( 'Preview', 'mainwp' ); ?></summary>
<pre class="mainwp-approval-preview"><?php echo esc_html( (string) wp_json_encode( $mainwp_rmcp_summary['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
</details>
    <?php endif; ?>
    <?php if ( $mainwp_rmcp_view['plan_less'] ) : ?>
<p class="mainwp-approval-note">
        <?php
        /* translators: %s: AI app name. */
        echo esc_html( sprintf( __( 'MainWP doesn\'t keep this action\'s details, so it checks them again when %s retries.', 'mainwp' ), $mainwp_rmcp_app ) );
        ?>
</p>
    <?php endif; ?>
    <?php if ( 'consumed' === $mainwp_rmcp_view['status'] && null !== $mainwp_rmcp_view['result_summary'] ) : ?>
<h3><?php echo esc_html__( 'Result', 'mainwp' ); ?></h3>
<pre class="mainwp-approval-preview"><?php echo esc_html( $mainwp_rmcp_view['result_summary'] ); ?></pre>
    <?php endif; ?>
    <?php if ( $mainwp_rmcp_open ) : ?>
<p class="mainwp-approval-caution">
        <?php
        /* translators: %s: AI app name. */
        echo esc_html( sprintf( __( 'Only approve if you just asked %s to do this.', 'mainwp' ), $mainwp_rmcp_app ) );
        ?>
</p>
<div class="mainwp-approval-actions">
<form method="post" action="<?php echo esc_url( $page['action_url'] ); ?>">
<input type="hidden" name="approval" value="<?php echo esc_attr( (string) $mainwp_rmcp_view['approval_id'] ); ?>">
<input type="hidden" name="decision" value="approve">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $page['nonces']['approve'] ); ?>">
<button type="submit" class="mainwp-approval-approve"><?php echo esc_html__( 'Approve', 'mainwp' ); ?></button>
</form>
<form method="post" action="<?php echo esc_url( $page['action_url'] ); ?>">
<input type="hidden" name="approval" value="<?php echo esc_attr( (string) $mainwp_rmcp_view['approval_id'] ); ?>">
<input type="hidden" name="decision" value="deny">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $page['nonces']['deny'] ); ?>">
<button type="submit" class="mainwp-approval-deny"><?php echo esc_html__( 'Deny', 'mainwp' ); ?></button>
</form>
</div>
    <?php else : ?>
        <?php // A decision just made already says how it went in the notice above; the status line would repeat it. ?>
        <?php if ( null === $page['notice'] ) : ?>
            <?php if ( 'approved' === $mainwp_rmcp_view['status'] ) : ?>
<p>
                <?php
                /* translators: %s: AI app name. */
                echo esc_html( sprintf( __( 'You approved this. Go back to %s and ask it to try again.', 'mainwp' ), $mainwp_rmcp_app ) );
                ?>
</p>
            <?php elseif ( 'consumed' !== $mainwp_rmcp_view['status'] ) : ?>
<p>
                <?php
                /* translators: %s: AI app name. */
                echo esc_html( sprintf( __( 'This approval has been used or has expired. Ask %s to start again if you still want this.', 'mainwp' ), $mainwp_rmcp_app ) );
                ?>
</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $mainwp_rmcp_view['can_run'] ) : ?>
<form method="post" action="<?php echo esc_url( $page['action_url'] ); ?>">
<input type="hidden" name="approval" value="<?php echo esc_attr( (string) $mainwp_rmcp_view['approval_id'] ); ?>">
<input type="hidden" name="decision" value="run">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $page['nonces']['run'] ); ?>">
<button type="submit" class="mainwp-approval-run"><?php echo esc_html__( 'Run it from the Dashboard instead', 'mainwp' ); ?></button>
</form>
        <?php endif; ?>
<p><a href="<?php echo esc_url( admin_url() ); ?>"><?php echo esc_html__( 'Back to your Dashboard', 'mainwp' ); ?></a></p>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
