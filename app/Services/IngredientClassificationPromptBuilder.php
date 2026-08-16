<?php

namespace App\Services;

use App\Data\IngredientClassificationPromptInput;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\IngredientFunction;
use InvalidArgumentException;

class IngredientClassificationPromptBuilder
{
    public function __construct(
        private readonly SupportedLocaleCatalog $localeCatalog,
    ) {}

    public function build(IngredientClassificationPromptInput $input): string
    {
        $taxonomy = collect(IngredientCategory::cases())
            ->map(function (IngredientCategory $category) use ($input): string {
                $subcategories = collect($category->subcategories())
                    ->map(fn (IngredientSubcategory $subcategory): string => sprintf(
                        '- %s (%s)',
                        $subcategory->localizedLabel($input->responseLocale),
                        $subcategory->value,
                    ))
                    ->implode("\n");

                return sprintf('%s (%s)', $category->localizedLabel($input->responseLocale), $category->value)
                    .($subcategories === '' ? '' : "\n{$subcategories}");
            })
            ->implode("\n\n");

        $functionVocabulary = IngredientFunction::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(fn (IngredientFunction $function): string => sprintf(
                '- %s (%s)',
                $function->localizedName($input->responseLocale),
                $function->key,
            ))
            ->implode("\n");

        $identity = $this->prettyJson([
            'name' => $input->name,
            'inci_name' => $input->inciName,
            'cas_number' => $input->casNumber,
            'ec_number' => $input->ecNumber,
            'additional_identifiers' => $input->additionalIdentifiers,
            'supplier_notes' => $input->supplierNotes,
        ]);
        $responseLanguage = $this->responseLanguage($input->responseLocale);

        return <<<PROMPT
Classify and review the identity of the cosmetic or soapmaking ingredient below. For category, subcategory, and function selections, use only the backing values supplied here. Write for a beginner while applying professional cosmetic terminology.

Answer in: {$responseLanguage}. Keep category, subcategory, and function backing values exactly as supplied.

Current ingredient:
{$identity}

Taxonomy:
{$taxonomy}

Available COSING function vocabulary:
{$functionVocabulary}

Clarification gate:
- If the ingredient identity is unclear, ask one to three concise plain-language questions and wait for the user's answers.
- Do not classify the ingredient or propose identifiers until the user answers.
- Clarify unknown or ambiguous product names, trade names without an INCI or composition, generic names that may represent several materials, incomplete botanicals, insufficiently described blends, and conflicting names or identifiers.
- Ask only for information that materially improves identification, such as the supplier INCI, SDS, specification sheet, composition, botanical species and plant part, or extraction method.
- For a commercial blend, review the exact ingredient and every declared component of a commercial blend only when an authoritative supplier INCI, SDS, specification sheet, or composition establishes them.
- Do not issue a regulatory conclusion for a blend until its components are established. Ask for the missing supplier documentation instead.

Rules for a sufficiently clear ingredient:
- Return readable structured text, not JSON.
- Return exactly one category label with its backing value. Return one compatible subcategory label with its backing value unless the category is "other". Mention a plausible alternative only in Professional notes.
- A catalogue category describes the primary practical material role. It is independent from COSING function assignments.
- A catalogue placement under preservation does not establish authorization under EU Cosmetics Regulation Annex V. State the jurisdiction when discussing regulatory status, and do not treat absence from Annex V as proof that a material has no antimicrobial efficacy.
- Review the exact ingredient and every declared component of a commercial blend separately for EU and U.S. regulatory restrictions.
- For the EU, check the current consolidated Regulation (EC) No 1223/2009: Annex II for prohibited substances, Annex III for restricted substances, Annex IV for colorants, Annex V for preservatives, and Annex VI for UV filters when relevant.
- Treat CosIng as informative for identification and functions, not as legal authorisation. The regulation and its annexes establish EU regulatory status.
- For the United States, check official FDA prohibited and restricted cosmetic ingredient information and the applicable official 21 CFR provision. Flag color-additive intended-use requirements and drug or drug/cosmetic implications when relevant.
- Use only these regulatory statuses: Prohibited, Restricted, No specific restriction found, or Not verified.
- For Prohibited or Restricted, identify the exact matched substance or blend component, legal entry, conditions, and a directly accessed official URL.
- No specific restriction found must not be described as approval, proof of safety, complete legal clearance, or finished-product compliance.
- Use Not verified when identity, blend composition, official source access, or legal matching is insufficient.
- Never call an ordinary U.S. cosmetic ingredient FDA approved. Cosmetic ingredients generally do not require FDA premarket approval, except for applicable color additives.
- State the date or version of official material reviewed when the source exposes one. Do not invent a legal entry, condition, concentration, URL, or regulatory conclusion.
- A function describes what the ingredient does; it does not replace its material category. Conditioning agents may occur in several categories.
- Practical formulation roles must not be returned as COSING assignments. They may be described without backing keys in the overview or Professional notes so the user can copy useful information manually.
- Review every user-entered INCI, CAS, and EC / EINECS value. Clearly distinguish the user-entered value from any proposed correction.
- For a missing identifier, provide a proposal only when it is supportable. Otherwise write "No supported proposal".
- For each identifier, give a status of consistent, questionable, missing, or conflicting; a confidence of high, medium, or low; and a supporting source or "Not verified".
- Multiple identifier candidates are allowed only when legitimate material variants exist; explain the distinction briefly.
- Suggest aromatic allergen or IFRA review only when relevant to this exact material.
- Suggest soap-saponification review when the material may need a verified KOH SAP value. This suggestion cannot establish trusted soap chemistry; never infer trust merely from "oil", "butter", "fat", or "wax".
- Describe a function as COSING-verified only when it is assigned to this exact ingredient and supported by a directly accessed official European Commission COSING source name and URL. Otherwise write "Not verified"; do not infer a COSING assignment from common formulation use.
- Label mirrors and secondary sources as secondary evidence. They cannot establish official COSING verification.
- Include an official source name and URL only when that source was actually accessed. Do not invent an INCI, CAS number, EC / EINECS number, SAP value, COSING reference, source URL, or regulatory status. Do not treat plausible memory as verification.
- Do not include a commercial product example unless a directly accessed manufacturer source confirms its exact composition.
- Do not provide a usage level unless it is tied to the exact substance or commercial product and a named, accessed source.
- Do not infer natural origin from a chemical name. State an origin claim only when a source supports it for the exact material.
- State when an identifier belongs to a component rather than the complete commercial blend.

Use these exact headings when the ingredient is sufficiently clear:

Ingredient overview
Write two to four short lines explaining what the ingredient is, its usual cosmetic or soapmaking role, and any material distinction a beginner should understand.

Classification
- Category: label (backing_value)
- Subcategory: label (backing_value), or Not applicable
- Reason: brief professional explanation

Identity review
- INCI
  - User-entered value: value or Not provided
  - Proposed value: value or No supported proposal
  - Status: consistent, questionable, missing, or conflicting
  - Confidence: high, medium, or low
  - Source: supporting source or Not verified
- CAS number
  - User-entered value: value or Not provided
  - Proposed value: value or No supported proposal
  - Status: consistent, questionable, missing, or conflicting
  - Confidence: high, medium, or low
  - Source: supporting source or Not verified
- EC / EINECS number
  - User-entered value: value or Not provided
  - Proposed value: value or No supported proposal
  - Status: consistent, questionable, missing, or conflicting
  - Confidence: high, medium, or low
  - Source: supporting source or Not verified
- Additional identifiers
  - Review every additional CAS, EC / EINECS, UNII, ECHA List Number, InChIKey, or PubChem CID separately and distinguish its identifier scheme clearly.
  - Report conflicting or alternative CAS and EC / EINECS values without silently choosing one.
  - Do not treat an ECHA list number as an EC / EINECS number.

Functions
- Verified COSING functions: list only functions officially assigned to this exact ingredient, each with its exact backing key and the directly accessed official European Commission COSING source URL; otherwise write Not verified
- Practical formulation roles: describe useful non-COSING roles in plain text only, or None. Do not return function backing keys here.

Specialist review
- Soap saponification: Relevant or Not relevant, with a short reason
- Aromatic allergen / IFRA: Relevant or Not relevant, with a short reason
- EU regulatory status: Prohibited, Restricted, No specific restriction found, or Not verified; identify the exact material or component reviewed, applicable annex and entry, conditions, and directly accessed official EUR-Lex or European Commission URL
- U.S. FDA regulatory status: Prohibited, Restricted, No specific restriction found, or Not verified; identify the exact material or component reviewed, applicable 21 CFR citation and conditions, and directly accessed official FDA or eCFR URL

Professional notes
Add one short comment only when a useful caution, unresolved ambiguity, material variant, blend limitation, supplier-document check, or verified regulatory distinction remains. Otherwise omit this section.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function prettyJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function responseLanguage(string $locale): string
    {
        $normalizedLocale = str_replace('_', '-', trim($locale));
        $normalizedLocale = $normalizedLocale !== '' ? $normalizedLocale : 'en';
        $localeCandidates = array_values(array_unique([
            $normalizedLocale,
            explode('-', $normalizedLocale)[0],
        ]));

        foreach ($localeCandidates as $localeCandidate) {
            try {
                $metadata = $this->localeCatalog->metadata($localeCandidate);
            } catch (InvalidArgumentException) {
                continue;
            }

            if (mb_strtolower($metadata['name']) === mb_strtolower($metadata['native_name'])) {
                return sprintf('%s (%s)', $metadata['name'], $metadata['code']);
            }

            return sprintf('%s — %s (%s)', $metadata['name'], $metadata['native_name'], $metadata['code']);
        }

        return $normalizedLocale;
    }
}
