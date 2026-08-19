# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-08-19

### Added
- **"CVE Exposure" lookup** (`front/cve.php`, Plugins → CVE Exposure) — enter
  a CVE id, see every **Computer** currently open (vulnerable) for it, with a
  per-source Yes/No column across **all three** security scanners (Armis,
  Nexpose, Defender). Membership isn't uniform (a computer may be
  Nexpose+Defender, another Defender only), so this is a per-asset,
  per-source view, not a single count.
- **`src/CveExposure.php`** — the data layer. A `SOURCES` registry describes
  each scanner's tables/rights. `assetsFor($cve)` runs one plain indexed
  equality lookup per usable source (`v.cve = ?`, `KEY cve` on the catalog
  table), merged in PHP — no UNION, no GROUP BY. A session missing a source's
  right never sees that source's data at all (fail-closed), not just a
  hidden column.
  - *Originally shipped as a fleet-wide "every CVE, one row each" aggregate
    (`COUNT`/`GROUP BY` over a `QueryUnion` of all three sources). That hit
    MySQL error 1114 ("the table is full" on an on-disk temp table) in
    production — a single widespread CVE touched 5,700+ assets, and grouping
    across the whole catalog needed more temp-table capacity than the server
    has. The aggregate was removed rather than left in place unused.*
  - **Deliberately reduced scope, current state:** itemtype restricted to
    `Computer` only (`CveExposure::ITEMTYPE_SCOPE`); no entity scoping
    applied (`getEntitiesRestrictCriteria` was dropped) — every visible
    computer shows regardless of the session's active entities. Both are
    explicit, requested trade-offs — see the class docblock.
- **`front/cve_fleet_export.php`** — CSV export of the same lookup (one row
  per computer, per-source Yes/No), mirroring the existing per-computer
  `cve_export.php`.
- New **Plugins → CVE Exposure** menu entry (`src/CveMenu.php`), gated on
  holding READ on at least one scanner's right.
- New module toggle **`cve`** (Setup → Impact360 → Modules), defaulting on.

(The per-computer CVE table on the Computer Dashboard tab,
`ComputerDashboard::gatherCves()`, is unchanged — it still unions Nexpose +
Defender only, scoped to one Computer.)

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
