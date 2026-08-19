<?php

/**
 * Impact360 - CVE lookup data layer
 *
 * Given one CVE id, every asset that currently has it open (status =
 * vulnerable) across every itemtype the scanners cover (Computer,
 * NetworkEquipment, Phone, Printer, and the OT/custom asset types Armis
 * reports on), and which of the three security-scanner plugins (Armis,
 * Nexpose, Defender) currently flags it — membership is not uniform (an
 * asset may be Nexpose+Defender, another Defender only), so this returns
 * per-asset, per-source detail rather than a single yes/no.
 *
 * *** HISTORY (2026-08-19) ***
 * This started as a fleet-wide "every CVE, one row each" aggregate board
 * (COUNT/GROUP BY across a UNION of all three sources' findings). That
 * aggregate hit MySQL error 1114 ("the table is full" on an on-disk temp
 * table) in production — a single widespread CVE can touch thousands of
 * assets, and grouping across the whole catalog needed more temp-table
 * capacity than the server has. The aggregate has been removed rather than
 * left in place unused; what's left is exactly what's needed: look up ONE
 * CVE at a time. Each per-source query below is a plain indexed equality
 * lookup (`v.cve = ?`, `KEY cve` on the catalog table) with no UNION and no
 * GROUP BY, so it doesn't hit the same temp-table ceiling — which is also
 * why itemtype coverage could be restored to every itemtype the scanners
 * report on (not just Computer) without reintroducing that risk.
 *
 * Does NOT apply entity scoping (no `getEntitiesRestrictCriteria`): every
 * visible asset shows regardless of the session's active entities. That is
 * a deliberate, requested trade-off, not an oversight.
 *
 * Every source is a soft dependency: usable only when its plugin is active,
 * its two tables exist, and the current session holds READ on its right. A
 * session missing a source's right never sees that source's data at all —
 * not just a hidden column, the query never runs for it (fail-closed).
 *
 * Read-only: never writes to the security plugins' tables or any impact360
 * table. Owns no schema of its own.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

class CveExposure
{
    /**
     * Source registry, in display order. `exploitable` = false means the
     * catalog table has no such column (Armis) — unused now that the
     * aggregate is gone, kept for parity with the per-computer Dashboard
     * tab's own source map.
     */
    public const SOURCES = [
        'armis' => [
            'plugin'   => 'armissync',
            'class'    => 'PluginArmissyncVulnerability',
            'right'    => 'plugin_armissync_vulnerability',
            'catalog'  => 'glpi_plugin_armissync_vulnerabilities',
            'findings' => 'glpi_plugin_armissync_vulnerabilities_items',
            'fk'       => 'plugin_armissync_vulnerabilities_id',
            'label'    => 'Armis',
        ],
        'nexpose' => [
            'plugin'   => 'nexposesync',
            'class'    => 'PluginNexposesyncVulnerability',
            'right'    => 'plugin_nexposesync_vulnerability',
            'catalog'  => 'glpi_plugin_nexposesync_vulnerabilities',
            'findings' => 'glpi_plugin_nexposesync_vulnerabilities_items',
            'fk'       => 'plugin_nexposesync_vulnerabilities_id',
            'label'    => 'Nexpose',
        ],
        'defender' => [
            'plugin'   => 'defendersync',
            'class'    => 'PluginDefendersyncVulnerability',
            'right'    => 'plugin_defendersync_vulnerability',
            'catalog'  => 'glpi_plugin_defendersync_vulnerabilities',
            'findings' => 'glpi_plugin_defendersync_vulnerabilities_items',
            'fk'       => 'plugin_defendersync_vulnerabilities_id',
            'label'    => 'Defender',
        ],
    ];

    /** In-request memo — install state and session rights don't change mid-request. */
    private static ?array $usableCache = null;

    /**
     * Sources usable in THIS session: plugin active, both tables present, and
     * READ held on the source's right. Order follows SOURCES.
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
            if (!\Plugin::isPluginActive($src['plugin']) || !class_exists($src['class'])) {
                continue;
            }
            if (!$DB->tableExists($src['catalog']) || !$DB->tableExists($src['findings'])) {
                continue;
            }
            if (!\Session::haveRight($src['right'], READ)) {
                continue;
            }
            $out[$key] = $src;
        }

        return self::$usableCache = $out;
    }

    /**
     * Every asset that currently has $cve open (status = vulnerable), across
     * every itemtype the scanners cover, with per-source detail. A source
     * absent from an asset's 'sources' entry either doesn't flag this CVE
     * for it, or has already remediated it — this tool only surfaces what's
     * still open.
     *
     * @return list<array{itemtype:string,items_id:int,name:string,entities_id:?int,sources:array<string,array{first_seen:?string,last_seen:?string}>}>
     */
    public static function assetsFor(string $cve): array
    {
        global $DB;

        $merged = [];
        foreach (self::usableSources() as $key => $src) {
            foreach (self::itemtypesFor($src) as $itemtype) {
                $assetTable = self::tableFor($itemtype);
                if ($assetTable === null) {
                    continue;
                }

                $hasEntities = $DB->fieldExists($assetTable, 'entities_id');

                // No entities_id restriction (see class docblock).
                $where = [
                    'i.itemtype' => $itemtype,
                    'v.cve'      => $cve,
                    'i.status'   => 'vulnerable',   // "only the open one"
                ];
                if ($DB->fieldExists($assetTable, 'is_deleted')) {
                    $where['a.is_deleted'] = 0;
                }

                $select = [
                    'i.items_id AS items_id',
                    'i.first_seen AS first_seen',
                    'i.last_seen AS last_seen',
                    'a.name AS name',
                ];
                if ($hasEntities) {
                    $select[] = 'a.entities_id AS entities_id';
                }

                try {
                    foreach ($DB->request([
                        'SELECT'     => $select,
                        'FROM'       => $src['findings'] . ' AS i',
                        'INNER JOIN' => [
                            $src['catalog'] . ' AS v' => ['ON' => ['v' => 'id', 'i' => $src['fk']]],
                            $assetTable . ' AS a'      => ['ON' => ['a' => 'id', 'i' => 'items_id']],
                        ],
                        'WHERE' => $where,
                    ]) as $r) {
                        $itemsId = (int) $r['items_id'];
                        $ak      = $itemtype . '#' . $itemsId;
                        if (!isset($merged[$ak])) {
                            $merged[$ak] = [
                                'itemtype'    => $itemtype,
                                'items_id'    => $itemsId,
                                'name'        => (string) ($r['name'] ?? ''),
                                'entities_id' => $hasEntities ? (int) ($r['entities_id'] ?? 0) : null,
                                'sources'     => [],
                            ];
                        }
                        $merged[$ak]['sources'][$key] = [
                            'first_seen' => $r['first_seen'] ?? null,
                            'last_seen'  => $r['last_seen'] ?? null,
                        ];
                    }
                } catch (\Throwable $e) {
                    error_log(
                        '[Impact360] CveExposure::assetsFor(' . $cve . ', ' . $key
                        . ', ' . $itemtype . ') query failed: ' . $e->getMessage()
                    );
                }
            }
        }

        $items = array_values($merged);
        usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $items;
    }

    /**
     * Distinct itemtypes actually present in one source's findings table,
     * filtered to classes that exist, resolve to a real table, and pass
     * canView() — so a plugin listing 9 possible itemtypes doesn't blindly
     * cross-join all 9 when only 2 are ever populated.
     *
     * @param array<string,mixed> $src
     * @return list<string>
     */
    private static function itemtypesFor(array $src): array
    {
        global $DB;

        $out = [];
        try {
            foreach ($DB->request([
                'SELECT'  => ['itemtype'],
                'FROM'    => $src['findings'],
                'GROUPBY' => ['itemtype'],
            ]) as $r) {
                $itemtype = (string) ($r['itemtype'] ?? '');
                if ($itemtype === '' || !class_exists($itemtype)) {
                    continue;
                }
                if (!is_callable([$itemtype, 'canView']) || !$itemtype::canView()) {
                    continue;
                }
                if (self::tableFor($itemtype) === null) {
                    continue;
                }
                $out[] = $itemtype;
            }
        } catch (\Throwable $e) {
            error_log(
                '[Impact360] CveExposure::itemtypesFor(' . ($src['findings'] ?? '?')
                . ') query failed: ' . $e->getMessage()
            );
            return [];
        }
        return $out;
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
