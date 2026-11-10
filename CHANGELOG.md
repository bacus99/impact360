# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-11-04

### Added
- **"Vuln Priority" tab** on Computer, Network device, Printer and Phone: one
  de-duplicated CVE list per CI merging the **open** findings of Armis,
  Nexpose and Defender (same CVE from several scanners = one row with every
  source shown), enriched with **CISA KEV** (listed, due date, known
  ransomware use) and **EPSS** (probability + percentile), and ranked by a
  **contextual priority score** 0–100:
  `CVSS/10 × exploit × Armis risk × business criticality`, tiers P1–P4.
  The CI's priority is its highest CVE score; hovering a score shows the four
  factors behind it. Business criticality (1–5) is **derived from the impact
  graph**: application membership, whether an application is production
  (Appliance environment), and downstream dependents (1 hop). Read-only; same
  fail-closed source gating as CVE Exposure. Toggle under Setup → Plugins →
  Impact360 → Modules.
- **Threat-intel cache** `glpi_plugin_impact360_cve_intel` + daily automatic
  action **VulnIntel** pulling the CISA KEV feed and FIRST EPSS scores (EPSS
  only for CVEs present in the scanner catalogs). Uses GLPI's HTTP client, so
  the proxy set in Setup → General applies. A "Refresh threat intel now"
  button on the config page runs the same refresh on demand. Needs outbound
  HTTPS to `www.cisa.gov` and `api.first.org`; without it the tab still works
  and treats exploitability as unknown (neutral).

- **Coverage Gaps board** (Plugins → Coverage Gaps, `front/coverage.php`):
  for every asset, which sources actually see it — **Armis, GLPI agent,
  Defender, Nexpose**. Headline tiles (Armis-only "unmanaged attack surface",
  not seen by each source, seen by none, fully covered), a **coverage-pattern
  matrix** (every Armis/agent/Defender/Nexpose combination with its asset
  count — click to list), and a paginated asset table with a per-source
  state: *seen*, *checked but not found*, *never checked* (the scanner
  plugin hasn't looked at that asset/type), *stale* (agent silent, or Armis/
  Defender not seen, for 30 days; Nexpose not scanned for 90 days) and, for
  Defender, *discovered but not onboarded* (Defender knows the device from
  network discovery but there is no EDR sensor on it). Only *seen* counts as
  covered. GLPI 11 custom asset types are each limited to their own asset
  definition; the name search runs in SQL, before the 50,000-asset cap. Also counts GLPI
  "Unmanaged" devices that armissync's classification identified in Armis —
  Armis-only devices not even inventoried yet. CSV export of the current
  filter. Entity-scoped; each source is fail-closed on its plugin + right
  (the agent column on GLPI's own Agent view right). One indexed read per
  source per asset type, merged in PHP — no cross-source SQL aggregate.
  Toggle under Setup → Plugins → Impact360 → Modules.

### Changed
- **All Impact360 dashboards follow the GLPI colour palette you select** in
  My Settings (light, dark, or a custom palette such as the uxcustomizer TC
  Transcontinental one): Computer Dashboard, CVE Exposure, Vuln Priority,
  Coverage Gaps, Application Health and the Impact Map (including the
  canvas-drawn labels/edges, which read the palette at load). Previously
  most of them used fixed dark colours with a light-theme swap. Vendor logo
  colours and the Impact Map's per-asset-type colours are intentionally kept.

### Notes
- Upgrading from 1.3.x creates the new table and registers the automatic
  action (GLPI prompts for the plugin update because the version changed).
- Uninstall drops the new table and removes the automatic action.

## [1.3.0] - 2026-10-28

### Added
- **CVE Exposure now includes Pentest-Tools.com website findings** as a fourth
  source column ("Pentest-Tools"), next to Armis / Nexpose / Defender. The
  "Websites" custom asset appears in the results (Type = Websites) when a
  website has an **open** finding carrying the CVE. Read-only, same
  soft-dependency rules as the other sources: `pentesttoolssync` active, its
  tables present, and READ on its `plugin_pentesttoolssync_vulnerability`
  right — a session without that right never sees the column or the data.
  The CSV export gets the column too.
  - Matching differs from the other sources: Pentest-Tools.com keeps the CVE
    ids on the finding row (`cve`, comma-separated), not on the shared
    catalog row, so the lookup is an exact element match on that list
    (`FIND_IN_SET`), not a substring match, and uses Pentest-Tools.com's own
    `open` status instead of `vulnerable`. Findings synced before
    pentesttoolssync 1.2.5 have no per-finding CVE until their next nightly
    sync, so they appear after that sync runs.
  - When one asset has several findings with the same CVE, the widest
    first-seen / last-seen window is kept (applies to every source).
- **Click-to-sort on the CVE Exposure table.** Click a column header to sort
  by it; click again to reverse. The source columns (Armis, Nexpose, Defender,
  Pentest-Tools) put "Yes" first on the first click, so the assets flagged by
  that tool come to the top; Asset and Type sort A to Z (numeric-aware). Ties
  fall back to the asset name. Client-side only (`public/js/cve-sort.js`), no
  extra request; the CSV export order is unchanged.

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
