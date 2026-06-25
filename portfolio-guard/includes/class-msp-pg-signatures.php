<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Signatures
{
    private static $registry    = null;
    private static $registry_ok = false;

    // -------------------------------------------------------------------------
    // Registry availability
    // -------------------------------------------------------------------------

    public static function registry_available()
    {
        if (self::$registry === null) {
            self::load();
        }
        return self::$registry_ok;
    }

    // -------------------------------------------------------------------------
    // Public accessors (interface unchanged)
    // -------------------------------------------------------------------------

    public static function family()
    {
        if (self::$registry === null) {
            self::load();
        }
        return array(
            'family'   => MSP_PG_Config::family_name(),
            'variants' => self::$registry_ok ? self::$registry['variants'] : array(),
        );
    }

    public static function known_slugs()
    {
        return array_keys(self::family()['variants']);
    }

    public static function known_hashes()
    {
        $hashes = array();
        foreach (self::family()['variants'] as $variant) {
            foreach ($variant['hashes'] as $hash) {
                $hashes[strtoupper($hash)] = $variant['slug'];
            }
        }
        return $hashes;
    }

    public static function route_namespaces()
    {
        $routes = array();
        foreach (self::family()['variants'] as $variant) {
            foreach ($variant['routes'] as $route) {
                if ($route !== '') {
                    $routes[] = $route;
                }
            }
        }
        return $routes;
    }

    public static function backdoor_pairs()
    {
        $pairs = array();
        foreach (self::family()['variants'] as $variant) {
            foreach ($variant['backdoors'] as $backdoor) {
                $pairs[] = array_merge($backdoor, array('slug' => $variant['slug']));
            }
        }
        return $pairs;
    }

    public static function exact_ioc_strings()
    {
        if (self::$registry === null) {
            self::load();
        }
        return self::$registry_ok ? self::$registry['exact_ioc_strings'] : array();
    }

    public static function known_domains()
    {
        $domains = array();
        foreach (self::family()['variants'] as $variant) {
            foreach ($variant['domains'] as $domain) {
                $domains[] = $domain;
            }
        }
        return $domains;
    }

    public static function known_relative_filenames()
    {
        $filenames = array();
        foreach (self::family()['variants'] as $variant) {
            foreach ($variant['ioc_strings'] as $string) {
                if (preg_match('/^[a-z0-9]{4,8}\/[a-z0-9]{6,10}\.php$/i', $string)) {
                    $filenames[] = $string;
                }
            }
        }
        return $filenames;
    }

    public static function known_primary_plugin_files()
    {
        $files = array();
        foreach (self::family()['variants'] as $variant) {
            if (!empty($variant['main_file'])) {
                $files[$variant['main_file']] = $variant['slug'];
            }
        }
        return $files;
    }

    public static function variant_by_slug($slug)
    {
        $variants = self::family()['variants'];
        return isset($variants[$slug]) ? $variants[$slug] : null;
    }

    // -------------------------------------------------------------------------
    // Registry loading (private)
    // -------------------------------------------------------------------------

    private static function load()
    {
        $path = defined('MSP_PG_PLUGIN_FILE')
            ? plugin_dir_path(MSP_PG_PLUGIN_FILE) . 'data/signatures.json'
            : '';

        if ($path === '') {
            error_log('MSP Portfolio Guard: MSP_PG_PLUGIN_FILE is not defined — cannot locate signature registry');
            return;
        }

        if (!file_exists($path)) {
            error_log('MSP Portfolio Guard: signature registry not found at ' . $path);
            return;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            error_log('MSP Portfolio Guard: could not read signature registry at ' . $path);
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('MSP Portfolio Guard: signature registry JSON decode failed at ' . $path);
            return;
        }

        if (!isset($data['schema_version']) || $data['schema_version'] !== 1) {
            error_log('MSP Portfolio Guard: unsupported signature registry schema_version at ' . $path);
            return;
        }

        if (!isset($data['variants']) || !is_array($data['variants'])) {
            error_log('MSP Portfolio Guard: signature registry missing variants at ' . $path);
            return;
        }

        if (!isset($data['exact_ioc_strings']) || !is_array($data['exact_ioc_strings'])) {
            error_log('MSP Portfolio Guard: signature registry missing exact_ioc_strings at ' . $path);
            return;
        }

        self::$registry    = $data;
        self::$registry_ok = true;
    }
}
