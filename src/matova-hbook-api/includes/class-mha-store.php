<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Own-table persistence for matova/v2: idempotency, audit, outbox (events).
 * Tables created on activation via dbDelta (CONTRACTS §5).
 *
 * All reads use $wpdb->prepare. All tables live under the WP table prefix
 * (NOT the HBook prefix) — these are the plugin's own tables.
 */
class MHA_Store {

    /** Bump when any table DDL changes so ensure_installed() re-runs dbDelta. */
    const SCHEMA_VERSION = '3';
    const SCHEMA_OPT = 'mha_schema_version';

    /** Debounce set for the no-arg hb_reservations_updated storm (per request). */
    private static $debounce = [];

    /**
     * Idempotently ensure the plugin's own tables exist, version-gated so the
     * cost is a single option read on the normal path. REQUIRED because the
     * production deploy mechanism is a zip-overwrite of an already-active
     * plugin, which never fires register_activation_hook — so install() alone
     * would leave idempotency/audit/events silently absent and every insert a
     * no-op. Safe to call on every boot.
     */
    public static function ensure_installed() {
        if (get_option(self::SCHEMA_OPT) === self::SCHEMA_VERSION) { return; }
        self::install();
        update_option(self::SCHEMA_OPT, self::SCHEMA_VERSION, true);
    }

    public static function table_idempotency() { global $wpdb; return $wpdb->prefix . 'mha_idempotency'; }
    public static function table_audit()       { global $wpdb; return $wpdb->prefix . 'mha_audit'; }
    public static function table_events()      { global $wpdb; return $wpdb->prefix . 'mha_events'; }

    /** Create/upgrade the three tables. Activation hook target. */
    public static function install() {
        global $wpdb;

        $idem = self::table_idempotency();
        $audit = self::table_audit();
        $events = self::table_events();

        // Skip entirely when all three tables already exist — avoids loading the
        // heavy wp-admin/includes/upgrade.php and running dbDelta needlessly.
        $have = function ($t) use ($wpdb) {
            return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
        };
        if ($have($idem) && $have($audit) && $have($events)) { return; }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_idem = "CREATE TABLE {$idem} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            key_hash CHAR(64) NOT NULL,
            route VARCHAR(191) NOT NULL,
            resort_id VARCHAR(32) NOT NULL,
            request_sha CHAR(64) NOT NULL,
            response_json LONGTEXT NULL,
            http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY key_hash (key_hash)
        ) {$charset_collate};";

        $sql_audit = "CREATE TABLE {$audit} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created DATETIME NOT NULL,
            request_id VARCHAR(64) NOT NULL,
            key_id VARCHAR(64) NULL,
            consumer VARCHAR(128) NULL,
            scope VARCHAR(32) NULL,
            resort_id VARCHAR(32) NULL,
            route VARCHAR(191) NULL,
            method VARCHAR(10) NULL,
            http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            target_type VARCHAR(64) NULL,
            target_id VARCHAR(64) NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            ip VARCHAR(64) NULL,
            PRIMARY KEY  (id),
            KEY request_id (request_id),
            KEY resort_route (resort_id, route),
            KEY created (created)
        ) {$charset_collate};";

        $sql_events = "CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created DATETIME NOT NULL,
            type VARCHAR(64) NOT NULL,
            resort_id VARCHAR(32) NOT NULL,
            entity VARCHAR(64) NULL,
            entity_id VARCHAR(64) NULL,
            payload_json LONGTEXT NULL,
            seq BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY entity (entity, entity_id),
            KEY created (created)
        ) {$charset_collate};";

        dbDelta($sql_idem);
        dbDelta($sql_audit);
        dbDelta($sql_events);
    }

    /* ---------------------------------------------------------------- idempotency */

    /** A reserved-but-unfinished idempotency row older than this is treated as
     *  abandoned (the original request died mid-write), so a retry may proceed
     *  instead of being locked out forever. */
    const IDEM_INFLIGHT_TTL = 120; // seconds

    /**
     * Begin an idempotent mutation. Atomic against concurrent duplicates: the key
     * is reserved with INSERT IGNORE and we proceed ONLY if THIS request won the
     * insert — so two simultaneous identical writes can never both execute the
     * native mutation (prevents duplicate payments / reservations on retry races).
     *   - array ['replay'=>true,...]  — verbatim replay of a finished response
     *   - 'conflict'                   — same key, different request body
     *   - 'in_progress'               — a concurrent duplicate is still running
     *   - null                         — safe to proceed (this request owns the key)
     */
    public static function idempotency_begin($key, $route, $resort, $request_sha) {
        global $wpdb;
        $t = self::table_idempotency();
        $key_hash = hash('sha256', (string) $key);

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT request_sha, response_json, http_status, created FROM {$t} WHERE key_hash = %s", $key_hash),
            ARRAY_A
        );

        if ($row) {
            return self::idem_decide($row, $request_sha, $key_hash);
        }

        // Reserve the key. INSERT IGNORE collides on the UNIQUE key_hash index, so
        // exactly one concurrent request gets rows-affected == 1 (the owner).
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$t} (key_hash, route, resort_id, request_sha, response_json, http_status, created)
                 VALUES (%s, %s, %s, %s, NULL, 0, %s)",
                $key_hash, $route, $resort, $request_sha, gmdate('Y-m-d H:i:s')
            )
        );
        $won = (int) $wpdb->rows_affected === 1;
        if ($won) {
            return null; // we own the key — proceed with the single authoritative write
        }

        // Lost the insert race to a concurrent request with the same key.
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT request_sha, response_json, http_status, created FROM {$t} WHERE key_hash = %s", $key_hash),
            ARRAY_A
        );
        if (!$row) { return 'in_progress'; } // vanished mid-flight; make the caller retry
        return self::idem_decide($row, $request_sha, $key_hash);
    }

    /** Classify an existing idempotency row: conflict / replay / in_progress / proceed-if-abandoned. */
    private static function idem_decide(array $row, $request_sha, $key_hash) {
        if ((string) $row['request_sha'] !== (string) $request_sha) {
            return 'conflict';
        }
        $finished = ((int) $row['http_status'] !== 0) || ($row['response_json'] !== null);
        if ($finished) {
            return [
                'replay'        => true,
                'response_json' => $row['response_json'],
                'http_status'   => (int) $row['http_status'],
            ];
        }
        // Reserved but not finished. If the reservation is stale the original write
        // almost certainly died mid-request; reclaim the key so the retry can proceed.
        $age = time() - strtotime((string) $row['created'] . ' UTC');
        if ($age >= self::IDEM_INFLIGHT_TTL) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE " . self::table_idempotency() . " SET created = %s WHERE key_hash = %s",
                gmdate('Y-m-d H:i:s'), $key_hash
            ));
            return null; // reclaimed — proceed
        }
        return 'in_progress';
    }

    /** Store the final response for an in-flight idempotency key. */
    public static function idempotency_finish($key, $response_json, $http) {
        global $wpdb;
        $t = self::table_idempotency();
        $key_hash = hash('sha256', (string) $key);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$t} SET response_json = %s, http_status = %d WHERE key_hash = %s",
                $response_json, (int) $http, $key_hash
            )
        );
    }

    /* ---------------------------------------------------------------- audit */

    /** Insert one audit row. $row keys mirror the table columns (minus id). */
    public static function audit(array $row) {
        global $wpdb;
        $t = self::table_audit();
        $data = [
            'created'     => isset($row['created']) ? $row['created'] : gmdate('Y-m-d H:i:s'),
            'request_id'  => isset($row['request_id']) ? (string) $row['request_id'] : '',
            'key_id'      => isset($row['key_id']) ? (string) $row['key_id'] : null,
            'consumer'    => isset($row['consumer']) ? (string) $row['consumer'] : null,
            'scope'       => isset($row['scope']) ? (string) $row['scope'] : null,
            'resort_id'   => isset($row['resort_id']) ? (string) $row['resort_id'] : null,
            'route'       => isset($row['route']) ? (string) $row['route'] : null,
            'method'      => isset($row['method']) ? (string) $row['method'] : null,
            'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : 0,
            'target_type' => isset($row['target_type']) ? (string) $row['target_type'] : null,
            'target_id'   => isset($row['target_id']) ? (string) $row['target_id'] : null,
            'before_json' => isset($row['before_json']) ? self::as_json($row['before_json']) : null,
            'after_json'  => isset($row['after_json']) ? self::as_json($row['after_json']) : null,
            'ip'          => isset($row['ip']) ? (string) $row['ip'] : self::client_ip(),
        ];
        $wpdb->insert($t, $data);
        return (int) $wpdb->insert_id;
    }

    private static function as_json($v) {
        if ($v === null) { return null; }
        if (is_string($v)) { return $v; }
        return wp_json_encode($v);
    }

    private static function client_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
    }

    /* ---------------------------------------------------------------- outbox / events */

    /** Append one outbox event. */
    public static function outbox_emit($type, $resort, $entity, $entity_id, $payload) {
        global $wpdb;
        $t = self::table_events();
        $wpdb->insert($t, [
            'created'      => gmdate('Y-m-d H:i:s'),
            'type'         => (string) $type,
            'resort_id'    => (string) $resort,
            'entity'       => $entity !== null ? (string) $entity : null,
            'entity_id'    => $entity_id !== null ? (string) $entity_id : null,
            'payload_json' => self::as_json($payload),
            'seq'          => null,
        ]);
        $id = (int) $wpdb->insert_id;
        // seq mirrors id for pull-sync ordering (external field name stays "cursor").
        if ($id) {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET seq = %d WHERE id = %d", $id, $id));
        }
        return $id;
    }

    /** Ordered events with id > $cursor, oldest first. */
    public static function events_since($cursor, $limit = 100) {
        global $wpdb;
        $t = self::table_events();
        $cursor = (int) $cursor;
        $limit = (int) $limit;
        if ($limit < 1) { $limit = 1; }
        if ($limit > 500) { $limit = 500; }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created, type, resort_id, entity, entity_id, payload_json, seq AS `cursor`
                 FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $cursor, $limit
            ),
            ARRAY_A
        );
        if (!is_array($rows)) { $rows = []; }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['cursor'] = (int) $r['cursor'];
            $r['payload'] = $r['payload_json'] !== null ? json_decode($r['payload_json'], true) : null;
            unset($r['payload_json']);
        }
        unset($r);
        return $rows;
    }

    /** Max event id currently stored (for /meta last-event-id). */
    public static function last_event_id() {
        global $wpdb;
        $t = self::table_events();
        $max = $wpdb->get_var("SELECT MAX(id) FROM {$t}");
        return $max !== null ? (int) $max : 0;
    }

    /* ---------------------------------------------------------------- HBook hook bridge */

    /**
     * Subscribe to HBook's hooks and translate each into an outbox event.
     * Hooks (CONTRACTS §5):
     *   hb_create_reservation, hb_reservations_updated (no-arg, debounced),
     *   hb_reservation_updated(what,id), hb_parent_reservation_updated,
     *   hb_blocked_accom_updated, hb_parent_reservation_cancelled.
     */
    public static function subscribe_hooks() {
        if (!function_exists('add_action')) { return; }

        // REAL hook (verified HBook 2.2.1): do_action('hb_create_reservation', $resa_info)
        // passes the reservation INFO array, NOT an id (the row id is not known to
        // this hook). Casting it to an entity_id would store "Array"; instead we
        // carry the info in the payload and leave entity_id null. The paired
        // hb_reservations_updated fires straight after for cursor movement.
        add_action('hb_create_reservation', function ($resa_info = null) {
            $payload = is_array($resa_info) ? $resa_info : ['value' => $resa_info];
            MHA_Store::emit_hook('reservation.created', 'reservation', null, $payload);
        }, 10, 1);

        // No-arg storm hook — debounce to a single event per request.
        add_action('hb_reservations_updated', function () {
            MHA_Store::emit_hook('reservations.updated', 'reservations', null, [], true);
        }, 10, 0);

        add_action('hb_reservation_updated', function ($what = null, $id = null) {
            MHA_Store::emit_hook('reservation.updated', 'reservation', $id, ['what' => $what, 'id' => $id]);
        }, 10, 2);

        add_action('hb_parent_reservation_updated', function ($id = null) {
            MHA_Store::emit_hook('parent_reservation.updated', 'parent_reservation', $id, ['id' => $id]);
        }, 10, 1);

        add_action('hb_blocked_accom_updated', function ($id = null) {
            MHA_Store::emit_hook('blocked_accom.updated', 'blocked_accom', $id, ['id' => $id]);
        }, 10, 1);

        add_action('hb_parent_reservation_cancelled', function ($id = null) {
            MHA_Store::emit_hook('parent_reservation.cancelled', 'parent_reservation', $id, ['id' => $id]);
        }, 10, 1);
    }

    /**
     * Shared hook handler: emit an outbox event, with per-request debounce
     * guarding against the no-arg hb_reservations_updated storm.
     */
    public static function emit_hook($type, $entity, $entity_id, $payload, $debounce = false) {
        $sig = $type . '|' . ($entity_id === null ? '*' : $entity_id);
        if ($debounce) {
            if (isset(self::$debounce[$sig])) { return; }
            self::$debounce[$sig] = true;
        }
        $resort = MHA_Config::resort_id();
        self::outbox_emit($type, $resort, $entity, $entity_id, $payload);
    }
}
