<?php

namespace App\Services;

use App\Enums\FormulaShareStatus;
use App\Enums\IngredientCategory;
use App\Models\FormulaShare;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FormulaSharePreview
{
    public function __construct(
        private readonly IngredientShareResolver $resolver,
        private readonly FormulaShareTransaction $transactions,
        private readonly FormulaShareBudget $budget,
        private readonly FormulaShareReferences $references,
        private readonly EntitlementService $entitlements,
        private readonly LyeLiquidIngredientValidator $dilutionLiquids,
    ) {}

    /** Browser-safe projection only.
     * @param  list<array<string, mixed>>  $decisions  @return array<string, mixed>
     */
    public function build(User $actor, FormulaShare $share, array $decisions): array
    {
        $share = FormulaShare::query()->findOrFail($share->id);
        $this->budget->consume($actor, $share->recipient_workspace_id, 'preview');
        $shareId = $share->id;

        return $this->transactions->run($actor, [$share->recipient_workspace_id], function (User $fresh, array $workspaces) use ($shareId, $decisions): array {
            $share = FormulaShare::query()->findOrFail($shareId);
            $destination = $workspaces[$share->recipient_workspace_id];
            $prepared = $this->resolve($fresh, $destination, $share, $decisions);
            $snapshot = $share->snapshot;
            $rows = collect($prepared['resolution']['nodes'])->map(function (array $row, string $key) use ($snapshot): array {
                $incoming = $snapshot['ingredients']['nodes'][$key];
                $technical = $this->displayTechnical($incoming['technical']);
                $local = $row['local_projection'] === null ? null : $this->displayTechnical($row['local_projection']['technical']);

                return [
                    'key' => $key, 'kind' => $incoming['kind'], 'name' => $incoming['display']['display_name'], 'identity' => $incoming['display'],
                    'mode' => $row['mode'], 'local_public_id' => $row['ingredient_public_id'],
                    'local_name' => $row['local_projection']['display']['display_name'] ?? null,
                    'technical' => $technical, 'local_technical' => $local,
                    'differences' => $local === null ? [] : $this->differences($technical, $local),
                    'warning' => $row['warning'],
                    'candidates' => collect($row['candidates'])->map(fn (array $candidate): array => [
                        'public_id' => $candidate['public_id'], 'name' => $candidate['name'],
                        'differences' => $this->differences($technical, $this->displayTechnical($candidate['projection']['technical'])),
                    ])->all(),
                ];
            })->values()->all();

            return [
                'share_public_id' => $share->public_id, 'product_name' => $snapshot['product']['name'],
                'description' => $snapshot['product']['description'] ?? null,
                'procedure' => $snapshot['formula']['manufacturing_instructions'] ?? null,
                'phases' => collect($snapshot['formula']['phases'])->map(fn (array $phase): array => Arr::only($phase, ['key', 'slug', 'name', 'phase_type', 'sort_order', 'is_system', 'items']))->all(),
                'ingredients' => $rows, 'warnings' => array_values(array_unique(array_merge($snapshot['warnings'] ?? [], collect($rows)->pluck('warning')->filter()->all(), $prepared['ifra']['changed'] ? ['ifra_selection_changed'] : []))),
                'remaining_keys' => $prepared['resolution']['remaining_keys'], 'import_count' => $prepared['resolution']['import_count'],
                'private_ingredient_quota' => $this->entitlements->privateIngredientUsageFor($fresh),
                'expected_hash' => $prepared['expected_hash'],
                'ifra' => $prepared['ifra'],
            ];
        }, write: false);
    }

    /** Internal preparation reused by acceptance while the actor, workspaces and grant are locked.
     * @param  list<array<string, mixed>>  $decisions  @return array{resolution: array<string, mixed>, expected_hash: string}
     */
    public function resolve(User $actor, Workspace $destination, FormulaShare $share, array $decisions): array
    {
        Gate::forUser($actor)->authorize('accept', $share);
        if ($share->recipient_workspace_id !== $destination->id || $share->status !== FormulaShareStatus::Pending || $share->snapshot === null || $share->schema_version !== 1) {
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.unavailable')]);
        }
        $snapshot = $share->snapshot;
        $resolution = $this->resolver->resolve($destination, $snapshot['ingredients'], $decisions);
        if (data_get($snapshot, 'formula.manufacturing_mode') === 'saponify_in_formula') {
            foreach ($snapshot['formula']['phases'] as $phase) {
                if ($phase['phase_type'] === 'lye_water') {
                    $this->dilutionLiquids->assertMaximumRows($phase['items']);
                    $localRows = [];
                    foreach ($phase['items'] as $item) {
                        $row = $resolution['nodes'][$item['ingredient_key']];
                        if ($row['ingredient_id'] !== null) {
                            $localRows[] = ['ingredient_id' => $row['ingredient_id'], 'percentage' => $item['percentage']];
                        } elseif ($row['mode'] === 'import' && $snapshot['ingredients']['nodes'][$item['ingredient_key']]['technical']['category'] === IngredientCategory::SoapmakingAlkalis->value) {
                            throw ValidationException::withMessages(['decisions' => __('workbench.validation.lye_liquid_alkali')]);
                        }
                    }
                    $this->dilutionLiquids->validate($localRows, $actor);
                }
                if ($phase['phase_type'] !== 'saponified_oils') {
                    continue;
                }
                foreach ($phase['items'] as $item) {
                    $row = $resolution['nodes'][$item['ingredient_key']];
                    $technical = $row['local_projection']['technical'] ?? $snapshot['ingredients']['nodes'][$item['ingredient_key']]['technical'];
                    if ($row['mode'] !== 'decision' && (! $technical['is_soap_saponification_trusted'] || data_get($technical, 'sap_profile.koh_sap_value') === null)) {
                        throw ValidationException::withMessages(['decisions' => __('sharing.validation.saponification')]);
                    }
                }
            }
        }
        $state = collect($resolution['nodes'])->map(fn (array $row): array => [
            'mode' => $row['mode'], 'public_id' => $row['ingredient_public_id'], 'fingerprint' => $row['local_fingerprint'], 'warning' => $row['warning'],
        ])->all();
        $references = $this->references->state($snapshot, $resolution);
        $effective = $references['effective_ifra'];
        $ifraChanged = $effective['amendment_id'] !== data_get($snapshot, 'formula.ifra.amendment.id')
            || $effective['category_id'] !== data_get($snapshot, 'formula.ifra.category.id')
            || $effective['mapping_id'] !== data_get($snapshot, 'formula.ifra.mapping.id');
        $hash = hash('sha256', 'formula-share-preview-v1\n'.json_encode([
            'share' => $share->public_id, 'snapshot' => $snapshot, 'snapshot_hash' => $share->snapshot_hash,
            'recipient' => $destination->public_id, 'decisions' => $resolution['decisions'],
            'state' => $state, 'references' => $references,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['resolution' => $resolution, 'expected_hash' => $hash, 'ifra' => [
            'category_code' => $references[IfraProductCategory::class.':'.$effective['category_id']]['code'] ?? null,
            'amendment_code' => $references[IfraAmendment::class.':'.$effective['amendment_id']]['code'] ?? null,
            'changed' => $ifraChanged,
        ]];
    }

    /** @param array<string, mixed> $technical @return array<string, mixed> */
    private function displayTechnical(array $technical): array
    {
        unset($technical['baseline'], $technical['is_soap_saponification_trusted']);
        $technical['components'] = collect($technical['components'])->map(fn (array $component): array => Arr::only($component, ['key', 'percentage_in_parent', 'sort_order']))->all();
        $stripIds = function (mixed $value) use (&$stripIds): mixed {
            if (! is_array($value)) {
                return $value;
            }
            unset($value['id']);

            return collect($value)->map(fn (mixed $entry): mixed => $stripIds($entry))->all();
        };

        return $stripIds($technical);
    }

    /** @param array<string, mixed> $incoming @param array<string, mixed> $local @return list<string> */
    private function differences(array $incoming, array $local): array
    {
        return collect($incoming)->filter(fn (mixed $value, string $key): bool => $value !== ($local[$key] ?? null))->keys()->all();
    }
}
