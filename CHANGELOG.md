# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-06-30

Initial release — extracted from **uxcustomizer** (the Impact Map module, the
Computer Dashboard, and everything built on them), now its own plugin.

### Added
- **Computer Dashboard tab** — a CI insight tab on the Computer form
  (connectivity, antivirus, health checks, software / hardware / lifecycle
  summary). Moved here because it shares the health concept with the impact
  roll-up. (`src/ComputerDashboard.php` + `templates/computer_dashboard.html.php`
  + `public/css/dashboard.css`.)
- **Dashboard tab placed first** on the Computer form — a small client-side
  reorder (`public/js/dashboard-first.js`, self-guarded to `Computer.form.php`),
  since GLPI 11 has no server-side tab-order hook and plugin tabs otherwise land
  last.
- **Settings reachable under Setup** — both as a **Setup → Impact360** menu entry
  (`ConfigMenu`) and the wrench on Setup → Plugins (`config_page`).
- **Configurable Computer Dashboard health** — a config page (Setup → Impact360)
  to choose which signals count toward a computer's health roll-up
  (connectivity, antivirus, AV up-to-date, open tickets, OS inventoried, asset
  retention) and set thresholds (agent-online days, max open tickets). Stored in
  `glpi_plugin_impact360_configs` via `src/Config.php` (key/value + module
  toggles). Disabled checks are ignored, not counted as failing.
- **Asset retention stays in uxcustomizer.** The dashboard's retirement-date /
  retention check consumes `uxcustomizer`'s `Lifecycle` **only when that plugin
  is active** (`Plugin::isPluginActive` + `class_exists` guard); absent, the
  retirement date is simply not shown. No duplication, no hard dependency.
- **Impact Map tab** on Computer, Appliance, and ITIL objects (Ticket / Change /
  Problem), with a dagre "flow" layout, health overlays, clustering/compounds,
  what-if failure preview, path tracing, search, and export (PNG/SVG/PDF).
- **Observed-traffic overlay** from the `netstatconnections` plugin — real
  TCP/UDP dependency edges merged with native impact relations (solid =
  confirmed, dashed = observed-only), port-labelled and weight-thickened, with
  a depth-1 + per-host fan-out cap to avoid sprawl. Soft dependency
  (`tableExists`-guarded).
- **Explore mode** — progressive expand-on-click (seed + reveal next hop);
  focused CI never clustered; outlier-robust Fit.
- **Application health roll-up** on Appliances — worst-of members, and
  **dependency-aware** (folds in the health of CIs the members depend on, from
  native impact + observed traffic).
- **Application Health board** (`front/portfolio.php`) — every Appliance as a
  health tile, worst-first; entity-scoped + `Appliance::canView()` gate.

(Corresponds to uxcustomizer 2.2.0–2.6.0, repackaged under the
`GlpiPlugin\Impact360` namespace.)
