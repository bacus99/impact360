<?php

/**
 * UX Customizer - Application Health board ("wall of apps")
 *
 * Every Appliance (business service) rendered as a health tile, worst-first.
 * The SquaredUp landing screen — but grounded in GLPI's CMDB + observed
 * dependencies (roll-up Phase 4).
 *
 * An appliance selector (the same dropdown the webapplications dashboard uses)
 * sits at the top. Selecting an application — or clicking its tile — opens it:
 *   - when the webapplications plugin is active → its dashboard, focused on that
 *     appliance (dashboard.php?appliances_id=…);
 *   - otherwise → the Appliance form's Impact Map tab.
 *
 * Read-only. Entity-scoped (ImpactMap::portfolio) + Appliance READ gate, so a
 * technician sees only the services + health in their entities.
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Impact360\Config;
use GlpiPlugin\Impact360\ImpactMap;

include('../../../inc/includes.php');

global $CFG_GLPI;

$plugin = new Plugin();
if (!$plugin->isInstalled('impact360') || !$plugin->isActivated('impact360')) {
    Html::displayNotFoundError();
}
if (!Config::isModuleEnabled('impactmap')) {
    Html::displayNotFoundError();
}

Session::checkLoginUser();
if (!Appliance::canView()) {
    Html::displayRightError();
}

Html::header(
    __('Application Health', 'impact360'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'impact360'
);

$root     = $CFG_GLPI['root_doc'] ?? '';
$waActive = Plugin::isPluginActive('webapplications');

/**
 * Where clicking an appliance goes:
 *   - webapplications active → its dashboard, focused on that appliance;
 *   - otherwise → the Appliance form's Impact Map tab.
 */
$applianceUrl = static function (int $id) use ($root, $waActive): string {
    if ($waActive) {
        return $root . '/plugins/webapplications/front/dashboard.php?appliances_id=' . $id;
    }
    return $root . '/front/Appliance.form.php?id=' . $id
        . '&forcetab=' . rawurlencode('GlpiPlugin\\Impact360\\ImpactMapTab$1');
};

/** Health level → [color, label, tabler icon]. */
$styleFor = static function (?string $lvl): array {
    switch ($lvl) {
        case 'crit': return ['#d63939', __('Critical', 'impact360'), 'ti-alert-triangle-filled'];
        case 'warn': return ['#f59f00', __('Warning', 'impact360'),  'ti-alert-triangle'];
        case 'ok':   return ['#2fb344', __('Healthy', 'impact360'),   'ti-circle-check'];
        default:     return ['#6b7280', __('No data', 'impact360'),   'ti-help-circle'];
    }
};

$apps = ImpactMap::portfolio();

echo '<div class="container-fluid mt-3">';

// ── Heading ─────────────────────────────────────────────────────────────────
echo '<div class="d-flex align-items-center mb-3">';
echo '<i class="ti ti-heartbeat me-2" style="font-size:1.6rem"></i>';
echo '<h2 class="m-0">' . __('Application Health', 'impact360') . '</h2>';
echo '<span class="text-muted ms-3">'
    . sprintf(_n('%d application', '%d applications', count($apps), 'impact360'), count($apps))
    . '</span>';
echo '</div>';

// ── Appliance selector (mirrors the webapplications dashboard dropdown) ───────
// Selecting an application opens it (dashboard when webapplications is active,
// else the Appliance Impact Map tab) — same destination as clicking its tile.
echo '<div class="mb-3" style="max-width:32rem">';
echo '<label class="form-label small mb-1">' . __('Select an application', 'impact360') . '</label>';
$rand = Appliance::dropdown([
    'name'  => 'applianceDropdown',
    'value' => 0,
    'width' => '100%',
]);
// Build the destination URL client-side (the base + suffix depend on whether
// webapplications is active — decided server-side above).
if ($waActive) {
    $jsUrl = "'" . $root . "/plugins/webapplications/front/dashboard.php?appliances_id=' + v";
} else {
    $jsUrl = "'" . $root . "/front/Appliance.form.php?id=' + v + '&forcetab="
        . rawurlencode('GlpiPlugin\\Impact360\\ImpactMapTab$1') . "'";
}
echo Html::scriptBlock(
    "$(function () {"
    . " $('#dropdown_applianceDropdown{$rand}').on('change', function () {"
    . "   var v = parseInt(this.value, 10) || 0;"
    . "   if (v > 0) { window.location = {$jsUrl}; }"
    . " });"
    . "});"
);
echo '</div>';

if ($apps === []) {
    echo '<div class="alert alert-info">'
        . __('No applications (Appliances) are visible. Group CIs into an Appliance to see its rolled-up health here.', 'impact360')
        . '</div>';
} else {
    echo '<div class="row g-3">';
    foreach ($apps as $a) {
        [$col, $lbl, $ic] = $styleFor($a['level']);
        $openUrl = $applianceUrl((int) $a['id']);

        echo '<div class="col-12 col-sm-6 col-md-4 col-xl-3">';
        echo '<a href="' . htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="card h-100 text-decoration-none text-reset"'
            . ' style="border-left:5px solid ' . $col . '">';
        echo '<div class="card-body">';
        echo '<div class="d-flex align-items-center mb-1">';
        echo '<i class="ti ' . $ic . ' me-2" style="color:' . $col . ';font-size:1.25rem"></i>';
        echo '<strong style="color:' . $col . '">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</strong>';
        echo '</div>';
        echo '<div class="fw-bold text-truncate" title="' . htmlspecialchars($a['name'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($a['name'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="text-muted small mt-1" title="'
            . htmlspecialchars(
                __('Degraded = open ticket(s) on the CI and/or its agent silent for more than 2 days. Both at once = critical.', 'impact360'),
                ENT_QUOTES,
                'UTF-8'
            ) . '">'
            . sprintf(__('%1$d of %2$d components degraded', 'impact360'), (int) $a['degraded'], (int) $a['total'])
            . '</div>';
        if (!empty($a['deps_degraded'])) {
            echo '<div class="small" style="color:' . $col . '">'
                . sprintf(
                    _n('%d dependency at risk', '%d dependencies at risk', (int) $a['deps_degraded'], 'impact360'),
                    (int) $a['deps_degraded']
                )
                . '</div>';
        }
        echo '</div>';   // card-body
        echo '</a>';
        echo '</div>';   // col
    }
    echo '</div>';       // row
}

echo '</div>';           // container

Html::footer();
