<?php

/**
 * UX Customizer - Impact Map data endpoint (GET, JSON)
 *
 * Returns the impact graph (nodes / edges / compounds) for the client
 * vis-network renderer. Read-only — does NOT mutate impact tables.
 *
 * Query params (all optional):
 *   itemtype      — restrict to the subgraph from this asset (with items_id)
 *   items_id      — paired with itemtype
 *   itil_itemtype — Ticket|Change|Problem: subgraph seeded by ALL assets
 *   itil_items_id   linked to this ITIL object (pair) — used by the ITIL tab
 *   forward       — directed BFS hops along arrows OUT (impacts); 0..10, default 2
 *   backward      — directed BFS hops along arrows IN (impacted by); 0..10, default 2
 *   types[]       — filter nodes to these itemtypes (repeatable)
 *
 * Rights model (per scope):
 *   org-wide (no scope) — config UPDATE (the Setup page audience)
 *   asset scope         — READ on that asset
 *   itil scope          — READ on that Ticket/Change/Problem
 *
 * Response: { ok: bool, error?: string, data?: {nodes,edges,compounds,meta} }
 *
 * CSRF: read-only GET, no token required. Authn/Authz enforced below.
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\ImpactMap;

include('../../../inc/includes.php');

global $CFG_GLPI, $DB;

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('impact360') || !$plugin->isActivated('impact360')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

if (!Config::isModuleEnabled('impactmap')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Impact Map module disabled']);
    exit;
}

function uxc_impact_deny(): void
{
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

$scope    = [];
$hasScope = false;

if (!empty($_GET['itil_itemtype']) && !empty($_GET['itil_items_id'])) {
    // ── ITIL scope: seeds = all assets linked to the Ticket/Change/Problem ──
    $itilType = (string) $_GET['itil_itemtype'];
    $itilId   = (int) $_GET['itil_items_id'];
    if (!isset(ImpactMap::ITIL_LINK_TABLES[$itilType]) || !class_exists($itilType)) {
        uxc_impact_deny();
    }
    $itil = new $itilType();
    // can(READ) covers entity restrictions and per-object visibility rules
    // (e.g. a technician seeing only their group's tickets).
    if (!$itil->can($itilId, READ)) {
        uxc_impact_deny();
    }
    $scope['seeds'] = ImpactMap::linkedAssetSeeds($itilType, $itilId);
    if ($scope['seeds'] === []) {
        // No (mappable) assets on this object: empty graph, not an error.
        echo json_encode(['ok' => true, 'data' => [
            'nodes' => [], 'edges' => [], 'compounds' => [],
            'meta'  => ['truncated' => false, 'total_nodes' => 0, 'total_edges' => 0],
        ]]);
        exit;
    }
    $hasScope = true;
} elseif (!empty($_GET['itemtype']) && !empty($_GET['items_id'])) {
    // ── Asset scope ──
    // Whitelist itemtype against our known list — prevents arbitrary class
    // strings reaching getTableForItemType().
    $known = ImpactMap::knownItemtypes();
    if (!isset($known[$_GET['itemtype']])) {
        uxc_impact_deny();
    }
    $itemtype = (string) $_GET['itemtype'];
    $itemId   = (int) $_GET['items_id'];
    $item     = class_exists($itemtype) ? new $itemtype() : null;
    if (!($item instanceof CommonDBTM) || !$item->can($itemId, READ)) {
        uxc_impact_deny();
    }
    $scope['itemtype'] = $itemtype;
    $scope['items_id'] = $itemId;
    $hasScope = true;
}

if ($hasScope) {
    // BFS depths: numeric, clamp to [0..10] so a hostile or fat-fingered URL
    // can't make us BFS the entire org. ImpactMap::getGraph re-clamps to be safe.
    if (isset($_GET['forward'])) {
        $scope['forward']  = max(0, min(10, (int) $_GET['forward']));
    }
    if (isset($_GET['backward'])) {
        $scope['backward'] = max(0, min(10, (int) $_GET['backward']));
    }
} else {
    // ── Org-wide view (no scope): Setup-page audience only ──
    Session::checkRight('config', UPDATE);
}
if (!empty($_GET['types']) && is_array($_GET['types'])) {
    $known = ImpactMap::knownItemtypes();
    $types = [];
    foreach ($_GET['types'] as $t) {
        if (is_string($t) && isset($known[$t])) {
            $types[] = $t;
        }
    }
    if ($types) {
        $scope['types'] = $types;
    }
}

// Observed-traffic overlay from netstatconnections. ON unless explicitly
// disabled (&include_observed=0). Entity scoping still applies to every node
// inside getGraph, so this never widens what the session may see — it only
// adds observed edges/labels between already-visible CIs (and CIs the session
// can see). No-op when the netstatconnections plugin isn't installed.
$scope['include_observed'] = !isset($_GET['include_observed'])
    || (int) $_GET['include_observed'] === 1;

try {
    $graph = ImpactMap::getGraph($scope);
    echo json_encode(['ok' => true, 'data' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to build graph']);
}
