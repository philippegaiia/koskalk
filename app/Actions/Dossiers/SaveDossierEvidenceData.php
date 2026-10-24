<?php

namespace App\Actions\Dossiers;

use App\Enums\ConstituentKind;
use App\Enums\UseLimitType;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveDossierEvidenceData
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    /**
     * Replaces everything a document states (constituents, use limits, facts) in one transaction.
     *
     * @param  array{constituents?: list<array<string, mixed>>, use_limits?: list<array<string, mixed>>, facts?: array<string, mixed>,
     *               constituents_complete?: bool}  $data  `constituents_complete`: every content the document declares is entered
     *                                                     (an empty list then means it declares none).
     */
    public function handle(User $actor, Workspace $workspace, DossierEvidenceItem $item, array $data): DossierEvidenceItem
    {
        return DB::transaction(function () use ($actor, $workspace, $item, $data): DossierEvidenceItem {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $current = DossierEvidenceItem::query()->where('workspace_id', $workspace->id)->whereKey($item->getKey())->lockForUpdate()->first();

            if (! $current instanceof DossierEvidenceItem || ! $current->isActive()) {
                throw ValidationException::withMessages(['evidence' => __('dossiers.evidence.not_editable')]);
            }

            $validated = $this->validate($current, $data);

            $current->constituents()->delete();
            $current->useLimits()->delete();

            foreach ($validated['constituents'] as $row) {
                $kind = ConstituentKind::from($row['kind']);
                $current->constituents()->create([
                    'kind' => $kind,
                    'allergen_id' => $kind->usesAllergenCatalogue() ? $row['allergen_id'] : null,
                    'substance_id' => $kind->usesAllergenCatalogue() ? null : $row['substance_id'],
                    'max_value' => $row['max_value'],
                    'unit' => $kind->unit(),
                    'note' => $row['note'] ?? null,
                ]);
            }

            foreach ($validated['use_limits'] as $row) {
                $type = UseLimitType::from($row['limit_type']);
                $current->useLimits()->create([
                    'ifra_product_category_id' => $current->type->useLimitBasis() === 'ifra_category' ? $row['ifra_product_category_id'] : null,
                    'product_class_label' => $current->type->useLimitBasis() === 'product_class' ? trim((string) $row['product_class_label']) : null,
                    'limit_type' => $type,
                    'max_percent' => $type === UseLimitType::Maximum ? $row['max_percent'] : null,
                ]);
            }

            $current->update([
                'facts' => $validated['facts'] === [] ? null : $validated['facts'],
                'constituents_complete' => $validated['constituents_complete'],
                'data_updated_by_user_id' => $freshActor->id,
                'data_updated_at' => now(),
            ]);

            return $current->load(['constituents', 'useLimits']);
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{constituents: list<array<string, mixed>>, use_limits: list<array<string, mixed>>, facts: array<string, mixed>, constituents_complete: bool}
     */
    private function validate(DossierEvidenceItem $item, array $data): array
    {
        $type = $item->type;
        $kinds = array_map(fn (ConstituentKind $kind): string => $kind->value, $type->constituentKinds());
        $basis = $type->useLimitBasis();
        $constituents = array_values($data['constituents'] ?? []);
        $useLimits = array_values($data['use_limits'] ?? []);
        $useLimits = array_map(fn (array $row): array => ['limit_type' => UseLimitType::Maximum->value, ...$row], $useLimits);
        $facts = array_filter($data['facts'] ?? [], fn (mixed $value): bool => filled($value));
        $constituentsComplete = (bool) ($data['constituents_complete'] ?? false);

        $errors = [];

        if ($kinds === [] && $constituents !== []) {
            $errors['constituents'] = __('dossiers.evidence.no_constituents_for_type');
        }

        if ($kinds === [] && $constituentsComplete) {
            $errors['constituents_complete'] = __('dossiers.evidence.no_constituents_for_type');
        }

        if ($basis === null && $useLimits !== []) {
            $errors['use_limits'] = __('dossiers.evidence.no_use_limits_for_type');
        }

        if (array_diff(array_keys($facts), $type->factKeys()) !== []) {
            $errors['facts'] = __('dossiers.evidence.unknown_facts_for_type');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validator = Validator::make(['constituents' => $constituents, 'use_limits' => $useLimits, 'facts' => $facts], [
            'constituents' => ['array'],
            'constituents.*.kind' => ['required', Rule::in($kinds)],
            'constituents.*.allergen_id' => ['required_if:constituents.*.kind,allergen', 'nullable', 'integer', 'exists:allergen_catalog,id'],
            'constituents.*.substance_id' => ['exclude_if:constituents.*.kind,allergen', 'required', 'integer', 'exists:substance_catalog,id'],
            // Zero is information ("absent", "not allowed in this category"), distinct from missing data.
            'constituents.*.max_value' => ['required', 'numeric', 'gte:0'],
            'constituents.*.note' => ['nullable', 'string', 'max:500'],
            'use_limits' => ['array'],
            'use_limits.*.limit_type' => ['required', Rule::enum(UseLimitType::class)],
            // A maximum needs its value (zero means not permitted); an explicit "no restriction" has none.
            'use_limits.*.max_percent' => ['exclude_unless:use_limits.*.limit_type,maximum', 'required', 'numeric', 'gte:0', 'lte:100'],
            // A category or class is stated once: a second entry could contradict the first.
            'use_limits.*.ifra_product_category_id' => $basis === 'ifra_category'
                ? ['required', 'integer', 'distinct', 'exists:ifra_product_categories,id']
                : ['nullable'],
            'use_limits.*.product_class_label' => $basis === 'product_class'
                ? ['required', 'string', 'distinct:ignore_case', 'max:255']
                : ['nullable'],
            'facts.ifra_amendment_id' => ['nullable', 'integer', 'exists:ifra_amendments,id'],
            'facts.covers_supplied_grade' => ['nullable', 'boolean'],
            'facts.peroxide_value_mmol_l' => ['nullable', 'numeric', 'gte:0'],
            'facts.microbiology_specification' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($constituents): void {
            foreach ($constituents as $index => $row) {
                $isPercent = ($row['kind'] ?? null) !== ConstituentKind::Impurity->value;

                if ($isPercent && is_numeric($row['max_value'] ?? null) && (float) $row['max_value'] > 100) {
                    $validator->errors()->add("constituents.{$index}.max_value", __('dossiers.evidence.percent_above_100'));
                }
            }
        });
        $validator->after(function ($validator) use ($useLimits): void {
            foreach ($useLimits as $index => $row) {
                if (($row['limit_type'] ?? null) === UseLimitType::NoRestriction->value && filled($row['max_percent'] ?? null)) {
                    $validator->errors()->add("use_limits.{$index}.max_percent", __('dossiers.evidence.no_restriction_without_value'));
                }
            }
        });
        $validator->validate();

        if (array_key_exists('covers_supplied_grade', $facts)) {
            $facts['covers_supplied_grade'] = filter_var($facts['covers_supplied_grade'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('ifra_amendment_id', $facts)) {
            $facts['ifra_amendment_id'] = (int) $facts['ifra_amendment_id'];
        }

        return ['constituents' => $constituents, 'use_limits' => $useLimits, 'facts' => $facts, 'constituents_complete' => $constituentsComplete];
    }
}
