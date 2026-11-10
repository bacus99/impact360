# CLAUDE.md — Impact360 (GLPI 11)

## Shared conventions

Conventions for all of my GLPI 11 plugins live in [`../GLPI-Shared/`](../GLPI-Shared/CLAUDE.md). **Read those rules first** for any task: versioning, namespacing, hooks, DB API, validation workflow, migrations, AJAX endpoints, build/release. This file only covers what's specific to *this* plugin.

## Project scope

Impact360 is the **CI insight + dependency/impact** plugin for GLPI 11, split out of `uxcustomizer` (which keeps menu order, color palette, tab order). It provides:

- **"Impact Map" tab** on Computer, Appliance, and ITIL objects (Ticket/Change/Problem) — dagre "flow" layout, coexists with GLPI's native Impact Analysis tab.
- **Explore mode** — progressive expand-on-click (start at the focused CI, click a node to reveal its next hop).
- **Application health roll-up** on Appliances (worst-of members, **dependency-aware**).
- **Application Health board** (`front/portfolio.php`) — every Appliance as a health tile.
- **Observed-traffic overlay** — optional, reads the `netstatconnections` plugin's tables (real TCP/UDP dependencies). **Soft dependency:** `tableExists`-guarded, a clean no-op when that plugin isn't installed.
- **Computer Dashboard tab** — CI insight on the Computer form (connectivity, antivirus, health checks, software/hardware/lifecycle). Moved here from uxcustomizer because it shares the health concept with the impact roll-up.
- **CVE Exposure lookup** (`front/cve.php`) — enter a CVE id, see every **asset** currently open (vulnerable) for it, across every itemtype the scanners cover (Computer, NetworkEquipment, Phone, Printer, OT/custom asset types), with a per-source Yes/No column across all four security-scanner plugins (Armis, Nexpose, Defender, and since 1.3.0 Pentest-Tools.com website scans) and a Type column since rows aren't all Computers. Membership isn't uniform across sources. Distinct from the per-computer CVE table on the Dashboard tab (`ComputerDashboard::gatherCves()`), which only unions Nexpose + Defender and is scoped to one Computer.
  ⚠ **History (2026-08-19, see `CveExposure.php`'s class docblock):** this started as a fleet-wide "every CVE, one row each" aggregate (`COUNT`/`GROUP BY` over a `QueryUnion`), which hit MySQL error 1114 ("table full" on an on-disk temp table) in production at real data volume (one CVE alone touching 5,700+ assets). That aggregate was **removed entirely** (not left in place unused) — the tool now only looks up one CVE at a time (`CveExposure::assetsFor()`), per source, an index-backed `GROUP BY itemtype` (on `KEY item (itemtype, items_id)`) to discover itemtypes, then one indexed equality lookup per (source, itemtype), with no UNION and no aggregate across the whole catalog, which is also why full itemtype coverage could be restored without reintroducing that risk. It does **not** apply entity scoping (`getEntitiesRestrictCriteria` was dropped) — every visible asset shows regardless of the session's active entities. That remains a deliberate, requested trade-off.
- **Vuln Priority tab** (since 1.4.0, `VulnPriorityTab` / `VulnPriority`) — per-CI unified CVE list: Armis + Nexpose + Defender open findings de-duped by CVE, enriched with CISA KEV + EPSS from the local `VulnIntel` cache, scored `CVSS/10 × exploit × Armis risk × criticality` (0–100, P1–P4; CI = max CVE). Criticality 1–5 is **derived** from the impact graph (Appliance membership, prod environment, 1-hop downstream dependents) — there is no stored criticality field. All weights are constants in `VulnPriority`. Per-CI only — no fleet aggregate (same MySQL 1114 lesson as CVE Exposure). Score depends on the viewing session's source rights (fail-closed); a future ServiceNow push must run with all three rights. **Not run against a live GLPI when written** — the KEV/EPSS fetch, the `appliances_environments_id` read (guarded by `fieldExists`), and the namespaced cron dispatch are the first things to check if something misbehaves.

- **Coverage Gaps board** (since 1.4.0, `front/coverage.php` + `CoverageMatrix`) — per asset, which of Armis / GLPI agent (`glpi_agents`) / Defender / Nexpose sees it. The scanner plugins work GLPI → scanner and cache one row per (itemtype, items_id), so each source is tri-state: **seen** (real scanner id), **not found** (placeholder row: `armis_device_id = 0`, `defender_machine_id = ''`, `nexpose_asset_id = 0`), **never checked** (no row); plus **stale** (timestamp older than the source's `stale_days`: agent/Armis/Defender 30, Nexpose 90 — unknown timestamp stays "seen" for scanners) and, Defender only, **discovered** (`onboarding_status` not Onboarded = no EDR). Several rows per asset → best state wins (`RANK`). Only "seen" is coverage. Custom assets (`glpi_assets_assets`, shared by every definition) are scoped with `$itemtype::getSystemSQLCriteria()`, fail-closed — don't read that table raw anywhere else either. Entity-scoped (unlike CVE Exposure); fail-closed per source. Per itemtype: one base asset read + one `WHERE itemtype = ?` read per source, merged/counted in PHP (no cross-source UNION/GROUP BY — MySQL 1114 lesson), capped at `MAX_ASSETS`. "Armis-only" also surfaces native `Unmanaged` rows carrying armissync's `[ArmisSync] Armis reports this as` comment marker.
- **Palette theming rule (1.4.0):** every dashboard stylesheet defines its `--uxc-*` tokens from GLPI's Tabler variables (`--tblr-body-color`, `--tblr-bg-surface`, `--tblr-border-color`, `--tblr-primary`, `--tblr-success/warning/danger`, …) with literal fallbacks only — no hardcoded dark defaults, no `data-bs-theme` swap blocks — so the user's selected palette (incl. uxcustomizer's custom ones) applies. Canvas (vis-network) can't read CSS vars: `impactmap.js` resolves them via `getComputedStyle` at init. Keep doing this for any new page.

## Architecture

```
impact360/
├── setup.php                 init + version (registers ImpactMapTab + VulnPriorityTab + Menu + CveMenu)
├── hook.php                  install/uninstall (configs + cve_intel tables, VulnIntel cron)
├── src/
│   ├── ImpactMap.php         data layer: graph build, BFS, roll-up, portfolio, netstat overlay
│   ├── ImpactMapTab.php      the "Impact Map" tab (Computer/Appliance/ITIL) + Appliance roll-up banner
│   ├── ComputerDashboard.php "Dashboard" tab on Computer (CI insight; shares the health concept)
│   ├── CveExposure.php       CVE lookup data layer: per-CVE Armis/Nexpose/Defender asset lookup (assetsFor())
│   ├── Menu.php              Plugins → "Application Health" board entry
│   ├── CveMenu.php           Plugins → "CVE Exposure" entry
│   ├── VulnIntel.php         CISA KEV + EPSS cache (table, daily cron, Guzzle fetch, forCves())
│   ├── VulnPriority.php      per-CI unified CVE list + contextual score + graph-derived criticality
│   ├── VulnPriorityTab.php   "Vuln Priority" tab (Computer/NetworkEquipment/Printer/Phone)
│   ├── CoverageMatrix.php    coverage-gap data layer (Armis/agent/Defender/Nexpose per asset)
│   ├── CoverageMenu.php      Plugins → "Coverage Gaps" entry
│   └── Config.php            key/value store: dashboard health settings + module toggles
├── front/config.php          health-check + module-toggle settings page (Setup → Plugins → Impact360)
├── ajax/impactmap.php        graph JSON endpoint (GET, entity-scoped, rights-checked)
├── front/portfolio.php       Application Health board ("wall of apps")
├── front/cve.php             CVE Exposure lookup (one CVE → affected assets)
├── front/cve_export.php          per-computer CVE CSV export (Dashboard tab)
├── front/cve_fleet_export.php    CVE Exposure lookup CSV export
├── front/coverage.php        Coverage Gaps board (+ coverage_export.php CSV)
├── templates/computer_dashboard.html.php   Computer Dashboard view
├── public/js/impactmap.js    vis-network renderer (dagre flow, explore, clustering)
├── public/js/vis-network.min.js, dagre.min.js   bundled libs (no CDN)
├── public/css/impactmap.css
├── public/css/dashboard.css
├── public/css/cve.css        CVE Exposure lookup styles (own --uxc-* scope, not shared with dashboard.css)
├── public/css/vulnpriority.css   Vuln Priority tab styles (own .uxc-vp scope)
└── public/css/coverage.css   Coverage Gaps styles (own .uxc-cov scope)
```

## Points specific to this project

- **Owned tables:** `glpi_plugin_impact360_configs` (key/value) backs
  the Computer Dashboard's **health-check settings** + module toggles (+ the
  last threat-intel refresh status); `glpi_plugin_impact360_cve_intel` (1.4.0)
  caches CISA KEV + EPSS per CVE, written only by `VulnIntel::refresh()`. The graph
  itself stores no data — it reads GLPI's native impact tables
  (`glpi_impactrelations` / `glpi_impactitems` / `glpi_impactcompounds`) and,
  when present, `glpi_plugin_netstatconnections_*`. **Asset retention is not
  owned here** — it's consumed from uxcustomizer's `Lifecycle` only when that
  plugin is active (`Plugin::isPluginActive` + `class_exists` guard). Install
  creates the config table; uninstall drops it.
- **Security-scanner plugins are soft dependencies, same posture as netstat.**
  `armissync`, `nexposesync`, `defendersync` each own an identical two-table
  model (`glpi_plugin_<p>_vulnerabilities` catalog + `..._vulnerabilities_items`
  findings, polymorphic `itemtype`/`items_id`, no `entities_id` of their own).
  **Exception — `pentesttoolssync` (added 1.3.0, a fourth source) has the same
  two tables but stores the CVE ids on the FINDING row (`..._items.cve`,
  comma-separated), not the catalog row, and uses status `open`; its `SOURCES`
  entry carries `'match' => 'items'` / `'open' => 'open'` and the lookup is
  `FIND_IN_SET` on the findings table. Not run against a live GLPI when
  written — if the "Pentest-Tools" column is empty for a CVE you know a website
  has, check `glpi_plugin_pentesttoolssync_vulnerabilities_items.cve` for that
  asset first (rows older than pentesttoolssync 1.2.5 hold no per-finding CVE
  until the next nightly sync).**
  `CveExposure` reads them read-only, guarded by `Plugin::isPluginActive` +
  `class_exists` + `tableExists` + `Session::haveRight` per source — a session
  missing a source's right never sees that source's data at all (fail-closed,
  not just a hidden column).
- **Read-only + entity-scoped** (impact graph and the per-computer Dashboard
  tab). Every such endpoint runs nodes through `ImpactMap::filterByEntity()` or
  GLPI's own per-item `can(READ)` and checks rights; it never writes to
  impact, plugin, or security-scanner tables. `CveExposure` (the CVE Exposure
  lookup) is the current exception — see its reduced-scope note above; it
  does not currently entity-scope.
- **Internal identifiers `uxc-impact-*` / `UxcImpactConfig`** are retained from
  the uxcustomizer origin (shared between PHP-emitted HTML, `impactmap.js`, and
  `impactmap.css`). They're arbitrary names — keep them in sync across those
  three files; they carry no namespace meaning.
- **No local PHP** on the dev machine; `php -l` runs on the GLPI server / CI.
  Use PowerShell (the Bash tool fails here).

## Global rules (reminder)

The non-negotiables from [`../GLPI-Shared/CLAUDE.md`](../GLPI-Shared/CLAUDE.md) apply: GLPI 11 first, read before modify, minimal/reversible changes, preserve behavior, reuse GLPI mechanisms, never trust raw input. 95% validation workflow: [`../GLPI-Shared/rules/glpi-validation.md`](../GLPI-Shared/rules/glpi-validation.md).
