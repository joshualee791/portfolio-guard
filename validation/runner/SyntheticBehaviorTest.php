<?php

require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'portfolio-guard' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

class SyntheticBehaviorTest
{
    private $manifestPath;
    private $fixtureRoot;
    private $ownWorkspace = null;

    public function run()
    {
        $this->manifestPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'corpus' . DIRECTORY_SEPARATOR . 'synthetic' . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->fixtureRoot  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'corpus' . DIRECTORY_SEPARATOR . 'synthetic';

        if (!defined('WP_PLUGIN_DIR')) {
            $this->ownWorkspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msp-pg-synth-' . uniqid();
            wp_mkdir_p($this->ownWorkspace . DIRECTORY_SEPARATOR . 'plugins');
            wp_mkdir_p($this->ownWorkspace . DIRECTORY_SEPARATOR . 'mu-plugins');
            define('WP_PLUGIN_DIR',  $this->ownWorkspace . DIRECTORY_SEPARATOR . 'plugins');
            define('WPMU_PLUGIN_DIR', $this->ownWorkspace . DIRECTORY_SEPARATOR . 'mu-plugins');
            if (empty($GLOBALS['msp_pg_test_uploads_base'])) {
                $GLOBALS['msp_pg_test_uploads_base'] = $this->ownWorkspace . DIRECTORY_SEPARATOR . 'uploads';
                wp_mkdir_p($GLOBALS['msp_pg_test_uploads_base']);
            }
        }

        $manifest       = $this->loadManifest();
        $passed         = 0;
        $failed         = 0;
        $blockingFailed = 0;
        $results        = array();

        foreach ($manifest['entries'] as $entry) {
            $result = $this->runEntry($entry);

            if ($result['status'] === 'pass') {
                $results[] = '[PASS] SyntheticBehaviorTest: ' . $entry['slug'] . ' — Tier 1 not triggered, tier2 detected';
                $passed++;
            } elseif ($result['status'] === 'warn') {
                $results[] = '[WARN] SyntheticBehaviorTest: ' . $entry['slug'] . ' — ' . $result['message'] . ' (non-blocking, Phase 3 target)';
                $failed++;
            } else {
                $results[] = '[FAIL] SyntheticBehaviorTest: ' . $entry['slug'] . ' — ' . $result['message'];
                $failed++;
                $blockingFailed++;
            }
        }

        if ($this->ownWorkspace) {
            $this->cleanup($this->ownWorkspace);
        }

        return array(
            'results'         => $results,
            'passed'          => $passed,
            'failed'          => $failed,
            'blocking_failed' => $blockingFailed,
            'total'           => count($manifest['entries']),
        );
    }

    private function loadManifest()
    {
        if (!file_exists($this->manifestPath)) {
            throw new RuntimeException('Synthetic manifest not found: ' . $this->manifestPath);
        }
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        if (!is_array($manifest) || !isset($manifest['entries'])) {
            throw new RuntimeException('Invalid synthetic manifest format');
        }
        return $manifest;
    }

    private function runEntry($entry)
    {
        $slug       = $entry['slug'];
        $fixtureDir = $this->fixtureRoot . DIRECTORY_SEPARATOR . $entry['fixture_dir'];
        $pluginDir  = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug;

        if (!is_dir($fixtureDir)) {
            return array('status' => 'fail', 'message' => 'fixture directory not found: ' . $entry['fixture_dir']);
        }

        if (is_dir($pluginDir)) {
            $this->cleanup($pluginDir);
        }
        wp_mkdir_p($pluginDir);
        $this->copyDirectory($fixtureDir, $pluginDir);

        $GLOBALS['msp_pg_test_options']            = array('active_plugins' => array($slug . '/' . $slug . '.php'));
        $GLOBALS['msp_pg_test_transients']         = array();
        $GLOBALS['msp_pg_test_deactivated_plugins'] = array();

        $analysis = MSP_PG_Detector::detect($pluginDir);

        // Assertion 1 (always blocking): must NOT trigger Tier 1
        if ($analysis !== null && $analysis['tier'] === 'tier1') {
            $this->cleanup($pluginDir);
            return array('status' => 'fail', 'message' => 'must_not_trigger_tier1 violated — got tier1');
        }

        // Assertion 2 (always blocking): slug must not be in signature registry
        if (in_array($slug, MSP_PG_Signatures::known_slugs(), true)) {
            $this->cleanup($pluginDir);
            return array('status' => 'fail', 'message' => 'synthetic slug conflicts with registered malware slug');
        }

        $tier2Achieved = ($analysis !== null && $analysis['tier'] === 'tier2');

        $this->cleanup($pluginDir);

        // Assertion 3 (conditional blocking): Tier 2 detection
        if ($entry['gate_blocking'] && !$tier2Achieved) {
            $actual = $analysis ? $analysis['tier'] : 'null';
            return array('status' => 'fail', 'message' => 'expected tier2, got ' . $actual);
        }

        if (!$tier2Achieved) {
            $actual = $analysis ? $analysis['tier'] : 'null';
            return array('status' => 'warn', 'message' => 'expected tier2, got ' . $actual);
        }

        return array('status' => 'pass', 'message' => '');
    }

    private function copyDirectory($source, $destination)
    {
        if (!is_dir($source)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = ltrim(
                substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $source))),
                '/'
            );
            $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } else {
                wp_mkdir_p(dirname($target));
                copy($item->getPathname(), $target);
            }
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
