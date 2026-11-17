<?php
/**
 * Consent screen. Rendered by OAuth_Consent_Page with `$view`; every value is escaped
 * here except `headline_html` and `secondary_html`, which the page class built from escaped parts.
 * The order informs before it warns: who is asking, what it may do, then the one caution.
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
<p class="mainwp-oauth-eyebrow">
<?php
/* translators: %s: this Dashboard's host */
echo esc_html( sprintf( __( 'MainWP Dashboard · %s', 'mainwp' ), $view['site'] ) );
?>
</p>
<h1 class="mainwp-oauth-headline"><?php echo $view['headline_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by OAuth_Consent_Page::identity(). ?></h1>
<p class="mainwp-oauth-secondary"><?php echo $view['secondary_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by OAuth_Consent_Page::identity(). ?></p>
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
<fieldset class="mainwp-oauth-policies">
<legend><?php esc_html_e( 'When should MainWP ask you before the app changes something?', 'mainwp' ); ?></legend>
    <?php foreach ( $view['policies'] as $policy => list( $label, $description ) ) : ?>
<label><input type="radio" name="policy" value="<?php echo esc_attr( $policy ); ?>"<?php echo OAuth_Store::POLICY_STANDARD === $policy ? ' checked' : ''; ?>> <strong><?php echo esc_html( $label ); ?></strong> <span<?php echo OAuth_Store::POLICY_HANDS_OFF === $policy ? ' class="mainwp-oauth-hands-off"' : ''; ?>><?php echo esc_html( $description ); ?></span></label>
    <?php endforeach; ?>
</fieldset>
<?php else : ?>
<p class="mainwp-oauth-access"><strong><?php esc_html_e( 'View only', 'mainwp' ); ?></strong> <?php echo esc_html( $view['view_text'] ); ?></p>
<?php endif; ?>
<p class="mainwp-oauth-warning"><?php echo esc_html( $view['caution'] ); ?></p>
<div class="mainwp-oauth-actions">
<button type="submit" name="decision" value="approve" class="mainwp-oauth-approve"><?php esc_html_e( 'Approve', 'mainwp' ); ?></button>
<button type="submit" name="decision" value="deny"><?php esc_html_e( 'Deny', 'mainwp' ); ?></button>
</div>
</form>
</main>
</body>
</html>
