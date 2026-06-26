<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Updater
{
    const MAX_FAILURES_BEFORE_NOTIFY = 3;

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Full update lifecycle: fetch manifest, verify, fetch registry, verify,
     * validate, install. Designed to be called by the scheduled hook.
     * All HTTP fetches occur here; install_verified_registry() has no network dependency.
     */
    public static function run()
    {
        $manifestUrl = MSP_PG_Config::update_manifest_url();

        $manifestResponse = wp_remote_get($manifestUrl, array(
            'sslverify'  => true,
            'timeout'    => 15,
            'user-agent' => 'MSP-PortfolioGuard/' . MSP_PG_VERSION,
        ));

        update_option('msp_pg_last_update_checked', gmdate('c'), false);

        if (is_wp_error($manifestResponse)
            || wp_remote_retrieve_response_code($manifestResponse) !== 200
        ) {
            self::record_transient_failure();
            return;
        }

        $manifest = @json_decode(wp_remote_retrieve_body($manifestResponse), true);
        if (!is_array($manifest)) {
            self::record_transient_failure();
            return;
        }

        // Verify manifest authenticity before trusting any field values
        if (!MSP_PG_UpdateVerifier::verify_manifest($manifest)) {
            self::notify_integrity_failure('manifest_hmac_mismatch');
            return;
        }

        // Schema version guard — mismatch means the server published a format
        // this plugin version cannot process; skip silently
        if (!isset($manifest['schema_version']) || $manifest['schema_version'] !== 1) {
            error_log('MSP Portfolio Guard: manifest schema_version is not 1 — skipping update (plugin update may be required).');
            return;
        }

        // Version guard — reject if not newer than the highest version applied on this site
        $candidateVersion = isset($manifest['registry_version']) ? (int) $manifest['registry_version'] : 0;
        $maxSeen          = (int) get_option('msp_pg_max_registry_version', 0);
        if ($candidateVersion <= $maxSeen) {
            error_log('MSP Portfolio Guard: registry is current at version ' . $maxSeen . ' — no update required.');
            return;
        }

        // Validate registry_url is an absolute HTTPS URL under the expected host
        $registryUrl = isset($manifest['registry_url']) ? (string) $manifest['registry_url'] : '';
        if (strpos($registryUrl, 'https://') !== 0) {
            error_log('MSP Portfolio Guard: manifest registry_url is not a valid HTTPS URL — aborting.');
            return;
        }

        // Fetch registry content
        $registryResponse = wp_remote_get($registryUrl, array(
            'sslverify'  => true,
            'timeout'    => 30,
            'user-agent' => 'MSP-PortfolioGuard/' . MSP_PG_VERSION,
        ));

        if (is_wp_error($registryResponse)
            || wp_remote_retrieve_response_code($registryResponse) !== 200
        ) {
            self::record_transient_failure();
            return;
        }

        $registryJson = wp_remote_retrieve_body($registryResponse);

        // Integrity: SHA-256 of fetched bytes must match manifest assertion
        $expectedSha256 = isset($manifest['registry_sha256']) ? (string) $manifest['registry_sha256'] : '';
        if (!MSP_PG_UpdateVerifier::verify_registry($registryJson, $expectedSha256)) {
            self::notify_integrity_failure('registry_sha256_mismatch');
            return;
        }

        // Schema validation of decoded registry
        $decoded = @json_decode($registryJson, true);
        if (!is_array($decoded) || !MSP_PG_UpdateVerifier::validate_schema($decoded)) {
            error_log('MSP Portfolio Guard: candidate registry failed schema validation — aborting update.');
            return;
        }

        if (!self::install_verified_registry($registryJson, $candidateVersion)) {
            error_log('MSP Portfolio Guard: registry installation failed.');
        }
    }

    /**
     * Non-HTTP installation step: write a pre-verified registry JSON string to
     * the applied path, update tracking options, and reset the Signatures cache.
     *
     * Exposed as public to allow the UpdateInfrastructureTest to exercise the
     * write-and-cache-reset path without requiring a live HTTP endpoint.
     *
     * Returns true on success, false on any write failure.
     */
    public static function install_verified_registry($json, $version)
    {
        $appliedPath = MSP_PG_Config::applied_registry_path();
        if ($appliedPath === '') {
            error_log('MSP Portfolio Guard: cannot determine applied registry path.');
            return false;
        }

        $appliedDir = dirname($appliedPath);
        $tmpPath    = $appliedPath . '.tmp';

        if (!wp_mkdir_p($appliedDir)) {
            error_log('MSP Portfolio Guard: could not create applied registry directory: ' . $appliedDir);
            return false;
        }

        if (@file_put_contents($tmpPath, $json) === false) {
            @unlink($tmpPath);
            error_log('MSP Portfolio Guard: could not write temporary registry file: ' . $tmpPath);
            return false;
        }

        // Verify written bytes match the source before committing
        $written = @file_get_contents($tmpPath);
        if ($written === false || hash('sha256', $written) !== hash('sha256', $json)) {
            @unlink($tmpPath);
            error_log('MSP Portfolio Guard: written registry bytes do not match source — aborting install.');
            return false;
        }

        if (!rename($tmpPath, $appliedPath)) {
            @unlink($tmpPath);
            error_log('MSP Portfolio Guard: could not rename temporary registry to applied path: ' . $appliedPath);
            return false;
        }

        // Update tracking options
        $version = (int) $version;
        $maxSeen = (int) get_option('msp_pg_max_registry_version', 0);
        if ($version > $maxSeen) {
            update_option('msp_pg_max_registry_version', $version, false);
        }

        $prevFailures = (int) get_option('msp_pg_update_consecutive_failures', 0);
        update_option('msp_pg_update_consecutive_failures', 0, false);
        update_option('msp_pg_last_update_applied', array(
            'version'   => $version,
            'timestamp' => gmdate('c'),
            'source'    => 'applied',
        ), false);

        // Reset static cache so the next Signatures read uses the new file
        MSP_PG_Signatures::reset();

        // Admin notice (transient; dismissed on next page load)
        set_transient(
            'msp_pg_update_notice',
            'Portfolio Guard: Signature registry updated to version ' . $version . ' (' . gmdate('c') . ').',
            DAY_IN_SECONDS
        );

        $logSuffix = $prevFailures > 0
            ? ' (after ' . $prevFailures . ' consecutive check failures)'
            : '';
        error_log('MSP Portfolio Guard: registry successfully updated to version ' . $version . $logSuffix . '.');

        return true;
    }

    public static function last_checked()
    {
        $val = get_option('msp_pg_last_update_checked', null);
        return $val ? (string) $val : null;
    }

    public static function last_applied()
    {
        return (array) get_option('msp_pg_last_update_applied', array());
    }

    public static function consecutive_failures()
    {
        return (int) get_option('msp_pg_update_consecutive_failures', 0);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function record_transient_failure()
    {
        $failures = (int) get_option('msp_pg_update_consecutive_failures', 0) + 1;
        update_option('msp_pg_update_consecutive_failures', $failures, false);
        error_log('MSP Portfolio Guard: update check failed (consecutive failures: ' . $failures . ').');

        if ($failures >= self::MAX_FAILURES_BEFORE_NOTIFY) {
            $lastApplied    = self::last_applied();
            $lastAppliedStr = !empty($lastApplied['version'])
                ? 'registry_version=' . $lastApplied['version'] . ' at ' . $lastApplied['timestamp']
                : 'none — using installed registry';

            $body = 'The signature registry update check has failed ' . $failures . ' consecutive times on '
                . get_site_url() . '.'
                . "\nLast attempt: "         . gmdate('c') . '.'
                . "\nLast successful update: " . $lastAppliedStr . '.'
                . "\nCheck connectivity to "  . MSP_PG_Config::update_manifest_url()
                . ' and review PHP error logs.';

            wp_mail(
                MSP_PG_Config::report_recipient(),
                '[MSP Portfolio Guard] Signature registry update failure on ' . get_site_url(),
                $body
            );
        }
    }

    private static function notify_integrity_failure($type)
    {
        $body = 'A signature registry update was fetched but failed integrity verification on '
            . get_site_url() . '.'
            . "\nFailure type: " . $type . '.'
            . "\nTimestamp: "    . gmdate('c') . '.'
            . "\nThe existing registry remains in use. Investigate immediately.";

        error_log('MSP Portfolio Guard: ' . $body);
        wp_mail(
            MSP_PG_Config::report_recipient(),
            '[MSP Portfolio Guard] Registry integrity failure on ' . get_site_url(),
            $body
        );
    }
}
