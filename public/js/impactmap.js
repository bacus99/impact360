/*
 * UX Customizer — Impact Map client
 *
 * Renders GLPI's native impact graph (read from glpi_impactrelations /
 * glpi_impactitems / glpi_impactcompounds) with vis-network.
 *
 * UX:
 *   - Compounds (GLPI's named groups) start COLLAPSED — each shows as a single
 *     coloured node with "(N)" member count. Double-click expands.
 *   - Single node click → side panel (name, itemtype, "Open in GLPI" link).
 *   - Cluster edges show merged "N conn." labels (Faddom-style).
 *   - Legend pills filter visibility by itemtype.
 *   - Toolbar toggles between force-directed and hierarchical (tree) layout.
 *   - Search dims non-matching nodes (instead of selecting them).
 *
 * License: GPL-3.0-or-later
 */
(function () {
    'use strict';

    const cfg = window.UxcImpactConfig;
    if (!cfg) {
        return;
    }
    const root = document.getElementById('uxc-impact');
    if (!root) {
        return;
    }
    if (typeof window.vis === 'undefined' || typeof window.vis.Network !== 'function') {
        root.innerHTML =
            '<div class="alert alert-danger m-3">' +
            'vis-network library failed to load. Check that public/js/vis-network.min.js is present.' +
            '</div>';
        return;
    }

    const i18n = cfg.i18n || {};
    const t = (k, fallback) => (i18n[k] || fallback || k);

    const canvas = root.querySelector('.uxc-impact-canvas');
    const sidePanel = root.querySelector('.uxc-impact-side');
    const statusEl = root.querySelector('.uxc-impact-status');
    const legendEl = root.querySelector('.uxc-impact-legend');
    const searchEl = root.querySelector('.uxc-impact-search');
    const btnExpandAll = root.querySelector('.uxc-impact-expand-all');
    const btnCollapseAll = root.querySelector('.uxc-impact-collapse-all');
    const btnFit = root.querySelector('.uxc-impact-fit');
    // Layout mode select: flow (dagre LR — native Impact Analysis look),
    // force (physics), tree (vis hierarchical top-down).
    const layoutSel = root.querySelector('.uxc-impact-layoutsel');
    // Depth selects (only present in the on-asset tab; null on the config page).
    const fwdSel = root.querySelector('.uxc-impact-forward');
    const bwdSel = root.querySelector('.uxc-impact-backward');
    // Auto-group-by-type checkbox + export buttons.
    const groupChk = root.querySelector('.uxc-impact-autogroup');
    // Observed-traffic overlay toggle (netstatconnections). Null on surfaces
    // that don't render it (e.g. the org-wide config page) — buildFetchUrl then
    // omits the param and the server default (ON) applies.
    const obsChk = root.querySelector('.uxc-impact-observed');
    // Port/service labels on observed edges (off by default — declutters).
    const labelChk = root.querySelector('.uxc-impact-edgelabels');
    // Explore mode: progressive expand-on-click (collapse to seed + depth-1).
    const exploreChk = root.querySelector('.uxc-impact-explore');
    const btnExport = root.querySelector('.uxc-impact-export');         // PNG
    const btnExportSvg = root.querySelector('.uxc-impact-export-svg');
    const btnExportPdf = root.querySelector('.uxc-impact-export-pdf');
    // Analysis modes + minimap (v2.1).
    const btnWhatif = root.querySelector('.uxc-impact-whatif');
    const btnPath = root.querySelector('.uxc-impact-path');
    const btnMinimap = root.querySelector('.uxc-impact-minimap-toggle');
    const miniEl = root.querySelector('.uxc-impact-minimap');

    let network = null;
    let nodesDS = null;
    let edgesDS = null;
    let allNodes = []; // raw payload (with compoundId)
    let allEdges = []; // raw payload
    let compounds = []; // [{id,name,color,count}]
    let clusterIdByCompound = {}; // compoundId -> cluster id
    let typeClusterIds = {}; // itemtype -> cluster id (auto-grouping)
    let clusterMeta = {}; // cluster id -> {name, color, count, kind: 'compound'|'type'}
    let typeStyles = {}; // itemtype -> {color, border, icon, label}
    let nodeById = {};   // base node id -> raw payload (for style restore)
    let mini = null;      // minimap vis.Network instance (v2.1)
    let lastData = null;            // last rendered payload (client re-render, no refetch)
    let exploreExpanded = new Set(); // explore mode: node ids whose next hop is revealed
    let exploreAdj = {};             // explore mode: undirected adjacency (id -> Set of ids)

    // iTop-style auto-grouping: when more than this many LOOSE nodes of the
    // same itemtype are on the canvas, collapse them into one type cluster.
    const TYPE_GROUP_THRESHOLD = 8;

    // ── Reactive UI state ────────────────────────────────────────────────────
    const state = {
        hiddenTypes: new Set(),  // itemtypes hidden via legend pills
        layoutMode: 'force',     // 'flow' (dagre LR) | 'force' | 'tree'
        search: '',              // current search query (lowercase)
        mode: 'normal',          // 'normal' | 'whatif' | 'path' interaction mode
        pathFirst: null,         // first picked base node id in path mode
        analysisOn: false,       // an overlay (whatif/path) is currently applied
        showEdgeLabels: false,   // port/service labels on observed edges (toggle)
        explore: false,          // progressive expand-on-click mode (seed + reveal)
    };

    function setStatus(msg, level) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className =
            'uxc-impact-status' + (level ? ' uxc-impact-status--' + level : '');
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Build the legend from styles actually present in the dataset (so we
     * don't show 12 itemtypes when only 3 are in the graph). Each item is
     * a clickable pill: click toggles visibility of all raw nodes of that
     * type (cluster nodes ignore the filter).
     */
    function buildLegend() {
        if (!legendEl) return;
        const present = new Set();
        allNodes.forEach(n => present.add(n.itemtype));
        const items = [];
        present.forEach(it => {
            const style = typeStyles[it] || { color: '#9CA3AF', label: it };
            const isOff = state.hiddenTypes.has(it);
            const cls = 'uxc-impact-legend-item' + (isOff ? ' uxc-impact-legend-item--off' : '');
            const title = isOff
                ? t('show_type', 'Click to show')
                : t('hide_type', 'Click to hide');
            items.push(
                '<button type="button" class="' + cls + '" data-type="' +
                    escapeHtml(it) + '" title="' + escapeHtml(title) + '">' +
                    '<span class="uxc-impact-swatch" style="background:' +
                    escapeHtml(style.color) + '"></span>' +
                    escapeHtml(style.label || it) +
                '</button>'
            );
        });
        legendEl.innerHTML = items.join('');
        // Wire click handlers.
        legendEl.querySelectorAll('.uxc-impact-legend-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const it = btn.getAttribute('data-type');
                if (!it) return;
                if (state.hiddenTypes.has(it)) {
                    state.hiddenTypes.delete(it);
                } else {
                    state.hiddenTypes.add(it);
                }
                applyTypeFilter();
                buildLegend(); // refresh pill states
            });
        });
    }

    // Health overlay colors (SysAid/ServiceNow-style status on the map).
    const HEALTH_BORDER = { crit: '#d63939', warn: '#f59f00' };

    function buildVisNodes() {
        return allNodes.map(n => {
            typeStyles[n.itemtype] = {
                color: n.color,
                border: n.border,
                icon: n.icon,
                label: (n.label_type || n.itemtype),
            };
            // Health overlay: warn/crit nodes get a thick status border. 'ok'
            // and unknown stay with the itemtype border (no noise).
            // Seed nodes (the scoped asset / the ticket's linked assets) are
            // emphasized: thicker dark border + larger label — health colors
            // still win over the seed border so problems stay visible.
            const level = n.health && n.health.level;
            const isSeed = !!n.seed;
            const border = HEALTH_BORDER[level] || (isSeed ? '#111827' : n.border);
            const borderWidth = (HEALTH_BORDER[level] || isSeed) ? 3 : 2;
            return {
                id: n.id,
                label: n.name,
                title: n.title, // HTML tooltip (network is created with html title support)
                shape: 'box',
                color: {
                    background: n.color,
                    border: border,
                    highlight: { background: n.color, border: HEALTH_BORDER[level] || '#111827' },
                    hover: { background: n.color, border: HEALTH_BORDER[level] || '#111827' },
                },
                borderWidth: borderWidth,
                font: { color: pickFontColor(n.color), size: isSeed ? 14 : 13, face: 'inherit' },
                shapeProperties: { borderRadius: 6 },
                margin: 8,
                widthConstraint: { minimum: 60, maximum: 180 },
                opacity: 1,
                // Custom payload for clustering / side panel:
                _itemtype: n.itemtype,
                _items_id: n.items_id,
                _name: n.name,
                _url: n.url,
                _compoundId: n.compoundId,
                _health: n.health || null,
                _seed: !!n.seed,
            };
        });
    }

    function buildVisEdges() {
        // NB: no per-edge `smooth` here — edge curvature is a global option
        // set per layout mode (smoothFor), so mode switches restyle all edges.
        //
        // Edges may carry (from the netstatconnections overlay):
        //   label    — port/service name(s), e.g. "MSSQL 1433"
        //   weight   — 0..1 observation frequency → line thickness
        //   observed — true when seen ONLY in traffic (not confirmed in the
        //              native impact tables) → rendered dashed + blue
        return allEdges.map((e, idx) => {
            const observed = !!e.observed;
            const width = (typeof e.weight === 'number' && e.weight > 0)
                ? Math.max(1, Math.min(6, 1 + e.weight * 5))
                : 1.5;
            const edge = {
                id: 'e' + idx,
                from: e.from,
                to: e.to,
                arrows: { to: { enabled: true, scaleFactor: 0.6 } },
                color: observed
                    ? { color: '#60a5fa', highlight: '#2563eb', hover: '#2563eb' }
                    : { color: '#94a3b8', highlight: '#1f2937', hover: '#1f2937' },
                width: width,
                dashes: observed ? [6, 4] : false,
            };
            // Labels are opt-in (toggle) and only on edges that carry one.
            // Always set both keys so a DataSet.update can CLEAR them when off.
            const showLabel = state.showEdgeLabels && !!e.label;
            edge.label = showLabel ? String(e.label) : undefined;
            // Opaque white "pill", horizontal text → readable floating over the
            // (possibly thick) edge instead of buried under/along the line.
            edge.font = showLabel ? {
                size: 11, color: '#1f2937', face: 'arial',
                background: '#ffffff',
                strokeWidth: 0, align: 'horizontal', vadjust: 0,
                multi: false,
            } : undefined;
            return edge;
        });
    }

    /** Edge curvature per layout mode (horizontal flow / vertical tree / organic). */
    function smoothFor(mode) {
        if (mode === 'flow') {
            return { enabled: true, type: 'cubicBezier', forceDirection: 'horizontal', roundness: 0.45 };
        }
        if (mode === 'tree') {
            return { enabled: true, type: 'cubicBezier', forceDirection: 'vertical', roundness: 0.45 };
        }
        return { enabled: true, type: 'dynamic' };
    }

    /**
     * Compute a dagre left-to-right layered layout ("Flow") — the same
     * algorithm GLPI's native Impact Analysis uses (cytoscape-dagre, rankdir
     * LR) — and return {nodeId: {x,y}}. Cycles are handled by dagre's greedy
     * acyclicer. Width is estimated from the label since vis box nodes size
     * to their text.
     */
    function computeDagrePositions(nodes, edges) {
        const g = new dagre.graphlib.Graph();
        g.setGraph({
            rankdir: 'LR',
            nodesep: 30,
            ranksep: 110,
            marginx: 20,
            marginy: 20,
            acyclicer: 'greedy',
            ranker: 'network-simplex',
        });
        g.setDefaultEdgeLabel(() => ({}));
        nodes.forEach(n => {
            const label = String(n._name || n.label || '');
            const w = Math.max(70, Math.min(190, label.length * 7.5 + 30));
            g.setNode(n.id, { width: w, height: 38 });
        });
        edges.forEach(e => {
            if (g.hasNode(e.from) && g.hasNode(e.to)) {
                g.setEdge(e.from, e.to);
            }
        });
        dagre.layout(g);
        const out = {};
        g.nodes().forEach(id => {
            const p = g.node(id);
            if (p) {
                out[id] = { x: p.x, y: p.y };
            }
        });
        return out;
    }

    /**
     * Pick black/white text for a node fill so labels stay readable.
     * Standard YIQ luma threshold (no extra dependencies).
     */
    function pickFontColor(hex) {
        if (!hex || hex[0] !== '#' || (hex.length !== 7 && hex.length !== 4)) {
            return '#111827';
        }
        let h = hex.substring(1);
        if (h.length === 3) {
            h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        }
        const r = parseInt(h.substring(0, 2), 16);
        const g = parseInt(h.substring(2, 4), 16);
        const b = parseInt(h.substring(4, 6), 16);
        const yiq = (r * 299 + g * 587 + b * 114) / 1000;
        return yiq >= 150 ? '#111827' : '#ffffff';
    }

    /**
     * Build the fetch URL by appending live depth-select values (if the
     * selects are present on the page — the on-asset tab has them; the
     * org-wide config page does not, in which case the bare cfg.dataUrl wins).
     */
    function buildFetchUrl() {
        let url = cfg.dataUrl;
        const parts = [];
        if (fwdSel) parts.push('forward=' + encodeURIComponent(fwdSel.value));
        if (bwdSel) parts.push('backward=' + encodeURIComponent(bwdSel.value));
        if (obsChk) parts.push('include_observed=' + (obsChk.checked ? '1' : '0'));
        if (parts.length) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
        }
        return url;
    }

    // ── Position persistence (ServiceNow-style stable layout) ───────────────
    // Saved per scope (full fetch URL = asset + depths) in localStorage. When
    // a later load finds positions for EVERY node in the graph, they're applied
    // as preset coordinates and physics is skipped entirely — instant, stable
    // render. Any topology change (new/removed node) falls back to a seeded
    // physics run, which is itself deterministic (layout.randomSeed below).

    // v2 key: the observed-overlay layout changed (depth-1 fold + fan-out cap),
    // so old saved coordinates from the pre-cap sprawled render are discarded.
    const POS_STORE = 'uxcImpactPositions2';
    const POS_MAX_SCOPES = 20; // prune oldest beyond this many saved scopes

    function scopeKey() {
        return buildFetchUrl();
    }

    function loadSavedPositions() {
        try {
            const all = JSON.parse(localStorage.getItem(POS_STORE) || '{}');
            const entry = all[scopeKey()];
            return (entry && entry.positions) ? entry.positions : null;
        } catch (e) {
            return null;
        }
    }

    function savePositions() {
        if (!network || !nodesDS) return;
        let pos = {};
        try {
            pos = network.getPositions(nodesDS.getIds());
        } catch (e) {
            return; // some ids mid-cluster transition — skip this save
        }
        try {
            const all = JSON.parse(localStorage.getItem(POS_STORE) || '{}');
            all[scopeKey()] = { savedAt: Date.now(), positions: pos };
            const keys = Object.keys(all);
            if (keys.length > POS_MAX_SCOPES) {
                keys.sort((a, b) => (all[a].savedAt || 0) - (all[b].savedAt || 0));
                keys.slice(0, keys.length - POS_MAX_SCOPES).forEach(k => delete all[k]);
            }
            localStorage.setItem(POS_STORE, JSON.stringify(all));
        } catch (e) {
            // localStorage full or disabled — persistence is best-effort
        }
    }

    function init() {
        fetchAndRender();
        // Depth selects trigger a full reload (destroy network → re-fetch).
        if (fwdSel) fwdSel.addEventListener('change', reload);
        if (bwdSel) bwdSel.addEventListener('change', reload);
        // Observed-traffic toggle re-fetches (changes the server-side query).
        if (obsChk) obsChk.addEventListener('change', reload);
        // Explore toggle re-renders (full teardown via reload — keeps state clean).
        if (exploreChk) exploreChk.addEventListener('change', reload);
        // Port-label toggle only restyles edges — no refetch needed.
        if (labelChk) labelChk.addEventListener('change', () => {
            state.showEdgeLabels = labelChk.checked;
            if (edgesDS) {
                try { edgesDS.update(buildVisEdges()); } catch (e) { /* ignore */ }
            }
        });
    }

    function fetchAndRender() {
        setStatus(t('loading', 'Loading…'), 'info');
        fetch(buildFetchUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(payload => {
                if (!payload || !payload.ok || !payload.data) {
                    throw new Error((payload && payload.error) || 'bad response');
                }
                renderGraph(payload.data);
            })
            .catch(err => {
                setStatus(t('failed', 'Failed to load:') + ' ' + err.message, 'error');
            });
    }

    /**
     * Tear down the current network instance and re-fetch from scratch. Used
     * when depth selects change (or any future control that affects the
     * server-side query).
     */
    function reload() {
        destroyMinimap();
        if (network) {
            try { network.destroy(); } catch (e) { /* ignore */ }
            network = null;
        }
        nodesDS = null;
        edgesDS = null;
        clusterIdByCompound = {};
        typeClusterIds = {};
        clusterMeta = {};
        state.hiddenTypes = new Set();
        state.search = '';
        state.mode = 'normal';
        state.pathFirst = null;
        state.analysisOn = false;
        if (btnWhatif) btnWhatif.classList.remove('active');
        if (btnPath) btnPath.classList.remove('active');
        if (canvas) canvas.classList.remove('uxc-impact-analyzing');
        if (searchEl) searchEl.value = '';
        if (sidePanel) sidePanel.classList.remove('uxc-impact-side--open');
        const emptyEl = root.querySelector('.uxc-impact-empty');
        if (emptyEl) emptyEl.style.display = 'none';
        fetchAndRender();
    }

    function renderGraph(data) {
        lastData = data;
        allNodes = data.nodes || [];
        allEdges = data.edges || [];
        compounds = data.compounds || [];
        const meta = data.meta || {};
        nodeById = {};
        allNodes.forEach(n => { nodeById[n.id] = n; });

        if (allNodes.length === 0) {
            root.querySelector('.uxc-impact-empty').style.display = '';
            setStatus('', '');
            return;
        }

        // Explore (progressive expand-on-click) needs a seed to expand FROM, so
        // it's inert on the org-wide config page (no seed). Start collapsed to
        // the seed(s); clicks reveal each next hop.
        const hasSeed = allNodes.some(n => n.seed);
        state.explore = !!(exploreChk && exploreChk.checked && hasSeed);
        if (state.explore) {
            exploreExpanded = new Set(allNodes.filter(n => n.seed).map(n => n.id));
        }

        const visNodes = buildVisNodes();
        // Explore always uses static dagre (we re-layout the visible subset on
        // each expand); ignore the layout select while exploring.
        const mode = state.explore ? 'flow' : (layoutSel ? layoutSel.value : 'force');
        state.layoutMode = mode;
        state.showEdgeLabels = labelChk ? labelChk.checked : false;

        // Layout decision, in priority order:
        //   1. Saved positions covering every node (user's arrangement) — but
        //      not in tree mode, where vis's hierarchical engine owns x/y.
        //   2. Flow mode → dagre LR computed positions (native-Impact look).
        //   3. Tree mode → vis hierarchical (deterministic, no physics).
        //   4. Force → seeded physics run (deterministic per seed).
        // Skip saved-position preset while exploring — positions are ephemeral
        // (recomputed per expand), persisting them would fight the reveal.
        const saved = state.explore ? null : loadSavedPositions();
        const preset = !state.explore && mode !== 'tree' && !!saved && visNodes.every(
            n => saved[n.id] && typeof saved[n.id].x === 'number' && typeof saved[n.id].y === 'number'
        );
        let staticLayout = preset;
        if (preset) {
            visNodes.forEach(n => {
                n.x = saved[n.id].x;
                n.y = saved[n.id].y;
            });
        } else if (mode === 'flow' && typeof window.dagre !== 'undefined') {
            const pos = computeDagrePositions(visNodes, allEdges);
            visNodes.forEach(n => {
                if (pos[n.id]) {
                    n.x = pos[n.id].x;
                    n.y = pos[n.id].y;
                }
            });
            staticLayout = true;
        } else if (mode === 'tree') {
            staticLayout = true; // hierarchical engine positions synchronously
        }

        nodesDS = new vis.DataSet(visNodes);
        edgesDS = new vis.DataSet(buildVisEdges());

        const options = {
            interaction: {
                hover: true,
                tooltipDelay: 150,
                multiselect: false,
                navigationButtons: false,
                keyboard: true,
            },
            nodes: {
                borderWidth: 2,
                shadow: { enabled: true, size: 4, x: 0, y: 1, color: 'rgba(0,0,0,0.08)' },
            },
            edges: {
                hoverWidth: 0.5,
                selectionWidth: 1,
                smooth: smoothFor(mode),
            },
            physics: staticLayout
                ? { enabled: false }
                : {
                    enabled: true,
                    solver: 'forceAtlas2Based',
                    forceAtlas2Based: {
                        gravitationalConstant: -45,
                        centralGravity: 0.012,
                        springLength: 130,
                        springConstant: 0.08,
                        damping: 0.6,
                        avoidOverlap: 0.6,
                    },
                    stabilization: { iterations: 120, fit: true },
                },
            layout: {
                // Fixed seed → the same graph lays out identically on every
                // load (no more drift between visits). Static layouts (preset,
                // dagre, hierarchical) ignore the seed entirely. improvedLayout
                // (Kamada-Kawai pre-pass) is costly — only under ~150 nodes.
                randomSeed: 116,
                improvedLayout: !staticLayout && visNodes.length <= 150,
                hierarchical: mode === 'tree' ? hierarchicalOpts() : false,
            },
        };

        network = new vis.Network(canvas, { nodes: nodesDS, edges: edgesDS }, options);

        // Re-enable HTML tooltips by patching the rendered title to be a DOM node.
        upgradeHtmlTooltips();

        if (staticLayout) {
            // No physics pass — finalize immediately.
            finalizeRender(meta, false);
        } else {
            network.once('stabilizationIterationsDone', () => finalizeRender(meta, true));
        }

        bindEvents();
    }

    /** vis hierarchical options for the Tree (top-down) mode. */
    function hierarchicalOpts() {
        return {
            direction: 'UD',
            sortMethod: 'directed',
            levelSeparation: 140,
            nodeSpacing: 140,
            treeSpacing: 200,
            blockShifting: true,
            edgeMinimization: true,
            parentCentralization: true,
        };
    }

    /**
     * Post-layout finishing common to both render paths. `fromPhysics` is true
     * when a stabilization run just ended (positions are fresh → persist them
     * BEFORE clustering hides member nodes); false on a preset render.
     */
    function finalizeRender(meta, fromPhysics) {
        if (state.explore) {
            // Progressive disclosure owns visibility + layout; no clustering,
            // no position persistence (positions are ephemeral per expand).
            applyExplore();
            buildLegend();
            statusEl.innerHTML = (meta.total_nodes || allNodes.length) + ' ' + t('nodes', 'nodes')
                + ' · <span class="text-muted">' + t('explore_hint', 'click a node to expand') + '</span>';
            return;
        }
        if (fromPhysics) {
            network.setOptions({ physics: { enabled: false } });
            savePositions();
        } else if (state.layoutMode !== 'tree') {
            // Static render (preset or dagre flow): persist so the next load
            // short-circuits to these exact coordinates. Tree positions are
            // owned by vis's hierarchical engine — never save those, or they
            // would leak into flow/force renders as a stale "arrangement".
            savePositions();
        }
        reclusterAll();
        buildLegend();
        if (!fromPhysics) {
            robustFit();
        }
        const noticeParts = [];
        noticeParts.push(
            (meta.total_nodes || allNodes.length) + ' ' + t('nodes', 'nodes')
        );
        noticeParts.push(
            (meta.total_edges || allEdges.length) + ' ' + t('relations', 'relations')
        );
        if (meta.truncated) {
            noticeParts.push(
                '<span class="text-warning">' +
                t('truncated', 'truncated to ') + (meta.max_nodes || allNodes.length) +
                '</span>'
            );
        }
        statusEl.innerHTML = noticeParts.join(' · ');
    }

    function upgradeHtmlTooltips() {
        nodesDS.forEach(n => {
            if (typeof n.title === 'string' && n.title.indexOf('<') !== -1) {
                const wrap = document.createElement('div');
                wrap.className = 'uxc-impact-tt';
                wrap.innerHTML = n.title;
                nodesDS.update({ id: n.id, title: wrap });
            }
        });
    }

    // ── Explore mode (progressive expand-on-click) ──────────────────────────
    // Collapse to the seed(s) + their direct neighbours; clicking a node
    // reveals ITS next hop. Walk the graph instead of dumping it — the fix for
    // sprawl on hub hosts, and the SquaredUp "click develops a node" feel.
    // Visibility is client-side over the already-fetched payload; clustering is
    // disabled in this mode (the two paradigms don't mix).

    function buildExploreAdj() {
        exploreAdj = {};
        allEdges.forEach(e => {
            (exploreAdj[e.from] = exploreAdj[e.from] || new Set()).add(e.to);
            (exploreAdj[e.to]   = exploreAdj[e.to]   || new Set()).add(e.from);
        });
    }

    /** Visible = expanded nodes ∪ their direct neighbours. */
    function exploreVisibleSet() {
        const vis = new Set();
        exploreExpanded.forEach(id => {
            vis.add(id);
            (exploreAdj[id] || new Set()).forEach(nb => vis.add(nb));
        });
        return vis;
    }

    /** Recompute visibility, expandable "+N" cues, and re-layout the visible. */
    function applyExplore() {
        if (!network || !nodesDS) return;
        buildExploreAdj();
        const vis = exploreVisibleSet();

        const nodeUpd = [];
        nodesDS.forEach(n => {
            const visible = vis.has(n.id);
            let label = n._name || n.label;
            if (visible && !exploreExpanded.has(n.id)) {
                let hidden = 0;
                (exploreAdj[n.id] || new Set()).forEach(nb => { if (!vis.has(nb)) hidden++; });
                if (hidden > 0) { label = (n._name || n.label) + '  +' + hidden; }
            }
            nodeUpd.push({ id: n.id, hidden: !visible, label: label });
        });
        nodesDS.update(nodeUpd);

        const edgeUpd = [];
        edgesDS.forEach(e => {
            edgeUpd.push({ id: e.id, hidden: !(vis.has(e.from) && vis.has(e.to)) });
        });
        edgesDS.update(edgeUpd);

        exploreRelayout(vis);
    }

    /** Dagre-layout just the visible subset, then fit. */
    function exploreRelayout(vis) {
        if (typeof window.dagre !== 'undefined') {
            const vNodes = nodesDS.get().filter(n => vis.has(n.id));
            const vEdges = allEdges.filter(e => vis.has(e.from) && vis.has(e.to));
            const pos = computeDagrePositions(vNodes, vEdges);
            const upd = [];
            vNodes.forEach(n => {
                if (pos[n.id]) { upd.push({ id: n.id, x: pos[n.id].x, y: pos[n.id].y }); }
            });
            if (upd.length) nodesDS.update(upd);
        }
        robustFit();
    }

    /** Reveal a node's next hop. Returns true if anything changed. */
    function exploreExpand(id) {
        if (!state.explore || !id || network.isCluster(id)) return false;
        if (exploreExpanded.has(id) || !nodeById[id]) return false;
        exploreExpanded.add(id);
        applyExplore();
        return true;
    }

    function exploreRevealAll() {
        if (!state.explore) return;
        nodesDS.forEach(n => exploreExpanded.add(n.id));
        applyExplore();
    }

    function exploreReset() {
        if (!state.explore) return;
        exploreExpanded = new Set(allNodes.filter(n => n.seed).map(n => n.id));
        applyExplore();
    }

    // ── Clustering ──────────────────────────────────────────────────────────

    function reclusterAll() {
        clusterIdByCompound = {};
        compounds.forEach(c => clusterCompound(c.id));
        if (groupChk && groupChk.checked) {
            clusterLooseTypes();
        }
        // After all clusters are formed, label merged cluster edges with their
        // base-edge count ("3 conn." Faddom-style).
        labelAllClusterEdges();
    }

    /**
     * iTop-style auto-grouping: collapse loose nodes (not inside any cluster)
     * into one cluster per itemtype when the type exceeds the threshold.
     * Dashed border distinguishes auto type groups from named compounds.
     */
    function clusterLooseTypes() {
        if (!network) return;
        typeClusterIds = {};
        // Count loose nodes per type (findNode path length 1 = top level).
        const looseByType = {};
        nodesDS.getIds().forEach(id => {
            let path = [];
            try { path = network.findNode(id); } catch (e) { return; }
            if (path.length === 1) {
                const n = nodesDS.get(id);
                // Never fold the focused CI into an anonymous type group —
                // selecting a node and watching it vanish into "Computer (76)"
                // is exactly the bug this avoids.
                if (n && !n.hidden && !n._seed) {
                    (looseByType[n._itemtype] = looseByType[n._itemtype] || []).push(id);
                }
            }
        });
        Object.entries(looseByType).forEach(([itemtype, ids]) => {
            if (ids.length <= TYPE_GROUP_THRESHOLD) return;
            const style = typeStyles[itemtype] || { color: '#9CA3AF', label: itemtype };
            const clusterId = 'type:' + itemtype;
            const idSet = new Set(ids);
            network.cluster({
                joinCondition: nodeOpts => idSet.has(nodeOpts.id),
                processProperties: (clusterOptions, childNodes) => {
                    clusterOptions.label = (style.label || itemtype) + ' (' + childNodes.length + ')';
                    clusterMeta[clusterId] = {
                        name: style.label || itemtype,
                        color: style.color,
                        count: childNodes.length,
                        kind: 'type',
                    };
                    return clusterOptions;
                },
                clusterNodeProperties: {
                    id: clusterId,
                    shape: 'box',
                    margin: 12,
                    borderWidth: 2,
                    color: {
                        background: style.color,
                        border: '#111827',
                        highlight: { background: style.color, border: '#111827' },
                    },
                    font: { color: pickFontColor(style.color), size: 14, face: 'inherit' },
                    shapeProperties: { borderRadius: 8, borderDashes: [6, 4] },
                },
            });
            typeClusterIds[itemtype] = clusterId;
        });
    }

    function clusterCompound(compoundId) {
        if (!network) return;
        const meta = compounds.find(c => c.id === compoundId);
        if (!meta) return;
        const clusterId = 'cluster:' + compoundId;
        const opts = {
            joinCondition: nodeOpts => nodeOpts._compoundId === compoundId && !nodeOpts._seed,
            processProperties: (clusterOptions, childNodes) => {
                clusterOptions.label = meta.name + ' (' + childNodes.length + ')';
                clusterMeta[clusterId] = {
                    name: meta.name,
                    color: meta.color || '#6B7280',
                    count: childNodes.length,
                    kind: 'compound',
                };
                return clusterOptions;
            },
            clusterNodeProperties: {
                id: clusterId,
                shape: 'box',
                margin: 12,
                // Roll-up health (worst-of members) colours the box border,
                // SquaredUp-style; falls back to the neutral dark border.
                borderWidth: HEALTH_BORDER[meta.health] ? 3 : 2,
                color: {
                    background: meta.color || '#6B7280',
                    border: HEALTH_BORDER[meta.health] || '#111827',
                    highlight: { background: meta.color || '#6B7280', border: HEALTH_BORDER[meta.health] || '#111827' },
                },
                font: { color: pickFontColor(meta.color || '#6B7280'), size: 14, face: 'inherit' },
                shapeProperties: { borderRadius: 8 },
                _clusterCompound: compoundId,
            },
        };
        network.cluster(opts);
        clusterIdByCompound[compoundId] = clusterId;
    }

    function expandCluster(clusterId) {
        if (network && network.isCluster(clusterId)) {
            network.openCluster(clusterId);
            for (const k of Object.keys(clusterIdByCompound)) {
                if (clusterIdByCompound[k] === clusterId) {
                    delete clusterIdByCompound[k];
                    break;
                }
            }
            for (const k of Object.keys(typeClusterIds)) {
                if (typeClusterIds[k] === clusterId) {
                    delete typeClusterIds[k];
                    break;
                }
            }
            delete clusterMeta[clusterId];
            // Re-apply filter so newly-revealed raw nodes respect the legend.
            applyTypeFilter();
            // Members just got real on-canvas positions — persist them.
            savePositions();
        }
    }

    function allClusterIds() {
        return Object.values(clusterIdByCompound).concat(Object.values(typeClusterIds));
    }

    function expandAll() {
        allClusterIds().forEach(id => {
            if (network.isCluster(id)) network.openCluster(id);
        });
        clusterIdByCompound = {};
        typeClusterIds = {};
        clusterMeta = {};
        applyTypeFilter();
        savePositions();
    }

    /**
     * Walk every cluster edge in the network and label it with the number of
     * base (real) edges it merges. Uses the public vis-network APIs
     * getConnectedEdges() and getBaseEdges(); clustering.updateEdge() is also
     * public though under-documented — wrapped in try/catch for safety.
     */
    function labelAllClusterEdges() {
        if (!network) return;
        allClusterIds().forEach(clusterId => {
            if (!network.isCluster(clusterId)) return;
            const eids = network.getConnectedEdges(clusterId);
            eids.forEach(eid => {
                try {
                    const base = network.getBaseEdges(eid);
                    if (base && base.length > 1) {
                        network.clustering.updateEdge(eid, {
                            label: base.length + ' ' + t('conn', 'conn.'),
                            font: {
                                size: 10,
                                color: '#1f2937',
                                background: 'rgba(255,255,255,0.92)',
                                strokeWidth: 0,
                                align: 'middle',
                            },
                        });
                    }
                } catch (e) {
                    // edge may have just been removed/expanded — ignore
                }
            });
        });
    }

    // ── Filtering by itemtype (legend pills) ────────────────────────────────

    function applyTypeFilter() {
        if (!nodesDS || !edgesDS) return;
        const hidden = state.hiddenTypes;

        // 1. Update raw node visibility.
        const nodeUpdates = [];
        nodesDS.forEach(n => {
            const should = hidden.has(n._itemtype);
            if ((n.hidden === true) !== should) {
                nodeUpdates.push({ id: n.id, hidden: should });
            }
        });
        if (nodeUpdates.length) nodesDS.update(nodeUpdates);

        // 2. Hide edges that have a hidden endpoint.
        const hiddenIds = new Set();
        nodesDS.forEach(n => { if (n.hidden) hiddenIds.add(n.id); });
        const edgeUpdates = [];
        edgesDS.forEach(e => {
            const should = hiddenIds.has(e.from) || hiddenIds.has(e.to);
            if ((e.hidden === true) !== should) {
                edgeUpdates.push({ id: e.id, hidden: should });
            }
        });
        if (edgeUpdates.length) edgesDS.update(edgeUpdates);
    }

    /**
     * Outlier-robust Fit. A few far-flung nodes (e.g. an SCCM/SCOM host reached
     * through one long edge) would make a plain network.fit() frame THEM and
     * shrink the core to a dot. Instead we frame the dense core — nodes within
     * ~the 85th-percentile distance of the median centre — plus the focused CI,
     * so Fit always lands on what matters. Outliers stay reachable by panning.
     */
    function robustFit(anim) {
        const opts = anim || { animation: { duration: 400, easingFunction: 'easeInOutQuad' } };
        if (!network || !nodesDS) { return; }
        const ids = nodesDS.getIds();
        let pos;
        try { pos = network.getPositions(ids); } catch (e) {
            try { network.fit(opts); } catch (e2) { /* ignore */ }
            return;
        }
        const pts = ids.filter(id => pos[id]);
        if (pts.length < 6) {
            try { network.fit(opts); } catch (e) { /* ignore */ }
            return;
        }
        const median = arr => {
            const s = arr.slice().sort((a, b) => a - b);
            const m = s.length >> 1;
            return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
        };
        const cx = median(pts.map(id => pos[id].x));
        const cy = median(pts.map(id => pos[id].y));
        const dist = {};
        pts.forEach(id => { dist[id] = Math.hypot(pos[id].x - cx, pos[id].y - cy); });
        const sorted = pts.map(id => dist[id]).sort((a, b) => a - b);
        const cut = sorted[Math.floor(sorted.length * 0.85)] || sorted[sorted.length - 1];
        const core = pts.filter(id => dist[id] <= cut * 1.1);
        // Always keep the focused CI(s) in frame.
        (allNodes || []).forEach(n => {
            if (n.seed && core.indexOf(n.id) === -1) { core.push(n.id); }
        });
        try {
            network.fit(core.length >= 2 ? Object.assign({}, opts, { nodes: core }) : opts);
        } catch (e) {
            try { network.fit(opts); } catch (e2) { /* ignore */ }
        }
    }

    // ── Layout modes (Flow / Force / Tree) ──────────────────────────────────

    /**
     * Switch the live network to another layout without re-fetching.
     *   flow  — dagre LR positions applied to the dataset, physics stays off
     *   force — one bounded physics run, then freeze + persist
     *   tree  — vis hierarchical engine takes over x/y
     */
    function applyLayoutMode(mode) {
        state.layoutMode = mode;
        if (!network) return;

        const anim = { animation: { duration: 400 } };

        if (mode === 'tree') {
            network.setOptions({
                layout: { hierarchical: hierarchicalOpts() },
                physics: { enabled: false },
                edges: { smooth: smoothFor(mode) },
            });
            robustFit(anim);
            return;
        }

        // Leaving tree (or staying flat): release the hierarchical engine.
        network.setOptions({
            layout: { hierarchical: false },
            physics: { enabled: false },
            edges: { smooth: smoothFor(mode) },
        });

        if (mode === 'flow' && typeof window.dagre !== 'undefined') {
            const current = nodesDS.get();
            const pos = computeDagrePositions(current, allEdges);
            const updates = [];
            current.forEach(n => {
                if (pos[n.id]) {
                    updates.push({ id: n.id, x: pos[n.id].x, y: pos[n.id].y });
                }
            });
            nodesDS.update(updates);
            repositionClusters(pos);
            savePositions();
            robustFit(anim);
        } else if (mode === 'force') {
            network.setOptions({
                physics: {
                    enabled: true,
                    solver: 'forceAtlas2Based',
                    forceAtlas2Based: {
                        gravitationalConstant: -45,
                        centralGravity: 0.012,
                        springLength: 130,
                        springConstant: 0.08,
                        damping: 0.6,
                        avoidOverlap: 0.6,
                    },
                    stabilization: false,
                },
            });
            network.once('stabilizationIterationsDone', () => {
                network.setOptions({ physics: { enabled: false } });
                savePositions();
                robustFit(anim);
            });
            network.stabilize(120);
        }
    }

    /**
     * After a flow re-layout, collapsed cluster nodes are not in nodesDS so
     * dagre never moved them — drop each at the average of its members'
     * computed positions so groups land where their content belongs.
     */
    function repositionClusters(pos) {
        const moveToMemberAvg = (clusterId, isMember) => {
            if (!network.isCluster(clusterId)) return;
            let sx = 0, sy = 0, n = 0;
            nodesDS.get().forEach(node => {
                if (isMember(node) && pos[node.id]) {
                    sx += pos[node.id].x;
                    sy += pos[node.id].y;
                    n++;
                }
            });
            if (n > 0) {
                try { network.moveNode(clusterId, sx / n, sy / n); } catch (e) { /* ignore */ }
            }
        };
        Object.entries(clusterIdByCompound).forEach(([compoundId, clusterId]) => {
            moveToMemberAvg(clusterId, node => node._compoundId === Number(compoundId));
        });
        Object.entries(typeClusterIds).forEach(([itemtype, clusterId]) => {
            moveToMemberAvg(clusterId, node => node._itemtype === itemtype);
        });
    }

    // ── Search (dim non-matching nodes) ─────────────────────────────────────

    function applySearch(q) {
        if (!nodesDS) return;
        state.search = q || '';
        const ql = state.search.toLowerCase();

        if (!ql) {
            // Reset opacity to 1 for any node that isn't already.
            const updates = [];
            nodesDS.forEach(n => {
                if (n.opacity !== 1) updates.push({ id: n.id, opacity: 1 });
            });
            if (updates.length) nodesDS.update(updates);
            return;
        }

        let firstMatch = null;
        const updates = [];
        nodesDS.forEach(n => {
            const matches = (n._name || '').toLowerCase().indexOf(ql) !== -1;
            const op = matches ? 1 : 0.15;
            if (n.opacity !== op) updates.push({ id: n.id, opacity: op });
            if (matches && !firstMatch) firstMatch = n.id;
        });
        if (updates.length) nodesDS.update(updates);
        if (firstMatch) {
            try {
                network.focus(firstMatch, { scale: 1.0, animation: { duration: 250 } });
            } catch (e) {
                // node may be inside a collapsed cluster — ignore
            }
        }
    }

    // ── PNG export ──────────────────────────────────────────────────────────

    /**
     * Snapshot the network canvas onto a solid background (the raw canvas is
     * transparent — pasted into docs it would look broken) and download it.
     */
    function exportPng() {
        if (!network) return;
        let src;
        try {
            src = network.canvas.frame.canvas;
        } catch (e) {
            return;
        }
        const out = document.createElement('canvas');
        out.width = src.width;
        out.height = src.height;
        const ctx = out.getContext('2d');
        const bg = getComputedStyle(canvas.closest('.uxc-impact-stage') || canvas).backgroundColor;
        ctx.fillStyle = (bg && bg !== 'rgba(0, 0, 0, 0)') ? bg : '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(src, 0, 0);

        const a = document.createElement('a');
        const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
        a.download = 'impact-map-' + stamp + '.png';
        a.href = out.toDataURL('image/png');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // ── Analysis overlays: what-if failure + path highlight (v2.1) ──────────
    //
    // Both overlays work by EMPHASIS, not destructive recolor: relevant
    // top-level nodes go to full opacity with a coloured border, everything
    // else dims to 0.15, and the connecting edges are recoloured. clearAnalysis
    // restores defaults (health/seed/itemtype border, search-aware opacity).
    // Base nodes hidden inside a collapsed cluster are represented by the
    // cluster: if any member is in the highlight set, the cluster lights up.

    function defaultBorder(payload) {
        const level = payload && payload.health && payload.health.level;
        const isSeed = payload && payload.seed;
        return HEALTH_BORDER[level] || (isSeed ? '#111827' : (payload ? payload.border : '#6B7280'));
    }

    /** Base node ids currently inside cluster c. */
    function membersOf(clusterId) {
        try { return network.getNodesInCluster(clusterId); } catch (e) { return []; }
    }

    /** Top-level visible node ids: loose base nodes + active clusters. */
    function visibleTops() {
        const tops = [];
        nodesDS.getIds().forEach(id => {
            let path = [id];
            try { path = network.findNode(id); } catch (e) { /* loose */ }
            if (path.length === 1) tops.push({ id: id, cluster: false });
        });
        allClusterIds().forEach(cid => {
            if (network.isCluster(cid)) tops.push({ id: cid, cluster: true });
        });
        return tops;
    }

    /**
     * Paint an overlay. `hi` = Set of highlighted base ids, `origin` = Set of
     * origin base ids (stronger). `edgeColor` recolours edges whose BOTH
     * endpoints are highlighted; others dim.
     */
    function paintOverlay(hi, origin, edgeColor) {
        const nodeUpdates = [];
        visibleTops().forEach(top => {
            const bases = top.cluster ? membersOf(top.id) : [top.id];
            const isOrigin = bases.some(b => origin.has(b));
            const isHi = isOrigin || bases.some(b => hi.has(b));
            const opacity = isHi ? 1 : 0.15;
            const border = isOrigin ? '#b91c1c' : (isHi ? edgeColor : null);
            const bw = isOrigin ? 4 : (isHi ? 3 : 2);
            if (top.cluster) {
                const opts = { opacity: opacity, borderWidth: bw };
                if (border) opts.color = { border: border };
                try { network.clustering.updateClusteredNode(top.id, opts); } catch (e) { /* ignore */ }
            } else {
                const u = { id: top.id, opacity: opacity, borderWidth: bw };
                if (border) u.color = { border: border };
                nodeUpdates.push(u);
            }
        });
        if (nodeUpdates.length) nodesDS.update(nodeUpdates);

        const edgeUpdates = [];
        edgesDS.forEach(e => {
            const on = (hi.has(e.from) || origin.has(e.from)) && (hi.has(e.to) || origin.has(e.to));
            edgeUpdates.push({
                id: e.id,
                color: { color: on ? edgeColor : '#e2e8f0' },
                width: on ? 2.5 : 1,
            });
        });
        if (edgeUpdates.length) edgesDS.update(edgeUpdates);
        state.analysisOn = true;
    }

    /** Restore default node/edge styling (search-aware) after an overlay. */
    function clearAnalysis() {
        if (!state.analysisOn || !nodesDS) { state.analysisOn = false; return; }
        const ql = state.search.toLowerCase();
        const nodeUpdates = [];
        nodesDS.forEach(n => {
            const payload = nodeById[n.id];
            const op = ql ? ((n._name || '').toLowerCase().indexOf(ql) !== -1 ? 1 : 0.15) : 1;
            nodeUpdates.push({
                id: n.id,
                opacity: op,
                borderWidth: defaultBorderWidth(payload),
                color: { border: defaultBorder(payload) },
            });
        });
        nodesDS.update(nodeUpdates);
        allClusterIds().forEach(cid => {
            if (!network.isCluster(cid)) return;
            const m = clusterMeta[cid];
            try {
                network.clustering.updateClusteredNode(cid, {
                    opacity: 1, borderWidth: 2,
                    color: { border: '#111827', background: (m && m.color) || '#6B7280' },
                });
            } catch (e) { /* ignore */ }
        });
        const edgeUpdates = [];
        edgesDS.forEach(e => edgeUpdates.push({ id: e.id, color: { color: '#94a3b8' }, width: 1.5 }));
        edgesDS.update(edgeUpdates);
        labelAllClusterEdges();
        state.analysisOn = false;
    }

    function defaultBorderWidth(payload) {
        const level = payload && payload.health && payload.health.level;
        const isSeed = payload && payload.seed;
        return (HEALTH_BORDER[level] || isSeed) ? 3 : 2;
    }

    // ── What-if failure simulation ──────────────────────────────────────────
    // If the chosen CI fails, everything reachable by following impact arrows
    // OUT (it impacts → they're affected) lights up red.

    function simulateFailure(originId) {
        const fwd = {};
        allEdges.forEach(e => { (fwd[e.from] = fwd[e.from] || []).push(e.to); });
        const affected = new Set();
        let layer = [originId];
        while (layer.length) {
            const next = [];
            layer.forEach(cur => {
                (fwd[cur] || []).forEach(nb => {
                    if (nb !== originId && !affected.has(nb)) { affected.add(nb); next.push(nb); }
                });
            });
            layer = next;
        }
        const hi = new Set(affected);
        const origin = new Set([originId]);
        paintOverlay(hi, origin, '#d63939');
        const name = (nodeById[originId] && nodeById[originId].name) || originId;
        setStatus(affected.size + ' ' + t('affected_if_fails', 'affected if this fails') + ' — ' + name, 'info');
    }

    // ── Path highlighting ───────────────────────────────────────────────────
    // Pick two nodes; BFS the UNDIRECTED graph for a shortest chain and light
    // it. First pick is remembered; second pick computes + draws.

    function pathPick(id) {
        if (state.pathFirst === null) {
            state.pathFirst = id;
            clearAnalysis();
            paintOverlay(new Set(), new Set([id]), '#2563eb');
            const name = (nodeById[id] && nodeById[id].name) || id;
            setStatus(t('path_from', 'Path from') + ' ' + name + ' — ' + t('path_pick2', 'pick a second node'), 'info');
            return;
        }
        const a = state.pathFirst, b = id;
        state.pathFirst = null;
        if (a === b) { clearAnalysis(); setStatus('', ''); return; }

        const adj = {};
        allEdges.forEach(e => {
            (adj[e.from] = adj[e.from] || []).push(e.to);
            (adj[e.to] = adj[e.to] || []).push(e.from);
        });
        const prev = {}; prev[a] = a;
        let layer = [a], found = false;
        while (layer.length && !found) {
            const next = [];
            for (const cur of layer) {
                for (const nb of (adj[cur] || [])) {
                    if (!(nb in prev)) { prev[nb] = cur; if (nb === b) { found = true; break; } next.push(nb); }
                }
                if (found) break;
            }
            layer = next;
        }
        if (!(b in prev)) {
            clearAnalysis();
            setStatus(t('no_path', 'No path between those two nodes'), 'error');
            return;
        }
        const chain = new Set();
        let cur = b;
        while (cur !== a) { chain.add(cur); cur = prev[cur]; }
        chain.add(a);
        paintOverlay(chain, new Set([a, b]), '#2563eb');
        setStatus(t('path_len', 'Path length') + ': ' + (chain.size - 1), 'info');
    }

    // ── Mode switching (normal / whatif / path) ─────────────────────────────

    function setMode(mode) {
        state.mode = (state.mode === mode) ? 'normal' : mode;
        state.pathFirst = null;
        clearAnalysis();
        setStatus('', '');
        if (sidePanel) sidePanel.classList.remove('uxc-impact-side--open');
        [[btnWhatif, 'whatif'], [btnPath, 'path']].forEach(([btn, m]) => {
            if (btn) btn.classList.toggle('active', state.mode === m);
        });
        if (canvas) canvas.classList.toggle('uxc-impact-analyzing', state.mode !== 'normal');
        if (state.mode === 'whatif') {
            setStatus(t('whatif_hint', 'Click a CI to see what fails with it'), 'info');
        } else if (state.mode === 'path') {
            setStatus(t('path_hint', 'Click two CIs to trace the path between them'), 'info');
        }
    }

    // ── SVG export (vector, editable / Visio-importable) ────────────────────
    // Reconstructed from node positions + the model — every shape/line/label
    // is a separate SVG element, so it opens editable in Visio/Illustrator
    // (Device42 parity), without bundling any library.

    function exportSvg() {
        if (!network || !nodesDS) return;
        const ids = nodesDS.getIds();
        let pos;
        try { pos = network.getPositions(ids); } catch (e) { return; }
        const W = id => {
            const n = nodesDS.get(id);
            const label = String((n && n._name) || id);
            return Math.max(70, Math.min(190, label.length * 7.5 + 30));
        };
        const H = 38;
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        ids.forEach(id => {
            const p = pos[id]; if (!p) return;
            minX = Math.min(minX, p.x - W(id) / 2); maxX = Math.max(maxX, p.x + W(id) / 2);
            minY = Math.min(minY, p.y - H / 2);    maxY = Math.max(maxY, p.y + H / 2);
        });
        if (!isFinite(minX)) return;
        const pad = 40;
        const vbW = (maxX - minX) + pad * 2, vbH = (maxY - minY) + pad * 2;
        const ox = -minX + pad, oy = -minY + pad;
        const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const parts = [];
        parts.push('<?xml version="1.0" encoding="UTF-8"?>');
        parts.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + Math.ceil(vbW) + '" height="' + Math.ceil(vbH) + '" viewBox="0 0 ' + Math.ceil(vbW) + ' ' + Math.ceil(vbH) + '">');
        parts.push('<rect width="100%" height="100%" fill="#ffffff"/>');
        // Edges first (under nodes).
        allEdges.forEach(e => {
            const a = pos[e.from], b = pos[e.to];
            if (!a || !b) return;
            parts.push('<line x1="' + (a.x + ox).toFixed(1) + '" y1="' + (a.y + oy).toFixed(1) +
                '" x2="' + (b.x + ox).toFixed(1) + '" y2="' + (b.y + oy).toFixed(1) +
                '" stroke="#94a3b8" stroke-width="1.5"/>');
        });
        ids.forEach(id => {
            const p = pos[id]; if (!p) return;
            const n = nodesDS.get(id);
            const w = W(id);
            const x = (p.x + ox - w / 2), y = (p.y + oy - H / 2);
            const fill = (n && n.color && n.color.background) || '#9CA3AF';
            const stroke = (n && n.color && n.color.border) || '#6B7280';
            const fg = pickFontColor(fill);
            parts.push('<rect x="' + x.toFixed(1) + '" y="' + y.toFixed(1) + '" width="' + w.toFixed(1) +
                '" height="' + H + '" rx="6" fill="' + fill + '" stroke="' + stroke + '" stroke-width="2"/>');
            parts.push('<text x="' + (p.x + ox).toFixed(1) + '" y="' + (p.y + oy + 4).toFixed(1) +
                '" text-anchor="middle" font-family="sans-serif" font-size="12" fill="' + fg + '">' +
                esc((n && n._name) || id) + '</text>');
        });
        parts.push('</svg>');
        triggerDownload(new Blob([parts.join('\n')], { type: 'image/svg+xml' }), 'svg');
    }

    // ── PDF export ──────────────────────────────────────────────────────────
    // Dependency-free: open the rendered PNG in a print window; the user picks
    // "Save as PDF". Keeps the bundle small (no PDF library).

    function exportPdf() {
        const dataUrl = pngDataUrl();
        if (!dataUrl) return;
        const w = window.open('', '_blank');
        if (!w) return;
        w.document.write(
            '<html><head><title>Impact Map</title><style>@media print{@page{size:landscape}}' +
            'body{margin:0}img{width:100%;height:auto}</style></head><body>' +
            '<img src="' + dataUrl + '" onload="window.focus();window.print();"></body></html>'
        );
        w.document.close();
    }

    /** Composite the network canvas onto white; return a PNG data URL. */
    function pngDataUrl() {
        if (!network) return null;
        let src;
        try { src = network.canvas.frame.canvas; } catch (e) { return null; }
        const out = document.createElement('canvas');
        out.width = src.width; out.height = src.height;
        const ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(src, 0, 0);
        return out.toDataURL('image/png');
    }

    function triggerDownload(blob, ext) {
        const a = document.createElement('a');
        const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
        a.download = 'impact-map-' + stamp + '.' + ext;
        a.href = URL.createObjectURL(blob);
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }

    // ── Mini-map overview inset ─────────────────────────────────────────────
    // A second, non-interactive vis network sharing the SAME graph coordinate
    // space (positions snapshotted from the main network). Its afterDrawing
    // hook strokes the main viewport rectangle directly in graph coords — no
    // conversion needed since both share the coordinate system. Click recentres.

    function buildMinimap() {
        if (!miniEl || !network) return;
        destroyMinimap();
        let pos;
        try { pos = network.getPositions(nodesDS.getIds()); } catch (e) { return; }
        const mNodes = [];
        nodesDS.getIds().forEach(id => {
            const p = pos[id]; if (!p) return;
            const n = nodesDS.get(id);
            mNodes.push({
                id: id, x: p.x, y: p.y, fixed: true, shape: 'dot', size: 6,
                color: (n && n.color && n.color.background) || '#9CA3AF',
            });
        });
        const mEdges = allEdges.map((e, i) => ({ id: 'm' + i, from: e.from, to: e.to, color: { color: '#cbd5e1' }, width: 0.5 }));
        mini = new vis.Network(miniEl, { nodes: new vis.DataSet(mNodes), edges: new vis.DataSet(mEdges) }, {
            interaction: { dragNodes: false, dragView: false, zoomView: false, selectable: false, hover: false },
            physics: false,
            layout: { randomSeed: 116 },
            nodes: { borderWidth: 0 },
        });
        mini.once('afterDrawing', () => { try { mini.fit(); } catch (e) {} });
        mini.on('afterDrawing', ctx => {
            try {
                const c = network.getViewPosition();
                const s = network.getScale();
                const cv = network.canvas.frame.canvas;
                const w = (cv.clientWidth || cv.width) / s;
                const h = (cv.clientHeight || cv.height) / s;
                ctx.save();
                ctx.strokeStyle = '#2563eb';
                ctx.lineWidth = 3 / mini.getScale();
                ctx.strokeRect(c.x - w / 2, c.y - h / 2, w, h);
                ctx.restore();
            } catch (e) { /* ignore */ }
        });
        mini.on('click', params => {
            try { network.moveTo({ position: params.pointer.canvas, animation: { duration: 300 } }); } catch (e) {}
        });
        miniEl.style.display = '';
        if (btnMinimap) btnMinimap.classList.add('active');
    }

    function destroyMinimap() {
        if (mini) { try { mini.destroy(); } catch (e) {} mini = null; }
        if (miniEl) miniEl.style.display = 'none';
        if (btnMinimap) btnMinimap.classList.remove('active');
    }

    function refreshMinimap() {
        if (mini) { buildMinimap(); } // rebuild snapshot at current positions
    }

    // ── Event wiring ────────────────────────────────────────────────────────

    function bindEvents() {
        network.on('doubleClick', params => {
            if (params.nodes.length === 0) return;
            const id = params.nodes[0];
            if (network.isCluster(id)) {
                expandCluster(id);
            }
        });

        network.on('selectNode', params => {
            const id = params.nodes[0];

            // Analysis modes intercept the click: pick a representative base
            // node (a cluster → its first member) and run the overlay instead
            // of opening the side panel.
            if (state.mode === 'whatif') {
                simulateFailure(representativeBase(id));
                network.unselectAll();
                return;
            }
            if (state.mode === 'path') {
                pathPick(representativeBase(id));
                network.unselectAll();
                return;
            }

            if (network.isCluster(id)) {
                showClusterPanel(clusterMeta[id], id);
            } else {
                // Explore mode: clicking a node reveals its next hop, then we
                // still open the info panel.
                if (state.explore) { exploreExpand(id); }
                const node = nodesDS.get(id);
                showNodePanel(node);
            }
        });
        network.on('deselectNode', () => {
            if (state.mode === 'normal') sidePanel.classList.remove('uxc-impact-side--open');
        });

        // Manual node moves are the user telling us where things belong —
        // persist so the arrangement survives reloads (ServiceNow behaviour).
        network.on('dragEnd', params => {
            if (!state.explore && params.nodes && params.nodes.length) {
                savePositions();
            }
        });

        if (btnExpandAll) {
            // In explore mode "Expand all" reveals the whole graph; otherwise it
            // opens all clusters (the original behaviour).
            btnExpandAll.addEventListener('click', () => {
                if (state.explore) { exploreRevealAll(); } else { expandAll(); }
            });
        }
        if (btnCollapseAll) {
            // In explore mode "Collapse" re-collapses to the seed; otherwise it
            // re-forms the clusters.
            btnCollapseAll.addEventListener('click', () => {
                if (state.explore) { exploreReset(); } else { reclusterAll(); }
            });
        }
        if (groupChk) {
            groupChk.addEventListener('change', () => {
                if (groupChk.checked) {
                    clusterLooseTypes();
                    labelAllClusterEdges();
                } else {
                    // Open only the auto type groups; named compounds stay.
                    Object.values(typeClusterIds).forEach(id => {
                        if (network.isCluster(id)) network.openCluster(id);
                        delete clusterMeta[id];
                    });
                    typeClusterIds = {};
                    applyTypeFilter();
                }
            });
        }
        if (btnExport) {
            btnExport.addEventListener('click', exportPng);
        }
        if (btnExportSvg) {
            btnExportSvg.addEventListener('click', exportSvg);
        }
        if (btnExportPdf) {
            btnExportPdf.addEventListener('click', exportPdf);
        }
        if (btnWhatif) {
            btnWhatif.addEventListener('click', () => setMode('whatif'));
        }
        if (btnPath) {
            btnPath.addEventListener('click', () => setMode('path'));
        }
        if (btnMinimap) {
            btnMinimap.addEventListener('click', () => { mini ? destroyMinimap() : buildMinimap(); });
        }
        if (btnFit) {
            btnFit.addEventListener('click', () => robustFit({ animation: { duration: 400 } }));
        }
        if (layoutSel) {
            layoutSel.addEventListener('change', () => applyLayoutMode(layoutSel.value));
        }
        if (searchEl) {
            searchEl.addEventListener('input', () => {
                applySearch(searchEl.value.trim());
            });
        }
        // Keep the minimap viewport rectangle live as the main view moves.
        network.on('dragEnd', () => { if (mini) mini.redraw(); });
        network.on('zoom', () => { if (mini) mini.redraw(); });
        network.on('animationFinished', () => { if (mini) mini.redraw(); });
    }

    /** A representative base node id for a click target (cluster → a member). */
    function representativeBase(id) {
        if (network.isCluster(id)) {
            const m = membersOf(id);
            return m.length ? m[0] : id;
        }
        return id;
    }

    function showNodePanel(node) {
        if (!node || !sidePanel) return;
        const style = typeStyles[node._itemtype] || { color: '#9CA3AF', label: node._itemtype, icon: 'ti ti-package' };

        // Health rows (only signals that exist — no "unknown" filler).
        let healthRows = '';
        const h = node._health;
        if (h && h.level) {
            const dot = h.level === 'crit' ? '#d63939' : (h.level === 'warn' ? '#f59f00' : '#2fb344');
            healthRows += '<dt>' + t('health', 'Health') + '</dt><dd>' +
                '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + dot + ';margin-right:0.35rem"></span>' +
                escapeHtml(t('health_' + h.level, h.level)) + '</dd>';
            if (typeof h.tickets === 'number') {
                healthRows += '<dt>' + t('open_tickets', 'Open tickets') + '</dt><dd>' + escapeHtml(h.tickets) + '</dd>';
            }
            if (typeof h.agent_days === 'number') {
                healthRows += '<dt>' + t('agent_seen', 'Agent seen') + '</dt><dd>' +
                    (h.agent_days === 0
                        ? escapeHtml(t('today', 'today'))
                        : escapeHtml(h.agent_days) + ' ' + escapeHtml(t('days_ago', 'days ago'))) + '</dd>';
            }
            if (h.vulns) {
                const vdot = h.vulns === 'crit' ? '#d63939' : (h.vulns === 'warn' ? '#f59f00' : '#2fb344');
                healthRows += '<dt>' + t('vulns', 'Vulnerabilities') + '</dt><dd>' +
                    '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + vdot + ';margin-right:0.35rem"></span>' +
                    escapeHtml(t('vulns_' + h.vulns, h.vulns)) + '</dd>';
            }
        }

        sidePanel.innerHTML =
            '<div class="uxc-impact-side-head" style="background:' + escapeHtml(style.color) + ';color:' + pickFontColor(style.color) + '">' +
                '<i class="' + escapeHtml(style.icon || 'ti ti-package') + ' me-2"></i>' +
                '<strong>' + escapeHtml(node._name) + '</strong>' +
            '</div>' +
            '<div class="uxc-impact-side-body">' +
                '<dl class="uxc-impact-kv">' +
                    '<dt>' + t('type', 'Type') + '</dt><dd>' + escapeHtml(style.label || node._itemtype) + '</dd>' +
                    '<dt>' + t('id', 'ID') + '</dt><dd>#' + escapeHtml(node._items_id) + '</dd>' +
                    healthRows +
                '</dl>' +
                '<a class="btn btn-primary btn-sm" href="' + escapeHtml(node._url) + '">' +
                    '<i class="ti ti-external-link me-1"></i>' + t('open_in_glpi', 'Open in GLPI') +
                '</a>' +
            '</div>';
        sidePanel.classList.add('uxc-impact-side--open');
    }

    function showClusterPanel(meta, clusterId) {
        if (!meta || !sidePanel) return;
        const icon = meta.kind === 'type' ? 'ti ti-category' : 'ti ti-stack-2';
        const kindLabel = meta.kind === 'type' ? t('type_group', 'Type group') : t('group', 'Group');
        sidePanel.innerHTML =
            '<div class="uxc-impact-side-head" style="background:' + escapeHtml(meta.color || '#6B7280') + ';color:' + pickFontColor(meta.color || '#6B7280') + '">' +
                '<i class="' + icon + ' me-2"></i>' +
                '<strong>' + escapeHtml(meta.name) + '</strong>' +
            '</div>' +
            '<div class="uxc-impact-side-body">' +
                '<dl class="uxc-impact-kv">' +
                    '<dt>' + kindLabel + '</dt><dd>' + escapeHtml(meta.name) + '</dd>' +
                    '<dt>' + t('members', 'Members') + '</dt><dd>' + escapeHtml(meta.count) + '</dd>' +
                '</dl>' +
                '<button class="btn btn-outline-primary btn-sm uxc-impact-expand-this">' +
                    '<i class="ti ti-arrows-maximize me-1"></i>' + t('expand', 'Expand') +
                '</button>' +
            '</div>';
        const btn = sidePanel.querySelector('.uxc-impact-expand-this');
        if (btn) {
            btn.addEventListener('click', () => {
                expandCluster(clusterId);
                sidePanel.classList.remove('uxc-impact-side--open');
            });
        }
        sidePanel.classList.add('uxc-impact-side--open');
    }

    // Bottom-of-body script may load after DOMContentLoaded; handle both.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
