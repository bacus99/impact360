# Impact360

Interactive dependency / impact map for **GLPI 11** — with application health
roll-up, an Application Health board, and an optional observed-traffic overlay.

> Split out of [uxcustomizer](https://github.com/bacus99/uxcustomizer) at its
> v3.0: uxcustomizer keeps menu order / palette / tab order; Impact360 owns the
> impact map, the application health roll-up, and the Computer Dashboard.

## Features

- **Impact Map tab** on Computer, Appliance, and ITIL objects (Ticket / Change /
  Problem). Dagre "flow" layout — reads like a service diagram, not a hairball.
  Coexists with GLPI's native Impact Analysis tab.
- **Explore mode** — start collapsed to the focused CI + its neighbours; click a
  node to reveal its next hop. Walks the graph instead of dumping it.
- **Application health roll-up** on Appliances — one worst-of status from the
  members, **dependency-aware**: a service is flagged at risk when a CI it
  *depends on* (real traffic) is degraded.
- **Application Health board** — every Appliance as a health tile, worst-first
  (Assets → Application Health).
- **Observed-traffic overlay** — optional. When the
  [netstatconnections](https://github.com/) plugin is installed, real TCP/UDP
  dependencies are overlaid on the map (dashed = observed-only, solid =
  confirmed in native impact), labelled by port and weighted by frequency.

- **Computer Dashboard tab** — CI insight on the Computer form (connectivity,
  antivirus, health checks, software / hardware / lifecycle).
- **CVE Exposure lookup** (Plugins → CVE Exposure) — enter a CVE id, see every
  asset open for it, with a per-source Yes/No column for Armis, Nexpose,
  Defender and Pentest-Tools.com (soft dependencies, rights-gated, CSV export).
- **Vuln Priority tab** (1.4.0) on Computer, Network device, Printer and Phone —
  one de-duplicated CVE list per CI (Armis + Nexpose + Defender), enriched with
  CISA KEV and EPSS, ranked by a contextual 0–100 score (P1–P4) that factors in
  business criticality derived from the impact graph. Needs outbound HTTPS to
  `www.cisa.gov` and `api.first.org` for the threat-intel refresh; works
  without it (exploitability treated as unknown).
- **Coverage Gaps board** (1.4.0, Plugins → Coverage Gaps) — per asset, which of
  Armis / GLPI agent / Defender / Nexpose actually sees it (seen, not found,
  never checked, stale, discovered-not-onboarded), with a coverage-pattern
  matrix and CSV export.
- Dashboards follow the GLPI colour palette selected in My Settings.
- Modules can be toggled under Setup → Plugins → Impact360.

Read-only throughout, and entity-scoped everywhere except the CVE Exposure
lookup, which by design lists every visible asset regardless of the active
entities.

## Requirements

- GLPI `11.x` (`>= 11.0.0`, `< 12.0.0`)
- PHP `>= 8.1`
- *(optional)* the `netstatconnections` plugin for the observed-traffic overlay.
- *(optional)* `armissync`, `nexposesync`, `defendersync`, `pentesttoolssync`
  for CVE Exposure, Vuln Priority and Coverage Gaps. Each is a soft
  dependency: absent plugin or missing right = that source is simply skipped.

## Install

1. Drop the `impact360` folder into `glpi/plugins/` (folder name **must** be
   `impact360`).
2. Plugins → install → activate **Impact360**.
3. Open any Computer/Appliance → **Impact Map** tab; or Assets → **Application
   Health** for the board.

## Build a release

```powershell
./build.ps1            # version read from setup.php
# → dist/glpi-impact360-<version>.tar.bz2
```

## Publish (catalog)

Update `plugin.xml` (version + download URL), tag the release on GitHub, attach
the tarball, then submit/refresh on <https://plugins.glpi-project.org/>.

## License

GPL-3.0-or-later © Christian Bernard
