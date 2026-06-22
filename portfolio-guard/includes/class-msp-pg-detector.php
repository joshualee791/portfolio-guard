<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Detector
{
    public static function detect($pluginDir)
    {
        $slug = basename($pluginDir);
        $variant = MSP_PG_Signatures::variant_by_slug($slug);
        $knownHashes = MSP_PG_Signatures::known_hashes();
        $knownPrimaryPluginFiles = MSP_PG_Signatures::known_primary_plugin_files();
        $heuristics = MSP_PG_Signatures::heuristic_markers();
        $weights = MSP_PG_Config::score_weights();
        $thresholds = MSP_PG_Config::score_thresholds();
        $structure = MSP_PG_Utils::random_payload_structure($pluginDir);

        $analysis = array(
            'family' => MSP_PG_Config::family_name(),
            'plugin_slug' => $slug,
            'plugin_dir' => $pluginDir,
            'main_files' => MSP_PG_Utils::detect_plugin_files($pluginDir),
            'known_variant' => $variant ? $variant['slug'] : '',
            'score' => 0,
            'reasons' => array(),
            'hash_hits' => array(),
            'exact_match_types' => array(),
            'matched_indicators' => array(),
            'matched_strings' => array(),
            'routes' => array(),
            'domains' => array(),
            'backdoor_indicators' => array(),
            'structural_indicators' => array(),
            'payload_hashes' => array(),
            'files_scanned' => array(),
            'tier' => '',
            'variant_hash' => '',
            'detection_source' => '',
            'protected_plugin' => in_array($slug, MSP_PG_Config::protected_plugin_slugs(), true),
        );

        foreach ($structure as $bucket => $values) {
            foreach ($values as $value) {
                $analysis['structural_indicators'][] = $bucket . ':' . $value;
            }
        }

        if ($variant) {
            $analysis['exact_match_types'][] = 'known_plugin_directory';
            $analysis['matched_indicators'][] = 'slug:' . $slug;
            self::add_reason($analysis, 'known_plugin_directory', 'known plugin directory', $weights['known_plugin_directory']);
        }

        if (!empty($structure['directories']) && !empty($structure['php_files'])) {
            self::add_reason($analysis, 'known_family_payload_structure', 'known family payload structure', $weights['known_family_payload_structure']);
        }

        foreach (MSP_PG_Utils::recursive_files($pluginDir) as $file) {
            $relative = MSP_PG_Utils::relative_path($file, $pluginDir);
            $analysis['files_scanned'][] = $relative;
            $hash = MSP_PG_Utils::hash_file_safe($file);

            if (in_array(str_replace('\\', '/', $relative), MSP_PG_Signatures::known_relative_filenames(), true)) {
                $analysis['matched_indicators'][] = 'filename:' . str_replace('\\', '/', $relative);
                $analysis['exact_match_types'][] = 'known_filename';
                self::add_reason($analysis, 'known_filename:' . str_replace('\\', '/', $relative), 'known malware filename', $weights['known_filename']);
            }

            if (strpos(str_replace('\\', '/', $relative), '/') === false && isset($knownPrimaryPluginFiles[basename($relative)])) {
                $analysis['matched_indicators'][] = 'primary_plugin_file:' . basename($relative);
                $analysis['exact_match_types'][] = 'known_primary_plugin_file';
                self::add_reason($analysis, 'known_primary_plugin_file:' . basename($relative), 'known primary plugin file', $weights['known_primary_plugin_file']);
                if ($analysis['known_variant'] === '') {
                    $analysis['known_variant'] = $knownPrimaryPluginFiles[basename($relative)];
                }
            }

            if ($hash !== '' && isset($knownHashes[$hash])) {
                $analysis['hash_hits'][] = $hash;
                $analysis['matched_indicators'][] = 'hash:' . $hash;
                $analysis['payload_hashes'][] = $hash;
                $analysis['exact_match_types'][] = 'known_hash';
                self::add_reason($analysis, 'known_hash:' . $hash, 'known malware hash', $weights['known_hash']);
                if ($analysis['known_variant'] === '') {
                    $analysis['known_variant'] = $knownHashes[$hash];
                }
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($extension, MSP_PG_Config::scan_extensions(), true)) {
                continue;
            }

            if ((int) filesize($file) > MSP_PG_Config::max_scan_file_bytes()) {
                continue;
            }

            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach (MSP_PG_Signatures::known_domains() as $domain) {
                if (strpos($contents, $domain) !== false) {
                    $analysis['domains'][] = $domain;
                    $analysis['matched_indicators'][] = 'domain:' . $domain;
                    $analysis['exact_match_types'][] = 'known_domain';
                    self::add_reason($analysis, 'known_domain:' . $domain, 'known IOC domain', $weights['known_domain']);
                }
            }

            foreach (MSP_PG_Signatures::route_namespaces() as $route) {
                if (strpos($contents, $route) !== false) {
                    $analysis['routes'][] = $route;
                    $analysis['matched_indicators'][] = 'route:' . $route;
                    $analysis['exact_match_types'][] = 'known_route';
                    self::add_reason($analysis, 'known_route:' . $route, 'known malware route', $weights['known_route']);
                }
            }

            foreach (MSP_PG_Signatures::backdoor_pairs() as $backdoor) {
                $idHit = strpos($contents, $backdoor['id_param']) !== false;
                $tokenParamHit = strpos($contents, $backdoor['token_param']) !== false;
                $tokenValueHit = strpos($contents, $backdoor['token_value']) !== false;

                if ($idHit && $tokenParamHit && $tokenValueHit) {
                    $analysis['backdoor_indicators'][] = $backdoor['id_param'] . '+' . $backdoor['token_param'];
                    $analysis['matched_indicators'][] = 'backdoor:' . $backdoor['slug'];
                    self::add_reason($analysis, 'known_auth_cookie_impersonation_pattern:' . $backdoor['slug'], 'known auth-cookie impersonation pattern', $weights['known_auth_cookie_impersonation_pattern']);
                    if ($analysis['known_variant'] === '') {
                        $analysis['known_variant'] = $backdoor['slug'];
                    }
                }
            }

            if (
                strpos($contents, 'fastreactic_nanomicroserviceing') !== false ||
                strpos($contents, 'tridatation_quicktypescriptal') !== false ||
                strpos($contents, 'data-ph-pid') !== false
            ) {
                self::add_reason($analysis, 'known_family_bootstrap_pattern', 'known family bootstrap pattern', $weights['known_family_bootstrap_pattern']);
            }

            if (
                strpos($contents, "createElement('script')") !== false ||
                strpos($contents, 'createElement("script")') !== false
            ) {
                self::add_reason($analysis, 'suspicious_remote_javascript', 'suspicious remote JavaScript', $weights['suspicious_remote_javascript']);
            }

            if (strpos($contents, 'register_rest_route(') !== false) {
                self::add_reason($analysis, 'custom_rest_namespace', 'custom REST namespace', $weights['custom_rest_namespace']);
            }

            if (strpos($contents, 'wp_ajax_') !== false || strpos($contents, 'wp_ajax_nopriv_') !== false) {
                self::add_reason($analysis, 'ajax_handlers', 'AJAX handlers', $weights['ajax_handlers']);
            }

            if (
                strpos($contents, 'wp_remote_request(') !== false ||
                strpos($contents, 'wp_remote_get(') !== false ||
                strpos($contents, 'wp_remote_post(') !== false
            ) {
                self::add_reason($analysis, 'remote_requests', 'remote requests', $weights['remote_requests']);
            }

            if (
                strpos($contents, 'setcookie(') !== false ||
                strpos($contents, '$_COOKIE') !== false ||
                strpos($contents, 'wp_set_auth_cookie(') !== false
            ) {
                self::add_reason($analysis, 'cookie_manipulation', 'cookie manipulation', $weights['cookie_manipulation']);
            }

            if (
                strpos($contents, 'wp_register_script(') !== false ||
                strpos($contents, 'wp_enqueue_script(') !== false ||
                strpos($contents, 'wp_add_inline_script(') !== false
            ) {
                self::add_reason($analysis, 'dynamic_script_registration', 'dynamic script registration', $weights['dynamic_script_registration']);
            }
        }

        $analysis['hash_hits'] = MSP_PG_Utils::normalize_list($analysis['hash_hits']);
        $analysis['routes'] = MSP_PG_Utils::normalize_list($analysis['routes']);
        $analysis['domains'] = MSP_PG_Utils::normalize_list($analysis['domains']);
        $analysis['backdoor_indicators'] = MSP_PG_Utils::normalize_list($analysis['backdoor_indicators']);
        $analysis['exact_match_types'] = MSP_PG_Utils::normalize_list($analysis['exact_match_types']);
        $analysis['matched_indicators'] = MSP_PG_Utils::normalize_list($analysis['matched_indicators']);
        $analysis['structural_indicators'] = MSP_PG_Utils::normalize_list($analysis['structural_indicators']);
        $analysis['payload_hashes'] = MSP_PG_Utils::normalize_list($analysis['payload_hashes']);
        $analysis['reasons'] = array_values($analysis['reasons']);
        usort($analysis['reasons'], function ($left, $right) {
            return $right['weight'] <=> $left['weight'];
        });

        $exact = !empty($analysis['exact_match_types']);

        if ($exact) {
            $analysis['tier'] = 'tier1';
        } elseif ($analysis['score'] >= $thresholds['tier2']) {
            $analysis['tier'] = 'tier2';
        } elseif ($analysis['score'] >= $thresholds['tier3']) {
            $analysis['tier'] = 'tier3';
        } else {
            return null;
        }

        $analysis['exact_match_types'] = MSP_PG_Utils::normalize_list($analysis['exact_match_types']);
        $analysis['matched_indicators'] = MSP_PG_Utils::normalize_list($analysis['matched_indicators']);
        $analysis['confidence'] = $analysis['tier'] === 'tier1' ? 'Exact Match' : ($analysis['tier'] === 'tier2' ? 'Strong Heuristic' : 'Interesting');
        $analysis['detection_source'] = $analysis['tier'] === 'tier1' ? 'Built-In Signature Registry' : 'Behavioral / Heuristic Analysis';
        $analysis['variant_hash'] = self::variant_hash($analysis);

        return $analysis;
    }

    private static function add_reason(&$analysis, $key, $label, $weight)
    {
        if (isset($analysis['reasons'][$key])) {
            return;
        }

        $analysis['reasons'][$key] = array(
            'key' => $key,
            'label' => $label,
            'weight' => (int) $weight,
        );
        $analysis['score'] += (int) $weight;
    }

    public static function variant_hash($analysis)
    {
        $fingerprint = array(
            'family' => $analysis['family'],
            'slug' => $analysis['plugin_slug'],
            'tier' => $analysis['tier'],
            'score' => $analysis['score'],
            'known_variant' => $analysis['known_variant'],
            'exact_match_types' => $analysis['exact_match_types'],
            'hash_hits' => $analysis['hash_hits'],
            'routes' => $analysis['routes'],
            'domains' => $analysis['domains'],
            'backdoor_indicators' => $analysis['backdoor_indicators'],
            'structural_indicators' => $analysis['structural_indicators'],
            'reasons' => array_map(function ($reason) {
                return $reason['key'];
            }, $analysis['reasons']),
        );

        return strtoupper(hash('sha256', wp_json_encode($fingerprint)));
    }
}
