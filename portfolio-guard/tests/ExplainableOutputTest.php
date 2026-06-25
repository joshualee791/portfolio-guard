<?php

require_once __DIR__ . '/bootstrap.php';

class ExplainableOutputTest
{
    private $workspace;

    public function run()
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msp-pg-explain-' . uniqid();
        wp_mkdir_p($this->workspace . DIRECTORY_SEPARATOR . 'plugins');
        wp_mkdir_p($this->workspace . DIRECTORY_SEPARATOR . 'mu-plugins');
        wp_mkdir_p($this->workspace . DIRECTORY_SEPARATOR . 'uploads');

        if (!defined('WP_PLUGIN_DIR'))  define('WP_PLUGIN_DIR',  $this->workspace . DIRECTORY_SEPARATOR . 'plugins');
        if (!defined('WPMU_PLUGIN_DIR')) define('WPMU_PLUGIN_DIR', $this->workspace . DIRECTORY_SEPARATOR . 'mu-plugins');
        $GLOBALS['msp_pg_test_uploads_base'] = $this->workspace . DIRECTORY_SEPARATOR . 'uploads';

        $this->test_tier1_behavior_profiles_field_is_empty_array();
        $this->test_tier1_manifest_retains_score_and_reasons();
        $this->test_tier1_manifest_behavior_profiles_is_empty_array();
        $this->test_tier2_detection_has_activated_profiles();
        $this->test_tier2_manifest_omits_score();
        $this->test_tier2_manifest_omits_reasons();
        $this->test_tier2_manifest_contains_behavior_profiles();
        $this->test_tier2_profile_record_structure();
        $this->test_tier2_profile_summary_references_signals();
        $this->test_tier2_evidence_invariant_behavior_profiles_key_always_present();
        $this->test_regression_tier1_full_remediation_path();

        $this->cleanup($this->workspace);
    }

    // ─── Tier 1 must be completely unchanged ──────────────────────────────────

    private function test_tier1_behavior_profiles_field_is_empty_array()
    {
        $this->resetState();
        $slug = 'laravel-janet';
        $this->makeKnownMalwarePlugin($slug);

        $report     = MSP_PG_Remediator::run_scan('test');
        $detection  = $this->findDetection($report['confirmed_malware'], $slug);

        $this->assertNotNull($detection, 'laravel-janet must appear in confirmed_malware');
        $this->assertTrue(isset($detection['behavior_profiles']), 'behavior_profiles key must exist on Tier 1 detection');
        $this->assertTrue(is_array($detection['behavior_profiles']), 'behavior_profiles must be array');
        $this->assertTrue(empty($detection['behavior_profiles']), 'Tier 1 behavior_profiles must be empty');
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier1_manifest_retains_score_and_reasons()
    {
        $this->resetState();
        $slug = 'framework-triappment';
        $this->makeKnownMalwarePlugin($slug);

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['confirmed_malware'], $slug);
        $this->assertNotNull($detection, 'framework-triappment must be detected');

        $evidence = $this->readEvidence($detection);
        $this->assertTrue(array_key_exists('score', $evidence),   'Tier 1 evidence.json must contain score');
        $this->assertTrue(array_key_exists('reasons', $evidence), 'Tier 1 evidence.json must contain reasons');
        $this->assertTrue(is_int($evidence['score']) && $evidence['score'] > 0, 'Tier 1 score must be a positive integer');
    }

    private function test_tier1_manifest_behavior_profiles_is_empty_array()
    {
        $this->resetState();
        $slug = 'these-middleware';
        $this->makeKnownMalwarePlugin($slug);

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['confirmed_malware'], $slug);
        $this->assertNotNull($detection, 'these-middleware must be detected');

        $evidence = $this->readEvidence($detection);
        $this->assertTrue(array_key_exists('behavior_profiles', $evidence), 'Tier 1 evidence.json must have behavior_profiles key');
        $this->assertTrue(is_array($evidence['behavior_profiles']), 'Tier 1 behavior_profiles must be an array');
        $this->assertTrue(empty($evidence['behavior_profiles']), 'Tier 1 behavior_profiles must be empty per Spec 005 §12.1');
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    // ─── Tier 2: behavior profiles must be present and correct ───────────────

    private function test_tier2_detection_has_activated_profiles()
    {
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);

        $this->assertNotNull($detection, $slug . ' must appear in review_required');
        $this->assertTrue(isset($detection['behavior_profiles']), 'behavior_profiles must be present on Tier 2 detection');
        $this->assertTrue(is_array($detection['behavior_profiles']), 'behavior_profiles must be an array');
        $this->assertTrue(!empty($detection['behavior_profiles']), 'Tier 2 detection must have at least one activated profile');
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier2_manifest_omits_score()
    {
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);
        $this->assertNotNull($detection, $slug . ' must be in review_required');

        $evidence = $this->readEvidence($detection);
        $this->assertFalse(
            array_key_exists('score', $evidence),
            'Tier 2 evidence.json must NOT contain score (Spec 005 §11.3)'
        );
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier2_manifest_omits_reasons()
    {
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);

        $evidence = $this->readEvidence($detection);
        $this->assertFalse(
            array_key_exists('reasons', $evidence),
            'Tier 2 evidence.json must NOT contain reasons (contains weights — Spec 005 §11.3)'
        );
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier2_manifest_contains_behavior_profiles()
    {
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);

        $evidence = $this->readEvidence($detection);
        $this->assertTrue(
            array_key_exists('behavior_profiles', $evidence),
            'Tier 2 evidence.json must contain behavior_profiles (Spec 005 §12.1)'
        );
        $this->assertTrue(
            is_array($evidence['behavior_profiles']) && !empty($evidence['behavior_profiles']),
            'Tier 2 evidence.json behavior_profiles must be a non-empty array'
        );
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier2_profile_record_structure()
    {
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);

        foreach ($detection['behavior_profiles'] as $profile) {
            $this->assertTrue(isset($profile['profile_id']),       'profile_id must be present (Spec 005 §10)');
            $this->assertTrue(isset($profile['profile_label']),    'profile_label must be present');
            $this->assertTrue(isset($profile['summary']),          'summary must be present');
            $this->assertTrue(isset($profile['signals_observed']), 'signals_observed must be present');
            $this->assertTrue(is_array($profile['signals_observed']) && !empty($profile['signals_observed']),
                'signals_observed must be a non-empty array');

            foreach ($profile['signals_observed'] as $obs) {
                $this->assertTrue(isset($obs['signal_id']),      'signal evidence must have signal_id');
                $this->assertTrue(isset($obs['signal_label']),   'signal evidence must have signal_label');
                $this->assertTrue(isset($obs['file']),           'signal evidence must have file');
                $this->assertTrue(isset($obs['matched_string']), 'signal evidence must have matched_string');
            }
        }
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier2_profile_summary_references_signals()
    {
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);

        foreach ($detection['behavior_profiles'] as $profile) {
            $summary = $profile['summary'];
            $this->assertTrue(
                strlen($summary) > 20,
                'Summary must be a substantive sentence (Spec 005 §10, criterion 4)'
            );
            $this->assertTrue(
                strpos($summary, '`') !== false,
                'Summary must reference specific signal strings using backtick notation: ' . $profile['profile_id']
            );
        }
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    private function test_tier2_evidence_invariant_behavior_profiles_key_always_present()
    {
        // behavior_profiles must exist in the detection return value regardless of tier
        $this->resetState();
        $slug = $this->makeTier2Plugin();

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['review_required'], $slug);

        $this->assertTrue(
            array_key_exists('behavior_profiles', $detection),
            'behavior_profiles key must always be present in detection result'
        );
        $this->cleanup(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
    }

    // ─── Tier 1 regression: full remediation path must be unchanged ───────────

    private function test_regression_tier1_full_remediation_path()
    {
        $this->resetState();
        $slug = 'macrolayer-macroflag';
        $this->makeKnownMalwarePlugin($slug);

        $report    = MSP_PG_Remediator::run_scan('test');
        $detection = $this->findDetection($report['confirmed_malware'], $slug);

        $this->assertNotNull($detection, 'macrolayer-macroflag must be confirmed malware');
        $this->assertTrue(in_array('CONFIRMED_MALWARE_IDENTIFIED', $detection['actions'], true), 'Must record confirmed identification');
        $this->assertTrue(in_array('BUNDLE_VERIFIED', $detection['actions'], true),              'Evidence invariant: BUNDLE_VERIFIED must fire');
        $this->assertTrue(in_array('LIVE_PLUGIN_REMOVED', $detection['actions'], true),          'Tier 1 must be auto-remediated');
        $this->assertTrue(empty($detection['behavior_profiles']),                                 'Tier 1 must not have behavior profiles');

        $evidence = $this->readEvidence($detection);
        $this->assertTrue(file_exists($detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'evidence.json'),
            'evidence.json must exist after Tier 1 remediation');
        $this->assertTrue(array_key_exists('score', $evidence),           'Tier 1 evidence.json must retain score');
        $this->assertFalse(array_key_exists('behavior_profiles', $evidence) && !empty($evidence['behavior_profiles']),
            'Tier 1 evidence.json must not have non-empty behavior_profiles');
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    private function makeKnownMalwarePlugin($slug)
    {
        $dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug;
        wp_mkdir_p($dir);
        file_put_contents($dir . DIRECTORY_SEPARATOR . $slug . '.php', "<?php\n/**\n * Plugin Name: $slug\n */\n");
        $active   = (array) get_option('active_plugins', array());
        $active[] = $slug . '/' . $slug . '.php';
        update_option('active_plugins', array_unique($active));
    }

    private function makeTier2Plugin()
    {
        // Scores exactly 100 with the additive model, Tier 2:
        //   known_family_bootstrap_pattern (+50): fastreactic_nanomicroserviceing
        //   known_family_payload_structure (+50): 5-char dir + 8-char PHP file
        // Behaviorally activates: Command & Control (KB-02) and Payload Delivery (SP-01)
        $slug       = 'test-tier2-' . uniqid();
        $pluginDir  = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug;
        $payloadDir = $pluginDir . DIRECTORY_SEPARATOR . 'abc12';

        wp_mkdir_p($pluginDir);
        wp_mkdir_p($payloadDir);

        file_put_contents(
            $pluginDir . DIRECTORY_SEPARATOR . $slug . '.php',
            "<?php\n/**\n * Plugin Name: $slug\n */\nif (!defined('ABSPATH')) { exit; }\n// fastreactic_nanomicroserviceing\n"
        );
        file_put_contents(
            $payloadDir . DIRECTORY_SEPARATOR . 'defgh123.php',
            "<?php // payload staging"
        );

        update_option('active_plugins', array($slug . '/' . $slug . '.php'));
        return $slug;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resetState()
    {
        $GLOBALS['msp_pg_test_options']            = array();
        $GLOBALS['msp_pg_test_transients']         = array();
        $GLOBALS['msp_pg_test_deactivated_plugins'] = array();
        $GLOBALS['msp_pg_test_scheduled_events']   = array();

        foreach (glob(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: array() as $dir) {
            $this->cleanup($dir);
        }
    }

    private function readEvidence($detection)
    {
        $path = $detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'evidence.json';
        $this->assertTrue(file_exists($path), 'evidence.json must exist at ' . $path);
        return json_decode(file_get_contents($path), true);
    }

    private function findDetection($detections, $slug)
    {
        foreach ($detections as $d) {
            if ($d['plugin_slug'] === $slug) {
                return $d;
            }
        }
        return null;
    }

    private function assertTrue($condition, $message)
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function assertFalse($condition, $message)
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }

    private function assertNotNull($value, $message)
    {
        if ($value === null) {
            throw new RuntimeException($message . ' (got null)');
        }
    }

    private function cleanup($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

$test = new ExplainableOutputTest();
$test->run();
echo "ExplainableOutputTest passed\n";
