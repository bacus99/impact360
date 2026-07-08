<?php

/**
 * UX Customizer - Impact Map data layer
 *
 * Reads GLPI 11's native impact-analysis tables and returns a graph
 * (nodes, edges, compounds) suitable for rendering with vis-network on
 * the client. Read-only: this never writes to GLPI's impact tables.
 *
 * Native GLPI tables consumed:
 *   - glpi_impactrelations  edges: itemtype_source/items_id_source -> itemtype_impacted/items_id_impacted
 *   - glpi_impactitems      per-node persistence (compound membership, positions)
 *   - glpi_impactcompounds  named groups: id, name, color
 *
 * Returns plain arrays of scalars; everything user-visible is HTML-escaped
 * at the consumer side (vis-network titles use HTML option).
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

class ImpactMap
{
    /**
     * Hard cap on returned nodes — protects the browser and the DB from
     * accidentally rendering a 5000-node org map. The UI surfaces a
     * "truncated" notice when this kicks in.
     */
    public const MAX_NODES = 750;

    /**
     * Cap on observed (netstat) edges attached per in-scope node. Stops a
     * chatty host (e.g. a monitoring server that talks to everything) from
     * exploding the graph. Highest-weight edges win the cap.
     */
    public const MAX_OBSERVED_PER_NODE = 20;

    /**
     * Itemtype -> visual identity (Faddom-style colored nodes).
     * Tabler glyph names (rendered next to the label in the side panel, not
     * inside the node — vis-network doesn't render Tabler glyphs natively).
     */
    private const ITEMTYPE_STYLE = [
        'Computer'         => ['color' => '#4C9BE8', 'border' => '#2563EB', 'icon' => 'ti ti-device-desktop',     'label' => 'Computer'],
        'Monitor'          => ['color' => '#38B6FF', 'border' => '#0EA5E9', 'icon' => 'ti ti-device-tv',          'label' => 'Monitor'],
        'NetworkEquipment' => ['color' => '#5BA85F', 'border' => '#16A34A', 'icon' => 'ti ti-network',            'label' => 'Network equipment'],
        'Printer'          => ['color' => '#94A3B8', 'border' => '#64748B', 'icon' => 'ti ti-printer',            'label' => 'Printer'],
        'Peripheral'       => ['color' => '#A78BFA', 'border' => '#8B5CF6', 'icon' => 'ti ti-device-usb',         'label' => 'Peripheral'],
        'Phone'            => ['color' => '#F472B6', 'border' => '#DB2777', 'icon' => 'ti ti-device-mobile',      'label' => 'Phone'],
        'Software'         => ['color' => '#7B5EA7', 'border' => '#6D28D9', 'icon' => 'ti ti-box',                'label' => 'Software'],
        'DatabaseInstance' => ['color' => '#C9A227', 'border' => '#B45309', 'icon' => 'ti ti-database',           'label' => 'Database'],
        'Database'         => ['color' => '#C9A227', 'border' => '#B45309', 'icon' => 'ti ti-database',           'label' => 'Database'],
        'Cluster'          => ['color' => '#DC2626', 'border' => '#991B1B', 'icon' => 'ti ti-stack-2',            'label' => 'Cluster'],
        'Domain'           => ['color' => '#14B8A6', 'border' => '#0F766E', 'icon' => 'ti ti-world',              'label' => 'Domain'],
        'Appliance'        => ['color' => '#6366F1', 'border' => '#4338CA', 'icon' => 'ti ti-server',             'label' => 'Appliance'],
        'PluginAppliancesAppliance' => ['color' => '#6366F1', 'border' => '#4338CA', 'icon' => 'ti ti-server',   'label' => 'Appliance'],
        'Rack'             => ['color' => '#0EA5E9', 'border' => '#0369A1', 'icon' => 'ti ti-server-2',           'label' => 'Rack'],
        'Enclosure'        => ['color' => '#3B82F6', 'border' => '#1D4ED8', 'icon' => 'ti ti-box',                'label' => 'Enclosure'],
        'PDU'              => ['color' => '#22C55E', 'border' => '#15803D', 'icon' => 'ti ti-plug',               'label' => 'PDU'],
    ];

    private const DEFAULT_STYLE = ['color' => '#9CA3AF', 'border' => '#6B7280', 'icon' => 'ti ti-package', 'label' => 'Item'];

    /**
     * Visual identity for an itemtype (color/border/icon/label).
     *
     * @return array{color:string,border:string,icon:string,label:string}
     */
    public static function styleFor(string $itemtype): array
    {
        return self::ITEMTYPE_STYLE[$itemtype] ?? self::DEFAULT_STYLE;
    }

    /**
     * Known itemtypes (for filter dropdowns). Stable, hand-curated list.
     *
     * @return array<string,string>  classname => display label
     */
    public static function knownItemtypes(): array
    {
        $out = [];
        foreach (self::ITEMTYPE_STYLE as $cls => $style) {
            $out[$cls] = $style['label'];
        }
        return $out;
    }

    /**
     * Build the impact graph from GLPI's native tables.
     *
     * @param array{
     *   itemtype?:string,items_id?:int,
     *   seeds?:list<array{itemtype:string,items_id:int}>,
     *   forward?:int,backward?:int,
     *   types?:string[]
     * } $scope
     *        Optional scoping. If itemtype+items_id given, returns ONLY the
     *        subgraph reachable from that node by a BOUNDED DIRECTED BFS:
     *        `forward` hops along arrows OUT (impacts), `backward` hops
     *        along arrows IN (impacted by). Both default to 2, capped at 10.
     *        If the start node has no relations, returns an empty graph.
     *        If types given, additionally filters nodes by itemtype.
     *
     * @return array{
     *   nodes: list<array{
     *     id:string, itemtype:string, items_id:int, name:string,
     *     color:string, border:string, icon:string, group:string,
     *     compoundId:?int, url:string, title:string
     *   }>,
     *   edges: list<array{from:string, to:string}>,
     *   compounds: list<array{id:int, name:string, color:string, count:int}>,
     *   meta: array{truncated:bool, total_nodes:int, total_edges:int}
     * }
     */
    public static function getGraph(array $scope = []): array
    {
        global $DB, $CFG_GLPI;

        $empty = [
            'nodes'     => [],
            'edges'     => [],
            'compounds' => [],
            'meta'      => ['truncated' => false, 'total_nodes' => 0, 'total_edges' => 0],
        ];

        if (!$DB->tableExists('glpi_impactrelations')) {
            return $empty;
        }

        // ── 1. Read all relations ────────────────────────────────────────────
        $rows = [];
        try {
            foreach ($DB->request(['FROM' => 'glpi_impactrelations']) as $r) {
                $rows[] = [
                    'fs' => (string) $r['itemtype_source'],
                    'fi' => (int)    $r['items_id_source'],
                    'ts' => (string) $r['itemtype_impacted'],
                    'ti' => (int)    $r['items_id_impacted'],
                ];
            }
        } catch (\Throwable $e) {
            return $empty;
        }

        // ── 1b. Optional overlay: observed dependency edges from the
        //        netstatconnections plugin (real TCP/UDP flow). Loaded now but
        //        folded in AFTER the BFS (step 3b) so a chatty host's traffic
        //        mesh can't drive unbounded BFS expansion. No-op when the
        //        plugin isn't installed.
        $obsRows = !empty($scope['include_observed']) ? self::netstatEdges() : [];

        // ── 2. Collect unique nodes ──────────────────────────────────────────
        $nodeSet = []; // 'Type:id' => ['itemtype'=>, 'items_id'=>]
        foreach ($rows as $r) {
            $kf = $r['fs'] . ':' . $r['fi'];
            $kt = $r['ts'] . ':' . $r['ti'];
            $nodeSet[$kf] = ['itemtype' => $r['fs'], 'items_id' => $r['fi']];
            $nodeSet[$kt] = ['itemtype' => $r['ts'], 'items_id' => $r['ti']];
        }

        // Also include isolated nodes that appear in glpi_impactitems but have
        // no relations — they're still part of the saved impact graph.
        if ($DB->tableExists('glpi_impactitems')) {
            try {
                foreach ($DB->request([
                    'SELECT' => ['itemtype', 'items_id'],
                    'FROM'   => 'glpi_impactitems',
                ]) as $r) {
                    $k = $r['itemtype'] . ':' . (int) $r['items_id'];
                    if (!isset($nodeSet[$k])) {
                        $nodeSet[$k] = [
                            'itemtype' => (string) $r['itemtype'],
                            'items_id' => (int) $r['items_id'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal — proceed without isolated nodes.
            }
        }

        // ── 3. Optional scope: BOUNDED DIRECTED BFS from one or more seeds ──
        // Seeds come either from a single (itemtype, items_id) pair or from
        // scope['seeds'] (e.g. all assets linked to a Ticket). Walks `forward`
        // hops along arrows OUT (impacts) and `backward` hops along arrows IN
        // (impacted by), independently, from EVERY seed. Defaults match GLPI's
        // native impact analysis depth (≈2 each). Seeds absent from the impact
        // data are injected as isolated nodes (a ticket asset without
        // relations should still show on the triage map), and a scope that
        // matches nothing returns an empty graph — never the global one.
        $seeds = [];
        if (!empty($scope['seeds']) && is_array($scope['seeds'])) {
            foreach ($scope['seeds'] as $s) {
                if (!empty($s['itemtype']) && !empty($s['items_id'])) {
                    $seeds[(string) $s['itemtype'] . ':' . (int) $s['items_id']] = [
                        'itemtype' => (string) $s['itemtype'],
                        'items_id' => (int) $s['items_id'],
                    ];
                }
            }
        } elseif (!empty($scope['itemtype']) && !empty($scope['items_id'])) {
            $key = $scope['itemtype'] . ':' . (int) $scope['items_id'];
            $seeds[$key] = [
                'itemtype' => (string) $scope['itemtype'],
                'items_id' => (int) $scope['items_id'],
            ];
        }

        if ($seeds !== []) {
            $forwardDepth  = isset($scope['forward'])  ? max(0, min(10, (int) $scope['forward']))  : 2;
            $backwardDepth = isset($scope['backward']) ? max(0, min(10, (int) $scope['backward'])) : 2;

            // Inject seeds missing from the impact data as isolated nodes.
            foreach ($seeds as $key => $s) {
                if (!isset($nodeSet[$key])) {
                    $nodeSet[$key] = $s;
                }
            }

            // Directed adjacency: forward edges = source → impacted.
            $forward = []; // a => [b, …]  (a impacts b)
            $reverse = []; // b => [a, …]  (a impacts b → b is impacted by a)
            foreach ($rows as $r) {
                $a = $r['fs'] . ':' . $r['fi'];
                $b = $r['ts'] . ':' . $r['ti'];
                $forward[$a][$b] = true;
                $reverse[$b][$a] = true;
            }

            $seedKeys = array_keys($seeds);
            $keep = array_fill_keys($seedKeys, true);

            // Forward BFS (seeds → what they impact).
            $layer = $seedKeys;
            for ($d = 0; $d < $forwardDepth && $layer; $d++) {
                $next = [];
                foreach ($layer as $cur) {
                    foreach (array_keys($forward[$cur] ?? []) as $nb) {
                        if (!isset($keep[$nb])) {
                            $keep[$nb] = true;
                            $next[] = $nb;
                        }
                    }
                }
                $layer = $next;
            }

            // Backward BFS (what impacts the seeds → upstream).
            $layer = $seedKeys;
            for ($d = 0; $d < $backwardDepth && $layer; $d++) {
                $next = [];
                foreach ($layer as $cur) {
                    foreach (array_keys($reverse[$cur] ?? []) as $nb) {
                        if (!isset($keep[$nb])) {
                            $keep[$nb] = true;
                            $next[] = $nb;
                        }
                    }
                }
                $layer = $next;
            }

            $nodeSet = array_intersect_key($nodeSet, $keep);
        }

        // ── 3b. Fold in observed (netstat) edges at depth 1 ──────────────────
        // Observed edges do NOT drive BFS expansion (that pulls a hub's whole
        // traffic mesh in). Keep an observed edge when one endpoint is already
        // in scope, add the other as a leaf, and cap the fan-out per anchor so
        // a chatty host can't explode the graph. Highest-weight edges win.
        $keptObs = [];
        if ($obsRows !== []) {
            $cand = [];
            foreach ($obsRows as $e) {
                $a   = $e['fs'] . ':' . $e['fi'];
                $b   = $e['ts'] . ':' . $e['ti'];
                $aIn = isset($nodeSet[$a]);
                $bIn = isset($nodeSet[$b]);
                if (!$aIn && !$bIn) {
                    continue; // not connected to the in-scope set
                }
                $e['_anchor'] = $aIn ? $a : $b;
                $cand[] = $e;
            }
            // Highest weight first so the per-anchor cap keeps the strongest.
            usort($cand, static fn(array $x, array $y): int
                => ($y['weight'] ?? 0) <=> ($x['weight'] ?? 0));
            $perAnchor = [];
            foreach ($cand as $e) {
                $anchor = $e['_anchor'];
                if (($perAnchor[$anchor] ?? 0) >= self::MAX_OBSERVED_PER_NODE) {
                    continue;
                }
                $perAnchor[$anchor] = ($perAnchor[$anchor] ?? 0) + 1;
                $a = $e['fs'] . ':' . $e['fi'];
                $b = $e['ts'] . ':' . $e['ti'];
                if (!isset($nodeSet[$a])) {
                    $nodeSet[$a] = ['itemtype' => $e['fs'], 'items_id' => $e['fi']];
                }
                if (!isset($nodeSet[$b])) {
                    $nodeSet[$b] = ['itemtype' => $e['ts'], 'items_id' => $e['ti']];
                }
                $keptObs[] = $e;
            }
        }

        // ── 4. Filter by itemtype set (if requested) ─────────────────────────
        if (!empty($scope['types']) && is_array($scope['types'])) {
            $allow = array_flip($scope['types']);
            $nodeSet = array_filter(
                $nodeSet,
                static fn(array $n): bool => isset($allow[$n['itemtype']])
            );
        }

        // ── 4b. Entity-access filtering (SEC-1) ──────────────────────────────
        // Drop any node the current session may not see. Applies to EVERY
        // scope (org-wide, asset, ITIL): the raw impact tables aren't entity
        // scoped by GLPI, and 2.0.0 lets non-super-admins reach this code.
        if ($nodeSet !== []) {
            $entByType = [];
            foreach ($nodeSet as $n) {
                $entByType[$n['itemtype']][] = $n['items_id'];
            }
            $allowedEnt = self::filterByEntity($entByType);
            $nodeSet = array_filter(
                $nodeSet,
                static fn(array $n): bool => isset($allowedEnt[$n['itemtype']][$n['items_id']])
            );
        }

        $totalNodes = count($nodeSet);
        $totalEdges = count($rows) + count($keptObs);

        // ── 5. Cap to MAX_NODES ──────────────────────────────────────────────
        $truncated = false;
        if (count($nodeSet) > self::MAX_NODES) {
            $nodeSet = array_slice($nodeSet, 0, self::MAX_NODES, true);
            $truncated = true;
        }

        // ── 6. Batch-resolve names per itemtype ──────────────────────────────
        $byType = [];
        foreach ($nodeSet as $key => $n) {
            $byType[$n['itemtype']][] = $n['items_id'];
        }
        $names = self::resolveNames($byType);

        // ── 6b. Health signals (batched — never per-node queries) ───────────
        $ticketCounts = self::openTicketCounts($byType); // null if source unavailable
        $agentDays    = self::agentStaleness($byType);   // itemtype => id => days

        // ── 7. Read compound memberships ─────────────────────────────────────
        $memberCompound = []; // 'Type:id' => parent_compound_id
        if ($DB->tableExists('glpi_impactitems')) {
            try {
                foreach ($DB->request([
                    'SELECT' => ['itemtype', 'items_id', 'parent_id'],
                    'FROM'   => 'glpi_impactitems',
                    'WHERE'  => ['parent_id' => ['>', 0]],
                ]) as $r) {
                    $k = $r['itemtype'] . ':' . (int) $r['items_id'];
                    if (isset($nodeSet[$k])) {
                        $memberCompound[$k] = (int) $r['parent_id'];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        // ── 8. Read compound metadata ────────────────────────────────────────
        $compoundIds = array_unique(array_values($memberCompound));
        $compounds   = [];
        if ($compoundIds && $DB->tableExists('glpi_impactcompounds')) {
            $countByCompound = array_count_values($memberCompound);
            try {
                foreach ($DB->request([
                    'FROM'  => 'glpi_impactcompounds',
                    'WHERE' => ['id' => $compoundIds],
                ]) as $r) {
                    $id = (int) $r['id'];
                    $compounds[] = [
                        'id'    => $id,
                        'name'  => (string) ($r['name'] ?? ('Group ' . $id)),
                        'color' => self::normalizeColor($r['color'] ?? '#6B7280'),
                        'count' => $countByCompound[$id] ?? 0,
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        // ── 9. Build node payload ────────────────────────────────────────────
        $root      = $CFG_GLPI['root_doc'] ?? '';
        $nodes     = [];
        $nodeLevel = []; // 'Type:id' => health level (for compound roll-up)
        foreach ($nodeSet as $key => $n) {
            $style    = self::styleFor($n['itemtype']);
            $name     = $names[$n['itemtype']][$n['items_id']] ?? ('#' . $n['items_id']);
            $compound = $memberCompound[$key] ?? null;
            $url      = $root . '/front/' . $n['itemtype'] . '.form.php?id=' . $n['items_id'];

            // Health: combine open-ticket and agent-staleness signals. A node
            // with no signal at all gets level=null (no overlay, no noise).
            $tickets = ($ticketCounts === null)
                ? null
                : (int) ($ticketCounts[$n['itemtype']][$n['items_id']] ?? 0);
            $aDays   = $agentDays[$n['itemtype']][$n['items_id']] ?? null;
            $level   = self::healthLevel($tickets, $aDays);
            $nodeLevel[$key] = $level;

            // Tooltip — vis-network renders this as plain text by default; the
            // client opts into HTML rendering. All values HTML-escaped here.
            $tipExtra = '';
            if ($tickets !== null && $tickets > 0) {
                $tipExtra .= '<div class="uxc-impact-tip-sub">'
                    . sprintf(_n('%d open ticket', '%d open tickets', $tickets, 'impact360'), $tickets)
                    . '</div>';
            }
            if ($aDays !== null && $aDays > 2) {
                $tipExtra .= '<div class="uxc-impact-tip-sub">'
                    . sprintf(__('Agent silent for %d days', 'impact360'), $aDays)
                    . '</div>';
            }
            $title = '<div class="uxc-impact-tip">'
                . '<strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>'
                . '<div class="uxc-impact-tip-sub">' . htmlspecialchars($style['label'], ENT_QUOTES, 'UTF-8') . '</div>'
                . $tipExtra
                . '</div>';

            $nodes[] = [
                'id'         => $key,
                'itemtype'   => $n['itemtype'],
                'items_id'   => $n['items_id'],
                'name'       => $name,
                'label'      => $name,
                'color'      => $style['color'],
                'border'     => $style['border'],
                'icon'       => $style['icon'],
                'group'      => $n['itemtype'],
                'compoundId' => $compound,
                'url'        => $url,
                'title'      => $title,
                'seed'       => isset($seeds[$key]),
                'health'     => [
                    'level'      => $level,
                    'tickets'    => $tickets,
                    'agent_days' => $aDays,
                ],
            ];
        }

        // ── 9b. Roll member health up into each compound (worst-of) ──────────
        // Lets the client colour group boxes by aggregated health, SquaredUp
        // style. Native impact compounds only; the Appliance-level roll-up is
        // separate (rollupHealth(), surfaced on the Appliance tab).
        if ($compounds !== []) {
            $rank  = ['ok' => 1, 'warn' => 2, 'crit' => 3];
            $worst = []; // compoundId => level
            foreach ($memberCompound as $mkey => $cid) {
                $lvl = $nodeLevel[$mkey] ?? null;
                if ($lvl === null) {
                    continue;
                }
                if (!isset($worst[$cid]) || $rank[$lvl] > $rank[$worst[$cid]]) {
                    $worst[$cid] = $lvl;
                }
            }
            foreach ($compounds as &$c) {
                $c['health'] = $worst[$c['id']] ?? null;
            }
            unset($c);
        }

        // ── 10. Build edge payload (only edges with BOTH endpoints kept) ─────
        // Deduplicate per directed pair and merge the native + observed feeds:
        //   - a pair present in glpi_impactrelations is "confirmed" (solid);
        //   - a pair seen ONLY in observed traffic is dashed (observed=true);
        //   - either way it inherits the netstat port label + weight if present.
        $edgeMap = [];
        foreach (array_merge($rows, $keptObs) as $r) {
            $a = $r['fs'] . ':' . $r['fi'];
            $b = $r['ts'] . ':' . $r['ti'];
            if (!isset($nodeSet[$a]) || !isset($nodeSet[$b])) {
                continue;
            }
            $k = $a . '|' . $b;
            if (!isset($edgeMap[$k])) {
                $edgeMap[$k] = [
                    'from' => $a, 'to' => $b,
                    'label' => '', 'weight' => 0.0,
                    'observed' => false, 'native' => false,
                ];
            }
            if (!empty($r['observed'])) {
                $edgeMap[$k]['observed'] = true;
                if (!empty($r['label']) && $edgeMap[$k]['label'] === '') {
                    $edgeMap[$k]['label'] = (string) $r['label'];
                }
                if (isset($r['weight'])) {
                    $edgeMap[$k]['weight'] = max($edgeMap[$k]['weight'], (float) $r['weight']);
                }
            } else {
                $edgeMap[$k]['native'] = true;
            }
        }
        $edges = [];
        foreach ($edgeMap as $e) {
            $edge = ['from' => $e['from'], 'to' => $e['to']];
            if ($e['label'] !== '') {
                $edge['label'] = $e['label'];
            }
            if ($e['weight'] > 0) {
                $edge['weight'] = $e['weight'];
            }
            // Dashed ONLY when observed but not confirmed in the native tables.
            if ($e['observed'] && !$e['native']) {
                $edge['observed'] = true;
            }
            $edges[] = $edge;
        }

        return [
            'nodes'     => $nodes,
            'edges'     => $edges,
            'compounds' => array_values($compounds),
            'meta'      => [
                'truncated'   => $truncated,
                'total_nodes' => $totalNodes,
                'total_edges' => $totalEdges,
                'max_nodes'   => self::MAX_NODES,
            ],
        ];
    }

    /**
     * Derive a health level from the two batched signals (open tickets, agent
     * staleness days). null = no signal at all (no overlay / not counted).
     * Shared by the per-node overlay and the Appliance roll-up so they agree.
     */
    private static function healthLevel(?int $tickets, ?int $agentDays): ?string
    {
        $signals = 0;
        $issues  = 0;
        if ($tickets !== null)   { $signals++; if ($tickets > 0)   { $issues++; } }
        if ($agentDays !== null) { $signals++; if ($agentDays > 2) { $issues++; } }
        if ($signals === 0) {
            return null;
        }
        return $issues === 0 ? 'ok' : ($issues >= 2 ? 'crit' : 'warn');
    }

    /**
     * Application health roll-up (SquaredUp-style). Aggregates the health of a
     * container's members into a single status. Phase 1 supports Appliance
     * (members from glpi_appliances_items); extend the resolver block for other
     * containers. Reuses the SAME batched signals as the per-node overlay
     * (open tickets + agent staleness) so the roll-up and the map agree, and
     * applies the same entity-access guard so it never discloses across entities.
     *
     * @return array{
     *   level: ?string, total: int,
     *   counts: array{ok:int,warn:int,crit:int,unknown:int},
     *   worst: list<array{itemtype:string,items_id:int,name:string,level:string,kind:string,tickets:?int,agent_days:?int}>
     * }
     */
    public static function rollupHealth(string $itemtype, int $items_id, bool $withDeps = true): array
    {
        global $DB;

        $empty = [
            'level'         => null,
            'total'         => 0,
            'counts'        => ['ok' => 0, 'warn' => 0, 'crit' => 0, 'unknown' => 0],
            'deps_total'    => 0,
            'deps_degraded' => 0,
            'worst'         => [],
        ];

        // Resolve members. Phase 1: Appliance.
        $members = []; // 'Type:id' => ['itemtype'=>, 'items_id'=>]
        if ($itemtype === 'Appliance'
            && $items_id > 0
            && $DB->tableExists('glpi_appliances_items')) {
            try {
                foreach ($DB->request([
                    'SELECT' => ['itemtype', 'items_id'],
                    'FROM'   => 'glpi_appliances_items',
                    'WHERE'  => ['appliances_id' => $items_id],
                ]) as $r) {
                    $mt = (string) $r['itemtype'];
                    $mi = (int) $r['items_id'];
                    if ($mt !== '' && $mi > 0) {
                        $members[$mt . ':' . $mi] = ['itemtype' => $mt, 'items_id' => $mi];
                    }
                }
            } catch (\Throwable $e) {
                return $empty;
            }
        }
        if ($members === []) {
            return $empty;
        }

        // Entity-scope the members (same guard as getGraph): never count or name
        // an item the session can't see.
        $byType = [];
        foreach ($members as $m) { $byType[$m['itemtype']][] = $m['items_id']; }
        $allowed = self::filterByEntity($byType);
        $members = array_filter(
            $members,
            static fn(array $m): bool => isset($allowed[$m['itemtype']][$m['items_id']])
        );
        if ($members === []) {
            return $empty;
        }

        // Re-batch signals + names over the allowed members.
        $byType = [];
        foreach ($members as $m) { $byType[$m['itemtype']][] = $m['items_id']; }
        $tickets = self::openTicketCounts($byType);
        $agent   = self::agentStaleness($byType);
        $names   = self::resolveNames($byType);

        $rank   = ['ok' => 1, 'warn' => 2, 'crit' => 3];
        $counts = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'unknown' => 0];
        $worst  = [];
        $top    = null;
        foreach ($members as $m) {
            $t = ($tickets === null)
                ? null
                : (int) ($tickets[$m['itemtype']][$m['items_id']] ?? 0);
            $a   = $agent[$m['itemtype']][$m['items_id']] ?? null;
            $lvl = self::healthLevel($t, $a);
            if ($lvl === null) {
                $counts['unknown']++;
                continue;
            }
            $counts[$lvl]++;
            if ($lvl === 'warn' || $lvl === 'crit') {
                $worst[] = [
                    'itemtype'   => $m['itemtype'],
                    'items_id'   => $m['items_id'],
                    'name'       => $names[$m['itemtype']][$m['items_id']]
                                    ?? ($m['itemtype'] . ' #' . $m['items_id']),
                    'level'      => $lvl,
                    'kind'       => 'member',
                    'tickets'    => $t,
                    'agent_days' => $a,
                ];
            }
            if ($top === null || $rank[$lvl] > $rank[$top]) {
                $top = $lvl;
            }
        }

        // ── Phase 3: dependency-aware — fold in the health of CIs the members
        //    DEPEND ON (native impact + observed netstat, 1 hop upstream). A
        //    green member can still put the app at risk if the DB it talks to is
        //    red — the thing SquaredUp structurally can't see (no discovery).
        $depsTotal    = 0;
        $depsDegraded = 0;
        if ($withDeps) {
            $deps = self::dependenciesOf($members);
            if ($deps !== []) {
                $byType = [];
                foreach ($deps as $d) { $byType[$d['itemtype']][] = $d['items_id']; }
                $allowed = self::filterByEntity($byType);
                $deps = array_filter(
                    $deps,
                    static fn(array $d): bool => isset($allowed[$d['itemtype']][$d['items_id']])
                );
            }
            if ($deps !== []) {
                $byType = [];
                foreach ($deps as $d) { $byType[$d['itemtype']][] = $d['items_id']; }
                $dTickets = self::openTicketCounts($byType);
                $dAgent   = self::agentStaleness($byType);
                $dNames   = self::resolveNames($byType);
                foreach ($deps as $d) {
                    $depsTotal++;
                    $t = ($dTickets === null)
                        ? null
                        : (int) ($dTickets[$d['itemtype']][$d['items_id']] ?? 0);
                    $a   = $dAgent[$d['itemtype']][$d['items_id']] ?? null;
                    $lvl = self::healthLevel($t, $a);
                    if ($lvl === null) {
                        continue;
                    }
                    if ($lvl === 'warn' || $lvl === 'crit') {
                        $depsDegraded++;
                        $worst[] = [
                            'itemtype'   => $d['itemtype'],
                            'items_id'   => $d['items_id'],
                            'name'       => $dNames[$d['itemtype']][$d['items_id']]
                                            ?? ($d['itemtype'] . ' #' . $d['items_id']),
                            'level'      => $lvl,
                            'kind'       => 'dependency',
                            'tickets'    => $t,
                            'agent_days' => $a,
                        ];
                    }
                    if ($top === null || $rank[$lvl] > $rank[$top]) {
                        $top = $lvl;
                    }
                }
            }
        }

        usort($worst, static fn(array $x, array $y): int
            => $rank[$y['level']] <=> $rank[$x['level']]);

        return [
            'level'         => $top,
            'total'         => count($members),
            'counts'        => $counts,
            'deps_total'    => $depsTotal,
            'deps_degraded' => $depsDegraded,
            'worst'         => array_slice($worst, 0, 6),
        ];
    }

    /**
     * Portfolio "wall of apps": every (entity-visible) Appliance with its rolled
     * up health, worst-first. Drives the Application Health board. Capped to keep
     * the per-appliance roll-up queries bounded; the board notes when capped.
     *
     * @return list<array{id:int,name:string,level:?string,total:int,degraded:int,deps_degraded:int}>
     */
    public static function portfolio(int $limit = 200): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_appliances')) {
            return [];
        }
        $crit = \getEntitiesRestrictCriteria('glpi_appliances', '', '', true);

        $out = [];
        try {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_appliances',
                'WHERE'  => array_merge(['is_deleted' => 0], $crit),
                'ORDER'  => ['name ASC'],
                'LIMIT'  => $limit,
            ]) as $r) {
                $id   = (int) $r['id'];
                $roll = self::rollupHealth('Appliance', $id);
                $out[] = [
                    'id'            => $id,
                    'name'          => (string) ($r['name'] !== '' ? $r['name'] : ('Appliance #' . $id)),
                    'level'         => $roll['level'],
                    'total'         => (int) $roll['total'],
                    'degraded'      => (int) ($roll['counts']['warn'] + $roll['counts']['crit']),
                    'deps_degraded' => (int) $roll['deps_degraded'],
                ];
            }
        } catch (\Throwable $e) {
            return $out;
        }

        // Worst-first (crit > warn > ok > no-data), then alphabetical.
        $rank = ['crit' => 3, 'warn' => 2, 'ok' => 1];
        usort($out, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['level']] ?? 0;
            $rb = $rank[$b['level']] ?? 0;
            return $ra !== $rb ? ($rb <=> $ra) : strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Direct (1-hop) upstream dependencies of a member set — the CIs the members
     * DEPEND ON, from both sources:
     *   - native impact: `source → impacted` means impacted depends on source,
     *     so for each member-as-impacted we collect the sources;
     *   - observed netstat: a member (Computer) row with impact_direction
     *     'depends' (outbound consumption) → the resolved remote is a dependency.
     * Members themselves are excluded (a member isn't its own dependency).
     * Read-only; netstat side is tableExists-guarded (no-op without the plugin).
     *
     * @param array<string,array{itemtype:string,items_id:int}> $members
     * @return array<string,array{itemtype:string,items_id:int}> 'Type:id' => CI
     */
    private static function dependenciesOf(array $members): array
    {
        global $DB;

        $deps = [];
        if ($members === []) {
            return $deps;
        }

        $isMember    = [];
        $computerIds = [];
        $byType      = [];
        foreach ($members as $m) {
            $isMember[$m['itemtype'] . ':' . $m['items_id']] = true;
            $byType[$m['itemtype']][] = $m['items_id'];
            if ($m['itemtype'] === 'Computer') {
                $computerIds[$m['items_id']] = true;
            }
        }

        // 1. Native impact relations (source impacts impacted → impacted depends).
        if ($DB->tableExists('glpi_impactrelations')) {
            foreach ($byType as $itype => $ids) {
                try {
                    foreach ($DB->request([
                        'SELECT' => ['itemtype_source', 'items_id_source'],
                        'FROM'   => 'glpi_impactrelations',
                        'WHERE'  => [
                            'itemtype_impacted' => $itype,
                            'items_id_impacted' => array_values(array_unique($ids)),
                        ],
                    ]) as $r) {
                        $st = (string) $r['itemtype_source'];
                        $si = (int) $r['items_id_source'];
                        $k  = $st . ':' . $si;
                        if ($st !== '' && $si > 0 && !isset($isMember[$k])) {
                            $deps[$k] = ['itemtype' => $st, 'items_id' => $si];
                        }
                    }
                } catch (\Throwable $e) {
                    // Non-fatal — skip this itemtype.
                }
            }
        }

        // 2. Observed netstat: member computer depends on a resolved remote.
        $table = 'glpi_plugin_netstatconnections_connections';
        if ($computerIds !== [] && $DB->tableExists($table)) {
            try {
                foreach ($DB->request([
                    'SELECT' => ['remote_itemtype', 'remote_items_id', 'impact_direction', 'conn_direction'],
                    'FROM'   => $table,
                    'WHERE'  => [
                        'computers_id'    => array_keys($computerIds),
                        'remote_items_id' => ['>', 0],
                        'OR' => ['connection_status' => 'active', 'is_locked' => 1],
                    ],
                ]) as $r) {
                    $dir = (string) ($r['impact_direction'] ?? '');
                    if ($dir === '') {
                        $dir = ((string) ($r['conn_direction'] ?? '') === 'inbound')
                            ? 'impacts' : 'depends';
                    }
                    if ($dir !== 'depends') {
                        continue; // only upstream dependencies
                    }
                    $rt = (string) ($r['remote_itemtype'] ?? '');
                    $ri = (int) ($r['remote_items_id'] ?? 0);
                    $k  = $rt . ':' . $ri;
                    if ($rt !== '' && $ri > 0 && !isset($isMember[$k])) {
                        $deps[$k] = ['itemtype' => $rt, 'items_id' => $ri];
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal — fall back to native-only dependencies.
            }
        }

        return $deps;
    }

    /**
     * Observed dependency edges from the netstatconnections plugin (optional
     * overlay). Returns entries in the SAME shape as native relation rows
     * (fs/fi/ts/ti) plus `label`, `weight` (0..1, normalised per host) and
     * `observed`=true, aggregated to ONE entry per directed node-pair.
     *
     * Direction → impact orientation (source → impacted), matching the plugin's
     * own semantics: 'impacts' = Computer → remote; 'depends' = remote → Computer.
     * Falls back to conn_direction (inbound ⇒ impacts, else depends) when
     * impact_direction is unset.
     *
     * Read-only and fully guarded by tableExists, so it's a no-op when the
     * netstatconnections plugin isn't installed. Entity scoping is NOT applied
     * here — getGraph runs every node through filterByEntity afterwards.
     *
     * @return list<array{fs:string,fi:int,ts:string,ti:int,label:string,weight:float,observed:bool}>
     */
    private static function netstatEdges(): array
    {
        global $DB;

        $table = 'glpi_plugin_netstatconnections_connections';
        if (!$DB->tableExists($table)) {
            return [];
        }

        // Port label map ("port|PROTO" => name), mirroring the plugin's own
        // getPortLabel: a defined port shows its name (e.g. "MSSQL"), else we
        // fall back to "PROTO PORT".
        $portMap    = [];
        $portsTable = 'glpi_plugin_netstatconnections_ports';
        if ($DB->tableExists($portsTable)) {
            try {
                foreach ($DB->request([
                    'SELECT' => ['port_number', 'protocol', 'name'],
                    'FROM'   => $portsTable,
                    'WHERE'  => ['is_deleted' => 0],
                ]) as $p) {
                    $portMap[(int) $p['port_number'] . '|' . strtoupper((string) $p['protocol'])]
                        = (string) $p['name'];
                }
            } catch (\Throwable $e) {
                // Non-fatal — fall back to PROTO PORT labels.
            }
        }

        $agg     = []; // "fs:fi|ts:ti" => [fs,fi,ts,ti,labels[],seen,cid]
        $hostMax = []; // computers_id => max seen_count (weight denominator)

        try {
            $iter = $DB->request([
                'SELECT' => [
                    'computers_id', 'remote_itemtype', 'remote_items_id',
                    'protocol', 'service_port', 'impact_direction',
                    'conn_direction', 'seen_count',
                ],
                'FROM'  => $table,
                'WHERE' => [
                    'remote_items_id' => ['>', 0],
                    'OR' => [
                        'connection_status' => 'active',
                        'is_locked'         => 1,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($iter as $r) {
            $cid   = (int) $r['computers_id'];
            $rtype = (string) ($r['remote_itemtype'] ?? '');
            $rid   = (int) ($r['remote_items_id'] ?? 0);
            if ($cid <= 0 || $rid <= 0 || $rtype === '') {
                continue;
            }
            // Skip self-loops (a computer pointing at itself).
            if ($rtype === 'Computer' && $rid === $cid) {
                continue;
            }

            $proto = strtoupper((string) ($r['protocol'] ?: 'TCP'));
            $svc   = (int) ($r['service_port'] ?? 0);

            // Orientation: source → impacted.
            $dir = (string) ($r['impact_direction'] ?? '');
            if ($dir === '') {
                $dir = ((string) ($r['conn_direction'] ?? '') === 'inbound')
                    ? 'impacts' : 'depends';
            }
            if ($dir === 'impacts') {
                $fs = 'Computer'; $fi = $cid; $ts = $rtype; $ti = $rid;
            } else {
                $fs = $rtype;     $fi = $rid; $ts = 'Computer'; $ti = $cid;
            }

            $label = $portMap[$svc . '|' . $proto]
                ?? ($svc > 0 ? ($proto . ' ' . $svc) : $proto);
            $seen  = max(1, (int) ($r['seen_count'] ?? 1));
            $hostMax[$cid] = max($hostMax[$cid] ?? 1, $seen);

            $key = $fs . ':' . $fi . '|' . $ts . ':' . $ti;
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'fs' => $fs, 'fi' => $fi, 'ts' => $ts, 'ti' => $ti,
                    'labels' => [], 'seen' => 0, 'cid' => $cid,
                ];
            }
            $agg[$key]['labels'][$label] = true;
            $agg[$key]['seen'] = max($agg[$key]['seen'], $seen);
        }

        $out = [];
        foreach ($agg as $a) {
            $denom  = max(1, $hostMax[$a['cid']] ?? 1);
            $labels = array_keys($a['labels']);
            sort($labels);
            if (count($labels) > 3) {
                $labels = array_slice($labels, 0, 3);
                $labels[] = '…';
            }
            $out[] = [
                'fs'       => $a['fs'],
                'fi'       => $a['fi'],
                'ts'       => $a['ts'],
                'ti'       => $a['ti'],
                'label'    => implode(', ', $labels),
                'weight'   => round($a['seen'] / $denom, 3),
                'observed' => true,
            ];
        }
        return $out;
    }

    /**
     * Resolve the SQL table for an itemtype. The class owns this knowledge;
     * falling back to getTableForItemType keeps us correct for plugin types.
     * Returns null when the class/table can't be resolved.
     */
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

    /**
     * Entity-access guard (SEC-1). GLPI does NOT auto-scope the raw impact
     * tables, so the BFS neighborhood / ITIL seed expansion could otherwise
     * surface item names and health from entities the current session can't
     * see. For every itemtype we keep only the ids that pass GLPI's own
     * entity-restriction criteria for the session's active entities.
     *
     * Itemtypes whose table has no `entities_id` column are entity-agnostic
     * and pass through unchanged. On query error we fail CLOSED (drop the
     * ids) — better to under-show than to disclose across entities.
     *
     * @param array<string,int[]> $byType itemtype => ids present in the graph
     * @return array<string,array<int,bool>> itemtype => id => true (allowed)
     */
    private static function filterByEntity(array $byType): array
    {
        global $DB;

        $allowed = [];
        foreach ($byType as $itemtype => $ids) {
            if (!is_string($itemtype) || $itemtype === '' || $ids === []) {
                continue;
            }
            $ids   = array_values(array_unique(array_map('intval', $ids)));
            $table = self::tableFor($itemtype);

            // No resolvable table, or no entity concept → not entity-restricted.
            if ($table === null || !$DB->fieldExists($table, 'entities_id')) {
                foreach ($ids as $id) {
                    $allowed[$itemtype][$id] = true;
                }
                continue;
            }

            $crit = \getEntitiesRestrictCriteria($table, '', '', true);
            try {
                foreach ($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $table,
                    'WHERE'  => array_merge(['id' => $ids], $crit),
                ]) as $row) {
                    $allowed[$itemtype][(int) $row['id']] = true;
                }
            } catch (\Throwable $e) {
                // Fail closed: prove access or don't disclose.
            }
        }
        return $allowed;
    }

    /**
     * Resolve display names in batches: one query per itemtype.
     *
     * @param array<string,int[]> $byType  itemtype => list of ids
     * @return array<string,array<int,string>>  itemtype => id => name
     */
    private static function resolveNames(array $byType): array
    {
        global $DB;

        $out = [];
        foreach ($byType as $itemtype => $ids) {
            if (!is_string($itemtype) || $itemtype === '' || $ids === []) {
                continue;
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));

            $table = self::tableFor((string) $itemtype);
            if ($table === null) {
                continue;
            }

            // Use 'name' when present (the convention for most GLPI assets);
            // otherwise fall back to the row id.
            $hasName = $DB->fieldExists($table, 'name');
            $hasDel  = $DB->fieldExists($table, 'is_deleted');

            $select = ['id'];
            if ($hasName) {
                $select[] = 'name';
            }
            $where = ['id' => $ids];
            if ($hasDel) {
                // Show soft-deleted assets too — they still appear in the
                // impact graph until explicitly removed.
                // No is_deleted filter.
            }
            try {
                foreach ($DB->request([
                    'SELECT' => $select,
                    'FROM'   => $table,
                    'WHERE'  => $where,
                ]) as $row) {
                    $id = (int) $row['id'];
                    $out[$itemtype][$id] = $hasName && !empty($row['name'])
                        ? (string) $row['name']
                        : ($itemtype . ' #' . $id);
                }
            } catch (\Throwable $e) {
                // Non-fatal.
            }
        }
        return $out;
    }

    /** ITIL itemtype => its asset-link table + foreign key. */
    public const ITIL_LINK_TABLES = [
        'Ticket'  => ['table' => 'glpi_items_tickets',  'fk' => 'tickets_id'],
        'Change'  => ['table' => 'glpi_changes_items',  'fk' => 'changes_id'],
        'Problem' => ['table' => 'glpi_items_problems', 'fk' => 'problems_id'],
    ];

    /**
     * Assets linked to an ITIL object (Ticket / Change / Problem), as BFS
     * seeds. Itemtypes are whitelisted against knownItemtypes() — the same
     * filter the ajax endpoint applies to direct scope params.
     *
     * @return list<array{itemtype:string,items_id:int}>
     */
    public static function linkedAssetSeeds(string $itilType, int $id): array
    {
        global $DB;

        $map = self::ITIL_LINK_TABLES[$itilType] ?? null;
        if ($map === null || $id <= 0 || !$DB->tableExists($map['table'])) {
            return [];
        }

        $known = self::knownItemtypes();
        $seeds = [];
        try {
            foreach ($DB->request([
                'SELECT' => ['itemtype', 'items_id'],
                'FROM'   => $map['table'],
                'WHERE'  => [$map['fk'] => $id],
            ]) as $row) {
                if (isset($known[$row['itemtype']])) {
                    $seeds[] = [
                        'itemtype' => (string) $row['itemtype'],
                        'items_id' => (int) $row['items_id'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $seeds;
    }

    /**
     * Open-ticket counts per node, in ONE query (status 1-4 = new / assigned /
     * planned / waiting — same definition as the Computer Dashboard health).
     * Over-fetches by id (no per-tuple WHERE) and lets the caller filter via
     * the (itemtype, id) lookup; that's cheap and keeps the SQL portable.
     *
     * @param array<string,int[]> $byType itemtype => ids present in the graph
     * @return array<string,array<int,int>>|null itemtype => id => count, or
     *                                           null when sources are missing
     */
    private static function openTicketCounts(array $byType): ?array
    {
        global $DB;

        if ($byType === []
            || !$DB->tableExists('glpi_items_tickets')
            || !$DB->tableExists('glpi_tickets')) {
            return null;
        }

        $types  = array_keys($byType);
        $allIds = [];
        foreach ($byType as $ids) {
            foreach ($ids as $id) { $allIds[$id] = true; }
        }

        $out = [];
        try {
            foreach ($DB->request([
                'SELECT'     => [
                    'glpi_items_tickets.itemtype',
                    'glpi_items_tickets.items_id',
                    'COUNT' => 'glpi_items_tickets.id AS cnt',
                ],
                'FROM'       => 'glpi_items_tickets',
                'INNER JOIN' => ['glpi_tickets' => ['ON' => ['glpi_items_tickets' => 'tickets_id', 'glpi_tickets' => 'id']]],
                'WHERE'      => [
                    'glpi_items_tickets.itemtype' => $types,
                    'glpi_items_tickets.items_id' => array_keys($allIds),
                    'glpi_tickets.status'         => [1, 2, 3, 4],
                    'glpi_tickets.is_deleted'     => 0,
                ],
                'GROUPBY'    => ['glpi_items_tickets.itemtype', 'glpi_items_tickets.items_id'],
            ]) as $row) {
                $out[(string) $row['itemtype']][(int) $row['items_id']] = (int) $row['cnt'];
            }
        } catch (\Throwable $e) {
            return null;
        }
        return $out;
    }

    /**
     * Days since each node's inventory agent last reported, in ONE query.
     * Nodes without an agent row simply don't appear (no signal ≠ unhealthy).
     *
     * @param array<string,int[]> $byType itemtype => ids present in the graph
     * @return array<string,array<int,int>> itemtype => id => days since contact
     */
    private static function agentStaleness(array $byType): array
    {
        global $DB;

        if ($byType === [] || !$DB->tableExists('glpi_agents')) {
            return [];
        }

        $types  = array_keys($byType);
        $allIds = [];
        foreach ($byType as $ids) {
            foreach ($ids as $id) { $allIds[$id] = true; }
        }

        $out = [];
        try {
            foreach ($DB->request([
                'SELECT' => ['itemtype', 'items_id', 'MAX' => 'last_contact AS last'],
                'FROM'   => 'glpi_agents',
                'WHERE'  => ['itemtype' => $types, 'items_id' => array_keys($allIds)],
                'GROUPBY'=> ['itemtype', 'items_id'],
            ]) as $row) {
                if (empty($row['last'])) {
                    continue;
                }
                $ts = strtotime((string) $row['last']);
                if ($ts === false) {
                    continue;
                }
                $out[(string) $row['itemtype']][(int) $row['items_id']]
                    = max(0, (int) floor((time() - $ts) / 86400));
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * GLPI stores compound colors as CSS rgba() strings sometimes; normalize
     * to a #rrggbb hex when possible, else pass through unchanged.
     */
    private static function normalizeColor(?string $color): string
    {
        $color = trim((string) $color);
        if ($color === '') {
            return '#6B7280';
        }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return $color;
        }
        if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $color, $m)) {
            return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return $color; // CSS named color or other — vis-network accepts it
    }
}
