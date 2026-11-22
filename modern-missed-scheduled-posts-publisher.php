<?php
/**
 * Plugin Name:       Modern Missed Scheduled Posts Publisher
 * Description:       Catches scheduled posts that have been missed and publishes them.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Tested up to:      7.0
 * Requires PHP:      8.4
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modern-missed-scheduled-posts-publisher
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace ModernMSPP;

defined('ABSPATH') || exit;

const VERSION = '1.0.0';

define('MODERN_MSPP_VERSION', VERSION);
define('MODERN_MSPP_FILE', __FILE__);
define('MODERN_MSPP_DIR', plugin_dir_path(__FILE__));
define('MODERN_MSPP_URL', plugin_dir_url(__FILE__));

require_once MODERN_MSPP_DIR . 'src/Nonce.php';
require_once MODERN_MSPP_DIR . 'src/Publisher.php';
require_once MODERN_MSPP_DIR . 'src/Plugin.php';

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});