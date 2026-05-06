<?php
/**
 * Credential render helpers for sentinel-based form rendering.
 *
 * @package MainWP\Dashboard
 * @since   6.0.12
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Credential_Render
 *
 * Settings pages that render credential-shaped values into HTML inputs
 * (third-party API keys, master license keys, HTTP Basic Auth passwords)
 * leak the live value into the rendered DOM. The sentinel pattern fixes
 * the leak while preserving the "value already set" admin signal:
 *
 *   - On render, if a value is set the input shows a fixed placeholder
 *     ('••••••••') instead of the actual value. If unset, the input is
 *     empty.
 *   - On save, the corresponding storage handler checks for the sentinel
 *     and short-circuits without modifying the stored value, so a form
 *     submission that did not change the field preserves the existing
 *     value rather than overwriting it with the sentinel.
 *
 * Used by MWP-1543 (3rd-party API Backups settings), MWP-1547 (master
 * license key), MWP-1546 (per-extension license keys), and MWP-1548
 * (HTTP Basic Auth password on the Edit Site form).
 */
class MainWP_Credential_Render {

    /**
     * The placeholder rendered into a credential input when a value is
     * already stored. Visually obvious to admins, recognizable to the
     * matching save handler, and intentionally a string no real key
     * generator would produce.
     *
     * @var string
     */
    const SENTINEL = '••••••••';

    /**
     * Return the value to write into an `<input value="...">` attribute
     * for a credential field. Empty string when nothing is stored, the
     * sentinel placeholder when something is stored.
     *
     * @param bool   $has_value Whether a value is currently stored.
     * @param string $sentinel  Override the placeholder (rare; mostly for tests).
     * @return string Sentinel placeholder or empty string.
     */
    public static function value_for_input( $has_value, $sentinel = self::SENTINEL ) {
        return $has_value ? $sentinel : '';
    }

    /**
     * Detect whether a submitted form value is the unchanged-sentinel.
     * Save handlers that get true here MUST short-circuit and leave the
     * existing storage untouched.
     *
     * Callers MUST pass the raw `wp_unslash()`-ed value. Do not chain
     * `sanitize_text_field()`, `wp_kses*()`, `mb_convert_encoding()`, or
     * any other byte-altering filter before the check, or the multi-byte
     * bullet bytes get stripped/normalized and the comparison fails open
     * (the sentinel becomes a real submission and overwrites storage).
     *
     * @param mixed  $submitted Submitted form value (typically from $_POST).
     * @param string $sentinel  Override the placeholder (rare; mostly for tests).
     * @return bool True if the submitted value equals the sentinel.
     */
    public static function is_sentinel( $submitted, $sentinel = self::SENTINEL ) {
        return is_string( $submitted ) && $submitted === $sentinel;
    }
}
