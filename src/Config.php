<?php

/**
 * Impact360 - key/value config store + module toggles.
 *
 * Backs the Computer Dashboard's health-check settings and the module checks
 * the impact map calls. Stored in
 * glpi_plugin_impact360_configs (created by the install hook). Reads degrade
 * gracefully (return defaults) before the table exists.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

class Config
{
    private const TABLE = 'glpi_plugin_impact360_configs';

    /** In-request cache so early-boot checks don't re-query. */
    private static ?array $cache = null;

    /**
     * Load all config rows (cached per request).
     *
     * @return array<string,string>
     */
    private static function all(): array
    {
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        // During early boot / install the table may not exist yet.
        if (!$DB->tableExists(self::TABLE)) {
            return self::$cache;
        }

        foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
        return self::$cache;
    }

    /** Get a raw config value, or $default if unset. */
    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    /** Upsert a config value and refresh the cache. */
    public static function set(string $key, string $value): bool
    {
        global $DB;

        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['key' => $key],
            'LIMIT'  => 1,
        ]);

        $ok = (count($exists) > 0)
            ? (bool) $DB->update(self::TABLE, ['value' => $value], ['key' => $key])
            : (bool) $DB->insert(self::TABLE, ['key' => $key, 'value' => $value]);

        if ($ok && self::$cache !== null) {
            self::$cache[$key] = $value;
        }
        return $ok;
    }

    /**
     * Is a module enabled? Defaults to true when the toggle row is missing —
     * Impact360's features are on by default when the plugin is active.
     */
    public static function isModuleEnabled(string $module): bool
    {
        return self::get("module_{$module}_enabled", '1') === '1';
    }

    /** Enable/disable a module. */
    public static function setModuleEnabled(string $module, bool $enabled): bool
    {
        return self::set("module_{$module}_enabled", $enabled ? '1' : '0');
    }
}
