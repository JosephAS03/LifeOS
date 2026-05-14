<?php
/**
 * Plugin Name: LIFE OS
 * Plugin URI: https://github.com/JosephAS03/LifeOS
 * Description: Timeline-first personal dashboard for health, finance, tasks, mood, and Discord automation.
 * Version: 0.2.0
 * Author: OpenAI Codex
 * Author URI: https://github.com/JosephAS03/LifeOS
 * Update URI: https://github.com/JosephAS03/LifeOS
 * Requires PHP: 8.1
 * Text Domain: life-os
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LIFE_OS_VERSION', '0.2.0');
define('LIFE_OS_FILE', __FILE__);
define('LIFE_OS_DIR', plugin_dir_path(__FILE__));
define('LIFE_OS_URL', plugin_dir_url(__FILE__));
define('LIFE_OS_GITHUB_REPO_URL', 'https://github.com/JosephAS03/LifeOS');
define('LIFE_OS_GITHUB_RELEASES_URL', 'https://github.com/JosephAS03/LifeOS/releases');
define('LIFE_OS_GITHUB_LATEST_RELEASE_API', 'https://api.github.com/repos/JosephAS03/LifeOS/releases/latest');

require_once LIFE_OS_DIR . 'src/Autoloader.php';

\LifeOS\Autoloader::register();

register_activation_hook(__FILE__, [\LifeOS\Installer::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    (new \LifeOS\Plugin())->boot();
});
