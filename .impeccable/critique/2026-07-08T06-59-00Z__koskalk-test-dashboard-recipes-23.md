---
target: formula bench new color scheme
total_score: 29
p0_count: 0
p1_count: 1
timestamp: 2026-07-08T06-59-00Z
slug: koskalk-test-dashboard-recipes-23
---
**Design Health Score**

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Header, active nav, tabs, setup chips, formula status, and save state are visible, but color roles overlap. |
| 2 | Match System / Real World | 3 | The forest sidebar plus paper workbench feels like a serious formulation workspace. Copper is too close to lye/warning language. |
| 3 | User Control and Freedom | 3 | Save, lock, formula sheet, tabs, and settings are available without trapping the user. |
| 4 | Consistency and Standards | 3 | Components are coherent, but active state, CTA, focus, drag/drop, and setup chips overuse the same accent family. |
| 5 | Error Prevention | 3 | Totals, locked-state controls, and diagnostics provide guardrails. Visual severity would improve with clearer semantic color separation. |
| 6 | Recognition Rather Than Recall | 3 | Sidebar and tab structure are familiar. The color system does not help users classify state quickly enough. |
| 7 | Flexibility and Efficiency | 3 | Dense formula editing and persistent bottom save/status bar support repeat work. |
| 8 | Aesthetic and Minimalist Design | 3 | Registered desktop is calmer than the public calculator, but the mobile first viewport is still dominated by stacked peach active tab and chips. |
| 9 | Error Recovery | 2 | Error messaging exists in source, but normal selected states and caution/chemistry states are too visually related. |
| 10 | Help and Documentation | 3 | Labels and summary chips are helpful; no contextual explanation for color/state differences. |
| **Total** | | **29/40** | **Good: strong shell and direction, color semantics need cleanup** |

**Anti-Patterns Verdict**

This registered recipe workbench does not read as generic AI UI. The dark forest sidebar is the strongest part of the new scheme: it gives the app a serious workspace anchor and separates navigation from the warm paper formula surface.

The weak point is product semantics. Copper/amber is doing too many jobs: active sidebar nav, selected Formula tab, Save CTA, active radio controls, focus rings, setup chips, catalog tone, and some compliance/context markers. The interface is attractive, but the color vocabulary is not yet disciplined enough for a dense formula tool.

**Deterministic Scan**

Skipped. The local npx detector attempted to fetch from npm and the escalated run was rejected because it would execute third-party package code against a private workspace.

**Overall Impression**

The registered route is better than the public calculator route I first inspected. The app shell makes the color direction feel intentional and professional. The formula bench itself still needs a stricter split between brand accent, active selection, chemistry, warning, info, and success.

**What's Working**

1. The forest sidebar gives the workspace a grounded, premium, non-generic identity.
2. The saved formula header is clean: formula name, sheet, save, lock, more actions. It feels like a real tool.
3. Numeric trust remains strong: aligned values, monospace figures, totals, and calculated lye/water are legible.

**Priority Issues**

**[P1] Copper overload collapses product-state meaning**

Why it matters: users need to distinguish selected navigation, active formula controls, primary save action, chemistry context, warning, and compliance at a glance. The current palette makes too many of these warm/copper-adjacent, so the color stops carrying useful meaning.

Fix: split tokens by job. Keep copper for the primary Save action and maybe the brand/public shell. Use a quieter neutral or green-tinted active state for tabs and setup controls. Keep chemistry/warning on their own amber role, visibly distinct from normal selection.

Suggested command: impeccable colorize formula bench

**[P2] Mobile first viewport is still setup-heavy**

Why it matters: on mobile, the user sees header, formula actions, five stacked tabs, and setup chips before the actual formula rows. The selected Formula tab is a large peach band, so the visual priority is navigation/setup rather than editing.

Fix: make mobile tabs more compact, or convert them to a segmented/scrollable tab row. Reduce active tab fill to a quieter rule/dot/outline treatment.

Suggested command: impeccable adapt formula bench

**[P2] Active sidebar nav is hard to read as current location**

Why it matters: on the dark forest rail, the Recipes item uses translucent copper with amber text. It looks warm and branded, but not as crisp as a location marker. It competes with the Admin badge and primary action color.

Fix: use a clearer active-sidebar recipe: cream text, subtle filled forest-mid panel, and a small accent marker/dot. Keep copper out of inactive navigation.

Suggested command: impeccable polish formula bench

**[P2] Literal white weakens the paper/ink system**

Why it matters: the workbench still uses many pure white utilities for inactive tabs, chips, table cells, popovers, and buttons. Against the carefully tinted panel/surface system, white reads slightly raw and makes copper contrast feel harsher.

Fix: introduce product control tokens such as --color-control, --color-control-muted, and --color-on-accent. Replace repeated bg-white/text-white in workbench primitives with tinted equivalents that preserve AA contrast.

Suggested command: impeccable polish formula bench

**Persona Red Flags**

**Alex, power user**: The table and bottom bar are efficient, but Alex cannot rely on color as a fast state language because Save, selected nav, selected tab, and selected controls all share the same family.

**Jordan, first-timer**: Jordan sees a polished workspace, but may not know whether warm chips mean selected, important, chemical, or warning. The setup summary is useful, but the color meanings are not self-evident.

**Sam, accessibility-dependent user**: Contrast is generally acceptable, but state is conveyed heavily through similar hue changes. More non-color indicators for selected tabs, current nav, and diagnostic severity would help.

**Minor Observations**

- The dark sidebar is worth keeping.
- The registered header works better than the public calculator header.
- The bottom save/status bar is useful, but it should remain visually quieter than the active editing surface.
- The current formula page should stay product-register: no display serif inside dense workbench internals.

**Questions to Consider**

1. Should copper mean primary action, or chemistry? It should not mean both.
2. Should mobile users see all five workbench tabs as stacked cards, or a compact tab control?
3. Can the sidebar active state become location-first instead of brand-accent-first?
