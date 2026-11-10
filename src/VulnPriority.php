<?php

/**
 * Impact360 - unified, contextual vulnerability priority for ONE CI
 *
 * Merges the open findings of Armis, Nexpose and Defender for a single asset
 * into one de-duplicated CVE list (same CVE reported by several scanners =
 * one row listing every source), enriches each CVE with CISA KEV + EPSS
 * (VulnIntel cache), and ranks it by a contextual priority score:
 *
 *   score = 100 × (CVSS/10) × exploit × armis × criticality / NORMALISER   (cap 100)
 *
 *   CVSS        vulnerability severity — highest CVSS reported by any scanner,
 *               falling back to the severity label when no number is present.
 *   exploit     likelihood of real-world exploitation:
 *                 KEV listed                       → 1.0
 *                 EPSS percentile p known          → 0.4 + 0.5·p   (0.4 .. 0.9)
 *                 no intel                         → 0.6  (neutral — unknown ≠ safe)
 *               and a scanner's own "exploitable" flag floors it at 0.8.
 *   armis       0.5 + risk_level/10  (Armis device risk 0..10 → 0.5 .. 1.5);
 *               1.0 when Armis has no matched device (or the session can't
 *               read Armis).
 *   criticality 0.6 + 0.2·(level-1) for business-criticality level 1..5
 *               (0.6 .. 1.4). Impact360 has no criticality field, so the level
 *               is DERIVED from the impact graph — see criticality().
 *
 * The CI's priority is the MAX over its open CVEs (one critical exposure is
 * what matters, not how many mediums pile up); tiers P1 ≥ 70, P2 ≥ 45,
 * P3 ≥ 20, else P4. All weights are constants here so the model is easy to
 * tune and explain — the tab shows every factor behind each score.
 *
 * Scope: Armis, Nexpose, Defender only (they share one two-table model).
 * Pentest-Tools findings live on the finding row and are website-scoped, so
 * they stay in CveExposure. Read-only; never writes to any scanner table.
 *
 * NOTE: sources are gated by the SESSION's rights (CveExposure::usableSources,
 * fail-closed), so a user without the Armis right gets a score computed
 * without the Armis factor. Any non-interactive consumer (e.g. a ServiceNow
 * push) must run with a context that holds all three rights, or its score
 * will differ from what a fully-privileged user sees on the tab.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

class VulnPriority
{
    /** Sources merged into the unified view, in display order. */
    public const SOURCE_KEYS = ['armis', 'nexpose', 'defender'];

    public const TIER_P1 = 70;
    public const TIER_P2 = 45;
    public const TIER_P3 = 20;

    /** Product of the four factors at "typical worst" maps to ~100. */
    private const NORMALISER = 1.6;

    /** CVSS stand-ins when a scanner gives a label but no number. */
    private const SEVERITY_CVSS = [
        'critical' => 9.5,
        'severe'   => 8.0,
        'high'     => 8.0,
        'moderate' => 5.5,
        'medium'   => 5.5,
        'low'      => 2.5,
    ];
    private const SEVERITY_RANK = [
        'critical' => 4, 'severe' => 3, 'high' => 3, 'moderate' => 2, 'medium' => 2, 'low' => 1,
    ];

    private const CVE_RE = '/^CVE-\d{4}-\d{4,}$/';

    // ── Pure scoring (no DB) ────────────────────────────────────────────────

    /** Best CVSS number from a free-text cvss field, else the severity label's stand-in. */
    public static function cvssOf(string $cvss, string $severity): float
    {
        if (preg_match('/\d+(?:\.\d+)?/', $cvss, $m) === 1) {
            return max(0.0, min(10.0, (float) $m[0]));
        }
        return self::SEVERITY_CVSS[strtolower(trim($severity))] ?? 0.0;
    }

    public static function exploitFactor(bool $kev, ?float $epssPercentile, bool $scannerExploitable): float
    {
        if ($kev) {
            return 1.0;
        }
        $f = $epssPercentile !== null
            ? 0.4 + 0.5 * max(0.0, min(1.0, $epssPercentile))
            : 0.6;
        return $scannerExploitable ? max($f, 0.8) : $f;
    }

    public static function armisFactor(?int $riskLevel): float
    {
        return $riskLevel === null ? 1.0 : 0.5 + max(0, min(10, $riskLevel)) / 10;
    }

    public static function criticalityFactor(int $level): float
    {
        return 0.6 + 0.2 * (max(1, min(5, $level)) - 1);
    }

    public static function score(float $cvss, float $exploit, float $armis, float $criticality): int
    {
        $raw = 100 * ($cvss / 10) * $exploit * $armis * $criticality / self::NORMALISER;
        return (int) max(0, min(100, round($raw)));
    }

    /** 'P1'..'P4', or 'none' when the CI has no open CVE. */
    public static function tier(int $score, bool $hasFindings = true): string
    {
        if (!$hasFindings) {
            return 'none';
        }
        if ($score >= self::TIER_P1) {
            return 'P1';
        }
        if ($score >= self::TIER_P2) {
            return 'P2';
        }
        return $score >= self::TIER_P3 ? 'P3' : 'P4';
    }

    // ── Per-CI view ─────────────────────────────────────────────────────────

    /** Scanner sources usable in THIS session, restricted to the three merged here. */
    public static function sources(): array
    {
        return array_intersect_key(CveExposure::usableSources(), array_flip(self::SOURCE_KEYS));
    }

    /**
     * Everything the tab shows for one CI.
     *
     * @return array{
     *   sources:list<string>, priority:int, tier:string, top_cve:?string,
     *   counts:array{cves:int,kev:int,skipped:int,per_source:array<string,int>},
     *   armis:?array{risk_level:int,business_impact:string,tier:string,last_seen:?string},
     *   criticality:array{level:int,apps:list<array<string,mixed>>,dependents:int,reasons:list<string>},
     *   intel:?array{at:?string,errors:list<string>,rows:int,kev_rows:int},
     *   cves:list<array<string,mixed>>
     * }
     */
    public static function forItem(string $itemtype, int $id): array
    {
        $sources = self::sources();

        $merged  = self::findings($itemtype, $id, $sources);
        $armis   = isset($sources['armis']) ? self::armisContext($itemtype, $id) : null;
        $crit    = self::criticality($itemtype, $id);
        $intel   = VulnIntel::forCves(array_keys($merged['cves']));

        $armisF = self::armisFactor($armis['risk_level'] ?? null);
        $critF  = self::criticalityFactor($crit['level']);

        $cves = [];
        $kevCount = 0;
        foreach ($merged['cves'] as $cve => $c) {
            $i       = $intel[$cve] ?? null;
            $cvss    = self::cvssOf((string) $c['cvss'], (string) $c['severity']);
            $exploit = self::exploitFactor(
                (bool) ($i['kev'] ?? false),
                $i['epss_percentile'] ?? null,
                (bool) $c['exploitable']
            );
            $score = self::score($cvss, $exploit, $armisF, $critF);
            if (!empty($i['kev'])) {
                $kevCount++;
            }
            $cves[] = [
                'cve'             => $cve,
                'title'           => $c['title'],
                'severity'        => $c['severity'],
                'cvss'            => $cvss,
                'exploitable'     => $c['exploitable'],
                'kev'             => (bool) ($i['kev'] ?? false),
                'kev_due'         => $i['kev_due'] ?? null,
                'ransomware'      => (bool) ($i['ransomware'] ?? false),
                'epss'            => $i['epss'] ?? null,
                'epss_percentile' => $i['epss_percentile'] ?? null,
                'intel_known'     => $i !== null,
                'sources'         => $c['sources'],
                'first_seen'      => $c['first_seen'],
                'last_seen'       => $c['last_seen'],
                'score'           => $score,
                'tier'            => self::tier($score),
                'factors'         => [
                    'cvss'        => $cvss,
                    'exploit'     => round($exploit, 2),
                    'armis'       => round($armisF, 2),
                    'criticality' => round($critF, 2),
                ],
            ];
        }

        usort($cves, static function (array $a, array $b): int {
            return [$b['score'], $b['cvss'], $a['cve']] <=> [$a['score'], $a['cvss'], $b['cve']];
        });

        $priority = $cves === [] ? 0 : (int) $cves[0]['score'];

        return [
            'sources'     => array_keys($sources),
            'priority'    => $priority,
            'tier'        => self::tier($priority, $cves !== []),
            'top_cve'     => $cves[0]['cve'] ?? null,
            'counts'      => [
                'cves'       => count($cves),
                'kev'        => $kevCount,
                'skipped'    => $merged['skipped'],
                'per_source' => $merged['per_source'],
            ],
            'armis'       => $armis,
            'criticality' => $crit,
            'intel'       => VulnIntel::status(),
            'cves'        => $cves,
        ];
    }

    /**
     * Open findings of one CI from every usable source, merged by CVE id.
     * One indexed lookup per source (`KEY item (itemtype, items_id)`) — no
     * UNION, no aggregate, so no temp-table risk (see CveExposure history).
     * Findings with no well-formed CVE id (vendor-only advisories) are
     * counted in 'skipped', not shown.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array{cves:array<string,array<string,mixed>>,skipped:int,per_source:array<string,int>}
     */
    private static function findings(string $itemtype, int $id, array $sources): array
    {
        global $DB;

        $cves      = [];
        $skipped   = 0;
        $perSource = [];

        foreach ($sources as $key => $src) {
            $perSource[$key] = 0;
            $select = ['v.cve AS cve', 'v.title AS title', 'v.severity AS severity', 'v.cvss AS cvss',
                       'i.first_seen AS first_seen', 'i.last_seen AS last_seen'];
            $hasExploit = $DB->fieldExists($src['catalog'], 'exploitable');   // Armis has none
            if ($hasExploit) {
                $select[] = 'v.exploitable AS exploitable';
            }

            try {
                foreach ($DB->request([
                    'SELECT'     => $select,
                    'FROM'       => $src['findings'] . ' AS i',
                    'INNER JOIN' => [
                        $src['catalog'] . ' AS v' => ['ON' => ['v' => 'id', 'i' => $src['fk']]],
                    ],
                    'WHERE'      => [
                        'i.itemtype' => $itemtype,
                        'i.items_id' => $id,
                        'i.status'   => $src['open'] ?? 'vulnerable',
                    ],
                ]) as $r) {
                    $cve = strtoupper(trim((string) ($r['cve'] ?? '')));
                    if (preg_match(self::CVE_RE, $cve) !== 1) {
                        $skipped++;
                        continue;
                    }
                    $perSource[$key]++;

                    $sev = strtolower(trim((string) ($r['severity'] ?? '')));
                    if (!isset($cves[$cve])) {
                        $cves[$cve] = [
                            'title'       => '',
                            'severity'    => '',
                            'cvss'        => '',
                            'exploitable' => false,
                            'first_seen'  => null,
                            'last_seen'   => null,
                            'sources'     => [],
                        ];
                    }
                    $m = &$cves[$cve];

                    if ($m['title'] === '') {
                        $m['title'] = trim((string) ($r['title'] ?? ''));
                    }
                    if ((self::SEVERITY_RANK[$sev] ?? 0) > (self::SEVERITY_RANK[$m['severity']] ?? 0)) {
                        $m['severity'] = $sev;
                    }
                    // Keep the highest numeric CVSS any scanner reported.
                    $cv = trim((string) ($r['cvss'] ?? ''));
                    if ($cv !== '' && ($m['cvss'] === '' || self::cvssOf($cv, '') > self::cvssOf((string) $m['cvss'], ''))) {
                        $m['cvss'] = $cv;
                    }
                    if ($hasExploit && !empty($r['exploitable'])) {
                        $m['exploitable'] = true;
                    }
                    $first = $r['first_seen'] ?? null;
                    $last  = $r['last_seen'] ?? null;
                    if ($first !== null && ($m['first_seen'] === null || $first < $m['first_seen'])) {
                        $m['first_seen'] = $first;
                    }
                    if ($last !== null && ($m['last_seen'] === null || $last > $m['last_seen'])) {
                        $m['last_seen'] = $last;
                    }
                    $m['sources'][$key] = ['first_seen' => $first, 'last_seen' => $last];
                    unset($m);
                }
            } catch (\Throwable $e) {
                error_log('[Impact360] VulnPriority::findings(' . $itemtype . '#' . $id . ', ' . $key
                    . ') failed: ' . $e->getMessage());
            }
        }

        return ['cves' => $cves, 'skipped' => $skipped, 'per_source' => $perSource];
    }

    /**
     * Armis device context for the CI (highest risk across its matched
     * devices; placeholder rows with armis_device_id = 0 are unmatched checks
     * and carry no signal).
     *
     * @return array{risk_level:int,business_impact:string,tier:string,last_seen:?string}|null
     */
    private static function armisContext(string $itemtype, int $id): ?array
    {
        global $DB;

        $table = 'glpi_plugin_armissync_assets';
        if (!$DB->tableExists($table)) {
            return null;
        }

        $out = null;
        try {
            foreach ($DB->request([
                'SELECT' => ['risk_level', 'business_impact', 'tier', 'last_seen'],
                'FROM'   => $table,
                'WHERE'  => [
                    'itemtype' => $itemtype,
                    'items_id' => $id,
                    ['armis_device_id' => ['>', 0]],
                ],
            ]) as $r) {
                $risk = (int) ($r['risk_level'] ?? 0);
                if ($out === null || $risk > $out['risk_level']) {
                    $out = [
                        'risk_level'      => $risk,
                        'business_impact' => trim((string) ($r['business_impact'] ?? '')),
                        'tier'            => trim((string) ($r['tier'] ?? '')),
                        'last_seen'       => $r['last_seen'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[Impact360] VulnPriority::armisContext failed: ' . $e->getMessage());
        }
        return $out;
    }

    // ── Business criticality (derived from the impact graph) ────────────────

    /**
     * Business-criticality level 1..5, derived — not entered — from what the
     * CI carries in the graph Impact360 already reads:
     *
     *   base 1
     *   +2  the CI belongs to (Appliance membership) or directly feeds
     *       (native impact relation → Appliance, or → a member of one) at
     *       least one application
     *   +1  …and at least one of those applications is a production one
     *       (Appliance "Environment" name contains prod/prd, not non-/pre-prod)
     *   +1  ≥ 1 directly dependent CI downstream (native impact, 1 hop)
     *   +1  ≥ 10 directly dependent CIs
     *   cap 5
     *
     * One hop only, bounded queries — this runs per page view. Entity-scoped
     * on the Appliances it names, like the Application Health board.
     *
     * @return array{level:int,apps:list<array{id:int,name:string,env:string,prod:bool,via:string}>,dependents:int,reasons:list<string>}
     */
    public static function criticality(string $itemtype, int $id): array
    {
        global $DB;

        $apps       = [];   // appliances_id => 'member'|'downstream'
        $dependents = 0;

        // Direct memberships.
        if ($DB->tableExists('glpi_appliances_items')) {
            try {
                foreach ($DB->request([
                    'SELECT' => ['appliances_id'],
                    'FROM'   => 'glpi_appliances_items',
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $id],
                ]) as $r) {
                    $apps[(int) $r['appliances_id']] = 'member';
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        // Downstream: CIs this one impacts (1 hop), capped.
        if ($DB->tableExists('glpi_impactrelations')) {
            $byType = [];
            try {
                foreach ($DB->request([
                    'SELECT' => ['itemtype_impacted', 'items_id_impacted'],
                    'FROM'   => 'glpi_impactrelations',
                    'WHERE'  => ['itemtype_source' => $itemtype, 'items_id_source' => $id],
                    'LIMIT'  => 500,
                ]) as $r) {
                    $t = (string) $r['itemtype_impacted'];
                    $i = (int) $r['items_id_impacted'];
                    if ($t === '' || $i <= 0) {
                        continue;
                    }
                    $dependents++;
                    if ($t === 'Appliance') {
                        $apps[$i] = $apps[$i] ?? 'downstream';
                    } else {
                        $byType[$t][] = $i;
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }

            // Applications that the dependents themselves belong to.
            if ($byType !== [] && $DB->tableExists('glpi_appliances_items')) {
                foreach ($byType as $t => $ids) {
                    try {
                        foreach ($DB->request([
                            'SELECT' => ['appliances_id'],
                            'FROM'   => 'glpi_appliances_items',
                            'WHERE'  => ['itemtype' => $t, 'items_id' => array_values(array_unique($ids))],
                        ]) as $r) {
                            $aid = (int) $r['appliances_id'];
                            $apps[$aid] = $apps[$aid] ?? 'downstream';
                        }
                    } catch (\Throwable $e) {
                        // non-fatal
                    }
                }
            }
        }

        $appRows = self::applianceRows(array_keys($apps), $apps);

        $anyApp  = $appRows !== [];
        $anyProd = false;
        foreach ($appRows as $a) {
            $anyProd = $anyProd || $a['prod'];
        }

        $level   = 1;
        $reasons = [];
        if ($anyApp) {
            $level += 2;
            $reasons[] = sprintf(_n('Part of %d application', 'Part of %d applications', count($appRows), 'impact360'), count($appRows));
        }
        if ($anyProd) {
            $level += 1;
            $reasons[] = __('At least one application is in production', 'impact360');
        }
        if ($dependents >= 1) {
            $level += 1;
            $reasons[] = sprintf(_n('%d dependent CI downstream', '%d dependent CIs downstream', $dependents, 'impact360'), $dependents);
        }
        if ($dependents >= 10) {
            $level += 1;
        }
        if ($reasons === []) {
            $reasons[] = __('No application or downstream dependency recorded', 'impact360');
        }

        return [
            'level'      => min(5, $level),
            'apps'       => $appRows,
            'dependents' => $dependents,
            'reasons'    => $reasons,
        ];
    }

    /**
     * Resolve appliance ids to name + environment + prod flag, entity-scoped
     * and excluding deleted ones. Environment is read only when the column
     * exists (guarded, so a schema difference degrades to prod=false).
     *
     * @param list<int> $ids
     * @param array<int,string> $via
     * @return list<array{id:int,name:string,env:string,prod:bool,via:string}>
     */
    private static function applianceRows(array $ids, array $via): array
    {
        global $DB;

        if ($ids === [] || !$DB->tableExists('glpi_appliances')) {
            return [];
        }

        $hasEnv = $DB->fieldExists('glpi_appliances', 'appliances_environments_id')
            && $DB->tableExists('glpi_appliances_environments');

        $select = ['id', 'name'];
        if ($hasEnv) {
            $select[] = 'appliances_environments_id';
        }

        $rows = [];
        try {
            foreach ($DB->request([
                'SELECT' => $select,
                'FROM'   => 'glpi_appliances',
                'WHERE'  => array_merge(
                    ['id' => $ids, 'is_deleted' => 0],
                    \getEntitiesRestrictCriteria('glpi_appliances', '', '', true)
                ),
                'ORDER'  => ['name ASC'],
            ]) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        } catch (\Throwable $e) {
            return [];
        }

        $envNames = [];
        if ($hasEnv) {
            $envIds = array_values(array_unique(array_filter(array_map(
                static fn(array $r): int => (int) ($r['appliances_environments_id'] ?? 0),
                $rows
            ))));
            if ($envIds !== []) {
                try {
                    foreach ($DB->request([
                        'SELECT' => ['id', 'name'],
                        'FROM'   => 'glpi_appliances_environments',
                        'WHERE'  => ['id' => $envIds],
                    ]) as $e) {
                        $envNames[(int) $e['id']] = (string) $e['name'];
                    }
                } catch (\Throwable $e) {
                    // leave names empty
                }
            }
        }

        $out = [];
        foreach ($rows as $aid => $r) {
            $env = $envNames[(int) ($r['appliances_environments_id'] ?? 0)] ?? '';
            $out[] = [
                'id'   => $aid,
                'name' => (string) ($r['name'] !== '' ? $r['name'] : ('Appliance #' . $aid)),
                'env'  => $env,
                'prod' => self::isProduction($env),
                'via'  => $via[$aid] ?? 'member',
            ];
        }
        return $out;
    }

    /** Production-ish environment name, excluding non-prod / pre-prod. */
    public static function isProduction(string $env): bool
    {
        $env = trim($env);
        if ($env === '' || preg_match('/non[\s_-]?prod|pre[\s_-]?prod|nonprod|preprod/i', $env) === 1) {
            return false;
        }
        return preg_match('/prod|prd/i', $env) === 1;
    }
}
