<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_DiagnosticsPage
{
    const PAGE_SLUG = 'msp-pg-diagnostics';

    public static function register()
    {
        add_action('admin_menu',   array(__CLASS__, 'add_page'));
        add_action('admin_notices', array(__CLASS__, 'render_update_notice'));
        add_action('admin_init',   array(__CLASS__, 'register_settings'));
        add_action('admin_post_msp_pg_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_msp_pg_scan_now',      array(__CLASS__, 'handle_scan_now'));
    }

    public static function add_page()
    {
        add_submenu_page(
            null,
            'MSP Portfolio Guard — Diagnostics',
            'MSP Portfolio Guard — Diagnostics',
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'render')
        );
    }

    public static function register_settings()
    {
        register_setting('msp_pg_settings', 'msp_pg_report_recipient', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ));
    }

    public static function handle_save_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer('msp_pg_save_settings');

        $email = isset($_POST['msp_pg_report_recipient'])
            ? sanitize_email(wp_unslash($_POST['msp_pg_report_recipient']))
            : '';

        update_option('msp_pg_report_recipient', $email, false);

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&msp_pg_updated=1'));
        exit;
    }

    public static function handle_scan_now()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer('msp_pg_scan_now');

        MSP_PG_Remediator::run_scan('manual');

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&msp_pg_scanned=1'));
        exit;
    }

    /**
     * Renders and consumes the msp_pg_update_notice transient on any admin page.
     * Runs on admin_notices; fires after every page load until the transient is deleted.
     */
    public static function render_update_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient('msp_pg_update_notice');
        if (empty($notice)) {
            return;
        }
        delete_transient('msp_pg_update_notice');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $data = MSP_PG_Diagnostics::collect();

        echo '<div class="wrap">';
        echo '<h1>MSP Portfolio Guard &mdash; Diagnostics &amp; Settings</h1>';

        if (!empty($_GET['msp_pg_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        if (!empty($_GET['msp_pg_scanned'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Scan completed.</p></div>';
        }

        self::render_plugin($data['plugin']);
        self::render_scanning($data['scanning']);
        self::render_scheduler($data['scheduler']);
        self::render_registry($data['registry']);
        self::render_configuration($data['configuration']);
        self::render_settings();
        self::render_scan_now();

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Section renderers
    // -------------------------------------------------------------------------

    private static function render_plugin(array $d)
    {
        echo '<h2>Plugin</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::row('Version', esc_html($d['version']));
        echo '</tbody></table>';
    }

    private static function render_scanning(array $d)
    {
        echo '<h2>Scanning</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        if ($d['last_scan_at'] !== '') {
            $ts      = esc_html(self::format_timestamp($d['last_scan_at']));
            $trigger = esc_html(self::format_trigger($d['trigger']));
            $count   = (int) $d['detections'];
            $result  = $count === 0 ? 'No detections' : $count . ' detection' . ($count === 1 ? '' : 's');
            self::row('Last scan', $ts . ' &mdash; ' . $trigger . ' &mdash; ' . esc_html($result));
        } else {
            self::row('Last scan', 'No scan has been recorded on this site.');
        }

        self::row('Currently scanning', $d['scan_in_progress'] ? '<strong>Yes</strong>' : 'No');

        echo '</tbody></table>';
    }

    private static function render_scheduler(array $d)
    {
        echo '<h2>Scheduler</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        if ($d['scan_next'] !== false) {
            self::row('Daily scan', 'Scheduled &mdash; ' . esc_html(self::format_next((int) $d['scan_next'])));
        } else {
            self::row('Daily scan', '<strong>Not scheduled</strong>');
        }

        if ($d['update_next'] !== false) {
            self::row('Registry updates', 'Scheduled &mdash; ' . esc_html(self::format_next((int) $d['update_next'])));
        } else {
            self::row('Registry updates', '<strong>Not scheduled</strong>');
        }

        if ($d['mu_loader_present']) {
            self::row('MU-loader', 'Present');
        } else {
            self::row('MU-loader', '<strong>Missing &mdash; early request blocking inactive</strong>');
        }

        echo '</tbody></table>';
    }

    private static function render_registry(array $d)
    {
        echo '<h2>Registry</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        if ($d['available'] && $d['version'] !== null) {
            $source = $d['source'] === 'applied' ? 'Applied' : 'Installed';
            self::row('Active registry', esc_html($source . ' (version ' . $d['version'] . ')'));
        } else {
            self::row('Active registry', '<strong>Unavailable</strong>');
        }

        if ($d['last_applied_version'] !== null && $d['last_applied_at'] !== null) {
            $label = 'version ' . (int) $d['last_applied_version'] . ', ' . self::format_timestamp($d['last_applied_at']);
            self::row('Last successful update', esc_html($label));
        } else {
            self::row('Last successful update', 'Never &mdash; using installed registry');
        }

        $failures  = (int) $d['consecutive_failures'];
        $threshold = (int) $d['failure_threshold'];
        if ($failures === 0) {
            self::row('Update checks', 'Healthy');
        } elseif ($failures < $threshold) {
            self::row('Update checks', 'Recent failures (' . $failures . ') &mdash; monitoring');
        } else {
            self::row('Update checks', '<strong>Failing (' . $failures . ' consecutive) &mdash; notification sent</strong>');
        }

        echo '</tbody></table>';
    }

    private static function render_configuration(array $d)
    {
        echo '<h2>Configuration</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        if ($d['dry_run']) {
            self::row('Dry-run mode', '<strong>Enabled &mdash; no changes will be made to this site</strong>');
        } else {
            self::row('Dry-run mode', 'Disabled');
        }

        if ($d['tier1_deletion']) {
            self::row('Tier 1 remediation', 'Enabled');
        } else {
            self::row('Tier 1 remediation', 'Disabled &mdash; report only');
        }

        self::row('Evidence mode',    esc_html(self::format_evidence_mode($d['evidence_mode'])));
        self::row('Report recipient', esc_html(self::mask_email((string) $d['report_recipient'])));

        echo '</tbody></table>';
    }

    private static function render_settings()
    {
        $recipient = esc_attr(MSP_PG_Config::report_recipient());

        echo '<h2>Settings</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="msp_pg_save_settings">';
        wp_nonce_field('msp_pg_save_settings');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="msp_pg_report_recipient">Report recipient</label></th>';
        echo '<td><input type="email" id="msp_pg_report_recipient" name="msp_pg_report_recipient"'
           . ' value="' . $recipient . '" class="regular-text"></td>';
        echo '</tr>';
        echo '</tbody></table>';
        submit_button('Save Settings');
        echo '</form>';
    }

    private static function render_scan_now()
    {
        echo '<h2>Actions</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="msp_pg_scan_now">';
        wp_nonce_field('msp_pg_scan_now');
        echo '<p>Trigger the normal scan pipeline immediately. Produces the same results and report email as a scheduled scan.</p>';
        submit_button('Scan Now', 'secondary');
        echo '</form>';
    }

    // -------------------------------------------------------------------------
    // Rendering helpers
    // -------------------------------------------------------------------------

    private static function row($label, $value)
    {
        echo '<tr>';
        echo '<th scope="row">' . esc_html($label) . '</th>';
        echo '<td>' . $value . '</td>';
        echo '</tr>';
    }

    private static function format_timestamp($iso)
    {
        $ts = is_numeric($iso) ? (int) $iso : strtotime($iso);
        if ($ts === false || $ts === 0) {
            return (string) $iso;
        }
        $diff = human_time_diff($ts, time());
        $utc  = gmdate('Y-m-d H:i \U\T\C', $ts);
        return $diff . ' ago (' . $utc . ')';
    }

    private static function format_next($timestamp)
    {
        $diff = $timestamp - time();
        if ($diff <= 0) {
            return 'imminent';
        }
        return 'next in ~' . human_time_diff(time(), $timestamp);
    }

    private static function format_trigger($trigger)
    {
        $map = array(
            'cron'               => 'Scheduled',
            'activation-catchup' => 'Activation',
            'admin-catchup'      => 'Admin catch-up',
            'manual'             => 'Manual',
        );
        return isset($map[$trigger]) ? $map[$trigger] : $trigger;
    }

    private static function format_evidence_mode($mode)
    {
        $map = array(
            'metadata_only'           => 'Metadata only',
            'compressed_archive'      => 'Compressed archive',
            'full_artifact_retention' => 'Full artifact retention',
        );
        return isset($map[$mode]) ? $map[$mode] : $mode;
    }

    private static function mask_email($email)
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return $email;
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
