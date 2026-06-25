<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_UpdateVerifier
{
    /**
     * Verify the manifest HMAC-SHA256 signature.
     *
     * The canonical body is the manifest with manifest_hmac omitted and keys
     * sorted lexicographically, encoded as compact JSON. The HMAC is computed
     * using the update key from MSP_PG_Config.
     */
    public static function verify_manifest(array $manifest)
    {
        if (!isset($manifest['manifest_hmac'])) {
            return false;
        }

        $provided = $manifest['manifest_hmac'];
        $body     = $manifest;
        unset($body['manifest_hmac']);
        ksort($body);

        $canonical = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return false;
        }

        $expected = hash_hmac('sha256', $canonical, hex2bin(MSP_PG_Config::update_key()));

        return hash_equals($expected, $provided);
    }

    /**
     * Verify the SHA-256 digest of a registry JSON string.
     */
    public static function verify_registry($json, $expectedSha256)
    {
        $actual = hash('sha256', $json);
        return hash_equals((string) $expectedSha256, $actual);
    }

    /**
     * Validate the decoded structure of a candidate registry.
     */
    public static function validate_schema(array $decoded)
    {
        if (!isset($decoded['schema_version']) || $decoded['schema_version'] !== 1) {
            return false;
        }

        if (!isset($decoded['registry_version'])
            || !is_int($decoded['registry_version'])
            || $decoded['registry_version'] < 0
        ) {
            return false;
        }

        if (empty($decoded['variants']) || !is_array($decoded['variants'])) {
            return false;
        }

        if (!isset($decoded['exact_ioc_strings']) || !is_array($decoded['exact_ioc_strings'])) {
            return false;
        }

        return true;
    }
}
