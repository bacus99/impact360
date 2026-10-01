# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] - 2026-10-01

### Changed
- **GLPI compatibility widened to all of 11.x**: `PLUGIN_IMPACT360_MAX_GLPI_VERSION`
  goes from `11.0.99` to `11.99.99`, so the plugin now installs on 11.1+.
  GLPI 12 is still out of range until it has been tested.
- **CSRF hook registered with the literal `'csrf_compliant'` key** instead of
  `Hooks::CSRF_COMPLIANT`. GLPI 12 removes that constant, and referencing it
  is a fatal error at plugin load, which shows up as the misleading
  "function plugin_impact360_install is missing". GLPI 11 reads the string key
  the same way, so nothing changes on 11.x.

### Fixed
- The 1.2.0 CHANGELOG entry below wrongly said the CVE Exposure lookup was
  limited to Computers. As committed in 1.2.0 (`5798f65`), it already covered every itemtype the
  scanners report on. The entry has been corrected; the code is unchanged.

## [1.2.0] - 2026-08-19

### Added
- **"CVE Exposure" lookup** (`front/cve.php`, Plugins → CVE Exposure) — enter
  a CVE id, see every **asset** currently open (vulnerable) for it, across
  every itemtype the scanners cover (Computer, NetworkEquipment, Phone,
  Printer, OT/custom asset types), with a Type column and a per-source Yes/No
  column across **all three** security scanners (Armis, Nexpose, Defender).
  Membership isn't uniform (an asset may be Nexpose+Defender, another
  Defender only), so this is a per-asset, per-source view, not a single count.
- **`src/CveExposure.php`** — the data layer. A `SOURCES` registry describes
  each scanner's tables/rights. For each usable source, `assetsFor($cve)`
  first finds which itemtypes are present with a `GROUP BY itemtype` on the
  findings table. That query reads `KEY item (itemtype, items_id)`, so it
  needs no temp table. It then runs one indexed equality lookup per
  (source, itemtype) pair (`v.cve = ?`, `KEY cve` on the catalog table) and
  merges the results in PHP. There is no UNION and no aggregate across the
  whole catalog. A session missing a source's
  right never sees that source's data at all (fail-closed), not just a
  hidden column.
  - *Originally shipped as a fleet-wide "every CVE, one row each" aggregate
    (`COUNT`/`GROUP BY` over a `QueryUnion` of all three sources). That hit
    MySQL error 1114 ("the table is full" on an on-disk temp table) in
    production — a single widespread CVE touched 5,700+ assets, and grouping
    across the whole catalog needed more temp-table capacity than the server
    has. The aggregate was removed rather than left in place unused.*
  - Because every query is index-backed, it could cover every itemtype
    without bringing that temp-table risk back. Itemtypes found in the
    findings table are filtered by `class_exists`, `canView()` and whether
    they resolve to a table.
  - **Deliberately reduced scope:** no entity scoping
    (`getEntitiesRestrictCriteria` was dropped) — every visible asset shows
    regardless of the session's active entities. This is an explicit,
    requested trade-off — see the class docblock.
- **`front/cve_fleet_export.php`** — CSV export of the same lookup (one row
  per asset, per-source Yes/No), mirroring the existing per-computer
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
