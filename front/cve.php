<?php

/**
 * Impact360 - CVE lookup: which assets are open for one CVE
 *
 * Enter a CVE id, see every asset currently open (vulnerable) for it — across
 * every itemtype the scanners cover (Computer, NetworkEquipment, Phone,
 * Printer, OT/custom asset types) — with a per-source (Nexpose/Defender/
 * Armis) Yes/No column. Membership is not uniform, so this is a per-asset,
 * per-source view, not a single count. A user missing a source's right never
 * sees that source's column or data (CveExposure::usableSources() fails
 * closed).
 *
 * Deliberately minimal — no fleet-wide "every CVE" listing, no severity/
 * status/present-absent filters. See src/CveExposure.php's class docblock:
 * the earlier fleet-aggregate version hit MySQL's temp-table capacity in
 * production at real data volume, so the aggregate was removed rather than
 * kept around unused.
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\CveExposure;

include('../../../inc/includes.php');

global $CFG_GLPI;

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
$self     = $CFG_GLPI['root_doc'] . '/plugins/impact360/front/cve.php';

Html::header(__('CVE Exposure', 'impact360'), $_SERVER['PHP_SELF'], 'plugins', 'impact360');

echo '<link rel="stylesheet" type="text/css" href="'
    . $CFG_GLPI['root_doc']
    . '/plugins/impact360/public/css/cve.css?v=' . PLUGIN_IMPACT360_VERSION . '">';

// Source display order + labels, restricted to what THIS session can read.
$sourceLabels = [];
foreach (CveExposure::SOURCES as $key => $src) {
    if (isset($usable[$key])) {
        $sourceLabels[$key] = $src['label'];
    }
}

echo '<div class="container-fluid mt-3 uxc-cve-board">';
echo '<div class="d-flex align-items-center mb-3">';
echo '<i class="ti ti-bug me-2" style="font-size:1.6rem"></i>';
echo '<h2 class="m-0">' . __('CVE Exposure', 'impact360') . '</h2>';
echo '</div>';

// ── Search ──────────────────────────────────────────────────────────────
echo '<form method="get" action="' . htmlspecialchars($self, ENT_QUOTES, 'UTF-8') . '" class="uxc-card mb-3">';
echo '<div class="row g-2 align-items-end">';
echo '<div class="col-auto">';
echo '<label class="form-label small mb-1">' . __('CVE', 'impact360') . '</label>';
echo '<input type="text" class="form-control form-control-sm" name="cve" style="width:16rem" value="'
    . htmlspecialchars($cveParam, ENT_QUOTES, 'UTF-8') . '" placeholder="CVE-2026-28387" autofocus>';
echo '</div>';
echo '<div class="col-auto">';
echo '<button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-search me-1"></i>'
    . __('Search', 'impact360') . '</button>';
echo '</div>';
echo '</div>';
echo '</form>';

if ($cveParam === '') {
    echo '<div class="uxc-card"><div class="uxc-muted">'
        . __('Enter a CVE id to see which assets currently have it open.', 'impact360')
        . '</div></div>';
    echo '</div>';
    Html::footer();
    exit;
}

$assets = CveExposure::assetsFor($cveParam);

echo '<div class="uxc-card">';
echo '<div class="uxc-card-head">';
echo '<div class="uxc-card-title">' . htmlspecialchars($cveParam, ENT_QUOTES, 'UTF-8')
    . ' <span class="uxc-muted">'
    . sprintf(_n('%d asset', '%d assets', count($assets), 'impact360'), count($assets))
    . '</span></div>';
$exportUrl = $CFG_GLPI['root_doc'] . '/plugins/impact360/front/cve_fleet_export.php?cve=' . rawurlencode($cveParam);
echo '<a class="uxc-btn uxc-btn-sm" href="' . htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') . '">'
    . '<i class="ti ti-file-spreadsheet"></i> ' . __('Export to Excel', 'impact360') . '</a>';
echo '</div>';

if ($assets === []) {
    echo '<div class="uxc-muted">' . __('No asset currently has this CVE open.', 'impact360') . '</div>';
} else {
    echo '<div class="uxc-table-wrap"><table class="uxc-table uxc-cve-assets">';
    echo '<thead><tr>';
    echo '<th>' . __('Asset', 'impact360') . '</th>';
    echo '<th>' . __('Type', 'impact360') . '</th>';
    foreach ($sourceLabels as $label) {
        echo '<th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($assets as $row) {
        $itemtype = $row['itemtype'];
        $itemsId  = $row['items_id'];
        $name     = $row['name'] !== '' ? $row['name'] : sprintf('#%d', $itemsId);

        $link = null;
        if (class_exists($itemtype)) {
            try {
                $item = new $itemtype();
                if ($item->getFromDB($itemsId) && $item->can($itemsId, READ)) {
                    $link = $itemtype::getFormURLWithID($itemsId);
                }
            } catch (\Throwable $e) {
                $link = null;
            }
        }

        echo '<tr>';
        echo '<td>';
        if ($link !== null) {
            echo '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
        } else {
            echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        }
        echo '</td>';
        echo '<td>' . htmlspecialchars(
            method_exists($itemtype, 'getTypeName') ? $itemtype::getTypeName(1) : $itemtype,
            ENT_QUOTES,
            'UTF-8'
        ) . '</td>';

        foreach (array_keys($sourceLabels) as $srcKey) {
            $has = isset($row['sources'][$srcKey]);
            echo '<td class="' . ($has ? 'uxc-src-yes' : 'uxc-src-no') . '">'
                . ($has ? __('Yes', 'impact360') : __('No', 'impact360')) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
echo '</div>'; // card

echo '</div>'; // container

Html::footer();
