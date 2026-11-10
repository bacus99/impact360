<?php

/**
 * Impact360 - CVE threat-intel cache (CISA KEV + FIRST EPSS)
 *
 * Local cache of two public feeds used to enrich the unified per-CI
 * vulnerability view (VulnPriority):
 *   - CISA Known Exploited Vulnerabilities (KEV): is the CVE being exploited
 *     in the wild, the remediation due date, known ransomware use.
 *   - FIRST EPSS: probability (0..1) + percentile that the CVE is exploited
 *     in the next 30 days.
 *
 * Both are fetched by a daily GLPI automatic action (cronVulnIntel) — never on
 * a page render — and stored in glpi_plugin_impact360_cve_intel. The view only
 * ever reads that table, so a firewalled/proxied GLPI that can't reach the
 * feeds degrades to "no enrichment" rather than a slow or broken page.
 *
 * KEV is one small JSON file (all entries are stored). EPSS is queried only for
 * the CVEs that actually appear in the Armis/Nexpose/Defender catalogs, in
 * batches, stalest first, capped per run.
 *
 * HTTP goes through Toolbox::getGuzzleClient() (GLPI's own client — honours the
 * proxy configured under Setup → General), same as tasksmanager's connectors.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

class VulnIntel
{
    public const TABLE          = 'glpi_plugin_impact360_cve_intel';
    public const CRON_NAME      = 'VulnIntel';
    public const CRON_FREQUENCY = 86400;   // daily

    private const KEV_URL        = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
    private const EPSS_URL       = 'https://api.first.org/data/v1/epss';
    private const EPSS_BATCH     = 100;    // CVEs per request (keeps the URL well under 2 KB)
    private const EPSS_MAX_RUN   = 5000;   // CVEs refreshed per run, stalest first
    private const TIMEOUT        = 30;
    private const CONNECT_TIMEOUT = 10;
    private const CVE_RE         = '/^CVE-\d{4}-\d{4,}$/';

    /** Catalog tables whose `cve` column feeds the EPSS "tracked CVEs" list. */
    private const CATALOGS = [
        'glpi_plugin_armissync_vulnerabilities',
        'glpi_plugin_nexposesync_vulnerabilities',
        'glpi_plugin_defendersync_vulnerabilities',
    ];

    // ── Cron wiring ─────────────────────────────────────────────────────────

    /** Description shown on Setup → Automatic actions. */
    public static function cronInfo(string $name): array
    {
        if ($name === self::CRON_NAME) {
            return ['description' => __('Impact360: refresh CISA KEV and EPSS threat intelligence', 'impact360')];
        }
        return [];
    }

    /** GLPI cron entry point (dispatched as cron<CRON_NAME>). */
    public static function cronVulnIntel(\CronTask $task): int
    {
        $r      = self::refresh();
        $volume = $r['kev'] + $r['epss'];
        $task->addVolume($volume);
        foreach ($r['errors'] as $err) {
            $task->log($err);
        }
        return $volume > 0 ? 1 : 0;
    }

    // ── Refresh ─────────────────────────────────────────────────────────────

    /**
     * Pull both feeds into the cache. Each feed fails independently (a KEV
     * outage must not block EPSS and vice-versa). Also called by the "Refresh
     * now" button on the plugin's config page.
     *
     * @return array{kev:int,epss:int,errors:list<string>}
     */
    public static function refresh(): array
    {
        global $DB;

        $out = ['kev' => 0, 'epss' => 0, 'errors' => []];
        if (!$DB->tableExists(self::TABLE)) {
            $out['errors'][] = 'Intel table missing — re-run the plugin install/upgrade.';
            return $out;
        }

        try {
            $out['kev'] = self::refreshKev();
        } catch (\Throwable $e) {
            $out['errors'][] = 'KEV: ' . $e->getMessage();
            error_log('[Impact360] VulnIntel KEV refresh failed: ' . $e->getMessage());
        }

        try {
            $out['epss'] = self::refreshEpss();
        } catch (\Throwable $e) {
            $out['errors'][] = 'EPSS: ' . $e->getMessage();
            error_log('[Impact360] VulnIntel EPSS refresh failed: ' . $e->getMessage());
        }

        Config::set('intel_last_refresh', json_encode([
            'at'     => date('Y-m-d H:i:s'),
            'kev'    => $out['kev'],
            'epss'   => $out['epss'],
            'errors' => $out['errors'],
        ]));

        return $out;
    }

    /**
     * Last refresh outcome, or null if it never ran. 'rows' / 'kev_rows' are
     * live counts so the UI can say "cache is empty" even after a failed run.
     *
     * @return array{at:?string,errors:list<string>,rows:int,kev_rows:int}|null
     */
    public static function status(): ?array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }
        $raw  = Config::get('intel_last_refresh');
        $last = $raw !== null ? json_decode($raw, true) : null;

        $rows = 0;
        $kev  = 0;
        try {
            $rows = (int) countElementsInTable(self::TABLE);
            $kev  = (int) countElementsInTable(self::TABLE, ['kev' => 1]);
        } catch (\Throwable $e) {
            // leave zeros
        }

        return [
            'at'       => is_array($last) ? ($last['at'] ?? null) : null,
            'errors'   => is_array($last) ? array_values((array) ($last['errors'] ?? [])) : [],
            'rows'     => $rows,
            'kev_rows' => $kev,
        ];
    }

    private static function refreshKev(): int
    {
        global $DB;

        $data  = self::getJson(self::KEV_URL);
        $vulns = $data['vulnerabilities'] ?? null;
        if (!is_array($vulns) || $vulns === []) {
            throw new \RuntimeException('unexpected KEV payload (no "vulnerabilities" list)');
        }

        // One read of what's already cached; write only what changed.
        $existing = [];
        foreach ($DB->request([
            'SELECT' => ['cve', 'kev', 'kev_due', 'kev_ransomware'],
            'FROM'   => self::TABLE,
        ]) as $r) {
            $existing[(string) $r['cve']] = $r;
        }

        $now     = date('Y-m-d H:i:s');
        $changed = 0;
        foreach ($vulns as $v) {
            if (!is_array($v)) {
                continue;
            }
            $cve = strtoupper(trim((string) ($v['cveID'] ?? '')));
            if (preg_match(self::CVE_RE, $cve) !== 1) {
                continue;
            }
            $row = [
                'kev'            => 1,
                'kev_added'      => self::dateOrNull($v['dateAdded'] ?? null),
                'kev_due'        => self::dateOrNull($v['dueDate'] ?? null),
                'kev_ransomware' => strcasecmp((string) ($v['knownRansomwareCampaignUse'] ?? ''), 'Known') === 0 ? 1 : 0,
            ];

            $prev = $existing[$cve] ?? null;
            if ($prev === null) {
                $DB->insert(self::TABLE, ['cve' => $cve, 'date_mod' => $now] + $row);
                $changed++;
            } elseif ((int) $prev['kev'] !== 1
                || (string) ($prev['kev_due'] ?? '') !== (string) ($row['kev_due'] ?? '')
                || (int) $prev['kev_ransomware'] !== $row['kev_ransomware']) {
                $DB->update(self::TABLE, $row + ['date_mod' => $now], ['cve' => $cve]);
                $changed++;
            }
        }

        return $changed;
    }

    private static function refreshEpss(): int
    {
        global $DB;

        $tracked = self::trackedCves();
        if ($tracked === []) {
            return 0;
        }

        // Stalest first: never-scored CVEs, then oldest epss_checked.
        $existing = [];
        foreach ($DB->request([
            'SELECT' => ['cve', 'epss_checked'],
            'FROM'   => self::TABLE,
        ]) as $r) {
            $existing[(string) $r['cve']] = $r['epss_checked'];
        }
        usort($tracked, static function (string $a, string $b) use ($existing): int {
            return strcmp((string) ($existing[$a] ?? ''), (string) ($existing[$b] ?? ''));
        });
        $tracked = array_slice($tracked, 0, self::EPSS_MAX_RUN);

        $now     = date('Y-m-d H:i:s');
        $written = 0;
        foreach (array_chunk($tracked, self::EPSS_BATCH) as $batch) {
            $data = self::getJson(self::EPSS_URL, [
                'cve'   => implode(',', $batch),
                'limit' => self::EPSS_BATCH,
            ]);
            $rows = $data['data'] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $cve  = strtoupper(trim((string) ($r['cve'] ?? '')));
                $epss = isset($r['epss']) ? (float) $r['epss'] : null;
                $pct  = isset($r['percentile']) ? (float) $r['percentile'] : null;
                if (preg_match(self::CVE_RE, $cve) !== 1
                    || $epss === null || $pct === null
                    || $epss < 0.0 || $epss > 1.0 || $pct < 0.0 || $pct > 1.0) {
                    continue;
                }
                $row = [
                    'epss'            => round($epss, 5),
                    'epss_percentile' => round($pct, 5),
                    'epss_checked'    => $now,
                    'date_mod'        => $now,
                ];
                if (array_key_exists($cve, $existing)) {
                    $DB->update(self::TABLE, $row, ['cve' => $cve]);
                } else {
                    $DB->insert(self::TABLE, ['cve' => $cve] + $row);
                    $existing[$cve] = $now;
                }
                $written++;
            }
        }

        return $written;
    }

    /**
     * Distinct, well-formed CVE ids across the three scanner catalogs. Each
     * catalog is one row per CVE (small) and `cve` is indexed, so a per-table
     * GROUP BY is index-backed — deliberately NOT a UNION across tables (see
     * CveExposure's 2026-08-19 history note on MySQL error 1114).
     *
     * @return list<string>
     */
    private static function trackedCves(): array
    {
        global $DB;

        $set = [];
        foreach (self::CATALOGS as $table) {
            if (!$DB->tableExists($table)) {
                continue;
            }
            try {
                foreach ($DB->request([
                    'SELECT'  => ['cve'],
                    'FROM'    => $table,
                    'WHERE'   => ['cve' => ['LIKE', 'CVE-%']],
                    'GROUPBY' => ['cve'],
                ]) as $r) {
                    $cve = strtoupper(trim((string) $r['cve']));
                    if (preg_match(self::CVE_RE, $cve) === 1) {
                        $set[$cve] = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Impact360] VulnIntel::trackedCves(' . $table . ') failed: ' . $e->getMessage());
            }
        }
        return array_keys($set);
    }

    // ── Read side ───────────────────────────────────────────────────────────

    /**
     * Cached intel for a set of CVE ids. CVEs with no cached row are simply
     * absent from the result (caller treats that as "unknown", not "safe").
     *
     * @param list<string> $cves
     * @return array<string,array{kev:bool,kev_added:?string,kev_due:?string,ransomware:bool,epss:?float,epss_percentile:?float}>
     */
    public static function forCves(array $cves): array
    {
        global $DB;

        $cves = array_values(array_unique(array_filter($cves, static fn($c): bool => is_string($c) && $c !== '')));
        if ($cves === [] || !$DB->tableExists(self::TABLE)) {
            return [];
        }

        $out = [];
        try {
            foreach (array_chunk($cves, 500) as $chunk) {
                foreach ($DB->request([
                    'FROM'  => self::TABLE,
                    'WHERE' => ['cve' => $chunk],
                ]) as $r) {
                    $out[(string) $r['cve']] = [
                        'kev'             => ((int) $r['kev']) === 1,
                        'kev_added'       => $r['kev_added'] ?? null,
                        'kev_due'         => $r['kev_due'] ?? null,
                        'ransomware'      => ((int) $r['kev_ransomware']) === 1,
                        'epss'            => $r['epss'] !== null ? (float) $r['epss'] : null,
                        'epss_percentile' => $r['epss_percentile'] !== null ? (float) $r['epss_percentile'] : null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[Impact360] VulnIntel::forCves failed: ' . $e->getMessage());
        }
        return $out;
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    /**
     * GET a URL and decode the JSON body. Throws on transport error, non-200,
     * or a body that isn't a JSON object/array.
     *
     * @param array<string,scalar> $query
     * @return array<mixed>
     */
    private static function getJson(string $url, array $query = []): array
    {
        $options = [
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json'],
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }

        $client = method_exists(\Toolbox::class, 'getGuzzleClient')
            ? \Toolbox::getGuzzleClient($options)
            : new \GuzzleHttp\Client($options);

        // getGuzzleClient() may not carry per-request options through every
        // GLPI release; pass them on the call as well (harmless if duplicated).
        $res  = $client->request('GET', $url, $options);
        $code = $res->getStatusCode();
        if ($code !== 200) {
            throw new \RuntimeException('HTTP ' . $code . ' from ' . parse_url($url, PHP_URL_HOST));
        }
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json)) {
            throw new \RuntimeException('response from ' . parse_url($url, PHP_URL_HOST) . ' is not JSON');
        }
        return $json;
    }

    private static function dateOrNull(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }
}
