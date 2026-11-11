<?php
/**
 * Plugin Name: Matova HBook API
 * Description: Unified, versioned HBook API (matova/v2) for the Matova Group ecosystem. Read + scoped writes through HBook's native logic. Additive to legacy namespaces during migration.
 * Version: 2.4.0
 * Author: Matova Group
 * Requires PHP: 7.4
 *
 * Design + contracts: docs/CONTRACTS.md (source of truth for this plugin).
 * HBook stays the source of truth for reservations; this plugin never writes HBook
 * tables with raw SQL — mutations go through HBook's native methods only.
 */

if (!defined('ABSPATH')) { exit; }

define('MHA_VERSION', '2.4.0');
define('MHA_NS', 'matova/v2');
define('MHA_DIR', plugin_dir_path(__FILE__));
define('MHA_MIN_KEY_LEN', 32);

// Core (hand-maintained security layer first, then the rest).
require_once MHA_DIR . 'includes/class-mha-response.php';
require_once MHA_DIR . 'includes/class-mha-auth.php';
require_once MHA_DIR . 'includes/class-mha-config.php';
require_once MHA_DIR . 'includes/class-mha-request.php';
require_once MHA_DIR . 'includes/class-mha-store.php';      // idempotency + audit + outbox + schema
require_once MHA_DIR . 'includes/class-mha-hbook.php';      // the only bridge to HBook internals
require_once MHA_DIR . 'includes/controllers/class-mha-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-meta-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-reservations-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-availability-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-pricing-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-guests-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-payments-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-operations-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-relay-controller.php';
require_once MHA_DIR . 'includes/controllers/class-mha-update-controller.php';
// Switched-off legacy namespaces lose their routes (reversible, no file changes).
add_filter('rest_endpoints', ['MHA_Relay_Controller', 'filter_endpoints'], 999);
require_once MHA_DIR . 'includes/class-mha-plugin.php';
require_once MHA_DIR . 'includes/class-mha-admin.php';     // wp-admin control surface (write modes + keys); no CLI/snippets needed
require_once MHA_DIR . 'includes/class-mha-updater.php';   // one-click self-update from GitHub (no FTP)
require_once MHA_DIR . 'includes/class-mha-testguard.php'; // test/dev side-effect isolation; active only if option mha_test_isolation=on
require_once MHA_DIR . 'includes/compat/class-mha-legacy-shim.php'; // optional, self-registers only if option mha_legacy_shim=on
if (defined('WP_CLI') && WP_CLI) {
    require_once MHA_DIR . 'includes/cli/class-mha-cli.php'; // `wp mha` admin/migration commands
}

register_activation_hook(__FILE__, ['MHA_Store', 'install']);

add_action('plugins_loaded', function () {
    // Boot late so HBook (and its globals) are available for the adapter + outbox hooks.
    MHA_Plugin::instance()->boot();
    if (is_admin() && class_exists('MHA_Admin')) { MHA_Admin::boot(); }
    // Always on: background auto-updates and WP-CLI run outside wp-admin, and
    // the signature check must guard those installs too.
    if (class_exists('MHA_Updater')) { MHA_Updater::init(); }
    if (class_exists('MHA_TestGuard')) { MHA_TestGuard::boot(); }
    if (class_exists('MHA_Legacy_Shim')) { MHA_Legacy_Shim::boot(); }
}, 30);
