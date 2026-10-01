<?php

namespace App\Services;

use App\Enums\IfraCategorySelectionMode;
use App\Enums\MassUnit;
use App\Enums\ProductionOutputType;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FormulaShareSnapshotBuilder
{
    public function __construct(
        private readonly FormulaShareTransaction $transaction,
        private readonly FormulaShareBudget $budget,
        private readonly FormulaShareContentSanitizer $sanitizer,
        private readonly IngredientShareGraph $graph,
        private readonly IngredientShareFingerprint $fingerprint,
        private readonly CanonicalSoapAlkaliResolver $alkalis,
        private readonly FormulaSharePhaseValidator $phases,
    ) {}

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function build(User $actor, Recipe $recipe, array $options): array
    {
        $workspaceId = $this->sourceWorkspaceId($actor, $recipe);
        $this->budget->consume($actor, $workspaceId, 'preview');

        return $this->transaction->run($actor, [$workspaceId], function (User $fresh, array $workspaces) use ($recipe, $options, $workspaceId): array {
            $source = Recipe::withoutGlobalScopes()->lockForUpdate()->findOrFail($recipe->id);

            return $this->capture($fresh, $source, $workspaces[$workspaceId], $options);
        }, write: false);
    }

    public function sourceWorkspaceId(User $actor, Recipe $recipe): int
    {
        Gate::forUser($actor)->authorize('share', $recipe);
        $id = Recipe::withoutGlobalScopes()->whereKey($recipe->id)->value('workspace_id');
        if ($id === null) {
            throw new AuthorizationException;
        }

        return (int) $id;
    }

    public function resolveRecipient(User $actor, Recipe $recipe, string $address): Workspace
    {
        $sourceId = $this->sourceWorkspaceId($actor, $recipe);
        $this->budget->consume($actor, $sourceId, 'recipient');
        $address = trim($address);
        if (Str::isUuid($address)) {
            $recipient = Workspace::withoutGlobalScopes()->where('public_id', $address)->first();
        } else {
            if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->invalid('recipient');
            }
            $owners = User::withoutGlobalScopes()->whereNotNull('email_verified_at')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($address)])->select('id');
            $matches = Workspace::withoutGlobalScopes()->whereIn('owner_user_id', $owners)->orderBy('id')->limit(2)->get();
            if ($matches->count() > 1) {
                $this->invalid('recipient_ambiguous');
            }
            $recipient = $matches->first();
        }
        if ($recipient === null || $recipient->id === $sourceId) {
            $this->invalid('recipient');
        }

        return $recipient;
    }

    /** @param array<string, mixed> $options @return array{include_procedure: bool, include_description: bool, include_line_notes: bool} */
    public function options(array $options): array
    {
        $defaults = ['include_procedure' => false, 'include_description' => false, 'include_line_notes' => false];
        if (array_diff(array_keys($options), array_keys($defaults)) !== [] || count(array_filter($options, fn (mixed $value): bool => ! is_bool($value))) > 0) {
            $this->invalid('options');
        }

        return array_replace($defaults, $options);
    }

    /** @param array<string, mixed> $snapshot */
    public function previewHash(array $snapshot, Workspace $recipient): string
    {
        unset($snapshot['captured_at']);

        return hash('sha256', json_encode(['version' => 1, 'recipient' => ['public_id' => $recipient->public_id, 'name' => $recipient->name], 'snapshot' => $snapshot], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Capture only inside the caller's coherent transaction, after workspace and Product locks.
     * @param  array<string, mixed>  $options  @return array<string, mixed>
     */
    public function capture(User $actor, Recipe $recipe, Workspace $source, array $options): array
    {
        Gate::forUser($actor)->authorize('share', $recipe);
        if ($recipe->workspace_id !== $source->id) {
            throw new AuthorizationException;
        }
        $options = $this->options($options);
        if ($recipe->production_output_type === ProductionOutputType::ManufacturedIngredient) {
            $this->invalid('manufactured_output');
        }
        $saved = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)
            ->orderByDesc('version_number')->orderByDesc('id')->lockForUpdate()->first();
        if ($saved === null || $saved->workspace_id !== $source->id) {
            $this->invalid('saved_required');
        }
        $family = ProductFamily::query()->find($recipe->product_family_id);
        $type = $recipe->product_type_id === null ? null : ProductType::query()->find($recipe->product_type_id);
        if ($family === null || ! $family->is_active || ! in_array($family->calculation_basis, ['initial_oils', 'total_formula'], true)
            || ($recipe->product_type_id !== null && ($type === null || ! $type->is_active || ! $type->productFamilies()->whereKey($family->id)->exists()))) {
            $this->invalid('reference');
        }
        $formula = $this->settings($saved, $family, $type);
        $limit = (int) config('workspaces.formula_sharing.limits.relation_rows', 10000);
        $phases = RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $saved->id)->orderBy('sort_order')->orderBy('id')->limit($limit + 1)->get();
        $items = RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $saved->id)->orderBy('position')->orderBy('id')->limit($limit + 1)->get();
        if ($phases->isEmpty() || $items->isEmpty() || $phases->count() + $items->count() > $limit
            || $phases->contains(fn (RecipePhase $phase): bool => $phase->workspace_id !== $source->id)
            || $items->contains(fn (RecipeItem $item): bool => $item->workspace_id !== $source->id || ! $phases->contains('id', $item->recipe_phase_id) || $item->ingredient_id === null)) {
            $this->invalid('formula');
        }
        $rootIds = $items->pluck('ingredient_id')->map(fn (mixed $id): int => (int) $id)->all();
        $canonical = [];
        if ($family->calculation_basis === 'initial_oils' && $saved->manufacturing_mode === 'saponify_in_formula') {
            $lyeType = $formula['calculation_context']['lye_type'];
            $split = (float) $formula['calculation_context']['dual_lye_koh_percentage'];
            $types = $lyeType === 'dual' ? array_values(array_filter(['naoh', 'koh'], fn (string $type): bool => $type === 'naoh' ? $split < 100 : $split > 0)) : [$lyeType];
            $canonical = $this->alkalis->resolveMany($types)->map(fn (Ingredient $ingredient): int => $ingredient->id)->all();
            $rootIds = [...$rootIds, ...array_values($canonical)];
        }
        $graph = $this->graph->capture($actor, $source, $rootIds);
        Ingredient::withoutGlobalScopes()->whereIn('id', array_keys($graph['source_keys']))->orderBy('id')->lockForUpdate()->get();
        $formula['canonical_alkalis'] = array_map(fn (int $id): string => $graph['source_keys'][$id], $canonical);
        $formula['phases'] = [];
        foreach ($phases as $index => $phase) {
            if (! is_string($phase->slug) || $phase->slug === '' || $phases->where('slug', $phase->slug)->count() !== 1) {
                $this->invalid('formula');
            }
            $formula['phases'][] = [
                'key' => 'p'.($index + 1), 'slug' => $phase->slug, 'name' => $phase->name,
                'phase_type' => $phase->phase_type, 'sort_order' => $phase->sort_order, 'is_system' => $phase->is_system,
                'items' => $items->where('recipe_phase_id', $phase->id)->values()->map(fn (RecipeItem $item, int $position): array => [
                    'key' => 'p'.($index + 1).'i'.($position + 1), 'ingredient_key' => $graph['source_keys'][$item->ingredient_id],
                    'percentage' => $this->number($item->percentage, 4), 'weight' => $this->number($item->weight, 4),
                    'position' => $item->position, 'note' => $options['include_line_notes'] ? $this->sanitizer->plainText($item->note) : null,
                ])->all(),
            ];
        }
        $this->phases->validate($formula['phases'], $family->calculation_basis === 'total_formula');
        $formula['manufacturing_instructions'] = $options['include_procedure'] ? $this->sanitizer->plainText($saved->manufacturing_instructions) : null;
        unset($graph['source_keys']);
        $snapshot = [
            'schema_version' => 1, 'captured_at' => now()->toIso8601String(),
            'source_saved_formula' => ['public_id' => $saved->public_id, 'version_number' => $saved->version_number],
            'options' => $options,
            'product' => ['name' => $recipe->name, 'family' => ['id' => $family->id, 'slug' => $family->slug, 'calculation_basis' => $family->calculation_basis],
                'type' => $type === null ? null : ['id' => $type->id, 'slug' => $type->slug],
                'description' => $options['include_description'] ? $this->sanitizer->plainText($recipe->description) : null],
            'formula' => $formula, 'ingredients' => $graph,
            'warnings' => $options['include_procedure'] && $this->sanitizer->containsExcludedMedia($saved->manufacturing_instructions) ? ['procedure_media_excluded'] : [],
        ];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > (int) config('workspaces.formula_sharing.limits.bytes', 2097152)) {
            $this->invalid('snapshot_size');
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function settings(RecipeVersion $version, ProductFamily $family, ?ProductType $type): array
    {
        if (! in_array($version->manufacturing_mode, ['saponify_in_formula', 'blend_only'], true)
            || ! in_array($version->exposure_mode, ['rinse_off', 'leave_on'], true) || MassUnit::tryFrom((string) $version->batch_unit) === null) {
            $this->invalid('settings');
        }
        $context = $version->calculation_context;
        if (! is_array($context) || ! in_array($context['editing_mode'] ?? null, ['percentage', 'weight'], true)
            || MassUnit::tryFrom((string) ($context['oil_unit'] ?? '')) === null) {
            $this->invalid('settings');
        }
        $calculation = ['editing_mode' => $context['editing_mode'], 'oil_unit' => $context['oil_unit']];
        foreach (['oil_weight', 'mass_grams'] as $field) {
            $calculation[$field] = $this->number($context[$field] ?? null, 9);
            if (bccomp($calculation[$field], '0', 9) <= 0) {
                $this->invalid('settings');
            }
        }
        $water = [];
        if ($family->calculation_basis === 'total_formula') {
            if (($context['calculation_basis'] ?? null) !== 'total_formula' || $version->manufacturing_mode !== 'blend_only') {
                $this->invalid('settings');
            }
            $calculation['calculation_basis'] = 'total_formula';
        } else {
            if (! in_array($context['lye_type'] ?? null, ['naoh', 'koh', 'dual'], true)
                || ! in_array($context['koh_purity_percentage'] ?? null, [90, 100, 90.0, 100.0, '90', '100'], true)) {
                $this->invalid('settings');
            }
            $calculation['lye_type'] = $context['lye_type'];
            foreach (['koh_purity_percentage', 'dual_lye_koh_percentage', 'superfat'] as $field) {
                $calculation[$field] = $this->number($context[$field] ?? null, 9);
            }
            if ((float) $calculation['dual_lye_koh_percentage'] < 0 || (float) $calculation['dual_lye_koh_percentage'] > 100) {
                $this->invalid('settings');
            }
            $storedWater = $version->water_settings;
            if (! is_array($storedWater) || ! in_array($storedWater['mode'] ?? null, ['percent_of_oils', 'lye_ratio', 'lye_concentration'], true)) {
                $this->invalid('settings');
            }
            $water = ['mode' => $storedWater['mode'], 'value' => $this->number($storedWater['value'] ?? null, 9)];
        }
        $regime = $version->regulatory_regime_id === null ? RegulatoryRegime::query()->where('code', $version->regulatory_regime)->first() : RegulatoryRegime::query()->find($version->regulatory_regime_id);
        if ($regime === null || ! in_array($regime->status, ['active', 'preview'], true) || $regime->code !== $version->regulatory_regime) {
            $this->invalid('reference');
        }
        $mode = IfraCategorySelectionMode::tryFrom((string) $version->getRawOriginal('ifra_category_selection_mode'));
        if ($mode === null) {
            $this->invalid('settings');
        }
        $category = $version->ifra_product_category_id === null ? null : IfraProductCategory::query()->find($version->ifra_product_category_id);
        $amendment = $version->ifra_amendment_id === null ? null : IfraAmendment::query()->find($version->ifra_amendment_id);
        $mapping = $version->product_type_ifra_category_id === null ? null : ProductTypeIfraCategory::query()->find($version->product_type_ifra_category_id);
        if (($version->ifra_product_category_id !== null && ($category === null || ! $category->is_active))
            || ($version->ifra_amendment_id !== null && $amendment === null)
            || ($version->product_type_ifra_category_id !== null && ($mapping === null || ! $mapping->is_active || $mapping->product_type_id !== $type?->id || $mapping->ifra_amendment_id !== $amendment?->id || $mapping->ifra_product_category_id !== $category?->id))
            || ($mode === IfraCategorySelectionMode::Manual && $category === null)) {
            $this->invalid('reference');
        }

        return [
            'name' => $version->name, 'batch_size' => $this->number($version->batch_size, 3), 'batch_unit' => $version->batch_unit,
            'batch_mass_grams' => $this->number($version->batch_mass_grams, 9),
            'manufacturing_mode' => $version->manufacturing_mode, 'exposure_mode' => $version->exposure_mode,
            'regulatory_regime' => ['id' => $regime->id, 'code' => $regime->code, 'status' => $regime->status],
            'ifra' => ['selection_mode' => $mode->value, 'category' => $category === null ? null : ['id' => $category->id, 'code' => $category->code],
                'amendment' => $amendment === null ? null : ['id' => $amendment->id, 'code' => $amendment->code],
                'mapping' => $mapping === null ? null : ['id' => $mapping->id, 'product_type_id' => $mapping->product_type_id]],
            'water_settings' => $water, 'calculation_context' => $calculation,
        ];
    }

    private function number(mixed $value, int $scale): string
    {
        try {
            $canonical = $this->fingerprint->decimal($value, $scale);
        } catch (InvalidArgumentException) {
            $this->invalid('settings');
        }
        if ($canonical === null) {
            $this->invalid('settings');
        }

        return $canonical;
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['sharing' => __("sharing.validation.$key")]);
    }
}
