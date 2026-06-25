<?php
/**
 * Portfolio Guard Validation Gate
 *
 * Usage: php validation/gate.php
 *
 * Exit codes:
 *   0 — all blocking tests passed
 *   1 — one or more blocking tests failed
 *   2 — gate could not run (missing files, PHP error)
 */

define('VALIDATION_ROOT', __DIR__);
define('PORTFOLIO_GUARD_TEST_ROOT',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'portfolio-guard' . DIRECTORY_SEPARATOR . 'tests'
);

$bootstrapPath = PORTFOLIO_GUARD_TEST_ROOT . DIRECTORY_SEPARATOR . 'bootstrap.php';
if (!file_exists($bootstrapPath)) {
    fwrite(STDERR, "[ERROR] bootstrap.php not found at: $bootstrapPath\n");
    exit(2);
}
require_once $bootstrapPath;

// Gate-level workspace: shared by all runners that need WP_PLUGIN_DIR
$gateWorkspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msp-pg-gate-' . uniqid();
wp_mkdir_p($gateWorkspace . DIRECTORY_SEPARATOR . 'plugins');
wp_mkdir_p($gateWorkspace . DIRECTORY_SEPARATOR . 'mu-plugins');
wp_mkdir_p($gateWorkspace . DIRECTORY_SEPARATOR . 'uploads');

if (!defined('WP_PLUGIN_DIR'))  define('WP_PLUGIN_DIR',  $gateWorkspace . DIRECTORY_SEPARATOR . 'plugins');
if (!defined('WPMU_PLUGIN_DIR')) define('WPMU_PLUGIN_DIR', $gateWorkspace . DIRECTORY_SEPARATOR . 'mu-plugins');

$GLOBALS['msp_pg_test_uploads_base'] = $gateWorkspace . DIRECTORY_SEPARATOR . 'uploads';

// Include runner classes (class definitions only; no auto-run)
require_once VALIDATION_ROOT . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'KnownMalwareTest.php';
require_once VALIDATION_ROOT . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'CleanPluginTest.php';
require_once VALIDATION_ROOT . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'UpdateInfrastructureTest.php';
require_once VALIDATION_ROOT . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'SyntheticBehaviorTest.php';

$overallFailed = false;

// ─────────────────────────────────────────────────────────────────────────────
// 1. Known Malware (blocking)
// ─────────────────────────────────────────────────────────────────────────────
$km = (new KnownMalwareTest())->run();
foreach ($km['results'] as $line) {
    echo $line . "\n";
}
echo "\n";

if ($km['failed'] > 0) {
    $overallFailed = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Clean Plugins (blocking)
// ─────────────────────────────────────────────────────────────────────────────
$cp = (new CleanPluginTest())->run();
foreach ($cp['results'] as $line) {
    echo $line . "\n";
}
echo "\n";

if ($cp['failed'] > 0) {
    $overallFailed = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Update Infrastructure (blocking)
// ─────────────────────────────────────────────────────────────────────────────
$ui = (new UpdateInfrastructureTest())->run();
foreach ($ui['results'] as $line) {
    echo $line . "\n";
}
echo "\n";

if ($ui['failed'] > 0) {
    $overallFailed = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Synthetic Behaviors (gate_blocking per-entry; non-blocking in Phase 2)
// ─────────────────────────────────────────────────────────────────────────────
$sb = (new SyntheticBehaviorTest())->run();
foreach ($sb['results'] as $line) {
    echo $line . "\n";
}
echo "\n";

if ($sb['blocking_failed'] > 0) {
    $overallFailed = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Summary
// ─────────────────────────────────────────────────────────────────────────────
$syntheticLabel = $sb['blocking_failed'] > 0
    ? sprintf('%d / %d passed', $sb['passed'], $sb['total'])
    : sprintf('%d / %d passed (non-blocking in Phase 2)', $sb['passed'], $sb['total']);

echo "--- Portfolio Guard Validation Gate ---\n";
echo sprintf("Known Malware:         %d / %d passed\n", $km['passed'], $km['total']);
echo sprintf("Clean Plugins:         %d / %d passed\n", $cp['passed'], $cp['total']);
echo sprintf("Update Infrastructure: %d / %d passed\n", $ui['passed'], $ui['total']);
echo sprintf("Synthetic:             %s\n", $syntheticLabel);
echo "\n";

// Gate workspace cleanup
$cleanupIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($gateWorkspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanupIterator as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($gateWorkspace);

if ($overallFailed) {
    $reasons = array();
    if ($km['failed'] > 0) {
        $reasons[] = $km['failed'] . ' known malware ' . ($km['failed'] === 1 ? 'test' : 'tests') . ' failed';
    }
    if ($cp['failed'] > 0) {
        $reasons[] = $cp['failed'] . ' clean plugin ' . ($cp['failed'] === 1 ? 'test' : 'tests') . ' failed';
    }
    if ($ui['failed'] > 0) {
        $reasons[] = $ui['failed'] . ' update infrastructure ' . ($ui['failed'] === 1 ? 'test' : 'tests') . ' failed';
    }
    if ($sb['blocking_failed'] > 0) {
        $reasons[] = $sb['blocking_failed'] . ' synthetic ' . ($sb['blocking_failed'] === 1 ? 'test' : 'tests') . ' failed';
    }
    echo 'RESULT: FAIL — ' . implode(', ', $reasons) . "\n";
    exit(1);
}

echo "RESULT: PASS\n";
exit(0);
