<?php
/**
 * Consent screen. Rendered by OAuth_Consent_Page with `$view`; every value is escaped
 * here except `headline_html`, which the page class built from escaped parts.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $view['title'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $view['stylesheet'] ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head(), so an enqueued style would never print. ?>
</head>
<body class="mainwp-oauth" data-page="consent">
<main class="mainwp-oauth-card">
<p class="mainwp-oauth-eyebrow"><?php esc_html_e( 'An app wants to connect to your Dashboard', 'mainwp' ); ?></p>
<h1 class="mainwp-oauth-headline"><?php echo $view['headline_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by OAuth_Consent_Page::headline_html(). ?></h1>
<?php if ( '' !== $view['client_name'] ) : ?>
<p class="mainwp-oauth-secondary">
    <?php
    /* translators: %s: the name in the app's own document */
    echo esc_html( sprintf( __( 'The app calls itself "%s".', 'mainwp' ), $view['client_name'] ) );
    ?>
</p>
<?php endif; ?>
<p class="mainwp-oauth-warning"><?php esc_html_e( 'Only approve if you just started this in your own app.', 'mainwp' ); ?></p>
<?php if ( '' !== $view['redirect_host'] ) : ?>
<p>
    <?php
    /* translators: %s: host name */
    echo esc_html( sprintf( __( 'After you decide, you\'ll return to %s.', 'mainwp' ), $view['redirect_host'] ) );
    ?>
</p>
<?php endif; ?>
<?php if ( $view['loopback_only'] ) : ?>
<p class="mainwp-oauth-note"><?php esc_html_e( 'The approval goes to a program on your own computer.', 'mainwp' ); ?></p>
<?php endif; ?>
<p class="mainwp-oauth-user"><?php echo esc_html( $view['signed_in'] ); ?> <a href="<?php echo esc_url( $view['logout_url'] ); ?>"><?php esc_html_e( 'Not you? Log out', 'mainwp' ); ?></a></p>
<?php if ( '' !== $view['site_limit'] ) : ?>
<p class="mainwp-oauth-note"><?php echo esc_html( $view['site_limit'] ); ?></p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( $view['action'] ); ?>">
<input type="hidden" name="request_key" value="<?php echo esc_attr( $view['request_key'] ); ?>">
<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $view['nonce'] ); ?>">
<?php if ( $view['write'] ) : ?>
<fieldset>
<legend><?php esc_html_e( 'What the app can do', 'mainwp' ); ?></legend>
<label><input type="radio" name="access" value="write" checked> <strong><?php esc_html_e( 'View and make changes', 'mainwp' ); ?></strong> <span><?php echo esc_html( $view['change_text'] ); ?></span></label>
<label><input type="radio" name="access" value="read"> <strong><?php esc_html_e( 'View only', 'mainwp' ); ?></strong> <span><?php echo esc_html( $view['view_text'] ); ?></span></label>
</fieldset>
<fieldset>
<legend><?php esc_html_e( 'Approval policy', 'mainwp' ); ?></legend>
    <?php foreach ( $view['policies'] as $policy => list( $label, $description ) ) : ?>
<label><input type="radio" name="policy" value="<?php echo esc_attr( $policy ); ?>"<?php echo OAuth_Store::POLICY_STANDARD === $policy ? ' checked' : ''; ?>> <strong><?php echo esc_html( $label ); ?></strong> <span><?php echo esc_html( $description ); ?></span></label>
    <?php endforeach; ?>
</fieldset>
<?php else : ?>
<p class="mainwp-oauth-access"><strong><?php esc_html_e( 'View only', 'mainwp' ); ?></strong> <?php echo esc_html( $view['view_text'] ); ?></p>
<?php endif; ?>
<div class="mainwp-oauth-actions">
<button type="submit" name="decision" value="approve" class="mainwp-oauth-approve"><?php esc_html_e( 'Approve', 'mainwp' ); ?></button>
<button type="submit" name="decision" value="deny"><?php esc_html_e( 'Deny', 'mainwp' ); ?></button>
</div>
</form>
</main>
</body>
</html>
