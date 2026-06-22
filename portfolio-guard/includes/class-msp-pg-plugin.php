<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Plugin
{
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'maybe_complete_setup'), 1);
        add_action(MSP_PG_Config::cron_hook(), array($this, 'run_cron_scan'));
        add_action('admin_init', array($this, 'maybe_run_catchup_scan'));
        add_action('plugins_loaded', array($this, 'maybe_sync_mu_loader'), 1);
        add_action('admin_notices', array($this, 'render_setup_notice'));
    }

    public static function activate()
    {
        update_option(MSP_PG_Config::pending_activation_option_name(), gmdate('c'), false);
        update_option('msp_pg_version', MSP_PG_VERSION, false);
        if (get_option(MSP_PG_Config::tier1_override_option_name(), null) === null) {
            add_option(MSP_PG_Config::tier1_override_option_name(), true, '', false);
        }
    }

    public static function deactivate()
    {
        self::clear_scan_schedule();
        self::remove_mu_loader();
    }

    public static function uninstall()
    {
        self::clear_scan_schedule();
        self::remove_mu_loader();
        delete_option(MSP_PG_Config::state_option_name());
        delete_option(MSP_PG_Config::pending_activation_option_name());
        delete_option(MSP_PG_Config::setup_notice_option_name());
        delete_option(MSP_PG_Config::tier1_override_option_name());
        delete_option('msp_pg_version');
        delete_transient(MSP_PG_Config::scan_lock_key());
        delete_transient(MSP_PG_Config::catchup_lock_key());
    }

    public function run_cron_scan()
    {
        MSP_PG_Remediator::run_scan('cron');
    }

    public function maybe_run_catchup_scan()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (get_transient(MSP_PG_Config::catchup_lock_key())) {
            return;
        }

        $state = get_option(MSP_PG_Config::state_option_name(), array());
        $lastScan = isset($state['last_scan_at']) ? strtotime($state['last_scan_at']) : 0;
        $interval = MSP_PG_Config::interval_seconds();
        $pendingActivation = get_option(MSP_PG_Config::pending_activation_option_name());

        if (!empty($pendingActivation)) {
            MSP_PG_Remediator::run_scan('activation-catchup');
            delete_option(MSP_PG_Config::pending_activation_option_name());
            return;
        }

        if ($lastScan > 0 && (time() - $lastScan) < $interval) {
            return;
        }

        set_transient(MSP_PG_Config::catchup_lock_key(), 1, 5 * MINUTE_IN_SECONDS);
        MSP_PG_Remediator::run_scan('admin-catchup');
        delete_transient(MSP_PG_Config::catchup_lock_key());
    }

    public function maybe_complete_setup()
    {
        if (get_option(MSP_PG_Config::tier1_override_option_name(), null) === null) {
            add_option(MSP_PG_Config::tier1_override_option_name(), true, '', false);
        }

        $installedVersion = get_option('msp_pg_version');

        if ($installedVersion !== MSP_PG_VERSION) {
            update_option('msp_pg_version', MSP_PG_VERSION, false);
        }

        if ($this->has_setup_completed()) {
            return;
        }

        $errors = array();

        if (!self::ensure_mu_loader()) {
            $errors[] = 'Could not create or update the MU-loader.';
        }

        $scheduleResult = self::schedule_scan();
        if (!$scheduleResult['ok']) {
            $errors[] = $scheduleResult['message'];
        }

        if (!empty($errors)) {
            update_option(MSP_PG_Config::setup_notice_option_name(), implode(' ', $errors), false);
            return;
        }

        delete_option(MSP_PG_Config::setup_notice_option_name());
    }

    public function maybe_sync_mu_loader()
    {
        $installedVersion = get_option('msp_pg_version');
        $muLoaderPath = MSP_PG_Config::mu_loader_path();

        if ($installedVersion !== MSP_PG_VERSION || !file_exists($muLoaderPath)) {
            self::ensure_mu_loader();
            update_option('msp_pg_version', MSP_PG_VERSION, false);
        }
    }

    public function render_setup_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $message = get_option(MSP_PG_Config::setup_notice_option_name());
        if (empty($message)) {
            return;
        }

        echo '<div class="notice notice-error"><p>MSP Portfolio Guard setup warning: ' . esc_html($message) . '</p></div>';
    }

    private static function schedule_scan()
    {
        $hook = MSP_PG_Config::cron_hook();

        if (wp_next_scheduled($hook)) {
            return array(
                'ok' => true,
                'message' => '',
            );
        }

        $recurrence = MSP_PG_Config::scan_interval();
        $schedules = wp_get_schedules();
        if (!isset($schedules[$recurrence])) {
            $recurrence = 'hourly';
        }

        $scheduled = wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, $hook, array(), true);
        if ($scheduled === true || wp_next_scheduled($hook)) {
            return array(
                'ok' => true,
                'message' => '',
            );
        }

        $fallback = wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), $hook, array(), true);
        if ($fallback === true || wp_next_scheduled($hook)) {
            return array(
                'ok' => true,
                'message' => 'Recurring scan could not be registered; using fallback scheduling and admin catch-up scans.',
            );
        }

        $errorMessage = 'Could not register automated scan scheduling.';
        if (is_wp_error($scheduled)) {
            $errorMessage .= ' ' . $scheduled->get_error_message();
        } elseif (is_wp_error($fallback)) {
            $errorMessage .= ' ' . $fallback->get_error_message();
        }

        return array(
            'ok' => false,
            'message' => $errorMessage,
        );
    }

    private static function clear_scan_schedule()
    {
        $timestamp = wp_next_scheduled(MSP_PG_Config::cron_hook());
        if ($timestamp) {
            wp_unschedule_event($timestamp, MSP_PG_Config::cron_hook());
        }
    }

    private static function ensure_mu_loader()
    {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return false;
        }

        if (!MSP_PG_Utils::ensure_directory(WPMU_PLUGIN_DIR)) {
            return false;
        }

        $pluginDirName = basename(dirname(MSP_PG_PLUGIN_FILE));

$contents = <<<'PHP'
<?php
/**
 * Plugin Name: MSP Portfolio Guard Loader
 * Description: Early boot loader for MSP Portfolio Guard.
 * Version: __VERSION__
 */

if (!defined('ABSPATH')) {
    exit;
}

$boot = WP_PLUGIN_DIR . '/__PLUGIN_DIR__/portfolio-guard.php';

if (is_readable($boot)) {
    require_once $boot;
}
PHP;

        $contents = str_replace('__PLUGIN_DIR__', $pluginDirName, $contents);
        $contents = str_replace('__VERSION__', MSP_PG_VERSION, $contents);
        return file_put_contents(MSP_PG_Config::mu_loader_path(), $contents) !== false;
    }

    private static function remove_mu_loader()
    {
        $path = MSP_PG_Config::mu_loader_path();

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function has_setup_completed()
    {
        return file_exists(MSP_PG_Config::mu_loader_path());
    }
}
