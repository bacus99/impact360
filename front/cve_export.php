<?php

/**
 * Impact360 - CVE Exposure CSV export (GET)
 *
 * Downloads the FULL combined Nexpose + Defender CVE list for one computer as
 * CSV (Excel-compatible) — the Dashboard tab's "CVE Exposure" table only shows
 * the top 10; this is the "see everything" escape hatch. Read-only, entity-
 * scoped via Computer::can(READ) (same gate as the Dashboard tab itself).
 *
 * Query params:
 *   computers_id (required) — the Computer to export
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\ComputerDashboard;
use GlpiPlugin\Impact360\Config;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('impact360') || !$plugin->isActivated('impact360')) {
    Html::displayNotFoundError();
}
if (!Config::isModuleEnabled('dashboard')) {
    Html::displayNotFoundError();
}

Session::checkLoginUser();

$computersId = (int) ($_GET['computers_id'] ?? 0);
$computer    = new Computer();
if ($computersId <= 0 || !$computer->getFromDB($computersId) || !$computer->can($computersId, READ)) {
    Html::displayNotFoundError();
}

$cves  = ComputerDashboard::gatherCves($computersId);
$items = $cves['items'] ?? [];

$safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $computer->fields['name']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cve-exposure_' . $safeName . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel doesn't mangle accented characters.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    __('CVE', 'impact360'), __('Title', 'impact360'), __('Severity', 'impact360'),
    __('CVSS', 'impact360'), __('Exploitable', 'impact360'), __('Source(s)', 'impact360'),
    __('Status', 'impact360'), __('First seen', 'impact360'), __('Last seen', 'impact360'),
]);

$sourceLabels = ['nexpose' => __('Nexpose', 'impact360'), 'defender' => __('Defender', 'impact360')];
foreach ($items as $row) {
    $sources = [];
    foreach ($sourceLabels as $key => $label) {
        if (isset($row['sources'][$key])) {
            $sources[] = $label;
        }
    }
    fputcsv($out, [
        $row['cve'],
        $row['title'],
        $row['severity'] !== '' ? ucfirst($row['severity']) : '',
        $row['cvss'],
        $row['exploitable'] ? __('Yes', 'impact360') : __('No', 'impact360'),
        implode(' + ', $sources),
        $row['vulnerable'] ? __('Vulnerable', 'impact360') : __('Remediated', 'impact360'),
        $row['first_seen'] ?? '',
        $row['last_seen'] ?? '',
    ]);
}

fclose($out);
exit;
