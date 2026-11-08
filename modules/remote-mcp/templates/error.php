<?php
/**
 * Authorization error page. Rendered by OAuth_Consent_Page with `$view`.
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
<body class="mainwp-oauth" data-page="error" data-error="<?php echo esc_attr( $view['code'] ); ?>">
<main class="mainwp-oauth-card">
<h1><?php echo esc_html( $view['title'] ); ?></h1>
<p><?php echo esc_html( $view['message'] ); ?></p>
<?php if ( '' !== $view['detail'] ) : ?>
<p><?php echo esc_html( $view['detail'] ); ?></p>
<?php endif; ?>
</main>
</body>
</html>
