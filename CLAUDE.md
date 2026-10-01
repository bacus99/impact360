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
- **CVE Exposure lookup** (`front/cve.php`) — enter a CVE id, see every **asset** currently open (vulnerable) for it, across every itemtype the scanners cover (Computer, NetworkEquipment, Phone, Printer, OT/custom asset types), with a per-source Yes/No column across all three security-scanner plugins (Armis, Nexpose, Defender) and a Type column since rows aren't all Computers. Membership isn't uniform across sources. Distinct from the per-computer CVE table on the Dashboard tab (`ComputerDashboard::gatherCves()`), which only unions Nexpose + Defender and is scoped to one Computer.
  ⚠ **History (2026-08-19, see `CveExposure.php`'s class docblock):** this started as a fleet-wide "every CVE, one row each" aggregate (`COUNT`/`GROUP BY` over a `QueryUnion`), which hit MySQL error 1114 ("table full" on an on-disk temp table) in production at real data volume (one CVE alone touching 5,700+ assets). That aggregate was **removed entirely** (not left in place unused) — the tool now only looks up one CVE at a time (`CveExposure::assetsFor()`), per source, an index-backed `GROUP BY itemtype` (on `KEY item (itemtype, items_id)`) to discover itemtypes, then one indexed equality lookup per (source, itemtype), with no UNION and no aggregate across the whole catalog, which is also why full itemtype coverage could be restored without reintroducing that risk. It does **not** apply entity scoping (`getEntitiesRestrictCriteria` was dropped) — every visible asset shows regardless of the session's active entities. That remains a deliberate, requested trade-off.

## Architecture

```
impact360/
├── setup.php                 init + version (registers ImpactMapTab + Menu + CveMenu)
├── hook.php                  install/uninstall (no own tables — reads native + netstat + security plugins)
├── src/
│   ├── ImpactMap.php         data layer: graph build, BFS, roll-up, portfolio, netstat overlay
│   ├── ImpactMapTab.php      the "Impact Map" tab (Computer/Appliance/ITIL) + Appliance roll-up banner
│   ├── ComputerDashboard.php "Dashboard" tab on Computer (CI insight; shares the health concept)
│   ├── CveExposure.php       CVE lookup data layer: per-CVE Armis/Nexpose/Defender asset lookup (assetsFor())
│   ├── Menu.php              Plugins → "Application Health" board entry
│   ├── CveMenu.php           Plugins → "CVE Exposure" entry
│   └── Config.php            key/value store: dashboard health settings + module toggles
├── front/config.php          health-check + module-toggle settings page (Setup → Plugins → Impact360)
├── ajax/impactmap.php        graph JSON endpoint (GET, entity-scoped, rights-checked)
├── front/portfolio.php       Application Health board ("wall of apps")
├── front/cve.php             CVE Exposure lookup (one CVE → affected assets)
├── front/cve_export.php          per-computer CVE CSV export (Dashboard tab)
├── front/cve_fleet_export.php    CVE Exposure lookup CSV export
├── templates/computer_dashboard.html.php   Computer Dashboard view
├── public/js/impactmap.js    vis-network renderer (dagre flow, explore, clustering)
├── public/js/vis-network.min.js, dagre.min.js   bundled libs (no CDN)
├── public/css/impactmap.css
├── public/css/dashboard.css
└── public/css/cve.css        CVE Exposure lookup styles (own --uxc-* scope, not shared with dashboard.css)
```

## Points specific to this project

- **One small owned table:** `glpi_plugin_impact360_configs` (key/value) backs
  the Computer Dashboard's **health-check settings** + module toggles. The graph
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
