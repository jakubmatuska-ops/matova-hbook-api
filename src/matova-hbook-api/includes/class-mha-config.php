<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Per-site resort registry for matova/v2.
 *
 * Resolution order:
 *   1. constant MHA_CONFIG (array, keyed by resort_id)
 *   2. option 'mha_config'  (same shape)
 *   3. built-in defaults for the four known resorts (CONTRACTS §9)
 *
 * The active resort is selected by MHA_CONFIG_RESORT / option 'mha_resort_id'
 * when set, otherwise auto-detected from home_url() host.
 *
 * Record shape:
 *   { resort_id, hb_prefix, timezone, vat_payer(bool), season:{open,close},
 *     currency, units_total, hosts:[...] }
 */
class MHA_Config {

    /** @var array|null resolved registry {resort_id => record} */
    private static $registry = null;

    /** @var string|null resolved active resort id */
    private static $active = null;

    /** Built-in defaults for the four known resorts (CONTRACTS §9). */
    private static function defaults() {
        return [
            'slunicko' => [
                'resort_id'   => 'slunicko',
                'hb_prefix'   => 'ar1w_',
                'timezone'    => 'Europe/Prague',
                'vat_payer'   => true,
                'season'      => ['open' => '04-01', 'close' => '10-31'],
                'currency'    => 'CZK',
                'units_total' => 0,
                'hosts'       => ['kempslunicko.cz', 'www.kempslunicko.cz', 'dev.kempslunicko.cz'],
            ],
            'borny' => [
                'resort_id'   => 'borny',
                'hb_prefix'   => 'ar1w_',
                'timezone'    => 'Europe/Prague',
                'vat_payer'   => false,
                'season'      => ['open' => '04-01', 'close' => '10-31'],
                'currency'    => 'CZK',
                'units_total' => 0,
                'hosts'       => ['chatkyborny.cz', 'www.chatkyborny.cz'],
            ],
            'srdicko' => [
                'resort_id'   => 'srdicko',
                'hb_prefix'   => 'jbsp_',
                'timezone'    => 'Europe/Prague',
                'vat_payer'   => true,
                'season'      => ['open' => '04-01', 'close' => '10-31'],
                'currency'    => 'CZK',
                'units_total' => 0,
                'hosts'       => ['kempsrdicko.cz', 'www.kempsrdicko.cz'],
            ],
            'passage' => [
                'resort_id'   => 'passage',
                'hb_prefix'   => 'y9j6_',
                'timezone'    => 'Europe/Prague',
                'vat_payer'   => true,
                'season'      => ['open' => '01-01', 'close' => '12-31'],
                'currency'    => 'CZK',
                'units_total' => 0,
                'hosts'       => ['passagesplavy.cz', 'www.passagesplavy.cz'],
            ],
        ];
    }

    /** Resolve and cache the registry (merged defaults + overrides). */
    public static function all_resorts() {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $registry = self::defaults();

        $override = null;
        if (defined('MHA_CONFIG') && is_array(MHA_CONFIG)) {
            $override = MHA_CONFIG;
        } else {
            $opt = get_option('mha_config');
            if (is_array($opt)) { $override = $opt; }
        }

        if (is_array($override)) {
            foreach ($override as $rid => $rec) {
                if (!is_array($rec)) { continue; }
                $rid = isset($rec['resort_id']) ? $rec['resort_id'] : $rid;
                $base = isset($registry[$rid]) ? $registry[$rid] : [];
                $merged = array_merge($base, $rec);
                $merged['resort_id'] = $rid;
                $registry[$rid] = $merged;
            }
        }

        self::$registry = $registry;
        return self::$registry;
    }

    /** The active resort_id for this site. */
    public static function resort_id() {
        if (self::$active !== null) {
            return self::$active;
        }

        // Explicit override wins.
        if (defined('MHA_CONFIG_RESORT') && MHA_CONFIG_RESORT) {
            self::$active = (string) MHA_CONFIG_RESORT;
            return self::$active;
        }
        $opt = get_option('mha_resort_id');
        if (is_string($opt) && $opt !== '') {
            self::$active = $opt;
            return self::$active;
        }

        // Auto-detect by home_url() host.
        $host = '';
        if (function_exists('home_url')) {
            $parts = wp_parse_url(home_url());
            if (is_array($parts) && !empty($parts['host'])) {
                $host = strtolower($parts['host']);
            }
        }
        $registry = self::all_resorts();
        if ($host !== '') {
            foreach ($registry as $rid => $rec) {
                $hosts = isset($rec['hosts']) && is_array($rec['hosts']) ? $rec['hosts'] : [];
                foreach ($hosts as $h) {
                    if (strtolower($h) === $host) {
                        self::$active = $rid;
                        return self::$active;
                    }
                }
            }
        }

        // Fallback: first known resort (slunicko) to avoid a hard failure.
        self::$active = 'slunicko';
        return self::$active;
    }

    /** Full record for the active resort (or a named one). */
    public static function resort($resort_id = null) {
        $registry = self::all_resorts();
        $rid = $resort_id !== null ? $resort_id : self::resort_id();
        return isset($registry[$rid]) ? $registry[$rid] : null;
    }

    public static function hb_prefix($resort_id = null) {
        global $wpdb;
        $rec = self::resort($resort_id);
        if ($rec && !empty($rec['hb_prefix'])) {
            return $rec['hb_prefix'];
        }
        // Last resort: WP table prefix (HBook installs under the site prefix).
        return isset($wpdb) ? $wpdb->prefix : '';
    }

    public static function timezone($resort_id = null) {
        $rec = self::resort($resort_id);
        return ($rec && !empty($rec['timezone'])) ? $rec['timezone'] : 'Europe/Prague';
    }

    public static function is_vat_payer($resort_id = null) {
        $rec = self::resort($resort_id);
        return $rec ? (bool) ($rec['vat_payer'] ?? false) : false;
    }

    public static function currency($resort_id = null) {
        $rec = self::resort($resort_id);
        return ($rec && !empty($rec['currency'])) ? $rec['currency'] : 'CZK';
    }

    public static function season($resort_id = null) {
        $rec = self::resort($resort_id);
        return ($rec && isset($rec['season']) && is_array($rec['season']))
            ? $rec['season']
            : ['open' => null, 'close' => null];
    }

    /**
     * Write-mode gate (CONTRACTS §7). Per-kind config from option 'mha_write_mode'.
     * Returns 'off' | 'dry_run' | 'live'. Default 'off'.
     *
     * $kind examples: status, note, block, payment, guest, create, modify.
     */
    public static function write_mode($kind) {
        $modes = get_option('mha_write_mode', []);
        if (!is_array($modes)) { $modes = []; }
        $allowed = ['off', 'dry_run', 'live'];

        if (isset($modes[$kind]) && in_array($modes[$kind], $allowed, true)) {
            return $modes[$kind];
        }
        if (isset($modes['default']) && in_array($modes['default'], $allowed, true)) {
            return $modes['default'];
        }
        return 'off';
    }
}
