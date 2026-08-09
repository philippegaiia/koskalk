# Admin Ingredient Classification Helper Design

## Purpose

Make the existing external-AI ingredient classification helper available when an administrator creates or edits a platform ingredient. The customer dashboard and the admin panel must generate the same prompt contract from their current unsaved identity fields.

The application remains disconnected from an LLM. An administrator generates the prompt, previews it, copies it into an external assistant, reads the response, and manually enters any useful information.

## Shared Prompt Boundary

Extract prompt construction from the customer `IngredientEditor` into a single shared builder. The builder receives an explicit input containing:

- ingredient name
- INCI name
- CAS number
- EC/EINECS number
- optional supplier notes
- response locale

The customer editor and Filament admin form map their own state paths into this input. The builder must not know about Livewire or Filament field paths. Admin does not have a genuine supplier-notes field, so its input leaves supplier notes empty rather than relabelling unrelated information.

Taxonomy and active function vocabulary are loaded only when a prompt is generated, not on every page render.

## Locale

The prompt contains an explicit instruction such as:

`Answer in: Français (fr). Keep category, subcategory, and function backing values exactly as supplied.`

The human-readable response follows the active application locale. Stable category, subcategory, and function backing values remain untranslated so users can find the corresponding application values reliably.

## Category, COSING Function, and Regulatory Status

The prompt must keep four concepts separate:

1. The catalogue category describes the ingredient's primary practical material role.
2. A verified COSING function is assigned only when the exact ingredient-function relationship is supported by a directly accessed official European Commission COSING source.
3. A practical formulation role may be described in the overview or professional notes, but must not be converted into a COSING assignment.
4. Regulatory authorization, including EU Cosmetics Regulation Annex V status, is independent from both catalogue category and COSING function.

For example, Sodium Levulinate can be catalogued under `preservation_stability` / `preservatives` because formulators use it for preservation support, while its practical antimicrobial role must not be presented as a verified COSING preservative function. If useful, the user may manually copy practical-role guidance into the ingredient information field.

The displayed `preservatives` subcategory label becomes **Preservatives & preservation boosters** while its stable backing value remains unchanged.

## Evidence Rules

The shared prompt adds these safeguards:

- `Officially verified` requires a directly accessed official source name and URL.
- Mirrors and secondary sources must be labelled as secondary evidence and cannot establish COSING verification.
- If the official COSING assignment cannot be verified, the response says `Not verified` instead of inferring a function from common use.
- Commercial product examples are included only when a directly verified source confirms the exact composition.
- Usage levels are included only when tied to the exact substance or commercial product and a named source.
- Natural-origin claims cannot be inferred from a chemical name.
- Regulatory conclusions state the jurisdiction and do not confuse absence from Annex V with proof of antimicrobial inefficacy.
- Exactly one primary catalogue classification is returned. A plausible alternative may appear only in professional notes.

The existing clarification gate, identity review, specialist review, and anti-invention safeguards remain in force.

## Admin Interaction

Add a compact Classification helper immediately after Material identity in the shared Filament ingredient schema. Because Create and Edit use this schema, both pages receive the helper automatically.

The helper:

- requires either the current display name or INCI name
- reads current unsaved display name, INCI, CAS, and EC/EINECS state
- generates without saving or mutating the ingredient
- reveals a non-persisted, read-only prompt preview
- provides a separate Copy prompt action invoked directly by the administrator
- never parses an LLM response or fills fields automatically

The generated preview is UI-only and must never be dehydrated into ingredient persistence.

## Clipboard Behaviour

Reuse the existing clipboard utility and its non-secure-context fallback. The Copy action occurs on a direct user click after generation so browsers retain the required user activation. The admin panel receives only the small clipboard asset it needs; the complete customer JavaScript bundle is not loaded into Filament.

If automatic copying is unavailable, the prompt remains selected and readable so the administrator can use the operating system copy shortcut.

## Translations

Reuse existing classification-helper translation keys wherever the wording is identical. Add admin-specific keys only where necessary. English source strings and the authoritative interface-translation catalogue remain synchronized for all supported locales.

## Testing

- A focused shared-builder test owns the complete prompt contract.
- Customer editor tests verify state mapping, the blank-identity guard, preview generation, and locale forwarding.
- Filament Create and Edit tests verify generation from current unsaved nested identity state.
- Admin tests verify the blank-identity guard, non-persisted preview, and unchanged normal save behaviour.
- Prompt tests verify explicit locale instructions, unchanged backing values, strict COSING provenance, separation of practical roles, precise regulatory language, and evidence rules.
- Clipboard contract tests cover secure copying, rejected writes, and the HTTP fallback.
- Localization and interface-catalogue tests cover all new interface copy.
- Final verification includes focused Pest suites, Filacheck, Pint, frontend build when assets change, `git diff --check`, and Graphify refresh.

## Out of Scope

- Connecting the application to an LLM
- Parsing or importing an assistant response
- Automatically assigning categories, functions, identifiers, capabilities, or regulatory data
- Treating practical roles as COSING-verified functions
- Automatically trusting soap chemistry or aromatic compliance data
