<?php
/**
 * Explicit selection keeps personal plans out of automatic resolution.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- AI Client interfaces require these method names.

/** Personal plans must never become a site-wide default. */
class MainWP_Assistant_ChatGPT_Availability implements ProviderAvailabilityInterface {
    /** {@inheritDoc} */
    public function isConfigured(): bool {
        return false;
    }
}
