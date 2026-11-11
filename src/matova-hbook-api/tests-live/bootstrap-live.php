<?php
/**
 * LIVE harness bootstrap: loads the REAL HBook 2.2.1 classes and runs them
 * against a real SQLite database through a WP + $wpdb shim strong enough to
 * execute HBook's own queries. Nothing is mocked: HbDataBaseActions,
 * HbPriceCalc and HbUtils are the genuine plugin classes.
 *
 * Writes nothing outside the matova-hbook-api plugin dir.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('MHA_TEST_DIR', __DIR__);
define('MHA_PLUGIN_DIR', dirname(__DIR__));
define('HBOOK_DIR', dirname(dirname(dirname(__DIR__))) . '/hbook-plugins/hbook');

if (!defined('ABSPATH')) {
    $__wproot = __DIR__ . '/.wproot/';
    if (!is_dir($__wproot . 'wp-admin/includes')) { mkdir($__wproot . 'wp-admin/includes', 0777, true); }
    if (!file_exists($__wproot . 'wp-admin/includes/upgrade.php')) {
        file_put_contents($__wproot . 'wp-admin/includes/upgrade.php', "<?php\n// dbDelta defined in bootstrap\n");
    }
    define('ABSPATH', $__wproot);
}
if (!defined('WPINC')) { define('WPINC', 'wp-includes'); } // HBook top-level guard
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }
if (!defined('MHA_RATE_PER_MIN')) { define('MHA_RATE_PER_MIN', 500); }

/* ------------------------------------------------------------- option store */

$GLOBALS['__options']    = [];
$GLOBALS['__transients'] = [];
$GLOBALS['__actions']    = [];
$GLOBALS['__routes']     = [];
$GLOBALS['__activation'] = [];
$GLOBALS['__caps']       = [];
$GLOBALS['__postmeta']   = [];   // [post_id][key] => value
$GLOBALS['__mail']       = [];   // captured wp_mail() calls

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['__options']) ? $GLOBALS['__options'][$name] : $default;
}
function add_option($name, $value, $d = '', $a = 'yes') {
    if (array_key_exists($name, $GLOBALS['__options'])) { return false; }
    $GLOBALS['__options'][$name] = $value; return true;
}
function update_option($name, $value, $a = null) { $GLOBALS['__options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['__options'][$name]); return true; }

function get_transient($k) {
    if (!isset($GLOBALS['__transients'][$k])) { return false; }
    $t = $GLOBALS['__transients'][$k];
    if ($t['expires'] !== 0 && $t['expires'] < time()) { unset($GLOBALS['__transients'][$k]); return false; }
    return $t['value'];
}
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = ['value' => $v, 'expires' => $ttl > 0 ? time() + $ttl : 0]; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }

/* ------------------------------------------------------------- hooks/filters */

$GLOBALS['__hook_log'] = []; // ordered record of fired hooks for assertions

function add_action($hook, $cb, $prio = 10, $args = 1) {
    $GLOBALS['__actions'][$hook][] = ['cb' => $cb, 'prio' => $prio, 'args' => $args];
    return true;
}
function add_filter($hook, $cb, $prio = 10, $args = 1) { return add_action($hook, $cb, $prio, $args); }
function remove_action($hook, $cb, $prio = 10) { return true; }

function do_action($hook, ...$a) {
    $GLOBALS['__hook_log'][] = ['hook' => $hook, 'args' => $a];
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

/** Count how many times a hook fired (optionally with a matching first arg). */
function hb_test_hook_count($hook, $arg0 = '__ANY__', $arg1 = '__ANY__') {
    $n = 0;
    foreach ($GLOBALS['__hook_log'] as $e) {
        if ($e['hook'] !== $hook) { continue; }
        if ($arg0 !== '__ANY__' && (!isset($e['args'][0]) || $e['args'][0] !== $arg0)) { continue; }
        if ($arg1 !== '__ANY__' && (!isset($e['args'][1]) || $e['args'][1] !== $arg1)) { continue; }
        $n++;
    }
    return $n;
}
function hb_test_hook_reset() { $GLOBALS['__hook_log'] = []; }

/* --------------------------------------------------------------- REST plumb */

function register_rest_route($ns, $route, $args) { $GLOBALS['__routes'][] = ['ns' => $ns, 'route' => $route, 'args' => $args]; return true; }
function register_activation_hook($f, $cb) { $GLOBALS['__activation'][] = $cb; return true; }
function register_deactivation_hook($f, $cb) { return true; }
function __return_true() { return true; }

/* ------------------------------------------------------------ misc WP funcs */

function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/'; }
function plugins_url($path = '', $plugin = '') { return 'https://example.test/wp-content/plugins' . $path; }
function site_url($path = '') { return 'https://example.test' . $path; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function get_locale() { return 'en_US'; }
function get_user_locale() { return 'en_US'; }
function wp_generate_uuid4() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function current_time($type = 'mysql', $gmt = 0) {
    if ($type === 'timestamp') { return time(); }
    if ($type === 'mysql') { return gmdate('Y-m-d H:i:s'); }
    return gmdate($type);
}
function current_user_can($cap) { return !empty($GLOBALS['__caps'][$cap]); }
function sanitize_text_field($s) { return is_scalar($s) ? trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))) : ''; }
function sanitize_textarea_field($s) { return is_scalar($s) ? trim(strip_tags((string) $s)) : ''; }
function sanitize_email($s) { return filter_var((string) $s, FILTER_SANITIZE_EMAIL); }
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function stripslashes_deep($v) { return is_string($v) ? stripslashes($v) : $v; }
function __($t, $d = 'default') { return $t; }
function _e($t, $d = 'default') { echo $t; }
function _n($s, $p, $n, $d = 'default') { return $n == 1 ? $s : $p; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_html__($t, $d = 'default') { return $t; }
function esc_html_e($t, $d = 'default') { echo $t; }
function esc_attr__($t, $d = 'default') { return $t; }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function get_bloginfo($k = '') { return $k === 'version' ? '6.4.0' : ''; }
if (!function_exists('is_admin')) { function is_admin() { return false; } } // harness runs as front-end; ensure_installed() is invoked explicitly below
function number_format_i18n($n, $dec = 0) { return number_format((float) $n, (int) $dec, '.', ' '); }
function wpautop($s) { return $s; }
function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
    $GLOBALS['__mail'][] = compact('to', 'subject', 'message', 'headers', 'attachments');
    return true; // captured, never sent
}
function wp_kses_post($s) { return $s; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function get_option_or($n, $d) { return get_option($n, $d); }
function load_plugin_textdomain() { return true; }

if (!defined('DEBUG_BACKTRACE_IGNORE_ARGS')) { define('DEBUG_BACKTRACE_IGNORE_ARGS', 2); }
if (!defined('DEBUG_BACKTRACE_PROVIDE_OBJECT')) { define('DEBUG_BACKTRACE_PROVIDE_OBJECT', 1); }

/* -------------------------------------------------- posts / postmeta stubs */

function get_post_meta($id, $key, $single = false) {
    $id = (int) $id;
    if (isset($GLOBALS['__postmeta'][$id][$key])) {
        $v = $GLOBALS['__postmeta'][$id][$key];
        return $single ? $v : [$v];
    }
    return $single ? '' : [];
}
function update_post_meta($id, $key, $value) { $GLOBALS['__postmeta'][(int) $id][$key] = $value; return true; }

function get_post($id) {
    global $wpdb;
    $id = (int) $id;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}posts WHERE ID = %d", $id), ARRAY_A);
    if (!$row) { return null; }
    return (object) $row;
}
function get_posts($args = []) {
    global $wpdb;
    $type = isset($args['post_type']) ? $args['post_type'] : 'post';
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}posts WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", $type),
        ARRAY_A
    );
    $out = [];
    foreach ((array) $rows as $r) { $out[] = (object) $r; }
    return $out;
}
function get_post_type($id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->prefix}posts WHERE ID = %d", (int) $id));
}
function get_the_title($id) {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->prefix}posts WHERE ID = %d", (int) $id));
}

/* ------------------------------------------------------------- WP_Error etc */

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WP_REST_Request {
    private $method; private $params; private $headers; private $body; private $json;
    public function __construct($method = 'GET', $params = [], $headers = [], $body = '') {
        $this->method = strtoupper($method); $this->params = $params; $this->headers = [];
        foreach ($headers as $k => $v) { $this->headers[strtolower($k)] = $v; }
        $this->body = $body; $this->json = null;
    }
    public function get_method() { return $this->method; }
    public function set_param($k, $v) { $this->params[$k] = $v; }
    public function get_param($k) { return array_key_exists($k, $this->params) ? $this->params[$k] : null; }
    public function get_params() { return $this->params; }
    public function get_header($k) { $k = strtolower($k); return isset($this->headers[$k]) ? $this->headers[$k] : null; }
    public function set_header($k, $v) { $this->headers[strtolower($k)] = $v; }
    public function get_body() { return $this->body; }
    public function get_json_params() { if ($this->json === null) { $this->json = $this->body !== '' ? json_decode($this->body, true) : null; } return $this->json; }
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

function dbDelta($sql) {
    global $wpdb;
    // HBook ships a multi-statement schema; split on CREATE TABLE boundaries.
    $statements = preg_split('/;\s*(?=CREATE TABLE|$)/i', $sql);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || !preg_match('/CREATE TABLE/i', $stmt)) { continue; }
        hb_sqlite_create_table($wpdb, $stmt);
    }
}

/** Translate one MySQL CREATE TABLE into SQLite DDL and execute. */
function hb_sqlite_create_table($wpdb, $sql) {
    if (!preg_match('/CREATE TABLE\s+([^\s(]+)\s*\(/i', $sql, $m)) { return; }
    $table = trim($m[1], '`');
    $body = substr($sql, strpos($sql, '(') + 1);
    $body = substr($body, 0, strrpos($body, ')'));
    $lines = preg_split('/,\s*\n/', $body);

    $cols = [];
    $pk = [];
    foreach ($lines as $ln) {
        $ln = trim(rtrim(trim($ln), ','));
        if ($ln === '') { continue; }
        if (preg_match('/^PRIMARY KEY\s*\((.+)\)$/i', $ln, $mm)) { $pk = array_map('trim', explode(',', $mm[1])); continue; }
        if (preg_match('/^UNIQUE KEY\s+\S+\s*\((.+)\)$/i', $ln, $mm)) { $cols[] = 'UNIQUE (' . trim($mm[1]) . ')'; continue; }
        if (preg_match('/^(KEY|INDEX)\s/i', $ln)) { continue; }
        // column def: normalize MySQL types -> SQLite affinities
        $ln = preg_replace('/\bUNSIGNED\b/i', '', $ln);
        // HBook declares most columns NOT NULL but create_resa() etc. insert
        // only a subset; MySQL fills the rest from implicit defaults. SQLite is
        // strict, so drop NOT NULL to reproduce MySQL's lenient insert.
        if (!preg_match('/AUTO_INCREMENT/i', $ln)) { $ln = preg_replace('/\bNOT NULL\b/i', '', $ln); }
        $ln = preg_replace('/\b(bigint|smallint|tinyint|int)\s*\(\s*\d+\s*\)/i', 'INTEGER', $ln);
        $ln = preg_replace('/\bdecimal\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $ln);
        $ln = preg_replace('/\b(datetime|date)\b/i', 'TEXT', $ln);
        $ln = preg_replace('/\b(longtext|text|varchar\s*\(\s*\d+\s*\))\b/i', 'TEXT', $ln);
        if (preg_match('/AUTO_INCREMENT/i', $ln)) {
            $colname = trim(strtok($ln, " \t"), '`');
            $ln = $colname . ' INTEGER PRIMARY KEY AUTOINCREMENT';
            $pk = []; // folded
        }
        $ln = preg_replace('/\bDEFAULT NULL\b/i', 'DEFAULT NULL', $ln);
        $ln = preg_replace('/\s+/', ' ', trim($ln));
        $cols[] = $ln;
    }
    // Composite PK (no AUTO_INCREMENT): emit a PRIMARY KEY clause.
    if (!empty($pk)) {
        $has_autopk = false;
        foreach ($cols as $c) { if (stripos($c, 'AUTOINCREMENT') !== false) { $has_autopk = true; break; } }
        if (!$has_autopk) { $cols[] = 'PRIMARY KEY (' . implode(', ', $pk) . ')'; }
    }
    $create = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $cols) . ")";
    $wpdb->query($create);
}

/* --------------------------------------------------------------- $wpdb shim */

// Some legacy mu-plugin helpers type-hint the global as `wpdb` (e.g.
// hbprice_find_rule(wpdb $wpdb, ...)). WP is not loaded here, so provide an
// empty base class and let the shim extend it, satisfying those hints without
// changing any shim behavior.
if (!class_exists('wpdb')) { class wpdb {} }

class MHA_WPDB_Shim extends wpdb {
    public $pdo;
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows_affected = 0;
    public $last_error = '';
    public $last_query = '';
    public $charset = '';
    public $collate = '';

    public function __construct(PDO $pdo, $prefix = 'wp_') { $this->pdo = $pdo; $this->prefix = $prefix; }

    public function get_charset_collate() { return ''; }
    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

    /** Faithful WP prepare: strip quotes WP would add around placeholders, inline %s/%d/%f. */
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        // WP strips a single pair of surrounding quotes from quoted placeholders.
        $query = preg_replace('/([\'"])%([sdf])\1/', '%$2', $query);
        $i = 0; $pdo = $this->pdo;
        return preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args, $pdo) {
            $v = array_key_exists($i, $args) ? $args[$i] : null; $i++;
            switch ($m[0]) {
                case '%d': return (string) (int) $v;
                case '%f': return (string) (float) $v;
                default:   return $pdo->quote((string) $v);
            }
        }, $query);
    }

    /** MySQL -> SQLite rewrites the real HBook queries hit on the tested paths. */
    private function translate($sql) {
        $sql = str_ireplace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
        // SHOW TABLES LIKE '<name>'  ->  sqlite_master lookup (table-existence check)
        $sql = preg_replace(
            "/SHOW\\s+TABLES\\s+LIKE\\s+('[^']*'|\"[^\"]*\")/i",
            "SELECT name FROM sqlite_master WHERE type='table' AND name=$1",
            $sql
        );
        // GROUP_CONCAT( [DISTINCT] <col> ORDER BY <col> )  -> GROUP_CONCAT( [DISTINCT] <col> )
        $sql = preg_replace(
            '/GROUP_CONCAT\(\s*(DISTINCT\s+)?([A-Za-z0-9_.`]+)\s+ORDER\s+BY\s+[A-Za-z0-9_.`]+\s*\)/i',
            'GROUP_CONCAT($1$2)',
            $sql
        );
        return $sql;
    }

    public function get_results($sql, $output = 'ARRAY_A') {
        $this->last_query = $sql;
        try { $stmt = $this->pdo->query($this->translate($sql)); return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []; }
        catch (\Throwable $e) { $this->last_error = $e->getMessage(); throw $e; }
    }
    public function get_row($sql, $output = 'ARRAY_A') {
        $this->last_query = $sql;
        try { $stmt = $this->pdo->query($this->translate($sql)); $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false; return $row === false ? null : $row; }
        catch (\Throwable $e) { $this->last_error = $e->getMessage(); throw $e; }
    }
    public function get_var($sql) {
        $this->last_query = $sql;
        try { $stmt = $this->pdo->query($this->translate($sql)); $v = $stmt ? $stmt->fetchColumn(0) : null; return $v === false ? null : $v; }
        catch (\Throwable $e) { $this->last_error = $e->getMessage(); throw $e; }
    }
    public function query($sql) {
        $this->last_query = $sql;
        try {
            $sql = $this->translate($sql);
            $n = $this->pdo->exec($sql);
            $this->rows_affected = $n === false ? 0 : (int) $n;
            if (preg_match('/^\s*INSERT/i', $sql)) { $this->insert_id = (int) $this->pdo->lastInsertId(); }
            return $n === false ? 0 : $n;
        } catch (\Throwable $e) { $this->last_error = $e->getMessage(); throw $e; }
    }
    public function insert($table, $data, $format = null) {
        $cols = array_keys($data);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO {$table} (`" . implode('`, `', $cols) . "`) VALUES ({$ph})";
        $this->last_query = $sql;
        try {
            $stmt = $this->pdo->prepare($sql);
            $ok = $stmt->execute(array_values($data));
            $this->insert_id = (int) $this->pdo->lastInsertId();
            return $ok ? 1 : false;
        } catch (\Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function update($table, $data, $where, $format = null, $where_format = null) {
        $set = []; $vals = [];
        foreach ($data as $k => $v) { $set[] = "`{$k}` = ?"; $vals[] = $v; }
        $cond = [];
        foreach ($where as $k => $v) { $cond[] = "`{$k}` = ?"; $vals[] = $v; }
        $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $cond);
        $this->last_query = $sql;
        try { $stmt = $this->pdo->prepare($sql); $ok = $stmt->execute($vals); return $ok ? $stmt->rowCount() : false; }
        catch (\Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function delete($table, $where, $where_format = null) {
        $cond = []; $vals = [];
        foreach ($where as $k => $v) { $cond[] = "`{$k}` = ?"; $vals[] = $v; }
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $cond);
        $this->last_query = $sql;
        try { $stmt = $this->pdo->prepare($sql); $ok = $stmt->execute($vals); return $ok ? $stmt->rowCount() : false; }
        catch (\Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
}

/* ------------------------------------------------- build DB + load real HBook */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['wpdb'] = new MHA_WPDB_Shim($pdo, 'wp_');

// Load the REAL HBook classes we exercise (order matters for dependencies).
require_once HBOOK_DIR . '/database-actions/database-actions.php';
require_once HBOOK_DIR . '/database-actions/database-schema.php';
require_once HBOOK_DIR . '/utils/utils.php';
require_once HBOOK_DIR . '/utils/price-calc.php';
require_once HBOOK_DIR . '/front-end/front-end-ajax-actions.php';

/* ------------------------------------------------- config: resort -> wp_ */

update_option('mha_resort_id', 'srdicko');
update_option('mha_config', ['srdicko' => ['hb_prefix' => 'wp_']]);
// HBook option defaults relevant to tested paths.
update_option('hb_currency', 'CZK');
update_option('hb_price_precision', 'decimals');
update_option('hb_charge_per_day', 'no');
update_option('hb_resa_paid_has_confirmation', 'no');

/* ---------------------------------------------------------- load the plugin */

require MHA_PLUGIN_DIR . '/matova-hbook-api.php';

MHA_Store::install();

// Build the authentic HBook schema + realistic dataset.
require __DIR__ . '/seed-live.php';

// Boot the plugin (subscribe outbox hooks + register routes).
do_action('plugins_loaded');
do_action('rest_api_init');
