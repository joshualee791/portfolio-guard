<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Remediator
{
    public static function run_scan($trigger = 'manual', $args = array())
    {
        if (get_transient(MSP_PG_Config::scan_lock_key())) {
            return null;
        }

        set_transient(MSP_PG_Config::scan_lock_key(), 1, 15 * MINUTE_IN_SECONDS);

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $state = get_option(MSP_PG_Config::state_option_name(), array());
        $scanTimestamp = gmdate('c');
        $siteMeta = self::site_metadata();
        $safeMode = MSP_PG_Config::safe_mode();
        $allowTier1Remediation = MSP_PG_Config::allow_tier1_remediation();
        $evidenceRetentionMode = MSP_PG_Config::evidence_retention_mode();
        $dryRun = !empty($args['dry_run']) || MSP_PG_Config::default_dry_run();
        $cleanup = self::cleanup_expired_artifacts($dryRun, $evidenceRetentionMode);
        $detections = array();
        $errors = array();
        $scanDir = '';

        foreach (glob(trailingslashit(WP_PLUGIN_DIR) . '*', GLOB_ONLYDIR) ?: array() as $pluginDir) {
            $slug = basename($pluginDir);

            if ($slug === 'portfolio-guard' || strpos($slug, '.msp-') === 0) {
                continue;
            }

            $analysis = MSP_PG_Detector::detect($pluginDir);
            if ($analysis === null) {
                continue;
            }

            if ($scanDir === '') {
                $scanDir = self::scan_directory($siteMeta['site_slug'], $scanTimestamp);
                if (!$dryRun) {
                    MSP_PG_Utils::ensure_directory($scanDir);
                }
            }

            $detections[] = self::remediate_detection($analysis, $siteMeta, $scanDir, $safeMode, $allowTier1Remediation, $dryRun);
        }

        $tierCounts = array(
            'tier1' => 0,
            'tier2' => 0,
            'tier3' => 0,
        );

        foreach ($detections as $detection) {
            if (isset($tierCounts[$detection['tier']])) {
                $tierCounts[$detection['tier']]++;
            }
        }

        $scanReport = array(
            'family' => MSP_PG_Config::family_name(),
            'site_url' => $siteMeta['site_url'],
            'site_slug' => $siteMeta['site_slug'],
            'scan_timestamp' => $scanTimestamp,
            'trigger' => $trigger,
            'safe_mode' => $safeMode,
            'allow_tier1_remediation' => $allowTier1Remediation,
            'evidence_retention_mode' => $evidenceRetentionMode,
            'dry_run' => $dryRun,
            'cleanup' => $cleanup,
            'signature_version' => MSP_PG_Config::signature_version(),
            'heuristic_version' => MSP_PG_Config::heuristic_version(),
            'tier_counts' => $tierCounts,
            'detections' => $detections,
            'confirmed_malware' => array_values(array_filter($detections, function ($detection) { return $detection['tier'] === 'tier1'; })),
            'heuristic_findings' => array_values(array_filter($detections, function ($detection) { return $detection['tier'] === 'tier2'; })),
            'interesting_findings' => array_values(array_filter($detections, function ($detection) { return $detection['tier'] === 'tier3'; })),
            'errors' => $errors,
            'wordpress_version' => $siteMeta['wordpress_version'],
            'php_version' => $siteMeta['php_version'],
            'active_plugins' => $siteMeta['active_plugins'],
            'active_theme' => $siteMeta['active_theme'],
        );

        $state['last_scan_at'] = $scanTimestamp;
        $state['last_scan_result'] = array(
            'trigger' => $trigger,
            'detections' => count($detections),
        );
        update_option(MSP_PG_Config::state_option_name(), $state, false);

        if ($scanDir !== '' && !$dryRun) {
            self::write_scan_report($scanDir, $scanReport);
            self::send_scan_report($scanDir, $scanReport);
        }

        delete_transient(MSP_PG_Config::scan_lock_key());

        return $scanReport;
    }

    private static function remediate_detection($analysis, $siteMeta, $scanDir, $safeMode, $allowTier1Remediation, $dryRun)
    {
        $actions = array();
        $errors = array();
        $warnings = array();
        $activePlugins = self::matching_active_plugins($analysis['plugin_slug']);
        $reportOnly = $analysis['tier'] !== 'tier1';
        $shouldModify = !$reportOnly && !$dryRun && (!$safeMode || $allowTier1Remediation);
        $bundleEligible = in_array($analysis['tier'], array('tier1', 'tier2'), true);
        $canDeleteOriginal = in_array('known_hash', $analysis['exact_match_types'], true) || count($analysis['exact_match_types']) >= 2;
        $evidenceMode = MSP_PG_Config::evidence_retention_mode();
        $artifactDir = $bundleEligible ? MSP_PG_Utils::join_paths($scanDir, 'artifacts', $analysis['plugin_slug']) : '';
        $quarantineDir = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'quarantine', $analysis['plugin_slug']) : '';
        $snapshotDir = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'snapshot', $analysis['plugin_slug']) : '';
        $zipPath = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'artifact.zip') : '';
        $manifestPath = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'evidence.json') : '';
        $reportPath = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'report.json') : '';
        $markdownPath = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'report.md') : '';
        $textPath = $bundleEligible ? MSP_PG_Utils::join_paths($artifactDir, 'report.txt') : '';
        $livePluginDir = $analysis['plugin_dir'];
        $evidenceArchiveCreated = false;
        $fileCount = 0;
        $directoryCount = 0;
        $totalBytes = 0;
        $directoryFingerprint = '';

        if (!$dryRun && $bundleEligible) {
            MSP_PG_Utils::ensure_directory($artifactDir);
        }

        if ($analysis['tier'] === 'tier1') {
            $actions[] = 'CONFIRMED_MALWARE_IDENTIFIED';
        } elseif ($analysis['tier'] === 'tier2') {
            $actions[] = 'HEURISTIC_FINDING_IDENTIFIED';
        } elseif ($analysis['tier'] === 'tier3') {
            $actions[] = 'INTERESTING_FINDING_IDENTIFIED';
        }

        if (!empty($activePlugins)) {
            $warnings[] = 'Deactivation may impact site functionality.';
        }

        if ($reportOnly) {
            $actions[] = 'REPORT_ONLY_NO_CHANGES';
        }

        if ($safeMode && $allowTier1Remediation && !$reportOnly) {
            $actions[] = 'TIER1_OVERRIDE_ENABLED';
        } elseif ($safeMode && !$reportOnly) {
            $actions[] = 'SAFE_MODE_ENABLED';
        }

        if ($dryRun) {
            $actions[] = 'DRY_RUN_ENABLED';
        }

        if ($analysis['protected_plugin'] && $analysis['tier'] !== 'tier1') {
            $actions[] = 'PROTECTED_PLUGIN_REPORT_ONLY';
        }

        if ($shouldModify && !empty($activePlugins)) {
            deactivate_plugins($activePlugins, true);
            $actions[] = 'PLUGIN_DEACTIVATED';
        } elseif (!$shouldModify && !empty($activePlugins) && !$reportOnly) {
            $actions[] = 'WOULD_PLUGIN_DEACTIVATE';
        }

        if ($bundleEligible) {
            $counts = MSP_PG_Utils::directory_counts($livePluginDir);
            $fileCount = (int) $counts['file_count'];
            $directoryCount = (int) $counts['directory_count'];
            $totalBytes = (int) MSP_PG_Utils::directory_size($livePluginDir);
            $directoryFingerprint = MSP_PG_Utils::directory_fingerprint($livePluginDir);
        }

        $hashes = array();
        if ($evidenceMode === 'full_artifact_retention' && $bundleEligible) {
            $hashes = MSP_PG_Utils::hash_directory($livePluginDir);
        }

        if ($bundleEligible && $dryRun && !$reportOnly) {
            $actions[] = 'WOULD_EVIDENCE_MANIFEST_CREATE';
            if ($evidenceMode === 'compressed_archive') {
                $actions[] = 'WOULD_EVIDENCE_ARCHIVE_CREATE';
            } elseif ($evidenceMode === 'full_artifact_retention') {
                $actions[] = 'WOULD_FULL_ARTIFACT_RETAIN';
            }
        }

        $manifest = array();
        $preservationVerified = false;

        if ($bundleEligible) {
            $manifest = array(
                'family' => $analysis['plugin_slug'],
                'classification' => $analysis['tier'] === 'tier1' ? 'Confirmed Malware' : ($analysis['tier'] === 'tier2' ? 'Heuristic Finding' : 'Interesting Finding'),
                'tier' => strtoupper($analysis['tier']),
                'confidence' => $analysis['confidence'],
                'source' => $analysis['detection_source'],
                'action' => $shouldModify ? 'Pending Remediation' : 'Reported',
                'detected_at' => gmdate('c'),
                'plugin_slug' => $analysis['plugin_slug'],
                'file_count' => $fileCount,
                'directory_count' => $directoryCount,
                'total_bytes' => $totalBytes,
                'sha256_directory_fingerprint' => $directoryFingerprint,
                'evidence_retention_mode' => $evidenceMode,
                'variant_fingerprint' => $analysis['variant_hash'],
                'detection_tier' => $analysis['tier'],
                'score' => $analysis['score'],
                'reasons' => $analysis['reasons'],
                'safe_mode' => $safeMode,
                'allow_tier1_remediation' => $allowTier1Remediation,
                'dry_run' => $dryRun,
                'site_url' => $siteMeta['site_url'],
                'detection_timestamp' => gmdate('c'),
                'wordpress_version' => $siteMeta['wordpress_version'],
                'php_version' => $siteMeta['php_version'],
                'active_plugins' => $siteMeta['active_plugins'],
                'active_theme' => $siteMeta['active_theme'],
                'plugin_slug' => $analysis['plugin_slug'],
                'protected_plugin' => $analysis['protected_plugin'],
                'exact_match_types' => $analysis['exact_match_types'],
                'matched_indicators' => $analysis['matched_indicators'],
                'payload_hashes' => $analysis['payload_hashes'],
                'hashes' => $hashes,
                'domains' => $analysis['domains'],
                'routes' => $analysis['routes'],
                'backdoor_indicators' => $analysis['backdoor_indicators'],
                'structural_indicators' => $analysis['structural_indicators'],
                'signature_version' => MSP_PG_Config::signature_version(),
                'heuristic_version' => MSP_PG_Config::heuristic_version(),
            );

            $artifactReport = array(
                'plugin_slug' => $analysis['plugin_slug'],
                'live_path' => $analysis['plugin_dir'],
                'quarantine_path' => ($evidenceMode === 'full_artifact_retention') ? $quarantineDir : '',
                'snapshot_path' => ($evidenceMode === 'full_artifact_retention') ? $snapshotDir : '',
                'artifact_dir' => $artifactDir,
                'evidence_manifest_path' => $manifestPath,
                'zip_path' => (($evidenceMode === 'compressed_archive' || $evidenceMode === 'full_artifact_retention') ? $zipPath : ''),
                'evidence_retention_mode' => $evidenceMode,
                'tier' => $analysis['tier'],
                'score' => $analysis['score'],
                'confidence' => $analysis['confidence'],
                'source' => $analysis['detection_source'],
                'reason_labels' => array_values(array_map(function ($reason) { return $reason['label']; }, $analysis['reasons'])),
                'actions' => $actions,
                'exact_match_types' => $analysis['exact_match_types'],
                'matched_indicators' => $analysis['matched_indicators'],
                'errors' => $errors,
                'warnings' => $warnings,
                'protected_plugin' => $analysis['protected_plugin'],
                'variant_hash' => $analysis['variant_hash'],
                'known_variant' => $analysis['known_variant'],
            );

            if (!$dryRun && $shouldModify && $evidenceMode === 'compressed_archive') {
                $evidenceArchiveCreated = MSP_PG_Utils::zip_live_directory($livePluginDir, $zipPath);
                if ($evidenceArchiveCreated) {
                    $actions[] = 'EVIDENCE_ARCHIVE_CREATED';
                } else {
                    $errors[] = 'Failed to create compressed evidence archive.';
                }
            } elseif (!$dryRun && $shouldModify && $evidenceMode === 'full_artifact_retention') {
                $copied = MSP_PG_Utils::copy_directory($livePluginDir, $snapshotDir);
                if ($copied) {
                    $actions[] = 'FULL_ARTIFACT_SNAPSHOT_CREATED';
                    $zipOkFull = MSP_PG_Utils::zip_directory($artifactDir, $zipPath);
                    $evidenceArchiveCreated = $zipOkFull;
                } else {
                    $errors[] = 'Failed to create evidence snapshot.';
                }
            } elseif ($dryRun && $bundleEligible && !$reportOnly) {
                $evidenceArchiveCreated = false;
            }

            $manifestOk = !$dryRun ? MSP_PG_Utils::write_json($manifestPath, $manifest) : true;
            if ($manifestOk) {
                if (!$dryRun) {
                    $actions[] = 'EVIDENCE_MANIFEST_CREATED';
                }
            }
            $preReportOk = !$dryRun ? MSP_PG_Utils::write_json($reportPath, $artifactReport) : true;
            $preMarkdownOk = !$dryRun ? MSP_PG_Utils::write_text($markdownPath, self::artifact_markdown($artifactReport, $manifest)) : true;
            $preTextOk = !$dryRun ? MSP_PG_Utils::write_text($textPath, self::artifact_markdown($artifactReport, $manifest)) : true;
            $zipOk = $dryRun ? true : (($evidenceMode === 'compressed_archive' || $evidenceMode === 'full_artifact_retention') ? $evidenceArchiveCreated && is_readable($zipPath) : true);
            $preservationVerified = $dryRun
                ? false
                : ($manifestOk && $preReportOk && $preMarkdownOk && $preTextOk
                    && is_readable($manifestPath) && is_readable($reportPath)
                    && (
                        $evidenceMode === 'metadata_only'
                        || (($evidenceMode === 'compressed_archive' || $evidenceMode === 'full_artifact_retention') && $zipOk)
                    ));

            if ($preservationVerified) {
                $actions[] = 'BUNDLE_VERIFIED';
            } elseif (!$dryRun) {
                $errors[] = 'Preservation verification failed; live plugin retained.';
            }

            if ($analysis['tier'] === 'tier1' && $shouldModify && MSP_PG_Config::delete_tier1_enabled() && $preservationVerified && $canDeleteOriginal) {
                if ($evidenceMode === 'full_artifact_retention') {
                    $moved = MSP_PG_Utils::move_directory($livePluginDir, $quarantineDir);
                    if ($moved) {
                        $actions[] = 'QUARANTINE_COMPLETED';
                        $actions[] = 'LIVE_PLUGIN_REMOVED';
                    } else {
                        $errors[] = 'Failed to move plugin directory into quarantine after verified preservation.';
                    }
                } else {
                    $temporaryQuarantineDir = MSP_PG_Utils::join_paths(
                        MSP_PG_Config::temporary_quarantine_base_dir(),
                        $analysis['plugin_slug'] . '-' . preg_replace('/[^0-9A-Za-z_-]/', '-', gmdate('Ymd-His'))
                    );

                    $moved = MSP_PG_Utils::move_directory($livePluginDir, $temporaryQuarantineDir);
                    if ($moved) {
                        $actions[] = 'QUARANTINE_COMPLETED';
                        self::delete_directory($temporaryQuarantineDir);
                        $actions[] = 'LIVE_PLUGIN_REMOVED';
                    } else {
                        $errors[] = 'Failed to move plugin directory into temporary quarantine after verified preservation.';
                    }
                }
            }
        }

        if ($analysis['tier'] === 'tier2') {
            $actions[] = 'HEURISTIC_REPORT_ONLY';
        }

        if ($analysis['tier'] === 'tier3') {
            $actions[] = 'INTERESTING_REPORT_ONLY';
        }

        if ($dryRun && $analysis['tier'] === 'tier1') {
            $actions[] = 'WOULD_LIVE_PLUGIN_REMOVE';
        }

        if ($bundleEligible) {
            if (in_array('LIVE_PLUGIN_REMOVED', $actions, true)) {
                $manifest['action'] = 'Removed';
                $manifest['remediation_status'] = 'Removed';
            } elseif (in_array('QUARANTINE_COMPLETED', $actions, true)) {
                $manifest['action'] = 'Quarantined';
                $manifest['remediation_status'] = 'Quarantined';
            } elseif ($analysis['tier'] === 'tier2') {
                $manifest['action'] = 'Reported';
                $manifest['remediation_status'] = 'Report Only';
            } elseif ($dryRun) {
                $manifest['action'] = 'Simulated';
                $manifest['remediation_status'] = 'Dry Run';
            } elseif ($shouldModify) {
                $manifest['action'] = 'Preserved Evidence';
                $manifest['remediation_status'] = 'Evidence Preserved';
            } else {
                $manifest['action'] = 'Reported';
                $manifest['remediation_status'] = 'Reported';
            }

            $artifactReport['actions'] = $actions;
            $artifactReport['action_descriptions'] = MSP_PG_Utils::describe_actions($actions);
            $artifactReport['errors'] = $errors;
            $artifactReport['warnings'] = $warnings;
            $artifactReport['preservation_verified'] = $preservationVerified;
            $artifactReport['file_count'] = $fileCount;
            $artifactReport['directory_count'] = $directoryCount;
            $artifactReport['total_bytes'] = $totalBytes;
            $artifactReport['sha256_directory_fingerprint'] = $directoryFingerprint;
            if (!$dryRun) {
                MSP_PG_Utils::write_json($manifestPath, $manifest);
                MSP_PG_Utils::write_json($reportPath, $artifactReport);
                MSP_PG_Utils::write_text($markdownPath, self::artifact_markdown($artifactReport, $manifest));
                MSP_PG_Utils::write_text($textPath, self::artifact_markdown($artifactReport, $manifest));
            }
        }

        return array(
            'plugin_slug' => $analysis['plugin_slug'],
            'live_path' => $analysis['plugin_dir'],
            'artifact_dir' => $artifactDir,
            'quarantine_path' => $quarantineDir,
            'snapshot_path' => $snapshotDir,
            'zip_path' => $zipPath,
            'evidence_manifest_path' => $manifestPath,
            'tier' => $analysis['tier'],
            'family' => $analysis['family'],
            'score' => $analysis['score'],
            'confidence' => $analysis['confidence'],
            'source' => $analysis['detection_source'],
            'reason_labels' => array_values(array_map(function ($reason) { return $reason['label']; }, $analysis['reasons'])),
            'exact_match_types' => $analysis['exact_match_types'],
            'variant_hash' => $analysis['variant_hash'],
            'payload_hashes' => $analysis['payload_hashes'],
            'domains' => $analysis['domains'],
            'routes' => $analysis['routes'],
            'backdoor_indicators' => $analysis['backdoor_indicators'],
            'structural_indicators' => $analysis['structural_indicators'],
            'matched_indicators' => $analysis['matched_indicators'],
            'actions' => $actions,
            'action_descriptions' => MSP_PG_Utils::describe_actions($actions),
            'errors' => $errors,
            'warnings' => $warnings,
            'protected_plugin' => $analysis['protected_plugin'],
            'preservation_verified' => $preservationVerified,
            'bundle_eligible' => $bundleEligible,
            'evidence_retention_mode' => $evidenceMode,
        );
    }

    private static function artifact_markdown($artifactReport, $manifest)
    {
        $lines = array(
            '# Artifact Report',
            '',
            '- Plugin slug: `' . $artifactReport['plugin_slug'] . '`',
            '- Tier: `' . $artifactReport['tier'] . '`',
            '- Protected plugin: `' . ($artifactReport['protected_plugin'] ? 'yes' : 'no') . '`',
            '- Evidence mode: `' . $artifactReport['evidence_retention_mode'] . '`',
            '- Score: `' . $artifactReport['score'] . '`',
            '- Confidence: `' . $artifactReport['confidence'] . '`',
            '- Source: `' . $artifactReport['source'] . '`',
            '- Remediation status: `' . $manifest['remediation_status'] . '`',
            '- Variant fingerprint: `' . $artifactReport['variant_hash'] . '`',
            '- Evidence manifest: `' . $artifactReport['evidence_manifest_path'] . '`',
            '- Evidence archive: `' . (!empty($artifactReport['zip_path']) ? $artifactReport['zip_path'] : 'n/a') . '`',
            '- File count: `' . $artifactReport['file_count'] . '`',
            '- Directory count: `' . $artifactReport['directory_count'] . '`',
            '- Total bytes: `' . $artifactReport['total_bytes'] . '`',
            '- Directory fingerprint: `' . $artifactReport['sha256_directory_fingerprint'] . '`',
            '- Actions: `' . implode(', ', $artifactReport['actions']) . '`',
            '- Exact matches: `' . implode(', ', $artifactReport['exact_match_types']) . '`',
            '- Matched indicators: `' . implode(', ', $artifactReport['matched_indicators']) . '`',
            '- Reasons: `' . implode(', ', $artifactReport['reason_labels']) . '`',
            '- Domains: `' . implode(', ', $manifest['domains']) . '`',
            '- Routes: `' . implode(', ', $manifest['routes']) . '`',
        );

        return implode("\n", $lines) . "\n";
    }

    private static function write_scan_report($scanDir, $scanReport)
    {
        MSP_PG_Utils::write_json(MSP_PG_Utils::join_paths($scanDir, 'report.json'), $scanReport);
        MSP_PG_Utils::write_text(MSP_PG_Utils::join_paths($scanDir, 'report.md'), MSP_PG_Utils::markdown_report($scanReport));
        MSP_PG_Utils::write_text(MSP_PG_Utils::join_paths($scanDir, 'report.txt'), MSP_PG_Utils::plain_text_report($scanReport));
    }

    private static function send_scan_report($scanDir, $scanReport)
    {
        $subject = sprintf(
            '[MSP Portfolio Guard] %s detections on %s',
            count($scanReport['detections']),
            $scanReport['site_url']
        );

        $body = array(
            'MSP Portfolio Guard completed a remediation scan.',
            '',
            'Site: ' . $scanReport['site_url'],
            'Timestamp: ' . $scanReport['scan_timestamp'],
            'Trigger: ' . $scanReport['trigger'],
            'Safe mode: ' . ($scanReport['safe_mode'] ? 'enabled' : 'disabled'),
            'Tier 1 override: ' . ($scanReport['allow_tier1_remediation'] ? 'enabled' : 'disabled'),
            'Evidence retention mode: ' . $scanReport['evidence_retention_mode'],
            'Dry run: ' . ($scanReport['dry_run'] ? 'enabled' : 'disabled'),
            'Detections: ' . count($scanReport['detections']),
            'Cleanup: ' . MSP_PG_Utils::cleanup_summary($scanReport['cleanup']),
            'Report path: ' . MSP_PG_Utils::join_paths($scanDir, 'report.json'),
            'Artifact root: ' . MSP_PG_Utils::join_paths($scanDir, 'artifacts'),
            '',
        );

        foreach (array(
            'Confirmed Malware' => $scanReport['confirmed_malware'],
            'Heuristic Findings' => $scanReport['heuristic_findings'],
            'Interesting Findings' => $scanReport['interesting_findings'],
        ) as $label => $detectionsGroup) {
            if (empty($detectionsGroup)) {
                continue;
            }

            $body[] = $label . ':';
            foreach ($detectionsGroup as $detection) {
                $body[] = sprintf(
                    '- %s [%s] actions=%s exact=%s artifact=%s',
                    $detection['plugin_slug'],
                    strtoupper($detection['tier']),
                    implode('; ', $detection['action_descriptions']),
                    implode(',', $detection['exact_match_types']),
                    $detection['artifact_dir']
                );
            }
            $body[] = '';
        }

        if (!empty($scanReport['errors'])) {
            $body[] = '';
            $body[] = 'Errors:';
            foreach ($scanReport['errors'] as $error) {
                $body[] = '- ' . $error;
            }
        }

        $htmlBody = MSP_PG_Utils::html_report($scanReport);
        add_filter('wp_mail_content_type', array(__CLASS__, 'html_mail_content_type'));
        wp_mail(MSP_PG_Config::report_recipient(), $subject, $htmlBody);
        remove_filter('wp_mail_content_type', array(__CLASS__, 'html_mail_content_type'));
    }

    public static function html_mail_content_type()
    {
        return 'text/html';
    }

    private static function cleanup_expired_artifacts($dryRun, $evidenceMode)
    {
        $baseDir = MSP_PG_Config::artifact_base_dir();
        $retentionDays = MSP_PG_Config::artifact_retention_days();
        $result = array(
            'retention_days' => $retentionDays,
            'deleted_count' => 0,
            'deleted_paths' => array(),
            'scrubbed_count' => 0,
            'scrubbed_paths' => array(),
            'mode' => $dryRun ? 'simulated' : 'live',
        );

        if (!is_dir($baseDir)) {
            return $result;
        }

        $threshold = time() - ($retentionDays * DAY_IN_SECONDS);
        $sites = glob(trailingslashit($baseDir) . '*', GLOB_ONLYDIR) ?: array();

        foreach ($sites as $siteDir) {
            $scanDirs = glob(trailingslashit($siteDir) . '*', GLOB_ONLYDIR) ?: array();
            foreach ($scanDirs as $scanDir) {
                if ($evidenceMode !== 'full_artifact_retention') {
                    foreach (array('snapshot', 'quarantine') as $legacyDirName) {
                        foreach (glob(trailingslashit($scanDir) . 'artifacts/*/' . $legacyDirName, GLOB_ONLYDIR) ?: array() as $legacyPath) {
                            $result['scrubbed_count']++;
                            $result['scrubbed_paths'][] = $legacyPath;
                            if (!$dryRun) {
                                self::delete_directory($legacyPath);
                            }
                        }
                    }
                }

                if (filemtime($scanDir) >= $threshold) {
                    continue;
                }

                $result['deleted_count']++;
                $result['deleted_paths'][] = $scanDir;

                if (!$dryRun) {
                    self::delete_directory($scanDir);
                }
            }
        }

        return $result;
    }

    private static function delete_directory($dir)
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

    private static function matching_active_plugins($slug)
    {
        $active = (array) get_option('active_plugins', array());

        return array_values(array_filter($active, function ($plugin) use ($slug) {
            return strpos($plugin, $slug . '/') === 0;
        }));
    }

    private static function scan_directory($siteSlug, $scanTimestamp)
    {
        $normalizedTimestamp = preg_replace('/[^0-9TZ:-]/', '-', $scanTimestamp);

        return MSP_PG_Utils::join_paths(
            MSP_PG_Config::artifact_base_dir(),
            $siteSlug,
            $normalizedTimestamp
        );
    }

    private static function site_metadata()
    {
        $theme = wp_get_theme();

        return array(
            'site_url' => home_url('/'),
            'site_slug' => MSP_PG_Config::site_slug(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'active_plugins' => (array) get_option('active_plugins', array()),
            'active_theme' => $theme->get('Name'),
        );
    }
}
