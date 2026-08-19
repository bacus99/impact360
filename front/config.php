<?php

/**
 * Impact360 - configuration page
 *
 * Computer Dashboard health settings: choose which signals count toward the
 * health roll-up (ok/issue), and set their thresholds. Stored via
 * ComputerDashboard::saveHealthSettings (Config key 'dashboard_health').
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\ComputerDashboard;

include('../../../inc/includes.php');

global $CFG_GLPI, $DB;

$plugin = new Plugin();
if (!$plugin->isInstalled('impact360') || !$plugin->isActivated('impact360')) {
    Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

// ── Save (CSRF handled by GLPI 11's CheckCsrfListener middleware) ──────────
if (isset($_POST['save_health'])) {
    ComputerDashboard::saveHealthSettings([
        'connectivity'     => isset($_POST['chk_connectivity']),
        'antivirus'        => isset($_POST['chk_antivirus']),
        'av_uptodate'      => isset($_POST['chk_av_uptodate']),
        'tickets'          => isset($_POST['chk_tickets']),
        'os'               => isset($_POST['chk_os']),
        'retention'        => isset($_POST['chk_retention']),
        'vulns'            => isset($_POST['chk_vulns']),
        'agent_days'       => $_POST['agent_days']       ?? 2,
        'max_open_tickets' => $_POST['max_open_tickets'] ?? 0,
    ]);
    Session::addMessageAfterRedirect(__('Health settings saved.', 'impact360'), true, INFO);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/impact360/front/config.php');
}

if (isset($_POST['save_modules'])) {
    Config::setModuleEnabled('cve', isset($_POST['chk_module_cve']));
    Session::addMessageAfterRedirect(__('Module settings saved.', 'impact360'), true, INFO);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/impact360/front/config.php');
}

Html::header(__('Impact360', 'impact360'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$hs   = ComputerDashboard::healthSettings();
$self = $CFG_GLPI['root_doc'] . '/plugins/impact360/front/config.php';

// Each toggle row: [key, label, help]
$toggles = [
    ['connectivity', __('Connectivity (agent reported recently)', 'impact360'), ''],
    ['antivirus',    __('Antivirus active', 'impact360'), ''],
    ['av_uptodate',  __('Antivirus signatures up to date', 'impact360'), ''],
    ['tickets',      __('Open tickets within threshold', 'impact360'), ''],
    ['os',           __('Operating system inventoried', 'impact360'), ''],
    ['retention',    __('Within asset retention period', 'impact360'),
        __('Requires the uxcustomizer plugin (Lifecycle / retention policy).', 'impact360')],
    ['vulns',        __('No critical vulnerabilities', 'impact360'),
        __('Requires the nexposesync plugin. Also drives the Impact Map / Application Health vulnerability tinting.', 'impact360')],
];

echo '<div class="container-fluid mt-3" style="max-width:760px">';
echo '<div class="d-flex align-items-center mb-3">';
echo '<i class="ti ti-heart-rate-monitor me-2" style="font-size:1.5rem"></i>';
echo '<h2 class="m-0">' . __('Computer Dashboard — health checks', 'impact360') . '</h2>';
echo '</div>';
echo '<p class="text-muted">' . __('Pick which signals count toward a computer\'s health roll-up, and set their thresholds. Disabled checks are ignored (not counted as failing).', 'impact360') . '</p>';

// ── Modules ──────────────────────────────────────────────────────────────
echo '<form method="post" action="' . htmlspecialchars($self, ENT_QUOTES, 'UTF-8') . '" class="mb-3">';
echo '<input type="hidden" name="_glpi_csrf_token" value="'
    . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
echo '<input type="hidden" name="save_modules" value="1">';
echo '<div class="card"><div class="card-body">';
echo '<h5 class="mb-3">' . __('Modules', 'impact360') . '</h5>';
echo '<div class="form-check form-switch mb-2">';
echo '<input class="form-check-input" type="checkbox" role="switch" id="chk_module_cve" name="chk_module_cve"'
    . (Config::isModuleEnabled('cve') ? ' checked' : '') . '>';
echo '<label class="form-check-label" for="chk_module_cve">'
    . htmlspecialchars(__('CVE Exposure board (Plugins → CVE Exposure)', 'impact360'), ENT_QUOTES, 'UTF-8')
    . '</label></div>';
echo '<button type="submit" class="btn btn-primary btn-sm mt-2">'
    . '<i class="ti ti-device-floppy me-1"></i>' . __('Save', 'impact360') . '</button>';
echo '</div></div>'; // card
echo '</form>';

echo '<form method="post" action="' . htmlspecialchars($self, ENT_QUOTES, 'UTF-8') . '">';
echo '<input type="hidden" name="_glpi_csrf_token" value="'
    . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
echo '<input type="hidden" name="save_health" value="1">';

echo '<div class="card"><div class="card-body">';

echo '<h5 class="mb-3">' . __('Checks', 'impact360') . '</h5>';
foreach ($toggles as [$key, $label, $help]) {
    $checked = !empty($hs[$key]) ? ' checked' : '';
    echo '<div class="form-check form-switch mb-2">';
    echo '<input class="form-check-input" type="checkbox" role="switch"'
        . ' id="chk_' . $key . '" name="chk_' . $key . '"' . $checked . '>';
    echo '<label class="form-check-label" for="chk_' . $key . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    if ($help !== '') {
        echo ' <span class="text-muted small">— ' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '</label></div>';
}

echo '<hr>';
echo '<h5 class="mb-3">' . __('Thresholds', 'impact360') . '</h5>';

echo '<div class="row g-3 align-items-end">';
echo '<div class="col-auto">';
echo '<label class="form-label small mb-1" for="agent_days">' . __('Agent considered online within (days)', 'impact360') . '</label>';
echo '<input type="number" min="0" class="form-control form-control-sm" style="width:8rem"'
    . ' id="agent_days" name="agent_days" value="' . (int) $hs['agent_days'] . '">';
echo '</div>';
echo '<div class="col-auto">';
echo '<label class="form-label small mb-1" for="max_open_tickets">' . __('Max open tickets still "ok"', 'impact360') . '</label>';
echo '<input type="number" min="0" class="form-control form-control-sm" style="width:8rem"'
    . ' id="max_open_tickets" name="max_open_tickets" value="' . (int) $hs['max_open_tickets'] . '">';
echo '</div>';
echo '</div>';

echo '<div class="mt-4">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>' . __('Save', 'impact360') . '</button>';
echo '</div>';

echo '</div></div>'; // card
echo '</form>';
echo '</div>';

Html::footer();
