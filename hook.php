<?php

/**
 * Impact360 - install / uninstall hooks
 *
 * The impact map stores no data of its own (it reads GLPI's native impact
 * relations and, optionally, the netstatconnections plugin's tables). Owned
 * tables: a small key/value config store backing the Computer Dashboard's
 * health-check settings and module toggles, and (since 1.4.0) a CVE
 * threat-intel cache (CISA KEV + EPSS) refreshed by the VulnIntel automatic
 * action. (Asset retention lives in uxcustomizer's Lifecycle and is consumed
 * only when that plugin is active.)
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

    // Threat-intel cache (since 1.4.0): CISA KEV + FIRST EPSS per CVE, filled
    // by the VulnIntel automatic action, read by the "Vuln Priority" tab. A
    // separate guarded CREATE, so it also lands on upgrade from ≤ 1.3.x.
    $intel = 'glpi_plugin_impact360_cve_intel';
    if (!$DB->tableExists($intel)) {
        $DB->doQuery("CREATE TABLE `$intel` (
            `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `cve`             VARCHAR(32)   NOT NULL,
            `kev`             TINYINT       NOT NULL DEFAULT 0,
            `kev_added`       DATE          NULL DEFAULT NULL,
            `kev_due`         DATE          NULL DEFAULT NULL,
            `kev_ransomware`  TINYINT       NOT NULL DEFAULT 0,
            `epss`            DECIMAL(6,5)  NULL DEFAULT NULL,
            `epss_percentile` DECIMAL(6,5)  NULL DEFAULT NULL,
            `epss_checked`    TIMESTAMP     NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `cve` (`cve`),
            KEY `kev` (`kev`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    // Daily KEV/EPSS refresh. Idempotent — CronTask::register no-ops if the
    // task already exists (same pattern as tasksmanager's namespaced crons).
    \CronTask::register(
        \GlpiPlugin\Impact360\VulnIntel::class,
        \GlpiPlugin\Impact360\VulnIntel::CRON_NAME,
        \GlpiPlugin\Impact360\VulnIntel::CRON_FREQUENCY,
        [
            'state'   => \CronTask::STATE_WAITING,
            'mode'    => \CronTask::MODE_EXTERNAL,
            'comment' => 'Impact360: CISA KEV + EPSS threat-intel refresh',
        ]
    );

    return true;
}

function plugin_impact360_uninstall(): bool
{
    global $DB;

    $cron = new \CronTask();
    if ($cron->getFromDBbyName(\GlpiPlugin\Impact360\VulnIntel::class, \GlpiPlugin\Impact360\VulnIntel::CRON_NAME)) {
        $cron->delete(['id' => $cron->getID()]);
    }

    foreach (['glpi_plugin_impact360_configs', 'glpi_plugin_impact360_cve_intel'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}
