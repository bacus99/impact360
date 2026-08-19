<?php

/**
 * Impact360 - CVE lookup CSV export (GET)
 *
 * Every asset currently open (vulnerable) for one CVE, one row per asset,
 * with a per-source Yes/No column — mirrors front/cve.php exactly
 * (CveExposure::assetsFor()). Read-only; a session missing a source's right
 * never sees that source's data here either (CveExposure::usableSources()
 * fails closed).
 *
 * Query params: cve (required).
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\CveExposure;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('impact360') || !$plugin->isActivated('impact360')) {
    Html::displayNotFoundError();
}
if (!Config::isModuleEnabled('cve')) {
    Html::displayNotFoundError();
}

Session::checkLoginUser();

$usable = CveExposure::usableSources();
if ($usable === []) {
    Html::displayRightError();
}

$cveParam = trim((string) ($_GET['cve'] ?? ''));
if ($cveParam === '') {
    Html::displayNotFoundError();
}

header('Content-Type: text/csv; charset=utf-8');
header(
    'Content-Disposition: attachment; filename="cve-exposure_'
    . preg_replace('/[^A-Za-z0-9_-]/', '_', $cveParam) . '.csv"'
);
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel doesn't mangle accented characters.
fwrite($out, "\xEF\xBB\xBF");

$sourceLabels = [];
foreach (CveExposure::SOURCES as $key => $src) {
    if (isset($usable[$key])) {
        $sourceLabels[$key] = $src['label'];
    }
}

fputcsv($out, array_merge(
    [__('Asset', 'impact360'), __('Type', 'impact360')],
    array_values($sourceLabels),
    [__('Last seen', 'impact360')]
));

foreach (CveExposure::assetsFor($cveParam) as $row) {
    $lastSeen = null;
    foreach ($row['sources'] as $s) {
        if ($s['last_seen'] !== null && ($lastSeen === null || $s['last_seen'] > $lastSeen)) {
            $lastSeen = $s['last_seen'];
        }
    }

    $line = [
        $row['name'] !== '' ? $row['name'] : sprintf('#%d', $row['items_id']),
        method_exists($row['itemtype'], 'getTypeName') ? $row['itemtype']::getTypeName(1) : $row['itemtype'],
    ];
    foreach (array_keys($sourceLabels) as $srcKey) {
        $line[] = isset($row['sources'][$srcKey]) ? __('Yes', 'impact360') : __('No', 'impact360');
    }
    $line[] = $lastSeen ?? '';

    fputcsv($out, $line);
}

fclose($out);
exit;
