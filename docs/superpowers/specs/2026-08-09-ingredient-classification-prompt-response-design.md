# Ingredient Classification Prompt Response Design

## Purpose

Make the external AI classification helper useful to beginners who will read the answer and manually complete the ingredient form. The answer should be professional, concise, and understandable without exposing users to a machine-oriented JSON response.

The helper must also assist with ingredient identity. It reviews any INCI, CAS, and EC/EINECS values already entered and may propose missing or corrected values when they can be supported.

## Scope

This change covers only the prompt generated from the customer ingredient editor:

- ambiguity handling
- ingredient overview
- category and subcategory recommendations
- INCI, CAS, and EC/EINECS review and proposals
- function recommendations
- capability suggestions for soap chemistry and aromatic compliance
- confidence, sources, uncertainties, and short professional notes

The application remains disconnected from an LLM. Users copy the generated prompt into an external assistant, read its response, and manually enter the relevant values. Automatic response parsing and form filling are out of scope.

## Clarification Gate

The assistant must not classify an ingredient when its identity is unclear. It asks one to three concise, plain-language questions and waits for the answers. It must not include a provisional category, subcategory, function, or identifier while clarification is required.

Clarification is normally required for:

- an unknown or ambiguous product name
- a trade name without an INCI or composition
- a generic name that may describe several materials, such as `vitamin E`, `fragrance`, or `emulsifying wax`
- a botanical without enough information about species, plant part, preparation, or extraction medium
- a blend without enough composition information
- conflicting names or identifiers

Questions should request only information that materially improves classification, such as the supplier INCI, SDS, specification sheet, composition, botanical name, or extraction method.

## Final Response Format

Once the identity is sufficiently clear, the assistant returns readable structured text rather than JSON. It uses the following sections in this order.

### Ingredient overview

Two to four short lines explaining what the ingredient is, its usual cosmetic or soapmaking role, and any material distinction a beginner should understand.

### Classification

- Category label and exact category backing value
- Subcategory label and exact subcategory backing value, or `Not applicable`
- Brief classification reason

The assistant must select values only from the taxonomy included in the generated prompt.

### Identity review

Review INCI, CAS, and EC/EINECS separately. Each entry includes:

- the user-entered value, or `Not provided`
- the proposed value, or `No supported proposal`
- status: `consistent`, `questionable`, `missing`, or `conflicting`
- confidence: `high`, `medium`, or `low`
- a supporting source or `Not verified`

The assistant may suggest corrections to entered values. It must clearly distinguish the original value from the proposal and explain conflicts briefly. Multiple candidate identifiers are allowed when a material legitimately has variants, but the answer must explain why the candidates differ.

### Functions

- Verified COSING functions, with exact function backing keys and the official reference used
- Additional suggested functions, with exact backing keys and a short reason

A function may be described as COSING-verified only when the assistant can support that statement with an official European Commission COSING reference. Unsupported functions remain suggestions.

### Specialist review

- Whether verified soap-saponification data may be relevant
- Whether aromatic allergen or IFRA information may be relevant

These are review suggestions only. The assistant must not invent a SAP value, mark soap chemistry as trusted, or claim regulatory compliance.

### Professional notes

A short optional comment for useful cautions, unresolved ambiguity, material variants, blend limitations, or supplier-document checks. Omit this section when there is nothing meaningful to add.

## Evidence and Uncertainty Rules

- Never invent an INCI, CAS number, EC/EINECS number, COSING reference, SAP value, or source URL.
- Do not treat plausible memory as verification.
- Use `No supported proposal` or `Not verified` when evidence is insufficient.
- Prefer official regulatory sources and supplier SDS or specification documents.
- State when an identifier belongs to a component rather than the complete commercial blend.
- Keep the answer concise enough for manual use while preserving material uncertainties.

## Prompt and Interface Copy

The generated prompt will replace the current `Return JSON only` instruction with the clarification gate and readable response structure above. The compact helper description should state that the result includes classification, identifier review, and professional notes.

No new response-import interface is introduced. The existing generate, preview, and copy workflow remains unchanged.

## Testing

- Prompt tests verify that unclear ingredients trigger one to three questions and prohibit provisional guesses.
- Prompt tests verify the readable section order and the absence of a JSON-only instruction.
- Prompt tests verify review fields for entered and proposed INCI, CAS, and EC/EINECS values.
- Prompt tests verify confidence and source requirements.
- Prompt tests preserve the safeguards for COSING provenance, SAP values, aromatic compliance, and exact backing values.
- Localization tests verify the revised helper description remains in the authoritative translation catalogue.
- Run focused Pest tests, Pint, the frontend build when interface assets change, and Graphify update.
