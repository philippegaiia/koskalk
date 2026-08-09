# Ingredient Regulatory Review and Copy Controls Design

## Purpose

Extend the manual external-AI ingredient classification prompt with an evidence-based regulatory review for the European Union and United States. Repair the admin clipboard interaction and make Generate prompt and Copy prompt equally discoverable in both the customer dashboard and Filament admin panel.

The application remains disconnected from an LLM. Users generate a prompt, copy it into an external assistant, read the response, and manually enter useful information.

## Regulatory Review Scope

The review covers:

- the exact identified ingredient;
- every declared component of a commercial blend when an authoritative supplier INCI, SDS, specification, or composition is available;
- an explicit incomplete result when a blend's components cannot be established.

An unidentified or insufficiently described blend triggers the existing clarification gate. The assistant asks for the supplier INCI, SDS, specification sheet, or composition before issuing classification, identifier, function, or regulatory conclusions.

## EU Review

The prompt requires a check against the current consolidated Regulation (EC) No 1223/2009 and its applicable annexes:

- Annex II for prohibited substances;
- Annex III for restricted substances and conditions;
- Annex IV for authorised colorants when relevant;
- Annex V for authorised preservatives when relevant;
- Annex VI for authorised UV filters when relevant.

The legal conclusion must cite the exact annex entry and conditions, together with a directly accessed official EUR-Lex or European Commission URL. CosIng may assist identification, but its inventory and function records do not independently establish legal authorisation. The European Commission itself states that CosIng is informative and that Regulation (EC) No 1223/2009 and its annexes determine regulatory status.

## U.S. FDA Review

The prompt requires a check of the FDA's current prohibited and restricted cosmetic ingredient information and the applicable official 21 CFR provision. When relevant, the review also flags:

- color-additive authorisation and intended-use conditions;
- therapeutic or sunscreen claims that may make the product a drug or drug/cosmetic;
- restrictions that depend on product type, body area, concentration, aerosol use, or another condition.

The response must not call an ordinary cosmetic ingredient “FDA approved.” In the United States, cosmetic ingredients generally do not require premarket FDA approval, except for applicable color additives, but the manufacturer remains responsible for product safety and legal compliance.

## Regulatory Evidence and Status Vocabulary

Each jurisdiction returns exactly one of these statuses:

- `Prohibited`
- `Restricted`
- `No specific restriction found`
- `Not verified`

For `Prohibited` or `Restricted`, the response identifies the exact matched substance or component, legal entry, relevant conditions, and directly accessed official URL.

`No specific restriction found` means only that the reviewed official sources did not reveal a specific prohibition or restriction for the identified material. It must never be presented as approval, proof of safety, complete legal clearance, or confirmation that a finished product complies.

`Not verified` is required when the identity, blend composition, official source access, or legal match is insufficient. Secondary sources may be mentioned as secondary evidence but cannot establish the final regulatory status.

The prompt must not invent legal entries, URLs, concentrations, conditions, or regulatory conclusions. It should state the date or version of the official material reviewed when the source exposes one.

## Response Structure

The existing `Specialist review` gains two lines:

- `EU regulatory status:` status, exact material or component reviewed, annex and entry where applicable, conditions, and official source URL or `Not verified`.
- `U.S. FDA regulatory status:` status, exact material or component reviewed, applicable 21 CFR citation and conditions, and official FDA/eCFR URL or `Not verified`.

Soap saponification and aromatic allergen / IFRA reviews remain separate. Catalogue category, COSING functions, practical formulation roles, and regulatory status remain independent concepts.

## Copy-Control Interaction

Both customer and admin helpers display `Generate prompt` and `Copy prompt` side by side where space permits. On narrow screens the controls may wrap while preserving their order.

`Copy prompt` is visible but disabled until a prompt has been generated. Generating a new prompt replaces the current preview, after which Copy uses that latest prompt.

The customer dashboard retains its working clipboard behavior. The Filament admin implementation stops depending on an Alpine component registered during `alpine:init`. The current Vite module can load after Filament has already initialized Alpine, which hides the conditional button labels and leaves an empty button shell.

Admin copying uses an external delegated click handler attached to a stable `data-*` control. Event delegation survives Livewire DOM morphs and does not depend on Alpine initialization order. It reuses the existing clipboard utility, including the direct Clipboard API attempt and the synchronous fallback for non-secure local contexts.

The button always has a server-rendered label. A successful copy briefly changes the visible label to the translated copied state. Failure reveals translated manual-copy guidance while leaving the prompt selectable.

## Translations

Existing translated Generate, Copy, Copied, and failure strings are reused. Any new regulatory interface copy is unnecessary because the regulatory instructions live inside the generated prompt, not the application chrome. If new interface text becomes necessary during implementation, the English language catalogue and all supported interface-translation entries must remain synchronized.

## Testing

- Extend the shared prompt-builder contract test first and observe it fail before implementation.
- Assert the exact EU and U.S. specialist-review lines, four-status vocabulary, component-level blend review, official-source requirement, and the prohibition against treating “No specific restriction found” as approval or proof of safety.
- Assert that ambiguous blends remain behind the clarification gate.
- Add markup/JavaScript contract tests proving both views render Generate and Copy together, Copy starts disabled without a prompt, and admin copying uses delegated event handling rather than an Alpine data component.
- Preserve the existing clipboard utility tests for secure copying, rejected writes, and the HTTP fallback.
- Run focused Pest suites, Pint, the frontend production build, `git diff --check`, and Graphify refresh.

## Authoritative Reference Baseline

- European Commission, Cosmetic ingredient database: https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database_en
- EUR-Lex, current consolidated Regulation (EC) No 1223/2009: https://eur-lex.europa.eu/eli/reg/2009/1223/2026-05-01/eng
- U.S. FDA, Prohibited & Restricted Ingredients in Cosmetics: https://www.fda.gov/cosmetics/cosmetics-laws-regulations/prohibited-restricted-ingredients-cosmetics
- U.S. FDA, Cosmetic Ingredients: https://www.fda.gov/cosmetics/cosmetic-products-ingredients/cosmetic-ingredients

The generated prompt instructs the external assistant to access and cite current official sources. These reference URLs are a baseline, not a frozen substitute for checking the latest legal text.

## Out of Scope

- Connecting the application to an LLM
- Automatically importing an assistant response
- Automatically deciding whether a finished formulation complies
- Replacing a cosmetic safety assessment, responsible-person review, legal advice, or FDA regulatory determination
- Persisting regulatory conclusions as structured ingredient records
- Expanding the review to additional jurisdictions in this change
