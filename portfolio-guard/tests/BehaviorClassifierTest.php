<?php

require_once __DIR__ . '/bootstrap.php';

class BehaviorClassifierTest
{
    private $workspace;
    private $syntheticRoot;
    private $knownMalwareRoot;

    public function run()
    {
        $this->workspace        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msp-pg-classifier-' . uniqid();
        $this->syntheticRoot    = dirname(dirname(dirname(__FILE__)))
            . DIRECTORY_SEPARATOR . 'validation'
            . DIRECTORY_SEPARATOR . 'corpus'
            . DIRECTORY_SEPARATOR . 'synthetic';
        $this->knownMalwareRoot = dirname(dirname(dirname(__FILE__)))
            . DIRECTORY_SEPARATOR . 'validation'
            . DIRECTORY_SEPARATOR . 'corpus'
            . DIRECTORY_SEPARATOR . 'known-malware';

        wp_mkdir_p($this->workspace);

        // ── Activation rules ──────────────────────────────────────────────────
        $this->test_empty_observations_produces_no_profiles();
        $this->test_persistence_activates_on_hp01();
        $this->test_persistence_activates_on_hp02();
        $this->test_persistence_does_not_activate_on_fc01_alone();
        $this->test_c2_activates_on_sm_string();
        $this->test_c2_activates_on_fc01_plus_fc02();
        $this->test_c2_activates_on_fc01_plus_cb01();
        $this->test_c2_does_not_activate_on_fc01_alone();
        $this->test_c2_does_not_activate_on_fc02_alone();
        $this->test_payload_activates_on_dm01();
        $this->test_payload_activates_on_sp01();
        $this->test_payload_does_not_activate_on_fc07_alone();
        $this->test_operator_access_activates_on_fc03();
        $this->test_operator_access_activates_on_kb01();
        $this->test_stealth_activates_on_hp01();
        $this->test_stealth_activates_on_cb01_plus_fc01();
        $this->test_stealth_does_not_activate_on_cb01_alone();
        $this->test_multi_profile_activation();

        // ── Output structure ──────────────────────────────────────────────────
        $this->test_profile_record_contains_required_fields();
        $this->test_evidence_contains_activating_signals();
        $this->test_summary_contains_matched_string();
        $this->test_summary_contains_file_name();
        $this->test_summary_is_not_empty_template();

        // ── Synthetic corpus integration ──────────────────────────────────────
        $this->test_synthetic_c2_channel();
        $this->test_synthetic_payload_delivery();
        $this->test_synthetic_operator_access();
        $this->test_synthetic_persistence();
        $this->test_synthetic_stealth();

        // ── Known malware and clean plugin validation ─────────────────────────
        $this->test_known_malware_fixture_produces_no_behavioral_profiles();
        $this->test_minimal_clean_plugin_produces_no_profiles();

        // ── Determinism ───────────────────────────────────────────────────────
        $this->test_determinism();

        $this->cleanup($this->workspace);
    }

    // ── Activation rule tests ─────────────────────────────────────────────────

    private function test_empty_observations_produces_no_profiles()
    {
        $result = MSP_PG_BehaviorClassifier::classify(array());
        $this->assertTrue(empty($result), 'No profiles should activate from empty observations');
    }

    private function test_persistence_activates_on_hp01()
    {
        $obs = $this->fakeObs('HP-01', 'Plugin list filter', 'plugin.php', "add_filter('all_plugins'");
        $this->assertActivates('persistence', $obs, 'Persistence should activate on HP-01');
    }

    private function test_persistence_activates_on_hp02()
    {
        $obs = $this->fakeObs('HP-02', 'Template redirect hook', 'plugin.php', "add_action('template_redirect'");
        $this->assertActivates('persistence', $obs, 'Persistence should activate on HP-02');
    }

    private function test_persistence_does_not_activate_on_fc01_alone()
    {
        $obs = $this->fakeObs('FC-01', 'REST endpoint registration', 'plugin.php', 'register_rest_route(');
        $this->assertNotActivates('persistence', $obs, 'Persistence must not activate on FC-01 alone');
    }

    private function test_c2_activates_on_sm_string()
    {
        foreach (array('SM-01', 'SM-02', 'SM-03', 'SM-04', 'SM-05') as $smId) {
            $obs = $this->fakeObs($smId, 'String marker', 'plugin.php', 'marker-value');
            $this->assertActivates('command-and-control', $obs, 'C2 should activate on ' . $smId);
        }
        $obs = $this->fakeObs('KB-02', 'Bootstrap pattern', 'plugin.php', 'fastreactic_nanomicroserviceing');
        $this->assertActivates('command-and-control', $obs, 'C2 should activate on KB-02');
    }

    private function test_c2_activates_on_fc01_plus_fc02()
    {
        $obs = array_merge(
            $this->fakeObs('FC-01', 'REST endpoint registration', 'plugin.php', 'register_rest_route('),
            $this->fakeObs('FC-02', 'Outbound HTTP request', 'plugin.php', 'wp_remote_get(')
        );
        $this->assertActivates('command-and-control', $obs, 'C2 should activate on FC-01 + FC-02');
    }

    private function test_c2_activates_on_fc01_plus_cb01()
    {
        $obs = array_merge(
            $this->fakeObs('FC-01', 'REST endpoint registration', 'plugin.php', 'register_rest_route('),
            $this->fakeObs('CB-01', 'Unauthenticated REST access', 'plugin.php', "permission_callback' => '__return_true'")
        );
        $this->assertActivates('command-and-control', $obs, 'C2 should activate on FC-01 + CB-01');
    }

    private function test_c2_does_not_activate_on_fc01_alone()
    {
        $obs = $this->fakeObs('FC-01', 'REST endpoint registration', 'plugin.php', 'register_rest_route(');
        $this->assertNotActivates('command-and-control', $obs, 'C2 must not activate on FC-01 alone — common in clean plugins');
    }

    private function test_c2_does_not_activate_on_fc02_alone()
    {
        $obs = $this->fakeObs('FC-02', 'Outbound HTTP request', 'plugin.php', 'wp_remote_get(');
        $this->assertNotActivates('command-and-control', $obs, 'C2 must not activate on FC-02 alone — common in clean plugins');
    }

    private function test_payload_activates_on_dm01()
    {
        $obs = $this->fakeObs('DM-01', 'Dynamic JavaScript element creation', 'plugin.php', "createElement('script')");
        $this->assertActivates('payload-delivery', $obs, 'Payload Delivery should activate on DM-01');
    }

    private function test_payload_activates_on_sp01()
    {
        $obs = $this->fakeObs('SP-01', 'Concealed payload staging structure', 'abc12', 'directory:abc12, file:defgh123.php');
        $this->assertActivates('payload-delivery', $obs, 'Payload Delivery should activate on SP-01');
    }

    private function test_payload_does_not_activate_on_fc07_alone()
    {
        $obs = $this->fakeObs('FC-07', 'Script registration or inline injection', 'plugin.php', 'wp_enqueue_script(');
        $this->assertNotActivates('payload-delivery', $obs, 'Payload Delivery must not activate on FC-07 alone — extremely common in clean plugins');
    }

    private function test_operator_access_activates_on_fc03()
    {
        $obs = $this->fakeObs('FC-03', 'Authentication cookie creation', 'plugin.php', 'wp_set_auth_cookie(');
        $this->assertActivates('operator-access', $obs, 'Operator Access should activate on FC-03');
    }

    private function test_operator_access_activates_on_kb01()
    {
        $obs = $this->fakeObs('KB-01', 'Known authentication impersonation triplet', 'plugin.php', 'some_id_param');
        $this->assertActivates('operator-access', $obs, 'Operator Access should activate on KB-01');
    }

    private function test_stealth_activates_on_hp01()
    {
        $obs = $this->fakeObs('HP-01', 'Plugin list filter', 'plugin.php', "add_filter('all_plugins'");
        $this->assertActivates('stealth', $obs, 'Stealth should activate on HP-01');
    }

    private function test_stealth_activates_on_cb01_plus_fc01()
    {
        $obs = array_merge(
            $this->fakeObs('CB-01', 'Unauthenticated REST access', 'plugin.php', "permission_callback' => '__return_true'"),
            $this->fakeObs('FC-01', 'REST endpoint registration', 'plugin.php', 'register_rest_route(')
        );
        $this->assertActivates('stealth', $obs, 'Stealth should activate on CB-01 + FC-01');
    }

    private function test_stealth_does_not_activate_on_cb01_alone()
    {
        $obs = $this->fakeObs('CB-01', 'Unauthenticated REST access', 'plugin.php', "permission_callback' => '__return_true'");
        $this->assertNotActivates('stealth', $obs, 'Stealth must not activate on CB-01 alone');
    }

    private function test_multi_profile_activation()
    {
        // HP-01 activates both Persistence and Stealth
        $obs = $this->fakeObs('HP-01', 'Plugin list filter', 'plugin.php', "add_filter('all_plugins'");
        $result = MSP_PG_BehaviorClassifier::classify($obs);

        $ids = array_column($result, 'profile_id');
        $this->assertTrue(in_array('persistence', $ids, true), 'HP-01 should activate Persistence');
        $this->assertTrue(in_array('stealth', $ids, true), 'HP-01 should also activate Stealth');
        $this->assertTrue(count($result) >= 2, 'Multiple profiles should activate from HP-01');
    }

    // ── Output structure tests ────────────────────────────────────────────────

    private function test_profile_record_contains_required_fields()
    {
        $obs    = $this->fakeObs('HP-01', 'Plugin list filter', 'plugin.php', "add_filter('all_plugins'");
        $result = MSP_PG_BehaviorClassifier::classify($obs);
        $this->assertTrue(!empty($result), 'At least one profile should activate');

        $record = $result[0];
        $this->assertTrue(isset($record['profile_id']),       'profile_id must be present');
        $this->assertTrue(isset($record['profile_label']),    'profile_label must be present');
        $this->assertTrue(isset($record['summary']),          'summary must be present');
        $this->assertTrue(isset($record['signals_observed']), 'signals_observed must be present');
        $this->assertTrue(is_array($record['signals_observed']), 'signals_observed must be an array');
    }

    private function test_evidence_contains_activating_signals()
    {
        $obs = array_merge(
            $this->fakeObs('HP-01', 'Plugin list filter', 'plugin.php', "add_filter('all_plugins'"),
            $this->fakeObs('FC-01', 'REST endpoint registration', 'plugin.php', 'register_rest_route(')
        );
        $result = MSP_PG_BehaviorClassifier::classify($obs);

        $persistenceRecord = $this->findProfile($result, 'persistence');
        $this->assertTrue($persistenceRecord !== null, 'persistence profile should be in result');

        $evidenceSignalIds = array_column($persistenceRecord['signals_observed'], 'signal_id');
        $this->assertTrue(in_array('HP-01', $evidenceSignalIds, true), 'HP-01 must appear in persistence evidence');
    }

    private function test_summary_contains_matched_string()
    {
        $matchedString = "add_filter('all_plugins'";
        $obs    = $this->fakeObs('HP-01', 'Plugin list filter', 'plugin.php', $matchedString);
        $result = MSP_PG_BehaviorClassifier::classify($obs);

        $record = $this->findProfile($result, 'stealth');
        $this->assertTrue($record !== null, 'stealth should activate on HP-01');
        $this->assertTrue(
            strpos($record['summary'], $matchedString) !== false,
            'summary must reference the matched string (`' . $matchedString . '`)'
        );
    }

    private function test_summary_contains_file_name()
    {
        $fileName = 'core-logic.php';
        $obs    = $this->fakeObs('FC-03', 'Authentication cookie creation', $fileName, 'wp_set_auth_cookie(');
        $result = MSP_PG_BehaviorClassifier::classify($obs);

        $record = $this->findProfile($result, 'operator-access');
        $this->assertTrue($record !== null, 'operator-access should activate on FC-03');
        $this->assertTrue(
            strpos($record['summary'], $fileName) !== false,
            'summary must reference the file name (' . $fileName . ')'
        );
    }

    private function test_summary_is_not_empty_template()
    {
        $obs    = $this->fakeObs('DM-01', 'Dynamic JavaScript element creation', 'plugin.php', "createElement('script')");
        $result = MSP_PG_BehaviorClassifier::classify($obs);

        $record = $this->findProfile($result, 'payload-delivery');
        $this->assertTrue($record !== null, 'payload-delivery should activate');
        $this->assertTrue(strlen($record['summary']) > 20, 'summary must be a non-trivial sentence');
        $this->assertTrue(strpos($record['summary'], 'exhibits behavioral signals without further detail') === false, 'generic fallback summary must not be used');
    }

    // ── Synthetic corpus integration ──────────────────────────────────────────

    private function test_synthetic_c2_channel()
    {
        $dir = $this->syntheticRoot . DIRECTORY_SEPARATOR . 'synthetic-c2-channel';
        if (!is_dir($dir)) {
            return;
        }
        $result = $this->classifyDirectory($dir);
        $this->assertProfilePresent($result, 'command-and-control', 'synthetic-c2-channel must activate command-and-control');
    }

    private function test_synthetic_payload_delivery()
    {
        $dir = $this->syntheticRoot . DIRECTORY_SEPARATOR . 'synthetic-payload-delivery';
        if (!is_dir($dir)) {
            return;
        }
        $result = $this->classifyDirectory($dir);
        $this->assertProfilePresent($result, 'payload-delivery', 'synthetic-payload-delivery must activate payload-delivery');
    }

    private function test_synthetic_operator_access()
    {
        $dir = $this->syntheticRoot . DIRECTORY_SEPARATOR . 'synthetic-operator-access';
        if (!is_dir($dir)) {
            return;
        }
        $result = $this->classifyDirectory($dir);
        $this->assertProfilePresent($result, 'operator-access', 'synthetic-operator-access must activate operator-access');
    }

    private function test_synthetic_persistence()
    {
        $dir = $this->syntheticRoot . DIRECTORY_SEPARATOR . 'synthetic-persistence';
        if (!is_dir($dir)) {
            return;
        }
        $result = $this->classifyDirectory($dir);
        $this->assertProfilePresent($result, 'persistence', 'synthetic-persistence must activate persistence');
    }

    private function test_synthetic_stealth()
    {
        $dir = $this->syntheticRoot . DIRECTORY_SEPARATOR . 'synthetic-stealth';
        if (!is_dir($dir)) {
            return;
        }
        $result = $this->classifyDirectory($dir);
        $this->assertProfilePresent($result, 'stealth', 'synthetic-stealth must activate stealth');
    }

    // ── Known malware and clean plugin validation ─────────────────────────────

    private function test_known_malware_fixture_produces_no_behavioral_profiles()
    {
        // Known malware fixtures are minimal PHP plugin headers — no behavioral signals.
        // These families are caught by Tier 1 signature matching, not behavioral profiles.
        $dir = $this->knownMalwareRoot . DIRECTORY_SEPARATOR . 'laravel-janet';
        if (!is_dir($dir)) {
            return;
        }
        $result = $this->classifyDirectory($dir);
        $this->assertTrue(
            empty($result),
            'A known malware fixture with only a plugin header should produce no behavioral profile activations'
        );
    }

    private function test_minimal_clean_plugin_produces_no_profiles()
    {
        $dir = $this->workspace . DIRECTORY_SEPARATOR . 'clean-minimal-' . uniqid();
        wp_mkdir_p($dir);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'clean.php',
            "<?php\n/**\n * Plugin Name: Minimal Clean Plugin\n */\n"
        );

        $result = $this->classifyDirectory($dir);
        $this->assertTrue(empty($result), 'A minimal clean plugin should produce no profile activations');

        $this->cleanup($dir);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    private function test_determinism()
    {
        $dir = $this->syntheticRoot . DIRECTORY_SEPARATOR . 'synthetic-persistence';
        if (!is_dir($dir)) {
            return;
        }
        $run1 = $this->classifyDirectory($dir);
        $run2 = $this->classifyDirectory($dir);
        $this->assertTrue($run1 === $run2, 'classify() must return identical results on repeated calls');
    }

    // ── Test helpers ──────────────────────────────────────────────────────────

    private function classifyDirectory($dir)
    {
        return MSP_PG_BehaviorClassifier::classify(MSP_PG_FeatureExtractor::extract($dir));
    }

    private function fakeObs($signalId, $signalLabel, $file, $matchedString)
    {
        return array(array(
            'signal_id'      => $signalId,
            'signal_label'   => $signalLabel,
            'file'           => $file,
            'matched_string' => $matchedString,
        ));
    }

    private function findProfile(array $result, $profileId)
    {
        foreach ($result as $record) {
            if ($record['profile_id'] === $profileId) {
                return $record;
            }
        }
        return null;
    }

    private function assertActivates($profileId, array $observations, $message)
    {
        if (!MSP_PG_BehaviorClassifier::activates($profileId, $observations)) {
            throw new RuntimeException($message . ' — profile did not activate');
        }
    }

    private function assertNotActivates($profileId, array $observations, $message)
    {
        if (MSP_PG_BehaviorClassifier::activates($profileId, $observations)) {
            throw new RuntimeException($message . ' — profile activated when it should not have');
        }
    }

    private function assertProfilePresent(array $result, $profileId, $message)
    {
        if ($this->findProfile($result, $profileId) === null) {
            throw new RuntimeException($message . ' — profile_id "' . $profileId . '" not found in result');
        }
    }

    private function assertTrue($condition, $message)
    {
        if (!$condition) {
            throw new RuntimeException($message);
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

$test = new BehaviorClassifierTest();
$test->run();
echo "BehaviorClassifierTest passed\n";
