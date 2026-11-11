<?php
/**
 * Lightweight WordPress shim to load the matova-hbook-api plugin without a real
 * WordPress + MySQL. Backed by pdo_sqlite. See tests/README.md.
 *
 * Load order:
 *   1. define ABSPATH + plugin constants
 *   2. define WP function + class stubs
 *   3. build $wpdb (SQLite) and HBook price stub
 *   4. require the plugin main file (which requires the includes)
 *
 * NOTHING here modifies files outside tests/.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

/* ------------------------------------------------------------------ paths */

define('MHA_TEST_DIR', __DIR__);
define('MHA_PLUGIN_DIR', dirname(__DIR__));

// A throwaway WP "root" so the plugin's require_once ABSPATH.'wp-admin/...' works.
$__wproot = __DIR__ . '/.wproot/';
if (!is_dir($__wproot . 'wp-admin/includes')) {
    mkdir($__wproot . 'wp-admin/includes', 0777, true);
}
// dbDelta() is defined below; this file only needs to be requireable.
if (!file_exists($__wproot . 'wp-admin/includes/upgrade.php')) {
    file_put_contents($__wproot . 'wp-admin/includes/upgrade.php', "<?php\n// test stub; dbDelta is defined in bootstrap.php\n");
}

if (!defined('ABSPATH')) { define('ABSPATH', $__wproot); }

// $wpdb output-format constants.
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

// Low rate limit so the 429 test is cheap. Read at call-time by MHA_Auth.
if (!defined('MHA_RATE_PER_MIN')) { define('MHA_RATE_PER_MIN', 5); }

/* ------------------------------------------------------------- option store */

$GLOBALS['__options']    = [];
$GLOBALS['__transients'] = [];   // key => ['value'=>..,'expires'=>ts|0]
$GLOBALS['__actions']    = [];   // hook => [[cb,prio,args], ...]
$GLOBALS['__routes']     = [];   // [ns,route,args]
$GLOBALS['__activation'] = [];
$GLOBALS['__caps']       = [];   // capability => bool (current_user_can toggle)

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['__options']) ? $GLOBALS['__options'][$name] : $default;
}
function add_option($name, $value, $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($name, $GLOBALS['__options'])) { return false; }
    $GLOBALS['__options'][$name] = $value;
    return true;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['__options'][$name] = $value;
    return true;
}
function delete_option($name) { unset($GLOBALS['__options'][$name]); return true; }

/* ---------------------------------------------------------------- transients */

function get_transient($key) {
    if (!isset($GLOBALS['__transients'][$key])) { return false; }
    $t = $GLOBALS['__transients'][$key];
    if ($t['expires'] !== 0 && $t['expires'] < time()) {
        unset($GLOBALS['__transients'][$key]);
        return false;
    }
    return $t['value'];
}
function set_transient($key, $value, $ttl = 0) {
    $GLOBALS['__transients'][$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];
    return true;
}
function delete_transient($key) { unset($GLOBALS['__transients'][$key]); return true; }

/* ------------------------------------------------------------- hooks/filters */

function add_action($hook, $cb, $prio = 10, $args = 1) {
    $GLOBALS['__actions'][$hook][] = ['cb' => $cb, 'prio' => $prio, 'args' => $args];
    return true;
}
function add_filter($hook, $cb, $prio = 10, $args = 1) { return add_action($hook, $cb, $prio, $args); }

function do_action($hook, ...$a) {
    if (empty($GLOBALS['__actions'][$hook])) { return; }
    $list = $GLOBALS['__actions'][$hook];
    usort($list, function ($x, $y) { return $x['prio'] <=> $y['prio']; });
    foreach ($list as $entry) {
        $slice = array_slice($a, 0, (int) $entry['args']);
        call_user_func_array($entry['cb'], $slice);
    }
}
function apply_filters($hook, $value, ...$a) {
    if (empty($GLOBALS['__actions'][$hook])) { return $value; }
    $list = $GLOBALS['__actions'][$hook];
    usort($list, function ($x, $y) { return $x['prio'] <=> $y['prio']; });
    foreach ($list as $entry) {
        $params = array_merge([$value], $a);
        $params = array_slice($params, 0, max(1, (int) $entry['args']));
        $value = call_user_func_array($entry['cb'], $params);
    }
    return $value;
}

/* --------------------------------------------------------------- REST plumb */

function register_rest_route($ns, $route, $args) {
    $GLOBALS['__routes'][] = ['ns' => $ns, 'route' => $route, 'args' => $args];
    return true;
}
function register_activation_hook($file, $cb) { $GLOBALS['__activation'][] = $cb; return true; }
function __return_true() { return true; }

/* ------------------------------------------------------------ misc WP funcs */

function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function wp_generate_uuid4() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function current_time($type = 'mysql', $gmt = 0) {
    return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
}
function current_user_can($cap) { return !empty($GLOBALS['__caps'][$cap]); }
function sanitize_text_field($s) { return is_scalar($s) ? trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))) : ''; }
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
function __($t, $d = 'default') { return $t; }
function _e($t, $d = 'default') { echo $t; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_html__($t, $d = 'default') { return $t; }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_bloginfo($k = '') { return $k === 'version' ? '6.4.0' : ''; }
if (!function_exists('is_admin')) { function is_admin() { return false; } }
function get_post($id) { return null; }
function get_posts($args = []) { return []; }
function get_post_meta($id, $key, $single = false) { return $single ? '' : []; }

/* ------------------------------------------------------------- WP_Error etc */

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WP_REST_Request {
    private $method; private $params; private $headers; private $body; private $json;
    public function __construct($method = 'GET', $params = [], $headers = [], $body = '') {
        $this->method = strtoupper($method);
        $this->params = $params;
        $this->headers = [];
        foreach ($headers as $k => $v) { $this->headers[strtolower($k)] = $v; }
        $this->body = $body;
        $this->json = null;
    }
    public function get_method() { return $this->method; }
    public function set_param($k, $v) { $this->params[$k] = $v; }
    public function get_param($k) { return array_key_exists($k, $this->params) ? $this->params[$k] : null; }
    public function get_params() { return $this->params; }
    public function get_header($k) {
        $k = strtolower($k);
        return isset($this->headers[$k]) ? $this->headers[$k] : null;
    }
    public function set_header($k, $v) { $this->headers[strtolower($k)] = $v; }
    public function get_body() { return $this->body; }
    public function get_json_params() {
        if ($this->json === null) {
            $this->json = $this->body !== '' ? json_decode($this->body, true) : null;
        }
        return $this->json;
    }
}

class WP_REST_Response {
    private $data; private $status; private $headers = [];
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
    public function set_status($s) { $this->status = $s; }
    public function header($k, $v) { $this->headers[$k] = $v; return $this; }
    public function get_headers() { return $this->headers; }
}

/* --------------------------------------------------------------- dbDelta */

/**
 * Minimal dbDelta: translate the plugin's MySQL CREATE TABLE statements into
 * SQLite-compatible DDL and execute. Handles exactly the shapes in
 * MHA_Store::install() (AUTO_INCREMENT PK, UNIQUE KEY, KEY, UNSIGNED).
 */
function dbDelta($sql) {
    global $wpdb;
    if (!preg_match('/CREATE TABLE\s+([^\s(]+)\s*\(/i', $sql, $m)) { return; }
    $table = trim($m[1], '`');

    $body = substr($sql, strpos($sql, '(') + 1);
    $body = substr($body, 0, strrpos($body, ')'));
    $lines = preg_split('/\n/', $body);

    $cols = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        $ln = rtrim($ln, ',');
        if ($ln === '') { continue; }
        if (preg_match('/^PRIMARY KEY/i', $ln)) { continue; }          // folded into the id column
        if (preg_match('/^UNIQUE KEY\s+\S+\s*\((.+)\)$/i', $ln, $mm)) { $cols[] = 'UNIQUE (' . trim($mm[1]) . ')'; continue; }
        if (preg_match('/^KEY\s/i', $ln)) { continue; }                // plain indexes: not needed for tests
        $ln = preg_replace('/\bUNSIGNED\b/i', '', $ln);
        if (preg_match('/AUTO_INCREMENT/i', $ln)) {
            $colname = strtok($ln, " \t");
            $ln = $colname . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        $ln = preg_replace('/\s+/', ' ', trim($ln));
        $cols[] = $ln;
    }
    $create = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $cols) . ")";
    $wpdb->query($create);
}

/* --------------------------------------------------------------- $wpdb shim */

class MHA_WPDB_Shim {
    /** @var PDO */
    public $pdo;
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows_affected = 0;
    public $last_error = '';

    public function __construct(PDO $pdo, $prefix = 'wp_') {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function get_charset_collate() { return ''; }

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

    /** Faithful-enough prepare: inline %s/%d/%f with proper quoting (WP returns a SQL string). */
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        $pdo = $this->pdo;
        return preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args, $pdo) {
            $v = array_key_exists($i, $args) ? $args[$i] : null;
            $i++;
            switch ($m[0]) {
                case '%d': return (string) (int) $v;
                case '%f': return (string) (float) $v;
                default:   return $pdo->quote((string) $v); // %s
            }
        }, $query);
    }

    private function translate($sql) {
        // MySQL -> SQLite gaps the plugin actually hits.
        $sql = str_ireplace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
        $sql = preg_replace(
            "/SHOW\\s+TABLES\\s+LIKE\\s+('[^']*'|\"[^\"]*\")/i",
            "SELECT name FROM sqlite_master WHERE type='table' AND name=$1",
            $sql
        );
        return $sql;
    }

    public function get_results($sql, $output = 'ARRAY_A') {
        try {
            $stmt = $this->pdo->query($this->translate($sql));
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            $this->last_error = $e->getMessage();
            throw $e;
        }
    }

    public function get_row($sql, $output = 'ARRAY_A') {
        try {
            $stmt = $this->pdo->query($this->translate($sql));
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            $this->last_error = $e->getMessage();
            throw $e;
        }
    }

    public function get_var($sql) {
        try {
            $stmt = $this->pdo->query($this->translate($sql));
            $v = $stmt ? $stmt->fetchColumn(0) : null;
            return $v === false ? null : $v;
        } catch (\Throwable $e) {
            $this->last_error = $e->getMessage();
            throw $e;
        }
    }

    public function query($sql) {
        try {
            $sql = $this->translate($sql);
            $n = $this->pdo->exec($sql);
            $this->rows_affected = $n === false ? 0 : (int) $n;
            if (preg_match('/^\s*INSERT/i', $sql)) { $this->insert_id = (int) $this->pdo->lastInsertId(); }
            return $n === false ? 0 : $n;
        } catch (\Throwable $e) {
            $this->last_error = $e->getMessage();
            throw $e;
        }
    }

    public function insert($table, $data, $format = null) {
        $cols = array_keys($data);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO {$table} (" . implode(', ', $cols) . ") VALUES ({$ph})";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute(array_values($data));
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return $ok ? 1 : false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $set = [];
        $vals = [];
        foreach ($data as $k => $v) { $set[] = "{$k} = ?"; $vals[] = $v; }
        $cond = [];
        foreach ($where as $k => $v) { $cond[] = "{$k} = ?"; $vals[] = $v; }
        $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $cond);
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($vals);
        return $ok ? $stmt->rowCount() : false;
    }
}

/* ----------------------------------------------------- build DB + HBook stub */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['wpdb'] = new MHA_WPDB_Shim($pdo, 'wp_');

/**
 * Stub for HBook's native price calculator. Present so /pricing/quote can be
 * meaningfully tested; the db/utils/ajax natives are intentionally ABSENT so
 * live writes would resolve to write_disabled (we only test off/dry_run).
 */
if (!class_exists('HbPriceCalc')) {
    class HbPriceCalc {
        const PER_NIGHT = 500.0;
        public function get_price($accom_id, $check_in, $check_out, $adults, $children, &$breakdown = []) {
            $nights = max(0, (int) round((strtotime($check_out) - strtotime($check_in)) / 86400));
            $breakdown = ['nights' => $nights, 'per_night' => self::PER_NIGHT, 'adults' => (int) $adults, 'children' => (int) $children];
            return self::PER_NIGHT * $nights;
        }
    }
}

/* ------------------------------------------------- config: one resort, wp_ */

// Point the active resort at prefix wp_ so HBook reads hit the seeded wp_hb_* tables.
update_option('mha_resort_id', 'srdicko');
update_option('mha_config', ['srdicko' => ['hb_prefix' => 'wp_']]);

/* ---------------------------------------------------------- load the plugin */

require MHA_PLUGIN_DIR . '/matova-hbook-api.php';

// Create the plugin's own tables (idempotency/audit/events) via install() + dbDelta.
MHA_Store::install();

// Seed the HBook fixture tables.
require __DIR__ . '/fixtures/seed.php';

// Boot the plugin the way plugins_loaded would, then register routes.
do_action('plugins_loaded');
do_action('rest_api_init');
