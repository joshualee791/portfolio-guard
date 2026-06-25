<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Config
{
    public static function family_name()
    {
        return 'wordpress-shared-plugin-framework';
    }

    public static function report_recipient()
    {
        $defaultRecipient = (string) get_option('admin_email', '');
        $configuredRecipient = (string) get_option('msp_pg_report_recipient', $defaultRecipient);

        return apply_filters('msp_pg_report_recipient', $configuredRecipient);
    }

    public static function cron_hook()
    {
        return 'msp_pg_run_scan';
    }

    public static function state_option_name()
    {
        return 'msp_pg_state';
    }

    public static function scan_lock_key()
    {
        return 'msp_pg_scan_lock';
    }

    public static function catchup_lock_key()
    {
        return 'msp_pg_catchup_lock';
    }

    public static function delete_tier1_enabled()
    {
        return (bool) apply_filters('msp_pg_delete_tier1_enabled', true);
    }

    public static function default_dry_run()
    {
        if (defined('PORTFOLIO_GUARD_DRY_RUN')) {
            return (bool) PORTFOLIO_GUARD_DRY_RUN;
        }

        return (bool) apply_filters('msp_pg_default_dry_run', false);
    }

    public static function signature_version()
    {
        return apply_filters('msp_pg_signature_version', '2026-06-05.1');
    }

    public static function heuristic_version()
    {
        return apply_filters('msp_pg_heuristic_version', '2026-06-03.2');
    }

    public static function max_scan_file_bytes()
    {
        return (int) apply_filters('msp_pg_max_scan_file_bytes', 2 * 1024 * 1024);
    }

    public static function scan_extensions()
    {
        return apply_filters('msp_pg_scan_extensions', array('php', 'js', 'json', 'txt'));
    }

    public static function artifact_base_dir()
    {
        $override = apply_filters('msp_pg_artifact_base_dir', '');
        if (!empty($override)) {
            return rtrim($override, '/\\');
        }

        $uploads = wp_upload_dir();

        return rtrim($uploads['basedir'], '/\\') . DIRECTORY_SEPARATOR . '.msp-remediation';
    }

    public static function evidence_retention_mode()
    {
        $mode = (string) apply_filters('msp_pg_evidence_retention_mode', 'metadata_only');
        $allowed = array('metadata_only', 'compressed_archive', 'full_artifact_retention');

        return in_array($mode, $allowed, true) ? $mode : 'metadata_only';
    }

    public static function temporary_quarantine_base_dir()
    {
        $override = apply_filters('msp_pg_temporary_quarantine_base_dir', '');
        if (!empty($override)) {
            return rtrim($override, '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'msp-portfolio-guard-quarantine';
    }

    public static function site_slug()
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $slug = sanitize_title_with_dashes((string) $host);

        if ($slug === '') {
            $slug = 'site-' . substr(md5(home_url('/')), 0, 12);
        }

        return $slug;
    }

    public static function mu_loader_filename()
    {
        return 'portfolio-guard-loader.php';
    }

    public static function mu_loader_path()
    {
        return trailingslashit(WPMU_PLUGIN_DIR) . self::mu_loader_filename();
    }

    public static function pending_activation_option_name()
    {
        return 'msp_pg_pending_activation_scan';
    }

    public static function setup_notice_option_name()
    {
        return 'msp_pg_setup_notice';
    }

    public static function protected_plugin_slugs()
    {
        return apply_filters('msp_pg_protected_plugin_slugs', array(
            'elementor',
            'elementor-pro',
            'gravityforms',
            'seo-by-rank-math',
            'seo-by-rank-math-pro',
            'wp-rocket',
            'wp-smush-pro',
            'wpmudev-updates',
            'mainwp-child',
            'pojo-accessibility',
        ));
    }

    public static function score_weights()
    {
        return apply_filters('msp_pg_score_weights', array(
            'known_hash' => 100,
            'known_filename' => 100,
            'known_primary_plugin_file' => 100,
            'known_plugin_directory' => 100,
            'known_route' => 75,
            'known_domain' => 75,
            'known_auth_cookie_impersonation_pattern' => 50,
            'known_family_bootstrap_pattern' => 50,
            'known_family_payload_structure' => 50,
            'suspicious_remote_javascript' => 10,
            'custom_rest_namespace' => 10,
            'ajax_handlers' => 5,
            'remote_requests' => 5,
            'cookie_manipulation' => 5,
            'dynamic_script_registration' => 5,
        ));
    }

    public static function score_thresholds()
    {
        return apply_filters('msp_pg_score_thresholds', array(
            'tier2' => 100,
            'tier3' => 20,
        ));
    }

    public static function artifact_retention_days()
    {
        return (int) apply_filters('msp_pg_artifact_retention_days', 7);
    }
}
