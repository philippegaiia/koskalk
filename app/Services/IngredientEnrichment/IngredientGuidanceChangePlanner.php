<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Models\Ingredient;
use App\Services\IngredientTranslationSourceFingerprint;

class IngredientGuidanceChangePlanner
{
    public function __construct(
        private readonly IngredientTranslationSourceFingerprint $translationFingerprint,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $editedFields
     * @return array<string, mixed>
     */
    public function plan(
        Ingredient $ingredient,
        array $result,
        IngredientEnrichmentBatchMode $mode,
        array $editedFields = [],
    ): array {
        $currentEnglish = (string) ($ingredient->info_markdown ?? '');
        $proposedEnglish = (string) ($result['info_markdown'] ?? '');
        $englishDecision = $currentEnglish === $proposedEnglish
            ? null
            : [
                'field' => 'proposal.info_markdown',
                'decision' => 'replace',
                'current' => $currentEnglish,
                'proposed' => $proposedEnglish,
            ];

        $currentTranslations = $ingredient->translations()
            ->get(['locale', 'display_name', 'saponification_name', 'info_markdown', 'source_fingerprint'])
            ->keyBy('locale');
        $canonicalTranslationFingerprint = $this->translationFingerprint->forIngredient($ingredient);
        $proposedTranslations = is_array($result['translations'] ?? null)
            ? $result['translations']
            : [];
        $translationDecisions = collect($proposedTranslations)
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->flatMap(function (array $translation) use ($currentTranslations, $canonicalTranslationFingerprint): array {
                $locale = (string) ($translation['locale'] ?? '');
                $currentTranslation = $currentTranslations->get($locale);
                $decisions = collect(['display_name', 'saponification_name', 'info_markdown'])
                    ->filter(fn (string $field): bool => array_key_exists($field, $translation))
                    ->filter(fn (string $field): bool => $currentTranslation?->{$field} !== $translation[$field])
                    ->map(fn (string $field): array => [
                        'field' => "proposal.translations.{$locale}.{$field}",
                        'decision' => 'replace',
                        'current' => $currentTranslation?->{$field},
                        'proposed' => $translation[$field],
                    ])
                    ->values()
                    ->all();

                if ($decisions === [] && $currentTranslation?->source_fingerprint !== $canonicalTranslationFingerprint) {
                    $decisions[] = [
                        'field' => "proposal.translations.{$locale}.info_markdown",
                        'decision' => 'revalidate',
                        'current' => $currentTranslation?->info_markdown,
                        'proposed' => $translation['info_markdown'] ?? null,
                    ];
                }

                return $decisions;
            })
            ->values();

        $proposedEvidence = collect(is_array($result['guidance_evidence'] ?? null)
            ? $result['guidance_evidence']
            : [])
            ->filter(fn (mixed $evidence): bool => is_array($evidence))
            ->values()
            ->all();
        $currentEvidence = collect(data_get($ingredient->source_data, 'enrichment.guidance.evidence', []))
            ->filter(fn (mixed $evidence): bool => is_array($evidence))
            ->values()
            ->all();
        $effectiveEvidence = $mode->isLocalizationOnly()
            ? $currentEvidence
            : $proposedEvidence;
        $evidenceDecision = $effectiveEvidence !== $currentEvidence
            ? [[
                'field' => 'guidance.evidence',
                'decision' => $currentEvidence === [] ? 'new' : 'replace',
                'current' => $currentEvidence,
                'proposed' => $effectiveEvidence,
            ]]
            : [];

        $decisions = collect([$englishDecision])
            ->filter(fn (mixed $decision): bool => is_array($decision))
            ->merge($translationDecisions)
            ->merge($evidenceDecision)
            ->values()
            ->all();

        return [
            'changed' => collect($decisions)->contains(
                fn (array $decision): bool => in_array($decision['decision'], ['new', 'replace', 'revalidate'], true),
            ),
            'decisions' => $decisions,
            'effective' => [
                'info_markdown' => $proposedEnglish,
                'translations' => $proposedTranslations,
                'guidance_evidence' => $effectiveEvidence,
            ],
        ];
    }
}
