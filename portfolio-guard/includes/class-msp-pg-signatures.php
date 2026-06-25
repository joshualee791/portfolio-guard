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
    // Cache invalidation — called by MSP_PG_Updater after successful install
    // -------------------------------------------------------------------------

    public static function reset()
    {
        self::$registry    = null;
        self::$registry_ok = false;
    }

    // -------------------------------------------------------------------------
    // Registry loading (private)
    // -------------------------------------------------------------------------

    private static function load()
    {
        $installedPath = defined('MSP_PG_PLUGIN_FILE')
            ? plugin_dir_path(MSP_PG_PLUGIN_FILE) . 'data/signatures.json'
            : '';

        $appliedPath = MSP_PG_Config::applied_registry_path();

        $installed = $installedPath !== '' ? self::load_from_path($installedPath) : null;
        $applied   = $appliedPath   !== '' ? self::load_from_path($appliedPath)   : null;

        $installedVersion = ($installed !== null) ? (int) $installed['registry_version'] : -1;
        $appliedVersion   = ($applied   !== null) ? (int) $applied['registry_version']   : -1;

        if ($applied !== null && $appliedVersion >= $installedVersion) {
            self::$registry    = $applied;
            self::$registry_ok = true;
            return;
        }

        if ($installed !== null) {
            self::$registry    = $installed;
            self::$registry_ok = true;
            return;
        }

        error_log('MSP Portfolio Guard: signature registry unavailable — both installed and applied paths failed validation.');
    }

    private static function load_from_path($path)
    {
        if (empty($path) || !file_exists($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if (!isset($data['schema_version']) || $data['schema_version'] !== 1) {
            return null;
        }

        // registry_version is required; absent treated as 0 for backward compatibility
        if (!array_key_exists('registry_version', $data)) {
            $data['registry_version'] = 0;
        } elseif (!is_int($data['registry_version']) || $data['registry_version'] < 0) {
            return null;
        }

        if (empty($data['variants']) || !is_array($data['variants'])) {
            return null;
        }

        if (!isset($data['exact_ioc_strings']) || !is_array($data['exact_ioc_strings'])) {
            return null;
        }

        return $data;
    }
}
