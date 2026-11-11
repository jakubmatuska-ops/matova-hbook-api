<?php
if (!defined('ABSPATH')) { exit; }

/**
 * MHA_Admin — wp-admin control surface (Settings → Matova API).
 *
 * Purpose: make the plugin fully operable WITHOUT WP-CLI or temporary file-writer
 * snippets. An administrator can view config, flip per-kind write modes
 * (off/dry_run/live), and issue/revoke API keys from the dashboard. This is the
 * supported production control path on hosting without SSH/WP-CLI (e.g. WEDOS).
 *
 * Security: manage_options only; every mutation is nonce-checked; raw keys are
 * shown exactly once and never stored or re-displayed.
 */
class MHA_Admin {

    const KINDS = ['status', 'note', 'block', 'payment', 'guest', 'create', 'modify'];
    const MODES = ['off', 'dry_run', 'live'];
    const CAP   = 'manage_options';

    public static function boot() {
        add_action('admin_menu', ['MHA_Admin', 'menu']);
    }

    public static function menu() {
        add_options_page('Matova API', 'Matova API', self::CAP, 'matova-api', ['MHA_Admin', 'render']);
    }

    /** One transient raw key to show once after issuance. */
    private static function flash_key($set = null) {
        if ($set !== null) { set_transient('mha_admin_flash_key', $set, 60); return; }
        $v = get_transient('mha_admin_flash_key');
        if ($v !== false) { delete_transient('mha_admin_flash_key'); }
        return $v;
    }

    private static function handle_post() {
        if (empty($_POST['mha_action'])) { return; }
        if (!current_user_can(self::CAP)) { wp_die('Insufficient permissions.'); }
        check_admin_referer('mha_admin');

        $action = sanitize_text_field(wp_unslash($_POST['mha_action']));

        if ($action === 'write_modes') {
            $modes = get_option('mha_write_mode', []);
            if (!is_array($modes)) { $modes = []; }
            foreach (self::KINDS as $k) {
                $v = isset($_POST['mode'][$k]) ? sanitize_text_field(wp_unslash($_POST['mode'][$k])) : 'off';
                $modes[$k] = in_array($v, self::MODES, true) ? $v : 'off';
            }
            update_option('mha_write_mode', $modes, false);
            add_settings_error('mha', 'saved', 'Write modes saved.', 'updated');

        } elseif ($action === 'all_off') {
            $modes = get_option('mha_write_mode', []);
            if (!is_array($modes)) { $modes = []; }
            foreach (self::KINDS as $k) { $modes[$k] = 'off'; }
            $modes['default'] = 'off';
            update_option('mha_write_mode', $modes, false);
            add_settings_error('mha', 'off', 'All write modes set to OFF (kill switch).', 'updated');

        } elseif ($action === 'issue_key') {
            $consumer = sanitize_text_field(wp_unslash($_POST['consumer'] ?? ''));
            $scope    = sanitize_text_field(wp_unslash($_POST['scope'] ?? 'read'));
            $resort   = sanitize_text_field(wp_unslash($_POST['resort'] ?? MHA_Config::resort_id()));
            if ($consumer === '') {
                add_settings_error('mha', 'needc', 'Consumer is required.', 'error');
            } else {
                $raw = MHA_Auth::issue_key($consumer, $scope, $resort, '');
                if (is_wp_error($raw)) {
                    add_settings_error('mha', 'keyerr', $raw->get_error_message(), 'error');
                } else {
                    self::flash_key($raw);
                    add_settings_error('mha', 'keyok', 'Key issued — copy it now; it will not be shown again.', 'updated');
                }
            }

        } elseif ($action === 'revoke_key') {
            $id = sanitize_text_field(wp_unslash($_POST['key_id'] ?? ''));
            MHA_Auth::revoke_key($id);
            add_settings_error('mha', 'revoked', 'Key revoked.', 'updated');
        }
    }

    public static function render() {
        if (!current_user_can(self::CAP)) { wp_die('Insufficient permissions.'); }
        self::handle_post();

        $modes   = get_option('mha_write_mode', []);
        if (!is_array($modes)) { $modes = []; }
        $resort  = MHA_Config::resort_id();
        $keys    = get_option(MHA_Auth::OPT_KEYS, []);
        if (!is_array($keys)) { $keys = []; }
        $flash   = self::flash_key();
        $ver     = defined('MHA_VERSION') ? MHA_VERSION : '';

        echo '<div class="wrap"><h1>Matova HBook API <code style="font-size:12px">v' . esc_html($ver) . '</code></h1>';
        settings_errors('mha');
        echo '<p>Resort: <strong>' . esc_html($resort) . '</strong> · Namespace: <code>' . esc_html(defined('MHA_NS') ? MHA_NS : 'matova/v2') . '</code></p>';

        if ($flash) {
            echo '<div class="notice notice-warning"><p><strong>New API key (shown once):</strong><br><code style="user-select:all;font-size:14px">' . esc_html($flash) . '</code></p></div>';
        }

        // Write modes
        echo '<h2>Write modes</h2><p>Gate per operation kind. <code>off</code> rejects writes, <code>dry_run</code> returns the computed effect without touching HBook, <code>live</code> performs the real mutation.</p>';
        echo '<form method="post">';
        wp_nonce_field('mha_admin');
        echo '<input type="hidden" name="mha_action" value="write_modes">';
        echo '<table class="form-table"><tbody>';
        foreach (self::KINDS as $k) {
            $cur = MHA_Config::write_mode($k);
            echo '<tr><th scope="row">' . esc_html($k) . '</th><td><select name="mode[' . esc_attr($k) . ']">';
            foreach (self::MODES as $m) {
                echo '<option value="' . esc_attr($m) . '"' . selected($cur, $m, false) . '>' . esc_html($m) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save write modes');
        echo '</form>';

        echo '<form method="post" style="margin-top:-10px">';
        wp_nonce_field('mha_admin');
        echo '<input type="hidden" name="mha_action" value="all_off">';
        submit_button('Kill switch — set ALL to OFF', 'delete', 'submit', false);
        echo '</form>';

        // Keys
        echo '<h2 style="margin-top:2em">API keys</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Label</th><th>Consumer</th><th>Scope</th><th>Resort</th><th>Active</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>';
        if (!$keys) { echo '<tr><td colspan="8"><em>No keys issued.</em></td></tr>'; }
        foreach ($keys as $rec) {
            echo '<tr>';
            echo '<td>' . esc_html($rec['label'] ?? '') . '</td>';
            echo '<td>' . esc_html($rec['consumer'] ?? '') . '</td>';
            echo '<td>' . esc_html($rec['scope'] ?? '') . '</td>';
            echo '<td>' . esc_html($rec['resort'] ?? '') . '</td>';
            echo '<td>' . (!empty($rec['active']) ? '✅' : '—') . '</td>';
            echo '<td>' . esc_html($rec['created'] ?? '') . '</td>';
            echo '<td>' . esc_html($rec['last_used'] ?? '—') . '</td>';
            echo '<td>';
            if (!empty($rec['active'])) {
                echo '<form method="post" style="margin:0">';
                wp_nonce_field('mha_admin');
                echo '<input type="hidden" name="mha_action" value="revoke_key"><input type="hidden" name="key_id" value="' . esc_attr($rec['id'] ?? '') . '">';
                submit_button('Revoke', 'small delete', 'submit', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // Issue key
        echo '<h3>Issue a key</h3><form method="post">';
        wp_nonce_field('mha_admin');
        echo '<input type="hidden" name="mha_action" value="issue_key">';
        echo '<input type="text" name="consumer" placeholder="consumer (e.g. matova-ops)" class="regular-text"> ';
        echo '<select name="scope"><option value="read">read</option><option value="ops-write">ops-write</option><option value="admin">admin</option></select> ';
        echo '<input type="text" name="resort" value="' . esc_attr($resort) . '" class="small-text"> ';
        submit_button('Issue key', 'secondary', 'submit', false);
        echo '</form>';

        echo '</div>';
    }
}
