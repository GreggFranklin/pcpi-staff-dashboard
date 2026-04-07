<?php
/**
 * PCPI Dependency Checker
 *
 * Provides a reusable, lightweight dependency validation system for PCPI plugins.
 *
 * PURPOSE
 * -------
 * Ensures required plugins (or classes) are available before a plugin initializes.
 * Prevents fatal errors and provides clear admin feedback when dependencies are missing.
 *
 * This class is designed to be copied into each PCPI plugin to keep plugins
 * self-contained while maintaining consistent dependency behavior across the system.
 *
 * HOW IT WORKS
 * ------------
 * - Hooks into `admin_init`
 * - Checks for required classes using `class_exists()`
 * - Displays a WordPress admin notice if dependencies are missing
 * - Optionally deactivates the plugin to prevent runtime issues
 *
 * USAGE
 * -----
 * Place this file in:
 *   /includes/utils/class-dependency-checker.php
 *
 * Then in your main plugin file:
 *
 *   require_once PLUGIN_DIR . 'includes/utils/class-dependency-checker.php';
 *
 *   PCPI_Dependency_Checker::check([
 *       'GFAPI'                => 'Gravity Forms',
 *       'PCPI_Workflow_Engine' => 'PCPI Workflow Engine',
 *   ], 'Your Plugin Name');
 *
 * PARAMETERS
 * ----------
 * @param array  $dependencies  Associative array of required classes:
 *                             [ 'ClassName' => 'Human Readable Name' ]
 * @param string $plugin_name   Name displayed in admin notices
 * @param bool   $deactivate    Optional. If true, plugin auto-deactivates when missing dependencies
 *
 * BEHAVIOR
 * --------
 * - If ALL dependencies exist → plugin continues normally
 * - If ANY are missing:
 *     → Displays admin error notice
 *     → (Optional) Deactivates plugin
 *
 * NOTES
 * -----
 * - Uses class-based detection (not plugin slug detection)
 * - Safe to include in multiple plugins (guarded by class_exists)
 * - Designed for modular PCPI ecosystem (Workflow Engine, CPT, Dashboard, etc.)
 *
 * BEST PRACTICES
 * --------------
 * - Call this BEFORE loading or initializing your plugin
 * - Only check dependencies that are REQUIRED for execution
 * - Do not overuse auto-deactivation unless failure is critical
 *
 * FUTURE EXTENSIONS (Optional)
 * ---------------------------
 * - Plugin install/activate links in notices
 * - Version requirement checks
 * - Soft vs hard dependency modes
 * - Shared PCPI Core utilities plugin
 */

defined('ABSPATH') || exit;

if ( ! class_exists('PCPI_Dependency_Checker') ) {

final class PCPI_Dependency_Checker {

    public static function check(array $dependencies, string $plugin_name, bool $deactivate = false){

        add_action('admin_init', function() use ($dependencies, $plugin_name, $deactivate){

            $missing = [];

            foreach ($dependencies as $class => $label) {
                if ( ! class_exists($class) ) {
                    $missing[$class] = $label;
                }
            }

            if ( empty($missing) ) {
                return;
            }

            // Admin notice
            add_action('admin_notices', function() use ($missing, $plugin_name){

                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html($plugin_name) . ':</strong> Missing required dependencies:<br>';

                echo '<ul style="margin:8px 0 0 20px;">';
                foreach ($missing as $label) {
                    echo '<li>' . esc_html($label) . '</li>';
                }
                echo '</ul>';

                echo '</p></div>';
            });

            // Optional auto-deactivate
            if ( $deactivate ) {

                deactivate_plugins(plugin_basename(__FILE__));

                add_action('admin_notices', function() use ($plugin_name){
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>' . esc_html($plugin_name) . '</strong> has been deactivated due to missing dependencies.';
                    echo '</p></div>';
                });
            }
        });
    }
}

}