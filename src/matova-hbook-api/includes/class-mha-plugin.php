<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Plugin singleton. boot() subscribes the outbox hooks and registers all
 * matova/v2 REST routes on rest_api_init.
 */
class MHA_Plugin {

    /** @var MHA_Plugin|null */
    private static $instance = null;

    /** @var bool */
    private $booted = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot() {
        if ($this->booted) { return; }
        $this->booted = true;

        // Subscribe to HBook hooks -> outbox events. Wrapped so that a failure
        // in outbox wiring can never prevent REST route registration below —
        // the API must still answer even if event emission is degraded.
        try { MHA_Store::subscribe_hooks(); } catch (\Throwable $e) { /* outbox degraded; routes still register */ }

        // Ensure our own tables exist (zip-overwrite deploys never fire the
        // activation hook). This runs dbDelta + loads wp-admin/includes/upgrade.php,
        // which is unsafe/heavy on a front-end request, so it is gated to admin_init
        // only. Front-end REST requests never trigger schema work; if a table is
        // temporarily absent the store methods no-op until the next admin page load
        // heals the schema. Version-gated, so the steady-state cost is one option read.
        if (is_admin()) {
            add_action('admin_init', ['MHA_Store', 'ensure_installed']);
        }

        // Register REST routes once the REST API is initializing.
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Instantiate every controller and register its routes. Each controller is
     * isolated: a failure constructing or registering one never blocks the rest,
     * so a single bad component degrades one route group instead of taking the
     * whole namespace offline (which would surface to clients as rest_no_route).
     */
    public function register_routes() {
        $controllers = [
            'MHA_Meta_Controller',
            'MHA_Reservations_Controller',
            'MHA_Availability_Controller',
            'MHA_Pricing_Controller',
            'MHA_Guests_Controller',
            'MHA_Payments_Controller',
            'MHA_Operations_Controller',
            'MHA_Relay_Controller',
        ];
        foreach ($controllers as $cls) {
            try {
                $c = new $cls();
                $c->register();
            } catch (\Throwable $e) {
                // Degrade this one route group only; the rest of matova/v2 stays up.
                continue;
            }
        }
    }
}
