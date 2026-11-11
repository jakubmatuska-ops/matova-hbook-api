<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WP-CLI admin tooling for the matova/v2 migration.
 *
 * Registers `wp mha <subcommand>`:
 *   - issue-key       mint a scoped key (prints the RAW key exactly once)
 *   - list-keys       list key records (never the hash / never the raw key)
 *   - revoke-key      set a key record active:false (immediate revocation)
 *   - set-write-mode  flip the per-kind write-mode gate (off|dry_run|live)
 *   - show-config     dump the resolved registry + write modes + runtime flags
 *
 * This class is a THIN wrapper. It does not reimplement auth, hashing, scope
 * logic or the write gate — it calls the existing MHA_Auth / MHA_Config methods
 * and reads/writes the same options those classes own (MHA_Auth::OPT_KEYS,
 * option 'mha_write_mode'). The raw key is produced only by MHA_Auth::issue_key().
 *
 * ---------------------------------------------------------------------------
 * ACTIVATION: the main plugin bootstrap (matova-hbook-api.php) requires its
 * includes explicitly and does not glob. This file is therefore NOT loaded
 * until one line is added to that bootstrap (owner action — the build task may
 * not edit existing .php files):
 *
 *     if (defined('WP_CLI') && WP_CLI) {
 *         require_once MHA_DIR . 'includes/cli/class-mha-cli.php';
 *     }
 *
 * Once required under WP-CLI, it self-registers via the guard below.
 * ---------------------------------------------------------------------------
 */
class MHA_CLI {

    /** Valid write-mode kinds (mirror MHA_Config::write_mode() $kind usage). */
    const KINDS = ['status', 'note', 'block', 'payment', 'guest', 'create', 'modify'];
    const MODES = ['off', 'dry_run', 'live'];

    /**
     * Issue a scoped key for one consumer x resort x scope.
     *
     * The RAW key is printed once and is NOT recoverable afterwards — only its
     * salted SHA-256 hash is stored (MHA_Auth::issue_key()).
     *
     * ## OPTIONS
     *
     * --consumer=<consumer>
     * : Consumer identity, e.g. lovable, make, ops-bridge, telegram, wp-widgets.
     *
     * --scope=<scope>
     * : One of: read, ops-write, admin.
     *
     * --resort=<resort>
     * : One of: slunicko, srdicko, borny, passage.
     *
     * [--label=<label>]
     * : Human label for the key record. Defaults to "<consumer>:<scope>".
     *
     * ## EXAMPLES
     *     wp mha issue-key --consumer=lovable --scope=read --resort=srdicko
     *     wp mha issue-key --consumer=ops-bridge --scope=ops-write --resort=borny --label="OPS bridge borny ops"
     */
    public function issue_key($args, $assoc) {
        $consumer = isset($assoc['consumer']) ? (string) $assoc['consumer'] : '';
        $scope    = isset($assoc['scope']) ? (string) $assoc['scope'] : '';
        $resort   = isset($assoc['resort']) ? (string) $assoc['resort'] : '';
        $label    = isset($assoc['label']) ? (string) $assoc['label'] : '';

        if ($consumer === '' || $scope === '' || $resort === '') {
            WP_CLI::error('--consumer, --scope and --resort are all required.');
            return;
        }
        if (!isset(MHA_Auth::SCOPE_RANK[$scope])) {
            WP_CLI::error('Unknown scope "' . $scope . '". Valid: ' . implode(', ', array_keys(MHA_Auth::SCOPE_RANK)) . '.');
            return;
        }
        if (!array_key_exists($resort, MHA_Config::all_resorts())) {
            WP_CLI::warning('Resort "' . $resort . '" is not in the registry; issuing anyway (it will only match requests whose active resort is "' . $resort . '").');
        }

        $raw = MHA_Auth::issue_key($consumer, $scope, $resort, $label);
        if (is_wp_error($raw)) {
            WP_CLI::error($raw->get_error_message());
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('  consumer : ' . $consumer);
        WP_CLI::log('  scope    : ' . $scope);
        WP_CLI::log('  resort   : ' . $resort);
        WP_CLI::log('  label    : ' . ($label !== '' ? $label : $consumer . ':' . $scope));
        WP_CLI::log('');
        WP_CLI::log('  RAW KEY (shown ONCE — store it now, it is not recoverable):');
        WP_CLI::log('    ' . $raw);
        WP_CLI::log('');
        WP_CLI::success('Key issued. Send it to the consumer over a secure channel as "Authorization: Bearer <key>".');
    }

    /**
     * List key records. Shows id/consumer/scope/resort/active/last_used only.
     * The stored hash and the raw key are NEVER printed.
     *
     * ## OPTIONS
     *
     * [--resort=<resort>]
     * : Only records for this resort.
     *
     * [--active=<bool>]
     * : Filter by active state: true or false.
     *
     * [--format=<format>]
     * : table (default), json, csv, yaml, count.
     *
     * ## EXAMPLES
     *     wp mha list-keys
     *     wp mha list-keys --resort=passage --active=true
     *     wp mha list-keys --format=json
     */
    public function list_keys($args, $assoc) {
        $records = get_option(MHA_Auth::OPT_KEYS, []);
        if (!is_array($records)) { $records = []; }

        $resort_filter = isset($assoc['resort']) ? (string) $assoc['resort'] : null;
        $active_filter = null;
        if (isset($assoc['active'])) {
            $active_filter = in_array(strtolower((string) $assoc['active']), ['1', 'true', 'yes'], true);
        }

        $rows = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) { continue; }
            $active = !empty($rec['active']);
            if ($resort_filter !== null && ($rec['resort'] ?? '') !== $resort_filter) { continue; }
            if ($active_filter !== null && $active !== $active_filter) { continue; }
            $rows[] = [
                'id'        => (string) ($rec['id'] ?? ''),
                'label'     => (string) ($rec['label'] ?? ''),
                'consumer'  => (string) ($rec['consumer'] ?? ''),
                'scope'     => (string) ($rec['scope'] ?? ''),
                'resort'    => (string) ($rec['resort'] ?? ''),
                'active'    => $active ? 'yes' : 'no',
                'created'   => (string) ($rec['created'] ?? ''),
                'last_used' => (string) ($rec['last_used'] ?? ''),
            ];
        }

        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        $fields = ['id', 'label', 'consumer', 'scope', 'resort', 'active', 'created', 'last_used'];
        if (empty($rows) && $format === 'table') {
            WP_CLI::log('No key records match.');
            return;
        }
        WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    /**
     * Revoke a key by id (sets the record active:false — immediate, auditable).
     * The record is kept (not deleted) so audit history still resolves the id.
     *
     * ## OPTIONS
     *
     * <id>
     * : The key record id (the "id" column from `wp mha list-keys`, e.g. k_ab12cd34ef56).
     *
     * ## EXAMPLES
     *     wp mha revoke-key k_ab12cd34ef56
     */
    public function revoke_key($args, $assoc) {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '') {
            WP_CLI::error('A key id is required: wp mha revoke-key <id>');
            return;
        }
        $ok = MHA_Auth::revoke_key($id);
        if (!$ok) {
            WP_CLI::error('No key record with id "' . $id . '" found.');
            return;
        }
        WP_CLI::success('Key "' . $id . '" revoked (active:false). Any consumer still presenting it now gets 401.');
    }

    /**
     * Set the per-kind write-mode gate (CONTRACTS §7). Writes option
     * 'mha_write_mode' — the same option MHA_Config::write_mode() reads.
     *
     * ## OPTIONS
     *
     * --kind=<kind>
     * : One of: status, note, block, payment, guest, create, modify. Or "default".
     *
     * --mode=<mode>
     * : One of: off, dry_run, live.
     *
     * ## EXAMPLES
     *     wp mha set-write-mode --kind=status --mode=dry_run
     *     wp mha set-write-mode --kind=status --mode=live
     *     wp mha set-write-mode --kind=payment --mode=off
     */
    public function set_write_mode($args, $assoc) {
        $kind = isset($assoc['kind']) ? (string) $assoc['kind'] : '';
        $mode = isset($assoc['mode']) ? (string) $assoc['mode'] : '';

        if ($kind === '' || $mode === '') {
            WP_CLI::error('--kind and --mode are both required.');
            return;
        }
        $valid_kinds = array_merge(self::KINDS, ['default']);
        if (!in_array($kind, $valid_kinds, true)) {
            WP_CLI::error('Unknown kind "' . $kind . '". Valid: ' . implode(', ', $valid_kinds) . '.');
            return;
        }
        if (!in_array($mode, self::MODES, true)) {
            WP_CLI::error('Unknown mode "' . $mode . '". Valid: ' . implode(', ', self::MODES) . '.');
            return;
        }

        if (in_array($kind, ['create', 'modify'], true) && $mode === 'live') {
            WP_CLI::warning('create/modify reservation are documented untested-against-HBook (CONTRACTS §7). Enabling "live" is at your own risk; prefer dry_run until a local HBook env or Borny verification exists.');
        }
        if ($kind === 'payment' && $mode === 'live') {
            WP_CLI::warning('payment "live" requires HBook 2.2.x (payments_logs / add_resa_payment). On 2.0.9 sites (slunicko/passage) the adapter returns write_disabled regardless of this gate.');
        }

        $modes = get_option('mha_write_mode', []);
        if (!is_array($modes)) { $modes = []; }
        $prev = isset($modes[$kind]) ? $modes[$kind] : '(unset → off)';
        $modes[$kind] = $mode;
        update_option('mha_write_mode', $modes, false);

        // Read back through MHA_Config so the printed value is the effective one.
        $effective = ($kind === 'default') ? $mode : MHA_Config::write_mode($kind);
        WP_CLI::success('write-mode[' . $kind . ']: ' . $prev . ' → ' . $mode . ' (effective: ' . $effective . ').');
    }

    /**
     * Dump the resolved per-site config: active resort, registry record,
     * the effective write-mode per kind, and the runtime auth/rate flags.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table (default) for the per-kind modes, or json for the whole dump.
     *
     * ## EXAMPLES
     *     wp mha show-config
     *     wp mha show-config --format=json
     */
    public function show_config($args, $assoc) {
        $resort_id = MHA_Config::resort_id();
        $record    = MHA_Config::resort($resort_id);

        $modes = [];
        foreach (self::KINDS as $kind) {
            $modes[$kind] = MHA_Config::write_mode($kind);
        }

        $records = get_option(MHA_Auth::OPT_KEYS, []);
        $key_count = is_array($records) ? count($records) : 0;
        $active_keys = 0;
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (is_array($rec) && !empty($rec['active'])) { $active_keys++; }
            }
        }

        $runtime = [
            'api_namespace' => defined('MHA_NS') ? MHA_NS : 'matova/v2',
            'version'       => defined('MHA_VERSION') ? MHA_VERSION : 'unknown',
            'min_key_len'   => defined('MHA_MIN_KEY_LEN') ? MHA_MIN_KEY_LEN : null,
            'rate_per_min'  => defined('MHA_RATE_PER_MIN') ? (int) MHA_RATE_PER_MIN : 120,
            'keys_total'    => $key_count,
            'keys_active'   => $active_keys,
        ];

        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        if ($format === 'json') {
            WP_CLI::log(wp_json_encode([
                'active_resort' => $resort_id,
                'registry'      => $record,
                'write_mode'    => $modes,
                'runtime'       => $runtime,
            ]));
            return;
        }

        WP_CLI::log('Active resort : ' . $resort_id);
        if (is_array($record)) {
            WP_CLI::log('  hb_prefix   : ' . ($record['hb_prefix'] ?? ''));
            WP_CLI::log('  timezone    : ' . ($record['timezone'] ?? ''));
            WP_CLI::log('  currency    : ' . ($record['currency'] ?? ''));
            WP_CLI::log('  vat_payer   : ' . (!empty($record['vat_payer']) ? 'yes' : 'no'));
        }
        WP_CLI::log('Runtime       :');
        foreach ($runtime as $k => $v) {
            WP_CLI::log('  ' . str_pad($k, 13) . ': ' . (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v));
        }
        WP_CLI::log('Write modes   :');
        $rows = [];
        foreach ($modes as $kind => $mode) {
            $rows[] = ['kind' => $kind, 'mode' => $mode];
        }
        WP_CLI\Utils\format_items('table', $rows, ['kind', 'mode']);
    }
}

// Self-register the command set only under WP-CLI.
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mha', 'MHA_CLI');
}
