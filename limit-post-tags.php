<?php
/**
 * Plugin Name: Limit Post Tags
 * Description: Ограничивает вывод меток до 5 штук и добавляет кнопку «Ещё N меток» как продолжение списка. Скрытие лишних меток через CSS-класс, без обрезки по высоте — кнопка всегда видна.
 * Version:     1.7.6
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.4
 * Author:      Gnostis
 * License:     GPL-2.0-or-later
 * Text Domain: limit-post-tags
 * Update URI:  https://github.com/Gnoztis/limit-post-tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LPT_VERSION', '1.7.6' );
define( 'LPT_PLUGIN_FILE', __FILE__ );
define( 'LPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once LPT_PLUGIN_DIR . 'includes/class-lpt-config.php';
require_once LPT_PLUGIN_DIR . 'includes/class-lpt-tags.php';
require_once LPT_PLUGIN_DIR . 'includes/class-lpt-github-updater.php';
require_once LPT_PLUGIN_DIR . 'includes/class-lpt-plugin.php';

LPT_Plugin::instance()->init();
