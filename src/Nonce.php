<?php
declare(strict_types=1);

namespace ModernMSPP;

defined('ABSPATH') || exit;

/**
 * Stateless nonce for unauthenticated loopback requests.
 *
 * wp_create_nonce() requires a logged-in user + session token, which a
 * loopback request does not have. We therefore build a nonce on top of
 * wp_hash() (salted with NONCE_SALT from wp-config.php) so that the value
 * is unforgeable without access to the site's salts, and inherits the
 * standard 12/24-hour tick lifetime.
 */
final class Nonce
{
    private const UID   = 'n/a';
    private const TOKEN = 'n/a';

    /**
     * Create a nonce for the given action.
     *
     * @param string $action Action identifier (e.g. Publisher::ACTION).
     * @return string 10-character nonce.
     */
    public static function create(string $action): string
    {
        return substr(
            wp_hash(wp_nonce_tick() . '|' . $action . '|' . self::UID . '|' . self::TOKEN, 'nonce'),
            -12,
            10
        );
    }

    /**
     * Verify a nonce previously created by self::create().
     *
     * Accepts nonces from the current and the previous tick (12–24h window).
     * Accepts mixed so callers can pass raw $_POST values without type juggling.
     *
     * @param string $action Action identifier.
     * @param mixed  $nonce  Nonce value to verify (typically from $_POST).
     * @return bool True if valid.
     */
    public static function verify(string $action, mixed $nonce): bool
    {
        if (! is_string($nonce) || $nonce === '') {
            return false;
        }

        $tick = wp_nonce_tick();

        // Nonce generated 0–12 hours ago.
        $current = substr(
            wp_hash($tick . '|' . $action . '|' . self::UID . '|' . self::TOKEN, 'nonce'),
            -12,
            10
        );
        if (hash_equals($current, $nonce)) {
            return true;
        }

        // Nonce generated 12–24 hours ago.
        $previous = substr(
            wp_hash(($tick - 1) . '|' . $action . '|' . self::UID . '|' . self::TOKEN, 'nonce'),
            -12,
            10
        );

        return hash_equals($previous, $nonce);
    }
}