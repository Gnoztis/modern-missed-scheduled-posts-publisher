<?php
/**
 * Plugin Name:       Modern Missed Scheduled Posts Publisher
 * Description:       Catches scheduled posts that have been missed and publishes them.
 * Version:           1.1.8
 * Requires at least: 7.0
 * Tested up to:      7.1
 * Requires PHP:      8.1
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

const VERSION = '1.1.8';

define('MODERN_MSPP_VERSION', VERSION);
define('MODERN_MSPP_FILE', __FILE__);
define('MODERN_MSPP_DIR', plugin_dir_path(__FILE__));
define('MODERN_MSPP_URL', plugin_dir_url(__FILE__));

require_once MODERN_MSPP_DIR . 'src/Nonce.php';
require_once MODERN_MSPP_DIR . 'src/Publisher.php';
require_once MODERN_MSPP_DIR . 'includes/class-modern_mspp-github-updater.php';
require_once MODERN_MSPP_DIR . 'src/Plugin.php';

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});