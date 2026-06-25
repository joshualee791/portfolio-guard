<?php

require_once __DIR__ . '/bootstrap.php';

class SignatureRegistryTest
{
    private $workspace;

    public function run()
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msp-pg-tests-' . uniqid();
        wp_mkdir_p($this->workspace);

        $pluginRoot = $this->workspace . DIRECTORY_SEPARATOR . 'plugins';
        wp_mkdir_p($pluginRoot);

        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', $pluginRoot);
        }

        if (!defined('WPMU_PLUGIN_DIR')) {
            define('WPMU_PLUGIN_DIR', $this->workspace . DIRECTORY_SEPARATOR . 'mu-plugins');
        }

        $GLOBALS['msp_pg_test_uploads_base'] = $this->workspace . DIRECTORY_SEPARATOR . 'uploads';
        wp_mkdir_p($GLOBALS['msp_pg_test_uploads_base']);

        $families = array(
            'uniserviceist-multiinfrastructure',
            'miniapplicationing-protypescriptic',
            'these-middleware',
            'macrolayer-macroflag',
        );

        foreach ($families as $slug) {
            $dir = $pluginRoot . DIRECTORY_SEPARATOR . $slug;
            wp_mkdir_p($dir);
            file_put_contents(
                $dir . DIRECTORY_SEPARATOR . $slug . '.php',
                "<?php\n/*\nPlugin Name: {$slug}\n*/\n"
            );
        }

        $GLOBALS['msp_pg_test_options'] = array(
            'active_plugins' => array_map(function ($slug) {
                return $slug . '/' . $slug . '.php';
            }, $families),
        );
        $GLOBALS['msp_pg_test_transients'] = array();
        $GLOBALS['msp_pg_test_deactivated_plugins'] = array();

        foreach ($families as $slug) {
            $analysis = MSP_PG_Detector::detect($pluginRoot . DIRECTORY_SEPARATOR . $slug);
            $this->assertTrue(is_array($analysis), $slug . ' should produce a detection');
            $this->assertSame('tier1', $analysis['tier'], $slug . ' should classify as Tier 1');
            $this->assertSame('Exact Match', $analysis['confidence'], $slug . ' should have Exact Match confidence');
            $this->assertSame('Built-In Signature Registry', $analysis['detection_source'], $slug . ' should report Built-In Signature Registry source');
            $this->assertTrue(in_array('known_plugin_directory', $analysis['exact_match_types'], true), $slug . ' should match known plugin directory');
            $this->assertTrue(in_array('known_primary_plugin_file', $analysis['exact_match_types'], true), $slug . ' should match known primary plugin file');
        }

        $macrolayerVariant = MSP_PG_Signatures::variant_by_slug('macrolayer-macroflag');
        $this->assertSame('macrolayer-macroflag.php', $macrolayerVariant['main_file'], 'macrolayer-macroflag should register its primary plugin file');
        $this->assertTrue(in_array('grojechootiosta.com', $macrolayerVariant['domains'], true), 'macrolayer-macroflag should register known domains');
        $this->assertTrue(in_array('macrolayer-macroflag-3c6a523036d2/v1', $macrolayerVariant['routes'], true), 'macrolayer-macroflag should register its REST namespace');
        $this->assertTrue(isset(MSP_PG_Signatures::known_hashes()['782155B2EFFB65E5FA9DC61A3FE5FDA00DC85E10D134CEF295D1D4AFD4CDE979']), 'macrolayer-macroflag should register known hashes');

        $report = MSP_PG_Remediator::run_scan('unit-test');
        $this->assertSame(4, count($report['confirmed_malware']), 'All four tracked families should be confirmed malware');
        $this->assertSame('metadata_only', MSP_PG_Config::evidence_retention_mode(), 'Default evidence retention mode should be metadata_only');

        foreach ($families as $slug) {
            $detection = $this->findDetection($report['confirmed_malware'], $slug);
            $this->assertTrue(!empty($detection), $slug . ' should appear in confirmed malware report');
            $this->assertSame('Exact Match', $detection['confidence'], $slug . ' report confidence should be Exact Match');
            $this->assertSame('Built-In Signature Registry', $detection['source'], $slug . ' report source should be Built-In Signature Registry');
            $this->assertSame('metadata_only', $detection['evidence_retention_mode'], $slug . ' should default to metadata_only evidence retention');
            $this->assertTrue(in_array('CONFIRMED_MALWARE_IDENTIFIED', $detection['actions'], true), $slug . ' should record confirmed malware identification');
            $this->assertTrue(in_array('EVIDENCE_MANIFEST_CREATED', $detection['actions'], true), $slug . ' should create an evidence manifest');
            $this->assertTrue(in_array('BUNDLE_VERIFIED', $detection['actions'], true), $slug . ' should verify evidence preservation');
            $this->assertTrue(in_array('QUARANTINE_COMPLETED', $detection['actions'], true), $slug . ' should complete temporary quarantine');
            $this->assertTrue(in_array('LIVE_PLUGIN_REMOVED', $detection['actions'], true), $slug . ' should be eligible for automatic remediation');
            $this->assertTrue(file_exists($detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'evidence.json'), $slug . ' should have evidence.json');
            $this->assertFalse(file_exists($detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'artifact.zip'), $slug . ' should not create a ZIP archive in metadata_only mode');
            $this->assertFalse(is_dir($detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'snapshot'), $slug . ' should not retain a snapshot directory in metadata_only mode');
            $this->assertFalse(is_dir($detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'quarantine'), $slug . ' should not retain a quarantine directory in metadata_only mode');
            $this->assertFalse($this->containsRetainedExecutable($detection['artifact_dir']), $slug . ' should not retain PHP or JS artifacts in remediation storage');
        }

        $this->assertSame(4, count($GLOBALS['msp_pg_test_deactivated_plugins']), 'All four tracked families should be deactivated');

        $this->assertFalse(array_key_exists('safe_mode', $report), 'Scan report must not contain safe_mode field');
        $this->assertFalse(array_key_exists('allow_tier1_remediation', $report), 'Scan report must not contain allow_tier1_remediation field');

        foreach ($families as $slug) {
            $detection = $this->findDetection($report['confirmed_malware'], $slug);
            $this->assertTrue(in_array('LIVE_PLUGIN_REMOVED', $detection['actions'], true), $slug . ': LIVE_PLUGIN_REMOVED requires BUNDLE_VERIFIED to have preceded it');
            $this->assertTrue(in_array('BUNDLE_VERIFIED', $detection['actions'], true), $slug . ': evidence invariant — BUNDLE_VERIFIED must accompany LIVE_PLUGIN_REMOVED');
            $this->assertTrue(file_exists($detection['artifact_dir'] . DIRECTORY_SEPARATOR . 'evidence.json'), $slug . ': evidence invariant — evidence.json must exist when LIVE_PLUGIN_REMOVED is recorded');
        }

        $this->cleanup($this->workspace);
    }

    private function findDetection($detections, $slug)
    {
        foreach ($detections as $detection) {
            if ($detection['plugin_slug'] === $slug) {
                return $detection;
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

    private function assertSame($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Expected `' . var_export($expected, true) . '` but got `' . var_export($actual, true) . '`.');
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
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function containsRetainedExecutable($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && preg_match('/\.(php|js)$/i', $item->getFilename())) {
                return true;
            }
        }

        return false;
    }

    private function assertFalse($condition, $message)
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }
}

$test = new SignatureRegistryTest();
$test->run();
echo "SignatureRegistryTest passed\n";
