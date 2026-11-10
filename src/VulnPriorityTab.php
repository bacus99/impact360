<?php

/**
 * Impact360 - "Vuln Priority" item tab
 *
 * Unified, de-duplicated vulnerability view for one CI: Armis + Nexpose +
 * Defender findings merged per CVE, enriched with CISA KEV + EPSS, ranked by
 * a contextual priority score (severity × Armis risk × business criticality).
 * All data logic lives in VulnPriority; this class only renders it.
 *
 * Registered in setup.php via Plugin::registerClass(VulnPriorityTab::class,
 * ['addtabon' => VulnPriorityTab::ITEMTYPES]). Read-only.
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use CommonGLPI;

class VulnPriorityTab extends CommonGLPI
{
    /** Itemtypes the scanners report on that carry a GLPI form with tabs. */
    public const ITEMTYPES = ['Computer', 'NetworkEquipment', 'Printer', 'Phone'];

    public static function getTypeName($nb = 0): string
    {
        return __('Vuln Priority', 'impact360');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!method_exists($item, 'isNewItem') || $item->isNewItem()) {
            return '';
        }
        if (!in_array(get_class($item), self::ITEMTYPES, true)) {
            return '';
        }
        if (!Config::isModuleEnabled('vulnpriority') || VulnPriority::sources() === []) {
            return '';
        }
        return self::createTabEntry(__('Vuln Priority', 'impact360'), 0, $item->getType(), 'ti ti-shield-exclamation');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        global $CFG_GLPI;

        if (!method_exists($item, 'isNewItem') || $item->isNewItem()) {
            return false;
        }
        $itemtype = get_class($item);
        if (!in_array($itemtype, self::ITEMTYPES, true)
            || !Config::isModuleEnabled('vulnpriority')
            || VulnPriority::sources() === []) {
            return false;
        }

        $v = VulnPriority::forItem($itemtype, (int) $item->getID());

        echo '<link rel="stylesheet" type="text/css" href="'
            . $CFG_GLPI['root_doc']
            . '/plugins/impact360/public/css/vulnpriority.css?v=' . PLUGIN_IMPACT360_VERSION . '">';

        $labels = [];
        foreach (VulnPriority::SOURCE_KEYS as $k) {
            if (isset(CveExposure::SOURCES[$k]) && in_array($k, $v['sources'], true)) {
                $labels[$k] = CveExposure::SOURCES[$k]['label'];
            }
        }

        echo '<div class="uxc-vp">';
        self::renderSummary($v);
        self::renderIntelNotice($v);
        self::renderTable($v, $labels);
        self::renderFormula();
        echo '</div>';

        return true;
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $v */
    private static function renderSummary(array $v): void
    {
        $tier  = $v['tier'];
        $crit  = $v['criticality'];
        $armis = $v['armis'];
        $c     = $v['counts'];

        echo '<div class="uxc-vp-top">';

        // Priority card.
        echo '<div class="uxc-vp-card uxc-vp-score uxc-tier-' . self::e(strtolower($tier)) . '">';
        echo '<div class="uxc-vp-label">' . self::e(__('Contextual priority', 'impact360')) . '</div>';
        if ($tier === 'none') {
            echo '<div class="uxc-vp-big">—</div>';
            echo '<div class="uxc-vp-sub">' . self::e(__('No open CVE on this CI', 'impact360')) . '</div>';
        } else {
            echo '<div class="uxc-vp-big">' . (int) $v['priority'] . '<span class="uxc-vp-of">/100</span></div>';
            echo '<div class="uxc-vp-sub"><span class="uxc-vp-tier">' . self::e($tier) . '</span> '
                . self::e(sprintf(__('driven by %s', 'impact360'), (string) $v['top_cve'])) . '</div>';
        }
        echo '</div>';

        // Counts card.
        echo '<div class="uxc-vp-card">';
        echo '<div class="uxc-vp-label">' . self::e(__('Open CVEs (de-duplicated)', 'impact360')) . '</div>';
        echo '<div class="uxc-vp-big">' . (int) $c['cves'] . '</div>';
        echo '<div class="uxc-vp-sub">'
            . self::e(sprintf(__('%d in CISA KEV', 'impact360'), (int) $c['kev']));
        foreach ($c['per_source'] as $k => $n) {
            $lbl = CveExposure::SOURCES[$k]['label'] ?? $k;
            echo ' · ' . self::e($lbl) . ' ' . (int) $n;
        }
        echo '</div>';
        if ($c['skipped'] > 0) {
            echo '<div class="uxc-vp-sub uxc-muted">'
                . self::e(sprintf(
                    _n('%d finding without a CVE id not shown', '%d findings without a CVE id not shown', (int) $c['skipped'], 'impact360'),
                    (int) $c['skipped']
                ))
                . '</div>';
        }
        echo '</div>';

        // Armis risk card.
        echo '<div class="uxc-vp-card">';
        echo '<div class="uxc-vp-label">' . self::e(__('Armis device risk', 'impact360')) . '</div>';
        if ($armis === null) {
            echo '<div class="uxc-vp-big">—</div>';
            echo '<div class="uxc-vp-sub uxc-muted">' . self::e(
                in_array('armis', $v['sources'], true)
                    ? __('No matched Armis device — factor neutral (×1.0)', 'impact360')
                    : __('Armis not available to you — factor neutral (×1.0)', 'impact360')
            ) . '</div>';
        } else {
            echo '<div class="uxc-vp-big">' . (int) $armis['risk_level'] . '<span class="uxc-vp-of">/10</span></div>';
            $bits = [];
            if ($armis['business_impact'] !== '') {
                $bits[] = sprintf(__('Armis business impact: %s', 'impact360'), $armis['business_impact']);
            }
            if ($armis['tier'] !== '') {
                $bits[] = sprintf(__('tier %s', 'impact360'), $armis['tier']);
            }
            echo '<div class="uxc-vp-sub">' . self::e($bits === [] ? __('Matched Armis device', 'impact360') : implode(' · ', $bits)) . '</div>';
        }
        echo '</div>';

        // Business criticality card.
        echo '<div class="uxc-vp-card">';
        echo '<div class="uxc-vp-label">' . self::e(__('Business criticality (from impact graph)', 'impact360')) . '</div>';
        echo '<div class="uxc-vp-big">' . (int) $crit['level'] . '<span class="uxc-vp-of">/5</span></div>';
        echo '<div class="uxc-vp-sub">' . self::e(implode(' · ', $crit['reasons'])) . '</div>';
        if ($crit['apps'] !== []) {
            echo '<div class="uxc-vp-apps">';
            foreach (array_slice($crit['apps'], 0, 6) as $a) {
                $url = \Appliance::getFormURLWithID((int) $a['id']);
                echo '<a class="uxc-vp-app' . ($a['prod'] ? ' is-prod' : '') . '" href="' . self::e($url) . '"'
                    . ' title="' . self::e(($a['env'] !== '' ? $a['env'] . ' · ' : '')
                        . ($a['via'] === 'member' ? __('member', 'impact360') : __('downstream', 'impact360'))) . '">'
                    . '<i class="ti ti-apps"></i> ' . self::e($a['name']) . '</a>';
            }
            if (count($crit['apps']) > 6) {
                echo '<span class="uxc-muted"> +' . (count($crit['apps']) - 6) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '</div>'; // top
    }

    /** @param array<string,mixed> $v */
    private static function renderIntelNotice(array $v): void
    {
        $i = $v['intel'];
        if ($i === null) {
            echo '<div class="uxc-vp-note">' . self::e(__('Threat-intel cache table is missing — re-run the plugin install/upgrade.', 'impact360')) . '</div>';
            return;
        }
        if ($i['rows'] === 0) {
            echo '<div class="uxc-vp-note">'
                . self::e(__('CISA KEV / EPSS data has not been loaded yet — scores below assume "unknown exploitability" (neutral). Run Setup → Plugins → Impact360 → Refresh threat intel, or wait for the daily automatic action.', 'impact360'))
                . '</div>';
        } elseif ($i['errors'] !== []) {
            echo '<div class="uxc-vp-note">'
                . self::e(sprintf(__('Last threat-intel refresh (%s) had errors: %s', 'impact360'), (string) $i['at'], implode('; ', $i['errors'])))
                . '</div>';
        }
    }

    /**
     * @param array<string,mixed> $v
     * @param array<string,string> $labels source key => label
     */
    private static function renderTable(array $v, array $labels): void
    {
        echo '<div class="uxc-vp-card uxc-vp-tablecard">';
        echo '<div class="uxc-vp-label">' . self::e(__('Unified vulnerability list', 'impact360')) . '</div>';

        if ($v['cves'] === []) {
            echo '<div class="uxc-muted">' . self::e(__('No open CVE reported by Armis, Nexpose or Defender for this CI.', 'impact360')) . '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="uxc-vp-tablewrap"><table class="uxc-vp-table"><thead><tr>';
        foreach ([
            __('Priority', 'impact360'), __('CVE', 'impact360'), __('CVSS', 'impact360'),
            __('CISA KEV', 'impact360'), __('EPSS', 'impact360'), __('Sources', 'impact360'),
            __('First seen', 'impact360'), __('Last seen', 'impact360'),
        ] as $h) {
            echo '<th>' . self::e($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($v['cves'] as $r) {
            $f = $r['factors'];
            $why = sprintf(
                'CVSS %.1f × exploit %.2f × Armis %.2f × criticality %.2f',
                $f['cvss'], $f['exploit'], $f['armis'], $f['criticality']
            );
            echo '<tr>';

            echo '<td><span class="uxc-vp-pill uxc-tier-' . self::e(strtolower($r['tier'])) . '" title="' . self::e($why) . '">'
                . self::e($r['tier']) . ' · ' . (int) $r['score'] . '</span></td>';

            echo '<td><a href="https://nvd.nist.gov/vuln/detail/' . self::e(rawurlencode($r['cve']))
                . '" target="_blank" rel="noopener noreferrer">' . self::e($r['cve']) . '</a>';
            if ($r['title'] !== '') {
                echo '<div class="uxc-vp-cvetitle uxc-muted">' . self::e(mb_strimwidth($r['title'], 0, 110, '…')) . '</div>';
            }
            echo '</td>';

            echo '<td>' . self::e(number_format((float) $r['cvss'], 1))
                . ($r['severity'] !== '' ? ' <span class="uxc-muted">' . self::e($r['severity']) . '</span>' : '')
                . ($r['exploitable'] ? ' <span class="uxc-vp-flag" title="'
                    . self::e(__('Scanner reports a known exploit', 'impact360')) . '">'
                    . self::e(__('exploit', 'impact360')) . '</span>' : '')
                . '</td>';

            echo '<td>';
            if ($r['kev']) {
                echo '<span class="uxc-vp-kev">' . self::e(__('KEV', 'impact360')) . '</span>';
                if ($r['ransomware']) {
                    echo ' <span class="uxc-vp-flag" title="' . self::e(__('Known ransomware campaign use', 'impact360')) . '">'
                        . self::e(__('ransomware', 'impact360')) . '</span>';
                }
                if ($r['kev_due']) {
                    echo '<div class="uxc-muted">' . self::e(sprintf(__('due %s', 'impact360'), $r['kev_due'])) . '</div>';
                }
            } else {
                echo '<span class="uxc-muted">' . ($r['intel_known'] ? '—' : self::e(__('unknown', 'impact360'))) . '</span>';
            }
            echo '</td>';

            echo '<td>';
            if ($r['epss'] !== null) {
                echo self::e(number_format($r['epss'] * 100, $r['epss'] < 0.01 ? 2 : 1)) . '%'
                    . '<div class="uxc-muted">' . self::e(sprintf(__('top %s%%', 'impact360'),
                        number_format((1 - (float) $r['epss_percentile']) * 100, 1))) . '</div>';
            } else {
                echo '<span class="uxc-muted">' . self::e(__('unknown', 'impact360')) . '</span>';
            }
            echo '</td>';

            echo '<td>';
            foreach ($labels as $k => $label) {
                if (isset($r['sources'][$k])) {
                    echo '<span class="uxc-vp-src">' . self::e($label) . '</span> ';
                }
            }
            echo '</td>';

            echo '<td>' . self::e(self::day($r['first_seen'])) . '</td>';
            echo '<td>' . self::e(self::day($r['last_seen'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    }

    private static function renderFormula(): void
    {
        echo '<div class="uxc-vp-formula uxc-muted">'
            . self::e(__('Priority = CVSS/10 × exploit (KEV = 1.0, else 0.4–0.9 from EPSS percentile, 0.6 if unknown; scanner-reported exploit ≥ 0.8) × Armis (0.5 + risk/10, 1.0 if no match) × criticality (0.6–1.4 from level 1–5), scaled to 0–100. The CI priority is its highest CVE score. P1 ≥ 70 · P2 ≥ 45 · P3 ≥ 20 · P4 below. Hover a priority pill for its factors.', 'impact360'))
            . '</div>';
    }

    private static function day(?string $ts): string
    {
        return $ts === null || $ts === '' ? '—' : substr($ts, 0, 10);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
