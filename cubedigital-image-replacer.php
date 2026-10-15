<?php
/**
 * Plugin Name: CubeDigital Image Replacer
 * Description: Replace a Media Library image with a subtle Replicate variation, keep the same URL, and restore the original later.
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: CubeDigital
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cubedigital-image-replacer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CDAIR_VERSION', '1.0.1');
define('CDAIR_FILE', __FILE__);
define('CDAIR_DIR', plugin_dir_path(__FILE__));

require_once CDAIR_DIR . 'includes/class-settings.php';
require_once CDAIR_DIR . 'includes/class-replacer.php';
require_once CDAIR_DIR . 'includes/class-replicate.php';
require_once CDAIR_DIR . 'includes/class-admin.php';

add_action('plugins_loaded', function () {
    CubeDigital\ImageReplacer\Settings::init();
    CubeDigital\ImageReplacer\Admin::init();
});
