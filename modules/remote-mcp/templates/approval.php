<?php
/**
 * Remote MCP approval page template. Standalone: no theme, no wp_head(), only the module's own
 * stylesheet. Every value is escaped, and model-supplied values only appear as labeled argument
 * values, never as headings or markup.
 *
 * @package MainWP\Dashboard
 *
 * @var array $page `view` (page_view() result or null), `notice`, `action_url`, `css_url`, `nonces`.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$mainwp_rmcp_view    = $page['view'];
$mainwp_rmcp_summary = is_array( $mainwp_rmcp_view ) ? (array) $mainwp_rmcp_view['summary'] : array();
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html__( 'Approve an action from an AI app', 'mainwp' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $page['css_url'] ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page with no wp_head(), so nothing enqueued would print, and no other stylesheet may load here. ?>
</head>
<body>
<main class="mainwp-approval">
<h1><?php echo esc_html__( 'Approve an action from an AI app', 'mainwp' ); ?></h1>
<?php if ( null !== $page['notice'] ) : ?>
<p class="mainwp-approval-notice"><?php echo esc_html( $page['notice'] ); ?></p>
<?php endif; ?>
<?php if ( is_array( $mainwp_rmcp_view ) ) : ?>
<p class="mainwp-approval-app">
    <?php
    echo esc_html(
        sprintf(
            /* translators: 1: AI app name, 2: date the connection was made. */
            __( '%1$s, connected on %2$s', 'mainwp' ),
            $mainwp_rmcp_view['app_label'],
            $mainwp_rmcp_view['connected_at'] > 0 ? wp_date( get_option( 'date_format' ), $mainwp_rmcp_view['connected_at'] ) : __( 'an unknown date', 'mainwp' )
        )
    );
    ?>
</p>
<h2><?php echo esc_html( $mainwp_rmcp_view['action_label'] ); ?></h2>
    <?php if ( ! empty( $mainwp_rmcp_summary['heading'] ) ) : ?>
<p class="mainwp-approval-warning"><?php echo esc_html( $mainwp_rmcp_summary['heading'] ); ?></p>
    <?php endif; ?>
<p class="mainwp-approval-caution"><?php echo esc_html__( 'Only approve if you just asked your AI app to do this.', 'mainwp' ); ?></p>
    <?php if ( ! empty( $mainwp_rmcp_summary['arguments'] ) ) : ?>
<dl class="mainwp-approval-arguments">
        <?php foreach ( (array) $mainwp_rmcp_summary['arguments'] as $mainwp_rmcp_line ) : ?>
<dt><?php echo esc_html( (string) ( $mainwp_rmcp_line['label'] ?? '' ) ); ?></dt>
<dd><?php echo esc_html( (string) ( $mainwp_rmcp_line['value'] ?? '' ) ); ?></dd>
        <?php endforeach; ?>
</dl>
    <?php endif; ?>
    <?php if ( ! empty( $mainwp_rmcp_summary['sites'] ) ) : ?>
<h3><?php echo esc_html__( 'Sites', 'mainwp' ); ?></h3>
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
    <?php if ( isset( $mainwp_rmcp_summary['preview'] ) && null !== $mainwp_rmcp_summary['preview'] ) : ?>
<h3><?php echo esc_html__( 'Preview', 'mainwp' ); ?></h3>
<pre class="mainwp-approval-preview"><?php echo esc_html( (string) wp_json_encode( $mainwp_rmcp_summary['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
    <?php endif; ?>
    <?php if ( $mainwp_rmcp_view['plan_less'] ) : ?>
<p class="mainwp-approval-note"><?php echo esc_html__( 'The Dashboard doesn\'t store this action\'s details, so its check runs when your AI app tries again.', 'mainwp' ); ?></p>
    <?php endif; ?>
    <?php if ( 'consumed' === $mainwp_rmcp_view['status'] && null !== $mainwp_rmcp_view['result_summary'] ) : ?>
<h3><?php echo esc_html__( 'Result', 'mainwp' ); ?></h3>
<pre class="mainwp-approval-preview"><?php echo esc_html( $mainwp_rmcp_view['result_summary'] ); ?></pre>
    <?php endif; ?>
    <?php if ( $mainwp_rmcp_view['can_decide'] ) : ?>
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
    <?php elseif ( 'approved' === $mainwp_rmcp_view['status'] ) : ?>
<p><?php echo esc_html__( 'You approved this. Go back to your AI app and ask it to try again.', 'mainwp' ); ?></p>
        <?php if ( $mainwp_rmcp_view['can_run'] ) : ?>
<form method="post" action="<?php echo esc_url( $page['action_url'] ); ?>">
<input type="hidden" name="approval" value="<?php echo esc_attr( (string) $mainwp_rmcp_view['approval_id'] ); ?>">
<input type="hidden" name="decision" value="run">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $page['nonces']['run'] ); ?>">
<button type="submit" class="mainwp-approval-run"><?php echo esc_html__( 'Run it here instead', 'mainwp' ); ?></button>
</form>
        <?php endif; ?>
    <?php elseif ( 'consumed' !== $mainwp_rmcp_view['status'] ) : ?>
<p><?php echo esc_html__( 'This approval can no longer be used. Ask your AI app to start again.', 'mainwp' ); ?></p>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
