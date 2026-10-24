<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\EvidenceType;
use App\Enums\IngredientCategory;
use App\Enums\SupplierQualificationStatus;
use App\Enums\UseLimitType;
use App\Models\DossierEvidenceItem;
use App\Models\DossierEvidenceUseLimit;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * IFRA check of each recipe ingredient as used (phase 3b decisions 1–6): the ingredient as purchased — a blend at its
 * whole dosage — against the formula's IFRA category and amendment. Supplier certificates conclude only when they record
 * the amendment and cover the qualified grade; the ingredient's own certificate is shown but never concludes. Missing or
 * unusable information stays « À justifier ».
 */
class IfraUseChecker
{
    /**
     * Uncovered reasons, from the closest to applicable to the furthest.
     */
    private const UNCOVERED_ORDER = ['duplicate_category_limits', 'no_limit_for_category', 'grade_not_confirmed', 'amendment_mismatch', 'amendment_missing'];

    private const MAX_COMPONENT_DEPTH = 3;

    /**
     * @param  list<array{key: string, ingredient_id: int|null, grams: string}>  $initial
     * @return list<array<string, mixed>>
     */
    public function check(Workspace $workspace, array $initial, string $curedMass, ?IfraProductCategory $category, ?int $amendmentId, ?string $categoryJustification): array
    {
        $settingReason = $this->settingReason($category, $amendmentId, $categoryJustification);

        return collect($initial)
            ->whereNotNull('ingredient_id')
            ->groupBy('ingredient_id')
            ->map(function (Collection $rows, int|string $ingredientId) use ($workspace, $curedMass, $category, $amendmentId, $settingReason): array {
                $ingredient = Ingredient::withoutGlobalScopes()->find($ingredientId);
                $concentration = DecimalMath::percentOf(DecimalMath::add(...$rows->pluck('grams')->map(fn (mixed $grams): string => DecimalMath::of($grams))->all()), $curedMass);
                $suppliers = $ingredient instanceof Ingredient && $category !== null && $amendmentId !== null
                    ? $this->supplierCoverage($workspace, $ingredient, $category->id, $amendmentId)
                    : [];
                $fallback = $ingredient instanceof Ingredient && $category !== null ? $this->ingredientCertificate($ingredient, $category->id, $amendmentId) : null;
                $applicable = $ingredient instanceof Ingredient && $this->isApplicable($workspace, $ingredient, $fallback);
                $result = $applicable
                    ? $this->state($concentration, $suppliers, $fallback, $settingReason)
                    : ['state' => 'not_applicable', 'reason' => null, 'limit_percent' => null, 'limit_type' => null];

                return [
                    'ingredient_id' => (int) $ingredientId,
                    'name' => $ingredient?->display_name,
                    'initial_keys' => $rows->pluck('key')->values()->all(),
                    'concentration_percent' => $concentration,
                    'category_id' => $category?->id,
                    'amendment_id' => $amendmentId,
                    ...$result,
                    'suppliers' => $suppliers,
                    'uncovered' => array_values(array_filter($suppliers, fn (array $supplier): bool => ! $supplier['covered'])),
                    'fallback' => $fallback,
                ];
            })
            ->values()
            ->all();
    }

    private function settingReason(?IfraProductCategory $category, ?int $amendmentId, ?string $categoryJustification): ?string
    {
        if ($category === null || ($category->code !== (string) config('dossiers.pilot_ifra_category_code') && blank($categoryJustification))) {
            return 'ifra_category_unresolved';
        }

        return $amendmentId === null ? 'ifra_amendment_unresolved' : null;
    }

    /**
     * Decision 2: a fragrance role or IFRA data on the ingredient, or on a linked component, so a blend containing a
     * fragrance is never skipped.
     *
     * @param  array<string, mixed>|null  $fallback
     */
    private function isApplicable(Workspace $workspace, Ingredient $ingredient, ?array $fallback, int $depth = 0): bool
    {
        if ($fallback !== null || $this->hasFragranceRole($workspace, $ingredient)) {
            return true;
        }

        if ($depth >= self::MAX_COMPONENT_DEPTH) {
            return false;
        }

        return IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('component_ingredient_id')
            ->with(['componentIngredient' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()
            ->contains(fn (IngredientComponent $component): bool => $component->componentIngredient instanceof Ingredient
                && $this->isApplicable($workspace, $component->componentIngredient, null, $depth + 1));
    }

    private function hasFragranceRole(Workspace $workspace, Ingredient $ingredient): bool
    {
        return $ingredient->category === IngredientCategory::AromaticMaterials
            || (bool) $ingredient->requires_aromatic_compliance
            || IfraCertificate::query()->where('ingredient_id', $ingredient->id)->exists()
            || DossierEvidenceItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('type', EvidenceType::IfraCertificate->value)
                ->whereHas('supplierQualification', fn ($query) => $query->where('ingredient_id', $ingredient->id))
                ->exists();
    }

    /**
     * One entry per validated qualification: covered with its limit, or uncovered with the reason.
     *
     * @return list<array{qualification_id: int, covered: bool, reason: string|null, evidence_item_id: int|null, limit_type: string|null, max_percent: string|null}>
     */
    private function supplierCoverage(Workspace $workspace, Ingredient $ingredient, int $categoryId, int $amendmentId): array
    {
        return SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->orderBy('id')
            ->get()
            ->map(function (SupplierQualification $qualification) use ($categoryId, $amendmentId): array {
                $certificates = DossierEvidenceItem::query()
                    ->where('supplier_qualification_id', $qualification->id)
                    ->where('type', EvidenceType::IfraCertificate->value)
                    ->whereNull('retired_at')
                    ->with('useLimits')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (DossierEvidenceItem $item): array => $this->certificateStatus($item, $categoryId, $amendmentId));
                $applicable = $certificates->where('reason', null);

                if ($applicable->isEmpty()) {
                    $reasons = $certificates->pluck('reason');

                    return [
                        'qualification_id' => $qualification->id,
                        'covered' => false,
                        'reason' => collect(self::UNCOVERED_ORDER)->first(fn (string $reason): bool => $reasons->contains($reason)) ?? 'no_certificate',
                        'evidence_item_id' => null,
                        'limit_type' => null,
                        'max_percent' => null,
                    ];
                }

                $strictest = $this->strictest($applicable);

                return ['qualification_id' => $qualification->id, 'covered' => true, 'reason' => null, ...$strictest];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{evidence_item_id: int, reason: string|null, limit_type: string|null, max_percent: string|null}
     */
    private function certificateStatus(DossierEvidenceItem $item, int $categoryId, int $amendmentId): array
    {
        $facts = $item->facts ?? [];
        $limits = $item->useLimits->filter(fn (DossierEvidenceUseLimit $row): bool => (int) $row->ifra_product_category_id === $categoryId)->values();
        // A category stated twice is contradictory: neither entry is used (decision 5).
        $limit = $limits->count() === 1 ? $limits->first() : null;
        $reason = match (true) {
            ! isset($facts['ifra_amendment_id']) => 'amendment_missing',
            (int) $facts['ifra_amendment_id'] !== $amendmentId => 'amendment_mismatch',
            ($facts['covers_supplied_grade'] ?? null) !== true => 'grade_not_confirmed',
            $limits->count() > 1 => 'duplicate_category_limits',
            ! $limit instanceof DossierEvidenceUseLimit => 'no_limit_for_category',
            default => null,
        };

        return [
            'evidence_item_id' => $item->id,
            'reason' => $reason,
            'limit_type' => $limit?->limit_type?->value,
            'max_percent' => $limit?->limit_type === UseLimitType::Maximum ? DecimalMath::of($limit->max_percent) : null,
        ];
    }

    /**
     * The strictest of several applicable certificates: the lowest maximum; "no restriction" only when none states a
     * maximum.
     *
     * @param  Collection<int, array<string, mixed>>  $certificates
     * @return array{evidence_item_id: int, limit_type: string, max_percent: string|null}
     */
    private function strictest(Collection $certificates): array
    {
        $maxima = $certificates->where('limit_type', UseLimitType::Maximum->value)
            ->sort(fn (array $left, array $right): int => DecimalMath::cmp($left['max_percent'], $right['max_percent']));
        $chosen = $maxima->first() ?? $certificates->first();

        return ['evidence_item_id' => $chosen['evidence_item_id'], 'limit_type' => $chosen['limit_type'], 'max_percent' => $chosen['max_percent']];
    }

    /**
     * The ingredient's current IFRA certificate for the category: a blank maximum is unknown, never "no limit".
     *
     * @return array{certificate_id: int, amendment_matches: bool, max_percent: string|null, restriction_note: string|null, duplicate_category_limits: bool}|null
     */
    private function ingredientCertificate(Ingredient $ingredient, int $categoryId, ?int $amendmentId): ?array
    {
        $certificate = IfraCertificate::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('is_current', true)
            ->with('limits')
            ->orderByDesc('id')
            ->first();

        if (! $certificate instanceof IfraCertificate) {
            return null;
        }

        $limits = $certificate->limits->filter(fn (IfraCertificateLimit $row): bool => (int) $row->ifra_product_category_id === $categoryId)->values();
        $limit = $limits->count() === 1 ? $limits->first() : null;

        return [
            'certificate_id' => $certificate->id,
            'amendment_matches' => $amendmentId !== null && (int) $certificate->ifra_amendment_id === $amendmentId,
            // Blank, repeated or missing: unknown, never "no limit".
            'max_percent' => $limit?->max_percentage === null ? null : DecimalMath::of($limit->max_percentage),
            'restriction_note' => $limit?->restriction_note,
            'duplicate_category_limits' => $limits->count() > 1,
        ];
    }

    /**
     * Decision 6.
     *
     * @param  list<array<string, mixed>>  $suppliers
     * @param  array<string, mixed>|null  $fallback
     * @return array{state: string, reason: string|null, limit_percent: string|null, limit_type: string|null}
     */
    private function state(string $concentration, array $suppliers, ?array $fallback, ?string $settingReason): array
    {
        $result = fn (string $state, ?string $reason, ?string $limit = null, ?string $type = null): array => [
            'state' => $state,
            'reason' => $reason,
            'limit_percent' => $limit,
            'limit_type' => $type,
        ];

        if ($settingReason !== null) {
            return $result('a_justifier', $settingReason);
        }

        $covered = collect($suppliers)->where('covered', true);
        $strictest = $covered->isEmpty() ? null : $this->strictest($covered);

        if ($strictest !== null && $strictest['max_percent'] !== null && DecimalMath::cmp($concentration, $strictest['max_percent']) > 0) {
            return $result('non_conforme', 'ifra_limit_exceeded', $strictest['max_percent'], UseLimitType::Maximum->value);
        }

        if ($suppliers === []) {
            return match (true) {
                $fallback === null || $fallback['max_percent'] === null => $result('a_justifier', 'ifra_no_evidence'),
                DecimalMath::cmp($concentration, $fallback['max_percent']) > 0 => $result('a_justifier', 'ifra_fallback_limit_exceeded', $fallback['max_percent'], UseLimitType::Maximum->value),
                default => $result('a_justifier', 'ifra_fallback_only', $fallback['max_percent'], UseLimitType::Maximum->value),
            };
        }

        if ($covered->count() < count($suppliers)) {
            return $result('a_justifier', 'ifra_supplier_uncovered', $strictest['max_percent'] ?? null, $strictest['limit_type'] ?? null);
        }

        return $result('conforme', null, $strictest['max_percent'], $strictest['limit_type']);
    }
}
