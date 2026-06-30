<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Whitelist
{
    const OPTION_NAME = 'msp_pg_whitelist';

    /**
     * Add a plugin/version combination to the whitelist.
     *
     * Whitelisted entries suppress Tier 2 findings in future scans only for the
     * exact slug + version combination recorded here. Version upgrades require a
     * new approval decision.
     */
    public static function add($slug, $version, $userId = 0, $userLogin = '', $note = '')
    {
        $data              = self::load();
        $data[self::key($slug, $version)] = array(
            'slug'       => (string) $slug,
            'version'    => (string) $version,
            'timestamp'  => gmdate('c'),
            'user_id'    => (int) $userId,
            'user_login' => (string) $userLogin,
            'note'       => (string) $note,
        );
        self::save($data);
        return true;
    }

    /**
     * Remove a specific slug/version combination from the whitelist.
     * Returns true if an entry was removed, false if the entry did not exist.
     */
    public static function remove($slug, $version)
    {
        $data = self::load();
        $key  = self::key($slug, $version);
        if (!isset($data[$key])) {
            return false;
        }
        unset($data[$key]);
        self::save($data);
        return true;
    }

    /**
     * Return true if the exact slug/version combination is currently approved.
     */
    public static function is_approved($slug, $version)
    {
        $data = self::load();
        return isset($data[self::key($slug, $version)]);
    }

    /**
     * Return the whitelist entry for a specific slug/version, or null if absent.
     */
    public static function entry($slug, $version)
    {
        $data = self::load();
        $key  = self::key($slug, $version);
        return isset($data[$key]) ? $data[$key] : null;
    }

    /**
     * Return all whitelist entries as an indexed array, sorted by timestamp descending.
     */
    public static function all()
    {
        $data = array_values(self::load());
        usort($data, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        return $data;
    }

    /**
     * Return the count of current whitelist entries.
     */
    public static function count()
    {
        return count(self::load());
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function key($slug, $version)
    {
        return $slug . ':' . $version;
    }

    private static function load()
    {
        return (array) get_option(self::OPTION_NAME, array());
    }

    private static function save(array $data)
    {
        update_option(self::OPTION_NAME, $data, false);
    }
}
