<?php
/**
 * Portfolio Guard Release Package Test
 *
 * Validates the production ZIP artifact against Spec 009 §9.
 *
 * Usage:
 *   php validation/release-package-test.php --zip <path> --version <version>
 *
 * Exit codes:
 *   0 — all checks passed
 *   1 — one or more checks failed
 *   2 — ZIP not found or could not be opened
 */

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "[ERROR] PHP 'zip' extension is required but not loaded.\n");
    fwrite(STDERR, "  Fix: uncomment 'extension=zip' in your php.ini\n");
    fwrite(STDERR, "  Find your php.ini: php --ini\n");
    exit(2);
}

$opts = getopt('', array('zip:', 'version:'));

$zipPath = isset($opts['zip'])     ? $opts['zip']     : '';
$version = isset($opts['version']) ? $opts['version'] : '';

if (empty($zipPath) || empty($version)) {
    fwrite(STDERR, "Usage: php release-package-test.php --zip <path> --version <version>\n");
    exit(2);
}

if (!file_exists($zipPath)) {
    fwrite(STDERR, "[ERROR] ZIP not found: $zipPath\n");
    exit(2);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "[ERROR] Could not open ZIP: $zipPath\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$results = array();

function rpt_pass($label) {
    global $passed, $results;
    $results[] = '[PASS] ReleasePackageTest: ' . $label;
    $passed++;
}

function rpt_fail($label, $reason = '') {
    global $failed, $results;
    $msg = '[FAIL] ReleasePackageTest: ' . $label;
    if ($reason !== '') {
        $msg .= ' — ' . $reason;
    }
    $results[] = $msg;
    $failed++;
}

// ── Build an index of all entries in the ZIP ─────────────────────────────────

$entries = array();
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name !== false) {
        $entries[] = $name;
    }
}

$entriesLower = array_map('strtolower', $entries);

// ── Required presence checks ──────────────────────────────────────────────────

$requiredFiles = array(
    'portfolio-guard/portfolio-guard.php',
    'portfolio-guard/readme.txt',
    'portfolio-guard/data/signatures.json',
    'portfolio-guard/includes/class-msp-pg-plugin.php',
    'portfolio-guard/includes/class-msp-pg-plugin-updater.php',
);

foreach ($requiredFiles as $required) {
    if (in_array(strtolower($required), $entriesLower, true)) {
        rpt_pass("$required present");
    } else {
        rpt_fail("$required present", 'not found in ZIP');
    }
}

// ── Required absence checks ───────────────────────────────────────────────────

$excludedPrefixes = array(
    'portfolio-guard/tests/',
    'portfolio-guard/scripts/',
    'portfolio-guard/readme.md',
);

foreach ($excludedPrefixes as $prefix) {
    $prefixLower = strtolower($prefix);
    $found = array();
    foreach ($entriesLower as $idx => $entryLower) {
        if ($entryLower === $prefixLower
            || strncmp($entryLower, $prefixLower, strlen($prefixLower)) === 0
        ) {
            $found[] = $entries[$idx];
        }
    }
    if (empty($found)) {
        rpt_pass("$prefix absent");
    } else {
        rpt_fail("$prefix absent", 'found in ZIP: ' . implode(', ', array_slice($found, 0, 3)));
    }
}

// ── Version consistency: plugin header ───────────────────────────────────────

$pluginPhpIdx = array_search('portfolio-guard/portfolio-guard.php', $entriesLower);
if ($pluginPhpIdx !== false) {
    $pluginContent = $zip->getFromIndex($pluginPhpIdx);
    $headerVersion = '';
    if (preg_match('/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)/m', $pluginContent, $m)) {
        $headerVersion = $m[1];
    }
    if ($headerVersion === $version) {
        rpt_pass("plugin header Version matches $version");
    } else {
        rpt_fail(
            "plugin header Version matches $version",
            'header says "' . $headerVersion . '", expected "' . $version . '"'
        );
    }
} else {
    rpt_fail("plugin header Version matches $version", 'portfolio-guard.php not found');
}

// ── Version consistency: readme.txt Stable tag ────────────────────────────────

$readmeIdx = array_search('portfolio-guard/readme.txt', $entriesLower);
if ($readmeIdx !== false) {
    $readmeContent = $zip->getFromIndex($readmeIdx);
    $stableTag = '';
    if (preg_match('/^Stable tag:\s*(\S+)/m', $readmeContent, $m)) {
        $stableTag = $m[1];
    }
    if ($stableTag === $version) {
        rpt_pass("readme.txt Stable tag matches $version");
    } else {
        rpt_fail(
            "readme.txt Stable tag matches $version",
            'stable tag is "' . $stableTag . '", expected "' . $version . '"'
        );
    }
} else {
    rpt_fail("readme.txt Stable tag matches $version", 'readme.txt not found');
}

// ── Artifact quality: no .tmp files ──────────────────────────────────────────

$tmpFiles = array_filter($entries, function ($e) {
    return substr(strtolower($e), -4) === '.tmp';
});

if (empty($tmpFiles)) {
    rpt_pass('no .tmp files in ZIP');
} else {
    rpt_fail('no .tmp files in ZIP', implode(', ', array_values($tmpFiles)));
}

// ── Artifact quality: single top-level directory ──────────────────────────────

$topLevelDirs = array();
foreach ($entries as $entry) {
    $parts = explode('/', trim($entry, '/'));
    if (!empty($parts[0])) {
        $topLevelDirs[$parts[0]] = true;
    }
}

if (count($topLevelDirs) === 1 && isset($topLevelDirs['portfolio-guard'])) {
    rpt_pass('ZIP root contains exactly one top-level directory (portfolio-guard/)');
} else {
    rpt_fail(
        'ZIP root contains exactly one top-level directory (portfolio-guard/)',
        'found: ' . implode(', ', array_keys($topLevelDirs))
    );
}

$zip->close();

// ── Output ────────────────────────────────────────────────────────────────────

foreach ($results as $line) {
    echo $line . "\n";
}

$total = $passed + $failed;
echo "\n";
echo "--- Release Package Validation ---\n";
echo "Checks: $passed / $total passed\n";

if ($failed === 0) {
    echo "RESULT: PASS\n";
    exit(0);
} else {
    echo "RESULT: FAIL\n";
    exit(1);
}
