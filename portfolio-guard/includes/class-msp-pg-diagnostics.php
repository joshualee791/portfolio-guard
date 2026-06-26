<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Diagnostics
{
    /**
     * Collect all diagnostic data. Pure read operation — no side effects.
     * All state is obtained through intentional public interfaces.
     */
    public static function collect()
    {
        return array(
            'plugin'        => self::collect_plugin(),
            'scanning'      => self::collect_scanning(),
            'scheduler'     => self::collect_scheduler(),
            'registry'      => self::collect_registry(),
            'configuration' => self::collect_configuration(),
        );
    }

    // -------------------------------------------------------------------------
    // Section collectors
    // -------------------------------------------------------------------------

    private static function collect_plugin()
    {
        return array(
            'version' => MSP_PG_VERSION,
        );
    }

    private static function collect_scanning()
    {
        $state      = get_option(MSP_PG_Config::state_option_name(), array());
        $lastScanAt = isset($state['last_scan_at'])                  ? (string) $state['last_scan_at']                   : '';
        $trigger    = isset($state['last_scan_result']['trigger'])    ? (string) $state['last_scan_result']['trigger']    : '';
        $detections = isset($state['last_scan_result']['detections']) ? (int)    $state['last_scan_result']['detections'] : 0;

        return array(
            'last_scan_at'     => $lastScanAt,
            'trigger'          => $trigger,
            'detections'       => $detections,
            'scan_in_progress' => (bool) get_transient(MSP_PG_Config::scan_lock_key()),
        );
    }

    private static function collect_scheduler()
    {
        $nextScan   = wp_next_scheduled(MSP_PG_Config::cron_hook());
        $nextUpdate = wp_next_scheduled(MSP_PG_UpdateScheduler::HOOK);

        return array(
            'scan_next'         => $nextScan   !== false ? (int) $nextScan   : false,
            'update_next'       => $nextUpdate !== false ? (int) $nextUpdate : false,
            'mu_loader_present' => file_exists(MSP_PG_Config::mu_loader_path()),
        );
    }

    private static function collect_registry()
    {
        $available   = MSP_PG_Signatures::registry_available();
        $metadata    = $available ? MSP_PG_Signatures::registry_metadata() : null;
        $lastApplied = MSP_PG_Updater::last_applied();
        $failures    = MSP_PG_Updater::consecutive_failures();
        $lastChecked = MSP_PG_Updater::last_checked();

        return array(
            'available'            => $available,
            'version'              => $metadata !== null ? $metadata['version'] : null,
            'source'               => $metadata !== null ? $metadata['source']  : null,
            'last_applied_version' => !empty($lastApplied['version'])   ? (int)    $lastApplied['version']   : null,
            'last_applied_at'      => !empty($lastApplied['timestamp']) ? (string) $lastApplied['timestamp'] : null,
            'consecutive_failures' => $failures,
            'failure_threshold'    => MSP_PG_Updater::MAX_FAILURES_BEFORE_NOTIFY,
            'last_checked'         => $lastChecked,
        );
    }

    private static function collect_configuration()
    {
        return array(
            'dry_run'          => MSP_PG_Config::default_dry_run(),
            'tier1_deletion'   => MSP_PG_Config::delete_tier1_enabled(),
            'evidence_mode'    => MSP_PG_Config::evidence_retention_mode(),
            'report_recipient' => MSP_PG_Config::report_recipient(),
        );
    }
}
