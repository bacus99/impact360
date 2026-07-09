<?php

/**
 * UX Customizer - Computer Dashboard module
 *
 * Adds a "Dashboard" tab to the Computer form showing a card-based CI overview.
 * It is an ADDITIVE tab — GLPI's native form and all its tabs stay intact (this
 * replaces an earlier pre_show_item approach that replaced the whole form).
 *
 * Registered in setup.php via:
 *   Plugin::registerClass(ComputerDashboard::class, ['addtabon' => ['Computer']]);
 * (There is no $PLUGIN_HOOKS['tabs'] hook — addtabon + registerClass is the
 * GLPI 11 mechanism. The tab lands AFTER core tabs; use the Tab Order module to
 * move it to the top.)
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use CommonGLPI;
use Computer;
use Dropdown;

class ComputerDashboard extends CommonGLPI
{
    /** Tab label shown on the Computer form. */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Computer) || $item->isNewItem()) {
            return '';
        }
        if (!\GlpiPlugin\Impact360\Config::isModuleEnabled('dashboard')) {
            return '';
        }
        return self::createTabEntry(__('Dashboard', 'impact360'), 0, $item->getType(), 'ti ti-layout-dashboard');
    }

    /** Render the dashboard card view inside the tab. */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof Computer) || $item->isNewItem()) {
            return false;
        }

        global $CFG_GLPI;

        // Scoped dashboard stylesheet (browser-fetched → MUST be under public/).
        echo '<link rel="stylesheet" type="text/css" href="'
            . $CFG_GLPI['root_doc']
            . '/plugins/impact360/public/css/dashboard.css?v=' . PLUGIN_IMPACT360_VERSION . '">';

        $data = self::gatherData($item);   // [$data] is used by the template
        include __DIR__ . '/../templates/computer_dashboard.html.php';

        return true;
    }

    /**
     * Map an OS name to a Tabler brand-icon class + a CSS tone class, used in
     * the consolidated System-info card. Returns ['icon' => 'ti …', 'tone' => '…'].
     * Falls back to a generic device icon so the row never looks broken.
     */
    public static function osIcon(string $osName): array
    {
        $s = strtolower($osName);
        // Tabler brand glyphs ship with GLPI 11. Where no brand-specific Tabler
        // glyph exists, use the closest fit.
        if (str_contains($s, 'windows'))                                                  { return ['icon' => 'ti ti-brand-windows',  'tone' => 'uxc-os-windows']; }
        if (str_contains($s, 'red hat') || str_contains($s, 'rhel'))                      { return ['icon' => 'ti ti-brand-redhat',   'tone' => 'uxc-os-redhat']; }
        if (str_contains($s, 'ubuntu'))                                                   { return ['icon' => 'ti ti-brand-ubuntu',   'tone' => 'uxc-os-ubuntu']; }
        if (str_contains($s, 'debian'))                                                   { return ['icon' => 'ti ti-brand-debian',   'tone' => 'uxc-os-debian']; }
        if (str_contains($s, 'mac') || str_contains($s, 'os x') || str_contains($s, 'macos'))
                                                                                          { return ['icon' => 'ti ti-brand-apple',    'tone' => 'uxc-os-apple']; }
        if (str_contains($s, 'android'))                                                  { return ['icon' => 'ti ti-brand-android',  'tone' => 'uxc-os-android']; }
        if (str_contains($s, 'ios'))                                                      { return ['icon' => 'ti ti-brand-apple',    'tone' => 'uxc-os-apple']; }
        if (str_contains($s, 'fedora') || str_contains($s, 'centos') || str_contains($s, 'rocky') || str_contains($s, 'alma'))
                                                                                          { return ['icon' => 'ti ti-brand-redhat',   'tone' => 'uxc-os-redhat']; }
        if (str_contains($s, 'suse') || str_contains($s, 'opensuse'))                     { return ['icon' => 'ti ti-brand-opensuse', 'tone' => 'uxc-os-suse']; }
        if (str_contains($s, 'linux'))                                                    { return ['icon' => 'ti ti-brand-tux',      'tone' => 'uxc-os-linux']; }
        if (str_contains($s, 'esxi') || str_contains($s, 'vmware'))                       { return ['icon' => 'ti ti-server',         'tone' => 'uxc-os-generic']; }
        return ['icon' => 'ti ti-device-desktop', 'tone' => 'uxc-os-generic'];
    }

    /**
     * Health-check settings: which signals count toward the dashboard health
     * roll-up, plus thresholds. Stored as JSON in Config('dashboard_health'),
     * merged over defaults so a fresh install behaves sensibly. Editable on the
     * plugin's config page (Setup → Plugins → Impact360).
     *
     * @return array{connectivity:bool,antivirus:bool,av_uptodate:bool,tickets:bool,os:bool,retention:bool,agent_days:int,max_open_tickets:int}
     */
    public static function healthSettings(): array
    {
        $defaults = [
            'connectivity'     => true,
            'antivirus'        => true,
            'av_uptodate'      => true,
            'tickets'          => true,
            'os'               => true,
            'retention'        => true,
            'vulns'            => true,   // no critical vulns (nexposesync)
            'agent_days'       => 2,
            'max_open_tickets' => 0,
        ];
        $raw    = Config::get('dashboard_health');
        $stored = $raw !== null ? json_decode($raw, true) : null;
        return is_array($stored) ? array_merge($defaults, $stored) : $defaults;
    }

    /** Persist (sanitised) health-check settings. */
    public static function saveHealthSettings(array $s): bool
    {
        $clean = [
            'connectivity'     => !empty($s['connectivity']),
            'antivirus'        => !empty($s['antivirus']),
            'av_uptodate'      => !empty($s['av_uptodate']),
            'tickets'          => !empty($s['tickets']),
            'os'               => !empty($s['os']),
            'retention'        => !empty($s['retention']),
            'vulns'            => !empty($s['vulns']),
            'agent_days'       => max(0, (int) ($s['agent_days'] ?? 2)),
            'max_open_tickets' => max(0, (int) ($s['max_open_tickets'] ?? 0)),
        ];
        return Config::set('dashboard_health', json_encode($clean));
    }

    /**
     * Collect the dashboard data from existing GLPI tables (no new schema).
     *
     * NOTE (porting from lcornoc02): the detailed/inventory-derived fields —
     * connectivity (agent last-contact), antivirus, firewall, health checks,
     * uptime, unlicensed-software count, pending reboot, WSUS, custom fields,
     * tags — have a richer mapping in the lcornoc02 ComputerDashboard.php.
     * Drop that logic in where marked `TODO(lcornoc02)`. Everything here is
     * defensive so the tab always renders even when a source is empty.
     *
     * @return array<string,mixed>
     */
    private static function gatherData(Computer $c): array
    {
        global $DB, $CFG_GLPI;

        $id = (int) $c->getID();
        $f  = $c->fields;

        $name = function (string $table, $fk) {
            $fk = (int) $fk;
            return $fk > 0 ? Dropdown::getDropdownName($table, $fk) : '—';
        };

        // Owner: prefer the assigned user, else the group.
        $owner = '—';
        if (!empty($f['users_id'])) {
            $owner = $name('glpi_users', $f['users_id']);
        } elseif (!empty($f['groups_id'])) {
            $owner = $name('glpi_groups', $f['groups_id']);
        }

        // Operating system (Item_OperatingSystem relation).
        $os = ['name' => '—', 'version' => '—', 'install_date' => null];
        foreach ($DB->request([
            'SELECT' => ['operatingsystems_id', 'operatingsystemversions_id', 'install_date'],
            'FROM'   => 'glpi_items_operatingsystems',
            'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $id],
            'LIMIT'  => 1,
        ]) as $row) {
            $os['name']         = $name('glpi_operatingsystems', $row['operatingsystems_id']);
            $os['version']      = $name('glpi_operatingsystemversions', $row['operatingsystemversions_id']);
            $os['install_date'] = $row['install_date'] ?? null;
        }

        $softwareInstalled = (int) countElementsInTable('glpi_items_softwareversions',
            ['itemtype' => 'Computer', 'items_id' => $id]);

        // Configurable health: which signals count + thresholds.
        $hs = self::healthSettings();

        // ── Connectivity: native GLPI inventory agent (glpi_agents) ──
        $conn = ['ok' => null, 'label' => __('Connectivity', 'impact360'), 'detail' => __('No agent data', 'impact360')];
        try {
            if ($DB->tableExists('glpi_agents')) {
                foreach ($DB->request([
                    'FROM'  => 'glpi_agents',
                    'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id],
                    'ORDER' => ['last_contact DESC'],
                    'LIMIT' => 1,
                ]) as $a) {
                    $last = $a['last_contact'] ?? null;
                    $ver  = trim((string) ($a['version'] ?? ''));
                    if (!empty($last) && $last !== 'NULL') {
                        $days = (int) floor((time() - strtotime((string) $last)) / 86400);
                        $conn['ok']    = $days <= (int) $hs['agent_days'];
                        $conn['label'] = $conn['ok'] ? __('Connectivity online', 'impact360') : __('Connectivity offline', 'impact360');
                        $seen = $days <= 0 ? __('today', 'impact360') : sprintf(_n('%d day ago', '%d days ago', $days, 'impact360'), $days);
                        $conn['detail'] = trim(($ver !== '' ? __('Agent', 'impact360') . ' ' . $ver . ' — ' : '') . __('last seen', 'impact360') . ' ' . $seen);
                    } else {
                        $conn['detail'] = __('Agent present, never reported', 'impact360');
                    }
                }
            }
        } catch (\Throwable $e) { /* keep placeholder */ }

        // ── Antivirus: native inventory (glpi_items_antiviruses) ──
        $av = ['ok' => null, 'label' => __('Antivirus', 'impact360'), 'detail' => __('No antivirus reported', 'impact360')];
        $avUpToDate = false;
        try {
            // GLPI 11 ItemAntivirus table is `glpi_itemantiviruses` (no "items_").
            if ($DB->tableExists('glpi_itemantiviruses')) {
                foreach ($DB->request([
                    'FROM'  => 'glpi_itemantiviruses',
                    'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id],
                    'ORDER' => ['is_active DESC'],
                    'LIMIT' => 1,
                ]) as $row) {
                    $active      = !empty($row['is_active']);
                    $avUpToDate  = !empty($row['is_uptodate']);
                    $av['ok']    = $active;
                    $av['label'] = $active ? __('Antivirus enabled', 'impact360') : __('Antivirus disabled', 'impact360');
                    $nm          = trim((string) ($row['name'] ?? ''));
                    $av['detail'] = $nm !== '' ? $nm : '—';
                }
            }
        } catch (\Throwable $e) { /* keep placeholder */ }

        // ── Tickets: breakdown by status (join glpi_tickets) ──
        $ticketsLinked = (int) countElementsInTable('glpi_items_tickets', ['itemtype' => 'Computer', 'items_id' => $id]);
        $tOpen = 0; $tPending = 0;
        try {
            foreach ($DB->request([
                'SELECT'     => ['glpi_tickets.status AS status'],
                'FROM'       => 'glpi_items_tickets',
                'INNER JOIN' => ['glpi_tickets' => ['ON' => ['glpi_items_tickets' => 'tickets_id', 'glpi_tickets' => 'id']]],
                'WHERE'      => ['glpi_items_tickets.itemtype' => 'Computer', 'glpi_items_tickets.items_id' => $id],
            ]) as $t) {
                $s = (int) $t['status'];
                if (in_array($s, [1, 2, 3, 4], true)) { $tOpen++; }   // new / assigned / planned / waiting
                if ($s === 4) { $tPending++; }                         // waiting
            }
        } catch (\Throwable $e) { $tOpen = null; $tPending = null; }

        // ── Contracts: type + summed cost ──
        $contractsLinked = (int) countElementsInTable('glpi_contracts_items', ['itemtype' => 'Computer', 'items_id' => $id]);
        $cType = '—'; $cValue = null;
        try {
            foreach ($DB->request([
                'SELECT'     => ['glpi_contracts.id AS cid', 'glpi_contracts.contracttypes_id AS ctype'],
                'FROM'       => 'glpi_contracts_items',
                'INNER JOIN' => ['glpi_contracts' => ['ON' => ['glpi_contracts_items' => 'contracts_id', 'glpi_contracts' => 'id']]],
                'WHERE'      => ['glpi_contracts_items.itemtype' => 'Computer', 'glpi_contracts_items.items_id' => $id],
                'ORDER'      => ['glpi_contracts.id DESC'],
                'LIMIT'      => 1,
            ]) as $row) {
                $cType = $name('glpi_contracttypes', $row['ctype']);
                if ($DB->tableExists('glpi_contractcosts')) {
                    foreach ($DB->request(['SELECT' => ['cost'], 'FROM' => 'glpi_contractcosts', 'WHERE' => ['contracts_id' => $row['cid']]]) as $cc) {
                        $cValue = (float) ($cValue ?? 0) + (float) $cc['cost'];
                    }
                }
            }
        } catch (\Throwable $e) { /* keep defaults */ }
        $cValueStr = $cValue !== null ? ('$' . number_format($cValue, 2)) : null;

        // Serial + Last inventory move under Hardware (no separate Details
        // section). Description/Inventory number are dropped from the dashboard
        // — they're still visible on GLPI's main form.
        $serial         = !empty($f['serial']) ? (string) $f['serial'] : null;
        $lastInventory  = !empty($f['last_inventory_update']) ? substr((string) $f['last_inventory_update'], 0, 16) : null;

        // ── Lifecycle: purchase / warranty (Infocom) + retention policy ──
        $buyDate = null; $warrantyMonths = null; $warrantyEnd = null;
        try {
            if ($DB->tableExists('glpi_infocoms')) {
                foreach ($DB->request(['FROM' => 'glpi_infocoms', 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id], 'LIMIT' => 1]) as $ic) {
                    $buyDate        = !empty($ic['buy_date']) ? $ic['buy_date'] : ($ic['use_date'] ?? null);
                    $warrantyMonths = isset($ic['warranty_duration']) ? (int) $ic['warranty_duration'] : null;
                    $wStart         = !empty($ic['warranty_date']) ? $ic['warranty_date'] : $buyDate;
                    if (!empty($wStart) && $warrantyMonths !== null && $warrantyMonths > 0) {
                        $warrantyEnd = date('Y-m-d', strtotime($wStart . ' +' . $warrantyMonths . ' months'));
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Asset retention lives in uxcustomizer (Lifecycle); consume it only
        // when that plugin is active. Absent → $retYears stays 0 and the
        // retirement date is simply not shown (the block below is gated on > 0).
        $retYears = 0;
        if (\Plugin::isPluginActive('uxcustomizer')
            && class_exists('\\GlpiPlugin\\Uxcustomizer\\Lifecycle')) {
            $retYears = (int) \GlpiPlugin\Uxcustomizer\Lifecycle::yearsForType((int) ($f['computertypes_id'] ?? 0));
        }
        $retireDate = null; $remaining = null; $overdue = false;
        if (!empty($buyDate) && $retYears > 0) {
            $retireDate = date('Y-m-d', strtotime($buyDate . ' +' . $retYears . ' years'));
            $months     = (int) floor((strtotime($retireDate) - time()) / (30 * 86400));
            $overdue    = $months < 0;
            if ($overdue) {
                $remaining = sprintf(__('Overdue by %d months', 'impact360'), abs($months));
            } else {
                $y = intdiv($months, 12); $m = $months % 12;
                $remaining = $y > 0 ? sprintf(__('%1$d y %2$d m left', 'impact360'), $y, $m)
                                    : sprintf(__('%d months left', 'impact360'), $m);
            }
        }
        $lifecycle = [
            'buy_date'        => $buyDate,
            'warranty_months' => $warrantyMonths,
            'warranty_end'    => $warrantyEnd,
            'retention_years' => $retYears,
            'retire_date'     => $retireDate,
            'remaining'       => $remaining,
            'overdue'         => $overdue,
        ];

        // ── Security: Nexpose exposure (impact360 consumes nexposesync) ──
        // Provider guard mirrors the Lifecycle precedent above. nexposesync uses
        // legacy PluginNexposesync* class naming (no GlpiPlugin\ namespace), so
        // the guard is class_exists('PluginNexposesyncExposure').
        //
        // forComputer() returns null when the host has NO Nexpose match — shown
        // as grey "unknown", explicitly NOT "secure" (the unmatched-CI trap).
        $security = null;
        if (\Plugin::isPluginActive('nexposesync') && class_exists('PluginNexposesyncExposure')) {
            $exp = \PluginNexposesyncExposure::forComputer($id);
            if ($exp === null) {
                // Never assessed by the sync — distinct from "checked, no match".
                $security = [
                    'level'  => 'unknown',
                    'ok'     => null,
                    'label'  => __('Security: not synced yet', 'impact360'),
                    'detail' => __('Nexpose sync has not assessed this computer', 'impact360'),
                ];
            } elseif (($exp['match_status'] ?? 'matched') === 'unmatched') {
                $security = [
                    'level'  => 'unknown',
                    'ok'     => null,
                    'label'  => __('Security: no Nexpose match', 'impact360'),
                    'detail' => __('Not found in Nexpose by hostname', 'impact360'),
                ];
            } else {
                $crit = (int) $exp['critical'];
                $sev  = (int) $exp['severe'];
                $mod  = (int) $exp['moderate'];
                $expl = (int) $exp['exploits'];

                if ($crit > 0 || $expl > 0) {
                    $level = 'bad';
                    $label = __('Security: critical exposure', 'impact360');
                } elseif ($sev > 0) {
                    $level = 'warn';
                    $label = __('Security: exposed', 'impact360');
                } else {
                    $level = 'ok';
                    $label = __('Security: no critical vulns', 'impact360');
                }

                $parts = [];
                if ($crit > 0) { $parts[] = sprintf(__('%d critical', 'impact360'), $crit); }
                if ($sev  > 0) { $parts[] = sprintf(__('%d severe', 'impact360'), $sev); }
                if ($mod  > 0) { $parts[] = sprintf(__('%d moderate', 'impact360'), $mod); }
                if ($expl > 0) { $parts[] = sprintf(__('%d exploitable', 'impact360'), $expl); }
                $detail = $parts !== [] ? implode(' · ', $parts) : __('No known vulnerabilities', 'impact360');

                if (!empty($exp['last_scan'])) {
                    $sdays = (int) floor((time() - strtotime((string) $exp['last_scan'])) / 86400);
                    $sseen = $sdays <= 0
                        ? __('today', 'impact360')
                        : sprintf(_n('%d day ago', '%d days ago', $sdays, 'impact360'), $sdays);
                    $detail .= ' — ' . __('scanned', 'impact360') . ' ' . $sseen;
                } else {
                    $detail .= ' — ' . __('never scanned', 'impact360');
                }

                if (($exp['match_status'] ?? 'matched') === 'ambiguous') {
                    $detail .= ' · ' . __('ambiguous hostname match', 'impact360');
                }

                $security = [
                    'level'  => $level,
                    'ok'     => $level === 'ok' ? true : ($level === 'bad' ? false : null),
                    'label'  => $label,
                    'detail' => $detail,
                ];
            }
        }

        // ── Health: only the signals enabled in the config count toward the
        //    roll-up, with configurable thresholds (Setup → Plugins → Impact360).
        //    (Computed after the Security block so the vulns check can use it.)
        $checks = [];
        if ($hs['connectivity']) { $checks[] = $conn['ok'] === true; }              // agent seen recently
        if ($hs['antivirus'])    { $checks[] = $av['ok'] === true; }                // antivirus active
        if ($hs['av_uptodate'])  { $checks[] = (bool) $avUpToDate; }                // antivirus up to date
        if ($hs['tickets'])      { $checks[] = ($tOpen !== null && $tOpen <= (int) $hs['max_open_tickets']); }
        if ($hs['os'])           { $checks[] = $os['name'] !== '—'; }               // OS inventoried
        // Retention is only meaningful when uxcustomizer's Lifecycle supplied a
        // retirement date ($retireDate !== null).
        if ($hs['retention'] && $retireDate !== null) {
            $checks[] = !$overdue;
        }
        // "No critical vulns" (nexposesync): only counted when there is a real
        // signal — never-synced/unmatched computers are skipped, not failed.
        if ($hs['vulns'] && $security !== null && ($security['level'] ?? 'unknown') !== 'unknown') {
            $checks[] = $security['level'] !== 'bad';
        }
        $total   = count($checks);
        $passing = count(array_filter($checks));
        $health  = [
            'ok'     => $total === 0 ? null
                      : ($passing === $total ? true : ($passing >= $total - 1 ? null : false)),
            'label'  => $total === 0 ? __('Health: n/a', 'impact360')
                      : ($passing === $total ? __('Health: good', 'impact360')
                      : ($passing >= $total - 1 ? __('Health: warning', 'impact360') : __('Health: critical', 'impact360'))),
            'detail' => $total === 0 ? __('No health checks enabled', 'impact360')
                      : sprintf(__('%1$d of %2$d checks passing', 'impact360'), $passing, $total),
        ];

        // ── Hardware summary (model + native inventory devices) ──
        $hw = [
            'model'          => $name('glpi_computermodels', $f['computermodels_id'] ?? 0),
            'cpu'            => '—',
            'ram'            => '—',
            'disk'           => '—',
            'serial'         => $serial,
            'last_inventory' => $lastInventory,
        ];
        try {
            if ($DB->tableExists('glpi_items_deviceprocessors') && $DB->tableExists('glpi_deviceprocessors')) {
                $n = 0; $cpu = '';
                foreach ($DB->request([
                    'SELECT'     => ['glpi_deviceprocessors.designation AS d'],
                    'FROM'       => 'glpi_items_deviceprocessors',
                    'INNER JOIN' => ['glpi_deviceprocessors' => ['ON' => ['glpi_items_deviceprocessors' => 'deviceprocessors_id', 'glpi_deviceprocessors' => 'id']]],
                    'WHERE'      => ['glpi_items_deviceprocessors.itemtype' => 'Computer', 'glpi_items_deviceprocessors.items_id' => $id],
                ]) as $r) { $n++; if ($cpu === '') { $cpu = (string) $r['d']; } }
                if ($n > 0) { $hw['cpu'] = $cpu . ($n > 1 ? ' (×' . $n . ')' : ''); }
            }
            if ($DB->tableExists('glpi_items_devicememories')) {
                $mb = 0;
                foreach ($DB->request(['SELECT' => ['size'], 'FROM' => 'glpi_items_devicememories', 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id]]) as $r) { $mb += (int) $r['size']; }
                if ($mb > 0) { $hw['ram'] = round($mb / 1024, 1) . ' GB'; }
            }
            if ($DB->tableExists('glpi_items_deviceharddrives')) {
                $mb = 0;
                foreach ($DB->request(['SELECT' => ['capacity'], 'FROM' => 'glpi_items_deviceharddrives', 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id]]) as $r) { $mb += (int) $r['capacity']; }
                if ($mb > 0) { $hw['disk'] = $mb >= 1024 ? round($mb / 1024, 1) . ' GB' : $mb . ' MB'; }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // ── Volumes (disk usage: mount point + used %) from glpi_items_disks ──
        $volumes = [];
        try {
            if ($DB->tableExists('glpi_items_disks')) {
                foreach ($DB->request([
                    'SELECT' => ['name', 'mountpoint', 'totalsize', 'freesize'],
                    'FROM'   => 'glpi_items_disks',
                    'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $id],
                    'ORDER'  => ['mountpoint ASC'],
                ]) as $d) {
                    $total = (int) ($d['totalsize'] ?? 0);
                    $free  = (int) ($d['freesize'] ?? 0);
                    $pct   = $total > 0 ? (int) round((($total - $free) / $total) * 100) : null;
                    if ($pct !== null) { $pct = max(0, min(100, $pct)); }   // clamp 0..100 (used in CSS width)
                    $mount = trim((string) ($d['mountpoint'] ?? ''));
                    if ($mount === '') { $mount = trim((string) ($d['name'] ?? '')); }
                    $volumes[] = [
                        'mount'    => $mount !== '' ? $mount : '—',
                        'used_pct' => $pct,
                        'total_gb' => $total > 0 ? round($total / 1024, 1) : null,
                    ];
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // ── Activity (recent history from glpi_logs) ──
        $activity = [];
        try {
            if ($DB->tableExists('glpi_logs')) {
                foreach ($DB->request([
                    'FROM'  => 'glpi_logs',
                    'WHERE' => ['itemtype' => 'Computer', 'items_id' => $id],
                    'ORDER' => ['date_mod DESC'],
                    'LIMIT' => 6,
                ]) as $l) {
                    $who = trim((string) ($l['user_name'] ?? ''));
                    $who = $who !== '' ? trim(preg_replace('/\s*\(\d+\)\s*$/', '', $who)) : '';
                    $new = trim((string) ($l['new_value'] ?? ''));
                    $old = trim((string) ($l['old_value'] ?? ''));
                    $activity[] = [
                        'date' => $l['date_mod'] ?? null,
                        'who'  => $who,
                        'text' => $new !== '' ? $new : ($old !== '' ? $old : __('updated', 'impact360')),
                    ];
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        return [
            // ── Top bar ──
            'name'        => $f['name'] ?? ('#' . $id),
            'type_label'  => Computer::getTypeName(1),
            'updated'     => $f['date_mod'] ?? null,
            'status'      => $name('glpi_states', $f['states_id'] ?? 0),
            'location'    => $name('glpi_locations', $f['locations_id'] ?? 0),
            'owner'       => $owner,
            // The "Edit" button on the dashboard sends the user to the main
            // (form) tab. Without forcetab we'd reload the very URL we're on
            // (Dashboard tab) and the page wouldn't appear to do anything.
            'edit_url'    => Computer::getFormURLWithID($id) . '&forcetab=Computer$main',
            // Create a new ticket already linked to this computer; and a link to
            // the item's native Tickets tab.
            'new_ticket_url' => \Ticket::getFormURL() . '?_add_fromitem=1&itemtype=Computer&items_id=' . $id,
            'tickets_url'    => Computer::getFormURLWithID($id) . '&forcetab=Item_Ticket$1',

            // ── Security cards (native data) ──
            'connectivity' => $conn,
            'antivirus'    => $av,
            'health'       => $health,
            'security'     => $security,

            // ── Software summary ── (unlicensed/uptime not available natively)
            'software' => [
                'installed'    => $softwareInstalled,
                'unlicensed'   => null,
                'uptime'       => null,
                'os'           => $os['name'],
                'build'        => $os['version'],
                'install_date' => $os['install_date'],
            ],

            // ── Tickets ──
            'tickets' => ['linked' => $ticketsLinked, 'open' => $tOpen, 'pending' => $tPending],

            // ── Contracts ──
            'contracts' => ['assigned' => $contractsLinked, 'type' => $cType, 'value' => $cValueStr],

            // ── Lifecycle / Hardware / Volumes / Activity ──
            'lifecycle' => $lifecycle,
            'hardware'  => $hw,
            'volumes'   => $volumes,
            'activity'  => $activity,
        ];
    }
}
