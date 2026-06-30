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

## Architecture

```
impact360/
├── setup.php                 init + version (registers ImpactMapTab + Menu)
├── hook.php                  install/uninstall (no own tables — reads native + netstat)
├── src/
│   ├── ImpactMap.php         data layer: graph build, BFS, roll-up, portfolio, netstat overlay
│   ├── ImpactMapTab.php      the "Impact Map" tab (Computer/Appliance/ITIL) + Appliance roll-up banner
│   ├── ComputerDashboard.php "Dashboard" tab on Computer (CI insight; shares the health concept)
│   ├── Menu.php              Assets → "Application Health" board entry
│   └── Config.php            key/value store: dashboard health settings + module toggles
├── front/config.php          health-check settings page (Setup → Plugins → Impact360)
├── ajax/impactmap.php        graph JSON endpoint (GET, entity-scoped, rights-checked)
├── front/portfolio.php       Application Health board ("wall of apps")
├── templates/computer_dashboard.html.php   Computer Dashboard view
├── public/js/impactmap.js    vis-network renderer (dagre flow, explore, clustering)
├── public/js/vis-network.min.js, dagre.min.js   bundled libs (no CDN)
├── public/css/impactmap.css
└── public/css/dashboard.css
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
- **Read-only + entity-scoped.** Every endpoint runs nodes through
  `ImpactMap::filterByEntity()` and checks rights; it never writes to impact or
  plugin tables.
- **Internal identifiers `uxc-impact-*` / `UxcImpactConfig`** are retained from
  the uxcustomizer origin (shared between PHP-emitted HTML, `impactmap.js`, and
  `impactmap.css`). They're arbitrary names — keep them in sync across those
  three files; they carry no namespace meaning.
- **No local PHP** on the dev machine; `php -l` runs on the GLPI server / CI.
  Use PowerShell (the Bash tool fails here).

## Global rules (reminder)

The non-negotiables from [`../GLPI-Shared/CLAUDE.md`](../GLPI-Shared/CLAUDE.md) apply: GLPI 11 first, read before modify, minimal/reversible changes, preserve behavior, reuse GLPI mechanisms, never trust raw input. 95% validation workflow: [`../GLPI-Shared/rules/glpi-validation.md`](../GLPI-Shared/rules/glpi-validation.md).
