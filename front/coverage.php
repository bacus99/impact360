<?php

/**
 * Impact360 - Coverage-gap matrix board
 *
 * For each asset: which sources see it — Armis, GLPI agent, Defender,
 * Nexpose. Armis-only assets (on the network, no agent, no EDR, no vuln scan)
 * are the unmanaged attack surface. Data layer: src/CoverageMatrix.php
 * (entity-scoped, fail-closed per source, merged in PHP — no cross-source SQL
 * aggregate).
 *
 * Query params (all validated by CoverageMatrix::filters()):
 *   itemtype, q (name search), view (all|armis_only|missing|none|full|pattern),
 *   src (with view=missing), p (bit pattern with view=pattern), start.
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\CoverageMatrix;

// No include of GLPI's inc/includes.php: the kernel has already booted before a plugin script runs (GLPI 11 and 12); the relative path fails when the plugin lives in a marketplace directory outside the GLPI root.

global $CFG_GLPI;

$plugin = new Plugin();
if (!$plugin->isInstalled('impact360') || !$plugin->isActivated('impact360')) {
    Html::displayNotFoundError();
}
if (!Config::isModuleEnabled('coverage')) {
    Html::displayNotFoundError();
}

Session::checkLoginUser();

$sources = CoverageMatrix::usableSources();
if ($sources === []) {
    Html::displayRightError();
}
$keys      = array_keys($sources);
$itemtypes = CoverageMatrix::itemtypes($sources);
$f         = CoverageMatrix::filters($_GET, $keys, $itemtypes);

$data  = CoverageMatrix::build($sources, $f['itemtype'] !== '' ? [$f['itemtype']] : $itemtypes, $f['q']);
$scope = $data['rows'];   // itemtype + name search already applied in SQL by build()
$total = count($scope);

$patterns = CoverageMatrix::patternCounts($scope, $keys);

$shown = array_values(array_filter(
    $scope,
    static fn(array $r): bool => CoverageMatrix::matches(
        CoverageMatrix::bits($r, $keys), $keys, $f['view'], $f['src'], $f['p']
    )
));
$shownCount = count($shown);
$start      = $f['start'] < $shownCount ? $f['start'] : 0;
$page       = array_slice($shown, $start, CoverageMatrix::PAGE_SIZE);

$self = $CFG_GLPI['root_doc'] . '/plugins/impact360/front/coverage.php';
$e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** URL to this board with the current itemtype/search kept and $over applied. */
$url = static function (array $over) use ($self, $f): string {
    $params = array_filter(array_merge([
        'itemtype' => $f['itemtype'],
        'q'        => $f['q'],
    ], $over), static fn($v): bool => $v !== '' && $v !== null && $v !== 0);
    return $self . ($params === [] ? '' : '?' . http_build_query($params));
};

/** Count of rows in $scope matching a view. */
$countView = static function (string $view, string $src = '', string $p = '') use ($scope, $keys): int {
    $n = 0;
    foreach ($scope as $r) {
        if (CoverageMatrix::matches(CoverageMatrix::bits($r, $keys), $keys, $view, $src, $p)) {
            $n++;
        }
    }
    return $n;
};
$pct = static fn(int $n): string => $total > 0 ? number_format(100 * $n / $total, 1) . '%' : '—';

Html::header(__('Coverage Gaps', 'impact360'), $_SERVER['PHP_SELF'], 'plugins', 'impact360');

echo '<link rel="stylesheet" type="text/css" href="' . $CFG_GLPI['root_doc']
    . '/plugins/impact360/public/css/coverage.css?v=' . PLUGIN_IMPACT360_VERSION . '">';

echo '<div class="container-fluid mt-3 uxc-cov">';
echo '<div class="d-flex align-items-center mb-1">';
echo '<i class="ti ti-radar-2 me-2" style="font-size:1.6rem"></i>';
echo '<h2 class="m-0">' . $e(__('Coverage Gaps', 'impact360')) . '</h2>';
echo '</div>';
echo '<p class="uxc-muted mb-3">' . $e(__('Which sources actually see each asset. Assets only Armis sees — no inventory agent, no EDR, no vulnerability scan — are the unmanaged attack surface.', 'impact360')) . '</p>';

// Sources missing for this session (plugin inactive or no right) — say so, don't hide silently.
$missing = array_diff_key(CoverageMatrix::SOURCES, $sources);
if ($missing !== []) {
    echo '<div class="uxc-cov-note">' . $e(sprintf(
        __('Not shown (plugin inactive, not installed, or no read right): %s', 'impact360'),
        implode(', ', array_map(static fn(array $s): string => $s['label'], $missing))
    )) . '</div>';
}
if ($data['truncated']) {
    echo '<div class="uxc-cov-note">' . $e(sprintf(
        __('Only the first %d assets were analysed — filter by type to see the rest.', 'impact360'),
        CoverageMatrix::MAX_ASSETS
    )) . '</div>';
}

// ── Filters ──────────────────────────────────────────────────────────────
echo '<form method="get" action="' . $e($self) . '" class="uxc-cov-card mb-3">';
echo '<div class="row g-2 align-items-end">';
echo '<div class="col-auto"><label class="form-label small mb-1">' . $e(__('Type', 'impact360')) . '</label>';
echo '<select name="itemtype" class="form-select form-select-sm">';
echo '<option value="">' . $e(__('All types', 'impact360')) . '</option>';
foreach ($itemtypes as $t) {
    echo '<option value="' . $e($t) . '"' . ($f['itemtype'] === $t ? ' selected' : '') . '>'
        . $e(CoverageMatrix::typeName($t)) . '</option>';
}
echo '</select></div>';
echo '<div class="col-auto"><label class="form-label small mb-1">' . $e(__('Name contains', 'impact360')) . '</label>';
echo '<input type="text" name="q" class="form-control form-control-sm" style="width:14rem" value="' . $e($f['q']) . '"></div>';
if ($f['view'] !== 'all') {
    echo '<input type="hidden" name="view" value="' . $e($f['view']) . '">';
    if ($f['src'] !== '') {
        echo '<input type="hidden" name="src" value="' . $e($f['src']) . '">';
    }
    if ($f['p'] !== '') {
        echo '<input type="hidden" name="p" value="' . $e($f['p']) . '">';
    }
}
echo '<div class="col-auto"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter me-1"></i>'
    . $e(__('Apply', 'impact360')) . '</button></div>';
echo '</div></form>';

// ── Headline tiles ───────────────────────────────────────────────────────
$tiles = [];
$tiles[] = ['all', '', '', __('Assets analysed', 'impact360'), $total, 'neutral', null];
if (isset($sources['armis'])) {
    $um   = CoverageMatrix::unmanagedArmisCount();
    $sub  = $um !== null && $um > 0
        ? sprintf(__('+ %d network-discovered devices Armis knows but GLPI has not inventoried', 'impact360'), $um)
        : null;
    $tiles[] = ['armis_only', '', '', __('Armis only — unmanaged attack surface', 'impact360'),
        $countView('armis_only'), 'bad', $sub];
}
foreach ($sources as $k => $src) {
    $tiles[] = ['missing', $k, '', sprintf(__('Not seen by %s', 'impact360'), $src['label']),
        $countView('missing', $k), 'warn', null];
}
$tiles[] = ['none', '', '', __('Seen by no source', 'impact360'), $countView('none'), 'bad', null];
$tiles[] = ['full', '', '', __('Seen by every source', 'impact360'), $countView('full'), 'ok', null];

echo '<div class="uxc-cov-tiles">';
foreach ($tiles as [$view, $src, $p, $label, $n, $tone, $sub]) {
    $active = $f['view'] === $view && $f['src'] === $src;
    echo '<a class="uxc-cov-tile tone-' . $e($tone) . ($active ? ' is-active' : '') . '" href="'
        . $e($url(['view' => $view === 'all' ? '' : $view, 'src' => $src])) . '">';
    echo '<div class="uxc-cov-tile-n">' . (int) $n . '</div>';
    echo '<div class="uxc-cov-tile-l">' . $e($label) . '</div>';
    if ($view !== 'all') {
        echo '<div class="uxc-cov-tile-p">' . $e($pct((int) $n)) . '</div>';
    }
    if ($sub !== null) {
        echo '<div class="uxc-cov-tile-s">' . $e($sub);
        if (class_exists('Unmanaged')) {
            echo ' — <span class="uxc-cov-link" data-href="' . $e(\Unmanaged::getSearchURL()) . '">'
                . $e(__('open list', 'impact360')) . '</span>';
        }
        echo '</div>';
    }
    echo '</a>';
}
echo '</div>';

// ── Coverage pattern matrix ──────────────────────────────────────────────
echo '<div class="uxc-cov-card mb-3">';
echo '<div class="uxc-cov-title">' . $e(__('Coverage patterns', 'impact360')) . '</div>';
echo '<div class="uxc-cov-tablewrap"><table class="uxc-cov-table uxc-cov-patterns"><thead><tr>';
foreach ($sources as $src) {
    echo '<th class="c">' . $e($src['label']) . '</th>';
}
echo '<th class="r">' . $e(__('Assets', 'impact360')) . '</th><th class="r">%</th><th class="uxc-cov-barcol"></th><th></th></tr></thead><tbody>';
$max = $patterns === [] ? 1 : max($patterns);
foreach ($patterns as $bits => $n) {
    $bits     = (string) $bits;
    $isArmis  = isset($sources['armis']) && CoverageMatrix::matches($bits, $keys, 'armis_only', '', '');
    $isNone   = strpos($bits, '1') === false;
    $rowClass = $isArmis ? ' class="is-armis-only"' : ($isNone ? ' class="is-none"' : '');
    echo '<tr' . $rowClass . '>';
    foreach (str_split($bits) as $bit) {
        echo '<td class="c">' . ($bit === '1'
            ? '<span class="uxc-cov-dot on" title="' . $e(__('Seen', 'impact360')) . '"></span>'
            : '<span class="uxc-cov-dot off" title="' . $e(__('Not seen', 'impact360')) . '"></span>') . '</td>';
    }
    echo '<td class="r"><strong>' . (int) $n . '</strong></td>';
    echo '<td class="r uxc-muted">' . $e($pct((int) $n)) . '</td>';
    echo '<td class="uxc-cov-barcol"><span class="uxc-cov-bar" style="width:' . round(100 * $n / $max) . '%"></span></td>';
    echo '<td><a href="' . $e($url(['view' => 'pattern', 'p' => $bits])) . '">' . $e(__('Show', 'impact360')) . '</a>';
    if ($isArmis) {
        echo ' <span class="uxc-cov-tag">' . $e(__('Armis only', 'impact360')) . '</span>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div></div>';

// ── Asset list ───────────────────────────────────────────────────────────
echo '<div class="uxc-cov-card">';
echo '<div class="uxc-cov-head">';
echo '<div class="uxc-cov-title">' . $e(sprintf(_n('%d asset', '%d assets', $shownCount, 'impact360'), $shownCount));
if ($f['view'] !== 'all') {
    echo ' <a class="uxc-cov-clear" href="' . $e($url([])) . '">' . $e(__('clear filter', 'impact360')) . '</a>';
}
echo '</div>';
$exportParams = array_filter([
    'itemtype' => $f['itemtype'], 'q' => $f['q'],
    'view' => $f['view'] === 'all' ? '' : $f['view'], 'src' => $f['src'], 'p' => $f['p'],
], static fn($v): bool => $v !== '');
echo '<a class="btn btn-outline-secondary btn-sm" href="' . $e($CFG_GLPI['root_doc'] . '/plugins/impact360/front/coverage_export.php'
    . ($exportParams === [] ? '' : '?' . http_build_query($exportParams))) . '">'
    . '<i class="ti ti-file-spreadsheet me-1"></i>' . $e(__('Export to Excel', 'impact360')) . '</a>';
echo '</div>';

if ($page === []) {
    echo '<div class="uxc-muted">' . $e(__('No asset matches this filter.', 'impact360')) . '</div>';
} else {
    echo '<div class="uxc-cov-tablewrap"><table class="uxc-cov-table uxc-cov-assets"><thead><tr>';
    echo '<th>' . $e(__('Asset', 'impact360')) . '</th><th>' . $e(__('Type', 'impact360')) . '</th><th>'
        . $e(__('Entity', 'impact360')) . '</th>';
    foreach ($sources as $src) {
        echo '<th class="c">' . $e($src['label']) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($page as $r) {
        $name = $r['name'] !== '' ? $r['name'] : sprintf('#%d', $r['id']);
        $link = method_exists($r['t'], 'getFormURLWithID') ? $r['t']::getFormURLWithID($r['id']) : null;
        echo '<tr>';
        echo '<td>' . ($link !== null ? '<a href="' . $e($link) . '">' . $e($name) . '</a>' : $e($name)) . '</td>';
        echo '<td>' . $e(CoverageMatrix::typeName($r['t'])) . '</td>';
        echo '<td class="uxc-muted">' . $e(CoverageMatrix::entityName($r['e'])) . '</td>';
        foreach ($sources as $k => $src) {
            $st      = $r['s'][$k];
            $lastTs  = $data['last'][$k][$r['t'] . '#' . $r['id']] ?? null;
            $title   = CoverageMatrix::stateLabel($st, $src)
                . ($lastTs !== null ? ' — ' . sprintf(__('last seen %s', 'impact360'), substr($lastTs, 0, 10)) : '');
            $glyph = match ($st) {
                CoverageMatrix::SEEN       => '<i class="ti ti-circle-check-filled"></i>',
                CoverageMatrix::STALE      => '<i class="ti ti-clock-exclamation"></i>',
                CoverageMatrix::DISCOVERED => '<i class="ti ti-shield-off"></i>',
                CoverageMatrix::NOT_FOUND  => '<i class="ti ti-circle-x"></i>',
                default                    => '<i class="ti ti-minus"></i>',
            };
            echo '<td class="c uxc-cov-st st-' . $e(strtolower($st === '-' ? 'u' : $st)) . '" title="' . $e($title) . '">'
                . $glyph . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // Pager.
    if ($shownCount > CoverageMatrix::PAGE_SIZE) {
        $base = ['view' => $f['view'] === 'all' ? '' : $f['view'], 'src' => $f['src'], 'p' => $f['p']];
        echo '<div class="uxc-cov-pager">';
        if ($start > 0) {
            echo '<a class="btn btn-outline-secondary btn-sm" href="' . $e($url($base + ['start' => max(0, $start - CoverageMatrix::PAGE_SIZE)])) . '">‹ '
                . $e(__('Previous', 'impact360')) . '</a>';
        }
        echo '<span class="uxc-muted">' . $e(sprintf(__('%1$d–%2$d of %3$d', 'impact360'),
            $start + 1, min($start + CoverageMatrix::PAGE_SIZE, $shownCount), $shownCount)) . '</span>';
        if ($start + CoverageMatrix::PAGE_SIZE < $shownCount) {
            echo '<a class="btn btn-outline-secondary btn-sm" href="' . $e($url($base + ['start' => $start + CoverageMatrix::PAGE_SIZE])) . '">'
                . $e(__('Next', 'impact360')) . ' ›</a>';
        }
        echo '</div>';
    }
}

// Legend.
echo '<div class="uxc-cov-legend uxc-muted">'
    . '<span class="uxc-cov-st st-s"><i class="ti ti-circle-check-filled"></i></span> ' . $e(__('seen', 'impact360'))
    . ' &nbsp; <span class="uxc-cov-st st-a"><i class="ti ti-circle-x"></i></span> ' . $e(__('checked, not found / no agent', 'impact360'))
    . ' &nbsp; <span class="uxc-cov-st st-t"><i class="ti ti-clock-exclamation"></i></span> '
    . $e(sprintf(
        __('stale — agent silent > %1$d days, Armis/Defender not seen > %1$d days, Nexpose not scanned > %2$d days', 'impact360'),
        CoverageMatrix::AGENT_STALE_DAYS,
        (int) CoverageMatrix::SOURCES['nexpose']['stale_days']
    ))
    . ' &nbsp; <span class="uxc-cov-st st-d"><i class="ti ti-shield-off"></i></span> '
    . $e(__('Defender: discovered on the network, not onboarded (no EDR)', 'impact360'))
    . ' &nbsp; <span class="uxc-cov-st st-u"><i class="ti ti-minus"></i></span> ' . $e(__('never checked by that source', 'impact360'))
    . '. ' . $e(__('Only "seen" counts as covered.', 'impact360'))
    . '</div>';
echo '</div>'; // card

echo '</div>'; // container

// The "open list" link sits inside a tile <a>; nested anchors are invalid HTML,
// so it's a span navigated on click.
echo Html::scriptBlock(
    "document.querySelectorAll('.uxc-cov .uxc-cov-link[data-href]').forEach(function (el) {"
    . "el.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation();"
    . " window.location.href = el.getAttribute('data-href'); });"
    . "});"
);

Html::footer();
