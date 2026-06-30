<?php

/**
 * Impact360 - install / uninstall hooks
 *
 * The impact map stores no data of its own (it reads GLPI's native impact
 * relations and, optionally, the netstatconnections plugin's tables). The only
 * owned table is a small key/value config store backing the Computer
 * Dashboard's health-check settings and module toggles. (Asset retention lives
 * in uxcustomizer's Lifecycle and is consumed only when that plugin is active.)
 *
 * @license   GPL-3.0-or-later
 */

function plugin_impact360_install(): bool
{
    global $DB;

    $table = 'glpi_plugin_impact360_configs';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key`   VARCHAR(255) NOT NULL,
            `value` TEXT         NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    return true;
}

function plugin_impact360_uninstall(): bool
{
    global $DB;

    $table = 'glpi_plugin_impact360_configs';
    if ($DB->tableExists($table)) {
        $DB->doQuery("DROP TABLE `$table`");
    }

    return true;
}
