<?php

/**
 * DiagnosticsTest
 *
 * Validates the Spec 008 diagnostics infrastructure: the registry_metadata()
 * accessor, the consecutive_failures() accessor, and the MSP_PG_Diagnostics
 * data collector. Does not test rendering (WordPress admin context required).
 *
 * Blocking test suite in the Portfolio Guard validation gate.
 */
class DiagnosticsTest
{
    private $results = array();
    private $passed  = 0;
    private $failed  = 0;
    private $total   = 0;

    private $appliedRegistry = array(
        'schema_version'    => 1,
        'registry_version'  => 99,
        'variants'          => array(
            'diag-sentinel' => array(
                'slug'        => 'diag-sentinel',
                'main_file'   => 'diag-sentinel/diag-sentinel.php',
                'hashes'      => array(),
                'domains'     => array(),
                'routes'      => array(),
                'backdoors'   => array(),
                'ioc_strings' => array('diag-sentinel-ioc'),
            ),
        ),
        'exact_ioc_strings' => array('diag-sentinel-exact'),
    );

    public function run()
    {
        // MSP_PG_Signatures::registry_metadata()
        $this->test_metadata_source_is_installed_when_no_applied();
        $this->test_metadata_source_is_applied_when_applied_wins();
        $this->test_metadata_clears_after_reset();

        // MSP_PG_Updater::consecutive_failures()
        $this->test_consecutive_failures_default_is_zero();
        $this->test_consecutive_failures_returns_stored_value();

        // MSP_PG_Diagnostics::collect() — structure
        $this->test_collect_returns_all_sections();
        $this->test_collect_plugin_section();
        $this->test_collect_scanning_section_keys();
        $this->test_collect_scheduler_section_keys();
        $this->test_collect_registry_section_keys();
        $this->test_collect_configuration_section_keys();

        // MSP_PG_Diagnostics::collect() — no state mutation
        $this->test_collect_does_not_modify_options();

        return array(
            'results' => $this->results,
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'total'   => $this->total,
        );
    }

    // -------------------------------------------------------------------------
    // MSP_PG_Signatures::registry_metadata()
    // -------------------------------------------------------------------------

    private function test_metadata_source_is_installed_when_no_applied()
    {
        $this->remove_applied_registry();
        MSP_PG_Signatures::reset();

        $meta = MSP_PG_Signatures::registry_metadata();

        if ($meta === null) {
            $this->fail('registry_metadata — source=installed when no applied', 'returned null; installed registry should be available');
            return;
        }
        if ($meta['source'] !== 'installed') {
            $this->fail('registry_metadata — source=installed when no applied', 'source was "' . $meta['source'] . '", expected "installed"');
            return;
        }
        if (!is_int($meta['version'])) {
            $this->fail('registry_metadata — source=installed when no applied', 'version is not an integer');
            return;
        }
        $this->pass('registry_metadata — source=installed when no applied');
    }

    private function test_metadata_source_is_applied_when_applied_wins()
    {
        $this->write_applied_registry($this->appliedRegistry);
        MSP_PG_Signatures::reset();

        $meta = MSP_PG_Signatures::registry_metadata();

        $this->remove_applied_registry();
        MSP_PG_Signatures::reset();

        if ($meta === null) {
            $this->fail('registry_metadata — source=applied when applied wins', 'returned null');
            return;
        }
        if ($meta['source'] !== 'applied') {
            $this->fail('registry_metadata — source=applied when applied wins', 'source was "' . $meta['source'] . '", expected "applied"');
            return;
        }
        if ($meta['version'] !== 99) {
            $this->fail('registry_metadata — source=applied when applied wins', 'version was ' . $meta['version'] . ', expected 99');
            return;
        }
        $this->pass('registry_metadata — source=applied when applied wins');
    }

    private function test_metadata_clears_after_reset()
    {
        $this->write_applied_registry($this->appliedRegistry);
        MSP_PG_Signatures::reset();
        MSP_PG_Signatures::registry_metadata(); // warm the cache

        $this->remove_applied_registry();
        MSP_PG_Signatures::reset(); // must clear $registrySource

        // After reset with no applied file the installed registry loads; source becomes 'installed'
        $meta = MSP_PG_Signatures::registry_metadata();
        if ($meta !== null && $meta['source'] === 'applied') {
            $this->fail('registry_metadata — reset clears registrySource', 'still returns source=applied after reset and applied removal');
            return;
        }
        $this->pass('registry_metadata — reset clears registrySource');
    }

    // -------------------------------------------------------------------------
    // MSP_PG_Updater::consecutive_failures()
    // -------------------------------------------------------------------------

    private function test_consecutive_failures_default_is_zero()
    {
        delete_option('msp_pg_update_consecutive_failures');
        $result = MSP_PG_Updater::consecutive_failures();
        if ($result !== 0) {
            $this->fail('consecutive_failures — default is 0', 'returned ' . var_export($result, true));
            return;
        }
        $this->pass('consecutive_failures — default is 0');
    }

    private function test_consecutive_failures_returns_stored_value()
    {
        update_option('msp_pg_update_consecutive_failures', 7, false);
        $result = MSP_PG_Updater::consecutive_failures();
        delete_option('msp_pg_update_consecutive_failures');
        if ($result !== 7) {
            $this->fail('consecutive_failures — returns stored value', 'returned ' . var_export($result, true) . ', expected 7');
            return;
        }
        $this->pass('consecutive_failures — returns stored value');
    }

    // -------------------------------------------------------------------------
    // MSP_PG_Diagnostics::collect() — structure
    // -------------------------------------------------------------------------

    private function test_collect_returns_all_sections()
    {
        MSP_PG_Signatures::reset();
        $data     = MSP_PG_Diagnostics::collect();
        $required = array('plugin', 'scanning', 'scheduler', 'registry', 'configuration');
        $missing  = array();
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            $this->fail('collect — returns all five sections', 'missing: ' . implode(', ', $missing));
            return;
        }
        $this->pass('collect — returns all five sections');
    }

    private function test_collect_plugin_section()
    {
        $d = MSP_PG_Diagnostics::collect()['plugin'];
        if (!array_key_exists('version', $d)) {
            $this->fail('collect — plugin section has version key', 'key absent');
            return;
        }
        if ($d['version'] !== MSP_PG_VERSION) {
            $this->fail('collect — plugin section has version key', 'version was "' . $d['version'] . '", expected "' . MSP_PG_VERSION . '"');
            return;
        }
        $this->pass('collect — plugin section has version key');
    }

    private function test_collect_scanning_section_keys()
    {
        $d    = MSP_PG_Diagnostics::collect()['scanning'];
        $keys = array('last_scan_at', 'trigger', 'detections', 'scan_in_progress');
        $this->assert_section_keys('collect — scanning section keys', $d, $keys);
    }

    private function test_collect_scheduler_section_keys()
    {
        $d    = MSP_PG_Diagnostics::collect()['scheduler'];
        $keys = array('scan_next', 'update_next', 'mu_loader_present');
        $this->assert_section_keys('collect — scheduler section keys', $d, $keys);
    }

    private function test_collect_registry_section_keys()
    {
        $d    = MSP_PG_Diagnostics::collect()['registry'];
        $keys = array(
            'available', 'version', 'source',
            'last_applied_version', 'last_applied_at',
            'consecutive_failures', 'failure_threshold', 'last_checked',
        );
        $this->assert_section_keys('collect — registry section keys', $d, $keys);
    }

    private function test_collect_configuration_section_keys()
    {
        $d    = MSP_PG_Diagnostics::collect()['configuration'];
        $keys = array('dry_run', 'tier1_deletion', 'evidence_mode', 'report_recipient');
        $this->assert_section_keys('collect — configuration section keys', $d, $keys);
    }

    // -------------------------------------------------------------------------
    // MSP_PG_Diagnostics::collect() — no state mutation
    // -------------------------------------------------------------------------

    private function test_collect_does_not_modify_options()
    {
        $optionsBefore = $GLOBALS['msp_pg_test_options'];

        MSP_PG_Diagnostics::collect();

        $optionsAfter = $GLOBALS['msp_pg_test_options'];

        if ($optionsBefore !== $optionsAfter) {
            $added   = array_diff_key($optionsAfter, $optionsBefore);
            $removed = array_diff_key($optionsBefore, $optionsAfter);
            $detail  = '';
            if (!empty($added))   $detail .= 'added: ' . implode(', ', array_keys($added));
            if (!empty($removed)) $detail .= ($detail ? '; ' : '') . 'removed: ' . implode(', ', array_keys($removed));
            $this->fail('collect — does not modify options state', $detail ?: 'options changed');
            return;
        }
        $this->pass('collect — does not modify options state');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assert_section_keys($label, array $section, array $required)
    {
        $missing = array();
        foreach ($required as $key) {
            if (!array_key_exists($key, $section)) {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            $this->fail($label, 'missing: ' . implode(', ', $missing));
            return;
        }
        $this->pass($label);
    }

    private function applied_path()
    {
        return MSP_PG_Config::applied_registry_path();
    }

    private function write_applied_registry(array $registry)
    {
        $path = $this->applied_path();
        if (!empty($path)) {
            wp_mkdir_p(dirname($path));
            file_put_contents($path, json_encode($registry));
        }
    }

    private function remove_applied_registry()
    {
        $path = $this->applied_path();
        if (!empty($path) && file_exists($path)) {
            @unlink($path);
        }
    }

    private function pass($label)
    {
        $this->results[] = '[PASS] DiagnosticsTest: ' . $label;
        $this->passed++;
        $this->total++;
    }

    private function fail($label, $reason)
    {
        $this->results[] = '[FAIL] DiagnosticsTest: ' . $label . ' — ' . $reason;
        $this->failed++;
        $this->total++;
    }
}
