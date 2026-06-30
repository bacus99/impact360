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

Read-only and entity-scoped throughout.

## Requirements

- GLPI `~11.0.0`
- PHP `>= 8.1`
- *(optional)* the `netstatconnections` plugin for the observed-traffic overlay.

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
