<?php

/**
 * UX Customizer - Impact Map item tab
 *
 * Adds an additional "Impact Map" tab to the form pages of certain itemtypes
 * (Computer, Appliance — see ITEMTYPES). The tab renders the same vis-network
 * topology view as the Setup → UX Customizer config page, but scoped to the
 * subgraph connected to the current asset (BFS from this item over impact
 * relations).
 *
 * Coexists with GLPI's native "Impact Analysis" tab — this one adds the
 * enhanced UX (collapsible compounds, edge counts, filter pills, tree layout,
 * search dim) without touching the native page.
 *
 * Registered in setup.php via:
 *   Plugin::registerClass(ImpactMapTab::class, ['addtabon' => ['Computer', 'Appliance']]);
 *
 * NB: vis-network and impactmap.js are emitted INLINE in the tab response so
 * they load only when the tab is shown. GLPI 11's tab AJAX loader extracts and
 * executes embedded scripts (jQuery-style), which is how the existing
 * ComputerDashboard tab loads its stylesheet too.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use CommonGLPI;

class ImpactMapTab extends CommonGLPI
{
    /**
     * Asset itemtypes that receive this tab. Each must:
     *   1. Exist as a class in the running GLPI install.
     *   2. Be present in ImpactMap::knownItemtypes() so the ajax scope
     *      whitelist accepts it.
     * Adding more itemtypes here is the only step needed to expand coverage.
     */
    public const ITEMTYPES = ['Computer', 'Appliance'];

    /**
     * ITIL itemtypes that receive this tab (SysAid-style "business impact in
     * the ticket"). The map is seeded by ALL assets linked to the object —
     * a technician sees the blast radius without leaving the ticket.
     */
    public const ITIL_ITEMTYPES = ['Ticket', 'Change', 'Problem'];

    public static function getTypeName($nb = 0): string
    {
        return __('Impact Map', 'impact360');
    }

    /**
     * Tab label. Empty string skips the tab — used to hide it when the
     * item is brand-new (no id), the module is off, or the itemtype isn't
     * covered (the addtabon registration already filters by itemtype, but we
     * double-check defensively).
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!method_exists($item, 'isNewItem') || $item->isNewItem()) {
            return '';
        }
        $class = get_class($item);
        if (!in_array($class, self::ITEMTYPES, true)
            && !in_array($class, self::ITIL_ITEMTYPES, true)) {
            return '';
        }
        if (!Config::isModuleEnabled('impactmap')) {
            return '';
        }
        return self::createTabEntry(
            __('Impact Map', 'impact360'),
            0,
            $item->getType(),
            'ti ti-affiliate'
        );
    }

    /**
     * Render the tab body: the same DOM the config-page Impact Map tab uses,
     * plus the JS bootstrap. Scoped to the current asset via the dataUrl query
     * string (itemtype + items_id → BFS in ImpactMap::getGraph).
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        global $CFG_GLPI;

        if (!method_exists($item, 'isNewItem') || $item->isNewItem()) {
            return false;
        }
        $itemtype = get_class($item);
        $isItil   = in_array($itemtype, self::ITIL_ITEMTYPES, true);
        if (!$isItil && !in_array($itemtype, self::ITEMTYPES, true)) {
            return false;
        }
        if (!Config::isModuleEnabled('impactmap')) {
            return false;
        }

        $rootDoc = $CFG_GLPI['root_doc'] ?? '';
        $itemId  = (int) $item->getID();

        // Cache-busted asset URL builder (file mtime → fall back to plugin version).
        $asset = static function (string $rel) use ($rootDoc): string {
            $v = @filemtime(__DIR__ . '/../' . $rel) ?: PLUGIN_IMPACT360_VERSION;
            return $rootDoc . '/plugins/impact360/' . $rel . '?v=' . $v;
        };

        // The dataUrl carries only the scope identity; the JS appends
        // &forward=&backward= from the depth selects on each fetch.
        // ITIL scope: the SERVER resolves the linked assets (rights-checked).
        $dataUrl = $rootDoc . '/plugins/impact360/ajax/impactmap.php'
            . ($isItil
                ? '?itil_itemtype=' . urlencode($itemtype) . '&itil_items_id=' . $itemId
                : '?itemtype=' . urlencode($itemtype) . '&items_id=' . $itemId);

        // Default BFS depths (forward = arrows out / impacts; backward = arrows in / impacted by).
        $defaultForward  = 2;
        $defaultBackward = 2;

        // ── Stylesheet (idempotent: GLPI/browser dedupe by URL) ────────────
        echo '<link rel="stylesheet" type="text/css" href="' . $asset('public/css/impactmap.css') . '">';

        echo '<div class="container-fluid mt-2 uxc-impact-itemscope">';
        echo '<div class="d-flex align-items-center mb-2">';
        echo '<i class="ti ti-affiliate me-2" style="font-size:1.5rem"></i>';
        echo '<h3 class="m-0">' . __('Impact Map', 'impact360') . '</h3>';
        echo '<span class="text-muted ms-3 small">'
            . ($isItil
                ? __('Combined neighborhood of every asset linked to this object. Seed assets are emphasized.', 'impact360')
                : __('Subgraph connected to this asset. Native GLPI Impact Analysis is the source of truth.', 'impact360'))
            . '</span>';
        echo '</div>';

        // ── Application health roll-up (SquaredUp-style) — Appliance only ────
        // One aggregated status for the whole service, worst-of its members,
        // with links to the worst offenders. Server-rendered (no extra AJAX).
        if ($itemtype === 'Appliance') {
            $roll = ImpactMap::rollupHealth('Appliance', $itemId);
            if ($roll['level'] !== null) {
                $styles = [
                    'crit' => ['#d63939', __('Critical', 'impact360'), 'ti-alert-triangle-filled'],
                    'warn' => ['#f59f00', __('Warning', 'impact360'),  'ti-alert-triangle'],
                    'ok'   => ['#2fb344', __('Healthy', 'impact360'),   'ti-circle-check'],
                ];
                [$col, $lbl, $ic] = $styles[$roll['level']];
                $degraded = $roll['counts']['warn'] + $roll['counts']['crit'];
                echo '<div class="uxc-impact-rollup d-flex align-items-center flex-wrap mb-2 p-2"'
                    . ' style="border-left:4px solid ' . $col . ';background:rgba(0,0,0,.03);border-radius:4px">';
                echo '<i class="ti ' . $ic . ' me-2" style="color:' . $col . ';font-size:1.25rem"></i>';
                echo '<strong style="color:' . $col . '">' . $lbl . '</strong>';
                echo '<span class="text-muted ms-2 small" title="'
                    . htmlspecialchars(
                        __('Degraded = open ticket(s) on the CI and/or its agent silent for more than 2 days. Both at once = critical.', 'impact360'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) . '">'
                    . sprintf(__('%1$d of %2$d components degraded', 'impact360'), $degraded, (int) $roll['total'])
                    . '</span>';
                // Phase 3: dependency-aware — CIs the members depend on (real
                // traffic) that are themselves degraded put the app at risk.
                if (!empty($roll['deps_degraded'])) {
                    echo '<span class="text-muted ms-2 small">· '
                        . sprintf(
                            _n('%d dependency at risk', '%d dependencies at risk', (int) $roll['deps_degraded'], 'impact360'),
                            (int) $roll['deps_degraded']
                        )
                        . '</span>';
                }
                if (!empty($roll['worst'])) {
                    $links = [];
                    foreach ($roll['worst'] as $w) {
                        $u = $rootDoc . '/front/' . $w['itemtype'] . '.form.php?id=' . $w['items_id'];
                        // Say WHY the CI is degraded, mirroring healthLevel()'s
                        // signals — a bare hostname tells the operator nothing.
                        $why = [];
                        if (($w['kind'] ?? 'member') === 'dependency') {
                            $why[] = __('dependency', 'impact360');
                        }
                        $t = $w['tickets'] ?? null;
                        if ($t !== null && $t > 0) {
                            $why[] = sprintf(_n('%d open ticket', '%d open tickets', $t, 'impact360'), $t);
                        }
                        $a = $w['agent_days'] ?? null;
                        if ($a !== null && $a > 2) {
                            $why[] = sprintf(__('agent silent %d days', 'impact360'), (int) $a);
                        }
                        $tag = $why !== []
                            ? ' <span class="text-muted">('
                                . htmlspecialchars(implode(', ', $why), ENT_QUOTES, 'UTF-8')
                                . ')</span>'
                            : '';
                        $links[] = '<a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">'
                            . htmlspecialchars($w['name'], ENT_QUOTES, 'UTF-8') . '</a>' . $tag;
                    }
                    echo '<span class="text-muted ms-2 small">'
                        . __('Worst:', 'impact360') . ' ' . implode(', ', $links) . '</span>';
                }
                // Jump to the org-wide board (Phase 4) from any one service.
                echo '<a class="ms-auto small text-decoration-none" href="'
                    . $rootDoc . '/plugins/impact360/front/portfolio.php">'
                    . __('All applications', 'impact360') . ' →</a>';
                echo '</div>';
            }
        }

        // Wrapper div the client JS hooks into (the very same id used on the
        // config page — keeps the JS untouched).
        echo '<div class="uxc-impact-page" id="uxc-impact">';

        // Toolbar
        echo '<div class="uxc-impact-toolbar">';
        echo '<input type="text" class="form-control form-control-sm uxc-impact-search"'
            . ' placeholder="' . htmlspecialchars(__('Search node by name…', 'impact360'), ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary uxc-impact-collapse-all">'
            . '<i class="ti ti-arrows-minimize me-1"></i>' . __('Collapse groups', 'impact360') . '</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary uxc-impact-expand-all">'
            . '<i class="ti ti-arrows-maximize me-1"></i>' . __('Expand all', 'impact360') . '</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary uxc-impact-fit">'
            . '<i class="ti ti-focus-2 me-1"></i>' . __('Fit', 'impact360') . '</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary uxc-impact-minimap-toggle"'
            . ' title="' . htmlspecialchars(__('Toggle the overview mini-map', 'impact360'), ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="ti ti-map-2 me-1"></i>' . __('Mini-map', 'impact360') . '</button>';

        // Analysis modes (v2.1): what-if failure + path tracing.
        echo '<button type="button" class="btn btn-sm btn-outline-danger uxc-impact-whatif"'
            . ' title="' . htmlspecialchars(__('Click a CI to preview what fails if it goes down', 'impact360'), ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="ti ti-alert-triangle me-1"></i>' . __('What-if', 'impact360') . '</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-primary uxc-impact-path"'
            . ' title="' . htmlspecialchars(__('Click two CIs to trace the path between them', 'impact360'), ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="ti ti-route me-1"></i>' . __('Path', 'impact360') . '</button>';

        // Export group: PNG (raster), SVG (editable/Visio), PDF (print).
        echo '<div class="btn-group btn-group-sm" role="group">';
        echo '<button type="button" class="btn btn-outline-secondary uxc-impact-export"'
            . ' title="' . htmlspecialchars(__('Download as PNG image', 'impact360'), ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="ti ti-photo-down me-1"></i>PNG</button>';
        echo '<button type="button" class="btn btn-outline-secondary uxc-impact-export-svg"'
            . ' title="' . htmlspecialchars(__('Download as editable SVG (opens in Visio)', 'impact360'), ENT_QUOTES, 'UTF-8') . '">SVG</button>';
        echo '<button type="button" class="btn btn-outline-secondary uxc-impact-export-pdf"'
            . ' title="' . htmlspecialchars(__('Open a print view to save as PDF', 'impact360'), ENT_QUOTES, 'UTF-8') . '">PDF</button>';
        echo '</div>';
        // Layout mode. "Flow" = dagre LR, the same layered look as GLPI's
        // native Impact Analysis — default here so the tab feels familiar.
        // Explore mode — progressive expand-on-click. ON by default on the item
        // tab: start collapsed to this CI + its immediate neighbours, click to
        // reveal each next hop (kills sprawl on hub hosts). Inert with no seed
        // (org-wide config page), where it simply does nothing.
        echo '<label class="form-check form-switch d-inline-flex align-items-center mb-0 me-2"'
            . ' title="' . htmlspecialchars(__('Start collapsed to this CI; click a node to reveal its next hop', 'impact360'), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input class="form-check-input uxc-impact-explore" type="checkbox" checked>';
        echo '<span class="form-check-label small ms-1">' . __('Explore', 'impact360') . '</span>';
        echo '</label>';

        echo '<div class="d-inline-flex align-items-center ms-1">';
        echo '<label class="form-label small mb-0 me-1" for="uxc-impact-layoutsel">' . __('Layout', 'impact360') . '</label>';
        echo '<select id="uxc-impact-layoutsel" class="form-select form-select-sm uxc-impact-layoutsel" style="width:auto">';
        echo '<option value="flow" selected>' . __('Flow (left-right)', 'impact360') . '</option>';
        echo '<option value="force">' . __('Force (organic)', 'impact360') . '</option>';
        echo '<option value="tree">' . __('Tree (top-down)', 'impact360') . '</option>';
        echo '</select>';
        echo '</div>';

        // ── BFS depth selectors (on-asset tab only; the config-page Impact
        // Map keeps the org-wide view with no depth limit). 1..5 covers the
        // useful range; 2/2 matches GLPI native impact analysis density. ──
        $depthRange = [1, 2, 3, 4, 5];
        echo '<div class="uxc-impact-depth d-inline-flex align-items-center ms-2">';
        echo '<label class="form-label small mb-0 me-1" for="uxc-impact-forward" title="'
            . htmlspecialchars(__('Hops along arrows OUT (what this asset impacts)', 'impact360'), ENT_QUOTES, 'UTF-8')
            . '">' . __('Forward', 'impact360') . '</label>';
        echo '<select id="uxc-impact-forward" class="form-select form-select-sm uxc-impact-forward" style="width:auto">';
        foreach ($depthRange as $d) {
            $sel = ($d === $defaultForward) ? ' selected' : '';
            echo '<option value="' . $d . '"' . $sel . '>' . $d . '</option>';
        }
        echo '</select>';
        echo '<label class="form-label small mb-0 ms-2 me-1" for="uxc-impact-backward" title="'
            . htmlspecialchars(__('Hops along arrows IN (what impacts this asset)', 'impact360'), ENT_QUOTES, 'UTF-8')
            . '">' . __('Backward', 'impact360') . '</label>';
        echo '<select id="uxc-impact-backward" class="form-select form-select-sm uxc-impact-backward" style="width:auto">';
        foreach ($depthRange as $d) {
            $sel = ($d === $defaultBackward) ? ' selected' : '';
            echo '<option value="' . $d . '"' . $sel . '>' . $d . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Auto-group by type (iTop-style). Off by default here: depth-scoped
        // asset views are small; the org-wide config page defaults it on.
        echo '<label class="form-check form-switch d-inline-flex align-items-center mb-0 ms-2"'
            . ' title="' . htmlspecialchars(sprintf(__('Collapse loose nodes into one group per type when a type has more than %d nodes', 'impact360'), 8), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input class="form-check-input uxc-impact-autogroup" type="checkbox">';
        echo '<span class="form-check-label small ms-1">' . __('Auto-group types', 'impact360') . '</span>';
        echo '</label>';

        // Observed-traffic overlay (netstatconnections). On by default; toggling
        // re-fetches. Edges seen only in traffic render dashed; confirmed
        // (native impact) edges stay solid but inherit port labels + weight.
        echo '<label class="form-check form-switch d-inline-flex align-items-center mb-0 ms-2"'
            . ' title="' . htmlspecialchars(__('Overlay observed TCP/UDP dependencies from netstatconnections (dashed = observed only, not yet confirmed)', 'impact360'), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input class="form-check-input uxc-impact-observed" type="checkbox" checked>';
        echo '<span class="form-check-label small ms-1">' . __('Observed traffic', 'impact360') . '</span>';
        echo '</label>';

        // Port/service labels on observed edges. OFF by default — they collide
        // into noise on dense hosts; toggle on to read individual ports.
        echo '<label class="form-check form-switch d-inline-flex align-items-center mb-0 ms-2"'
            . ' title="' . htmlspecialchars(__('Show port/service names on observed edges', 'impact360'), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input class="form-check-input uxc-impact-edgelabels" type="checkbox">';
        echo '<span class="form-check-label small ms-1">' . __('Port labels', 'impact360') . '</span>';
        echo '</label>';

        echo '<span class="uxc-impact-status"></span>';
        echo '</div>';

        echo '<div class="uxc-impact-legend"></div>';

        // Stage (canvas + side panel)
        echo '<div class="uxc-impact-stage">';
        echo '<div class="uxc-impact-canvas"></div>';
        echo '<div class="uxc-impact-minimap" style="display:none"></div>';
        echo '<aside class="uxc-impact-side" aria-live="polite"></aside>';
        echo '<div class="uxc-impact-empty" style="display:none"><div>'
            . '<i class="ti ti-affiliate-off mb-2" style="font-size:2rem"></i><br>'
            . ($isItil
                ? __('No mappable assets are linked to this object (see its Items tab).', 'impact360')
                : __('No impact relations linked to this asset. Use the native Impact Analysis tab to start linking items.', 'impact360'))
            . '</div></div>';
        echo '</div>';

        echo '</div>'; // uxc-impact-page
        echo '</div>'; // container

        // ── JS bootstrap (inline) ─────────────────────────────────────────
        echo '<script>window.UxcImpactConfig = ' . json_encode([
            'dataUrl' => $dataUrl,
            'i18n'    => self::i18nKeys(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
        echo '<script src="' . $asset('public/js/vis-network.min.js') . '"></script>';
        // dagre powers the "Flow" (left-right layered) layout — the same
        // algorithm GLPI's native Impact Analysis uses. Bundled locally.
        echo '<script src="' . $asset('public/js/dagre.min.js') . '"></script>';
        echo '<script src="' . $asset('public/js/impactmap.js') . '"></script>';

        return true;
    }

    /**
     * Translation keys the JS client looks up. Kept centralised so the config
     * page and the item tab stay in sync — change once, propagate to both.
     *
     * @return array<string,string>
     */
    private static function i18nKeys(): array
    {
        return [
            'loading'      => __('Loading…', 'impact360'),
            'failed'       => __('Failed to load:', 'impact360'),
            'nodes'        => __('nodes', 'impact360'),
            'relations'    => __('relations', 'impact360'),
            'truncated'    => __('truncated to ', 'impact360'),
            'type'         => __('Type', 'impact360'),
            'id'           => __('ID', 'impact360'),
            'group'        => __('Group', 'impact360'),
            'members'      => __('Members', 'impact360'),
            'expand'       => __('Expand', 'impact360'),
            'open_in_glpi' => __('Open in GLPI', 'impact360'),
            'conn'         => __('conn.', 'impact360'),
            'layout_tree'  => __('Tree layout', 'impact360'),
            'layout_force' => __('Force layout', 'impact360'),
            'show_type'    => __('Click to show', 'impact360'),
            'hide_type'    => __('Click to hide', 'impact360'),
            'forward'      => __('Forward', 'impact360'),
            'backward'     => __('Backward', 'impact360'),
            'health'       => __('Health', 'impact360'),
            'health_ok'    => __('good', 'impact360'),
            'health_warn'  => __('warning', 'impact360'),
            'health_crit'  => __('critical', 'impact360'),
            'open_tickets' => __('Open tickets', 'impact360'),
            'agent_seen'   => __('Agent seen', 'impact360'),
            'today'        => __('today', 'impact360'),
            'days_ago'     => __('days ago', 'impact360'),
            'type_group'   => __('Type group', 'impact360'),
            'affected_if_fails' => __('affected if this fails', 'impact360'),
            'whatif_hint'  => __('Click a CI to see what fails with it', 'impact360'),
            'path_hint'    => __('Click two CIs to trace the path between them', 'impact360'),
            'path_from'    => __('Path from', 'impact360'),
            'path_pick2'   => __('pick a second node', 'impact360'),
            'path_len'     => __('Path length', 'impact360'),
            'no_path'      => __('No path between those two nodes', 'impact360'),
            'explore_hint' => __('click a node to expand', 'impact360'),
        ];
    }
}
