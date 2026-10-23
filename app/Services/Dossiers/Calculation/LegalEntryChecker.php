<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\LegalAnnex;
use App\Enums\LegalEntryProductScope;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\Substance;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * EU annex checks (spec §4.2, decision 13). Binding requirements are automatic: a banned substance present or an
 * exceeded maximum is a breach whatever the other indicators say. Conditions written as text cannot be checked
 * automatically and are flagged for verification.
 */
class LegalEntryChecker
{
    private const REGIME = 'eu';

    /**
     * @return array{entries: list<array<string, mixed>>, binding_breach: bool, needs_verification: bool,
     *               label_warnings_fr: list<string>, unreviewed_entry_ids: list<int>}
     */
    public static function none(): array
    {
        return ['entries' => [], 'binding_breach' => false, 'needs_verification' => false, 'label_warnings_fr' => [], 'unreviewed_entry_ids' => []];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, binding_breach: bool, needs_verification: bool,
     *               label_warnings_fr: list<string>, unreviewed_entry_ids: list<int>}
     */
    public function check(?Substance $substance, string $concentrationPercent, bool $isRinseOff, CarbonImmutable $on): array
    {
        $result = self::none();

        if ($substance === null) {
            return $result;
        }

        $entries = RegulatoryLegalEntry::query()
            ->whereHas('substances', fn ($query) => $query->whereKey($substance->id))
            ->whereHas('regulatoryRegime', fn ($query) => $query->where('code', self::REGIME))
            ->orderBy('annex')
            ->orderBy('entry_reference')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            if (! $entry->isReviewed()) {
                $result['unreviewed_entry_ids'][] = $entry->id;

                continue;
            }

            if (! $this->inForce($entry, $on)) {
                continue;
            }

            $applies = match ($entry->product_scope) {
                LegalEntryProductScope::AllProducts, LegalEntryProductScope::SeeConditions => true,
                LegalEntryProductScope::RinseOffOnly => $isRinseOff,
                LegalEntryProductScope::LeaveOnOnly => ! $isRinseOff,
            };
            $banned = $applies && $entry->annex === LegalAnnex::AnnexII && DecimalMath::isPositive($concentrationPercent);
            $exceeds = $applies && $entry->max_concentration_percent !== null
                && DecimalMath::cmp($concentrationPercent, DecimalMath::of($entry->max_concentration_percent)) > 0;
            $needsVerification = $applies && ($entry->product_scope === LegalEntryProductScope::SeeConditions
                || filled($entry->other_limitations)
                || filled($entry->product_type_body_parts));

            $result['entries'][] = [
                'entry_id' => $entry->id,
                'annex' => $entry->annex->value,
                'entry_reference' => $entry->entry_reference,
                'clause' => $entry->clause,
                'applies' => $applies,
                'max_concentration_percent' => $entry->max_concentration_percent === null ? null : DecimalMath::of($entry->max_concentration_percent),
                'banned' => $banned,
                'exceeds_maximum' => $exceeds,
                'needs_verification' => $needsVerification,
                'other_limitations' => $entry->other_limitations,
                'product_type_body_parts' => $entry->product_type_body_parts,
                'placing_on_market_until' => $entry->placing_on_market_until?->toDateString(),
                'making_available_until' => $entry->making_available_until?->toDateString(),
            ];
            $result['binding_breach'] = $result['binding_breach'] || $banned || $exceeds;
            $result['needs_verification'] = $result['needs_verification'] || $needsVerification;

            if ($applies && filled($entry->label_warnings_fr)) {
                $result['label_warnings_fr'][] = (string) $entry->label_warnings_fr;
            }
        }

        return $result;
    }

    /**
     * Whether the allergen must be declared on the label, from the EU threshold and its operator, against the
     * finished-product concentration (decision 14).
     *
     * @return array{threshold_percent: string, operator: string, must_declare: bool, declaration_label: string|null}|null
     */
    public function allergenDeclaration(int $allergenId, string $concentrationPercent, bool $isRinseOff, CarbonImmutable $on): ?array
    {
        $rule = RegulatoryRegimeAllergen::query()
            ->where('allergen_id', $allergenId)
            ->where('is_active', true)
            ->whereHas('regulatoryRegime', fn ($query) => $query->where('code', self::REGIME))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $on))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))
            ->orderBy('id')
            ->first();

        if (! $rule instanceof RegulatoryRegimeAllergen) {
            return null;
        }

        $threshold = DecimalMath::of($isRinseOff ? $rule->rinse_off_threshold_percent : $rule->leave_on_threshold_percent);
        $comparison = DecimalMath::cmp($concentrationPercent, $threshold);

        return [
            'threshold_percent' => $threshold,
            'operator' => (string) $rule->threshold_operator,
            'must_declare' => $rule->threshold_operator === 'greater_than' ? $comparison > 0 : $comparison >= 0,
            'declaration_label' => $rule->declaration_label,
        ];
    }

    /**
     * Effective dates are calendar days: an entry applies through the whole of its first and last day, whatever the
     * time of the evaluation.
     */
    private function inForce(RegulatoryLegalEntry $entry, CarbonImmutable $on): bool
    {
        $day = $on->toDateString();

        return ($entry->effective_from === null || $entry->effective_from->toDateString() <= $day)
            && ($entry->effective_until === null || $entry->effective_until->toDateString() >= $day);
    }
}
