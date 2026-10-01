<?php

/**
 * Impact360 - GLPI 11 plugin
 *
 * Interactive dependency / impact map with:
 *   - dagre "flow" layout + progressive expand-on-click (Explore mode);
 *   - application health roll-up on Appliances (dependency-aware);
 *   - an Application Health board ("wall of apps");
 *   - an optional observed-traffic overlay sourced from the netstatconnections
 *     plugin (real TCP/UDP dependencies) — soft dependency, tableExists-guarded.
 *
 * Split out of uxcustomizer (which keeps menu order / palette / tab order /
 * computer dashboard). Shared conventions: ../GLPI-Shared/CLAUDE.md.
 *
 * @license   GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Impact360\ComputerDashboard;
use GlpiPlugin\Impact360\ConfigMenu;
use GlpiPlugin\Impact360\CveMenu;
use GlpiPlugin\Impact360\ImpactMapTab;
use GlpiPlugin\Impact360\Menu;

define('PLUGIN_IMPACT360_VERSION',          '1.2.1');
define('PLUGIN_IMPACT360_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_IMPACT360_MAX_GLPI_VERSION', '11.99.99');

function plugin_init_impact360(): void
{
    global $PLUGIN_HOOKS;

    // Literal key, not Hooks::CSRF_COMPLIANT: GLPI 12 removed that
    // constant, and referencing it is a fatal at plugin load (it
    // surfaces as the misleading "function plugin_<slug>_install is
    // missing"). GLPI 11 and 12 both read the string key identically.
    $PLUGIN_HOOKS['csrf_compliant']['impact360'] = true;

    // Settings page — Computer Dashboard health-check configuration. Reachable
    // both as the wrench icon on Setup → Plugins (config_page) and as a Setup
    // menu entry (menu_toadd 'config' → ConfigMenu). Enforces config UPDATE.
    $PLUGIN_HOOKS['config_page']['impact360'] = 'front/config.php';

    // Menu entries: Application Health board + CVE Exposure board, both under
    // the top-level Plugins menu (their own root entries, not nested under
    // Assets); settings under Setup. 'plugins' accepts an array of classes —
    // verified against GLPI 11.0.8's Html::generateMenuSession(), which does
    // `if (is_array($val)) { foreach ($val as $object) { $menu[$key]['types'][] = $object; } }`.
    $PLUGIN_HOOKS['menu_toadd']['impact360'] = [
        'plugins' => [Menu::class, CveMenu::class],
        'config'  => ConfigMenu::class,
    ];

    // Put the Computer "Dashboard" tab first (client-side — GLPI 11 has no
    // tab-reorder hook). Self-guards to the Computer form; no-op elsewhere.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['impact360'] = ['public/js/dashboard-first.js'];

    // Registering a class touches the autoloader during early boot; wrap
    // defensively so a hiccup can't break Plugin::getPluginInformation.
    try {
        // Interactive "Impact Map" tab on assets (Computer, Appliance) and ITIL
        // objects (Ticket, Change, Problem — seeded by their linked assets).
        // Coexists with GLPI's native "Impact Analysis" tab.
        Plugin::registerClass(ImpactMapTab::class, [
            'addtabon' => array_merge(ImpactMapTab::ITEMTYPES, ImpactMapTab::ITIL_ITEMTYPES),
        ]);

        // "Dashboard" tab on Computer — CI insight (connectivity, antivirus,
        // health checks, software/hardware/lifecycle). Shares the health
        // concept with the impact roll-up, so it lives here too.
        Plugin::registerClass(ComputerDashboard::class, ['addtabon' => ['Computer']]);
    } catch (\Throwable $e) {
        // Early boot / install — skip tab registration.
    }
}

function plugin_version_impact360(): array
{
    return [
        'name'         => 'Impact360',
        'version'      => PLUGIN_IMPACT360_VERSION,
        'author'       => 'Christian Bernard',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bacus99/impact360',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_IMPACT360_MIN_GLPI_VERSION,
                'max' => PLUGIN_IMPACT360_MAX_GLPI_VERSION,
            ],
            'php' => ['min' => '8.1'],
        ],
    ];
}

function plugin_impact360_check_prerequisites(): bool
{
    return true;
}

function plugin_impact360_check_config(bool $verbose = false): bool
{
    return true;
}
