<?php

/**
 * UninstallTest
 *
 * Validates Spec 009 §6 — complete uninstall lifecycle.
 * Establishes a known pre-uninstall state, calls MSP_PG_Plugin::uninstall(),
 * and asserts the absence of every resource Portfolio Guard owns.
 *
 * Blocking test suite in the Portfolio Guard validation gate.
 */
class UninstallTest
{
    private $results = array();
    private $passed  = 0;
    private $failed  = 0;
    private $total   = 0;

    // Every msp_pg_* option Portfolio Guard owns, keyed for table-driven tests
    private $allOptions = array(
        'msp_pg_state',
        'msp_pg_pending_activation_scan',
        'msp_pg_setup_notice',
        'msp_pg_allow_tier1_remediation',
        'msp_pg_version',
        'msp_pg_report_recipient',
        'msp_pg_last_update_checked',
        'msp_pg_last_update_applied',
        'msp_pg_max_registry_version',
        'msp_pg_update_consecutive_failures',
        'msp_pg_plugin_update_last_checked',
        'msp_pg_plugin_update_cache',
    );

    public function run()
    {
        $this->setup_and_run_all();

        return array(
            'results' => $this->results,
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'total'   => $this->total,
        );
    }

    private function setup_and_run_all()
    {
        $this->seed_state();

        MSP_PG_Plugin::uninstall();

        // ── Options (one assertion per key) ──────────────────────────────────
        foreach ($this->allOptions as $key) {
            $this->assert_option_absent($key);
        }

        // ── Transients ───────────────────────────────────────────────────────
        $this->assert_transient_absent('msp_pg_scan_lock');
        $this->assert_transient_absent('msp_pg_catchup_lock');
        $this->assert_transient_absent('msp_pg_update_notice');

        // ── Scheduled events ─────────────────────────────────────────────────
        $this->assert_event_absent('msp_pg_run_scan',         MSP_PG_Config::cron_hook());
        $this->assert_event_absent('msp_pg_run_update_check', MSP_PG_UpdateScheduler::HOOK);

        // ── Generated files ───────────────────────────────────────────────────
        $muLoader = MSP_PG_Config::mu_loader_path();
        if (file_exists($muLoader)) {
            $this->fail('MU-loader absent after uninstall', 'file still exists: ' . $muLoader);
        } else {
            $this->pass('MU-loader absent after uninstall');
        }

        $registryDir = dirname(MSP_PG_Config::applied_registry_path());
        if (!empty($registryDir) && is_dir($registryDir)) {
            $this->fail('applied registry directory absent after uninstall', 'directory still exists: ' . $registryDir);
        } else {
            $this->pass('applied registry directory absent after uninstall');
        }

        // ── Catch-all: no msp_pg_* option survives ────────────────────────────
        $survivors = array_filter(
            array_keys($GLOBALS['msp_pg_test_options']),
            function ($k) { return strncmp($k, 'msp_pg_', 7) === 0; }
        );

        if (!empty($survivors)) {
            $this->fail(
                'catch-all: no msp_pg_* option survives uninstall',
                'remaining: ' . implode(', ', array_values($survivors))
            );
        } else {
            $this->pass('catch-all: no msp_pg_* option survives uninstall');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seed_state()
    {
        // Seed all owned options with non-empty values
        foreach ($this->allOptions as $key) {
            update_option($key, 'seed-value-' . $key, false);
        }

        // Seed transients
        set_transient('msp_pg_scan_lock', 1, 60);
        set_transient('msp_pg_catchup_lock', 1, 60);
        set_transient('msp_pg_update_notice', 'Registry updated to version 42.', 3600);

        // Seed scheduled events
        wp_schedule_event(time() + 3600, 'daily', MSP_PG_Config::cron_hook());
        wp_schedule_event(time() + 1800, 'msp_pg_six_hours', MSP_PG_UpdateScheduler::HOOK);

        // Seed MU-loader
        $muLoader = MSP_PG_Config::mu_loader_path();
        if (!empty($muLoader)) {
            wp_mkdir_p(dirname($muLoader));
            file_put_contents($muLoader, '<?php // seed loader');
        }

        // Seed applied registry directory and file
        $registryPath = MSP_PG_Config::applied_registry_path();
        if (!empty($registryPath)) {
            wp_mkdir_p(dirname($registryPath));
            file_put_contents($registryPath, json_encode(array('registry_version' => 1)));
        }
    }

    private function assert_option_absent($key)
    {
        $stored = array_key_exists($key, $GLOBALS['msp_pg_test_options']);
        if ($stored) {
            $this->fail("option '{$key}' absent after uninstall", 'option is still present');
        } else {
            $this->pass("option '{$key}' absent after uninstall");
        }
    }

    private function assert_transient_absent($key)
    {
        $stored = array_key_exists($key, $GLOBALS['msp_pg_test_transients']);
        if ($stored) {
            $this->fail("transient '{$key}' absent after uninstall", 'transient is still present');
        } else {
            $this->pass("transient '{$key}' absent after uninstall");
        }
    }

    private function assert_event_absent($label, $hook)
    {
        if (wp_next_scheduled($hook) !== false) {
            $this->fail("scheduled event '{$label}' absent after uninstall", 'event is still scheduled');
        } else {
            $this->pass("scheduled event '{$label}' absent after uninstall");
        }
    }

    private function pass($label)
    {
        $this->results[] = '[PASS] UninstallTest: ' . $label;
        $this->passed++;
        $this->total++;
    }

    private function fail($label, $reason)
    {
        $this->results[] = '[FAIL] UninstallTest: ' . $label . ' — ' . $reason;
        $this->failed++;
        $this->total++;
    }
}
