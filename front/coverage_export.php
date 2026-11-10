<?php

/**
 * Impact360 - Coverage-gap matrix CSV export (GET)
 *
 * Same filters as front/coverage.php (CoverageMatrix::filters()), every
 * matching row (no paging). Entity-scoped and fail-closed per source exactly
 * like the board.
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\CoverageMatrix;

// No include of GLPI's inc/includes.php: the kernel has already booted before a plugin script runs (GLPI 11 and 12); the relative path fails when the plugin lives in a marketplace directory outside the GLPI root.

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

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="coverage-gaps_' . date('Ymd') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel

$head = [__('Asset', 'impact360'), __('Type', 'impact360'), __('Entity', 'impact360')];
foreach ($sources as $src) {
    $head[] = $src['label'];
    $head[] = sprintf(__('%s last seen', 'impact360'), $src['label']);
}
$head[] = __('Seen by', 'impact360');
fputcsv($out, $head);

foreach ($scope as $r) {
    $bits = CoverageMatrix::bits($r, $keys);
    if (!CoverageMatrix::matches($bits, $keys, $f['view'], $f['src'], $f['p'])) {
        continue;
    }
    $line = [
        $r['name'] !== '' ? $r['name'] : sprintf('#%d', $r['id']),
        CoverageMatrix::typeName($r['t']),
        CoverageMatrix::entityName($r['e']),
    ];
    $seenBy = [];
    foreach ($sources as $k => $src) {
        $st     = $r['s'][$k];
        $line[] = CoverageMatrix::stateLabel($st, $src);
        $line[] = $data['last'][$k][$r['t'] . '#' . $r['id']] ?? '';
        if ($st === CoverageMatrix::SEEN) {
            $seenBy[] = $src['label'];
        }
    }
    $line[] = $seenBy === [] ? __('None', 'impact360') : implode(' + ', $seenBy);

    // Neutralise spreadsheet formula injection in free-text cells (asset/entity names).
    $line = array_map(static function ($v) {
        $v = (string) $v;
        return ($v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
    }, $line);

    fputcsv($out, $line);
}

fclose($out);
exit;
