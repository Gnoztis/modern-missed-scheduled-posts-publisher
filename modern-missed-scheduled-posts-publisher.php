<?php
/**
 * Plugin Name:       Modern Missed Scheduled Posts Publisher
 * Description:       Catches scheduled posts that have been missed and publishes them.
 * Version:           1.1.3
 * Requires at least: 7.0
 * Tested up to:      7.1
 * Requires PHP:      8.4
 * Author:            Gnostis
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modern-missed-scheduled-posts-publisher
 * Domain Path:       /languages
 * Update URI:        https://github.com/Gnoztis/modern-missed-scheduled-posts-publisher
 */

declare(strict_types=1);

namespace ModernMSPP;

if (! defined('ABSPATH')) {
    exit;
}

const VERSION = '1.1.3';

define('MODERN_MSPP_VERSION', VERSION);
define('MODERN_MSPP_FILE', __FILE__);
define('MODERN_MSPP_DIR', plugin_dir_path(__FILE__));
define('MODERN_MSPP_URL', plugin_dir_url(__FILE__));

// The generated updater reads MODERN_MSPP_GITHUB_TOKEN. The plugin docs use
// the shorter MSPP_GITHUB_TOKEN, so map it before the updater class is loaded.
if (defined('MSPP_GITHUB_TOKEN') && ! defined('MODERN_MSPP_GITHUB_TOKEN')) {
    define('MODERN_MSPP_GITHUB_TOKEN', (string) constant('MSPP_GITHUB_TOKEN'));
}

require_once MODERN_MSPP_DIR . 'src/Nonce.php';
require_once MODERN_MSPP_DIR . 'src/Publisher.php';
require_once MODERN_MSPP_DIR . 'includes/class-modern_mspp-github-updater.php';
require_once MODERN_MSPP_DIR . 'src/Plugin.php';

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});