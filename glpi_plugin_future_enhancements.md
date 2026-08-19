# GLPI Plugin Ecosystem — Future Enhancements Backlog

**Created:** 2026-08-19
**Source:** competitive scan (iTop 3.3 / MCP Server for iTop, Device42–Freshworks, Faddom, Virima, Flexera, GLPI Network AI) + iTop MCP server teardown
**Status:** parked — no active sprint assigned

Nothing here is committed. Items are recorded with enough context to be picked up cold, plus the rejected options so they don't get re-litigated.

---

## Cross-cutting

### XC-01 — Assembler as library, two consumers
Build the context assembler (A1) as a standalone library exposing **one call** returning a JSON bundle, then wire two consumers against the same contract:
- **push** — `pre_item_add` hook on Ticket (enrichment, deterministic, 1 round trip)
- **pull** — single MCP tool `get_ticket_context(ticket_id)` returning the identical payload (interactive triage)

Rationale: push composition beats tool-per-entity pull for a local Qwen2.5 host — small models plan multi-step retrieval badly. iTop went pull with 15 tools; that surface assumes a frontier model.

**Effort:** +2–3 days on top of A1 if designed in from the start; expensive as a retrofit.
**Blocker:** none. Decide the JSON contract before A1 code lands.

### XC-02 — Standalone deployment, not in-plugin
iTop shipped its MCP server as a standalone PHP/Symfony app rather than an extension, decoupling it from the platform upgrade cycle. With GLPI 12 already in GLPI Agent 1.19 as experimental, anything inside a plugin gets ported again. Keep the assembler and any MCP surface outside the GLPI tree, same as the netstat pipeline.

### XC-03 — Auth pattern for any middleware
GLPI 11's two-token scheme does not map onto a single Bearer header. Target shape:
- **App-Token** held server-side in middleware config (plain config file)
- **User token** accepted from the `Authorization` header, so per-user rights stay enforced in GLPI

Side benefit: a config-file App-Token is not a GLPI-encrypted stored secret, so it sidesteps the GLPIKey mismatch class of failure entirely.

### XC-04 — Runtime schema introspection, never a snapshot
iTop's MCP server requires a copied/symlinked `datamodel-production.xml` and goes stale silently when the datamodel changes. GLPI exposes search options over the API — introspect at runtime and cache on disk with a TTL (iTop uses 3600s). Matters specifically because the Fields containers (`glpi_plugin_fields_appliancetcservices`) keep evolving as the service model extends.

### XC-05 — Configurable tool/namespace prefix
Trivial config parameter (iTop: `APP_TOOLS_PREFIX`) to distinguish prod from dev instances in any AI-facing surface. Cheap; do it on day one rather than retrofitting.

---

## impact360-lite

### I360-01 — Direction-separated graph tools
Expose the existing MAX_DEPTH=3 walker as **two** distinct entry points — `get_impacted_objects` (downstream) and `get_depends_on_objects` (upstream) — rather than one bidirectional call. iTop split these for the same reason: a model conflates directions when handed a single tool.
**Effort:** near-free, walker already exists. Also the reusable half of A1.
**Note:** iTop's docs mention no depth limit. Our MAX_DEPTH=3 is a differentiator worth documenting, not hiding.

### I360-02 — Relation confidence / health indicator
Commercial ADM tools render edge state visually (Virima: health indicators on service maps). Currently an edge is present or masked. Add a confidence or freshness attribute surfaced in the graph — last-seen age, observation count, source (netstat vs manual vs inventory).
**Depends on:** netstat staging tables already carry the timestamps needed.

### I360-03 — Exportable diagram output
Device42 emits Visio-compatible diagrams for change boards. dagre.js already produces a deterministic layout, so SVG export is a small step; Visio/`.vsdx` is a bigger one and probably not worth it. Start with SVG + PNG download from the Impact tab.
**Driver:** CAB packets currently need screenshots.

---

## netstatconnections

### NSC-01 — Configurable auto-grouping rules
Device42 builds Application Groups and Business Services by grouping assets on real communication patterns using **configurable calculation rules**. Our Louvain community detection already produces candidate clusters; what's missing is a rules layer that promotes a cluster to a candidate Appliance/service with a proposed name and owner, for human confirmation. Cuts hand-modeling out of the service catalog build.
**Effort:** medium. Sits on top of the existing community detection, not a rewrite.
**Sequencing:** after C1 projection is complete.

### NSC-02 — Netflow-style fallback for unreachable hosts
Device42 maps dependencies for machines it cannot reach directly by using NetFlow. Our sensor requires an agent on the host, so appliances, network gear, and third-party-managed boxes are invisible. Candidate sources: switch flow export, firewall logs.
**Status:** idea only — no assessment of whether flow data is available or licensable on our fabric.

---

## softwaremetering

### SWM-01 — Reclaim-safety gate via impact360-lite
The strongest differentiator available to us. Virima's pitch is that its agent meters actual usage rather than installs, and links licenses through service maps so you can see which business services depend on a license *before* reclaiming it. We hold both halves: AppLocker-derived usage and the CMDB dependency graph. Before a reclaim recommendation is emitted, walk the graph and flag whether the host participates in a modeled service.
**Internal pitch line:** "reclaim without breaking a service."
**Depends on:** I360-01 (walker as callable interface).

### SWM-02 — Scope decision: do not chase entitlements
Flexera's moat is the product use rights catalog (Technopedia) covering non-production, DR, and virtualization rights. Not replicable and not the target. Keep scope at the **install-vs-actual-usage delta**, which the AppLocker sensor measures well — particularly the Office SKU disambiguation via `ProcessVersionInfoOriginalFileName`.
**Decision:** parked as a hard scope boundary, not an enhancement.

---

## servercost

### SVC-01 — Energy cost line from Carbon plugin data
Carbon 1.3.0 collects per-asset power consumption and uptime to produce environmental figures. Same input shape as the TCO tree, so an energy-cost component per Appliance is close to free once Carbon is populated. Also gives the cost model an ESG-reportable dimension.
**Prereq:** Carbon installed and inventory completion sufficient for its own reporting to work.
**Effort:** small — a cost component plus a join.

---

## uxcustomizer

### UXC-01 — Ticket-side context panel (D1)
Still absent. Asset-side context shipped; the ticket form has no equivalent. Natural first consumer of the XC-01 assembler payload — render the same JSON bundle as a read-only sidebar before any AI is involved in it. De-risks A1 by making its output visible and reviewable without a model in the loop.

---

## Platform watch items (operational, not enhancements)

| Item | Detail | Action |
|---|---|---|
| Plugin security advisory, 2026-06-29 | Critical RCE in **GenericObject** (CVSS 8.9); SQLi/XSS across several plugins; access-control faults in Escalade, Credit, Glpinventory | Inventory installed community plugins against the advisory; GenericObject first |
| GLPI 12 | GLPI Agent 1.19 (2026-08-04) ships experimental GLPI 12 support | Expect another port cycle for uxcustomizer, impact360-lite, netstatconnections; likely rebuild of the IEC 61850 Perl bindings |
| GLPI-AI | Network-subscription-exclusive, OpenAI-backed, ticket-timeline summarization only | Fails data sovereignty policy. Build decision for the AI layer stands unchanged |
| iTop 3.3 GA | Planned September 2026: native portal, containerized-environment CMDB, lifecycle management, ITOMIG AI base (multi-provider), MCP server | Re-check at GA — multi-provider AI support means iTop customers get a sovereignty-compatible path without building it |

---

## Rejected / closed

- **Refactor A1 into a tool-per-entity MCP server** — rejected. Pull composition with a large tool surface is a poor fit for a local model; keeps planning out of testable code.
- **Snapshot-based schema (iTop's `datamodel-production.xml` pattern)** — rejected, see XC-04.
- **Unbounded generic write tools** (`create-any-object` / `update-any-object` / `apply-stimulus`) — rejected for v1 of any AI surface. Read-only first; writes later as named, validated, audited operations.
- **LLM-generated query language** (iTop's NL→OQL via a separate AI extension) — rejected. Parameterized search tools with fixed criteria instead.
- **Buy the conversational layer** (UXÍA and equivalents) — already closed by data sovereignty policy.
