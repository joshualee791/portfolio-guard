<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Best-effort outbound telemetry publisher for Portfolio Guard Cloud.
 *
 * Cloud is an observability platform only; it never influences endpoint
 * behavior. Publishing here must never affect scan or remediation results.
 * Every failure mode (missing config, network error, bad response) is
 * swallowed silently.
 */
class MSP_PG_CloudPublisher
{
    const TIMEOUT_SECONDS = 3;

    /**
     * Publish a telemetry snapshot to Portfolio Guard Cloud, if configured.
     * Never throws; return value is informational only and unused by callers.
     */
    public static function publish(array $telemetry)
    {
        $cloudUrl = trim(MSP_PG_Config::cloud_url());
        if ($cloudUrl === '') {
            return false;
        }

        try {
            $payload = self::build_payload($telemetry);

            $response = wp_remote_post(trailingslashit($cloudUrl) . 'api/telemetry', array(
                'sslverify'  => true,
                'timeout'    => self::TIMEOUT_SECONDS,
                'user-agent' => 'MSP-PortfolioGuard/' . MSP_PG_VERSION,
                'headers'    => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . MSP_PG_Config::cloud_api_key(),
                ),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                error_log('MSP Portfolio Guard: cloud telemetry publish failed — ' . $response->get_error_message());
                return false;
            }

            $statusCode = wp_remote_retrieve_response_code($response);
            if ($statusCode < 200 || $statusCode >= 300) {
                error_log('MSP Portfolio Guard: cloud telemetry publish rejected — HTTP ' . $statusCode);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            error_log('MSP Portfolio Guard: cloud telemetry publish threw — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Own the Cloud wire protocol. For now this is a one-to-one mapping of the
     * local telemetry record; Cloud's ingestion schema may diverge from the
     * internal diagnostics structure over time, and that translation belongs
     * here rather than at the call site.
     */
    private static function build_payload(array $telemetry)
    {
        return $telemetry;
    }
}
