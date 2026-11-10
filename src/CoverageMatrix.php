<?php

/**
 * Impact360 - Coverage-gap matrix data layer
 *
 * For every (entity-visible) asset: which sources actually see it —
 * Armis, the GLPI inventory agent, Microsoft Defender, Rapid7 Nexpose.
 * Assets seen by Armis and by nothing else are the unmanaged attack surface:
 * on the network, but no inventory agent, no EDR, no vulnerability scan.
 *
 * How "seen" is decided — each scanner plugin works GLPI → scanner (it looks
 * every GLPI item up in the scanner and caches the outcome per
 * (itemtype, items_id)), so each source has THREE states, not two:
 *   'S' seen        cache row with a real scanner id
 *   'A' not found   checked, but only the "unmatched" placeholder row exists
 *                   (armis_device_id = 0 / defender_machine_id = '' /
 *                    nexpose_asset_id = 0)
 *   '-' not checked no cache row at all (itemtype not synced by that plugin,
 *                   or the sync hasn't reached it yet)
 * Refinements: 'T' stale — a matched row whose last-seen timestamp is older
 * than the source's stale_days (unknown timestamp = still 'S'); 'D' (Defender
 * only) — the machine is known to Defender through network discovery but its
 * onboarding_status isn't Onboarded, i.e. no EDR sensor.
 * GLPI agent (glpi_agents, native): 'S' contacted within AGENT_STALE_DAYS,
 * 'T' agent known but stale/never contacted, 'A' no agent.
 * Only 'S' counts as covered.
 *
 * GLPI 11 custom assets share glpi_assets_assets; the base query applies the
 * itemtype's getSystemSQLCriteria() (its asset definition) and skips the type
 * if that can't be done — never lists another definition's rows.
 *
 * Query shape (deliberate — see CveExposure's 2026-08-19 MySQL 1114 note):
 * per itemtype, ONE entity-scoped read of the asset table, then ONE
 * `WHERE itemtype = ?` read per source (each cache has KEY item
 * (itemtype, items_id)). No UNION, no GROUP BY across sources, no aggregate —
 * the matrix is merged and counted in PHP. Bounded by MAX_ASSETS.
 *
 * Entity-scoped (unlike CVE Exposure): the base asset list goes through
 * getEntitiesRestrictCriteria, and cache rows for assets outside it are
 * ignored. A source the session can't read (plugin inactive, table missing,
 * or no READ on its right) is dropped entirely — fail-closed, same rule as
 * CveExposure::usableSources().
 *
 * Also counts GLPI "Unmanaged" (network-discovered, not inventoried) devices
 * that armissync's classification pass identified in Armis — Armis-only
 * devices that aren't even assets yet.
 *
 * Read-only. Owns no schema.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

class CoverageMatrix
{
    public const AGENT_STALE_DAYS = 30;
    public const MAX_ASSETS       = 50000;
    public const PAGE_SIZE        = 100;

    public const SEEN       = 'S';
    public const NOT_FOUND  = 'A';
    public const UNCHECKED  = '-';
    public const STALE      = 'T';
    /** Defender knows the device (network discovery) but it isn't onboarded — no EDR. */
    public const DISCOVERED = 'D';

    /** Precedence when an asset has several rows for one source: best state wins. */
    private const RANK = [
        self::UNCHECKED  => 0,
        self::NOT_FOUND  => 1,
        self::DISCOVERED => 2,
        self::STALE      => 3,
        self::SEEN       => 4,
    ];

    /**
     * Source registry, in column order. 'native' = GLPI core (glpi_agents);
     * the others are scanner-plugin caches. 'none' = the placeholder value of
     * 'key' on an "unmatched" row.
     */
    public const SOURCES = [
        'armis' => [
            'label'  => 'Armis',
            'plugin' => 'armissync',
            'right'  => 'plugin_armissync_vulnerability',
            'table'  => 'glpi_plugin_armissync_assets',
            'key'    => 'armis_device_id',
            'none'   => '0',
            'last'   => 'last_seen',
            'stale_days' => 30,
        ],
        'agent' => [
            'label'  => 'GLPI agent',
            'native' => true,
            'table'  => 'glpi_agents',
            'last'   => 'last_contact',
            'stale_days' => self::AGENT_STALE_DAYS,
        ],
        'defender' => [
            'label'  => 'Defender',
            'plugin' => 'defendersync',
            'right'  => 'plugin_defendersync_vulnerability',
            'table'  => 'glpi_plugin_defendersync_assets',
            'key'    => 'defender_machine_id',
            'none'   => '',
            'last'   => 'last_seen',
            'stale_days' => 30,
            // /api/machines also returns devices Defender merely DISCOVERED on
            // the network (CanBeOnboarded / Unsupported / InsufficientInfo);
            // only 'Onboarded' means an EDR sensor is there.
            'onboard' => 'onboarding_status',
        ],
        'nexpose' => [
            'label'  => 'Nexpose',
            'plugin' => 'nexposesync',
            'right'  => 'plugin_nexposesync_vulnerability',
            'table'  => 'glpi_plugin_nexposesync_assets',
            'key'    => 'nexpose_asset_id',
            'none'   => '0',
            'last'   => 'last_scan',
            'stale_days' => 90,   // scan cadence is slower than agent/EDR check-ins
        ],
    ];

    /** Preset views (besides an explicit pattern). */
    public const VIEWS = ['all', 'armis_only', 'missing', 'none', 'full', 'pattern'];

    private static ?array $usableCache = null;

    /**
     * Sources usable in THIS session, in column order.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function usableSources(): array
    {
        global $DB;

        if (self::$usableCache !== null) {
            return self::$usableCache;
        }

        $out = [];
        foreach (self::SOURCES as $key => $src) {
            if (!$DB->tableExists($src['table'])) {
                continue;
            }
            if (!empty($src['native'])) {
                if (!class_exists('Agent') || !\Agent::canView()) {
                    continue;
                }
            } else {
                if (!\Plugin::isPluginActive($src['plugin'])
                    || !\Session::haveRight($src['right'], READ)
                    || !$DB->fieldExists($src['table'], 'itemtype')
                    || !$DB->fieldExists($src['table'], $src['key'])) {
                    continue;
                }
            }
            $out[$key] = $src;
        }
        return self::$usableCache = $out;
    }

    /**
     * Itemtypes any usable source has rows for, that resolve to a real asset
     * table the session can view. Per-source `GROUP BY itemtype` on the
     * `item` index — tiny result, no cross-source union.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return list<string>
     */
    public static function itemtypes(array $sources): array
    {
        global $DB;

        $set = [];
        foreach ($sources as $key => $src) {
            try {
                foreach ($DB->request([
                    'SELECT'  => ['itemtype'],
                    'FROM'    => $src['table'],
                    'GROUPBY' => ['itemtype'],
                ]) as $r) {
                    $t = (string) ($r['itemtype'] ?? '');
                    if ($t !== '') {
                        $set[$t] = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Impact360] CoverageMatrix::itemtypes(' . $key . ') failed: ' . $e->getMessage());
            }
        }

        $out = [];
        foreach (array_keys($set) as $t) {
            if ($t === 'Unmanaged' || !class_exists($t)) {
                continue;   // Unmanaged is reported separately (unmanagedArmisCount)
            }
            if (!is_callable([$t, 'canView']) || !$t::canView()) {
                continue;
            }
            if (self::tableFor($t) === null) {
                continue;
            }
            $out[] = $t;
        }
        usort($out, static fn(string $a, string $b): int => strcasecmp(self::typeName($a), self::typeName($b)));
        return $out;
    }

    /**
     * The matrix: one row per visible asset with its per-source state.
     *
     * $q (name search) is applied in SQL, BEFORE the MAX_ASSETS cap, so an
     * asset past the cap is still findable by name.
     *
     * @param array<string,array<string,mixed>> $sources
     * @param list<string> $itemtypes
     * @return array{
     *   rows: list<array{t:string,id:int,name:string,e:?int,s:array<string,string>}>,
     *   last: array<string,array<string,string>>,
     *   truncated: bool
     * }  last = source key => "Type#id" => last seen/contact timestamp
     */
    public static function build(array $sources, array $itemtypes, string $q = ''): array
    {
        global $DB;

        $rows      = [];
        $last      = [];
        $truncated = false;

        // Per-source freshness cut-off (a sighting older than this is STALE).
        $cut = [];
        foreach ($sources as $k => $src) {
            $cut[$k] = time() - (int) ($src['stale_days'] ?? self::AGENT_STALE_DAYS) * 86400;
        }

        foreach ($itemtypes as $t) {
            $room = self::MAX_ASSETS - count($rows);
            if ($room <= 0) {
                $truncated = true;
                break;
            }
            $table = self::tableFor($t);
            if ($table === null) {
                continue;
            }

            // ── Base population: every live, visible asset of this type ──
            $hasName = $DB->fieldExists($table, 'name');
            $hasEnt  = $DB->fieldExists($table, 'entities_id');
            $select  = ['id'];
            if ($hasName) {
                $select[] = 'name';
            }
            if ($hasEnt) {
                $select[] = 'entities_id';
            }
            $where = [];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }
            if ($hasEnt) {
                $where = array_merge($where, \getEntitiesRestrictCriteria($table, '', '', true));
            }
            // The itemtype's own system criteria. Native types return []; GLPI 11
            // custom assets (Glpi\Asset\Asset subclasses) all share one table
            // (glpi_assets_assets) and return their assets_assetdefinitions_id —
            // without it every custom type would list every definition's rows.
            // Fail closed: never fall back to the unfiltered shared table.
            if (method_exists($t, 'getSystemSQLCriteria')) {
                try {
                    $where = array_merge($where, (array) $t::getSystemSQLCriteria());
                } catch (\Throwable $e) {
                    error_log('[Impact360] CoverageMatrix::build(' . $t . ') system criteria failed: ' . $e->getMessage());
                    continue;
                }
            } elseif ($DB->fieldExists($table, 'assets_assetdefinitions_id')) {
                continue;   // shared custom-asset table but no way to scope it — skip
            }
            if ($q !== '') {
                if (!$hasName) {
                    continue;   // nothing to match a name search against
                }
                $where[] = ['name' => ['LIKE', '%' . addcslashes($q, '%_\\') . '%']];
            }

            $base = [];   // id => row index in $rows
            try {
                $query = [
                    'SELECT' => $select,
                    'FROM'   => $table,
                    'ORDER'  => ['id ASC'],   // deterministic cut when MAX_ASSETS truncates
                    'LIMIT'  => $room + 1,
                ];
                if ($where !== []) {
                    $query['WHERE'] = $where;
                }
                foreach ($DB->request($query) as $r) {
                    if (count($base) >= $room) {
                        $truncated = true;
                        break;
                    }
                    $id = (int) $r['id'];
                    $state = [];
                    foreach ($sources as $k => $src) {
                        $state[$k] = !empty($src['native']) ? self::NOT_FOUND : self::UNCHECKED;
                    }
                    $base[$id] = count($rows);
                    $rows[] = [
                        't'    => $t,
                        'id'   => $id,
                        'name' => $hasName ? trim((string) ($r['name'] ?? '')) : '',
                        'e'    => $hasEnt ? (int) $r['entities_id'] : null,
                        's'    => $state,
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[Impact360] CoverageMatrix::build(' . $t . ') base query failed: ' . $e->getMessage());
                continue;
            }
            if ($base === []) {
                continue;
            }

            // ── Overlay each source (one indexed read per source) ──
            // An asset can have several cache rows per source (ambiguous
            // matches, several agents): the best state wins (see RANK).
            foreach ($sources as $k => $src) {
                $isAgent  = !empty($src['native']);
                $hasLast  = $DB->fieldExists($src['table'], $src['last']);
                $onboard  = (!$isAgent && isset($src['onboard']) && $DB->fieldExists($src['table'], $src['onboard']))
                    ? $src['onboard'] : null;
                $cols = ['items_id'];
                if ($hasLast) {
                    $cols[] = $src['last'];
                }
                if (!$isAgent) {
                    $cols[] = $src['key'];
                }
                if ($onboard !== null) {
                    $cols[] = $onboard;
                }
                try {
                    foreach ($DB->request([
                        'SELECT' => $cols,
                        'FROM'   => $src['table'],
                        'WHERE'  => ['itemtype' => $t],
                    ]) as $r) {
                        $id = (int) $r['items_id'];
                        if (!isset($base[$id])) {
                            continue;   // outside the visible population
                        }
                        $i  = $base[$id];
                        $ak = $t . '#' . $id;
                        $ts = $hasLast ? ($r[$src['last']] ?? null) : null;
                        $ts = ($ts === null || $ts === '') ? null : (string) $ts;

                        $matched = $isAgent
                            || (string) ($r[$src['key']] ?? '') !== (string) $src['none'];

                        if (!$matched) {
                            $state = self::NOT_FOUND;
                        } else {
                            if ($ts !== null && (!isset($last[$k][$ak]) || $ts > $last[$k][$ak])) {
                                $last[$k][$ak] = $ts;
                            }
                            $when = $ts !== null ? strtotime($ts) : false;
                            if ($onboard !== null
                                && !in_array(strtolower(trim((string) ($r[$onboard] ?? ''))), ['', 'onboarded'], true)) {
                                // '' = row synced before the column existed — keep old behaviour.
                                $state = self::DISCOVERED;
                            } elseif ($isAgent) {
                                // An agent that never reported, or not for a while, isn't coverage.
                                $state = ($when !== false && $when >= $cut[$k]) ? self::SEEN : self::STALE;
                            } else {
                                // Scanner sighting: stale only when a timestamp proves it is old
                                // (no timestamp / no column = freshness unknown → seen).
                                $state = ($when !== false && $when < $cut[$k]) ? self::STALE : self::SEEN;
                            }
                        }

                        if (self::RANK[$state] > self::RANK[$rows[$i]['s'][$k]]) {
                            $rows[$i]['s'][$k] = $state;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[Impact360] CoverageMatrix::build(' . $t . ', ' . $k . ') failed: ' . $e->getMessage());
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $c = strcasecmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
        });

        return ['rows' => $rows, 'last' => $last, 'truncated' => $truncated];
    }

    /** Coverage bits in source order: '1' = seen, '0' = anything else. */
    public static function bits(array $row, array $keys): string
    {
        $b = '';
        foreach ($keys as $k) {
            $b .= (($row['s'][$k] ?? '') === self::SEEN) ? '1' : '0';
        }
        return $b;
    }

    /**
     * Does a row's bit pattern match the selected view?
     *
     * @param list<string> $keys source keys in column order
     */
    public static function matches(string $bits, array $keys, string $view, string $src, string $pattern): bool
    {
        switch ($view) {
            case 'armis_only':
                $i = array_search('armis', $keys, true);
                if ($i === false) {
                    return false;
                }
                return $bits === str_pad('', $i, '0') . '1' . str_pad('', count($keys) - $i - 1, '0');
            case 'missing':
                $i = array_search($src, $keys, true);
                return $i !== false && $bits[$i] === '0';
            case 'none':
                return strpos($bits, '1') === false;
            case 'full':
                return strpos($bits, '0') === false;
            case 'pattern':
                return $bits === $pattern;
            default:
                return true;
        }
    }

    /**
     * Validated request filters (shared by the board and its CSV export).
     *
     * @param array<string,mixed> $get
     * @param list<string> $keys
     * @param list<string> $itemtypes
     * @return array{itemtype:string,view:string,src:string,p:string,q:string,start:int}
     */
    public static function filters(array $get, array $keys, array $itemtypes): array
    {
        $itemtype = (string) ($get['itemtype'] ?? '');
        if (!in_array($itemtype, $itemtypes, true)) {
            $itemtype = '';
        }
        $view = (string) ($get['view'] ?? 'all');
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'all';
        }
        $src = (string) ($get['src'] ?? '');
        if (!in_array($src, $keys, true)) {
            $src = '';
        }
        $p = (string) ($get['p'] ?? '');
        if (preg_match('/^[01]{' . count($keys) . '}$/', $p) !== 1) {
            $p = '';
        }
        if (($view === 'missing' && $src === '') || ($view === 'pattern' && $p === '')
            || ($view === 'armis_only' && !in_array('armis', $keys, true))) {
            $view = 'all';
        }
        $q = trim((string) ($get['q'] ?? ''));
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }

        return [
            'itemtype' => $itemtype,
            'view'     => $view,
            'src'      => $src,
            'p'        => $p,
            'q'        => $q,
            'start'    => max(0, (int) ($get['start'] ?? 0)),
        ];
    }

    /**
     * Count of each coverage pattern over $rows, most common first.
     *
     * @return array<string,int> bits => count
     */
    public static function patternCounts(array $rows, array $keys): array
    {
        $out = [];
        foreach ($rows as $r) {
            $b = self::bits($r, $keys);
            $out[$b] = ($out[$b] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }

    /**
     * GLPI "Unmanaged" devices (network-discovered, not inventoried) that
     * armissync's classification pass found in Armis — its marker line is
     * "[ArmisSync] Armis reports this as: …". Entity-scoped. Null when Armis
     * isn't usable or the Unmanaged list isn't viewable.
     */
    public static function unmanagedArmisCount(): ?int
    {
        global $DB;

        if (!isset(self::usableSources()['armis'])
            || !class_exists('Unmanaged') || !\Unmanaged::canView()
            || !$DB->tableExists('glpi_unmanageds')) {
            return null;
        }

        $where = ['comment' => ['LIKE', '%[ArmisSync] Armis reports this as%']];
        if ($DB->fieldExists('glpi_unmanageds', 'is_deleted')) {
            $where['is_deleted'] = 0;
        }
        $where = array_merge($where, \getEntitiesRestrictCriteria('glpi_unmanageds', '', '', true));

        try {
            return (int) countElementsInTable('glpi_unmanageds', $where);
        } catch (\Throwable $e) {
            error_log('[Impact360] CoverageMatrix::unmanagedArmisCount failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Human label for a state in a given source column.
     *
     * @param array<string,mixed> $src one SOURCES entry
     */
    public static function stateLabel(string $state, array $src): string
    {
        $isAgent = !empty($src['native']);
        switch ($state) {
            case self::SEEN:
                return __('Seen', 'impact360');
            case self::STALE:
                return sprintf(
                    $isAgent ? __('Agent silent > %d days', 'impact360') : __('Last seen > %d days ago', 'impact360'),
                    (int) ($src['stale_days'] ?? self::AGENT_STALE_DAYS)
                );
            case self::DISCOVERED:
                return __('Discovered, not onboarded (no EDR)', 'impact360');
            case self::NOT_FOUND:
                return $isAgent ? __('No agent', 'impact360') : __('Not found', 'impact360');
            default:
                return __('Not checked', 'impact360');
        }
    }

    public static function typeName(string $itemtype): string
    {
        return (class_exists($itemtype) && method_exists($itemtype, 'getTypeName'))
            ? (string) $itemtype::getTypeName(1)
            : $itemtype;
    }

    /** Entity display name, memoised per request. */
    public static function entityName(?int $id): string
    {
        static $memo = [];
        if ($id === null) {
            return '';
        }
        if (!isset($memo[$id])) {
            try {
                $memo[$id] = (string) \Dropdown::getDropdownName('glpi_entities', $id);
            } catch (\Throwable $e) {
                $memo[$id] = '';
            }
        }
        return $memo[$id];
    }

    /** Resolve the SQL table for an itemtype, mirroring ImpactMap::tableFor(). */
    private static function tableFor(string $itemtype): ?string
    {
        global $DB;

        if ($itemtype === '' || !class_exists($itemtype)) {
            return null;
        }
        try {
            $table = method_exists($itemtype, 'getTable')
                ? $itemtype::getTable()
                : (\getTableForItemType($itemtype) ?: null);
        } catch (\Throwable $e) {
            return null;
        }
        return ($table && $DB->tableExists($table)) ? $table : null;
    }
}
