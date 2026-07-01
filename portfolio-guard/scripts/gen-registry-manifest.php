<?php
/**
 * Generates a signed registry-manifest.json for the Portfolio Guard signature update pipeline.
 *
 * Usage: php gen-registry-manifest.php <signatures-path> <signatures-download-url>
 *
 * Reads the signing key from the MSP_PG_UPDATE_KEY environment variable.
 * The output is written to stdout and should be captured into registry-manifest.json.
 *
 * The canonical body, HMAC algorithm, and JSON encoding flags must match
 * MSP_PG_UpdateVerifier::verify_manifest() exactly.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php gen-registry-manifest.php <signatures-path> <signatures-download-url>\n");
    exit(1);
}

$signaturesPath = $argv[1];
$signaturesUrl  = $argv[2];
$keyHex = trim((string) getenv('MSP_PG_UPDATE_KEY'));

if ($keyHex === '') {
    fwrite(STDERR, "FAIL: MSP_PG_UPDATE_KEY is not set or is empty.\n");
    exit(1);
}

if (strlen($keyHex) !== 64) {
    fwrite(STDERR, "FAIL: MSP_PG_UPDATE_KEY must be exactly 64 hex characters (got " . strlen($keyHex) . ").\n");
    exit(1);
}

if (!ctype_xdigit($keyHex)) {
    fwrite(STDERR, "FAIL: MSP_PG_UPDATE_KEY contains non-hexadecimal characters.\n");
    exit(1);
}

$keyBin = hex2bin($keyHex);
if ($keyBin === false) {
    fwrite(STDERR, "FAIL: hex2bin() failed on MSP_PG_UPDATE_KEY — key is malformed.\n");
    exit(1);
}

$signaturesJson = file_get_contents($signaturesPath);
if ($signaturesJson === false) {
    fwrite(STDERR, "Cannot read signatures file: $signaturesPath\n");
    exit(1);
}

$signaturesData = json_decode($signaturesJson, true);
if (!is_array($signaturesData) || !isset($signaturesData['registry_version'])) {
    fwrite(STDERR, "Invalid signatures.json: missing or non-array root, or missing registry_version\n");
    exit(1);
}

$sha256          = hash('sha256', $signaturesJson);
$registryVersion = (int) $signaturesData['registry_version'];

// Build canonical body (keys sorted lexicographically, manifest_hmac excluded).
// Must match MSP_PG_UpdateVerifier::verify_manifest() exactly.
$body = array(
    'registry_sha256'  => $sha256,
    'registry_url'     => $signaturesUrl,
    'registry_version' => $registryVersion,
    'schema_version'   => 1,
);
ksort($body);
$canonical = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($canonical === false) {
    fwrite(STDERR, "Failed to JSON-encode canonical body\n");
    exit(1);
}

$hmac = hash_hmac('sha256', $canonical, $keyBin);

$body['manifest_hmac'] = $hmac;
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
